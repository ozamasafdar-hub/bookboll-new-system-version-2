<?php

/**
 * Venue transfer and optional legacy reconciliation (readmeAlamgir §3).
 *
 * The confirmed historical model: external providers could not collect through
 * the system, so Bookboll took the online deposit and KEPT it as its fee, and
 * the customer paid the remainder in cash directly to the provider.
 *
 * Reconciling says that out loud. It is a reviewed assertion about the past,
 * not a payment — so it must create "no Bookboll receipt, provider payable,
 * provider wallet credit or payout", and the original records must survive it.
 */

use App\Models\Booking;
use App\Models\Business;
use App\Models\Payment;
use App\Models\User;
use App\Models\Venue;
use App\Models\WalletTransaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(fn () => Role::findOrCreate('super_admin', 'web'));

function transferAdmin(): User
{
    $u = User::factory()->create(['status' => User::ACTIVE]);
    $u->assignRole('super_admin');

    return $u->fresh();
}

function legacyVenue(): Venue
{
    return Venue::create([
        'name' => 'Legacy Pitch', 'status' => Venue::ACTIVE,
        'maps_url' => 'https://maps.google.com/?q=0,0', 'operator_type' => 'bookboll',
        'owner_user_id' => null, 'currency_override' => 'QAR',
        'min_advance_booking' => 0, 'max_bookings_per_slot' => 1,
    ]);
}

function destinationBusiness(): Business
{
    return Business::create([
        'owner_id' => null, 'name' => 'New Operator', 'username' => 'op-'.uniqid(),
        'status' => Business::STATUS_ACTIVE, 'plan' => 'free',
    ]);
}

/** The classic legacy shape: deposit 20 paid online, arrival 80 still pending. */
function legacyBooking(Venue $v, array $over = []): Booking
{
    $b = Booking::create(array_merge([
        'user_id' => User::factory()->create()->id, 'venue_id' => $v->id,
        'date' => '2026-01-15', 'start_time' => '10:00', 'end_time' => '11:00',
        'subtotal' => 100, 'total_price' => 100, 'status' => 'completed',
        'payment_status' => 'deposit_paid',
    ], $over));

    Payment::create([
        'booking_id' => $b->id, 'user_id' => $b->user_id, 'payment_id' => 'dep-'.uniqid(),
        'type' => 'deposit', 'method' => 'online', 'status' => 'paid',
        'amount' => 20, 'currency' => 'QAR', 'recipient' => '@bookboll',
    ]);
    Payment::create([
        'booking_id' => $b->id, 'user_id' => $b->user_id, 'payment_id' => 'rem-'.uniqid(),
        'type' => 'remaining', 'method' => 'cash', 'status' => 'pending',
        'amount' => 80, 'currency' => 'QAR', 'recipient' => 'provider',
    ]);

    return $b;
}

function previewTransfer(User $a, Venue $v, Business $d, array $over = [])
{
    return test()->actingAs($a)->postJson("/api/admin/venues/{$v->id}/transfer-preview", array_merge([
        'business_id' => $d->id, 'effective_from' => '2026-06-01',
        'history_mode' => 'legacy_deposit_fee',
        'from_date' => '2026-01-01', 'to_date' => '2026-05-31',
    ], $over));
}

it('lists destinations including ownerless businesses', function () {
    $d = destinationBusiness();

    $res = $this->actingAs(transferAdmin())
        ->getJson('/api/admin/venues/'.legacyVenue()->id.'/transfer-options')->assertOk();

    expect($res->json('data.contract_version'))->toBe(1)
        ->and($res->json('data.can_transfer'))->toBeTrue()
        ->and(collect($res->json('data.businesses'))->pluck('id'))->toContain((string) $d->id)
        // Unclaimed is a valid operator, just one nobody has logged into.
        ->and(collect($res->json('data.businesses'))->firstWhere('id', (string) $d->id)['owner_user_id'])
        ->toBeNull();
});

