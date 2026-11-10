<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\Community;
use App\Models\CommunityMember;
use App\Support\CommunityPermissions;
use App\Models\GameJoinRequest;
use App\Models\HostedGame;
use App\Models\Listing;
use App\Models\LoyaltyPoint;
use App\Models\LoyaltyProgram;
use App\Models\Payment;
use App\Models\TournamentMatch;
use App\Models\User;
use Nnjeim\World\Models\Country;
use App\Models\StripeSession;
use App\Models\Venue;
use App\Support\MobileAppReturn;
use App\Support\TenantOrigins;
use App\Notifications\HostedEvents\EventCancelledNotification;
use App\Notifications\HostedEvents\EventJoinConfirmedNotification;
use App\Notifications\HostedEvents\EventJoinDeclinedNotification;
use App\Notifications\HostedEvents\EventJoinRequestedNotification;
use App\Notifications\HostedEvents\EventParticipantLeftNotification;
use App\Notifications\HostedEvents\EventRequestReceivedNotification;
use App\Notifications\HostedEvents\EventUpdatedNotification;
use App\Notifications\HostedEvents\EventWaitlistedNotification;
use App\Support\QatarMarketGeo;
use BookingPaymentMethod;
use BookingPaymentStatus;
use BookingStatus;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use PaymentStatus;
use PaymentType;

/**
 * Hosting a game, hangout or tournament.
 *
 * A hosted game that uses a venue reserves the court with a real booking, exactly
 * like a normal customer booking, and the host really pays for it up front through
 * the same Stripe checkout: the court deposit (or the full price where the venue
 * takes online payment) plus a non-refundable seats service fee. The rest is
 * settled with the venue on arrival.
 *
 * Nothing goes live on credit. When there is money due the game is written with
 * published_at NULL — invisible to every feed — and only becomes real once Stripe
 * confirms the charge (settleHostCheckout). An abandoned checkout takes the game
 * and its court reservation with it (discardUnpaidGame).
 *
 * A hangout at the host's own spot books no court, so there is no venue money at
 * all. A PRICED one still pays the usual seats percentage; a FREE one pays a flat
 * publish fee instead (OWN_SPOT_MIN_FEE) — without it, publishing an event Bookboll
 * earns nothing from was free, and the Events feed was free to spam.
 *
 * All money is priced here from the listing and the platform's rates — the client
 * sends what the host picked, never an amount.
 */
class CustomerGameService
{
    /** Used when neither the listing nor the country sets a rate. */
    private const COURT_COMMISSION_PCT = 15;

    private const SEAT_FEE_PCT = 10;

    /**
     * What a FREE event at the host's own spot pays to publish.
     *
     * No court and no seats means no percentage to take, so this is the only thing
     * standing between the Events feed and an endless free-to-post queue. Charged in
     * USD on purpose: there is no venue here to take a currency from, and converting
     * into the host's own currency would need an FX rate the platform does not keep.
     * Override with the `own_spot_min_fee` setting (same currency).
     */
    private const OWN_SPOT_MIN_FEE = 5.00;

    private const OWN_SPOT_MIN_FEE_CURRENCY = 'USD';

    /** Points awarded for hosting, once per game. */
    private const HOST_BONUS = 50;

    /** Marks a Stripe checkout as belonging to the hosting flow. */
    public const CHECKOUT_SOURCE = 'customer_game';

    public function __construct(
        protected CustomerBookingQuoteService $quotes,
        protected HostPayoutService $payouts
    ) {}

    /**
     * Whether this host really is selling tickets on their own Stripe.
     *
     * `ticket_mode` arrives from the client, and saying 'stripe' waives Bookboll's
     * seats fee entirely — so on its own it was a way to host for nothing. The
     * waiver now needs an account Stripe has actually verified.
     */
    protected function sellsOwnTickets(User $host, array $data): bool
    {
        return ($data['ticket_mode'] ?? '') === 'stripe'
            && $this->payouts->hasReadyAccount($host);
    }

    /**
     * What the wizard needs before it can show prices: whether the features are on,
     * and the two percentages that drive the host's bill.
     */
    public function config(?int $listingId = null, bool $community = false)
    {
        $listing = $listingId ? Listing::with('pricingGroups')->find($listingId) : null;

        $plan = $listing ? $this->paymentPlan($listing, $this->venueFor($listing)) : null;

        return sendSuccess('Game config', [
            'games_enabled'       => true,
            'tournaments_enabled' => true,
            'hangout_enabled'     => true,
            'court_commission_pct' => $this->courtCommissionPct($listing),
            'seat_fee_pct'         => $this->seatFeePct($listing, $community),
            // What a FREE event at the host's own spot pays to publish, so the wizard
            // can say so before the host picks that option rather than at checkout.
            'own_spot_min_fee'          => $this->ownSpotMinFee(),
            'own_spot_min_fee_currency' => self::OWN_SPOT_MIN_FEE_CURRENCY,
            'bookboll_owned'       => $listing ? ! $this->venueFor($listing)?->owner_user_id : false,
            // What the provider takes on this court, so the host is offered the
            // same choice a customer booking it would get.
            'payment_methods'      => $plan['methods'] ?? [],
            'deposit_amount'       => $plan['deposit_amount'] ?? 0,
            'deposit_percentage'   => $plan['deposit_percentage'] ?? 0,
        ]);
    }

    /**
     * Price the host's selection without creating anything, so the wizard can show
     * the bill. Same numbers the create call will charge.
     */
    public function quote(User $host, array $data)
    {
        $listing = ! empty($data['listing_id']) ? Listing::with('pricingGroups')->find($data['listing_id']) : null;

        if (! empty($data['listing_id']) && ! $listing) {
            return sendError('Court not found.', [], 404);
        }

        try {
            $charge = $this->priceHost($host, $listing, $data);
        } catch (\RuntimeException $e) {
            // The wizard asks for a price on every change, so a court we cannot
            // price has to come back as a plain message rather than a 500.
            return sendError($e->getMessage(), [], 422);
        }

        return sendSuccess('Quote', ['charge' => $charge]);
    }

    /**
     * What a reward may be funded from on this hosted event.
     *
     * Priced through priceHost(), the same code the create call charges, so a
     * reward quote and the charge cannot drift apart. The split says which part
     * of Bookboll's share each figure came from, because the funding rule
     * counts the seats fee and the court commission separately and once each.
     *
     * @return array{currency:string, order_total:float, due_now:float, share:array{commission:float,event_fee:float,booking_fee:float}}|null
     */
    public function rewardBasis(User $host, array $data): ?array
    {
        $listing = ! empty($data['listing_id']) ? Listing::with('pricingGroups')->find($data['listing_id']) : null;

        if (! empty($data['listing_id']) && ! $listing) {
            return null;
        }

        // Priced with no reward applied: the quote decides the reward, so
        // feeding one in here would cap it against itself.
        $charge = $this->priceHost($host, $listing, array_merge($data, ['loyalty_redeem' => 0]));

        $method = (string) ($charge['paymentMethod'] ?? ($listing ? 'cash' : 'online'));
        $commission = $this->bookbollShareNow($listing, $charge['courtTotal'], $charge['courtNow'], 0.0, $method);

        return [
            'currency'    => (string) $charge['currency'],
            'order_total' => round((float) $charge['courtTotal'] + (float) $charge['serviceFee'], 2),
            'due_now'     => round((float) $charge['courtNow'] + (float) $charge['serviceFee'], 2),
            'share' => [
                // The court's commission, nothing more: the venue's revenue is
                // collected alongside it but is not Bookboll's to give away.
                'commission'  => round($commission, 2),
                // The seats service fee is wholly Bookboll's.
                'event_fee'   => round((float) $charge['serviceFee'], 2),
                'booking_fee' => 0.0,
            ],
        ];
    }

    /** Publish a game or a hangout. */
    public function create(User $host, array $data)
    {
        if (! $host->uuid) {
            return sendError('Please sign in to host.');
        }

        $listing = ! empty($data['listing_id']) ? Listing::with('pricingGroups')->find($data['listing_id']) : null;

        if (! empty($data['listing_id']) && ! $listing) {
            return sendError('Court not found.', [], 404);
        }

        // No court means a hangout at the host's own spot: nothing to reserve or pay.
        $ownSpot = ! $listing;

        if ($ownSpot && trim((string) ($data['custom_location_name'] ?? '')) === '') {
            return sendError('Tell people where to meet.', [], 422);
        }

        $venue = $listing ? $this->venueFor($listing) : null;

        try {
            $charge = $this->priceHost($host, $listing, $data);
        } catch (\RuntimeException $e) {
            return sendError($e->getMessage(), [], 422);
        }

        $currency = $charge['currency'];

        if (! $ownSpot && $this->slotTaken($listing->id, $data['game_date'], $data['start_time'], $data['end_time'])) {
            return sendError('That court is already booked for this time. Pick another time or court.', [], 422);
        }

        $community = ! empty($data['community_id'])
            ? Community::where('uuid', $data['community_id'])->first()
            : null;

        // Hosting in a community is a moderation-level act: the event appears on
        // the community's page and sells seats under its name. Nothing checked
        // this before, so any signed-in user could pass any community's uuid.
        if (! empty($data['community_id'])) {
            if (! $community) {
                return sendError('Community not found.', [], 404);
            }

            if ($denied = $this->communityHostingDenial($host, $community)) {
                return $denied;
            }
        }

        // Is there anything to collect? A free court or a bill fully covered by points
        // publishes straight away; anything else has to go through Stripe before the
        // event is real. An own-spot event used to be excluded here — it could never
        // owe anything — but a free one now pays the publish fee, so it goes through
        // the same gate as everything else.
        $needsPayment = $charge['dueNow'] > 0;

        // The money currency and the EVENT's currency are not always the same: a free
        // own-spot event is billed in USD, while its seat prices (and anything it shows
        // players) belong to the host's own country.
        $gameCurrency = $ownSpot ? ($this->hostCurrency($host, $data) ?: $currency) : $currency;

        try {
            return DB::transaction(function () use ($host, $listing, $venue, $ownSpot, $data, $charge, $currency, $gameCurrency, $community, $needsPayment) {
                if (! $ownSpot) {
                    // The check above happened outside any transaction, so two hosts
                    // could both pass it and both insert. Take the listing's booking
                    // mutex and ask again with the answer held still — the same guard
                    // the customer and dashboard booking paths use.
                    app(BookingService::class)->lockListingForBooking($listing->id);

                    if ($this->slotTaken($listing->id, $data['game_date'], $data['start_time'], $data['end_time'])) {
                        throw new \RuntimeException('That court was just booked for this time. Pick another time or court.');
                    }
                }

                $booking = $ownSpot ? null : $this->reserveCourt($host, $listing, $venue, $data, $charge, ! $needsPayment);

                $game = HostedGame::create([
                    'host_customer_id'        => $host->uuid,
                    'host_name'               => $data['host_name'] ?? ($host->name ?: ''),
                    'host_phone'              => $data['host_phone'] ?? ($host->phone ?: ''),
                    'host_whatsapp'           => $data['host_whatsapp'] ?? ($data['host_phone'] ?? ''),
                    'listing_id'              => $listing?->id,
                    'location_id'             => $venue?->id,
                    'booking_id'              => $booking?->id,
                    'city_id'                 => $data['city_id'] ?? $venue?->city_id,
                    'country_id'              => $data['country_id'] ?? $venue?->country_id,
                    'title'                   => $data['title'] ?? '',
                    'sport'                   => $data['sport'] ?? '',
                    'game_type'               => $data['game_type'] ?? 'game',
                    'game_date'               => $data['game_date'],
                    'start_time'              => $data['start_time'],
                    'end_time'                => $data['end_time'],
                    'price_per_person'        => round((float) ($data['price_per_person'] ?? 0), 2),
                    'currency'                => $gameCurrency,
                    'max_players'             => (int) ($data['max_players'] ?? 0),
                    'skill_level'             => $data['skill_level'] ?? 'all',
                    'description'             => $data['description'] ?? '',
                    'status'                  => 'open',
                    'prize_pot'               => round((float) ($data['prize_pot'] ?? 0), 2),
                    'prize_note'              => $data['prize_note'] ?? null,
                    'custom_location_name'    => $data['custom_location_name'] ?? null,
                    'custom_location_address' => $data['custom_location_address'] ?? null,
                    'hide_location'           => (bool) ($data['hide_location'] ?? false),
                    'images'                  => $data['images'] ?? [],
                    'signup_form'             => $data['signup_form'] ?? [],
                    'request_deadline'        => $data['request_deadline'] ?? null,
                    'approval_mode'           => $data['approval_mode'] ?? 'review',
                    'allow_waitlist'          => ($data['allow_waitlist'] ?? true) !== false,
                    'lock_minutes_before'     => $data['lock_minutes_before'] ?? null,
                    'play_format'             => $data['play_format'] ?? null,
                    'courts_count'            => $data['courts_count'] ?? null,
                    'charge'                  => $charge,
                    'community_id'            => $community?->id,
                    'community_public'        => $community ? (bool) ($data['community_public'] ?? false) : false,
                    'moderation_status'       => 'active',
                    // Nothing is live until the money is in.
                    'published_at'            => $needsPayment ? null : now(),
                ]);

                if ($booking) {
                    $this->recordHostPayments($host, $booking, $charge, $currency, true, ! $needsPayment);
                } elseif ($ownSpot) {
                    // No booking to hang the ledger row on, so the fee gets its own row
                    // keyed to the game.
                    $this->recordOwnSpotFee($host, $game, $charge, $currency, ! $needsPayment);
                }

                if (! $needsPayment && $community) {
                    app(CommunityNotifier::class)->eventPosted($community, $game, $host);
                }

                if ($needsPayment) {
                    $checkout = $this->startHostCheckout($host, $game, $booking ? [$booking->id] : [], $charge, $currency);

                    return sendSuccess('Complete payment to publish', [
                        'game'             => $this->mapGame($game),
                        'charge'           => $charge,
                        'requires_payment' => true,
                        'checkout_url'     => $checkout['url'],
                        'session_id'       => $checkout['session_id'],
                    ]);
                }

                $this->awardHostBonus($host);

                return sendSuccess('Game published', [
                    'game'             => $this->mapGame($game),
                    'charge'           => $charge,
                    'requires_payment' => false,
                ]);
            });
        } catch (\RuntimeException $e) {
            return sendError($e->getMessage(), [], 422);
        }
    }

