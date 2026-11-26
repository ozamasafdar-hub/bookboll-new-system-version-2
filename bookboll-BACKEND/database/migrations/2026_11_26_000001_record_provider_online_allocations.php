<?php

use App\Models\Payment;
use App\Support\ProviderOnlineAllocation;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        // Restore only provable splits from immutable booking/receipt snapshots.
        // Never use current geography rates, infer a legacy fee, or overwrite a split.
        Payment::where('method','online')->whereIn('type',['full','booking'])
            ->where('financial_context->operator','provider')->whereDoesntHave('ownedAllocations')
            ->orderBy('id')->chunkById(250, function ($payments) {
                foreach ($payments as $candidate) DB::transaction(function () use ($candidate) {
                    $payment = Payment::with('booking')->lockForUpdate()->findOrFail($candidate->id);
                    if ($payment->ownedAllocations()->exists()) return;
                    // Existing explicit/legacy ledger children remain authoritative.
                    if ($payment->booking?->payments()->whereIn('type',['booking_commission','booking_earning'])->exists()) return;
                    $context = array_merge($payment->booking?->financial_context ?? [], $payment->financial_context ?? []);
                    $plan = ProviderOnlineAllocation::plan($payment->getAttributes(), $context);
                    if ($plan) $payment->ownedAllocations()->createMany($plan);
                });
            });
    }
    public function down(): void
    {
        // Financial attribution is durable; a code rollback must not erase it.
    }
};
