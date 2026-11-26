<?php

use App\Models\{Booking, Business, Payment, User, Venue};
use App\Support\{PaymentReportRules, PaymentReportTotals, ProviderOnlineAllocation, StoredPaymentAllocationCheck};
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function providerAllocationFixture(array $context = []): Payment
{
    $owner = User::factory()->create();
    $business = Business::create(['name'=>'Allocation QA','username'=>'allocation-'.uniqid(),'owner_id'=>$owner->id,'plan'=>'free','status'=>'active']);
    $venue = Venue::forceCreate(['name'=>'Allocation QA','business_id'=>$business->id,'owner_user_id'=>$owner->id,'operator_type'=>'provider','currency_override'=>'QAR','status'=>'active']);
    $booking = Booking::create(['user_id'=>$owner->id,'venue_id'=>$venue->id,'date'=>now()->addDay()->toDateString(),'start_time'=>'10:00','end_time'=>'11:00','subtotal'=>90,'total_price'=>90,'status'=>'confirmed','payment_method'=>'online_payment','commission_channel'=>'site']);
    // A QAR 10 platform-funded promo reduces a QAR 20 fee to 10; the
    // provider still receives 80. Raw rate recalculation would be wrong.
    $facts = array_replace(['operator'=>'provider','business_id'=>$business->id,'business_name'=>$business->name,'channel'=>'site','fee_rate'=>20,'fee_minor'=>2000,'net_fee_minor'=>1000,'total_minor'=>9000,'merchant_share_minor'=>8000,'currency'=>'QAR'], $context);
    $booking->forceFill(['financial_context'=>$facts,'creation_context'=>['channel'=>$facts['channel'],'actor_kind'=>'customer']])->saveQuietly();
    return Payment::withoutEvents(fn()=>Payment::create(['booking_id'=>$booking->id,'user_id'=>$owner->id,'payment_id'=>uniqid('PAY-QA-'),'type'=>'full','method'=>'online','status'=>'paid','amount'=>90,'currency'=>'QAR','financial_context'=>$facts]));
}

function providerAllocationRows(Payment $receipt, array $extra = []): array
{
    $receipt = $receipt->fresh(['ownedAllocations','booking']);
    $raw = array_merge($receipt->getAttributes(), ['financial_context'=>$receipt->financial_context,'owned_allocations'=>$receipt->ownedAllocations->toArray()]);
    $booking = array_merge($receipt->booking->getAttributes(), ['financial_context'=>$receipt->booking->financial_context,'creation_context'=>$receipt->booking->creation_context]);
    return PaymentReportRules::rows([$raw,...$extra], $booking);
}

function providerAllocationMigration(): object
{
    return require database_path('migrations/2026_11_26_000001_record_provider_online_allocations.php');
}

it('restores only the recorded net fee and provider share once, without changing receipts', function () {
    $payment = providerAllocationFixture();
    $before = $payment->fresh()->getAttributes();
    $migration = providerAllocationMigration();
    $migration->up();
    $migration->up();
    $migration->down();
    expect($payment->fresh()->getAttributes())->toBe($before)
        ->and($payment->ownedAllocations()->count())->toBe(2)
        ->and($payment->ownedAllocations()->where('bucket','platform')->value('amount_minor'))->toBe(1000)
        ->and($payment->ownedAllocations()->where('bucket','venue')->value('amount_minor'))->toBe(8000);
    $rows = providerAllocationRows($payment);
    expect(array_column($rows,'amount'))->toBe([10.0,80.0])
        ->and(array_column($rows,'recipient_kind'))->toBe(['bookboll','provider']);
    expect(StoredPaymentAllocationCheck::inspect())->toBe(['checked_receipts'=>1,'invalid_receipts'=>0]);
});

it('does not infer missing fees or rewrite manual and mismatched receipts during migration', function ($overrides) {
    $payment = providerAllocationFixture($overrides);
    providerAllocationMigration()->up();
    expect($payment->ownedAllocations()->count())->toBe(0);
})->with([
    'missing fee'=>[['net_fee_minor'=>null]],
    'missing gross'=>[['total_minor'=>null]],
    'different gross'=>[['total_minor'=>10000]],
    'different currency'=>[['currency'=>'PKR']],
    'unknown business'=>[['business_id'=>null]],
    'manual'=>[['channel'=>'admin','net_fee_minor'=>0]],
    'fee exceeds payment'=>[['net_fee_minor'=>9001]],
]);

it('preserves existing legacy fee/share records and counts their receipt once', function () {
    $payment = providerAllocationFixture();
    foreach (['booking_commission'=>10,'booking_earning'=>80] as $type=>$amount) Payment::withoutEvents(fn()=>Payment::create([
        'booking_id'=>$payment->booking_id,'user_id'=>$payment->user_id,'payment_id'=>uniqid('PAY-LEGACY-'),
        'type'=>$type,'method'=>'online','status'=>'paid','amount'=>$amount,'currency'=>'QAR',
        'financial_context'=>$payment->financial_context + ['receipt_ids'=>[$payment->id]],
    ]));
    providerAllocationMigration()->up();
    expect($payment->ownedAllocations()->count())->toBe(0);
    // Even if a prior pending quote was recorded, the settled legacy pair wins.
    $payment->ownedAllocations()->createMany(ProviderOnlineAllocation::plan($payment->getAttributes(),$payment->financial_context));
    $extra = $payment->booking->payments()->where('id','!=',$payment->id)->get()->map(fn($p)=>array_merge($p->getAttributes(),['financial_context'=>$p->financial_context]))->all();
    $totals = new PaymentReportTotals;
    foreach (providerAllocationRows($payment,$extra) as $row) $totals->add($row);
    $sum = $totals->result()[0];
    expect($sum['total_sales'])->toBe(90.0)->and($sum['bookboll_received'])->toBe(10.0)->and($sum['provider_received'])->toBe(80.0);
});

it('follows the receipt status for refunds without another payment or payout', function () {
    $payment = providerAllocationFixture();
    providerAllocationMigration()->up();
    $payment->update(['status'=>'refunded']);
    $totals = new PaymentReportTotals;
    foreach (providerAllocationRows($payment) as $row) $totals->add($row);
    $sum = $totals->result()[0];
    expect($sum['total_sales'])->toBe(0.0)->and($sum['bookboll_received'])->toBe(0.0)
        ->and($sum['provider_received'])->toBe(0.0)->and($sum['refunded'])->toBe(90.0)
        ->and(Payment::count())->toBe(1);
});

it('keeps corrupted allocations out of earnings and fails the stored-allocation check', function () {
    $payment = providerAllocationFixture();
    providerAllocationMigration()->up();
    $payment->ownedAllocations()->where('bucket','venue')->update(['amount_minor'=>8001]);
    $rows = providerAllocationRows($payment);
    expect($rows)->toHaveCount(1)->and($rows[0]['category'])->toBe('unclassified');
    expect(StoredPaymentAllocationCheck::inspect()['invalid_receipts'])->toBe(1);
});
