<?php
namespace App\Services;

use App\Models\{Booking,Promocode,User};
use App\Support\{BookingProvenance,MarketSettings};
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** One offer per customer's first completed marketplace session at each venue. */
class ComebackOfferService
{
    public static function marketplace(Booking $b): bool
    {
        $context = $b->creation_context ?: BookingProvenance::legacy($b);
        return ($context['channel'] ?? '') === 'marketplace' && $b->origin !== 'customer_game'
            && $b->payment_method !== 'payment_skipped' && !$b->program_id;
    }

    public static function window(Booking $b): array
    {
        $zone = $b->venue?->timezone_override ?: config('app.timezone');
        $start = Carbon::parse(substr((string) $b->date, 0, 10).' '.$b->start_time, $zone);
        $end = Carbon::parse(substr((string) $b->date, 0, 10).' '.$b->end_time, $zone);
        if ($end->lte($start)) $end->addDay();
        return [$start->utc(), $end->utc()];
    }

    public function consider(Booking $booking): ?int
    {
        if (!$booking->user_id || !$booking->venue || !self::marketplace($booking)) return null;
        // Serialize issuance for this customer. The unique pair is the final race guard.
        return DB::transaction(function () use ($booking) {
            $user = User::whereKey($booking->user_id)->lockForUpdate()->first();
            if (!$user || DB::table('booking_comeback_offers')->where('user_id',$user->id)->where('venue_id',$booking->venue_id)->exists()) return null;
            $sessions = Booking::with(['venue','creator.roles','creationHistory'])->where('user_id',$user->id)->where('venue_id',$booking->venue_id)
                ->whereIn('status',['confirmed','completed'])->where('date','<=', now()->addDay()->toDateString())->orderBy('date')->orderBy('start_time')->get()
                ->filter(fn ($b) => self::marketplace($b))->values();
            if ($sessions->isEmpty()) return null;
            $first = $sessions->first(); [$start,$end] = self::window($first);
            // Adjacent slots form one visit, so do not interrupt a multi-slot session.
            foreach ($sessions->skip(1) as $session) {
                [$nextStart,$nextEnd] = self::window($session);
                if ($nextStart->gt($end)) break;
                if ($nextEnd->gt($end)) $end = $nextEnd;
            }
            // No historical marketing blast on rollout or after an extended outage.
            if ($end->gt(now()->utc()) || $end->lt(now()->utc()->subDay())) return null;
            $settings = app(MarketSettings::class)->config($booking->venue->country_id, $booking->venue->city)['effective'];
            $rate = (float) $settings['comeback_discount_pct'];
            $consent = $user->preferences?->notifications ?? [];
            $enabled = $settings['comeback_enabled'] && $rate > 0;
            $email = $enabled && ($consent['marketing_emails'] ?? false) && filter_var($user->email,FILTER_VALIDATE_EMAIL);
            $wa = $enabled && ($consent['marketing_whatsapp'] ?? false) && WhatsappService::normalizeNumber($user->phone);
            $now = now(); $expires = $end->copy()->addDays(7)->timezone(config('app.timezone'));
            $promo = null;
            if ($email || $wa) {
                $promo = Promocode::create([
                    'code'=>'COMEBACK'.Str::upper(Str::random(10)), 'discount_type'=>'percentage','discount_value'=>$rate,
                    'recipient'=>'bookboll','creator_role'=>'super_admin','applicable_to'=>'facility','audience'=>'all',
                    'status'=>'active','usage_limit'=>1,'per_user_limit'=>1,'used_count'=>0,'used_by_users'=>[],
                    'is_reminder_promocode'=>true,'payment_methods'=>'all','comeback_booking_id'=>$first->id,
                    'valid_from'=>$now->toDateString(),'expires_at'=>$expires->toDateString(),'starts_at'=>$now,'ends_at'=>$expires,
                    'location_ids'=>[$booking->venue_id], 'description'=>"Book again at {$booking->venue->name}. Up to {$rate}% off, capped at Bookboll's fee. Valid for 7 days after your first session.",
                ]);
                $promo->users()->attach($user->id,['usage_count'=>0]);
                $promo->venues()->attach($booking->venue_id);
            }
            $id = DB::table('booking_comeback_offers')->insertGetId([
                'user_id'=>$user->id,'venue_id'=>$booking->venue_id,'booking_id'=>$first->id,'promocode_id'=>$promo?->id,
                'session_ended_at'=>$end->copy()->timezone(config('app.timezone')), 'status'=>$promo?'pending':'skipped',
                'email_status'=>$email?'pending':'skipped','whatsapp_status'=>$wa?'pending':'skipped','created_at'=>$now,'updated_at'=>$now,
            ]);
            return $id;
        });
    }
}
