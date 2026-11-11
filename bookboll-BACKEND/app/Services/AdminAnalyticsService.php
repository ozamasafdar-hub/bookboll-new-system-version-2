<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\ClassProgram;
use App\Models\ClassSubscription;
use App\Models\GameJoinRequest;
use App\Models\HostedGame;
use App\Models\Listing;
use App\Models\Payment;
use App\Models\ProviderWallet;
use App\Models\User;
use App\Models\Venue;
use App\Models\WithdrawalRequest;
use App\Support\AccessScope;
use Illuminate\Http\Request;
use Nnjeim\World\Models\City;
use Nnjeim\World\Models\Country;

/**
 * The analytics dashboard's dataset (/dashboard).
 *
 * The page computes every KPI, chart and leaderboard client-side from a handful
 * of raw collections, so this returns those collections rather than pre-computed
 * numbers — the charts keep their own maths and stay easy to change.
 *
 * Everything venue-bound is scoped through AccessScope: a provider sees only
 * their own business, a country admin only their jurisdiction. Platform-wide
 * sets (games, communities) are only returned to platform roles, because the
 * page's "platform" view mode is the only thing that reads them.
 */
class AdminAnalyticsService
{
    public function index(Request $request)
    {
        $user = $request->user();
        if (! $user) {
            return sendError('Unauthenticated.', [], 401);
        }

        $venueIds   = AccessScope::allowedVenueIds($user);   // null = unrestricted
        $isPlatform = AccessScope::isPlatform($user);
        $ownerId    = AccessScope::ownerUserId($user);

        $venues   = $this->venues($venueIds);
        $vIds     = $venues->pluck('id')->all();
        $programsOnly=$request->boolean('programs_only');
        $bookings = $programsOnly ? collect() : $this->bookings($venueIds);
        $programs = $this->programs($venueIds, $ownerId, $isPlatform);
        $progIds  = $programs->pluck('id')->all();
        $subs     = $this->subscriptions($progIds);
        $games    = $isPlatform ? $this->games($user) : collect();

        return sendSuccess('Analytics fetched successfully', [
            'bookings'      => $bookings,
            'customers'     => $programsOnly ? [] : $this->customers($bookings, $isPlatform),
            'providers'     => $programsOnly ? [] : $this->providers($user, $ownerId, $isPlatform),
            'listings'      => $programsOnly ? [] : $this->listings($vIds),
            'locations'     => $venues,
            'programs'      => $programs,
            'wallets'       => $programsOnly ? [] : $this->wallets($ownerId, $isPlatform),
            'withdrawals'   => $programsOnly ? [] : $this->withdrawals($ownerId, $isPlatform),
            'cities'        => $this->cities($venues, $games),
            'countries'     => $this->countries(),
            'subscriptions' => $subs,
            'sub_payments'  => $programsOnly ? [] : $this->subscriptionPayments($subs->pluck('id')->all()),
            'games'         => $games,
            'game_joins'    => $isPlatform ? $this->gameJoins($games->pluck('id')->all()) : [],
        ]);
    }

    /**
     * Only the cities actually referenced — by a visible venue, or by a game on
     * the platform view. The world table holds ~150k rows; sending all of them
     * would be a multi-megabyte response for a handful of lookups.
     */
    protected function cities($venues, $games)
    {
        $ids = $venues->pluck('city_id')
            ->merge($games->pluck('city_id'))
            ->filter()
            ->unique()
            ->values()
            ->all();

        if (! $ids) {
            return collect();
        }

        return City::whereIn('id', $ids)->get(['id', 'name', 'country_id']);
    }

    /** @param int[]|null $venueIds */
    protected function venues(?array $venueIds)
    {
        return Venue::query()
            ->when($venueIds !== null, fn ($q) => $q->whereIn('id', $venueIds ?: [0]))
            ->get(['id', 'owner_user_id', 'name', 'city_id', 'country_id', 'currency_override'])
            ->map(fn (Venue $v) => [
                'id'                => (string) $v->id,
                'owner_user_id'     => $v->owner_user_id ? (string) $v->owner_user_id : null,
                'name'              => $v->name,
                'city_id'           => $v->city_id,
                'country_id'        => $v->country_id,
                'currency_override' => $v->currency_override,
            ]);
    }