it('never turns an unpaid online payment into provider cash', function () {
    $v=legacyVenue(); $b=legacyBooking($v);
    Payment::where('booking_id',$b->id)->where('type','remaining')->update(['type'=>'full','method'=>'online']);
    $res=previewTransfer(transferAdmin(),$v,destinationBusiness())->assertOk();
    expect($res->json('data.reconciliation_version'))->toBe(2)
        ->and($res->json('data.pending_arrival_count'))->toBe(0)
        ->and((float)$res->json('data.totals.0.provider_cash'))->toBe(0.0);
});

it('reconciles saved receipts into the report without duplicating the retained deposit', function () {
    $a=transferAdmin(); $v=legacyVenue(); $d=destinationBusiness();
    legacyBooking($v,['origin'=>'bookboll_web','payment_method'=>'deposit_remaining_on_arrival']);
    $token=previewTransfer($a,$v,$d)->json('data.preview_token');
    $this->actingAs($a)->postJson("/api/admin/venues/{$v->id}/transfers",[
        'preview_token'=>$token,'idempotency_key'=>'report-reconciled','acknowledge_legacy_cash'=>true,
    ])->assertOk();
    $res=$this->getJson('/api/admin/payments/dashboard?report_version=2')->assertOk();
    expect((float)$res->json('data.summary.0.bookboll_received'))->toBe(20.0)
        ->and((float)$res->json('data.summary.0.provider_received'))->toBe(80.0)
        ->and($res->json('data.review_count'))->toBe(0);
});

it('assigns already-paid manual receipts entirely to the provider', function () {
    $a=transferAdmin(); $v=legacyVenue(); $d=destinationBusiness();
    $b=legacyBooking($v,['origin'=>'manual_admin','payment_method'=>'paid_cash']);
    Payment::where('booking_id',$b->id)->update(['status'=>'paid']);
    $review=previewTransfer($a,$v,$d)->assertOk();
    expect((float)$review->json('data.totals.0.deposit_to_fee'))->toBe(0.0)
        ->and((float)$review->json('data.totals.0.provider_already_paid'))->toBe(100.0);
    $this->actingAs($a)->postJson("/api/admin/venues/{$v->id}/transfers",[
        'preview_token'=>$review->json('data.preview_token'),'idempotency_key'=>'manual-receipts','acknowledge_legacy_cash'=>true,
    ])->assertOk();
    $res=$this->getJson('/api/admin/payments/dashboard?report_version=2')->assertOk();
    expect((float)$res->json('data.summary.0.bookboll_received'))->toBe(0.0)
        ->and((float)$res->json('data.summary.0.provider_manual'))->toBe(100.0);
});

it('previews the deposit as fee and the arrival as provider cash, per currency', function () {
    $v = legacyVenue();
    legacyBooking($v);

    $res = previewTransfer(transferAdmin(), $v, destinationBusiness())->assertOk();

    expect($res->json('data.eligible_count'))->toBe(1)
        ->and($res->json('data.pending_arrival_count'))->toBe(1);

    $t = $res->json('data.totals.0');
    expect($t['currency'])->toBe('QAR')
        ->and($t['deposit_to_fee'])->toBe(20)
        ->and($t['provider_cash'])->toBe(80)
        // The CHANGE in Bookboll earnings: the deposit it already held, now
        // named as the fee. Provider cash never was Bookboll's.
        ->and($t['earnings_delta'])->toBe(20);
});

it('changes nothing historical in preserve mode', function () {
    $v = legacyVenue();
    legacyBooking($v);

    $res = previewTransfer(transferAdmin(), $v, destinationBusiness(), [
        'history_mode' => 'preserve', 'from_date' => null, 'to_date' => null,
    ])->assertOk();

    expect($res->json('data.eligible_count'))->toBe(0)
        ->and($res->json('data.pending_arrival_count'))->toBe(0)
        ->and($res->json('data.totals'))->toBe([])
        ->and(implode(' ', $res->json('data.warnings')))->toContain('no historical payment is reclassified');
});

