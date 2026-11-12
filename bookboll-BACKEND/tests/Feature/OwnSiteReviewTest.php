<?php

/**
 * The owner review path for own-site listings that take no online bookings.
 *
 * Payment on arrival was removed from the own-site channel, so a listing that
 * offered only cash there now takes nothing — the storefront says online
 * booking is unavailable and BookingService refuses the request however it
 * arrives. That part shipped.
 *
 * What was missing is the way out. The document is explicit about both halves:
 * "Provide an owner review path to select a supported method; do not silently
 * enable deposits on the owner's behalf." Enabling deposits for them would
 * start charging their customers a fee they never agreed to collect.
 *
 * These pin that nothing is decided for the owner, that the queue and the
 * per-listing check cannot disagree, and that a bulk apply reports what it
 * refused instead of letting an owner believe everything is fixed.
 */

use App\Models\Business;
use App\Models\Listing;
use App\Models\ProviderWallet;
use App\Models\User;
use App\Models\Venue;
use App\Support\OwnSiteReview;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Nnjeim\World\Models\Country;
use Nnjeim\World\Models\State;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    foreach (['provider_admin', 'super_admin'] as $r) {
        Role::findOrCreate($r, 'web');
    }

    $this->country = Country::create([
        'name' => 'Qatar', 'iso2' => 'QA', 'iso3' => 'QAT',
        'phone_code' => '0', 'region' => 'Asia', 'subregion' => 'Asia', 'status' => 1,
    ]);
    State::firstOrCreate(['name' => 'Qatar State', 'country_id' => $this->country->id], ['country_code' => 'QAT']);

    $this->owner = reviewOwner('North Club');
    $this->venue = reviewVenue($this->owner, $this->country);
});

function reviewOwner(string $name): User
{
    $u = User::factory()->create(['name' => $name.' Owner', 'status' => User::ACTIVE, 'uuid' => (string) Str::uuid()]);
    $u->assignRole('provider_admin');

    Business::create([
        'owner_id' => $u->id, 'name' => $name, 'username' => Str::slug($name).'-'.uniqid(),
        'status' => Business::STATUS_ACTIVE, 'plan' => 'free',
    ]);

    return $u->fresh();
}

function reviewVenue(User $owner, Country $country): Venue
{
    return Venue::create([
        'name' => 'Court Venue', 'owner_user_id' => $owner->id, 'country_id' => $country->id,
        'status' => Venue::ACTIVE, 'maps_url' => 'https://maps.google.com/?q=0,0',
        'min_advance_booking' => 0, 'max_bookings_per_slot' => 1,
    ]);
}

/** A listing with whatever own-site payment shape we want to test. */
function reviewListing(Venue $venue, array $paymentOptions, array $over = []): Listing
{
    return Listing::create(array_merge([
        'name' => 'Court '.uniqid(), 'venue_id' => $venue->id, 'status' => 'active',
        'base_price' => 100, 'capacity' => 4, 'slot_duration' => 1,
        'max_bookings_per_slot' => 1, 'payment_options' => $paymentOptions,
    ], $over));
}

/** The classic affected row: cash on arrival and nothing else. */
function legacyCashOnly(Venue $venue, array $over = []): Listing
{
    return reviewListing($venue, [
        'cash_on_arrival'      => true,
        'site_cash_enabled'    => true,
        'site_deposit_enabled' => false,
        'site_online_enabled'  => false,
    ], $over);
}

/**
 * A genuinely paid plan.
 *
 * The plan column alone is not the discount: CommissionPolicy requires an
 * active subscription that is past any trial and still inside the period it
 * paid for.
 */
function paidPlan(User $owner): void
{
    $business = Business::where('owner_id', $owner->id)->firstOrFail();
    $business->update(['plan' => 'premium']);

    \App\Models\BusinessSubscription::create([
        'business_id' => $business->id, 'plan' => 'premium', 'billing' => 'yearly',
        'status' => 'active', 'amount' => 588, 'currency' => 'USD',
        'current_period_end' => now()->addMonths(6),
    ]);
}