    /**
     * Tournament games whose time has passed with no winner recorded — the host
     * still has to advance the bracket. Powers the bell and the on-login prompt.
     */
    public function dueTournamentMatches(User $user)
    {
        $tournaments = HostedGame::where('host_customer_id', $user->uuid)
            ->where('game_type', 'tournament')
            ->where('status', 'open')
            ->get()
            ->keyBy('id');

        if ($tournaments->isEmpty()) {
            return sendSuccess('Due matches', ['due' => []]);
        }

        $matches = TournamentMatch::whereIn('tournament_id', $tournaments->keys())
            ->whereNotNull('match_date')
            ->orderBy('match_date')->orderBy('start_time')
            ->get();

        $today = now()->toDateString();
        $nowHM = now()->format('H:i');

        $due = $matches->filter(function (TournamentMatch $m) use ($today, $nowHM) {
            // Only a match that can actually be decided: both teams known, no winner.
            if ($m->winner || ! $m->home_team || ! $m->away_team) {
                return false;
            }

            $date = Carbon::parse($m->match_date)->toDateString();
            $end  = substr((string) ($m->end_time ?: '23:59'), 0, 5);

            return $date < $today || ($date === $today && $end <= $nowHM);
        })->map(function (TournamentMatch $m) use ($tournaments) {
            $t = $tournaments->get($m->tournament_id);

            return [
                'match'       => $this->mapMatch($m, collect()),
                'tournament'  => $this->mapGame($t),
                'totalRounds' => $this->roundsFor((int) ($t->num_teams ?: $t->max_players)),
            ];
        })->values()->all();

        return sendSuccess('Due matches', ['due' => $due]);
    }

    /**
     * The rows the notification bell builds its list from. The wording and links
     * stay in the storefront — this only supplies the data behind them.
     */
    public function notificationFeed(User $user)
    {
        $hosted = HostedGame::where('host_customer_id', $user->uuid)->get();

        $hostRequests = $hosted->isEmpty() ? collect() : GameJoinRequest::whereIn('game_id', $hosted->pluck('id'))
            ->whereIn('status', ['pending', 'accepted'])
            ->orderByDesc('created_at')->limit(60)->get();

        $myRequests = GameJoinRequest::where('joiner_customer_id', $user->uuid)
            ->whereIn('status', ['pending', 'accepted', 'rejected'])
            ->orderByDesc('created_at')->limit(60)->get();

        $myGames = HostedGame::whereIn('id', $myRequests->pluck('game_id')->unique())->get();

        // Decided matches in tournaments I have a team in, so we can tell someone
        // they advanced or were knocked out.
        $myTournamentIds = $myGames->where('game_type', 'tournament')->pluck('id');
        $matches = $myTournamentIds->isEmpty() ? collect() : TournamentMatch::whereIn('tournament_id', $myTournamentIds)
            ->whereNotNull('winner')->where('winner', '!=', '')->limit(80)->get();

        return sendSuccess('Notification feed', [
            'dueMatches'   => $this->dueTournamentMatches($user)->getData(true)['data']['due'] ?? [],
            'hostedGames'  => $hosted->map(fn ($g) => $this->mapNotificationGame($g))->values()->all(),
            'hostRequests' => $hostRequests->map(fn ($r) => $this->mapNotificationRequest($r))->values()->all(),
            'myRequests'   => $myRequests->map(fn ($r) => $this->mapNotificationRequest($r))->values()->all(),
            'myGames'      => $myGames->map(fn ($g) => $this->mapNotificationGame($g))->values()->all(),
            'matches'      => $matches->map(fn ($m) => [
                'id'              => $m->uuid,
                'tournament_id'   => $myGames->firstWhere('id', $m->tournament_id)?->uuid,
                'round'           => (int) $m->round,
                'match_index'     => (int) $m->match_index,
                'home_team'       => $m->home_team ?? '',
                'away_team'       => $m->away_team ?? '',
                'home_request_id' => $m->home_request_id ? GameJoinRequest::find($m->home_request_id)?->uuid : null,
                'away_request_id' => $m->away_request_id ? GameJoinRequest::find($m->away_request_id)?->uuid : null,
                'winner'          => $m->winner ?? '',
                'match_date'      => $m->match_date ? Carbon::parse($m->match_date)->toDateString() : null,
            ])->values()->all(),
        ]);
    }

