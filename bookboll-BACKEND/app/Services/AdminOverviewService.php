<?php

namespace App\Services;

use App\Models\Business;
use App\Support\HourPeriods;
use App\Models\ClassProgram;
use App\Models\Listing;
use App\Models\ProviderWallet;
use App\Models\User;
use App\Models\Venue;
use App\Support\AccessScope;
use Illuminate\Http\Request;
use Nnjeim\World\Models\City;

/**
 * The dashboard landing page: the onboarding checklist and the provider's venues
 * at a glance (Home.vue + the floating SetupCoach).
 *
 * Both surfaces ask the same question — "how far through setup is this business,
 * and what does it have?" — so they share one call. Everything is scoped through
 * AccessScope, so a provider sees their own business and a platform admin sees
 * whatever their jurisdiction covers.
 */
class AdminOverviewService
{
    public function index(Request $request)
    {
        $user = $request->user();
        if (! $user) {
            return sendError('Unauthenticated.', [], 401);
        }

        $ownerId  = AccessScope::ownerUserId($user);
        $venueIds = AccessScope::allowedVenueIds($user);

        return sendSuccess('Overview fetched successfully', [
            'setup'     => $this->setup($user, $ownerId, $venueIds),
            'locations' => $this->locations($venueIds),
        ]);
    }

    /**
     * The checklist state. A provider's steps are: add a venue → add a listing or
     * programme → connect a payout method. Platform users have no checklist.
     *
     * @param  int[]|null  $venueIds
     */
    protected function setup(User $user, ?int $ownerId, ?array $venueIds): array
    {
        $isProvider = AccessScope::isProviderScoped($user) && $ownerId !== null;

        if (! $isProvider) {
            return [
                'is_provider'   => false,
                'has_location'  => true,
                'has_listing'   => true,
                'has_program'   => true,
                'wallet_ready'  => true,
                'site_slug'     => null,
                'business_name' => null,
            ];
        }

        $ids = $venueIds ?? Venue::ownedBy($ownerId)->pluck('id')->all();

        $hasListing = $ids
            ? Listing::whereIn('venue_id', $ids)->exists()
            : false;

        // Programmes can exist before any venue does, so they are checked on the
        // owner rather than through the venue list.
        $hasProgram = ClassProgram::query()
            ->where(function ($q) use ($ownerId, $user) {
                $q->where('mapped_owner_user_id', $ownerId)
                    ->orWhere('owner_user_id', (string) $ownerId)
                    ->orWhere('owner_user_id', (string) $user->uuid);
            })
            ->exists();

        // A paused payout method does not count — the earning gates treat a paused
        // wallet as inactive (walletSetup.js isWalletReady).
        $walletReady = ProviderWallet::query()
            ->where('owner_user_id', $ownerId)
            ->where('setup_status', 'active')
            ->where(function ($q) {
                $q->where('payout_paused', false)->orWhereNull('payout_paused');
            })
            ->exists();

        $business = Business::forOwnerId($ownerId);

        return [
            'is_provider'   => true,
            'has_location'  => (bool) $ids,
            'has_listing'   => $hasListing || $hasProgram,
            'has_program'   => $hasProgram,
            'wallet_ready'  => $walletReady,
            'site_slug'     => $business?->brand_subdomain ?: $business?->username,
            'business_name' => $business?->brand_name ?: $business?->name,
        ];
    }

    /**
     * Venues with their weekly hours, listings and programmes — the expandable
     * cards on Home.
     *
     * @param  int[]|null  $venueIds
     */
    protected function locations(?array $venueIds): array
    {
        $query = Venue::query()
            ->select(['id', 'name', 'address', 'city_id', 'photos', 'photo_url', 'status', 'sort_order', 'created_at'])
            ->with(['schedules'])
            ->orderBy('sort_order')
            ->orderBy('created_at');

        if ($venueIds !== null) {
            $query->whereIn('id', $venueIds ?: [0]);
        }

        $venues = $query->get();
        if ($venues->isEmpty()) {
            return [];
        }

        $ids = $venues->pluck('id')->all();

        $listings = Listing::whereIn('venue_id', $ids)
            ->get(['id', 'name', 'venue_id', 'sport_category', 'status', 'photo_url'])
            ->groupBy('venue_id');

        $programs = ClassProgram::whereIn('venue_id', $ids)
            ->get(['id', 'name', 'venue_id', 'status'])
            ->groupBy('venue_id');

        $cityNames = City::whereIn('id', $venues->pluck('city_id')->filter()->unique())
            ->pluck('name', 'id');

        return $venues->map(function (Venue $v) use ($listings, $programs, $cityNames) {
            return [
                'id'        => (string) $v->id,
                'name'      => $v->name,
                'address'   => $v->address ?: '',
                'city_id'   => $v->city_id,
                'cityName'  => $v->city_id ? ($cityNames[$v->city_id] ?? '') : '',
                'photos'    => is_array($v->photos) ? $v->photos : [],
                'photo_url' => $v->photo_url,
                'status'    => $v->status,
                // Weekly rows — Home.vue formats them into its own schedule view.
                // Merged, because the stored rows can be one per half hour.
                'hours'     => HourPeriods::mergeDashboardRows(
                    $v->schedules->map(fn ($r) => $r->toDashboardLocationHour())
                        ->filter(fn ($r) => $r['day_of_week'] !== null)->values()->all()
                ),
                'listings'  => ($listings->get($v->id) ?? collect())->map(fn (Listing $l) => [
                    'id'             => (string) $l->id,
                    'name'           => $l->name,
                    'location_id'    => (string) $l->venue_id,
                    'sport_category' => $l->sport_category,
                    'status'         => $l->status,
                    'photo_url'      => $l->photo_url,
                ])->values()->all(),
                'programs'  => ($programs->get($v->id) ?? collect())->map(fn (ClassProgram $p) => [
                    'id'          => (string) $p->id,
                    'name'        => $p->name,
                    'location_id' => (string) $p->venue_id,
                    'status'      => $p->status,
                ])->values()->all(),
            ];
        })->values()->all();
    }
}