function payoutsFor(User $owner, $country): void
{
    $business = \App\Models\Business::forOwnerId($owner->id) ?: \App\Models\Business::create(['owner_id'=>$owner->id,'name'=>'QA','username'=>'qa-'.uniqid(),'status'=>'active','plan'=>'free']);
    \App\Models\BusinessPaymentConnection::updateOrCreate(['business_id'=>$business->id],[
        'stripe_account_id'=>'acct_'.$business->id,'connected_by'=>$owner->id,'livemode'=>false,
        'status'=>'active','charges_enabled'=>true,'payouts_enabled'=>true,'transfers_enabled'=>true,
    ]);
}

function reviewQueue(User $actor, array $query = [])
{
    Sanctum::actingAs($actor);

    return test()->getJson('/api/admin/listings/own-site-review?'.http_build_query($query));
}

function applyReview(User $actor, array $payload)
{
    Sanctum::actingAs($actor);

    return test()->postJson('/api/admin/listings/own-site-review', $payload);
}

// ── Who is in the queue ──────────────────────────────────────────────────────

it('lists the own-site listings that can no longer be booked', function () {
    $broken = legacyCashOnly($this->venue, ['name' => 'Cash Only Court']);
    reviewListing($this->venue, ['site_deposit_enabled' => true], ['name' => 'Deposit Court']);
    reviewListing($this->venue, ['site_online_enabled' => true], ['name' => 'Online Court']);

    $data = reviewQueue($this->owner)->assertOk()->json('data');

    expect(collect($data['listings'])->pluck('name')->all())->toBe(['Cash Only Court']);
    expect($data['listings'][0]['id'])->toBe($broken->id);
    expect($data['listings'][0]['reason'])->toBe('legacy_cash_only');
});

it('leaves out a listing that is not offered on the own site at all', function () {
    // Nothing to fix: there is no own-site channel on this one.
    legacyCashOnly($this->venue, ['name' => 'Hidden Court', 'show_on_provider_site' => false]);

    expect(reviewQueue($this->owner)->assertOk()->json('data.listings'))->toBe([]);
});

it('includes a listing with no own-site keys at all, the oldest rows of all', function () {
    // These are the rows most in need of review. A SQL predicate that compares
    // a missing JSON key without COALESCE returns NULL, not true, and would
    // leave exactly these out.
    reviewListing($this->venue, ['cash_on_arrival' => true], ['name' => 'Ancient Court']);
    reviewListing($this->venue, [], ['name' => 'Emptier Court']);

    expect(collect(reviewQueue($this->owner)->assertOk()->json('data.listings'))->pluck('name')->sort()->values()->all())
        ->toBe(['Ancient Court', 'Emptier Court']);
});

it('says why each listing is here, so a legacy row reads differently from a switched-off one', function () {
    legacyCashOnly($this->venue, ['name' => 'Legacy Court']);
    reviewListing($this->venue, ['site_deposit_enabled' => false, 'site_online_enabled' => false], ['name' => 'Switched Off Court']);

    $reasons = collect(reviewQueue($this->owner)->assertOk()->json('data.listings'))->pluck('reason', 'name');

    expect($reasons['Legacy Court'])->toBe('legacy_cash_only');
    expect($reasons['Switched Off Court'])->toBe('no_online_method');
});