    /** @param int[]|null $venueIds */
    protected function bookings(?array $venueIds)
    {
        return Booking::query()
            ->when($venueIds !== null, fn ($q) => $q->whereIn('venue_id', $venueIds ?: [0]))
            ->with('user:id,name')
            ->orderByDesc('created_at')
            ->limit(5000)
            ->get()
            ->map(function (Booking $b) {
                $breakdown = is_array($b->payment_breakdown) ? $b->payment_breakdown : [];
                $date = $b->date;
                if ($date instanceof \Carbon\CarbonInterface) {
                    $date = $date->format('Y-m-d');
                }

                $paymentStatus = (string) $b->payment_status;
                if ($paymentStatus === \BookingPaymentStatus::FULLY_PAID) {
                    $paymentStatus = 'paid';
                } elseif ($paymentStatus === \BookingPaymentStatus::PAYMENT_SKIPPED) {
                    $paymentStatus = 'skipped';
                }
                if ($b->refunded_at) {
                    $paymentStatus = 'refunded';
                }

                return [
                    'id'                => (string) $b->id,
                    'customer_id'       => $b->user_id ? (string) $b->user_id : null,
                    'customer_name'     => $b->user?->name,
                    'listing_id'        => $b->listing_id ? (string) $b->listing_id : null,
                    'location_id'       => $b->venue_id ? (string) $b->venue_id : null,
                    'program_id'        => $b->program_id ? (string) $b->program_id : null,
                    'booking_date'      => $date,
                    'start_time'        => $this->hm($b->start_time),
                    'end_time'          => $this->hm($b->end_time),
                    'players'           => (int) ($b->players ?? 1),
                    'total'             => (float) ($b->total_price ?? 0),
                    'currency'          => null,   // resolved from the venue by the page
                    'booking_status'    => $b->status,
                    'payment_status'    => $paymentStatus,
                    'payment_method'    => $b->payment_method,
                    'commission_amount' => (float) ($breakdown['commission_amount'] ?? 0),
                    'created_at'        => optional($b->created_at)?->toIso8601String(),
                ];
            });
    }

    /**
     * Only the customers who appear in the visible bookings — a provider has no
     * business seeing the platform's whole customer list.
     */
    protected function customers($bookings, bool $isPlatform)
    {
        if ($isPlatform) {
            return User::query()
                ->whereHas('roles', fn ($q) => $q->where('name', 'customer'))
                ->get(['id', 'name', 'status'])
                ->map(fn (User $u) => [
                    'id'        => (string) $u->id,
                    'full_name' => $u->name,
                    'status'    => $u->status,
                ]);
        }

        $ids = $bookings->pluck('customer_id')->filter()->unique()->all();
        if (! $ids) {
            return collect();
        }

        return User::whereIn('id', $ids)->get(['id', 'name', 'status'])
            ->map(fn (User $u) => [
                'id'        => (string) $u->id,
                'full_name' => $u->name,
                'status'    => $u->status,
            ]);
    }

    protected function providers(User $user, ?int $ownerId, bool $isPlatform)
    {
        $query = User::query()
            ->whereHas('roles', fn ($q) => $q->where('name', 'provider_admin'))
            ->with('ownedBusiness:id,owner_id,name,status');

        if (! $isPlatform) {
            $query->where('id', $ownerId ?: 0);
        }

        return $query->get(['id', 'name', 'email', 'country_id', 'status', 'business_status'])
            ->map(function (User $u) {
                $b = $u->ownedBusiness;

                return [
                    'id'              => (string) $u->id,
                    'full_name'       => $u->name,
                    'business_name'   => $b?->name ?: $u->name,
                    'email'           => $u->email,
                    'country_id'      => $u->country_id,
                    'role'            => 'provider_admin',
                    'status'          => $u->status,
                    'business_status' => ApprovalsDashboardService::resolveBusinessStatus($u, $b),
                ];
            });
    }

    /** @param int[] $venueIds */
    protected function listings(array $venueIds)
    {
        return Listing::whereIn('venue_id', $venueIds ?: [0])
            ->get(['id', 'venue_id', 'name', 'status', 'approval_status'])
            ->map(fn (Listing $l) => [
                'id'              => (string) $l->id,
                'location_id'     => (string) $l->venue_id,
                'name'            => $l->name,
                'status'          => $l->status,
                'approval_status' => $l->approval_status,
            ]);
    }

    /** @param int[]|null $venueIds */
    protected function programs(?array $venueIds, ?int $ownerId, bool $isPlatform)
    {
        return ClassProgram::query()
            ->when(!AccessScope::isGlobal(auth()->user()),fn($q)=>$q->whereIn('id',AccessScope::scopedProgramIds(auth()->user(),$venueIds ?? [])))
            ->when(AccessScope::pinnedProgramIds(auth()->user()),fn($q,$pins)=>$q->whereIn('id',$pins))
            ->get(['id', 'name', 'currency', 'owner_user_id', 'mapped_owner_user_id', 'venue_id', 'approval_status', 'status'])
            ->map(fn (ClassProgram $p) => [
                'id'              => (string) $p->id,
                'name'            => $p->name,
                'currency'        => $p->currency,
                'owner_user_id'   => $p->mapped_owner_user_id ? (string) $p->mapped_owner_user_id : $p->owner_user_id,
                'location_id'     => $p->venue_id ? (string) $p->venue_id : null,
                'approval_status' => $p->approval_status,
                'status'          => $p->status,
            ]);
    }