it('excludes provider slot blocks, cancellations and refunds with reasons', function (array $case) {
    [$over, $fragment] = $case;
    $v = legacyVenue();
    legacyBooking($v, $over);

    $res = previewTransfer(transferAdmin(), $v, destinationBusiness())->assertOk();

    expect($res->json('data.eligible_count'))->toBe(0)
        ->and(implode(' ', $res->json('data.warnings')))->toContain($fragment);
})->with([
    'slot block'  => [[['payment_method' => 'payment_skipped'], 'slot block']],
    'cancelled'   => [[['status' => 'cancelled'], 'cancelled']],
]);

it('refuses a historical range that runs into or past the transfer date', function () {
    $v = legacyVenue();

    $res = previewTransfer(transferAdmin(), $v, destinationBusiness(), [
        'to_date' => '2026-06-01',          // same day as effective_from
    ])->assertOk();

    expect(implode(' ', $res->json('data.blockers')))->toContain('must end before the transfer date');
});

it('will not mark arrival cash collected without an explicit confirmation', function () {
    $a = transferAdmin();
    $v = legacyVenue();
    legacyBooking($v);
    $token = previewTransfer($a, $v, destinationBusiness())->json('data.preview_token');

    // Opening a form is not an assertion that someone else collected cash.
    $this->actingAs($a)->postJson("/api/admin/venues/{$v->id}/transfers", [
        'preview_token' => $token, 'idempotency_key' => 'k1',
    ])->assertStatus(422)->assertJsonValidationErrors('acknowledge_legacy_cash');

    expect(Payment::where('type', 'remaining')->first()->status)->toBe('pending');
});

it('reconciles without creating any receipt, payable, wallet credit or payout', function () {
    $a = transferAdmin();
    $v = legacyVenue();
    $d = destinationBusiness();
    legacyBooking($v);

    $paymentsBefore = Payment::count();
    $token = previewTransfer($a, $v, $d)->json('data.preview_token');

    $this->actingAs($a)->postJson("/api/admin/venues/{$v->id}/transfers", [
        'preview_token' => $token, 'idempotency_key' => 'k2',
        'acknowledge_legacy_cash' => true,
    ])->assertOk();

    $deposit = Payment::where('type', 'booking_commission')->first();
    $arrival = Payment::where('type', 'remaining')->first();

    // The deposit is now named as the fee it was — gross and currency intact.
    expect($deposit)->not->toBeNull()
        ->and((float) $deposit->amount)->toBe(20.0)
        ->and($deposit->currency)->toBe('QAR')
        ->and($deposit->recipient)->toBe('@bookboll');

    // The arrival is closed as cash the destination collected at the facility.
    expect($arrival->status)->toBe('paid')
        ->and($arrival->collection_method)->toBe('provider_direct_cash')
        ->and((int) $arrival->collected_by_business_id)->toBe($d->id)
        ->and($arrival->reconciliation_batch_id)->not->toBeNull();

    // And NAMED as theirs. PaymentService reports `recipient IS NULL` as money
    // Bookboll received, so a blank label here would count the provider's cash
    // as platform revenue on the payments stats card — for every transfer.
    expect($arrival->recipient)->toBe('@'.$d->username)
        ->and($arrival->recipient)->not->toBe('@bookboll');

    // THE point of the whole exercise: no new money anywhere.
    expect(Payment::count())->toBe($paymentsBefore)
        ->and(Payment::where('type', 'booking_earning')->count())->toBe(0)
        ->and(WalletTransaction::count())->toBe(0);
});

