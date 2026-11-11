<?php
namespace App\Services;

use App\Models\{Booking, Payment};
use App\Support\{Money, PaymentRefundAccess, PaymentReleaseGate, PaymentReportRules, PaymentReportTotals, PaymentReviewRules};
use Illuminate\Support\Facades\DB;

final class PaymentReviewService
{
    public function __construct(private PaymentReportService $report) {}

    private function facts($actor, int $id): array
    {
        abort_unless(PaymentRefundAccess::allows($actor),403,'Only platform admins and super admins can resolve payment reviews.');
        $payment = $this->report->authorizedQuery($actor)->with(['booking','ownedAllocations'])->findOrFail($id);
        $siblings = $payment->booking_id ? Payment::where('booking_id',$payment->booking_id)->with('ownedAllocations')->get()
            : ($payment->subscription_id ? Payment::where('subscription_id',$payment->subscription_id)->with('ownedAllocations')->get() : collect([$payment]));
        abort_unless($this->report->authorizedQuery($actor)->whereIn('id',$siblings->modelKeys())->count() === $siblings->count(),403);
        $raw = $siblings->map(fn ($p) => $this->report->raw($p))->all();
        $booking = $payment->booking ? array_merge($payment->booking->getAttributes(),[
            'financial_context'=>$payment->booking->financial_context ?? [],'creation_context'=>$payment->booking->creation_context ?? []]) : [];
        return [$payment,$raw,$booking];
    }

    public function information($actor, int $id): ?array
    {
        if (!PaymentRefundAccess::allows($actor)) return null;
        [$p,$raw,$b] = $this->facts($actor,$id);
        $rows = $this->rows($raw,$b,$id);
        $row = $rows[0];
        $decision = $p->financial_context['review_decision'] ?? null;
        $history = DB::table('payment_review_decisions')->where('payment_id',$id)->orderByDesc('id')->get(['id','actor_name','action','reason','created_at'])->all();
        if ($row['category'] !== 'unclassified' && !$decision && !$history) return null;
        $options = PaymentReviewRules::options($this->report->raw($p),$b,$raw,$row);
        $resolved = !empty($row['review_decision_id']);
        if ($decision) $options[] = 'reopen';
        if ($row['category'] === 'unclassified') $options[] = 'note';
        return ['options'=>$options,'resolved'=>$resolved,'stale'=>(bool)$decision && !$resolved,
            'history'=>$history,'evidence'=>[
                'original_status'=>$p->status,'booking_status'=>$p->booking?->status,'paid_at'=>$p->paid_at?->toISOString(),
                'processor_reference'=>$p->charge_id ?: $p->payment_intent_id,
                'connected_records'=>count($raw)-1,'business_name'=>$p->financial_context['business_name'] ?? null,
            ]];
    }

    private function rows(array $raw, array $b, int $id): array
    {
        return array_values(array_filter(PaymentReportRules::rows($raw,$b), fn ($r) => (int)($r['source_payment_id'] ?? $r['id']) === $id));
    }

