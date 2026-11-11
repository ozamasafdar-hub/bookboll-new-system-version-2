<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/** Read-only activity. Each bookings.id is counted once, never once per payment. */
final class BookingActivity
{
    // Mirrors PaymentReportRules::channel, including the immutable creation stamp.
    public static function channelSql(): string
    {
        return "COALESCE(NULLIF(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(bookings.creation_context, '$.channel')), 'null'), ''),
            CASE WHEN bookings.origin IN ('manual_admin','manual') THEN 'admin'
            WHEN bookings.commission_channel IN ('site','marketplace') THEN bookings.commission_channel
            WHEN bookings.origin IN ('bookboll_web','bookboll_app','customer_game') THEN 'marketplace'
            WHEN bookings.origin IN ('provider_site','whitelabel','white_label') THEN 'site'
            WHEN bookings.origin = 'class_program' THEN 'auto' ELSE 'unknown' END)";
    }

    public static function purposeSql(): string
    {
        return "CASE WHEN bookings.origin = 'class_program' OR bookings.record_purpose = 'membership_session' THEN 'membership_session'
            WHEN bookings.record_purpose = 'provider_slot_block' OR bookings.payment_method = 'payment_skipped' OR bookings.payment_status = 'payment_skipped' THEN 'provider_slot_block'
            WHEN bookings.record_purpose = 'needs_review' THEN 'needs_review' ELSE 'booking' END";
    }

    public static function summarize($query, array $filters): array
    {
        $source = (clone $query)->reorder()->setEagerLoads([])->select('bookings.*')
            ->selectRaw(self::channelSql().' AS activity_channel')->selectRaw(self::purposeSql().' AS activity_purpose');
        $base = fn () => DB::query()->fromSub(clone $source->toBase(), 'activity');
        // Counts booking activity, including unpaid reservations; never claims these are visits or receipts.
        $eligible = fn () => $base()->where('activity_purpose','booking')->whereIn('status',['pending','confirmed','completed'])
            ->whereNull('refunded_at')->where(fn($q)=>$q->whereNull('payment_status')->orWhereNotIn('payment_status',['cancelled','refunded']));
        $matched = $base()->count();
        $count = $eligible()->count();
        $identifiedQuery=fn()=>$eligible()->whereNotNull('user_id')->whereNotIn('user_id',\App\Models\User::where('email','walkin@bookboll.local')->select('id'));
        $identified = $identifiedQuery()->count();
        $frequencies = $identifiedQuery()->selectRaw('user_id, COUNT(*) AS n')->groupBy('user_id');
        $distribution = DB::query()->fromSub($frequencies,'frequency')->selectRaw('n, COUNT(*) AS customers')->groupBy('n')->get();
        $customers = (int) $distribution->sum('customers');
        $repeat = (int) $distribution->where('n','>',1)->sum('customers');
        $buckets = [1=>0,2=>0,3=>0,5=>0];
        foreach ($distribution as $row) $buckets[$row->n >= 5 ? 5 : ($row->n >= 3 ? 3 : (int)$row->n)] += (int)$row->customers;
        $dateColumn = ($filters['date_basis'] ?? 'booking') === 'created' ? 'created_at' : 'date';
        $bounds = $eligible()->selectRaw("MIN(DATE($dateColumn)) AS first_date, MAX(DATE($dateColumn)) AS last_date")->first();
        $monthly = $bounds->first_date && $bounds->last_date && (strtotime($bounds->last_date)-strtotime($bounds->first_date))/86400 > 120;
        $bucket = $monthly ? "DATE_FORMAT($dateColumn, '%Y-%m')" : "DATE($dateColumn)";
        $series = $eligible()->whereNotNull($dateColumn)->selectRaw("$bucket AS bucket, COUNT(*) AS bookings")
            ->groupBy('bucket')->orderBy('bucket')->get()->map(fn ($r)=>['label'=>$r->bucket,'value'=>(int)$r->bookings])->all();
        // Fill missing dates without truncating any part of history.
        if ($series) {
            $indexed = array_column($series,'value','label');
            $first = \Carbon\CarbonImmutable::parse($bounds->first_date)->startOfDay();
            $last = \Carbon\CarbonImmutable::parse($bounds->last_date)->startOfDay();
            if ($monthly) { $first=$first->startOfMonth(); $last=$last->startOfMonth(); }
            $series=[];
            for ($day=$first; $day <= $last; $day=$monthly ? $day->addMonth() : $day->addDay()) {
                $label=$day->format($monthly ? 'Y-m' : 'Y-m-d');
                $series[]=['label'=>$label,'value'=>$indexed[$label] ?? 0];
            }
        }
        $group = fn ($key) => $base()->selectRaw("$key AS label, COUNT(*) AS value")->groupBy($key)->orderByDesc('value')->orderBy($key)->get()
            ->map(fn ($r)=>['label'=>$r->label ?: 'Not recorded','value'=>(int)$r->value])->all();
        $sales = $eligible()->selectRaw("COALESCE(NULLIF(currency,''),'Unknown') AS currency, SUM(total_price) AS amount")
            ->groupBy('currency')->orderBy('currency')->get()->map(fn ($r)=>['currency'=>$r->currency,'amount'=>(float)$r->amount])->all();
        $venues = $eligible()->selectRaw('venue_id, COUNT(*) AS bookings, COUNT(DISTINCT user_id) AS customers')
            ->groupBy('venue_id')->orderByDesc('bookings')->orderBy('venue_id')->get()->map(fn ($r)=>['id'=>(string)$r->venue_id,'bookings'=>(int)$r->bookings,'customers'=>(int)$r->customers])->all();
        $identifiedByVenue=$identifiedQuery()->selectRaw('venue_id, COUNT(DISTINCT user_id) AS customers')->groupBy('venue_id')->pluck('customers','venue_id');
        foreach($venues as &$venue) $venue['customers']=(int)($identifiedByVenue[$venue['id']] ?? 0);
        unset($venue);
        return [
            'version'=>1,'matched'=>$matched,'active'=>$count,'customers'=>$customers,'identified_bookings'=>$identified,
            'walk_in_bookings'=>$count-$identified,'per_customer'=>$customers ? round($identified/$customers,2) : null,
            'repeat_customers'=>$repeat,'repeat_pct'=>$customers ? round($repeat/$customers*100,1) : null,
            'slot_blocks'=>$base()->where('activity_purpose','provider_slot_block')->count(),
            'pending'=>$base()->where('activity_purpose','booking')->where('status','pending')->count(),
            'cancelled'=>$base()->where('status','cancelled')->count(),
            'unclassified'=>$base()->where('activity_purpose','needs_review')->count(),
            'sales'=>$sales,'series'=>$series,'grain'=>$monthly ? 'month' : 'day',
            'undated'=>$eligible()->whereNull($dateColumn)->count(),
            'statuses'=>$group('status'),'channels'=>$group('activity_channel'),'purposes'=>$group('activity_purpose'),
            'frequency'=>array_map(fn($key)=>['label'=>[1=>'1 booking',2=>'2 bookings',3=>'3–4 bookings',5=>'5+ bookings'][$key],'value'=>$buckets[$key]],array_keys($buckets)),
            'hours'=>$eligible()->selectRaw('HOUR(start_time) AS hour, COUNT(*) AS bookings')->whereNotNull('start_time')->groupBy('hour')->orderBy('hour')->get()
                ->map(fn($r)=>['label'=>sprintf('%02d:00',$r->hour),'value'=>(int)$r->bookings])->all(),
            'venues'=>$venues,'coverage'=>['status'=>'complete','matched'=>$matched],
        ];
    }
}