it('records before and after for every reconciled payment', function () {
    $a = transferAdmin();
    $v = legacyVenue();
    legacyBooking($v);
    $token = previewTransfer($a, $v, destinationBusiness())->json('data.preview_token');

    $this->actingAs($a)->postJson("/api/admin/venues/{$v->id}/transfers", [
        'preview_token' => $token, 'idempotency_key' => 'k3', 'acknowledge_legacy_cash' => true,
    ])->assertOk();

    $rows = DB::table('venue_transfer_allocations')->get();

    expect($rows)->toHaveCount(2);
    foreach ($rows as $r) {
        $before = json_decode($r->before, true);
        expect($before)->toHaveKey('type')
            ->and($before)->toHaveKey('status')
            ->and(json_decode($r->after, true))->toHaveKey('type');
    }
});

it('moves the venue and keeps landlord obligations in force', function () {
    $a = transferAdmin();
    $v = legacyVenue();
    $d = destinationBusiness();

    \App\Models\VenueOperatingAgreement::create([
        'venue_id' => $v->id, 'version' => 1, 'deal_type' => 'fixed_rent',
        'currency' => 'QAR', 'landlord_name' => 'Landlord', 'monthly_rent_minor' => 310000,
        'effective_from' => '2026-01-01', 'change_type' => 'initial',
    ]);

    $res = previewTransfer($a, $v, $d, ['history_mode' => 'preserve', 'from_date' => null, 'to_date' => null]);
    expect($res->json('data.landlord_obligation_count'))->toBe(1)
        ->and(implode(' ', $res->json('data.warnings')))->toContain('neither cancels rent nor pays it');

    $this->actingAs($a)->postJson("/api/admin/venues/{$v->id}/transfers", [
        'preview_token' => $res->json('data.preview_token'), 'idempotency_key' => 'k4',
    ])->assertOk();

    expect((int) $v->fresh()->business_id)->toBe($d->id)
        // The agreement is untouched: a transfer does not cancel rent.
        ->and(\App\Models\VenueOperatingAgreement::count())->toBe(1);
});

it('refuses a replayed, consumed or expired review', function () {
    $a = transferAdmin();
    $v = legacyVenue();
    legacyBooking($v);
    $token = previewTransfer($a, $v, destinationBusiness())->json('data.preview_token');

    $this->actingAs($a)->postJson("/api/admin/venues/{$v->id}/transfers", [
        'preview_token' => $token, 'idempotency_key' => 'k5', 'acknowledge_legacy_cash' => true,
    ])->assertOk();

    // The same review again, with a new key, is a consumed one.
    $this->actingAs($a)->postJson("/api/admin/venues/{$v->id}/transfers", [
        'preview_token' => $token, 'idempotency_key' => 'k6', 'acknowledge_legacy_cash' => true,
    ])->assertStatus(409);
});

it('returns the original transfer when a commit is retried', function () {
    $a = transferAdmin();
    $v = legacyVenue();
    legacyBooking($v);
    $token = previewTransfer($a, $v, destinationBusiness())->json('data.preview_token');
    $key = 'same-transfer';

    $one = $this->actingAs($a)->postJson("/api/admin/venues/{$v->id}/transfers", [
        'preview_token' => $token, 'idempotency_key' => $key, 'acknowledge_legacy_cash' => true,
    ])->assertOk()->json('data.transfer.id');

    $two = $this->actingAs($a)->postJson("/api/admin/venues/{$v->id}/transfers", [
        'preview_token' => $token, 'idempotency_key' => $key, 'acknowledge_legacy_cash' => true,
    ])->assertOk()->json('data.transfer.id');

    expect($two)->toBe($one)
        ->and(DB::table('venue_transfers')->count())->toBe(1)
        // And reconciliation did not run twice.
        ->and(DB::table('venue_transfer_allocations')->count())->toBe(2);
});

