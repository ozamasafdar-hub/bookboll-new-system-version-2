<?php

use App\Models\{Booking, StripeSession};
use App\Support\BookingConversion;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function conversionFixture(): array
{
    $session = \Stripe\Checkout\Session::constructFrom([
        'id'=>'cs_test_conversion', 'status'=>'complete', 'payment_status'=>'paid',
        'payment_intent'=>'pi_conversion', 'amount_total'=>1500, 'currency'=>'qar',
        'metadata'=>['user_id'=>'42', 'source'=>'customer', 'conversion_version'=>'1',
            'conversion_surface'=>'marketplace', 'listing_id'=>'9', 'quantity'=>'2'],
    ]);
    $bookings = collect([1,2])->map(fn($id) => (new Booking)->forceFill([
        'id'=>$id, 'user_id'=>42, 'payment_status'=>'deposit_paid',
        'refunded_amount'=>'0.00',
        'financial_context'=>['channel'=>'marketplace'],
    ]));
    return [['status'=>'completed','session'=>$session,'booking_ids'=>[1,2]], $bookings];
}

it('uses the paid amount and currency, independent of booking prices and fee changes', function ($currency, $minor, $value) {
    [$state,$bookings] = conversionFixture();
    StripeSession::create(['session_id'=>'cs_test_conversion','source'=>'customer','processed'=>true]);
    $state['session']->amount_total = $minor;
    $state['session']->currency = $currency;
    expect(BookingConversion::purchase($state,$bookings,42)['value'])->toEqual($value)
        ->and(BookingConversion::purchase($state,$bookings,42)['currency'])->toBe(strtoupper($currency));
})->with([['qar',1500,15],['pkr',152550,1525.5],['usd',1234,12.34],['jpy',1500,1500],['kwd',12345,12.345],['ugx',150000,1500]]);

it('does not report unpaid, free, historical, staff, partial or foreign-customer checkouts', function ($case) {
    [$state,$bookings] = conversionFixture();
    StripeSession::create(['session_id'=>'cs_test_conversion','source'=>'customer','processed'=>$case !== 'unprocessed']);
    $customerId = 42;
    match ($case) {
        'unpaid' => $state['session']->payment_status = 'unpaid',
        'open' => $state['session']->status = 'open',
        'processing' => $state['status'] = 'processing',
        'free' => $state['session']->amount_total = 0,
        'missing_currency' => $state['session']->currency = null,
        'historical' => $state['session']->metadata->conversion_version = null,
        'staff' => $state['session']->metadata->source = 'admin',
        'pay_link' => $state['session']->metadata->source = 'pay_link',
        'unsettled' => $bookings[0]->payment_status = 'pending',
        'refunded' => $bookings[0]->payment_status = 'refunded',
        'partial' => $bookings->pop(),
        'other_customer' => $customerId = 43,
        'wrong_booking_owner' => $bookings[0]->user_id = 43,
        'admin_booking' => $bookings[0]->financial_context = ['channel'=>'admin'],
        'no_intent' => $state['session']->payment_intent = null,
        default => null,
    };
    expect(BookingConversion::purchase($state,$bookings,$customerId))->toBeNull();
})->with(['unpaid','open','processing','free','missing_currency','historical','staff','pay_link','unprocessed','unsettled','refunded','partial','other_customer','wrong_booking_owner','admin_booking','no_intent']);

it('reports confirmed cash and free bookings as Schedule without revenue', function () {
    [, $bookings] = conversionFixture();
    foreach ($bookings as $booking) $booking->forceFill(['status'=>'confirmed','listing_id'=>9,'payment_status'=>'pending']);
    $event = BookingConversion::schedule($bookings, 42);
    expect($event['contents'])->toBe([['id'=>'9','quantity'=>2]])->and($event['num_items'])->toBe(2)
        ->and($event)->not->toHaveKey('value')->and($event)->not->toHaveKey('currency')
        ->and(BookingConversion::schedule($bookings->reverse(),42)['event_id'])->toBe($event['event_id']);
    $bookings[0]->status = 'pending';
    expect(BookingConversion::schedule($bookings,42))->toBeNull();
});

it('rejects malformed catalog identifiers and includes quantities on payment events', function () {
    [$state] = conversionFixture();
    $meta = $state['session']->metadata->toArray();
    expect(BookingConversion::checkout('cs_test', $meta,1500,'qar')['contents'])->toBe([['id'=>'9','quantity'=>2]]);
    foreach (['listing_id', 'quantity'] as $field) {
        expect(BookingConversion::checkout('cs_test', array_merge($meta,[$field=>'']),1500,'qar'))->toBeNull();
    }
});