it('agrees with itself: the SQL queue and the per-listing check return the same set', function () {
    // The queue asks in SQL so it can be counted and paged; apply() asks in
    // PHP. A disagreement either hides a broken listing or offers to fix a
    // working one, so every flag spelling that occurs is checked both ways.
    $shapes = [
        ['site_deposit_enabled' => true],
        ['site_deposit_enabled' => '1'],
        ['site_deposit_enabled' => 1],
        ['site_online_enabled' => true],
        ['site_online_enabled' => '1'],
        ['site_deposit_enabled' => false, 'site_online_enabled' => false],
        ['site_deposit_enabled' => '0', 'site_online_enabled' => '0'],
        ['site_deposit_enabled' => null],
        ['site_deposit_enabled' => ''],
        ['cash_on_arrival' => true],
        [],
    ];

    $ids = [];
    foreach ($shapes as $i => $shape) {
        $ids[] = reviewListing($this->venue, $shape, ['name' => "Shape {$i}"])->id;
    }

    $fromSql = OwnSiteReview::scopeNeedsReview(Listing::whereIn('listings.id', $ids))
        ->pluck('listings.id')->sort()->values()->all();

    $fromPhp = Listing::whereIn('id', $ids)->get()
        ->filter(fn (Listing $l) => OwnSiteReview::needsReview($l))
        ->pluck('id')->sort()->values()->all();

    expect($fromSql)->toBe($fromPhp);
    // And it is actually distinguishing: not everything, not nothing.
    expect(count($fromSql))->toBeGreaterThan(0);
    expect(count($fromSql))->toBeLessThan(count($ids));
});

it('counts the whole queue, not the page it returned', function () {
    foreach (range(1, 5) as $i) {
        legacyCashOnly($this->venue, ['name' => "Queued Court {$i}"]);
    }

    $data = reviewQueue($this->owner, ['per_page' => 2])->assertOk()->json('data');

    expect($data['listings'])->toHaveCount(2);
    expect($data['total'])->toBe(5);
    expect($data['coverage'])->toBe(['status' => 'complete', 'total' => 5]);
});

it('shows only the venues this user may touch', function () {
    legacyCashOnly($this->venue, ['name' => 'Mine']);

    $other = reviewOwner('South Club');
    legacyCashOnly(reviewVenue($other, $this->country), ['name' => 'Theirs']);

    expect(collect(reviewQueue($this->owner)->assertOk()->json('data.listings'))->pluck('name')->all())->toBe(['Mine']);
});

// ── What each choice would mean ─────────────────────────────────────────────

it('previews the deposit from the current plan, not from a stored number', function () {
    $listing = legacyCashOnly($this->venue);
    $listing->payment_options = array_merge($listing->payment_options, ['site_deposit_percentage' => 99]);
    $listing->save();

    $free = reviewQueue($this->owner)->assertOk()->json('data.listings.0.preview');
    expect($free['plan_eligible'])->toBeFalse();
    expect($free['effective_rate_pct'])->toBe($free['standard_rate_pct']);
    // The stale 99 in the row is not the rate.
    expect($free['effective_rate_pct'])->not->toBe(99.0);

    // Upgrading changes the preview with nothing re-saved.
    paidPlan($this->owner);

    $paid = reviewQueue($this->owner)->assertOk()->json('data.listings.0.preview');
    expect($paid['plan_eligible'])->toBeTrue();
    expect($paid['effective_rate_pct'])->toBe(round($free['standard_rate_pct'] / 2, 4));
    // Cast: JSON encodes 100.0 as 100, so a float sum never matches it strictly.
    expect($paid['deposit_due_now'] + $paid['arrival_balance'])->toBe((float) $paid['base_price']);
});

it('says full online is unavailable until payouts are connected for that venue', function () {
    legacyCashOnly($this->venue);

    $row = reviewQueue($this->owner)->assertOk()->json('data.listings.0');
    expect($row['available'])->toBe(['deposit' => true, 'online' => false, 'hide' => true]);
    expect($row['online_blocked_reason'])->toBe('payouts_not_connected');

    payoutsFor($this->owner, $this->country);

    $ready = reviewQueue($this->owner)->assertOk()->json('data.listings.0');
    expect($ready['available']['online'])->toBeTrue();
    expect($ready['online_blocked_reason'])->toBeNull();
});

