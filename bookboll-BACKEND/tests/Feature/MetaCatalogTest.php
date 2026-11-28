<?php

use App\Models\{Business, Listing, Venue};
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function metaCatalogFixture(string $slug, string $currency = 'QAR'): array
{
    $business = Business::create(['name' => ucfirst($slug), 'username' => $slug, 'status' => 'active', 'brand_enabled' => true]);
    $venue = Venue::create(['name' => 'Public venue', 'status' => 'active', 'currency_override' => $currency]);
    $venue->forceFill(['business_id' => $business->id])->save();
    $listing = Listing::create(['name' => 'Court, "North"', 'venue_id' => $venue->id, 'base_price' => 100,
        'capacity' => 1, 'slot_duration' => 60, 'show_on_customer' => true, 'show_on_admin' => true,
        'show_on_provider_site' => true, 'show_on_marketplace' => true, 'approval_status' => 'approved',
        'photos' => ['https://images.example.com/court.jpg'], 'payment_options' => ['cash_on_arrival' => '1']]);
    return [$business, $venue, $listing];
}

it('matches receipt IDs, uses venue currency, quotes CSV and separates provider inventories', function () {
    config(['app.frontend_url' => 'https://bookboll-marketplace-preview.up.railway.app']);
    [,,$qar] = metaCatalogFixture('one');
    [,,$pkr] = metaCatalogFixture('two', 'PKR');
    $all = $this->getJson('/api/marketplace/meta-catalog')->assertOk()->json('data.items');
    expect(array_column($all, 'id'))->toBe([(string)$qar->id, (string)$pkr->id]);
    $site = $this->getJson('/api/marketplace/meta-catalog?brand_slug=one')->assertOk()->json('data.items');
    expect($site)->toHaveCount(1)->and($site[0]['price'])->toBe('100.00 QAR')
        ->and($site[0]['link'])->toBe('https://bookboll-marketplace-preview.up.railway.app/listings/'.$qar->id.'?b=one');
    $csv = $this->get('/api/marketplace/meta-catalog.csv?currency=PKR')->assertOk()->getContent();
    $rows = array_map(fn($line) => str_getcsv($line, ',', '"', ''), array_filter(explode("\n", trim($csv))));
    expect($rows)->toHaveCount(2)->and($rows[1][0])->toBe((string)$pkr->id)->and($rows[1][1])->toBe('Court, "North"')->and($rows[1][5])->toBe('100.00 PKR');
});

it('does not leak staff-only, hidden, paused, unapproved or suspended inventory', function ($case) {
    [$business, ,$listing] = metaCatalogFixture('one');
    match($case) {
        'staff' => $listing->update(['show_on_customer' => false]),
        'hidden' => $listing->update(['show_on_marketplace' => false]),
        'paused' => $listing->update(['eligibility_status' => 'paused']),
        'unapproved' => $listing->update(['approval_status' => 'pending']),
        'suspended' => $business->update(['status' => 'suspended']),
    };
    $this->withHeader('from', 'admin')->getJson('/api/marketplace/meta-catalog')->assertOk()->assertJsonCount(0, 'data.items');
})->with(['staff','hidden','paused','unapproved','suspended']);

it('never falls back to marketplace data for unknown or unpublished providers', function () {
    [$business] = metaCatalogFixture('one');
    $this->getJson('/api/marketplace/meta-catalog?brand_slug=unknown')->assertNotFound();
    $business->update(['brand_enabled' => false]);
    $this->getJson('/api/marketplace/meta-catalog?brand_slug=one')->assertOk()->assertJsonCount(0, 'data.items');
    $this->get('/api/marketplace/meta-catalog.csv?brand_slug=one')->assertOk();
});

it('gives actionable omissions rather than fake prices or placeholder photos', function () {
    [,,$listing] = metaCatalogFixture('one');
    $listing->update(['photos' => [], 'photo_url' => null, 'images_urls' => []]);
    $this->getJson('/api/marketplace/meta-catalog')->assertOk()->assertJsonPath('data.omitted.0.reason', 'Add a public HTTPS photo')->assertJsonCount(0, 'data.items');
    $listing->update(['base_price' => 0]);
    $this->getJson('/api/marketplace/meta-catalog')->assertJsonPath('data.omitted.0.reason', 'Add an advertised price');
});

it('uses only verified custom domains in production and respects currency precision', function () {
    config(['app.frontend_url' => 'https://bookboll.com']);
    [$business,,$listing] = metaCatalogFixture('one', 'KWD');
    $listing->update(['base_price' => 12.345]);
    $business->update(['brand_custom_domain' => 'courts.example.com', 'brand_domain_status' => 'active']);
    $items = $this->getJson('/api/marketplace/meta-catalog?brand_slug=one')->assertOk()->json('data.items');
    expect($items[0]['link'])->toBe('https://courts.example.com/listings/'.$listing->id)
        ->and($items[0]['price'])->toBe(number_format((float)$listing->fresh()->base_price, 3, '.', '').' KWD');
    $business->update(['brand_domain_status' => 'pending']);
    $this->getJson('/api/marketplace/meta-catalog?brand_slug=one')->assertJsonPath('data.items.0.link', 'https://one.bookboll.com/listings/'.$listing->id);
});

it('respects provider-site visibility independently of marketplace placement', function () {
    [,,$listing] = metaCatalogFixture('one');
    $listing->update(['show_on_marketplace' => false]);
    $this->getJson('/api/marketplace/meta-catalog?brand_slug=one')->assertOk()->assertJsonCount(1, 'data.items');
    $listing->update(['show_on_provider_site' => false]);
    $this->getJson('/api/marketplace/meta-catalog?brand_slug=one')->assertOk()->assertJsonCount(0, 'data.items');
});

it('omits a listing without a known currency instead of using a platform fallback', function () {
    [, $venue] = metaCatalogFixture('one');
    $venue->update(['currency_override' => null]);
    $this->getJson('/api/marketplace/meta-catalog')->assertOk()->assertJsonCount(0, 'data.items')
        ->assertJsonPath('data.omitted.0.reason', 'Set the venue or country currency');
});
