<?php
namespace App\Support;

/** Presentation only: group authorized allocations, never add a second receipt. */
final class PaymentReportGroups
{
    private array $keys = [];
    private array $counts = [];
    private array $balances = [];

    public function __construct(array $authorizedRows)
    {
        $charges = [];
        foreach ($authorizedRows as $r) {
            if (($r['method'] ?? '') !== 'online') continue;
            if (!empty($r['charge_id']) && !empty($r['payment_intent_id'])) {
                $charges[$r['currency'].'|'.$r['payment_intent_id']][$r['charge_id']] = true;
            }
        }
        foreach ($authorizedRows as $r) {
            if (($r['type'] ?? '') === 'remaining' && ($r['method'] ?? '') !== 'online' && !empty($r['booking_id'])) {
                $this->balances[$r['booking_id']][] = $r;
            }
            if (($r['method'] ?? '') !== 'online' || empty($r['currency']) || empty($r['booking_id']) || ($r['row_kind'] ?? '') === 'receipt') continue;
            $charge = $r['charge_id'] ?? null;
            $intent = $r['payment_intent_id'] ?? null;
            $candidates = $intent ? array_keys($charges[$r['currency'].'|'.$intent] ?? []) : [];
            // A payment intent may have several charge attempts. Never merge them.
            if (!$charge && count($candidates) > 1) continue;
            $identity = $charge ?: ($candidates[0] ?? ($intent ? 'intent:'.$intent : null));
            if (!$identity) continue;
            $key = 'online:'.hash('sha256', $r['currency'].'|'.$identity);
            $this->keys[(string)$r['id']] = $key;
            $this->counts[$key] = ($this->counts[$key] ?? 0) + 1;
        }
    }

    public function key(array $row): ?string { return $this->keys[(string)$row['id']] ?? null; }

    /** Filters apply to allocations first; paginate these groups only afterwards. */
    public function entries(array $matching, bool $platform): array
    {
        $groups = []; $entries = [];
        foreach ($matching as $r) {
            $key = $this->key($r);
            if ($key && $this->counts[$key] > 1) $groups[$key][] = $r;
            else $entries[] = $r;
        }
        foreach ($groups as $key => $children) {
            $first = $children[0];
            $bookings = []; $minor = 0;
            foreach ($children as $r) {
                $minor += Money::toMinor($r['amount'], $r['currency']);
                $bookings[$r['booking_id']] ??= ['id'=>$r['booking_id'], 'reference'=>$r['booking_ref'],
                    'venue_name'=>$r['venue_name'], 'listing_name'=>$r['listing_name'], 'online'=>[],
                    'arrival_balances'=>$this->balances[$r['booking_id']] ?? []];
                $bookings[$r['booking_id']]['online'][] = $r;
            }
            $unique = fn ($field, $fallback) => count(array_unique(array_column($children, $field))) === 1 ? ($first[$field] ?? $fallback) : $fallback;
            $partial = count($children) !== $this->counts[$key];
            $entries[] = [
                'id'=>$key, 'row_kind'=>'online_group', 'method'=>'online', 'currency'=>$first['currency'],
                'amount'=>Money::toMajor($minor, $first['currency']),
                'payment_date'=>max(array_column($children, 'payment_date')),
                'status'=>$unique('status','mixed'), 'customer_name'=>$unique('customer_name','Multiple customers'),
                'venue_name'=>$unique('venue_name','Multiple venues'), 'channel'=>$unique('channel','mixed'),
                'recipient_label'=>$unique('recipient_label','Multiple recipients'),
                'booking_count'=>count($bookings), 'allocation_count'=>count($children),
                'partial'=>$partial, 'scoped'=>!$platform, 'bookings'=>array_values($bookings),
                // The existing detail/refund endpoints still authorize the real row.
                'anchor'=>$first,
                'can_request_refund'=>!$partial && $platform && collect($children)->every(fn ($r) => !empty($r['can_request_refund'])),
            ];
        }
        return $entries;
    }
}