it('tells the owner whether the listing still sells on the marketplace', function () {
    legacyCashOnly($this->venue, ['name' => 'Both Channels']);
    legacyCashOnly($this->venue, ['name' => 'Own Site Only', 'show_on_marketplace' => false]);

    $rows = collect(reviewQueue($this->owner)->assertOk()->json('data.listings'))->pluck('on_marketplace', 'name');

    expect($rows['Both Channels'])->toBeTrue();
    // Selling nowhere at all, which is why this one is urgent.
    expect($rows['Own Site Only'])->toBeFalse();
});

// ── Nothing is decided for the owner ───────────────────────────────────────

it('never enables deposits on its own, whatever is asked of the queue', function () {
    $listing = legacyCashOnly($this->venue);

    // Reading the queue is a read. It is the only thing a dashboard does on
    // load, and it must not fix anything.
    reviewQueue($this->owner)->assertOk();
    reviewQueue($this->owner)->assertOk();

    $po = $listing->fresh()->payment_options;
    expect($po['site_deposit_enabled'])->toBeFalse();
    expect($po['site_online_enabled'])->toBeFalse();
});

it('refuses to act without a method, and without listings', function () {
    $listing = legacyCashOnly($this->venue);

    applyReview($this->owner, ['listing_ids' => [$listing->id]])
        ->assertStatus(422)->assertJsonValidationErrors(['method']);
    applyReview($this->owner, ['method' => 'deposit'])
        ->assertStatus(422)->assertJsonValidationErrors(['listing_ids']);
    applyReview($this->owner, ['method' => 'deposit', 'listing_ids' => []])
        ->assertStatus(422)->assertJsonValidationErrors(['listing_ids']);
    // No fourth option, and no "pick the obvious one".
    applyReview($this->owner, ['method' => 'auto', 'listing_ids' => [$listing->id]])
        ->assertStatus(422)->assertJsonValidationErrors(['method']);
    applyReview($this->owner, ['method' => 'cash', 'listing_ids' => [$listing->id]])
        ->assertStatus(422)->assertJsonValidationErrors(['method']);

    expect($listing->fresh()->payment_options['site_deposit_enabled'])->toBeFalse();
});

// ── Applying a choice ──────────────────────────────────────────────────────

it('enables the deposit the owner asked for, and clears the retired cash flag', function () {
    $listing = legacyCashOnly($this->venue);

    applyReview($this->owner, ['method' => 'deposit', 'listing_ids' => [$listing->id]])
        ->assertOk()
        ->assertJsonPath('data.applied.0.method', 'deposit')
        ->assertJsonPath('data.remaining', 0);

    $po = $listing->fresh()->payment_options;
    expect($po['site_deposit_enabled'])->toBeTrue();
    expect($po['site_online_enabled'])->toBeFalse();
    expect($po['site_cash_enabled'])->toBeFalse();
    expect($listing->fresh()->show_on_provider_site)->toBeTruthy();
});

it('enables full online only where payouts are connected', function () {
    $listing = legacyCashOnly($this->venue);

    applyReview($this->owner, ['method' => 'online', 'listing_ids' => [$listing->id]])
        ->assertOk()
        ->assertJsonPath('data.applied', [])
        ->assertJsonPath('data.refused.0.reason', 'payouts_not_connected');

    expect($listing->fresh()->payment_options['site_online_enabled'])->toBeFalse();

    payoutsFor($this->owner, $this->country);

    applyReview($this->owner, ['method' => 'online', 'listing_ids' => [$listing->id]])->assertOk();

    $po = $listing->fresh()->payment_options;
    expect($po['site_online_enabled'])->toBeTrue();
    expect($po['site_deposit_enabled'])->toBeFalse();
});

it('hides the own-site channel without touching the marketplace', function () {
    $listing = legacyCashOnly($this->venue);

    applyReview($this->owner, ['method' => 'hide', 'listing_ids' => [$listing->id]])->assertOk();

    $fresh = $listing->fresh();
    expect($fresh->show_on_provider_site)->toBeFalsy();
    // A provider who wants no online bookings on their own site may still sell
    // on Bookboll.
    expect($fresh->show_on_marketplace)->not->toBeFalse();
    expect($fresh->payment_options['site_deposit_enabled'])->toBeFalse();
});

