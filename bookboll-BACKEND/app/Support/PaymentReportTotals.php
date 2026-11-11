<?php

namespace App\Support;

final class PaymentReportTotals
{
    private array $currencies = [];
    public int $reviewCount = 0;
    public int $feeSplitCount = 0;
    public array $feeSplitStatuses = [];

    public function add(array $row): void
    {
        if ($row['row_kind'] === 'receipt') return;
        if ($row['category'] === 'cancelled_uncollected') return;
        if ($row['category'] === 'unclassified') { $this->reviewCount++; return; }
        if ($row['category'] === 'owned_unallocated') {
            $this->feeSplitCount++;
            $this->feeSplitStatuses[$row['status']] = ($this->feeSplitStatuses[$row['status']] ?? 0) + 1;
        }
        $currency = $row['currency'];
        $amount = Money::toMinor($row['amount'], $currency);
        $this->currencies[$currency] ??= array_fill_keys([
            'bookboll_received', 'platform_fees', 'marketplace_fees', 'site_fees', 'other_channel_fees',
            'event_fees', 'membership_fees', 'owned_online', 'owned_onsite', 'owned_pending',
            'provider_received', 'provider_online', 'provider_arrival', 'provider_manual', 'provider_pending',
            'provider_received_online', 'provider_received_offline', 'provider_received_other',
            'provider_sales', 'refunded',
            'owned_platform_fees', 'external_booking_fees', 'owned_venue_received', 'owned_venue_pending', 'owned_platform_pending',
            'external_marketplace_fees', 'external_site_fees', 'owned_marketplace_fees', 'owned_site_fees',
            'owned_unallocated', 'owned_unallocated_pending',
        ], 0);
        $s = &$this->currencies[$currency];
        $category = $row['category'];
        $status = $row['status'];
        $providerSale = $row['operator'] === 'provider' && in_array($row['channel'], ['marketplace', 'site'], true)
            && in_array($category, ['provider_booking_fee', 'provider_online', 'provider_arrival'], true);
        if ($providerSale && in_array($status, ['paid', 'pending'], true)) $s['provider_sales'] += $amount;
        if ($status === 'refunded') { $s['refunded'] += $amount; return; }
        if ($status === 'pending') {
            if (in_array($category, ['owned_online', 'owned_onsite', 'owned_platform_fee', 'owned_unallocated'], true)) {
                $s['owned_pending'] += $amount;
                $s[match($category) {'owned_platform_fee'=>'owned_platform_pending', 'owned_unallocated'=>'owned_unallocated_pending', default=>'owned_venue_pending'}] += $amount;
            }
            if ($row['recipient_kind'] === 'provider') $s['provider_pending'] += $amount;
            return;
        }
        if ($status !== 'paid') return;
        if ($row['recipient_kind'] === 'bookboll') $s['bookboll_received'] += $amount;
        if ($row['recipient_kind'] === 'provider') {
            $s['provider_received'] += $amount;
            $methodBucket = match ($row['method'] ?? '') {
                'online' => 'provider_received_online',
                'cash', 'card', 'credit_card', 'cheque', 'wire_transfer' => 'provider_received_offline',
                default => 'provider_received_other',
            };
            $s[$methodBucket] += $amount;
        }
        if (array_key_exists($category, $s)) $s[$category] += $amount;
        if ($category === 'event_fee') $s['event_fees'] += $amount;
        if ($category === 'provider_membership_fee') $s['membership_fees'] += $amount;
        if ($category === 'owned_platform_fee') $s['owned_platform_fees'] += $amount;
        if ($category === 'provider_booking_fee') $s['external_booking_fees'] += $amount;
        if (in_array($category, ['owned_online','owned_onsite'], true)) $s['owned_venue_received'] += $amount;
        if (in_array($category, ['provider_booking_fee', 'owned_platform_fee', 'provider_membership_fee', 'event_fee'], true)) $s['platform_fees'] += $amount;
        if (in_array($category, ['provider_booking_fee','owned_platform_fee'], true)) {
            $s[match ($row['channel']) { 'marketplace' => 'marketplace_fees', 'site' => 'site_fees', default => 'other_channel_fees' }] += $amount;
            if (in_array($row['channel'], ['marketplace','site'], true)) {
                $s[($category === 'owned_platform_fee' ? 'owned_' : 'external_').$row['channel'].'_fees'] += $amount;
            }
        }
    }

    public function result(): array
    {
        ksort($this->currencies);
        $out = [];
        foreach ($this->currencies as $currency => $amounts) {
            $out[] = ['currency' => $currency] + array_map(fn ($minor) => Money::toMajor($minor, $currency), $amounts);
        }
        return $out;
    }
}
