<?php

namespace App\Support;

/** Pure reporting rules. Never moves money or resolves today's commission rate. */
final class PaymentReportRules
{
    public const CATEGORIES = [
        'provider_booking_fee', 'owned_platform_fee', 'provider_membership_fee', 'event_fee',
        'owned_online', 'owned_onsite', 'owned_unallocated', 'provider_online', 'provider_arrival',
        'provider_manual', 'cancelled_uncollected', 'unclassified',
    ];

    public static function channel(array $booking): string
    {
        $creation = $booking['creation_context'] ?? [];
        if (is_string($creation)) $creation = json_decode($creation, true) ?: [];
        if (isset($creation['channel'])) return $creation['channel'];
        if (in_array($booking['origin'] ?? '', ['manual_admin', 'manual'], true)) return 'admin';
        return match ($booking['commission_channel'] ?? '') {
            'site' => 'site', 'marketplace' => 'marketplace',
            default => match ($booking['origin'] ?? '') {
                'bookboll_web', 'bookboll_app', 'customer_game' => 'marketplace',
                'provider_site', 'whitelabel', 'white_label' => 'site',
                default => 'unknown',
            },
        };
    }

    /** All siblings must be provided BEFORE filtering, including refunded rows. */
    public static function rows(array $payments, array $booking = []): array
    {
        $roots = [];
        $children = [];
        foreach ($payments as $p) {
            $type = $p['type'] ?? '';
            // A reviewed legacy deposit renamed as commission is itself the
            // original receipt; it has no extra provider split to match.
            if (($p['financial_context']['reconciliation_kind'] ?? '') === 'deposit_to_fee'
                && !empty($p['reconciliation_batch_id'])) continue;
            if (in_array($type, ['booking_commission', 'booking_earning', 'subscription_commission', 'subscription_earning'], true)) {
                $children[] = $p;
            } elseif (in_array($type, ['full', 'booking', 'remaining', 'balance'], true) && ($p['method'] ?? '') === 'online') {
                $roots[] = $p;
            }
        }
        $receiptIds = [];
        $childStatus = [];
        $childDates = [];
        $childReceipts = [];
        $unmatchedChildren = [];
        // Link by explicit receipt ids for new records. Legacy: accept only one
        // unambiguous receipt and a conserving pair, never arbitrary siblings.
        $groups = [];
        foreach ($children as $p) {
            $ids = $p['financial_context']['receipt_ids'] ?? [];
            sort($ids);
            $groups[$ids ? implode(',', $ids) : 'legacy'][] = $p;
        }
        foreach ($groups as $key => $group) {
            $ids = $key === 'legacy' ? [] : explode(',', $key);
            $parents = array_values(array_filter($roots, fn ($p) => !$ids || in_array((string) $p['id'], $ids, true)));
            // Membership services persist the two allocations directly, without
            // an extra gross receipt. These are already individual ledger amounts.
            if (!$parents && !$ids && count(array_filter($group, fn ($p) => ($p['source_type'] ?? '') === 'subscription')) === count($group)) continue;
            $currencies = array_unique(array_column(array_merge($parents, $group), 'currency'));
            $types = array_column($group, 'type');
            $pair = (in_array('booking_commission', $types, true) && in_array('booking_earning', $types, true))
                || (in_array('subscription_commission', $types, true) && in_array('subscription_earning', $types, true));
            $validAmounts = count(array_filter(array_merge($parents, $group), fn ($p) => is_numeric($p['amount'] ?? null) && (float) $p['amount'] >= 0)) === count($parents) + count($group);
            $unit = $currencies[0] ?? '';
            $conserves = $validAmounts && count($currencies) === 1 && $unit !== ''
                && array_sum(array_map(fn ($p) => Money::toMinor($p['amount'], $unit), $parents)) === array_sum(array_map(fn ($p) => Money::toMinor($p['amount'], $unit), $group));
            $parentStatuses = array_unique(array_map(fn ($p) => self::status($p['status'] ?? ''), $parents));
            $parentDates = array_unique(array_map(fn ($p) => substr((string) ($p['paid_at'] ?? $p['date'] ?? $p['created_at'] ?? ''), 0, 10), $parents));
            $valid = $pair && $conserves && count($parentStatuses) === 1 && count($parentDates) === 1
                && ($ids ? count($parents) === count($ids) : count($parents) === 1 && count($group) === 2);
            foreach ($group as $p) {
                if ($valid) {
                    $childStatus[(string) $p['id']] = $parentStatuses[0];
                    $childDates[(string) $p['id']] = $parentDates[0];
                    $childReceipts[(string) $p['id']] = array_column($parents, 'id');
                }
                else $unmatchedChildren[(string) $p['id']] = true;
            }
            if ($valid) foreach ($parents as $p) $receiptIds[(string) $p['id']] = true;
        }

        $rows = array_map(function ($p) use ($booking, $receiptIds, $childStatus, $childDates, $childReceipts, $unmatchedChildren) {
            $id = (string) $p['id'];
            $context = array_merge($booking['financial_context'] ?? [], $p['financial_context'] ?? []);
            $type = $p['type'] ?? 'full';
            $recipient = trim((string) ($p['recipient'] ?? ''));
            $ownership = $context['operator'] ?? 'unknown';
            $channel = !empty($booking['creation_context']) ? self::channel($booking) : ($context['channel'] ?? self::channel($booking));
            $status = $childStatus[$id] ?? self::status($p['status'] ?? '');
            $online = ($p['method'] ?? '') === 'online'; // terminal card ≠ Stripe
            $category = 'unclassified';
            $recipientKind = 'unknown';
            $reason = null;
            $event = ($p['source_type'] ?? '') === 'hosted_game'
                || (($booking['origin'] ?? '') === 'customer_game' && in_array($type, ['commission', 'service_fee'], true));
            $skipped = ($booking['payment_method'] ?? '') === 'payment_skipped'
                || ($booking['payment_status'] ?? '') === 'payment_skipped';
            if (isset($receiptIds[$id])) {
                $reason = 'Receipt split into the linked fee and provider-share records.';
            } elseif ($skipped && !$event) {
                $reason = 'Payment exists on a skipped-payment booking. Review before including it.';
            } elseif (isset($unmatchedChildren[$id])) {
                $reason = 'The fee and provider share could not be reconciled to one receipt.';
            } elseif ($event) {
                $category = 'event_fee'; $recipientKind = 'bookboll';
            } elseif ($ownership === 'bookboll') {
                $category = $online ? 'owned_online' : 'owned_onsite'; $recipientKind = 'bookboll';
            } elseif ($ownership === 'provider' && $channel === 'admin') {
                // Both parts of a reconciled receipt belong to the business;
                // creation channel, not payment method, determines this rule.
                $category = 'provider_manual'; $recipientKind = 'provider';
            } elseif ($channel === 'admin') {
                $reason = 'Staff booking has no platform fee; its historical venue operator needs review.';
            } elseif (in_array($type, ['booking_commission', 'subscription_commission'], true)) {
                $category = $type === 'subscription_commission' ? 'provider_membership_fee' : 'provider_booking_fee';
                $recipientKind = 'bookboll';
            } elseif (in_array($type, ['booking_earning', 'subscription_earning'], true)) {
                $category = 'provider_online'; $recipientKind = 'provider';
            } elseif ($ownership === 'provider' && $type === 'deposit' && $online) {
                $category = 'provider_booking_fee'; $recipientKind = 'bookboll';
            } elseif ($ownership === 'provider' && in_array($type, ['remaining', 'balance'], true)) {
                $category = $online ? 'provider_online' : 'provider_arrival'; $recipientKind = 'provider';
            } elseif ($ownership === 'provider' && !$online) {
                $category = 'provider_arrival'; $recipientKind = 'provider';
            } else {
                $reason = $ownership === 'provider'
                    ? 'Full online payment needs its recorded fee and provider-share allocations.'
                    : 'Historical ownership or recipient is not recorded. Confirm before attributing earnings.';
            }
            if ($status === 'pending' && (($booking['status'] ?? '') === 'cancelled' || ($booking['payment_status'] ?? '') === 'cancelled')) $status = 'cancelled';
            $currency = (string) ($p['currency'] ?? '');
            if ($currency === '' || !is_numeric($p['amount'] ?? null) || (float) $p['amount'] < 0) {
                $category = 'unclassified'; $recipientKind = 'unknown';
                $reason = 'Missing currency or invalid amount. Review this payment.';
            }
            if ($category === 'unclassified') $recipientKind = 'unknown';
            return array_merge($p, [
                'id' => $id, 'payment_type' => $type, 'status' => $status,
                'category' => $category, 'recipient_kind' => $recipientKind,
                'recipient_label' => $recipientKind === 'bookboll' ? 'Bookboll'
                    : ($recipientKind === 'provider' ? ($context['business_name'] ?? ($recipient ?: 'External provider')) : 'Needs review'),
                'business_id' => $context['business_id'] ?? null,
                'operator' => $ownership, 'channel' => $channel,
                'fee_rate' => $event ? ($context['event_fee_rate'] ?? null) : ($channel === 'admin' ? 0 :
                    (($context['reconciliation_kind'] ?? '') === 'deposit_to_fee' ? null : ($context['fee_rate'] ?? ($booking['commission_effective_pct'] ?? null)))),
                'row_kind' => isset($receiptIds[$id]) ? 'receipt' : 'allocation',
                'source_receipt_ids' => $childReceipts[$id] ?? [],
                'review_reason' => $reason, 'snapshot_recorded' => !empty($context),
                'payment_date' => $childDates[$id] ?? substr((string) ($p['paid_at'] ?? $p['date'] ?? $p['created_at'] ?? ''), 0, 10),
            ]);
        }, $payments);
        return PaymentReviewRules::apply(array_merge(...array_map(fn ($row) => OwnedVenueAllocation::expand($row), $rows)), $payments, $booking);
    }

    public static function status(string $status): string
    {
        return $status === 'unpaid' ? 'pending' : $status;
    }
}