it('records the rate the owner was shown without making it the authority', function () {
    paidPlan($this->owner);
    $listing = legacyCashOnly($this->venue);

    $shown = reviewQueue($this->owner)->assertOk()->json('data.listings.0.preview.effective_rate_pct');
    applyReview($this->owner, ['method' => 'deposit', 'listing_ids' => [$listing->id]])->assertOk();

    expect($listing->fresh()->payment_options['site_deposit_percentage'])->toBe($shown);

    // And it is only a record: dropping to Free changes the fee with nothing
    // re-saved, which is why a one-time database update could never be the
    // source of truth.
    Business::where('owner_id', $this->owner->id)->update(['plan' => 'free']);
    expect(\App\Support\CommissionPolicy::effectiveRatePct($listing->fresh(), 'site'))->not->toBe($shown);
});

// ── Bulk, honestly ─────────────────────────────────────────────────────────

it('applies one named method across the listings the owner named', function () {
    $ids = collect(range(1, 3))->map(fn ($i) => legacyCashOnly($this->venue, ['name' => "Bulk {$i}"])->id)->all();

    $data = applyReview($this->owner, ['method' => 'deposit', 'listing_ids' => $ids])->assertOk()->json('data');

    expect($data['applied'])->toHaveCount(3);
    expect($data['refused'])->toBe([]);
    expect($data['remaining'])->toBe(0);
    expect(Listing::whereIn('id', $ids)->get()->every(fn (Listing $l) => $l->payment_options['site_deposit_enabled'] === true))->toBeTrue();
});

it('reports what it refused rather than letting the owner believe all is well', function () {
    $mine = legacyCashOnly($this->venue, ['name' => 'Mine']);
    $working = reviewListing($this->venue, ['site_deposit_enabled' => true], ['name' => 'Already Fine']);
    $other = reviewOwner('South Club');
    $theirs = legacyCashOnly(reviewVenue($other, $this->country), ['name' => 'Theirs']);

    $data = applyReview($this->owner, [
        'method' => 'deposit',
        'listing_ids' => [$mine->id, $working->id, $theirs->id, 999999],
    ])->assertOk()->json('data');

    expect(collect($data['applied'])->pluck('listing_id')->all())->toBe([$mine->id]);

    $refused = collect($data['refused'])->pluck('reason', 'listing_id');
    expect($refused[$working->id])->toBe('already_resolved');
    // Another owner's listing is simply not found here.
    expect($refused[$theirs->id])->toBe('not_found');
    expect($refused[999999])->toBe('not_found');

    expect($theirs->fresh()->payment_options['site_deposit_enabled'])->toBeFalse();
});

it('does not overwrite a configuration somebody already fixed', function () {
    $listing = legacyCashOnly($this->venue);
    payoutsFor($this->owner, $this->country);

    applyReview($this->owner, ['method' => 'online', 'listing_ids' => [$listing->id]])->assertOk();

    // A stale bulk choice from another tab must not undo it.
    applyReview($this->owner, ['method' => 'deposit', 'listing_ids' => [$listing->id]])
        ->assertOk()->assertJsonPath('data.refused.0.reason', 'already_resolved');

    $po = $listing->fresh()->payment_options;
    expect($po['site_online_enabled'])->toBeTrue();
    expect($po['site_deposit_enabled'])->toBeFalse();
});

it('changes nothing at all when every listing is refused', function () {
    $other = reviewOwner('South Club');
    $theirs = legacyCashOnly(reviewVenue($other, $this->country));

    applyReview($this->owner, ['method' => 'deposit', 'listing_ids' => [$theirs->id]])
        ->assertOk()
        ->assertJsonPath('message', 'Nothing was changed')
        ->assertJsonPath('data.applied', []);

    expect($theirs->fresh()->payment_options['site_deposit_enabled'])->toBeFalse();
});

