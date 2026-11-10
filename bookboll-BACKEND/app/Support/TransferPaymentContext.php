<?php

namespace App\Support;

use App\Models\Booking;
use App\Models\Business;

final class TransferPaymentContext
{
    /** Called only after the reviewed historical provider assertion is accepted. */
    public static function make(Booking $booking, Business $business, string $batch, string $kind): array
    {
        $prior = $booking->financial_context ?? [];
        $channel = $prior['channel'] ?? PaymentReportRules::channel($booking->getAttributes());
        return array_merge($prior, [
            'version'=>1, 'operator'=>'provider', 'business_id'=>$business->id,
            'business_name'=>$business->name, 'provider_recipient'=>PaymentRecipient::forBusiness($business),
            'channel'=>$channel, 'fee_rate'=>$channel === 'admin' ? 0 : $booking->commission_effective_pct,
            'venue_id'=>$booking->venue_id, 'reconciliation_batch_id'=>$batch,
            'reconciliation_kind'=>$kind, 'recorded_at'=>now()->toIso8601String(),
            'ownership_basis'=>'reviewed_legacy_provider_history',
        ]);
    }
}