it('never reconciles the same payment under two transfers', function () {
    $a = transferAdmin();
    $v = legacyVenue();
    legacyBooking($v);

    $t1 = previewTransfer($a, $v, destinationBusiness())->json('data.preview_token');
    $this->actingAs($a)->postJson("/api/admin/venues/{$v->id}/transfers", [
        'preview_token' => $t1, 'idempotency_key' => 'x1', 'acknowledge_legacy_cash' => true,
    ])->assertOk();

    // A second transfer over the same history finds nothing left to do.
    $res = previewTransfer($a, $v, destinationBusiness())->assertOk();

    expect($res->json('data.eligible_count'))->toBe(0)
        ->and(implode(' ', $res->json('data.warnings')))->toContain('Already reconciled');
});

it('keeps providers out of venue transfers entirely', function () {
    Role::findOrCreate('provider_admin', 'web');
    $p = User::factory()->create(['status' => User::ACTIVE]);
    $p->assignRole('provider_admin');

    $this->actingAs($p->fresh())
        ->getJson('/api/admin/venues/'.legacyVenue()->id.'/transfer-options')->assertStatus(403);
});

// ── The trail a later investigation reads ──────────────────────────────────

it('logs the ownership change even when the destination has no owner user', function () {
    // The hole this closes: Venue logged owner_user_id but not business_id. An
    // ownerless destination leaves owner_user_id null on BOTH sides, so nothing
    // logged was dirty — and logOnlyDirty() + dontSubmitEmptyLogs() wrote no
    // activity row at all for the one change that matters most.
    $a = transferAdmin();
    $v = legacyVenue();
    $d = destinationBusiness();
    expect($d->owner_id)->toBeNull();
    legacyBooking($v);

    $token = previewTransfer($a, $v, $d)->json('data.preview_token');
    $this->actingAs($a)->postJson("/api/admin/venues/{$v->id}/transfers", [
        'preview_token' => $token, 'idempotency_key' => 'audit-1',
        'acknowledge_legacy_cash' => true,
    ])->assertOk();

    $log = DB::table('activity_log')
        ->where('subject_type', Venue::class)->where('subject_id', $v->id)
        ->latest('id')->first();

    expect($log)->not->toBeNull('a venue transfer must leave an activity row');

    $props = json_decode($log->properties, true);
    expect($props['attributes']['business_id'])->toBe($d->id)
        ->and($props['old']['business_id'])->toBeNull()
        ->and($props['attributes']['operator_type'])->toBe('provider')
        // Who did it, not just what changed.
        ->and((int) $log->causer_id)->toBe($a->id);
});

it('logs each reconciled payment with who collected it', function () {
    // These writes go through Eloquent precisely so this happens. A bulk
    // DB::table(...)->update() would change the same columns and record none
    // of it — the trail would stop at "the balance became paid somehow".
    $a = transferAdmin();
    $v = legacyVenue();
    $d = destinationBusiness();
    legacyBooking($v);

    $token = previewTransfer($a, $v, $d)->json('data.preview_token');
    $this->actingAs($a)->postJson("/api/admin/venues/{$v->id}/transfers", [
        'preview_token' => $token, 'idempotency_key' => 'audit-2',
        'acknowledge_legacy_cash' => true,
    ])->assertOk();

    $arrival = Payment::where('type', 'remaining')->first();
    $log = DB::table('activity_log')
        ->where('subject_type', Payment::class)->where('subject_id', $arrival->id)
        ->latest('id')->first();

    expect($log)->not->toBeNull('a reconciled payment must leave an activity row');

    $props = json_decode($log->properties, true);
    expect($props['attributes']['collected_by_business_id'])->toBe($d->id)
        ->and($props['attributes']['collection_method'])->toBe('provider_direct_cash')
        ->and($props['attributes']['recipient'])->toBe('@'.$d->username)
        ->and($props['attributes']['status'])->toBe('paid')
        ->and($props['old']['status'])->toBe('pending')
        ->and($props['attributes']['reconciliation_batch_id'])->not->toBeNull();
});