it('caps how many listings one request may change', function () {
    applyReview($this->owner, ['method' => 'deposit', 'listing_ids' => range(1, 201)])
        ->assertStatus(422)->assertJsonValidationErrors(['listing_ids']);
});

// ── The booking channel actually reopens ───────────────────────────────────

it('leaves the listing bookable on its own site once the owner has chosen', function () {
    $listing = legacyCashOnly($this->venue);

    expect(OwnSiteReview::needsReview($listing))->toBeTrue();

    applyReview($this->owner, ['method' => 'deposit', 'listing_ids' => [$listing->id]])->assertOk();

    $fresh = $listing->fresh();
    expect(OwnSiteReview::needsReview($fresh))->toBeFalse();
    expect($fresh->payment_options['site_deposit_enabled'])->toBeTrue();
    // Cash is still refused — the review path does not bring it back.
    expect($fresh->payment_options['site_cash_enabled'])->toBeFalse();
});

it('is closed to anyone without the listings capability, and to the signed out', function () {
    test()->getJson('/api/admin/listings/own-site-review')->assertStatus(401);
    test()->postJson('/api/admin/listings/own-site-review', [])->assertStatus(401);

    $customer = User::factory()->create(['status' => User::ACTIVE, 'uuid' => (string) Str::uuid()]);
    Role::findOrCreate('customer', 'web');
    $customer->assignRole('customer');

    reviewQueue($customer->fresh())->assertStatus(403);
});

// ── A platform admin is not the owner ───────────────────────────────────────

function platformAdmin(): User
{
    $u = User::factory()->create(['name' => 'Platform Staff', 'status' => User::ACTIVE, 'uuid' => (string) Str::uuid()]);
    $u->assignRole('super_admin');

    return $u->fresh();
}

it('shows a platform admin every business, and which actions they may take', function () {
    legacyCashOnly($this->venue, ['name' => 'Mine']);
    $other = reviewOwner('South Club');
    legacyCashOnly(reviewVenue($other, $this->country), ['name' => 'Theirs']);

    $data = reviewQueue(platformAdmin())->assertOk()->json('data');

    expect(collect($data['listings'])->pluck('name')->sort()->values()->all())->toBe(['Mine', 'Theirs']);
    expect($data['viewer']['scope'])->toBe('platform');
    // Deposit and hide, but not online: that one settles money into the
    // business's own wallet.
    expect($data['viewer']['allowed_methods'])->toBe(['deposit', 'hide']);
});

it('lets a platform admin restore a broken channel with a deposit', function () {
    // The deposit IS Bookboll's commission, so the venue is paid exactly the
    // same on arrival — and without it the channel takes nothing at all.
    $listing = legacyCashOnly($this->venue);

    applyReview(platformAdmin(), ['method' => 'deposit', 'listing_ids' => [$listing->id]])->assertOk();

    expect($listing->fresh()->payment_options['site_deposit_enabled'])->toBeTrue();
});

it('lets a platform admin remove a broken channel', function () {
    $listing = legacyCashOnly($this->venue);

    applyReview(platformAdmin(), ['method' => 'hide', 'listing_ids' => [$listing->id]])->assertOk();

    expect($listing->fresh()->show_on_provider_site)->toBeFalsy();
    // The marketplace listing is untouched.
    expect($listing->fresh()->show_on_marketplace)->not->toBeFalse();
});

it('does not let a platform admin reroute a business\'s money', function () {
    // Full online payment settles the venue's share into THAT business's
    // wallet. Where the money lands is their decision.
    payoutsFor($this->owner, $this->country);
    $listing = legacyCashOnly($this->venue);

    applyReview(platformAdmin(), ['method' => 'online', 'listing_ids' => [$listing->id]])
        ->assertStatus(403);

    expect($listing->fresh()->payment_options['site_online_enabled'])->toBeFalse();
});

