<?php

namespace App\Support;

/** Each breakdown uses the very same minor-unit accumulator as Payments. */
final class PaymentReportAnalytics
{
    private array $groups = [];
    private string $grain;
    private ?string $from;
    private ?string $to;
    private int $undated = 0;

    public function __construct(array $rows, array $filters)
    {
        $dates = array_values(array_filter(array_map(fn ($row) => self::date($row), $rows)));
        sort($dates);
        $this->from = $filters['from_date'] ?? ($dates[0] ?? null);
        $this->to = $filters['to_date'] ?? ($dates[count($dates) - 1] ?? null);
        $days = $this->from && $this->to ? (strtotime($this->to) - strtotime($this->from)) / 86400 : 0;
        $this->grain = $days > 120 ? 'month' : 'day';
    }

    private static function date(array $row): ?string
    {
        $date = substr($row['payment_date'] ?? '', 0, 10);
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        return $parsed && $parsed->format('Y-m-d') === $date ? $date : null;
    }

    public function add(array $row): void
    {
        if ($row['row_kind'] === 'receipt' || in_array($row['category'], ['unclassified', 'cancelled_uncollected'], true)) return;
        if (!in_array($row['status'], ['paid', 'pending', 'refunded'], true)) return;
        $date = self::date($row);
        if ($date) $this->bucket('timeline', $this->grain === 'month' ? substr($date, 0, 7) : $date, $row);
        else $this->undated++;
        $this->bucket('channels', $row['channel'] ?: 'unknown', $row);
        $this->bucket('methods', ($row['method'] === 'credit_card' ? 'card' : $row['method']) ?: 'unknown', $row);
        $this->bucket('venues', (string)($row['venue_id'] ?? 'unassigned'), $row, $row['venue_name'] ?? 'No venue');
    }

    private function bucket(string $group, string $key, array $row, ?string $label = null): void
    {
        $this->groups[$group][$key] ??= ['key'=>$key, 'label'=>$label ?? $key, 'totals'=>new PaymentReportTotals];
        $this->groups[$group][$key]['totals']->add($row);
    }

    public function result(): array
    {
        // Fill periods without payments so chart lines never imply missing days
        // are adjacent. Bound output for corrupt/implausibly old source dates.
        if ($this->from && $this->to) {
            $start = new \DateTimeImmutable($this->from);
            if ($this->grain === 'month') $start = $start->modify('first day of this month');
            $end = new \DateTimeImmutable($this->to);
            for ($i = 0, $date = $start; $date <= $end && $i < 1200; $i++, $date = $date->modify('+1 '.$this->grain)) {
                $key = $date->format($this->grain === 'month' ? 'Y-m' : 'Y-m-d');
                $this->groups['timeline'][$key] ??= ['key'=>$key, 'label'=>$key, 'totals'=>new PaymentReportTotals];
            }
        }
        $out = ['grain'=>$this->grain, 'undated_allocations'=>$this->undated];
        foreach (['timeline', 'channels', 'methods', 'venues'] as $type) {
            $groups = $this->groups[$type] ?? [];
            ksort($groups);
            $out[$type] = array_values(array_map(fn ($group) => [
                'key'=>$group['key'], 'label'=>$group['label'], 'summary'=>$group['totals']->result(),
            ], $groups));
        }
        return $out;
    }
}
