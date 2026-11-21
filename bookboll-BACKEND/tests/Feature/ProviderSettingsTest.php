<?php

/**
 * Settings → Business Profile / My Account / Notifications saved nothing.
 *
 * The profile save handler reported that updates were unavailable, the fields
 * were read-only, and the notification switches changed local component state
 * and nothing else. The fields they showed — business name, phone, description
 * — had no column anywhere: the form was reading nothing and saving nowhere.
 *
 * These pin what matters once it does save: that one business cannot be edited
 * through another's session, that identity cannot be rewritten through a
 * submitted field, and that a notification switch actually changes what is
 * delivered rather than being stored and ignored.
 */

use App\Models\Booking;
use App\Models\Business;
use App\Models\Listing;
use App\Models\Payment;
use App\Models\User;
use App\Models\Venue;
use App\Support\AdminAlerts;
use App\Support\ProviderNotifications;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    foreach (['provider_admin', 'provider_staff', 'super_admin'] as $r) {
        Role::findOrCreate($r, 'web');
    }

    $this->owner = providerOwner('North Club');
    $this->business = $this->owner->ownedBusiness;

    $this->staff = User::factory()->create([
        'name' => 'Staff Member', 'status' => User::ACTIVE,
        'owner_user_id' => $this->owner->id, 'uuid' => (string) Str::uuid(),
    ]);
    $this->staff->assignRole('provider_staff');
    $this->staff = $this->staff->fresh();
});

function providerOwner(string $name): User
{
    $u = User::factory()->create(['name' => $name.' Owner', 'status' => User::ACTIVE, 'uuid' => (string) Str::uuid()]);
    $u->assignRole('provider_admin');

    Business::create([
        'owner_id' => $u->id, 'name' => $name, 'username' => Str::slug($name).'-'.uniqid(),
        'status' => Business::STATUS_ACTIVE, 'plan' => 'free',
    ]);

    return $u->fresh();
}

function settings(User $actor)
{
    Sanctum::actingAs($actor);

    return test()->getJson('/api/business/settings');
}

function saveProfile(User $actor, array $payload)
{
    Sanctum::actingAs($actor);

    return test()->putJson('/api/business/settings/profile', $payload);
}

function saveAccount(User $actor, array $payload)
{
    Sanctum::actingAs($actor);

    return test()->putJson('/api/business/settings/account', $payload);
}

function saveNotifications(User $actor, array $preferences)
{
    Sanctum::actingAs($actor);

    return test()->putJson('/api/business/settings/notifications', ['preferences' => $preferences]);
}

/** A booking on this owner's venue, which is what raises provider alerts. */
function ownedBooking(User $owner): Booking
{
    $venue = Venue::create([
        'name' => 'Court Venue', 'owner_user_id' => $owner->id,
        'status' => Venue::ACTIVE, 'maps_url' => 'https://maps.google.com/?q=0,0',
        'min_advance_booking' => 0, 'max_bookings_per_slot' => 1,
    ]);
    $listing = Listing::create([
        'name' => 'Court 1', 'venue_id' => $venue->id, 'status' => 'active',
        'base_price' => 50, 'capacity' => 4, 'slot_duration' => 1,
        'payment_options' => ['cash_on_arrival' => '0'], 'max_bookings_per_slot' => 1,
    ]);

    return Booking::create([
        'user_id' => User::factory()->create()->id, 'venue_id' => $venue->id,
        'listing_id' => $listing->id, 'date' => now()->addDay()->format('Y-m-d'),
        'start_time' => '10:00', 'end_time' => '11:00',
        'status' => BookingStatus::CONFIRMED, 'subtotal' => 50, 'total_price' => 50,
    ]);
}

/** A payment attached to that booking, with the columns the table requires. */
function bookingPayment(Booking $booking, string $status, array $over = []): Payment
{
    return Payment::create(array_merge([
        'payment_id' => 'PAY_'.uniqid(),
        'user_id'    => $booking->user_id,
        'booking_id' => $booking->id,
        'amount'     => 50,
        'currency'   => 'AED',
        'method'     => 'online',
        'status'     => $status,
        'type'       => 'full',
    ], $over));
}

