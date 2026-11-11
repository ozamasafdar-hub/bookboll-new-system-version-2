<?php
namespace App\Support;

/** Checkout payments contain booking allocations; parents never add revenue. */
final class PaymentReportGroups
{
    private array $keys = [], $counts = [], $references = [], $kinds = [], $paymentReferences = [];

    public function __construct(array $rows)
    {
        $charges = []; $bookingCheckouts = []; $online = []; $checkoutCharges = [];
        foreach ($rows as $r) {
            if (($r['method'] ?? '') === 'online' && !empty($r['charge_id']) && !empty($r['payment_intent_id']))
                $charges[$r['currency'].'|'.$r['payment_intent_id']][$r['charge_id']] = true;
            if (!empty($r['checkout_reference']) && !empty($r['charge_id']))
                $checkoutCharges[$r['checkout_reference']][$r['charge_id']] = true;
        }
        foreach ($rows as $r) {
            if (empty($r['booking_id']) || empty($r['currency']) || ($r['row_kind'] ?? '') === 'receipt') continue;
            if (($r['method'] ?? '') !== 'online') continue;
            $charge = $r['charge_id'] ?? null; $intent = $r['payment_intent_id'] ?? null;
            $candidates = $intent ? array_keys($charges[$r['currency'].'|'.$intent] ?? []) : [];
            if (!$charge && count($candidates) > 1) continue;
            $identity = $charge ?: ($candidates[0] ?? ($intent ? 'intent:'.$intent : null));
            $checkout = $r['checkout_reference'] ?? null;
            if (!$identity && !$checkout) continue;
            $checkout ??= 'CHK-'.strtoupper(substr(hash('sha256',$r['currency'].'|'.$identity),0,16));
            $stableCheckout = !empty($r['checkout_reference']) && count($checkoutCharges[$checkout] ?? []) <= 1;
            $key = 'online:'.hash('sha256',$r['currency'].'|'.($stableCheckout ? $checkout : ($identity ?: $checkout)));
            $online[(string)$r['id']] = [$key,$checkout];
            if (($r['type'] ?? '') === 'deposit') $bookingCheckouts[$r['booking_id']][$checkout] = true;
        }
        foreach ($rows as $r) {
            if (empty($r['booking_id']) || empty($r['currency']) || ($r['row_kind'] ?? '') === 'receipt') continue;
            $id = (string)$r['id'];
            if (isset($online[$id])) {
                [$key,$checkout] = $online[$id]; $kind = 'online';
            } elseif (($r['method'] ?? '') !== 'online' && in_array($r['type'] ?? '', ['remaining','full'], true)) {
                $candidates = array_keys($bookingCheckouts[$r['booking_id']] ?? []);
                $checkout = $r['checkout_reference'] ?? (count($candidates) === 1 ? $candidates[0] : null);
                if (!$checkout) continue;
                // Group only the same collector, customer and currency.
                $collector = ($r['operator'] ?? '').'|'.($r['business_id'] ?? '').'|'.($r['customer_id'] ?? $r['customer_email'] ?? '');
                $key = 'remaining:'.hash('sha256',$checkout.'|'.$r['currency'].'|'.$collector); $kind = 'remaining';
            } else continue;
            $this->keys[$id] = $key;
            $this->counts[$key] = ($this->counts[$key] ?? 0) + 1;
            $this->references[$key] = $checkout;
            $this->kinds[$key] = $kind;
            $this->paymentReferences[$key] = $checkout.'-'.($kind === 'online' ? 'ONLINE' : 'BAL').'-'.strtoupper(substr(explode(':',$key,2)[1],0,6));
        }
    }

    public function key(array $r): ?string { return $this->keys[(string)$r['id']] ?? null; }

    public function decorate(array $r): array
    {
        if ($key = $this->key($r)) {
            $r['checkout_reference'] = $this->references[$key];
            $r['checkout_payment_reference'] = $this->paymentReferences[$key];
        }
        return $r;
    }

    public function entries(array $matching, bool $platform): array
    {
        $groups = []; $entries = [];
        foreach ($matching as $r) {
            $r = $this->decorate($r); $key = $this->key($r);
            if ($key && $this->counts[$key] > 1) $groups[$key][] = $r;
            else $entries[] = $r;
        }
        foreach ($groups as $key => $children) {
            $first = $children[0]; $kind = $this->kinds[$key];
            $bookings = []; $minor = 0; $paid = 0; $pending = 0;
            foreach ($children as $r) {
                $amount = Money::toMinor($r['amount'],$r['currency']); $minor += $amount;
                if ($r['status'] === 'paid') $paid += $amount;
                if ($r['status'] === 'pending') $pending += $amount;
                $bookings[$r['booking_id']] ??= ['id'=>$r['booking_id'],'reference'=>$r['booking_ref'],
                    'venue_name'=>$r['venue_name'],'listing_name'=>$r['listing_name'],'allocations'=>[]];
                $bookings[$r['booking_id']]['allocations'][] = $r;
            }
            $unique = fn ($field,$fallback) => count(array_unique(array_column($children,$field))) === 1 ? ($first[$field] ?? $fallback) : $fallback;
            $partial = count($children) !== $this->counts[$key];
            $entries[] = [
                'id'=>$key, 'row_kind'=>$kind === 'online' ? 'online_group' : 'remaining_group', 'kind'=>$kind,
                'payment_id'=>$first['checkout_payment_reference'],'checkout_reference'=>$first['checkout_reference'],
                'title'=>$kind === 'online' ? ($unique('type','full') === 'deposit' ? 'Online deposit' : 'Online payment') : 'Remaining payment',
                'method'=>$kind === 'online' ? 'online' : $unique('method','mixed'), 'currency'=>$first['currency'],
                'amount'=>Money::toMajor($minor,$first['currency']), 'paid_amount'=>Money::toMajor($paid,$first['currency']),
                'pending_amount'=>Money::toMajor($pending,$first['currency']),
                'payment_date'=>max(array_column($children,'payment_date')),
                'sort_id'=>max(array_map(fn ($r)=>(int)($r['source_payment_id'] ?? $r['id']),$children)),
                'status'=>$unique('status', $paid > 0 && $pending > 0 ? 'partially_paid' : 'mixed'),
                'customer_name'=>$unique('customer_name','Multiple customers'),'venue_name'=>$unique('venue_name','Multiple venues'),
                'channel'=>$unique('channel','mixed'),'recipient_label'=>$unique('recipient_label','Multiple recipients'),
                'booking_count'=>count($bookings),'allocation_count'=>count($children),'partial'=>$partial,'scoped'=>!$platform,
                'bookings'=>array_values($bookings),'anchor'=>$first,
                'can_request_refund'=>$kind === 'online' && !$partial && $platform && collect($children)->every(fn ($r)=>!empty($r['can_request_refund'])),
            ];
        }
        return $entries;
    }
}
