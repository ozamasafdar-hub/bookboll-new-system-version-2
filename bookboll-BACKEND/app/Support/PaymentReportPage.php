<?php

namespace App\Support;

/** Page normalized allocations, including children dated to their receipt. */
final class PaymentReportPage
{
    private \SplPriorityQueue $queue;
    private int $limit;

    public function __construct(private int $offset, private int $perPage, string $sort, string $direction)
    {
        $this->limit = $offset + $perPage;
        $this->queue = new class($sort, $direction) extends \SplPriorityQueue {
            public function __construct(private string $sort, private string $direction) {}

            public function compare(mixed $a, mixed $b): int
            {
                $key = $this->sort === 'date' ? 'payment_date' : $this->sort;
                $comparison = $this->sort === 'amount'
                    ? (float) $a[$key] <=> (float) $b[$key]
                    : strcmp((string) ($a[$key] ?? ''), (string) ($b[$key] ?? ''));
                $comparison = $comparison ?: ((int) ($a['sort_id'] ?? $a['id']) <=> (int) ($b['sort_id'] ?? $b['id']));
                $comparison = $comparison ?: strcmp((string) $a['id'], (string) $b['id']);
                // The greatest priority is the last (worst) row in the desired
                // ordering, so it can be discarded when the window is full.
                return $this->direction === 'asc' ? $comparison : -$comparison;
            }
        };
        $this->queue->setExtractFlags(\SplPriorityQueue::EXTR_DATA);
    }

    public function add(array $row): void
    {
        $this->queue->insert($row, $row);
        if ($this->queue->count() > $this->limit) $this->queue->extract();
    }

    public function rows(): array
    {
        $queue = clone $this->queue;
        $rows = [];
        while (!$queue->isEmpty()) $rows[] = $queue->extract();
        return array_slice(array_reverse($rows), $this->offset, $this->perPage);
    }
}
