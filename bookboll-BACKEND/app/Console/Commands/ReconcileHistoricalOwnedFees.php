<?php
namespace App\Console\Commands;

use App\Models\Payment;
use App\Services\HistoricalOwnedFeeReconciliation;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ReconcileHistoricalOwnedFees extends Command
{
    protected $signature = 'payments:reconcile-owned-history {--apply : Save the owner-approved missing legacy splits}';
    protected $description = 'Apply the approved 20% historical customer fee only where own-venue splits are missing';

    public function handle(HistoricalOwnedFeeReconciliation $reconciliation): int
    {
        if ($this->option('apply') && (!config('preview.workspace') || DB::connection()->getDatabaseName() !== 'bookboll_preview')) {
            $this->error('Writes are restricted to the isolated Railway workspace database.'); return self::FAILURE;
        }
        $count = 0; $totals = [];
        Payment::where('financial_context->operator','bookboll')
            ->where('financial_context->ownership_basis','preview_owner_confirmed_2026_11_10')
            ->whereDoesntHave('ownedAllocations')->with(['booking','ownedAllocations'])
            ->chunkById(250,function($payments)use($reconciliation,&$count,&$totals){
                $process=function()use($payments,$reconciliation,&$count,&$totals){
                    foreach($payments as $payment){
                        $plan=$reconciliation->plan($payment);
                        if(!$plan || ($this->option('apply') && !$reconciliation->apply($payment))) continue;
                        $count++;
                        $key=$payment->currency.' '.$payment->status;
                        $totals[$key]??=['receipts_minor'=>0,'platform_minor'=>0,'venue_minor'=>0];
                        $totals[$key]['receipts_minor']+=array_sum(array_column($plan,'amount_minor'));
                        foreach($plan as $allocation) $totals[$key][$allocation['bucket'].'_minor']+=$allocation['amount_minor'];
                    }
                };
                if($this->option('apply')) DB::transaction($process); else $process();
            });
        $this->info(($this->option('apply')?'Applied':'Dry run').': '.$count.' missing customer splits; '.json_encode($totals));
        $this->info('Receipt amounts, methods, statuses and dates unchanged. Manual bookings and saved fee splits unchanged.');
        return self::SUCCESS;
    }
}