function alertTitles(User $user): array
{
    return $user->notifications()->get()->pluck('data.title')->filter()->values()->all();
}

// ── Reading ──────────────────────────────────────────────────────────────────

it('reads the business profile, the account and the notification switches', function () {
    $data = settings($this->owner)->assertOk()->json('data');

    expect($data['business']['name'])->toBe('North Club');
    expect($data['account']['name'])->toBe('North Club Owner');
    expect($data['account']['email'])->toBe($this->owner->email);
    expect($data['account']['email_editable'])->toBeFalse();
    expect($data['can_edit_business'])->toBeTrue();
    expect($data['notifications']['preferences'])->toBe(ProviderNotifications::forUser($this->owner));
});

it('says which alerts cannot be switched off, instead of appearing to lose them', function () {
    $notifications = settings($this->owner)->assertOk()->json('data.notifications');

    expect(collect($notifications['always_on'])->pluck('kind')->all())->toBe(['approval', 'wallet']);
    expect($notifications['always_on'][0]['reason'])->not->toBeEmpty();

    // The dashboard also offered a low-wallet switch for an alert nothing
    // raises. It is reported as not offered, with the reason, rather than
    // shipped as a switch that changes nothing.
    expect(collect($notifications['not_offered'])->pluck('key')->all())->toBe(['wallet_low']);
});

it('shows staff the profile but tells them they cannot change it', function () {
    $data = settings($this->staff)->assertOk()->json('data');

    expect($data['business']['name'])->toBe('North Club');
    expect($data['can_edit_business'])->toBeFalse();
});

// ── Business profile ─────────────────────────────────────────────────────────

it('saves the business profile and returns what was persisted', function () {
    $business = saveProfile($this->owner, [
        'name' => 'North Sports Club', 'phone' => '+971 50 000 0000',
        'description' => 'Six padel courts and a gym.',
    ])->assertOk()->json('data.business');

    expect($business['name'])->toBe('North Sports Club');
    expect($business['phone'])->toBe('+971500000000');

    // Read back from the database, not from the response that reported success.
    $saved = $this->business->fresh();
    expect($saved->name)->toBe('North Sports Club');
    expect($saved->description)->toBe('Six padel courts and a gym.');
});

it('leaves the public website name alone, which is edited elsewhere', function () {
    $this->business->update(['brand_name' => 'North Courts Online']);

    saveProfile($this->owner, ['name' => 'North Sports Club'])->assertOk();

    expect($this->business->fresh()->brand_name)->toBe('North Courts Online');
});

it('validates the profile and reports the field, not just a failure', function () {
    saveProfile($this->owner, ['name' => ''])->assertStatus(422)->assertJsonValidationErrors(['name']);
    saveProfile($this->owner, ['name' => str_repeat('a', 151)])->assertStatus(422)->assertJsonValidationErrors(['name']);
    saveProfile($this->owner, ['name' => 'Fine', 'phone' => str_repeat('9', 40)])
        ->assertStatus(422)->assertJsonValidationErrors(['phone']);

    expect($this->business->fresh()->name)->toBe('North Club');
});

it('does not let staff edit the business profile', function () {
    saveProfile($this->staff, ['name' => 'Taken Over'])->assertStatus(403);

    expect($this->business->fresh()->name)->toBe('North Club');
});

it('does not let one provider edit another business through any submitted id', function () {
    $other = providerOwner('South Club');
    $otherBusiness = $other->ownedBusiness;

    // Every shape of "this other business" a caller might try.
    foreach ([
        ['name' => 'Hijacked', 'business_id' => $otherBusiness->id],
        ['name' => 'Hijacked', 'id' => $otherBusiness->id],
        ['name' => 'Hijacked', 'owner_id' => $other->id],
        ['name' => 'Hijacked', 'username' => $otherBusiness->username],
    ] as $payload) {
        saveProfile($this->owner, $payload)->assertOk();
    }

    // Their business is untouched; ours took only the name.
    expect($otherBusiness->fresh()->name)->toBe('South Club');
    expect($otherBusiness->fresh()->owner_id)->toBe($other->id);
    expect($this->business->fresh()->owner_id)->toBe($this->owner->id);
});