    /** @param int[] $programIds */
    protected function subscriptions(array $programIds)
    {
        return ClassSubscription::whereIn('program_id', $programIds ?: [0])
            ->get([
                'id', 'program_id', 'status', 'trial_status', 'started_at',
                'monthly_amount', 'cancelled_during_trial_at', 'updated_at',
            ])
            ->map(fn (ClassSubscription $s) => [
                'id'                        => (string) $s->id,
                'program_id'                => (string) $s->program_id,
                'status'                    => $s->status,
                'trial_status'              => $s->trial_status,
                'started_at'                => optional($s->started_at)?->toIso8601String(),
                'monthly_amount'            => (float) ($s->monthly_amount ?? 0),
                'cancelled_during_trial_at' => optional($s->cancelled_during_trial_at)?->toIso8601String(),
                'updated_at'                => optional($s->updated_at)?->toIso8601String(),
            ]);
    }

    /**
     * Paid membership billing rows for the visible subscriptions. The page only
     * ever uses paid ones, so the filter happens here.
     *
     * @param  string[]  $subscriptionIds
     */
    protected function subscriptionPayments(array $subscriptionIds)
    {
        return Payment::query()
            ->where('source_type', 'subscription')
            ->where('status', \PaymentStatus::PAID)
            ->whereIn('subscription_id', $subscriptionIds ?: [0])
            ->get(['id', 'amount', 'currency', 'status', 'paid_at', 'created_at', 'subscription_id'])
            ->map(fn (Payment $p) => [
                'id'              => (string) $p->id,
                'amount'          => (float) $p->amount,
                'currency'        => $p->currency,
                'status'          => 'paid',
                'paid_at'         => optional($p->paid_at)?->toIso8601String(),
                'created_at'      => optional($p->created_at)?->toIso8601String(),
                'subscription_id' => $p->subscription_id ? (string) $p->subscription_id : null,
            ]);
    }

    protected function wallets(?int $ownerId, bool $isPlatform)
    {
        return ProviderWallet::query()
            ->when(! $isPlatform, fn ($q) => $q->where('owner_user_id', (string) $ownerId))
            ->get()
            ->map(fn (ProviderWallet $w) => app(WalletPayoutService::class)->mapWallet($w));
    }

    protected function withdrawals(?int $ownerId, bool $isPlatform)
    {
        return WithdrawalRequest::query()
            ->when(! $isPlatform, fn ($q) => $q->where('owner_user_id', (string) $ownerId))
            ->orderByDesc('requested_at')
            ->limit(1000)
            ->get()
            ->map(fn (WithdrawalRequest $w) => app(WalletPayoutService::class)->mapWithdrawal($w));
    }

    protected function countries()
    {
        return Country::query()->with('currency')->get(['id', 'name', 'iso2'])
            ->map(fn (Country $c) => [
                'id'            => $c->id,
                'name'          => $c->name,
                'currency_code' => $c->currency?->code ?: '',
            ]);
    }

    protected function games($user)
    {
        return HostedGame::query()
            ->when(AccessScope::jurisdictionCountryIds($user)!==null,fn($q)=>$q->whereIn('country_id',AccessScope::jurisdictionCountryIds($user)))
            ->get(['id', 'sport', 'game_date', 'status', 'max_players', 'country_id', 'city_id', 'created_at'])
            ->map(fn (HostedGame $g) => [
                'id'          => (string) $g->id,
                'sport'       => $g->sport,
                'game_date'   => $g->game_date instanceof \Carbon\CarbonInterface
                    ? $g->game_date->format('Y-m-d') : $g->game_date,
                'status'      => $g->status,
                'max_players' => (int) ($g->max_players ?? 0),
                'country_id'  => $g->country_id,
                'city_id'     => $g->city_id,
                'created_at'  => optional($g->created_at)?->toIso8601String(),
            ]);
    }

    protected function gameJoins(array $gameIds)
    {
        return GameJoinRequest::query()
            ->whereIn('game_id',$gameIds)
            ->get(['id', 'game_id', 'status'])
            ->map(fn (GameJoinRequest $j) => [
                'id'      => (string) $j->id,
                'game_id' => (string) $j->game_id,
                'status'  => $j->status,
            ]);
    }

    protected function hm($value): string
    {
        if ($value === null || $value === '') {
            return '';
        }
        if ($value instanceof \DateTimeInterface) {
            return $value->format('H:i');
        }

        return substr((string) $value, 0, 5);
    }
}
