<?php
namespace App\Console\Commands;

use App\Models\Payment;
use App\Services\HistoricalRetainedDepositReconciliation;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ReconcileHistoricalRetainedDeposits extends Command
{
    protected $signature = 'payments:reconcile-retained-deposits {--apply : Classify the three owner-confirmed paid deposits}';
    protected $description = 'Correct three specifically confirmed legacy external-provider deposit receipts';

    public function handle(HistoricalRetainedDepositReconciliation $reconciliation): int
    {
        if ($this->option('apply') && (!config('preview.workspace') || DB::connection()->getDatabaseName() !== 'bookboll_preview')) {
            $this->error('Writes are restricted to the isolated Railway workspace database.'); return self::FAILURE;
        }
        $count = 0;
        foreach (Payment::whereIn('payment_id',array_keys(HistoricalRetainedDepositReconciliation::RECEIPTS))
            ->with(['booking','ownedAllocations'])->get() as $payment) {
            if (!$reconciliation->eligible($payment)) continue;
            if (!$this->option('apply') || $reconciliation->apply($payment)) $count++;
        }
        $this->info(($this->option('apply')?'Applied':'Dry run').': '.$count.' owner-confirmed retained deposits.');
        $this->info('Amounts, statuses, methods, dates and original timestamps unchanged; no charge or payout created.');
        return self::SUCCESS;
    }
}