// ── Personal account ─────────────────────────────────────────────────────────

it('saves the account holder their own name and phone', function () {
    $account = saveAccount($this->owner, ['name' => 'Alamgir Khan', 'phone' => '+971 55 111 2222'])
        ->assertOk()->json('data.account');

    expect($account['name'])->toBe('Alamgir Khan');
    expect($this->owner->fresh()->name)->toBe('Alamgir Khan');
    expect($this->owner->fresh()->phone)->toBe('+971 55 111 2222');
});

it('refuses an email change rather than ignoring it', function () {
    // Silently dropping it would let someone leave believing the address that
    // receives their password resets had changed.
    saveAccount($this->owner, ['name' => 'Same Name', 'email' => 'new@example.com'])
        ->assertStatus(422)->assertJsonValidationErrors(['email']);

    expect($this->owner->fresh()->email)->not->toBe('new@example.com');

    // Sending the address unchanged is not an attempt to change it.
    saveAccount($this->owner, ['name' => 'Same Name', 'email' => $this->owner->email])->assertOk();
});

it('refuses any attempt to rewrite identity through account settings', function () {
    $other = providerOwner('South Club');

    foreach (['id', 'user_id', 'owner_user_id', 'business_id', 'role', 'status'] as $field) {
        saveAccount($this->owner, ['name' => 'Still Me', $field => $other->id])
            ->assertStatus(422)->assertJsonValidationErrors([$field]);
    }

    $fresh = $this->owner->fresh();
    expect($fresh->owner_user_id)->toBeNull();
    expect($fresh->status)->toBe(User::ACTIVE);
    expect($other->fresh()->name)->toBe('South Club Owner');
});

it('lets staff manage their own account without touching the business', function () {
    saveAccount($this->staff, ['name' => 'Staff New Name'])->assertOk();

    expect($this->staff->fresh()->name)->toBe('Staff New Name');
    expect($this->business->fresh()->name)->toBe('North Club');
});

// ── Notification preferences ─────────────────────────────────────────────────

it('persists notification switches and returns the saved values', function () {
    $saved = saveNotifications($this->owner, ['booking_new' => false, 'payment_received' => false])
        ->assertOk()->json('data.notifications.preferences');

    expect($saved['booking_new'])->toBeFalse();
    expect($saved['payment_received'])->toBeFalse();
    expect($saved['booking_cancelled'])->toBeTrue();

    // Survives a reload, which is the whole point.
    expect(settings($this->owner)->assertOk()->json('data.notifications.preferences.booking_new'))->toBeFalse();
});

it('merges a patch so naming one switch does not reset the others', function () {
    saveNotifications($this->owner, ['booking_new' => false])->assertOk();
    saveNotifications($this->owner, ['member_new' => false])->assertOk();

    $saved = settings($this->owner)->assertOk()->json('data.notifications.preferences');
    expect($saved['booking_new'])->toBeFalse();
    expect($saved['member_new'])->toBeFalse();
});

it('refuses a switch nobody defined instead of storing it', function () {
    saveNotifications($this->owner, ['booking_new_' => false])
        ->assertStatus(422)->assertJsonValidationErrors(['preferences']);
    saveNotifications($this->owner, ['wallet_low' => false])
        ->assertStatus(422)->assertJsonValidationErrors(['preferences']);

    Sanctum::actingAs($this->owner);
    test()->putJson('/api/business/settings/notifications', ['preferences' => 'off'])
        ->assertStatus(422)->assertJsonValidationErrors(['preferences']);

    expect($this->owner->fresh()->notification_preferences)->toBeNull();
});

