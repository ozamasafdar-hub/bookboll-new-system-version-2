<?php
namespace App\Services;

use App\Models\{Booking, Payment};
use App\Support\{Money, PaymentBulkRules, PaymentReleaseGate, PaymentReportRules, PaymentReportTotals};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class PaymentBulkService
{
    public function __construct(private PaymentReportService $report) {}

    public function authorize($actor): void
    {
        abort_unless(PaymentBulkRules::allows($actor),403,'Only a platform superadmin can change selected payments.');
    }

    private function facts($actor,array $ids): array
    {
        $sourceIds = collect($ids)->map(fn ($id) => (int)explode(':',$id)[0])->unique()->sort()->values();
        $payments = $this->report->authorizedQuery($actor)->with(['booking','ownedAllocations','paymentRefund'])->whereIn('id',$sourceIds)->orderBy('id')->get();
        abort_unless($payments->count() === $sourceIds->count(),404,'One or more selected payments are unavailable.');
        $bookingIds=$payments->pluck('booking_id')->filter()->unique();
        $subscriptionIds=$payments->pluck('subscription_id')->filter()->unique();
        $siblings=Payment::where(fn ($q) => $q->whereIn('id',$sourceIds)->orWhereIn('booking_id',$bookingIds)->orWhereIn('subscription_id',$subscriptionIds))
            ->with('ownedAllocations')->orderBy('id')->get();
        $groupKey=fn ($p) => $p->booking_id ? 'b'.$p->booking_id : ($p->subscription_id ? 's'.$p->subscription_id : 'p'.$p->id);
        $groups=$siblings->groupBy($groupKey);
        $rows=[]; $base=[]; $evidence=[]; $classified=[]; $original=[];
        foreach ($payments as $p) {
            $key=$groupKey($p);
            if (!isset($classified[$key])) {
                $raw=$groups[$key]->map(fn ($s) => $this->report->raw($s))->all();
                $b=$p->booking ? array_merge($p->booking->getAttributes(),['financial_context'=>$p->booking->financial_context ?? [],'creation_context'=>$p->booking->creation_context ?? []]) : [];
                $evidence['groups'][$key]=['payments'=>$raw,'booking'=>$b];
                $classified[$key]=PaymentReportRules::rows($raw,$b);
                foreach ($raw as &$s) unset($s['financial_context']['allocation_corrections']);
                unset($s);
                $original[$key]=PaymentReportRules::rows($raw,$b);
            }
            $evidence['refunds'][$p->id]=$p->paymentRefund?->getAttributes();
            $pick=fn ($r) => $r['row_kind'] !== 'receipt' && (int)($r['source_payment_id'] ?? $r['id']) === $p->id;
            foreach (array_filter($classified[$key],$pick) as $r) $rows[(string)$r['id']]=$r;
            foreach (array_filter($original[$key],$pick) as $r) $base[(string)$r['id']]=$r;
        }
        foreach ($ids as $id) abort_unless(isset($rows[$id]),409,'A selected allocation changed. Refresh and select it again.');
        return [$payments->keyBy('id'),$rows,$base,$evidence];
    }

    private function summary(array $row): array
    {
        return array_intersect_key($row,array_flip(['id','source_payment_id','payment_id','amount','currency','method','status','category','recipient_kind','recipient_label']));
    }

    public function preview($actor,array $data): array
    {
        $this->authorize($actor);
        $ids=$data['row_ids']; sort($ids,SORT_STRING);
        [$payments,$rows,$base,$evidence]=$this->facts($actor,$ids);
        $changes=[]; $excluded=[];
        foreach ($ids as $id) {
            $row=$rows[$id]; $source=(int)($row['source_payment_id'] ?? $row['id']); $p=$payments[$source];
            $reason=null; $after=$row;
            if ($data['action'] === 'mark_paid') {
                if ($row['status'] !== 'pending') $reason='Only pending payments can be marked paid.';
                elseif ($p->method === 'online' || $p->charge_id || $p->payment_intent_id) $reason='Online payments must be confirmed by Stripe.';
                elseif (!$p->booking || $p->booking->status === 'cancelled') $reason='An active venue booking is required for an offline collection.';
                elseif (!$row['currency'] || !is_numeric($p->amount) || $p->amount <= 0 || $row['category'] === 'unclassified') $reason='Resolve this payment’s financial evidence first.';
                elseif ($p->payment_refund_id || in_array('payment_skipped',[$p->booking->payment_method,$p->booking->payment_status],true)) $reason='Refunded or skipped bookings cannot be collected here.';
                else {
                    $siblings=array_filter($rows,fn ($r) => (int)($r['source_payment_id'] ?? $r['id']) === $source);
                    if (array_diff(array_column($siblings,'id'),$ids)) $reason='Select every allocation of this payment before marking it paid. Clear the allocation filter if needed.';
                    elseif (array_sum(array_map(fn ($r) => Money::toMinor($r['amount'],$r['currency']),$siblings)) !== Money::toMinor($p->amount,$p->currency)) $reason='The receipt and allocations must balance before collection.';
                }
                if (!$reason) { $after['status']='paid'; $after['method']=$data['method'] === 'card' ? 'credit_card' : $data['method']; }
            } else {
                $destinations=PaymentBulkRules::destinations($row);
                $fees=['provider_booking_fee','owned_platform_fee','provider_membership_fee','event_fee'];
                $target=collect($destinations)->first(fn ($value) => in_array($value,$fees,true) === (($data['destination'] ?? '') === 'platform_fee'));
                if ($p->payment_refund_id) $reason='Payments with refunds cannot be reassigned here.';
                elseif ($row['channel'] === 'admin' && ($data['destination'] ?? '') === 'platform_fee') $reason='Staff-added bookings have no platform fee. Their money stays with the venue business.';
                elseif (!in_array($target,PaymentBulkRules::destinations($row),true)) $reason='This destination does not match the existing business, booking channel or payment method.';
                elseif ($row['category'] === $target) $reason='Already assigned to this allocation.';
                else $after=PaymentBulkRules::assign($row,$target,'preview');
            }
            if ($reason) $excluded[]=['payment_id'=>$p->payment_id,'row_id'=>$id,'reason'=>$reason];
            else $changes[]=['row_id'=>$id,'payment_id'=>$p->id,'before'=>$this->summary($row),'after'=>$this->summary($after),
                'state_hash'=>PaymentBulkRules::hash($base[$id])];
        }
        $before=new PaymentReportTotals; $after=new PaymentReportTotals;
        foreach ($changes as $c) {
            $before->add($rows[$c['row_id']]);
            $after->add(array_merge($rows[$c['row_id']],$c['after']));
        }
        $old=collect($before->result())->keyBy('currency'); $impact=[];
        foreach ($after->result() as $total) {
            $delta=['currency'=>$total['currency']];
            foreach (['bookboll_received','platform_fees','owned_venue_received','provider_received','owned_pending','provider_pending','total_sales'] as $key)
                $delta[$key]=Money::toMajor(Money::toMinor($total[$key] ?? 0,$total['currency'])-Money::toMinor($old[$total['currency']][$key] ?? 0,$total['currency']),$total['currency']);
            $impact[]=$delta;
        }
        $fingerprint=hash('sha256',json_encode([$actor->id,$ids,$data['action'],$data['destination'] ?? null,$data['method'] ?? null,trim($data['reason']),$evidence,now()->toDateString()]));
        return ['fingerprint'=>$fingerprint,'action'=>$data['action'],'reason'=>trim($data['reason']),
            'collection_date'=>now()->toDateString(),'collection_method'=>$data['action'] === 'mark_paid' ? $data['method'] : null,'changes'=>$changes,'excluded'=>$excluded,'impact'=>$impact,
            'payment_count'=>count(array_unique(array_column($changes,'payment_id'))),'allocation_count'=>count($changes)];
    }

    public function save($actor,array $data): array
    {
        $this->authorize($actor);
        app(PaymentReleaseGate::class)->assertReady();
        return DB::transaction(function () use ($actor,$data) {
            $sourceIds=collect($data['row_ids'])->map(fn ($id) => (int)explode(':',$id)[0])->unique()->sort()->values();
            // Match existing collection/refund lock ordering; lock all sibling evidence.
            $sources=$this->report->authorizedQuery($actor)->whereIn('id',$sourceIds)->get();
            abort_unless($sources->count() === $sourceIds->count(),404);
            $bookings=$sources->pluck('booking_id')->filter()->unique()->sort()->values();
            Booking::whereIn('id',$bookings)->orderBy('id')->lockForUpdate()->get();
            $subscriptions=$sources->pluck('subscription_id')->filter()->unique()->sort()->values();
            \App\Models\ClassSubscription::whereIn('id',$subscriptions)->orderBy('id')->lockForUpdate()->get();
            Payment::where(fn ($q) => $q->whereIn('id',$sourceIds)->orWhereIn('booking_id',$bookings)->orWhereIn('subscription_id',$subscriptions))->orderBy('id')->lockForUpdate()->get();
            $prior=DB::table('payment_bulk_actions')->where('fingerprint',$data['fingerprint'])->where('actor_id',$actor->id)->first();
            if ($prior) return ['audit_id'=>$prior->id,'already_recorded'=>true];
            $plan=$this->preview($actor,$data);
            abort_unless(hash_equals($plan['fingerprint'],$data['fingerprint']),409,'Payments or your choices changed. Preview the action again.');
            abort_unless($plan['changes'],422,'There are no eligible changes to save.');
            [,,,$evidence]=$this->facts($actor,$data['row_ids']);
            $audit=DB::table('payment_bulk_actions')->insertGetId(['actor_id'=>$actor->id,'actor_name'=>$actor->name,
                'action'=>$data['action'],'reason'=>$plan['reason'],'fingerprint'=>$plan['fingerprint'],
                'evidence'=>json_encode($evidence),'outcome'=>json_encode($plan),'created_at'=>now()]);
            foreach (collect($plan['changes'])->groupBy('payment_id') as $paymentId=>$changes) {
                $payment=Payment::findOrFail($paymentId);
                if ($data['action'] === 'mark_paid') {
                    $response=app(BookingService::class)->dashboardCollect(Request::create('/','PUT',['method'=>$data['method']]),$payment->booking_id,$payment->id,true);
                    abort_unless($response->getStatusCode() === 200 && $response->getData(true)['success'],409,'A collection could not be saved. No changes were applied.');
                } else {
                    $context=$payment->financial_context ?? [];
                    foreach ($changes as $c) $context['allocation_corrections'][$c['row_id']]=['category'=>$c['after']['category'],'state_hash'=>$c['state_hash'],'audit_id'=>$audit];
                    $payment->forceFill(['financial_context'=>$context])->saveQuietly();
                }
                foreach ($changes as $c) DB::table('payment_bulk_action_items')->insert(['bulk_action_id'=>$audit,'payment_id'=>$paymentId,
                    'row_id'=>$c['row_id'],'before'=>json_encode($c['before']),'after'=>json_encode($c['after'])]);
                $payment->booking?->history()->create(['user_id'=>$actor->id,'action'=>'payment_bulk_action',
                    'description'=>'Superadmin payment action #'.$audit.' ('.$data['action'].'). Before/after evidence is saved in the payment audit.']);
            }
            return ['audit_id'=>$audit,'already_recorded'=>false,'payment_count'=>$plan['payment_count'],'allocation_count'=>$plan['allocation_count']];
        },3);
    }
}
