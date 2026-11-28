<?php

/**
 * Guards the write side of the shared-pool rules: what lock-slot accepts and
 * what check-slot-availability reports must agree with fetch-time-slots.
 *
 * Before 2026-09-02 lock-slot checked only the listing's own ceiling, so a
 * user could lock a court the pool could not back and would only be rejected
 * later, at booking validation.
 */

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function alSaddPair(): array
{
    return courtPool(6, [
        'badminton'  => ['max' => 6],
        'pickleball' => ['max' => 3],
    ]);
}

function lockCourt(\App\Models\Listing $listing, string $date, string $start, string $end, string $agent = 'session-a')
{
    return test()->withHeaders(['User-Agent' => $agent])
        ->postJson('/api/lock-slot', [
            'listing_id' => $listing->id,
            'date'       => $date,
            'start_time' => $start,
            'end_time'   => $end,
        ]);
}

function checkCourt(\App\Models\Listing $listing, string $date, string $start, string $end, string $agent = 'session-a')
{
    return test()->withHeaders(['User-Agent' => $agent])
        ->postJson('/api/check-slot-availability', [
            'listing_id' => $listing->id,
            'date'       => $date,
            'start_time' => $start,
            'end_time'   => $end,
        ]);
}

describe('lock-slot honours the shared pool', function () {

    it('preserves a browser hold across proxy IP changes and isolates other browsers', function () {
        ['listings' => $l, 'date' => $date] = courtPool(1, ['court' => ['max' => 1]]);
        $slot = ['listing_id' => $l['court']->id, 'date' => $date, 'start_time' => '20:00', 'end_time' => '21:00'];
        $this->withHeaders(['X-Bookboll-Hold-Token' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee'])
            ->withServerVariables(['REMOTE_ADDR' => '10.0.0.1'])
            ->postJson('/api/lock-slot', $slot)->assertJson(['success' => true]);
        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.2'])
            ->postJson('/api/lock-slot', $slot)->assertJson(['success' => true]);
        expect(\App\Models\SlotLock::count())->toBe(1);
        expect(\App\Models\SlotLock::active()->count())->toBe(1);
        expect(\App\Models\SlotLock::where(function ($q) use ($date) { applyBookingOverlapWhere($q, $date, '20:00', '21:00'); })->count())->toBe(1);
        $this->withHeaders(['X-Bookboll-Hold-Token' => 'ffffffff-bbbb-cccc-dddd-eeeeeeeeeeee'])
            ->postJson('/api/lock-slot', $slot)->assertJson(['success' => false]);
    });

    it('refuses a lock the pool cannot back even when the listing has room', function () {
        ['listings' => $l, 'date' => $date] = alSaddPair();

        // 3 pickleball + 3 badminton = all 6 courts gone. Badminton's own
        // ceiling of 6 still leaves 3 "spots", which is what used to let this through.
        bookCourt($l['pickleball'], $date, '20:00', '21:00', times: 3);
        bookCourt($l['badminton'], $date, '20:00', '21:00', times: 3);

        lockCourt($l['badminton'], $date, '20:00', '21:00')
            ->assertJson(['success' => false]);

        expect(\App\Models\SlotLock::count())->toBe(0);
    });

    it('allows a lock while the pool still has a court', function () {
        ['listings' => $l, 'date' => $date] = alSaddPair();

        bookCourt($l['pickleball'], $date, '20:00', '21:00', times: 3);

        lockCourt($l['badminton'], $date, '20:00', '21:00')
            ->assertJson(['success' => true]);
    });

    it('lets a session re-lock the slot it already holds', function () {
        ['listings' => $l, 'date' => $date] = alSaddPair();

        lockCourt($l['badminton'], $date, '20:00', '21:00', 'session-a')
            ->assertJson(['success' => true]);

        // A refresh or extend must not be blocked by the session's own lock.
        lockCourt($l['badminton'], $date, '20:00', '21:00', 'session-a')
            ->assertJson(['success' => true]);

        expect(\App\Models\SlotLock::count())->toBe(1);
    });

    it('refuses the last court to a second session once locks exhaust the pool', function () {
        ['listings' => $l, 'date' => $date] = alSaddPair();

        bookCourt($l['pickleball'], $date, '20:00', '21:00', times: 3);
        bookCourt($l['badminton'], $date, '20:00', '21:00', times: 2);

        // One court left; the first session takes it.
        lockCourt($l['badminton'], $date, '20:00', '21:00', 'session-a')
            ->assertJson(['success' => true]);

        lockCourt($l['badminton'], $date, '20:00', '21:00', 'session-b')
            ->assertJson(['success' => false]);
    });

    it('creates locks that outlive the request when the duration setting is missing', function () {
        ['listings' => $l, 'date' => $date] = alSaddPair();

        // No default_bookings_slot_lock_time row exists here. intval(null) is 0,
        // not null, so a ?? fallback would silently produce already-expired
        // locks and disable slot locking altogether.
        lockCourt($l['badminton'], $date, '20:00', '21:00')->assertJson(['success' => true]);

        expect(\App\Models\SlotLock::active()->count())->toBe(1);
    });

    it('leaves a listing with no resources unaffected by an empty pool', function () {
        ['listings' => $l, 'date' => $date] = courtPool(6, [
            'shared'   => ['max' => 6],
            'standard' => ['max' => 4, 'needs' => null],
        ]);

        bookCourt($l['shared'], $date, '20:00', '21:00', times: 6);

        lockCourt($l['standard'], $date, '20:00', '21:00')
            ->assertJson(['success' => true]);
    });
});

describe('check-slot-availability agrees with fetch-time-slots', function () {

    it('reports the same number of spots as the slot listing does', function () {
        ['listings' => $l, 'date' => $date] = alSaddPair();

        bookCourt($l['pickleball'], $date, '20:00', '21:00', times: 1);

        foreach ([['badminton', 5], ['pickleball', 2]] as [$key, $expected]) {
            expect(checkCourt($l[$key], $date, '20:00', '21:00')->json('data.available_spots'))
                ->toBe($expected)
                ->and(availableCount($l[$key], $date, '20:00'))->toBe($expected);
        }
    });

    it('reports unavailable with a court-specific reason when the pool is empty', function () {
        ['listings' => $l, 'date' => $date] = alSaddPair();

        bookCourt($l['pickleball'], $date, '20:00', '21:00', times: 3);
        bookCourt($l['badminton'], $date, '20:00', '21:00', times: 3);

        $response = checkCourt($l['badminton'], $date, '20:00', '21:00');

        expect($response->json('data.available'))->toBeFalse()
            ->and($response->json('data.reason'))->toBe('All courts are taken for this time slot');
    });
});