    public function preview($actor, int $id, array $data): array
    {
        [$p,$raw,$b] = $this->facts($actor,$id);
        $info = $this->information($actor,$id);
        abort_unless(in_array($data['action'],$info['options'] ?? [],true),422,'This action is not available for the current payment evidence. Refresh the payment and review again.');
        $fee = null;
        if ($data['action'] === 'provider_split') {
            $input = (string)($data['fee_amount'] ?? '');
            $scale = Money::exponent($p->currency);
            $pattern = $scale ? '/^\d{1,10}(?:\.\d{1,'.$scale.'})?$/D' : '/^\d{1,10}$/D';
            abort_unless(preg_match($pattern,$input),422,'Enter an exact platform fee amount in the payment currency.');
            $fee = Money::toMinor($input,$p->currency);
            abort_unless($fee <= Money::toMinor($p->amount,$p->currency),422,'The platform fee cannot exceed the original payment.');
        }
        $before = $this->rows($raw,$b,$id);
        $state = PaymentReviewRules::hash($raw,$b);
        $lastId = DB::table('payment_review_decisions')->where('payment_id',$id)->max('id');
        $fingerprint = hash('sha256',json_encode([$actor->id,$id,$state,$lastId,$p->financial_context['review_decision'] ?? null,$data['action'],trim($data['reason']),$fee]));
        $decision = ['id'=>'preview','action'=>$data['action'],'state_hash'=>$state,'fee_minor'=>$fee];
        foreach ($raw as &$item) {
            if ((int)$item['id'] !== $id) continue;
            if ($data['action'] === 'reopen') unset($item['financial_context']['review_decision']);
            elseif ($data['action'] !== 'note') $item['financial_context']['review_decision'] = $decision;
        }
        unset($item);
        $after = $this->rows($raw,$b,$id);
        $totals = function ($rows) { $t = new PaymentReportTotals; foreach ($rows as $r) $t->add($r); return $t->result()[0] ?? []; };
        $a = $totals($after); $z = $totals($before);
        $change = [];
        foreach (['bookboll_received','platform_fees','provider_received'] as $key) $change[$key] = round(($a[$key] ?? 0)-($z[$key] ?? 0),Money::exponent($p->currency));
        return ['fingerprint'=>$fingerprint,'payment_id'=>$id,'reference'=>$p->payment_id,'action'=>$data['action'],
            'reason'=>trim($data['reason']),'fee_minor'=>$fee,'state_hash'=>$state,
            'amount'=>(float)$p->amount,'currency'=>$p->currency,'change'=>$change,
            'before'=>$this->summary($before),'after'=>$this->summary($after),
            'remains_in_review'=>collect($after)->contains('category','unclassified')];
    }

    private function summary(array $rows): array
    {
        return array_map(fn ($r) => array_intersect_key($r,array_flip(['category','recipient_label','amount','currency','status'])),$rows);
    }

    public function save($actor, int $id, array $data): array
    {
        app(PaymentReleaseGate::class)->assertReady();
        [$p] = $this->facts($actor,$id);
        return DB::transaction(function () use ($actor,$id,$data,$p) {
            if ($p->booking_id) Booking::whereKey($p->booking_id)->lockForUpdate()->firstOrFail();
            if ($p->booking_id) Payment::where('booking_id',$p->booking_id)->orderBy('id')->lockForUpdate()->get();
            elseif ($p->subscription_id) Payment::where('subscription_id',$p->subscription_id)->orderBy('id')->lockForUpdate()->get();
            else Payment::whereKey($id)->lockForUpdate()->firstOrFail();
            $saved = DB::table('payment_review_decisions')->where('fingerprint',$data['fingerprint'])->where('actor_id',$actor->id)->where('payment_id',$id)->first();
            if ($saved) return ['decision_id'=>$saved->id,'already_recorded'=>true];
            $plan = $this->preview($actor,$id,$data);
            abort_unless(hash_equals($plan['fingerprint'],$data['fingerprint']),409,'Payment evidence or your decision changed. Preview the action again.');
            [$payment,$raw,$b] = $this->facts($actor,$id);
            $decisionId = DB::table('payment_review_decisions')->insertGetId([
                'payment_id'=>$id,'actor_id'=>$actor->id,'actor_name'=>$actor->name,'action'=>$data['action'],
                'reason'=>$plan['reason'],'fingerprint'=>$plan['fingerprint'],
                'evidence'=>json_encode(['payments'=>$raw,'booking'=>$b]),'outcome'=>json_encode($plan),'created_at'=>now(),
            ]);
            $context = $payment->financial_context ?? [];
            if ($data['action'] === 'reopen') unset($context['review_decision']);
            elseif ($data['action'] !== 'note') $context['review_decision'] = ['id'=>$decisionId,'action'=>$data['action'],
                'state_hash'=>$plan['state_hash'],'fee_minor'=>$plan['fee_minor']];
            // Keep original receipt amount, status, type, dates and processor identity intact.
            if ($data['action'] !== 'note') Payment::withoutTimestamps(fn () => $payment->forceFill(['financial_context'=>$context])->saveQuietly());
            $payment->booking?->history()->create(['user_id'=>$actor->id,'action'=>'payment_review',
                'description'=>'Payment '.$payment->payment_id.' reviewed: '.$data['action'].'. Evidence is saved in the platform review audit.']);
            return ['decision_id'=>$decisionId,'already_recorded'=>false];
        });
    }
}