    /** @return array<string, mixed> */
    protected function mapNotificationGame(HostedGame $g): array
    {
        return [
            'id'                => $g->uuid,
            'title'             => $g->title ?? '',
            'sport'             => $g->sport ?? '',
            'game_type'         => $g->game_type ?? 'game',
            'status'            => $g->status ?? 'open',
            'game_date'         => $g->game_date ? Carbon::parse($g->game_date)->toDateString() : null,
            'start_time'        => $g->start_time,
            'approval_mode'     => $g->approval_mode ?? 'review',
            'num_teams'         => (int) $g->num_teams,
            'moderation_status' => $g->moderation_status ?: 'active',
            'moderation_reason' => $g->moderation_reason ?? '',
            'moderated_at'      => optional($g->moderated_at)?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    protected function mapNotificationRequest(GameJoinRequest $r): array
    {
        return [
            'id'          => $r->uuid,
            'game_id'     => $r->game?->uuid,
            'joiner_name' => $r->joiner_name ?? '',
            'status'      => $r->status ?? 'pending',
            'created_at'  => optional($r->created_at)?->toIso8601String(),
        ];
    }

    /** Events I'm hosting, newest first, with how the roster is filling up. */
    public function mineHosted(User $user)
    {
        $games = HostedGame::where('host_customer_id', $user->uuid)
            ->orderByDesc('game_date')->get();

        $counts = GameJoinRequest::whereIn('game_id', $games->pluck('id'))
            ->get()->groupBy('game_id');

        $idByUuid = $games->pluck('id', 'uuid');

        $rows = $this->decorateGames($games)->map(function (array $g) use ($counts, $idByUuid) {
            $mine = $counts->get($idByUuid->get($g['id']), collect());

            return $g + [
                'pendingCount'  => $mine->where('status', 'pending')->count(),
                'acceptedCount' => $mine->where('status', 'accepted')->count(),
            ];
        })->values()->all();

        return sendSuccess('My hosted events', ['games' => $rows]);
    }

    /** Events I've asked to join, each with the event attached. */
    public function mineJoined(User $user)
    {
        $requests = GameJoinRequest::where('joiner_customer_id', $user->uuid)
            ->orderByDesc('created_at')->get();

        $games     = HostedGame::whereIn('id', $requests->pluck('game_id')->unique())->get();
        $decorated = $this->decorateGames($games)->keyBy('id');
        $uuidById  = $games->pluck('uuid', 'id');

        $rows = $requests->map(function (GameJoinRequest $r) use ($decorated, $uuidById) {
            $uuid = $uuidById->get($r->game_id);

            return $this->mapRequest($r) + ['game' => $uuid ? $decorated->get($uuid) : null];
        })->values()->all();

        return sendSuccess('My join requests', ['requests' => $rows]);
    }


    /**
     * Every event a city can join right now — the Events page.
     *
     * Only open events that haven't finished: a past date is out, and today's are
     * out once their end time has gone. Events with no date stay (they're flexible).
     * Community events are listed here on purpose — discovery is how communities
     * grow — and membership is enforced when someone actually tries to join.
     */
    public function list(mixed $requestOrCityId = null)
    {
        $cityId = null;
        $iso = '';
        $countryId = null;

        if ($requestOrCityId instanceof \Illuminate\Http\Request) {
            $cityId = $requestOrCityId->filled('city_id') ? (int) $requestOrCityId->query('city_id') : null;
            $iso = strtoupper(trim((string) $requestOrCityId->query('country_iso', '')));
            $countryId = $requestOrCityId->filled('country_id') ? (int) $requestOrCityId->query('country_id') : null;
        } elseif ($requestOrCityId !== null) {
            $cityId = (int) $requestOrCityId;
        }

        $qatarId = QatarMarketGeo::countryId();
        $isQatar = ($iso === QatarMarketGeo::ISO)
            || ($countryId && (int) $countryId === $qatarId)
            || ($cityId && $qatarId && QatarMarketGeo::touchesQatar([$cityId]));

        if (! $isQatar && ! $cityId) {
            return sendSuccess('Events', ['games' => []]);
        }

        $today = now()->toDateString();

        $query = HostedGame::where('status', 'open')
            ->where('moderation_status', '!=', 'removed')
            ->where(function ($w) use ($today) {
                $w->whereNull('game_date')->orWhere('game_date', '>=', $today);
            });

        if ($isQatar) {
            $cityIds = QatarMarketGeo::allCityIds();
            $query->where(function ($q) use ($cityIds, $qatarId) {
                if ($cityIds) {
                    $q->whereIn('city_id', $cityIds);
                }
                if ($qatarId) {
                    $q->orWhere('country_id', $qatarId);
                }
            });
        } else {
            $query->where('city_id', $cityId);
        }

        $games = $query->orderBy('game_date')
            ->get()
            ->filter(function (HostedGame $g) use ($today) {
                $date = $g->game_date ? Carbon::parse($g->game_date)->toDateString() : null;

                if (! $date || $date > $today || ! $g->end_time) {
                    return true;
                }

                return substr((string) $g->end_time, 0, 5) >= now()->format('H:i');
            })
            ->values();

        return sendSuccess('Events', ['games' => $this->decorateGames($games)->all()]);
    }

    /**
     * Why this user may not host in this community, or null when they may.
     *
     * Returns the refusal itself rather than a boolean so both call sites give
     * the same answer, and so a visitor is told to join while a plain member is
     * told who may host — two different problems with two different fixes.
     */
    protected function communityHostingDenial(User $host, Community $community)
    {
        $membership = CommunityMember::where('community_id', $community->id)
            ->where('customer_id', $host->uuid)
            ->first();

        $role = CommunityPermissions::role($community, $membership, $host->uuid);

        if (! CommunityPermissions::isMember($role)) {
            return sendError('Join this community before hosting an event in it.', [], 403);
        }

        if (! CommunityPermissions::canHostEvent($role)) {
            return sendError('Only the community owner, admins and moderators can host events here.', [], 403);
        }

        return null;
    }

    /** Events posted to a community — the community page's Meetups tab. */
    public function communityEvents(string $communityUuid)
    {
        $community = Community::where('uuid', $communityUuid)->first();

        if (! $community) {
            return sendError('Community not found.', [], 404);
        }

        $games = HostedGame::where('community_id', $community->id)
            ->where('moderation_status', '!=', 'removed')
            ->where('status', '!=', 'cancelled')
            ->get();

        // Upcoming first (soonest first), then past (most recent first) — the order
        // the community page has always shown.
        $today    = now()->toDateString();
        $upcoming = $games->filter(fn ($g) => ! $g->game_date || $g->game_date >= $today)
            ->sortBy(fn ($g) => (string) ($g->game_date ?: '9999-12-31'));
        $past = $games->filter(fn ($g) => $g->game_date && $g->game_date < $today)
            ->sortByDesc(fn ($g) => (string) $g->game_date);

        $ordered = $upcoming->concat($past)->values();

        return sendSuccess('Community events', [
            'events' => $this->decorateGames($ordered)->all(),
        ]);
    }

    /** Post an existing game to a community, or change whether it is publicly listed. */
    public function setCommunity(User $host, string $gameUuid, ?string $communityUuid, ?bool $public)
    {
        $game = HostedGame::where('uuid', $gameUuid)->first();

        if (! $game) {
            return sendError('Event not found.', [], 404);
        }

        if ($game->host_customer_id !== $host->uuid) {
            return sendError('Only the host can change this event.', [], 403);
        }

        if ($communityUuid !== null) {
            $community = Community::where('uuid', $communityUuid)->first();

            if (! $community) {
                return sendError('Community not found.', [], 404);
            }

            // Being the event's host says nothing about the community it is
            // being posted into. Without this, any host could put their event
            // on the page of a community they had never joined.
            if ($denied = $this->communityHostingDenial($host, $community)) {
                return $denied;
            }

            $newlyAttached = (int) $game->community_id !== (int) $community->id;
            $game->community_id = $community->id;
        }

        if ($public !== null) {
            $game->community_public = $public;
        }

        $game->save();

        // Only a LIVE event newly put on this community's page is news to its members.
        if (! empty($newlyAttached) && $game->published_at && isset($community)) {
            app(CommunityNotifier::class)->eventPosted($community, $game, $host);
        }

        return sendSuccess('Event updated', ['game' => $this->mapGame($game)]);
    }

    // ── Money ────────────────────────────────────────────────────────────────

    /**
     * What the provider actually lets someone pay with on this court, and the
     * deposit they set. Same source of truth a normal booking uses, so hosting a
     * game can never offer a method the venue doesn't take.
     *
     * @return array{methods: array<int,string>, deposit_amount: float, deposit_percentage: float}
     */
    protected function paymentPlan(Listing $listing, ?Venue $venue): array
    {
        $options = is_array($listing->payment_options) ? $listing->payment_options : [];
        $deposit = is_array($options['deposit'] ?? null) ? $options['deposit'] : [];

        $methods = [];

        if ($listing->online_payment || filter_var($options['online_payment_enabled'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            $methods[] = 'online';
        }

        if ($deposit !== [] || filter_var($options['deposit_enabled'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            $methods[] = 'deposit';
        }

        if (filter_var($options['cash_on_arrival'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            $methods[] = 'cash';
        }

        // Nothing configured yet — fall back on ownership, as the booking flow does.
        if ($methods === []) {
            $methods = $venue?->owner_user_id ? ['deposit'] : ['online'];
        }

        return [
            'methods'            => $methods,
            'deposit_amount'     => (float) ($deposit['amount'] ?? 0),
            'deposit_percentage' => (float) ($deposit['percentage'] ?? 0),
        ];
    }

    /**
     * Split a court total the way the chosen method pays for it: all now, all on
     * arrival, or the provider's deposit now and the rest at the venue.
     *
     * @return array{now: float, arrival: float}
     */
    protected function splitCourt(float $total, string $method, array $plan): array
    {
        if ($method === 'online') {
            return ['now' => $total, 'arrival' => 0.0];
        }

        if ($method === 'cash') {
            return ['now' => 0.0, 'arrival' => $total];
        }

        // A flat deposit wins over a percentage — the rule the booking flow uses.
        $deposit = $plan['deposit_amount'] > 0
            ? $plan['deposit_amount']
            : floor($total * ($plan['deposit_percentage'] > 0 ? $plan['deposit_percentage'] : 20) / 100);

        $deposit = max(0, min($deposit, $total));

        return ['now' => round($deposit, 2), 'arrival' => round($total - $deposit, 2)];
    }

    /**
     * Bookboll's own money inside what this host pays today.
     *
     * The seats service fee is wholly Bookboll's. The court is not:
     *
     *   online   Bookboll collects the whole court price and credits the venue
     *            its share, so only the commission is Bookboll's.
     *   deposit  the deposit IS the commission — the venue is credited nothing
     *            until the court is paid in full — so all of it is Bookboll's.
     *   cash     nothing is collected online, so there is nothing here.
     *
     * This is the ceiling on a reward. Capping against the whole amount
     * collected instead would let a host redeem points against the venue's
     * revenue, which is not Bookboll's to give away.
     */
    protected function bookbollShareNow(?Listing $listing, float $courtTotal, float $courtNow, float $serviceFee, string $method): float
    {
        if ($method === 'online') {
            $rate = $this->courtCommissionPct($listing);

            return round($serviceFee + $courtTotal * max(0, min(100, $rate)) / 100, 2);
        }

        return round($serviceFee + max(0, $courtNow), 2);
    }

    /** The method to charge on: what the host picked, if the provider allows it. */
    protected function resolveMethod(array $plan, ?string $wanted): string
    {
        if ($wanted && in_array($wanted, $plan['methods'], true)) {
            return $wanted;
        }

        // Otherwise take the most-paid-up-front option the provider does allow.
        foreach (['online', 'deposit', 'cash'] as $method) {
            if (in_array($method, $plan['methods'], true)) {
                return $method;
            }
        }

        return 'cash';
    }

    /**
     * The host's bill. The court is priced from the listing's own rates; the seats
     * service fee is a percentage of the seats the host is selling. Hosts selling
     * tickets through their own Stripe pay no seats fee — Stripe collects it there.
     */
    protected function priceHost(User $host, ?Listing $listing, array $data): array
    {
        $venue    = $listing ? $this->venueFor($listing) : null;
        $currency = $this->venueCurrency($venue);

        if (! $listing) {
            return $this->priceOwnSpot($host, $data);
        }

        $courtTotal = $this->courtPrice($listing, $data);

        // Pay for the court the way the provider allows — the same choice and the
        // same deposit a customer booking this court would get.
        $plan   = $this->paymentPlan($listing, $venue);
        $method = $this->resolveMethod($plan, $data['payment_method'] ?? null);
        $split  = $this->splitCourt($courtTotal, $method, $plan);
        $courtNow = $split['now'];

        $seats      = max(0, (int) ($data['max_players'] ?? 0));
        $seatsValue = round((float) ($data['price_per_person'] ?? 0), 2) * $seats;
        $serviceFee = $this->sellsOwnTickets($host, $data)
            ? 0
            : round($seatsValue * $this->seatFeePct($listing, ! empty($data['community_id'])) / 100);

        $loyalty = $this->loyaltyAllowed(
            $host,
            (float) ($data['loyalty_redeem'] ?? 0),
            $this->bookbollShareNow($listing, $courtTotal, $courtNow, $serviceFee, $method)
        );

        $charge = $this->charge($currency, $courtTotal, $courtNow, $serviceFee, $method === 'online', $loyalty);
        $charge['paymentMethod']  = $method;
        $charge['paymentMethods'] = $plan['methods'];

        return $charge;
    }

    /**
     * The bill for an event at the host's own spot. There is no court, so every
     * figure here is Bookboll's own money.
     *
     * Selling seats → the normal seats percentage, in the host's own currency, the
     * same as it would be at a venue. (Hosts on their own verified Stripe pay it per
     * ticket instead, so nothing is due here.)
     *
     * Free → there is no seats value to take a percentage of. That used to publish
     * for nothing; it now pays a flat fee in USD. Loyalty points are deliberately NOT
     * accepted against that one: points are held in the host's local currency and
     * there is no rate to convert them at.
     *
     * @return array<string, mixed>
     */
    protected function priceOwnSpot(User $host, array $data): array
    {
        $seats      = max(0, (int) ($data['max_players'] ?? 0));
        $seatsValue = round((float) ($data['price_per_person'] ?? 0), 2) * $seats;

        if ($seatsValue <= 0) {
            return $this->charge(self::OWN_SPOT_MIN_FEE_CURRENCY, 0, 0, $this->ownSpotMinFee(), false, 0);
        }

        $currency = $this->hostCurrency($host, $data);

        $serviceFee = $this->sellsOwnTickets($host, $data)
            ? 0
            : round($seatsValue * $this->seatFeePct(null, ! empty($data['community_id']), $this->hostCountryId($host, $data)) / 100);

        // No court, so the service fee is the whole of Bookboll's share here.
        $loyalty = $this->loyaltyAllowed($host, (float) ($data['loyalty_redeem'] ?? 0), $serviceFee);

        return $this->charge($currency, 0, 0, $serviceFee, false, $loyalty);
    }

    /** The flat publish fee for a free own-spot event, admin-tunable. */
    protected function ownSpotMinFee(): float
    {
        $setting = $this->setting('own_spot_min_fee');

        return is_numeric($setting) ? round(max(0, (float) $setting), 2) : self::OWN_SPOT_MIN_FEE;
    }

    /** Which country an own-spot event belongs to — it has no venue to ask. */
    protected function hostCountryId(User $host, array $data): ?int
    {
        $id = $data['country_id'] ?? $host->country_id;

        return $id ? (int) $id : null;
    }

    /**
     * The currency an own-spot event prices its seats in: the host's own country.
     * Empty when we cannot tell — charging in a guessed currency is worse than not
     * charging, exactly as venueCurrency() refuses to guess.
     */
    protected function hostCurrency(User $host, array $data): string
    {
        $countryId = $this->hostCountryId($host, $data);

        if (! $countryId) {
            return '';
        }

        $code = Country::with('currency')->find($countryId)?->currency?->code;

        return strtoupper((string) ($code ?: ''));
    }

    /** @return array<string, mixed> */
    protected function charge(string $currency, float $courtTotal, float $courtNow, float $serviceFee, bool $owned, float $loyalty): array
    {
        return [
            'currency'      => $currency,
            'courtTotal'    => round($courtTotal, 2),
            'courtNow'      => round($courtNow, 2),
            'courtArrival'  => round($courtTotal - $courtNow, 2),
            'serviceFee'    => round($serviceFee, 2),
            'bookbollOwned' => $owned,
            'loyaltyRedeem' => round($loyalty, 2),
            'dueNow'        => round(max(0, $courtNow + $serviceFee - $loyalty), 2),
            'dueArrival'    => round($courtTotal - $courtNow, 2),
        ];
    }

    /** Court price for the host's date and time, straight from the listing's rates. */
    protected function courtPrice(Listing $listing, array $data): float
    {
        try {
            $quote = $this->quotes->quoteBlock(
                $listing,
                (string) $data['game_date'],
                (string) $data['start_time'],
                (string) $data['end_time'],
                max(1, (int) ($data['max_players'] ?? 1))
            );
        } catch (\Throwable $e) {
            // NEVER fall back to zero. This used to `return 0.0`, which turned any
            // pricing failure into a free court: a block ending at midnight threw,
            // was swallowed here, and the host reserved the venue for nothing.
            // Refusing is the only safe answer — the venue must never be given away
            // because we could not work out what it costs.
            Log::error('Hosted game court pricing failed', [
                'listing_id' => $listing->id,
                'date'       => $data['game_date'] ?? null,
                'start_time' => $data['start_time'] ?? null,
                'end_time'   => $data['end_time'] ?? null,
                'error'      => $e->getMessage(),
            ]);

            throw new \RuntimeException(
                "We couldn't price that court for the time you picked. Please choose another time or court."
            );
        }

        return round((float) ($quote['subtotal'] ?? 0), 2);
    }

    /**
     * The currency this venue charges in. The venue's own override wins, then its
     * country's currency. There is deliberately no platform default: charging a
     * host in a guessed currency is worse than refusing to charge them at all.
     */
    protected function venueCurrency(?Venue $venue): string
    {
        if (! $venue) {
            return '';
        }

        if (! empty($venue->currency_override)) {
            return strtoupper((string) $venue->currency_override);
        }

        $code = $venue->country_id
            ? Country::with('currency')->find($venue->country_id)?->currency?->code
            : null;

        return strtoupper((string) ($code ?: ''));
    }

    /** Listing override → the venue's country rate → platform default. */
    protected function courtCommissionPct(?Listing $listing): float
    {
        if (! $listing) {
            return self::COURT_COMMISSION_PCT;
        }

        if ($listing->commission_rate !== null && $listing->commission_rate !== '') {
            return (float) $listing->commission_rate;
        }

        $rate = $this->countryRate($this->venueFor($listing)?->country_id, 'facility');

        return $rate ?? self::COURT_COMMISSION_PCT;
    }

    /**
     * Community rate → the country's event rate → platform default. The country comes
     * from the venue, or — for an own-spot event, which has none — from $countryId.
     */
    protected function seatFeePct(?Listing $listing, bool $community = false, ?int $countryId = null): float
    {
        if ($community) {
            $setting = $this->setting('community_commission');

            if (is_numeric($setting)) {
                return (float) $setting;
            }
        }

        $rate = $this->countryRate($listing ? $this->venueFor($listing)?->country_id : $countryId, 'event');

        if ($rate !== null) {
            return $rate;
        }

        $global = $this->setting('global_commission_event');

        return is_numeric($global) ? (float) $global : self::SEAT_FEE_PCT;
    }

    protected function countryRate(?int $countryId, string $listingType): ?float
    {
        if (! $countryId) {
            return null;
        }

        $rate = DB::table('commission_rates')
            ->where('country_id', $countryId)
            ->where('listing_type', $listingType)
            ->value('rate_percent');

        return $rate === null ? null : (float) $rate;
    }

    protected function setting(string $key): mixed
    {
        return DB::table('settings')->where('key', $key)->value('value');
    }

    /**
     * How much loyalty the host may actually spend: never more than they hold, and
     * never more than the amount Bookboll is collecting now. Points never reduce
     * the venue's on-arrival balance — that is the provider's money.
     */
    protected function loyaltyAllowed(User $host, float $requested, float $bookbollNow): float
    {
        // $bookbollNow is BOOKBOLL'S OWN share of what is collected now, not the
        // whole amount collected. The two differ on a fully-online court, where
        // Bookboll collects the court price but keeps only its commission — the
        // rest is the venue's, and a reward is never funded from it.
        $wanted = min(max(0, $requested), max(0, $bookbollNow));

        if ($wanted <= 0) {
            return 0.0;
        }

        $config = LoyaltyProgram::where('country_id', $host->country_id)->first();
        $rate   = (int) ($config->redeem_rate ?? LoyaltyProgram::defaults()['redeem_rate']);
        $unit   = (float) ($config->redeem_value ?? LoyaltyProgram::defaults()['redeem_value']) ?: 1;
        $held   = (int) (LoyaltyPoint::balance($host->id) ?? 0);

        return round(min($wanted, $held / max(1, $rate) * $unit), 2);
    }

    // ── The court reservation ────────────────────────────────────────────────

    /** Any confirmed booking overlapping this court and time blocks the host. */
    protected function slotTaken(int $listingId, string $date, string $start, string $end): bool
    {
        return Booking::where('listing_id', $listingId)
            ->where('date', $date)
            ->where('status', '!=', 'cancelled')
            ->where('start_time', '<', $end)
            ->where('end_time', '>', $start)
            ->exists();
    }

    protected function reserveCourt(
        User $host,
        Listing $listing,
        ?Venue $venue,
        array $data,
        array $charge,
        bool $settled = true
    ): Booking {
        // Record it under the method the host is paying on. `$settled` says whether
        // the money is already in: it is only true when there is nothing to collect
        // (a free court, or points covering the whole bill). When the host is being
        // sent to Stripe the booking starts unpaid, exactly like a normal customer
        // checkout, and Stripe's confirmation is what marks it paid.
        if ($charge['courtTotal'] <= 0) {
            $paymentStatus = 'payment_skipped';
            $paymentMethod = 'payment_skipped';
        } elseif (($charge['paymentMethod'] ?? '') === 'cash') {
            $paymentStatus = 'pending';
            $paymentMethod = 'cash_on_arrival';
        } elseif ($charge['courtArrival'] > 0) {
            $paymentStatus = $settled ? 'deposit_paid' : 'pending';
            $paymentMethod = 'deposit_remaining_on_arrival';
        } else {
            $paymentStatus = $settled ? 'fully_paid' : 'pending';
            $paymentMethod = 'online_payment';
        }

        return Booking::create([
            'user_id'        => $host->id,
            'listing_id'     => $listing->id,
            'venue_id'       => $venue?->id,
            'date'           => $data['game_date'],
            'start_time'     => $data['start_time'],
            'end_time'       => $data['end_time'],
            'players'        => max(1, (int) ($data['max_players'] ?? 1)),
            'subtotal'       => $charge['courtTotal'],
            'total_price'    => $charge['courtTotal'],
            // A hosted game's court is confirmed on the same rule as every
            // other booking: only when nothing is left to collect. A host who
            // paid a deposit with the balance due at the venue leaves this
            // pending until the venue collects it.
            'status'         => initialBookingStatus($paymentStatus, (float) $charge['courtTotal']),
            'payment_status' => $paymentStatus,
            'payment_method' => $paymentMethod,
            'origin'         => 'customer_game',
            'notes'          => 'Hosted '.($data['game_type'] ?? 'game').': '.($data['title'] ?? $data['sport'] ?? '')
                .'. Player seat payments handled offline; Bookboll service fee pre-paid by host.',
        ]);
    }

    /**
     * What the host paid, split the way the money actually moves: the deposit and
     * the seats fee go to Bookboll now, the balance is owed to the venue on arrival.
     * Loyalty is Bookboll's to give, so it comes off the service fee first, then
     * the deposit — never the venue's balance.
     */
    protected function recordHostPayments(
        User $host,
        Booking $booking,
        array $charge,
        string $currency,
        bool $spendLoyalty = true,
        bool $settled = true
    ): void {
        // Money the host still has to pay is written as PENDING, never 'paid'.
        // These rows used to be created already marked paid with no charge id, so
        // venues were shown money that had never arrived. settleHostCheckout()
        // flips them once Stripe confirms.
        $nowStatus = $settled ? 'paid' : 'pending';

        $remaining = $charge['loyaltyRedeem'];

        $serviceNet = max(0, $charge['serviceFee'] - $remaining);
        $remaining  = max(0, $remaining - $charge['serviceFee']);

        $courtNet  = max(0, $charge['courtNow'] - $remaining);
        $remaining = max(0, $remaining - $charge['courtNow']);

        $rows = [];

        if ($courtNet > 0) {
            $rows[] = [
                'type'      => $charge['bookbollOwned'] ? 'full' : 'deposit',
                'amount'    => $courtNet,
                'recipient' => '@bookboll',
                'status'    => $nowStatus,
                'notes'     => $charge['bookbollOwned'] ? 'Court paid in full online' : 'Court deposit',
            ];
        }

        if ($serviceNet > 0) {
            $rows[] = [
                'type'      => 'commission',
                'amount'    => $serviceNet,
                'recipient' => '@bookboll',
                'status'    => $nowStatus,
                'notes'     => 'Seats service fee (non-refundable)',
            ];
        }

        if ($charge['courtArrival'] > 0) {
            $rows[] = [
                'type'      => 'remaining',
                'amount'    => $charge['courtArrival'],
                'recipient' => '@provider',
                'status'    => 'pending',
                'notes'     => 'Balance due to the venue on arrival',
            ];
        }

        foreach ($rows as $row) {
            Payment::create([
                'payment_id'  => 'game-'.Str::uuid(),
                'user_id'     => $host->id,
                'booking_id'  => $booking->id,
                'source_type' => 'booking',
                'type'        => $row['type'],
                'method'      => 'online',
                'amount'      => $row['amount'],
                'currency'    => $currency,
                'recipient'   => $row['recipient'],
                'status'      => $row['status'],
                'date'        => now()->toDateString(),
                'paid_at'     => $row['status'] === 'paid' ? now() : null,
                'notes'       => $row['notes'],
            ]);
        }

        // A bracket spends its points once for the whole thing, not per match, and
        // never before the payment they part-funded actually goes through.
        if ($spendLoyalty && $settled && $charge['loyaltyRedeem'] > 0) {
            $this->spendLoyalty($host, $charge['loyaltyRedeem']);
        }
    }

    /**
     * The ledger row for an own-spot event's fee. Every other hosted payment hangs off
     * a court booking; this one has none, so it is keyed to the game by payment_id and
     * settled (or cancelled) by that key.
     */
    protected function recordOwnSpotFee(
        User $host,
        HostedGame $game,
        array $charge,
        string $currency,
        bool $settled
    ): void {
        $amount = round(max(0, $charge['serviceFee'] - $charge['loyaltyRedeem']), 2);

        if ($amount <= 0) {
            return;
        }

        Payment::create([
            'payment_id'  => $this->ownSpotFeeKey($game),
            'user_id'     => $host->id,
            'booking_id'  => null,
            'source_type' => 'hosted_game',
            'type'        => 'commission',
            'method'      => 'online',
            'amount'      => $amount,
            'currency'    => $currency,
            'recipient'   => '@bookboll',
            'status'      => $settled ? 'paid' : 'pending',
            'date'        => now()->toDateString(),
            'paid_at'     => $settled ? now() : null,
            'notes'       => (float) $game->price_per_person > 0
                ? 'Seats service fee — own spot (non-refundable)'
                : 'Publish fee — free event at the host\'s own spot (non-refundable)',
        ]);
    }

    /** Stable payment_id for an own-spot fee, so settle and discard can find it. */
    protected function ownSpotFeeKey(HostedGame $game): string
    {
        return 'game-fee-'.$game->uuid;
    }

    /**
     * Send the host to Stripe for what they owe now, on the same checkout the rest
     * of the platform uses. The game already exists but is unpublished, and its
     * courts are reserved but unpaid — this session is what turns both real.
     *
     * @param  array<int,int>  $bookingIds  every court this game holds
     * @return array{url:string,session_id:string}
     */
    protected function startHostCheckout(
        User $host,
        HostedGame $game,
        array $bookingIds,
        array $charge,
        string $currency
    ): array {
        $amount = round((float) $charge['dueNow'], 2);

        if ($amount <= 0) {
            throw new \RuntimeException('There is nothing to pay for this event.');
        }

        if ($currency === '') {
            // Charging in a guessed currency is worse than not charging: the host
            // would be billed in one currency and the venue paid in another. An
            // own-spot event lands here when its country has no currency set.
            throw new \RuntimeException(
                $game->listing_id
                    ? 'This venue has no currency set, so we cannot take payment for it yet. Please contact support.'
                    : 'Your country has no currency set, so we cannot take payment yet. Please contact support.'
            );
        }

        \Stripe\Stripe::setApiKey(config('services.stripe.secret'));

        $host->createOrGetStripeCustomer();

        if (! $host->stripe_id) {
            throw new \RuntimeException('We could not start the payment. Please try again.');
        }

        $stripe   = app(StripeService::class);
        $urls     = $stripe->checkoutReturnUrls();
        $listing  = $game->listing_id ? Listing::with('pricingGroups')->find($game->listing_id) : null;
        $venue    = $game->location_id ? Venue::find($game->location_id) : null;
        $label    = $game->game_type === 'tournament' ? 'Tournament' : ($game->game_type === 'event' ? 'Hangout' : 'Game');

        $lines = array_filter([
            $charge['courtNow'] > 0
                ? ($charge['bookbollOwned'] ? 'Court' : 'Court deposit').': '.$currency.' '.number_format($charge['courtNow'], 2)
                : null,
            $charge['serviceFee'] > 0
                ? ((! $game->listing_id && (float) $game->price_per_person <= 0) ? 'Publish fee' : 'Seats service fee')
                    .': '.$currency.' '.number_format($charge['serviceFee'], 2)
                : null,
            $charge['loyaltyRedeem'] > 0 ? 'Points applied: -'.$currency.' '.number_format($charge['loyaltyRedeem'], 2) : null,
            $charge['courtArrival'] > 0 ? 'Due at the venue on arrival: '.$currency.' '.number_format($charge['courtArrival'], 2) : null,
        ]);

        $description = substr(trim(implode(' | ', $lines)), 0, 500);

        $session = \Stripe\Checkout\Session::create([
            'customer'             => $host->stripe_id,
            'payment_method_types' => ['card'],
            'mode'                 => 'payment',
            'success_url'          => $urls['success_url'],
            'cancel_url'           => $urls['cancel_url'],
            'metadata'             => [
                // `source` routes fulfilment here instead of the normal booking path.
                'source'           => self::CHECKOUT_SOURCE,
                'hosted_game_uuid' => (string) $game->uuid,
                'game_type'        => (string) $game->game_type,
                'user_id'          => (string) $host->id,
                'user_email'       => (string) ($host->email ?? ''),
                'booking_id'       => (string) ($bookingIds[0] ?? ''),
                'all_booking_ids'  => json_encode(array_values($bookingIds)),
                'venue_id'         => (string) ($game->location_id ?? ''),
                'venue_name'       => (string) ($venue?->name ?? ''),
                'listing_id'       => (string) ($game->listing_id ?? ''),
                'listing_name'     => (string) ($listing?->name ?? ''),
                'payment_type'     => 'hosted_game',
                'currency'         => $currency,
                'court_now'        => (string) $charge['courtNow'],
                'service_fee'      => (string) $charge['serviceFee'],
                'loyalty_redeem'   => (string) $charge['loyaltyRedeem'],
                'due_now'          => (string) $amount,
                // The storefront the host started on — see PaymentController::storefrontUrl().
                'return_origin'    => (string) TenantOrigins::storefrontOriginFromRequest(request()),
                // Set when the host is in the mobile app — see MobileAppReturn.
                MobileAppReturn::METADATA_KEY => MobileAppReturn::targetFromRequest(request()),
            ],
            'line_items' => [[
                'price_data' => [
                    'currency'     => strtolower($currency),
                    'unit_amount'  => (int) round($amount * 100),
                    'product_data' => [
                        'name'        => $label.': '.($game->title ?: $game->sport ?: 'Hosted event'),
                        'description' => $description !== '' ? $description : 'Hosted event',
                    ],
                ],
                'quantity' => 1,
            ]],
        ]);

        StripeSession::create([
            'session_id'  => $session->id,
            'booking_id'  => $bookingIds[0] ?? null,
            'booking_ids' => array_values($bookingIds),
            'source'      => self::CHECKOUT_SOURCE,
            'processed'   => false,
        ]);

        return ['url' => $session->url, 'session_id' => $session->id];
    }

    /**
     * Stripe confirmed the host's payment: settle every row it covered, credit the
     * venue, spend the points and put the event live.
     *
     * Runs inside the fulfilment transaction and must be idempotent — Stripe will
     * happily deliver the same webhook twice.
     */
    public function settleHostCheckout(string $gameUuid, string $chargeId, string $paymentIntentId): void
    {
        $game = HostedGame::withUnpublished()
            ->where('uuid', $gameUuid)
            ->lockForUpdate()
            ->first();

        if (! $game) {
            Log::error('Hosted game missing during Stripe fulfilment', ['uuid' => $gameUuid]);

            return;
        }

        if ($game->isPublished()) {
            return;   // already settled by an earlier delivery of this webhook
        }

        foreach ($this->bookingIdsFor($game) as $bookingId) {
            $booking = Booking::whereKey($bookingId)->lockForUpdate()->first();

            if (! $booking) {
                continue;
            }

            if ($booking->status === BookingStatus::CANCELLED) {
                // The court went away while the host was paying. Publishing now
                // would advertise an event with nowhere to play it.
                throw new \RuntimeException(
                    "Court booking #{$booking->booking_id} was cancelled; refusing to publish a paid hosted game without its court."
                );
            }

            $this->settleHostBooking($booking, $chargeId, $paymentIntentId);
        }

        // An own-spot event has no booking, so its fee row is not covered by the loop
        // above — settle it by the key it was written under.
        Payment::where('payment_id', $this->ownSpotFeeKey($game))
            ->where('status', PaymentStatus::PENDING)
            ->update(['status' => PaymentStatus::PAID, 'paid_at' => now()]);

        $charge = is_array($game->charge) ? $game->charge : [];
        $host   = User::where('uuid', $game->host_customer_id)->first();

        if ($host) {
            // Points are only spent now — the charge they discounted has landed.
            if ((float) ($charge['loyaltyRedeem'] ?? 0) > 0) {
                $this->spendLoyalty($host, (float) $charge['loyaltyRedeem']);
            }

            $this->awardHostBonus($host);
        }

        $game->forceFill(['published_at' => now()])->save();

        if ($game->community_id && ($community = Community::find($game->community_id))) {
            app(CommunityNotifier::class)->eventPosted($community, $game, $host);
        }

        Log::info('Hosted game published after payment', [
            'uuid'       => $game->uuid,
            'game_type'  => $game->game_type,
            'charge_id'  => $chargeId,
            'booking_ids' => $this->bookingIdsFor($game),
        ]);
    }

    /**
     * The host walked away from Stripe. Release the courts and drop the event so
     * nothing half-made is left behind — the slot has to go back on sale.
     */
    public function discardUnpaidGame(string $gameUuid, string $reason = 'Payment was not completed.'): void
    {
        $game = HostedGame::withUnpublished()
            ->where('uuid', $gameUuid)
            ->lockForUpdate()
            ->first();

        if (! $game || $game->isPublished()) {
            return;   // nothing pending, or the host paid after all
        }

        $bookingIds = $this->bookingIdsFor($game);

        $cancelNote = DB::raw("CONCAT(COALESCE(notes,''), ' — cancelled: unpaid hosted event')");

        Payment::whereIn('booking_id', $bookingIds)
            ->where('status', PaymentStatus::PENDING)
            ->update(['status' => PaymentStatus::CANCELLED, 'notes' => $cancelNote]);

        // Own-spot events have no booking — their fee row is keyed to the game.
        Payment::where('payment_id', $this->ownSpotFeeKey($game))
            ->where('status', PaymentStatus::PENDING)
            ->update(['status' => PaymentStatus::CANCELLED, 'notes' => $cancelNote]);

        foreach ($bookingIds as $bookingId) {
            $booking = Booking::whereKey($bookingId)->lockForUpdate()->first();

            if (! $booking || $booking->status === BookingStatus::CANCELLED) {
                continue;
            }

            $booking->update([
                'status' => BookingStatus::CANCELLED,
                'notes'  => trim((string) $booking->notes.' — '.$reason),
            ]);
        }

        TournamentMatch::where('tournament_id', $game->id)->delete();
        $game->delete();

        Log::info('Unpaid hosted game discarded', ['uuid' => $gameUuid, 'booking_ids' => $bookingIds]);
    }

    /** Every court booking this event holds — one for a game, many for a bracket. */
    protected function bookingIdsFor(HostedGame $game): array
    {
        $ids = $game->booking_id ? [(int) $game->booking_id] : [];

        if ($game->game_type === 'tournament') {
            $ids = array_merge(
                $ids,
                TournamentMatch::where('tournament_id', $game->id)
                    ->whereNotNull('booking_id')
                    ->pluck('booking_id')
                    ->map(fn ($id) => (int) $id)
                    ->all()
            );
        }

        return array_values(array_unique(array_filter($ids)));
    }

    /**
     * Mark one court's collected money as received, exactly the way a normal
     * customer booking is settled: flip its pending rows, set the booking's
     * payment state from what was actually collected, and credit the provider.
     */
    protected function settleHostBooking(Booking $booking, string $chargeId, string $paymentIntentId): void
    {
        // The seats service fee is Bookboll's own money — it settles, but it is
        // not part of what the venue is owed, so it is handled apart from the
        // court rows below and never reaches the provider wallet.
        Payment::where('booking_id', $booking->id)
            ->where('type', 'commission')
            ->where('status', PaymentStatus::PENDING)
            ->update([
                'status'            => PaymentStatus::PAID,
                'method'            => 'online',
                'charge_id'         => $chargeId,
                'payment_intent_id' => $paymentIntentId,
                'paid_at'           => now(),
                'date'              => now()->toDateString(),
            ]);

        $courtRows = Payment::where('booking_id', $booking->id)
            ->where('status', PaymentStatus::PENDING)
            ->whereIn('type', [PaymentType::FULL, PaymentType::DEPOSIT])
            ->lockForUpdate()
            ->get();

        if ($courtRows->isEmpty()) {
            // Cash on arrival: Stripe only collected the seats fee, so the court
            // is still owed to the venue and the booking stays pending.
            return;
        }

        $paidInFull = $courtRows->contains(fn (Payment $p) => $p->type === PaymentType::FULL);
        $collected  = (float) $courtRows->sum('amount');

        foreach ($courtRows as $row) {
            $row->update([
                'status'            => PaymentStatus::PAID,
                'method'            => 'online',
                'charge_id'         => $chargeId,
                'payment_intent_id' => $paymentIntentId,
                'paid_at'           => now(),
                'date'              => now()->toDateString(),
            ]);
        }

        $booking->update([
            'payment_status'        => $paidInFull ? BookingPaymentStatus::FULLY_PAID : BookingPaymentStatus::DEPOSIT_PAID,
            'payment_method'        => $paidInFull ? BookingPaymentMethod::ONLINE_PAYMENT : BookingPaymentMethod::DEPOSIT_REMAINING_ON_ARRIVAL,
            'full_payment_received' => $paidInFull,
        ]);

        $booking->history()->create([
            'user_id'     => $booking->user_id,
            'action'      => 'payment_received',
            'description' => "Hosted event payment marked as PAID via Stripe. Charge ID: {$chargeId}",
        ]);

        autoConfirmBookingIfPaid($booking->fresh());
        regenerateBookingInvoice($booking->fresh());

        // Same rule as every other booking: a deposit IS Bookboll's commission, so
        // the provider is credited only when the whole court was collected.
        if ($paidInFull) {
            // The venue is paid on what the court COSTS, not on what was
            // collected after a reward. These used to be the same number, so
            // redeeming points on a hosted game quietly cut the venue's payout
            // by the non-commission part of the discount — the reward has to
            // come out of Bookboll's fee and nothing else.
            $courtPrice = round((float) ($booking->total_price ?: $collected), 2);
            $reward = max(0, round($courtPrice - $collected, 2));

            $this->creditProviderWallet($booking, $courtPrice, $reward);
        }
    }

    /**
     * Credit the venue owner's wallet for the court.
     *
     * `$courtPrice` is the court's real price and `$reward` whatever a reward
     * took off it. The ledger computes the venue's share from the price and
     * caps the reward at Bookboll's fee, so the payout is identical with or
     * without a reward.
     */
    protected function creditProviderWallet(Booking $booking, float $courtPrice, float $reward = 0): void
    {
        try {
            $venue       = $booking->venue_id ? Venue::find($booking->venue_id) : null;
            $ownerUserId = $venue?->owner_user_id;

            if (! $ownerUserId || $courtPrice <= 0) {
                return;   // Bookboll-run venue — there is no provider to pay
            }

            $wallets = app(BookingWalletService::class);

            $wallets->recordBookingOnlinePayment([
                'bookingId'     => $booking->id,
                'ownerUserId'   => $ownerUserId,
                'countryId'     => $wallets->countryIdForVenue($venue),
                'total'         => $courtPrice,
                'currency'      => $this->venueCurrency($venue),
                'ratePct'       => $wallets->courtCommissionPct($booking->listing),
                'customerLabel' => $booking->user?->name ?: 'Hosted event',
                // Capped at the platform fee inside the ledger, which leaves
                // the venue's share untouched.
                'loyaltyRedeem' => $reward,
            ]);
        } catch (\Throwable $e) {
            // A booking is paid whether or not the ledger writes; never roll the
            // host's payment back over an accounting error.
            Log::error('Hosted game provider wallet credit failed', [
                'booking_id' => $booking->id,
                'error'      => $e->getMessage(),
            ]);
        }
    }

    protected function spendLoyalty(User $host, float $amount): void
    {
        $config = LoyaltyProgram::where('country_id', $host->country_id)->first();
        $rate   = (int) ($config->redeem_rate ?? LoyaltyProgram::defaults()['redeem_rate']);
        $unit   = (float) ($config->redeem_value ?? LoyaltyProgram::defaults()['redeem_value']) ?: 1;

        $points = min((int) ceil($amount / $unit * $rate), (int) (LoyaltyPoint::balance($host->id) ?? 0));

        if ($points <= 0) {
            return;
        }

        LoyaltyPoint::create([
            'user_id' => $host->id,
            'type'    => 'debit',
            'points'  => $points,
            'reason'  => 'Redeemed on hosting',
        ]);

        $host->updateLoyaltyTier();
    }

    /** Hosting earns a flat bonus, once per game. */
    protected function awardHostBonus(User $host): void
    {
        LoyaltyPoint::create([
            'user_id' => $host->id,
            'type'    => 'credit',
            'points'  => self::HOST_BONUS,
            'reason'  => 'Hosted a game',
        ]);

        $host->updateLoyaltyTier();
    }


    // ── Viewing and joining ──────────────────────────────────────────────────

    /** One event, with the people context the detail page shows. */
    public function show(string $uuid, ?User $viewer = null)
    {
        $game = HostedGame::where('uuid', $uuid)->first();

        // Removed by a Bookboll admin — hidden even from a direct link.
        if (! $game || $game->moderation_status === 'removed') {
            return sendError('Event not found.', [], 404);
        }

        [$decorated] = $this->decorateGames(collect([$game]))->all();

        $mine = $viewer?->uuid
            ? GameJoinRequest::where('game_id', $game->id)->where('joiner_customer_id', $viewer->uuid)->first()
            : null;

        return sendSuccess('Event', [
            'game'      => $decorated,
            'myRequest' => $mine ? $this->mapRequest($mine) : null,
            'isHost'    => $viewer?->uuid && $viewer->uuid === $game->host_customer_id,
        ]);
    }

    /** Everyone who asked to join — the host's roster. */
    public function requests(User $host, string $uuid)
    {
        $game = HostedGame::where('uuid', $uuid)->first();

        if (! $game) {
            return sendError('Event not found.', [], 404);
        }

        if ($game->host_customer_id !== $host->uuid) {
            return sendError('Only the host can see the roster.', [], 403);
        }

        $rows = GameJoinRequest::where('game_id', $game->id)->orderBy('created_at')->get();

        return sendSuccess('Requests', [
            'requests' => $rows->map(fn ($r) => $this->mapRequest($r))->values()->all(),
        ]);
    }

    /**
     * Ask to join. Instant events skip approval, a full event queues on the
     * waitlist, and a members-only community event is closed to non-members.
     */
    public function join(User $user, string $uuid, array $data)
    {
        $game = HostedGame::where('uuid', $uuid)->first();

        if (! $game || $game->moderation_status === 'removed') {
            return sendError('Event not found.', [], 404);
        }

        if ($game->request_deadline && $game->request_deadline->isPast()) {
            return sendError('Requests for this event have closed.', [], 422);
        }

        $accepted = GameJoinRequest::where('game_id', $game->id)->where('status', 'accepted')->count();

        // Once an event locks, starts or ends the roster is final.
        $state = $this->meetupState($game, $accepted);

        if ($state === 'locked') {
            return sendError('This event is locked — the roster is final. Message the host if you still want in.', [], 422);
        }

        if ($state === 'active') {
            return sendError('This event has already started.', [], 422);
        }

        if ($state === 'archived') {
            return sendError('This event has ended.', [], 422);
        }

        if ($state === 'cancelled') {
            return sendError('This event was cancelled.', [], 422);
        }

        // A community event that was never listed publicly is members-only.
        if ($game->community_id && ! $game->community_public) {
            $isMember = CommunityMember::where('community_id', $game->community_id)
                ->where('customer_id', $user->uuid)
                ->where('status', 'accepted')
                ->exists();

            if (! $isMember) {
                return sendError('This is a members-only community event — join the community first.', [
                    'community_id' => Community::find($game->community_id)?->uuid,
                ], 422);
            }
        }

        $cap = (int) $game->max_players;
        $full = $cap > 0 && $accepted >= $cap;

        if ($full && $game->allow_waitlist === false) {
            return sendError('This event is full.', [], 422);
        }

        $status = $full ? 'waitlisted' : ($game->approval_mode === 'instant' ? 'accepted' : 'pending');

        $request = GameJoinRequest::updateOrCreate(
            ['game_id' => $game->id, 'joiner_customer_id' => $user->uuid],
            [
                'joiner_name'     => $data['joiner_name'] ?? ($user->name ?: ''),
                'joiner_phone'    => $data['joiner_phone'] ?? ($user->phone ?: ''),
                'joiner_whatsapp' => $data['joiner_whatsapp'] ?? ($data['joiner_phone'] ?? ''),
                'joiner_message'  => $data['joiner_message'] ?? '',
                'form_answers'    => $data['form_answers'] ?? [],
                'status'          => $status,
            ]
        );

        // The joiner hears where they stand, the host hears that someone wants in.
        $this->notifyQuietly($user, match ($status) {
            'accepted'   => new EventJoinConfirmedNotification($game, 'instant'),
            'waitlisted' => new EventWaitlistedNotification($game),
            default      => new EventJoinRequestedNotification($game),
        });

        if ($status !== 'waitlisted') {
            $this->notifyQuietly($this->hostUser($game), new EventRequestReceivedNotification(
                $game,
                $request->joiner_name ?: ($user->name ?: ''),
                $status === 'accepted',
                (string) ($data['joiner_message'] ?? '')
            ));
        }

        return sendSuccess('Request sent', ['request' => $this->mapRequest($request->fresh())]);
    }

    /** The joiner's own request, so the page knows what to show them. */
    public function myRequest(User $user, string $uuid)
    {
        $game = HostedGame::where('uuid', $uuid)->first();

        if (! $game) {
            return sendError('Event not found.', [], 404);
        }

        $row = GameJoinRequest::where('game_id', $game->id)
            ->where('joiner_customer_id', $user->uuid)
            ->first();

        return sendSuccess('My request', ['request' => $row ? $this->mapRequest($row) : null]);
    }

    /**
     * Accept everyone waiting, oldest first, but never past the cap — anyone who
     * doesn't fit stays pending so the host can decide.
     */
    public function acceptAllRequests(User $host, string $uuid)
    {
        $game = HostedGame::where('uuid', $uuid)->first();

        if (! $game) {
            return sendError('Event not found.', [], 404);
        }

        if ($game->host_customer_id !== $host->uuid) {
            return sendError('Only the host can accept requests.', [], 403);
        }

        $rows     = GameJoinRequest::where('game_id', $game->id)->orderBy('created_at')->get();
        $pending  = $rows->where('status', 'pending')->values();
        $accepted = $rows->where('status', 'accepted')->count();

        if ($pending->isEmpty()) {
            return sendSuccess('Nothing to accept', ['accepted' => 0, 'leftover' => 0]);
        }

        // A tournament caps on teams; everything else on players.
        $cap  = (int) ($game->game_type === 'tournament' ? ($game->num_teams ?: $game->max_players) : $game->max_players);
        $take = $pending;

        if ($cap > 0) {
            $remaining = max(0, $cap - $accepted);

            if ($remaining <= 0) {
                return sendSuccess('Event is full', [
                    'accepted' => 0,
                    'leftover' => $pending->count(),
                    'full'     => true,
                ]);
            }

            $take = $pending->take($remaining);
        }

        GameJoinRequest::whereIn('id', $take->pluck('id'))->update(['status' => 'accepted']);

        foreach ($take as $row) {
            $this->notifyQuietly($this->joinerUser($row), new EventJoinConfirmedNotification($game));
        }

        return sendSuccess('Requests accepted', [
            'accepted' => $take->count(),
            'leftover' => $pending->count() - $take->count(),
        ]);
    }

    /** Host accepts or rejects someone. Accepting never overfills the event. */
    public function respondToRequest(User $host, string $requestUuid, string $status)
    {
        $request = GameJoinRequest::where('uuid', $requestUuid)->first();

        if (! $request) {
            return sendError('Request not found.', [], 404);
        }

        $game = HostedGame::find($request->game_id);

        if (! $game || $game->host_customer_id !== $host->uuid) {
            return sendError('Only the host can decide this.', [], 403);
        }

        if ($status === 'accepted') {
            $cap = (int) $game->max_players;
            $accepted = GameJoinRequest::where('game_id', $game->id)
                ->where('status', 'accepted')
                ->where('id', '!=', $request->id)
                ->count();

            if ($cap > 0 && $accepted >= $cap) {
                return sendError('This event is already full.', [], 422);
            }
        }

        $wasAccepted = $request->status === 'accepted';

        $request->update(['status' => $status]);

        $this->notifyQuietly(
            $this->joinerUser($request),
            $status === 'accepted'
                ? new EventJoinConfirmedNotification($game)
                : new EventJoinDeclinedNotification($game, $wasAccepted)
        );

        if ($status !== 'accepted') {
            $this->promoteFromWaitlist($game);
        }

        return sendSuccess('Request updated', ['request' => $this->mapRequest($request->fresh())]);
    }

    // ── Email ────────────────────────────────────────────────────────────────
    // Events ran silent: a host learned their event had filled only by opening the
    // app, and a cancelled event reached its players when they turned up. These
    // send on the moments where one person is waiting on another to act.
    //
    // Every send is wrapped — a bounced address or a dead SMTP host must never turn
    // "you're in" into a failed request. Same rule the booking notifications follow.

    /** The host's User row, or null if the account has gone. */
    protected function hostUser(HostedGame $game): ?User
    {
        return $game->host_customer_id ? User::where('uuid', $game->host_customer_id)->first() : null;
    }

    protected function joinerUser(GameJoinRequest $request): ?User
    {
        return $request->joiner_customer_id ? User::where('uuid', $request->joiner_customer_id)->first() : null;
    }

    /** Send, and never let the sending be the reason the caller fails. */
    protected function notifyQuietly(?User $user, $notification): void
    {
        if (! $user) {
            return;
        }

        try {
            $user->notify($notification);
        } catch (\Throwable $e) {
            Log::error('Hosted event notification failed: '.$e->getMessage(), [
                'notification' => $notification::class,
                'user_id'      => $user->id,
                'exception'    => $e,
            ]);
        }
    }

    /**
     * Tell everyone with a stake in this event — holding a spot, waiting on the
     * host, or queued on the waitlist. One wrapped send each, so one bad address
     * does not stop the rest of the room being told.
     */
    protected function notifyParticipants(HostedGame $game, callable $make): void
    {
        $rows = GameJoinRequest::where('game_id', $game->id)
            ->whereIn('status', ['accepted', 'pending', 'waitlisted'])
            ->get();

        foreach ($rows as $row) {
            $this->notifyQuietly($this->joinerUser($row), $make($row));
        }
    }

    /** A joiner drops out. Their spot goes to whoever waited longest. */
    public function cancelRequest(User $user, string $requestUuid)
    {
        $request = GameJoinRequest::where('uuid', $requestUuid)->first();

        if (! $request) {
            return sendError('Request not found.', [], 404);
        }

        $game = HostedGame::find($request->game_id);
        $isOwner = $request->joiner_customer_id === $user->uuid;
        $isHost = $game && $game->host_customer_id === $user->uuid;

        if (! $isOwner && ! $isHost) {
            return sendError('You cannot drop this request.', [], 403);
        }

        // Close to kick-off the roster is fixed, so a confirmed spot can't be dropped.
        if ($game && $request->status === 'accepted') {
            $state = $this->meetupState($game, GameJoinRequest::where('game_id', $game->id)->where('status', 'accepted')->count());

            if (in_array($state, ['locked', 'active'], true) && $isOwner) {
                return sendError("This event is locked — spots can't be dropped this close to start. Message the host if you really can't make it.", [], 422);
            }
        }

        $hadSpot = $request->status === 'accepted';
        $joiner  = $this->joinerUser($request);
        $name    = $request->joiner_name ?: ($joiner?->name ?: '');

        $request->delete();

        if ($game) {
            if ($isOwner) {
                // They walked: the host's roster just changed and they may need to
                // find someone else.
                $accepted  = GameJoinRequest::where('game_id', $game->id)->where('status', 'accepted')->count();
                $cap       = (int) $game->max_players;
                $spotsLeft = $cap > 0 ? max(0, $cap - $accepted) : 0;

                $this->notifyQuietly(
                    $this->hostUser($game),
                    new EventParticipantLeftNotification($game, $name, $hadSpot, $spotsLeft)
                );
            } else {
                // The host dropped them — they are the ones who need telling.
                $this->notifyQuietly($joiner, new EventJoinDeclinedNotification($game, $hadSpot));
            }

            $this->promoteFromWaitlist($game);
        }

        return sendSuccess('Request removed', ['ok' => true]);
    }

    /**
     * Fill a freed spot from the waitlist, oldest first. An instant-join event
     * takes them straight in; one the host reviews puts them back in the queue.
     */
    protected function promoteFromWaitlist(HostedGame $game): void
    {
        $cap = (int) $game->max_players;

        if ($cap <= 0) {
            return;
        }

        $accepted = GameJoinRequest::where('game_id', $game->id)->where('status', 'accepted')->count();

        if ($accepted >= $cap) {
            return;
        }

        $next = GameJoinRequest::where('game_id', $game->id)
            ->where('status', 'waitlisted')
            ->orderBy('created_at')
            ->first();

        if (! $next) {
            return;
        }

        $promotedTo = $game->approval_mode === 'instant' ? 'accepted' : 'pending';
        $next->update(['status' => $promotedTo]);

        // Waiting is the whole point of a waitlist — the person has to be told the
        // moment it turns into something, or the spot goes unused.
        $this->notifyQuietly(
            $this->joinerUser($next),
            $promotedTo === 'accepted'
                ? new EventJoinConfirmedNotification($game, 'waitlist')
                : new EventJoinRequestedNotification($game)
        );
    }

    /** Host edits the details people can safely be told about after publishing. */
    public function updateGame(User $host, string $uuid, array $patch)
    {
        $game = HostedGame::where('uuid', $uuid)->first();

        if (! $game) {
            return sendError('Event not found.', [], 404);
        }

        if ($game->host_customer_id !== $host->uuid) {
            return sendError('Only the host can edit this event.', [], 403);
        }

        // The cap can never drop below the players already holding a spot.
        if (array_key_exists('max_players', $patch) && (int) $patch['max_players'] > 0) {
            $accepted = GameJoinRequest::where('game_id', $game->id)->where('status', 'accepted')->count();

            if ($accepted > (int) $patch['max_players']) {
                return sendError("$accepted players are already in — max players can't go below that.", [], 422);
            }
        }

        // Only a move in time or place is worth an email — a reworded description
        // would just train people to ignore these.
        $watched = [
            'game_date'            => 'date',
            'start_time'           => 'start time',
            'end_time'             => 'end time',
            'listing_id'           => 'location',
            'location_id'          => 'location',
            'custom_location_name' => 'location',
        ];

        $changed = [];

        foreach ($watched as $field => $label) {
            if (array_key_exists($field, $patch) && (string) $game->getAttribute($field) !== (string) $patch[$field]) {
                $changed[$label] = $label;
            }
        }

        $game->update($patch);

        if ($changed && $game->status !== 'cancelled') {
            $fresh = $game->fresh();
            $this->notifyParticipants($fresh, fn () => new EventUpdatedNotification($fresh, array_values($changed)));
        }

        return sendSuccess('Event updated', ['game' => $this->mapGame($game->fresh())]);
    }

    /** Host calls the event off; the court reservation is released with it. */
    public function cancelGame(User $host, string $uuid)
    {
        $game = HostedGame::where('uuid', $uuid)->first();

        if (! $game) {
            return sendError('Event not found.', [], 404);
        }

        if ($game->host_customer_id !== $host->uuid) {
            return sendError('Only the host can cancel this event.', [], 403);
        }

        $game->update(['status' => 'cancelled']);

        if ($game->booking_id) {
            Booking::where('id', $game->booking_id)->update(['status' => 'cancelled']);
        }

        // Without this the first anyone hears of a cancellation is turning up to it.
        $this->notifyParticipants($game, fn () => new EventCancelledNotification($game));

        return sendSuccess('Event cancelled', ['ok' => true]);
    }

    /** Host ticks off who actually turned up. */
    public function markAttendance(User $host, string $requestUuid, bool $attended)
    {
        $request = GameJoinRequest::where('uuid', $requestUuid)->first();

        if (! $request) {
            return sendError('Request not found.', [], 404);
        }

        $game = HostedGame::find($request->game_id);

        if (! $game || $game->host_customer_id !== $host->uuid) {
            return sendError('Only the host can mark attendance.', [], 403);
        }

        $request->update(['attended' => $attended]);

        return sendSuccess('Attendance saved', ['ok' => true]);
    }

    /** The host's saved round/matchmaking sheet for an event. */
    public function saveRounds(User $host, string $uuid, $rounds)
    {
        $game = HostedGame::where('uuid', $uuid)->first();

        if (! $game) {
            return sendError('Event not found.', [], 404);
        }

        if ($game->host_customer_id !== $host->uuid) {
            return sendError('Only the host can save rounds.', [], 403);
        }

        $game->update(['rounds' => $rounds]);

        return sendSuccess('Rounds saved', ['ok' => true]);
    }

    /**
     * Where an event is in its life: cancelled, ended, running, locked to new
     * joins, full, or open. Mirrors meetup.js so both sides agree.
     */
    protected function meetupState(HostedGame $game, int $acceptedCount = 0): string
    {
        if ($game->status === 'cancelled') {
            return 'cancelled';
        }

        $date = $game->game_date ? Carbon::parse($game->game_date)->toDateString() : null;

        if ($date) {
            $start = Carbon::parse($date.' '.($game->start_time ?: '00:00'));
            $end = Carbon::parse($date.' '.($game->end_time ?: '23:59'));

            if (now()->greaterThan($end)) {
                return 'archived';
            }

            if (now()->greaterThanOrEqualTo($start)) {
                return 'active';
            }

            $lockMinutes = (int) $game->lock_minutes_before;

            if ($lockMinutes > 0 && now()->greaterThanOrEqualTo($start->copy()->subMinutes($lockMinutes))) {
                return 'locked';
            }
        }

        $cap = (int) $game->max_players;

        if ($cap > 0 && $acceptedCount >= $cap) {
            return 'waitlisted';
        }

        return 'open';
    }

    /** @return array<string, mixed> */
    protected function mapRequest(GameJoinRequest $r): array
    {
        return [
            'id'                 => $r->uuid,
            'game_id'            => $r->game?->uuid,
            'joiner_customer_id' => $r->joiner_customer_id,
            'joiner_name'        => $r->joiner_name ?? '',
            'joiner_phone'       => $r->joiner_phone ?? '',
            'joiner_whatsapp'    => $r->joiner_whatsapp ?? '',
            'joiner_message'     => $r->joiner_message ?? '',
            'status'             => $r->status ?? 'pending',
            'form_answers'       => $r->form_answers ?? [],
            'attended'           => (bool) $r->attended,
            'created_at'         => optional($r->created_at)?->toIso8601String(),
        ];
    }


    // ── Tournaments ──────────────────────────────────────────────────────────

    /**
     * Publish a knockout bracket: one parent event plus a match per game, each
     * holding its own court. If any court can't be reserved the whole thing is
     * rolled back — a half-booked tournament would be worse than none.
     */
    public function createTournament(User $host, array $data)
    {
        if (! $host->uuid) {
            return sendError('Please sign in to host.');
        }

        $teams = (int) ($data['num_teams'] ?? 0);

        if (! in_array($teams, [4, 8, 16, 32], true)) {
            return sendError('Pick how many teams will play.', [], 422);
        }

        $matches = $data['matches'] ?? [];

        if (count($matches) !== $teams - 1) {
            return sendError('Please schedule every game.', [], 422);
        }

        // Earliest game represents the tournament; the last one gives its end time.
        usort($matches, fn ($a, $b) => strcmp($a['date'].'T'.$a['start_time'], $b['date'].'T'.$b['start_time']));
        $first = $matches[0];
        $last  = $matches[count($matches) - 1];

        $listings = Listing::with('pricingGroups')->whereIn('id', array_filter(array_column($matches, 'listing_id')))->get()->keyBy('id');

        foreach ($matches as $m) {
            if (empty($m['listing_id']) || ! $listings->has($m['listing_id'])) {
                return sendError('Every game needs a venue and court.', [], 422);
            }
        }

        try {
            $charge = $this->priceTournament($host, $listings, $matches, $data);
        } catch (\RuntimeException $e) {
            return sendError($e->getMessage(), [], 422);
        }

        $currency = $charge['currency'];

        // A bracket is paid for as one bill covering every court in it.
        $needsPayment = $charge['dueNow'] > 0;

        $community = ! empty($data['community_id'])
            ? Community::where('uuid', $data['community_id'])->first()
            : null;

        try {
            return DB::transaction(function () use ($host, $data, $teams, $matches, $first, $last, $listings, $charge, $currency, $community, $needsPayment) {
                $firstListing = $listings->get($first['listing_id']);

                $tournament = HostedGame::create([
                    'host_customer_id'    => $host->uuid,
                    'host_name'           => $data['host_name'] ?? ($host->name ?: ''),
                    'host_phone'          => $data['host_phone'] ?? ($host->phone ?: ''),
                    'host_whatsapp'       => $data['host_whatsapp'] ?? ($data['host_phone'] ?? ''),
                    'listing_id'          => $firstListing?->id,
                    'location_id'         => $firstListing ? $this->venueFor($firstListing)?->id : null,
                    'city_id'             => $data['city_id'] ?? null,
                    'country_id'          => $data['country_id'] ?? null,
                    'title'               => $data['title'] ?? '',
                    'sport'               => $data['sport'] ?? '',
                    'game_type'           => 'tournament',
                    'num_teams'           => $teams,
                    'format'              => 'single_elim',
                    'game_date'           => $first['date'],
                    'start_time'          => $first['start_time'],
                    'end_time'            => $last['end_time'],
                    'price_per_person'    => round((float) ($data['price_per_person'] ?? 0), 2),
                    'currency'            => $currency,
                    'max_players'         => $teams,
                    'skill_level'         => $data['skill_level'] ?? 'all',
                    'description'         => $data['description'] ?? '',
                    'status'              => 'open',
                    'prize_pot'           => round((float) ($data['prize_pot'] ?? 0), 2),
                    'prize_note'          => $data['prize_note'] ?? null,
                    'signup_form'         => $data['signup_form'] ?? [],
                    'request_deadline'    => $data['request_deadline'] ?? null,
                    'approval_mode'       => $data['approval_mode'] ?? 'review',
                    'allow_waitlist'      => ($data['allow_waitlist'] ?? true) !== false,
                    'lock_minutes_before' => $data['lock_minutes_before'] ?? null,
                    'charge'              => $charge,
                    'community_id'        => $community?->id,
                    'community_public'    => $community ? (bool) ($data['community_public'] ?? false) : false,
                    'moderation_status'   => 'active',
                    // Nothing is live until the money is in.
                    'published_at'        => $needsPayment ? null : now(),
                ]);

                // Lock every court this bracket touches before checking any of
                // them, always in id order: two brackets sharing courts then queue
                // behind each other instead of deadlocking half-way through.
                $lockIds = array_values(array_unique(array_map('intval', array_column($matches, 'listing_id'))));
                sort($lockIds);

                $bookings = app(BookingService::class);

                foreach ($lockIds as $lockId) {
                    $bookings->lockListingForBooking($lockId);
                }

                $firstBooking = null;
                $bookingIds   = [];

                foreach ($matches as $i => $m) {
                    $listing = $listings->get($m['listing_id']);
                    $venue   = $this->venueFor($listing);
                    $line    = $charge['matches'][$i];

                    if ($this->slotTaken($listing->id, $m['date'], $m['start_time'], $m['end_time'])) {
                        throw new \RuntimeException(
                            'That court is already booked on '.$m['date'].' at '.$m['start_time'].' — pick another time for that game.'
                        );
                    }

                    $booking = $this->reserveCourt($host, $listing, $venue, [
                        'game_date'   => $m['date'],
                        'start_time'  => $m['start_time'],
                        'end_time'    => $m['end_time'],
                        'max_players' => $teams,
                        'game_type'   => 'tournament',
                        'title'       => ($data['title'] ?? $data['sport'] ?? '').' — round '.((int) $m['round'] + 1).', match '.((int) $m['index'] + 1),
                    ], $line, ! $needsPayment);

                    $firstBooking ??= $booking;
                    $bookingIds[] = $booking->id;
                    $this->recordHostPayments($host, $booking, $line, $currency, spendLoyalty: false, settled: ! $needsPayment);

                    TournamentMatch::create([
                        'tournament_id' => $tournament->id,
                        'round'         => (int) $m['round'],
                        'match_index'   => (int) $m['index'],
                        'listing_id'    => $listing->id,
                        'location_id'   => $venue?->id,
                        'booking_id'    => $booking->id,
                        'match_date'    => $m['date'],
                        'start_time'    => $m['start_time'],
                        'end_time'      => $m['end_time'],
                        'status'        => 'scheduled',
                    ]);
                }

                // One seats fee covers the whole bracket, so it is booked once
                // against the first match rather than repeated per court.
                if ($charge['serviceFee'] > 0 && $firstBooking) {
                    $this->recordHostPayments($host, $firstBooking, $this->charge(
                        $currency, 0, 0, $charge['serviceFee'], true, $charge['serviceFeeLoyalty']
                    ), $currency, spendLoyalty: false, settled: ! $needsPayment);
                }

                if ($needsPayment) {
                    $checkout = $this->startHostCheckout($host, $tournament, $bookingIds, $charge, $currency);

                    return sendSuccess('Complete payment to publish', [
                        'tournament'       => $this->mapGame($tournament),
                        'charge'           => $charge,
                        'requires_payment' => true,
                        'checkout_url'     => $checkout['url'],
                        'session_id'       => $checkout['session_id'],
                    ]);
                }

                // Points are spent once for the whole bracket, not per match — and
                // only when there was nothing left to collect.
                if ($charge['loyaltyRedeem'] > 0) {
                    $this->spendLoyalty($host, $charge['loyaltyRedeem']);
                }

                $this->awardHostBonus($host);

                if ($community) {
                    app(CommunityNotifier::class)->eventPosted($community, $tournament, $host);
                }

                return sendSuccess('Tournament published', [
                    'tournament'       => $this->mapGame($tournament),
                    'charge'           => $charge,
                    'requires_payment' => false,
                ]);
            });
        } catch (\RuntimeException $e) {
            return sendError($e->getMessage(), [], 422);
        }
    }

    /**
     * The host's bill for a bracket: every match's court, plus one seats service
     * fee for the whole thing. Loyalty comes off the fee first, then each court's
     * deposit in order — never a venue's on-arrival balance.
     *
     * @return array<string, mixed>
     */
    protected function priceTournament(User $host, $listings, array $matches, array $data): array
    {
        $teams    = (int) ($data['num_teams'] ?? 0);
        $currency = '';
        $lines    = [];
        $bookbollNow = 0.0;

        foreach ($matches as $m) {
            $listing = $listings->get($m['listing_id']);
            $venue   = $this->venueFor($listing);
            $currency = $this->venueCurrency($venue) ?: $currency;

            $courtTotal = $this->courtPrice($listing, [
                'game_date'   => $m['date'],
                'start_time'  => $m['start_time'],
                'end_time'    => $m['end_time'],
                'max_players' => $teams,
            ]);

            $plan     = $this->paymentPlan($listing, $venue);
            $method   = $this->resolveMethod($plan, $data['payment_method'] ?? null);
            $split    = $this->splitCourt($courtTotal, $method, $plan);
            $courtNow = $split['now'];

            $bookbollNow += $courtNow;
            $line = $this->charge($currency, $courtTotal, $courtNow, 0, $method === 'online', 0);
            $line['paymentMethod'] = $method;
            $lines[] = $line;
        }

        $seatsValue = round((float) ($data['price_per_person'] ?? 0), 2) * $teams;
        $serviceFee = $this->sellsOwnTickets($host, $data)
            ? 0
            : round($seatsValue * $this->seatFeePct($listings->first(), ! empty($data['community_id'])) / 100);

        $loyalty = $this->loyaltyAllowed($host, (float) ($data['loyalty_redeem'] ?? 0), $bookbollNow + $serviceFee);

        // Spread the points: the seats fee first, then across each court in turn.
        $budget = max(0, $loyalty - $serviceFee);

        foreach ($lines as $i => $line) {
            $take = min($budget, $line['courtNow']);
            $budget = round($budget - $take, 2);
            $lines[$i]['loyaltyRedeem'] = round($take, 2);
            $lines[$i]['dueNow'] = round(max(0, $line['courtNow'] - $take), 2);
        }

        // The whole bracket's bill, with each match's line kept for the bookings.
        // The bracket only counts as fully online if every one of its courts is.
        $total = $this->charge(
            $currency,
            array_sum(array_column($lines, 'courtTotal')),
            array_sum(array_column($lines, 'courtNow')),
            $serviceFee,
            $lines !== [] && ! in_array(false, array_column($lines, 'bookbollOwned'), true),
            $loyalty
        );
        $total['matches'] = $lines;
        $total['serviceFeeLoyalty'] = round(min($loyalty, $serviceFee), 2);

        return $total;
    }

    /** A tournament with its bracket, for the tournament page. */
    public function showTournament(string $uuid, ?User $viewer = null)
    {
        $tournament = HostedGame::where('uuid', $uuid)->where('game_type', 'tournament')->first();

        if (! $tournament || $tournament->moderation_status === 'removed') {
            return sendError('Tournament not found.', [], 404);
        }

        [$decorated] = $this->decorateGames(collect([$tournament]))->all();

        $matches  = TournamentMatch::where('tournament_id', $tournament->id)
            ->orderBy('round')->orderBy('match_index')->get();
        $listings = Listing::with('pricingGroups')->whereIn('id', $matches->pluck('listing_id')->filter()->unique())->get()->keyBy('id');
        $requests = GameJoinRequest::where('game_id', $tournament->id)->orderBy('created_at')->get();

        $mine = $viewer?->uuid ? $requests->firstWhere('joiner_customer_id', $viewer->uuid) : null;

        return sendSuccess('Tournament', [
            'tournament' => $decorated,
            'matches'    => $matches->map(fn ($m) => $this->mapMatch($m, $listings))->values()->all(),
            'requests'   => $requests->map(fn ($r) => $this->mapRequest($r))->values()->all(),
            'totalRounds' => $this->roundsFor((int) $tournament->num_teams),
            'myRequest'  => $mine ? $this->mapRequest($mine) : null,
            'isHost'     => $viewer?->uuid && $viewer->uuid === $tournament->host_customer_id,
        ]);
    }

    /**
     * Seed the first round from the accepted teams, in the order they were
     * accepted. A team left without an opponent walks into the next round.
     */
    public function generateMatchups(User $host, string $uuid)
    {
        $tournament = HostedGame::where('uuid', $uuid)->where('game_type', 'tournament')->first();

        if (! $tournament) {
            return sendError('Tournament not found.', [], 404);
        }

        if ($tournament->host_customer_id !== $host->uuid) {
            return sendError('Only the host can seed the bracket.', [], 403);
        }

        $accepted = GameJoinRequest::where('game_id', $tournament->id)
            ->where('status', 'accepted')->orderBy('created_at')->get()->values();

        $round0 = TournamentMatch::where('tournament_id', $tournament->id)
            ->where('round', 0)->orderBy('match_index')->get();

        $totalRounds = $this->roundsFor((int) $tournament->num_teams);

        foreach ($round0 as $i => $match) {
            $home = $accepted[$i * 2] ?? null;
            $away = $accepted[$i * 2 + 1] ?? null;

            $match->update([
                'home_team'       => $home?->joiner_name ?? '',
                'home_request_id' => $home?->id,
                'away_team'       => $away?->joiner_name ?? '',
                'away_request_id' => $away?->id,
            ]);

            // A lone team has nobody to beat, so it advances instead of stalling.
            if ($home && ! $away) {
                $match->update(['winner' => 'home', 'status' => 'done']);
                $this->advanceWinner($tournament, $match, $home->joiner_name, $home->id, $totalRounds);
            }
        }

        return sendSuccess('Bracket seeded', [
            'count'    => $accepted->count(),
            'overflow' => max(0, $accepted->count() - $round0->count() * 2),
        ]);
    }

    /** Host records who won; the winner moves into the next round. */
    public function setMatchWinner(User $host, string $matchUuid, string $side)
    {
        $match = TournamentMatch::where('uuid', $matchUuid)->first();

        if (! $match) {
            return sendError('Match not found.', [], 404);
        }

        $tournament = HostedGame::find($match->tournament_id);

        if (! $tournament || $tournament->host_customer_id !== $host->uuid) {
            return sendError('Only the host can record results.', [], 403);
        }

        $team = $side === 'home' ? $match->home_team : $match->away_team;

        if (! $team) {
            return sendError('That side has no team yet.', [], 422);
        }

        $match->update(['winner' => $side, 'status' => 'done']);

        $this->advanceWinner(
            $tournament,
            $match,
            $team,
            $side === 'home' ? $match->home_request_id : $match->away_request_id,
            $this->roundsFor((int) $tournament->num_teams)
        );

        return sendSuccess('Result saved', ['ok' => true]);
    }

    /** Undo a result — only while the winner hasn't already played on. */
    public function clearMatchWinner(User $host, string $matchUuid)
    {
        $match = TournamentMatch::where('uuid', $matchUuid)->first();

        if (! $match) {
            return sendError('Match not found.', [], 404);
        }

        $tournament = HostedGame::find($match->tournament_id);

        if (! $tournament || $tournament->host_customer_id !== $host->uuid) {
            return sendError('Only the host can change results.', [], 403);
        }

        $next = $this->nextMatchFor(
            (int) $match->round,
            (int) $match->match_index,
            $this->roundsFor((int) $tournament->num_teams)
        );

        if ($next) {
            $target = TournamentMatch::where('tournament_id', $tournament->id)
                ->where('round', $next['round'])->where('match_index', $next['index'])->first();

            if ($target?->winner) {
                return sendError('That team already played their next match. Undo that later result first, then change this one.', [], 422);
            }

            $target?->update($next['slot'] === 'home'
                ? ['home_team' => '', 'home_request_id' => null]
                : ['away_team' => '', 'away_request_id' => null]);
        }

        $match->update(['winner' => null, 'status' => 'scheduled']);

        return sendSuccess('Result cleared', ['ok' => true]);
    }

    /** Carry a winning team into its next-round slot. */
    protected function advanceWinner(HostedGame $tournament, TournamentMatch $match, string $team, ?int $requestId, int $totalRounds): void
    {
        $next = $this->nextMatchFor((int) $match->round, (int) $match->match_index, $totalRounds);

        if (! $next) {
            return;
        }

        $target = TournamentMatch::where('tournament_id', $tournament->id)
            ->where('round', $next['round'])->where('match_index', $next['index'])->first();

        $target?->update($next['slot'] === 'home'
            ? ['home_team' => $team, 'home_request_id' => $requestId]
            : ['away_team' => $team, 'away_request_id' => $requestId]);
    }

    /** Rounds in a single-elimination bracket. Mirrors bracket.js. */
    protected function roundsFor(int $teams): int
    {
        return max(1, (int) round(log(max(2, $teams), 2)));
    }

    /**
     * Where the winner of (round, index) goes — null at the Final. Winners of the
     * even-numbered match take the home slot, the odd one the away slot.
     *
     * @return array{round:int,index:int,slot:string}|null
     */
    protected function nextMatchFor(int $round, int $index, int $totalRounds): ?array
    {
        if ($round >= $totalRounds - 1) {
            return null;
        }

        return [
            'round' => $round + 1,
            'index' => intdiv($index, 2),
            'slot'  => $index % 2 === 0 ? 'home' : 'away',
        ];
    }

    /** @return array<string, mixed> */
    protected function mapMatch(TournamentMatch $m, $listings): array
    {
        return [
            'id'              => $m->uuid,
            'round'           => (int) $m->round,
            'match_index'     => (int) $m->match_index,
            'listing_id'      => $m->listing_id,
            'listingName'     => $listings->get($m->listing_id)?->name ?? '',
            'location_id'     => $m->location_id,
            'match_date'      => $m->match_date ? Carbon::parse($m->match_date)->toDateString() : null,
            'start_time'      => $m->start_time,
            'end_time'        => $m->end_time,
            'home_team'       => $m->home_team ?? '',
            'away_team'       => $m->away_team ?? '',
            'home_request_id' => $m->home_request_id,
            'away_request_id' => $m->away_request_id,
            'winner'          => $m->winner ?? '',
            'status'          => $m->status ?? 'scheduled',
        ];
    }

    // ── Shaping ──────────────────────────────────────────────────────────────



    protected function venueFor(Listing $listing): ?Venue
    {
        return $listing->venue ?: Venue::find($listing->venue_id);
    }

    /**
     * Add the people context the event cards show — who is going, who is hosting,
     * and the community an event belongs to.
     *
     * @param  \Illuminate\Support\Collection<int, HostedGame>  $games
     */
    protected function decorateGames($games)
    {
        $ids      = $games->pluck('id')->all();
        $requests = $ids
            ? GameJoinRequest::whereIn('game_id', $ids)->where('status', 'accepted')->get()
            : collect();

        $venues = Venue::whereIn('id', $games->pluck('location_id')->filter()->unique())->get()->keyBy('id');
        $hosts  = User::whereIn('uuid', $games->pluck('host_customer_id')->filter()->unique())->get()->keyBy('uuid');
        // A list can mix communities, so load them all rather than assume one.
        $communities = Community::whereIn('id', $games->pluck('community_id')->filter()->unique())->get()->keyBy('id');

        return $games->map(function (HostedGame $g) use ($requests, $venues, $hosts, $communities) {
            $community = $g->community_id ? $communities->get($g->community_id) : null;
            $going = $requests->where('game_id', $g->id);
            $host  = $hosts->get($g->host_customer_id);
            $venue = $g->location_id ? $venues->get($g->location_id) : null;

            return $this->mapGame($g, $communities) + [
                'locationName'    => $venue?->name ?? '',
                'locationAddress' => $venue?->address ?? '',
                'joinedCount'     => $going->count(),
                // Legacy rows point at customers that were never migrated, so the
                // name saved on the row is the dependable one.
                'hostName'        => $host?->name ?: (string) $g->host_name,
                'hostAvatar'      => $host?->image ?: '',
                'communityName'   => $community?->name ?? '',
                'communityAvatar' => $community?->avatar_image ?? '',
                'goingAvatars'    => $going->take(5)->map(fn ($r) => [
                    'name'   => (string) $r->joiner_name,
                    'avatar' => '',
                ])->values()->all(),
                'goingNames'      => $going->take(2)
                    ->map(fn ($r) => Str::before(trim((string) $r->joiner_name), ' '))
                    ->filter()->values()->all(),
            ];
        });
    }

    /** @return array<string, mixed> */
    protected function mapGame(HostedGame $g, $communities = null): array
    {
        return [
            'id'                      => $g->uuid,
            'host_customer_id'        => $g->host_customer_id,
            'host_name'               => $g->host_name ?? '',
            'title'                   => $g->title ?? '',
            'sport'                   => $g->sport ?? '',
            'game_type'               => $g->game_type ?? 'game',
            'game_date'               => $g->game_date ? Carbon::parse($g->game_date)->toDateString() : null,
            'start_time'              => $g->start_time,
            'end_time'                => $g->end_time,
            'price_per_person'        => (float) $g->price_per_person,
            'currency'                => $g->currency ?? '',
            'max_players'             => (int) $g->max_players,
            'skill_level'             => $g->skill_level ?? 'all',
            'description'             => $g->description ?? '',
            'status'                  => $g->status ?? 'open',
            'prize_pot'               => (float) $g->prize_pot,
            'prize_note'              => $g->prize_note ?? '',
            'custom_location_name'    => $g->custom_location_name ?? '',
            'custom_location_address' => $g->custom_location_address ?? '',
            'hide_location'           => (bool) $g->hide_location,
            'images'                  => $g->images ?? [],
            'approval_mode'           => $g->approval_mode ?? 'review',
            'allow_waitlist'          => $g->allow_waitlist !== false,
            'request_deadline'        => optional($g->request_deadline)?->toIso8601String(),
            'lock_minutes_before'     => $g->lock_minutes_before,
            'play_format'             => $g->play_format,
            'num_teams'               => (int) $g->num_teams,
            'community_id'            => $g->community_id
                ? ($communities?->get($g->community_id) ?? Community::find($g->community_id))?->uuid
                : null,
            'community_public'        => (bool) $g->community_public,
            'moderation_status'       => $g->moderation_status ?: 'active',
            // The mobile host flow polls this after Stripe to learn the event went
            // live; without it the "payment not confirmed" sheet never cleared.
            'published_at'            => optional($g->published_at)?->toIso8601String(),
            'created_at'              => optional($g->created_at)?->toIso8601String(),
        ];
    }
}