it('records who acted for a business, so the question can be answered later', function () {
    $admin = platformAdmin();
    $listing = legacyCashOnly($this->venue);

    applyReview($admin, ['method' => 'deposit', 'listing_ids' => [$listing->id]])->assertOk();

    $entry = \App\Models\AuditLog::where('action', 'listing.own_site_review')->first();
    expect($entry)->not->toBeNull();
    expect($entry->actor_id)->toBe($admin->id);
    expect($entry->details)->toContain('deposit');
    expect($entry->details)->toContain('platform');
});

it('writes no audit entry when the business acts for itself', function () {
    $listing = legacyCashOnly($this->venue);

    applyReview($this->owner, ['method' => 'deposit', 'listing_ids' => [$listing->id]])->assertOk();

    expect(\App\Models\AuditLog::where('action', 'listing.own_site_review')->count())->toBe(0);
});

it('still lets the business that owns the listing decide', function () {
    $listing = legacyCashOnly($this->venue);

    $data = reviewQueue($this->owner)->assertOk()->json('data');
    expect($data['viewer']['scope'])->toBe('business');
    expect($data['viewer']['allowed_methods'])->toBe(['deposit', 'online', 'hide']);

    applyReview($this->owner, ['method' => 'deposit', 'listing_ids' => [$listing->id]])->assertOk();
    expect($listing->fresh()->payment_options['site_deposit_enabled'])->toBeTrue();
});

// ── Bookboll's own venues are not a provider's website ──────────────────────

it('never flags a venue Bookboll runs itself', function () {
    // These have no provider and no provider website: their site bookings use
    // the MARKETPLACE payment methods, which the listing editor says in as
    // many words. Reading the own-site flags on them called 46 working
    // listings broken.
    $bookboll = Venue::create([
        'name' => 'Abu Hamor', 'status' => Venue::ACTIVE,
        'owner_user_id' => null, 'business_id' => null,
        'maps_url' => 'https://maps.google.com/?q=0,0',
        'min_advance_booking' => 0, 'max_bookings_per_slot' => 1,
    ]);
    $listing = legacyCashOnly($bookboll, ['name' => 'Bookboll Court']);

    expect(OwnSiteReview::needsReview($listing->fresh('venue')))->toBeFalse();
    expect(OwnSiteReview::scopeNeedsReview(Listing::whereKey($listing->id))->count())->toBe(0);

    // And it is not offered to a platform admin to "fix" either.
    expect(collect(reviewQueue(platformAdmin())->assertOk()->json('data.listings'))
        ->pluck('name'))->not->toContain('Bookboll Court');
});

it('still flags a venue that belongs to a business', function () {
    // The guard must not swallow the real cases it exists for.
    $listing = legacyCashOnly($this->venue, ['name' => 'Provider Court']);

    expect(OwnSiteReview::needsReview($listing->fresh('venue')))->toBeTrue();
    expect(OwnSiteReview::scopeNeedsReview(Listing::whereKey($listing->id))->count())->toBe(1);
});

it('agrees with itself about Bookboll venues in SQL and in PHP', function () {
    $bookboll = Venue::create([
        'name' => 'Bookboll Run', 'status' => Venue::ACTIVE,
        'owner_user_id' => null, 'business_id' => null,
        'maps_url' => 'https://maps.google.com/?q=0,0',
        'min_advance_booking' => 0, 'max_bookings_per_slot' => 1,
    ]);
    $ids = [
        legacyCashOnly($bookboll)->id,
        legacyCashOnly($this->venue)->id,
    ];

    $fromSql = OwnSiteReview::scopeNeedsReview(Listing::whereIn('listings.id', $ids))
        ->pluck('listings.id')->sort()->values()->all();
    $fromPhp = Listing::with('venue')->whereIn('id', $ids)->get()
        ->filter(fn (Listing $l) => OwnSiteReview::needsReview($l))
        ->pluck('id')->sort()->values()->all();

    expect($fromSql)->toBe($fromPhp);
    expect($fromSql)->toHaveCount(1);
});