it('applies the switches to what is actually delivered', function () {
    $booking = ownedBooking($this->owner);
    expect(alertTitles($this->owner))->toHaveCount(1);

    saveNotifications($this->owner, ['booking_new' => false])->assertOk();

    ownedBooking($this->owner);
    // Still one: the second booking raised nothing.
    expect(alertTitles($this->owner))->toHaveCount(1);

    // A cancellation is a different switch and still arrives.
    $booking->update(['status' => BookingStatus::CANCELLED]);
    expect(collect(alertTitles($this->owner))->filter(fn ($t) => str_contains($t, 'cancelled')))->toHaveCount(1);

    saveNotifications($this->owner, ['booking_cancelled' => false])->assertOk();
    ownedBooking($this->owner)->update(['status' => BookingStatus::CANCELLED]);
    expect(collect(alertTitles($this->owner))->filter(fn ($t) => str_contains($t, 'cancelled')))->toHaveCount(1);
});

it('announces a payment once it is actually paid, and only once', function () {
    $booking = ownedBooking($this->owner);

    $payment = bookingPayment($booking, PaymentStatus::PENDING);
    expect(collect(alertTitles($this->owner))->filter(fn ($t) => str_contains($t, 'Payment received')))->toHaveCount(0);

    $payment->update(['status' => PaymentStatus::PAID]);
    expect(collect(alertTitles($this->owner))->filter(fn ($t) => str_contains($t, 'Payment received')))->toHaveCount(1);

    // Editing a paid payment afterwards is not a second payment.
    $payment->update(['notes' => 'reconciled']);
    expect(collect(alertTitles($this->owner))->filter(fn ($t) => str_contains($t, 'Payment received')))->toHaveCount(1);
});

it('turns payment alerts off for the person who asked, and nobody else', function () {
    $other = providerOwner('South Club');

    saveNotifications($this->owner, ['payment_received' => false])->assertOk();

    bookingPayment(ownedBooking($this->owner), PaymentStatus::PAID, ['method' => 'cash']);
    bookingPayment(ownedBooking($other), PaymentStatus::PAID, ['method' => 'cash', 'amount' => 70]);

    expect(collect(alertTitles($this->owner))->filter(fn ($t) => str_contains($t, 'Payment received')))->toHaveCount(0);
    expect(collect(alertTitles($other))->filter(fn ($t) => str_contains($t, 'Payment received')))->toHaveCount(1);
});

it('never lets a preference silence an alert that must always arrive', function () {
    // Approvals and payout problems are not a newsletter: muting them means
    // money stops moving and nobody is told.
    $this->owner->notification_preferences = ['booking_new' => false, 'approval' => false, 'wallet' => false];
    $this->owner->save();

    AdminAlerts::toUser($this->owner->id, 'approval', 'warn', 'Listing needs changes', '', '/approvals');
    AdminAlerts::toUser($this->owner->id, 'wallet', 'warn', 'Payout method needs attention', '', '/wallet');

    // Order is not the point and two alerts raised in the same second have no
    // stable one — both arriving is the point.
    expect(collect(alertTitles($this->owner->fresh()))->sort()->values()->all())
        ->toBe(['Listing needs changes', 'Payout method needs attention']);
});

it('keeps personal preferences separate from the customer message templates', function () {
    saveNotifications($this->owner, ['booking_new' => false])->assertOk();

    // businesses.notification_templates is the copy sent to customers from the
    // Automated notifications page. One is not the other.
    expect($this->business->fresh()->notification_templates)->toBeNull();
    expect($this->owner->fresh()->notification_preferences)->toHaveKey('booking_new');
});

it('is closed to anyone who is not signed in', function () {
    foreach ([
        ['getJson', '/api/business/settings'],
        ['putJson', '/api/business/settings/profile'],
        ['putJson', '/api/business/settings/account'],
        ['putJson', '/api/business/settings/notifications'],
    ] as [$method, $path]) {
        test()->{$method}($path, [])->assertStatus(401);
    }
});
