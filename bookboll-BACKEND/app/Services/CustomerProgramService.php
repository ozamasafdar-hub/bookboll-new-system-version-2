<?php

namespace App\Services;

use App\Models\ClassProgram;
use App\Support\HourPeriods;
use App\Models\ClassSubscription;
use App\Models\EntryPass;
use App\Models\LoyaltyPoint;
use App\Models\LoyaltyProgram;
use App\Models\OfferRedemption;
use App\Models\Payment;
use App\Support\StripeDocumentLink;
use App\Models\ProviderWallet;
use App\Models\User;
use App\Models\Schedule;
use App\Models\Venue;
use App\Models\WalletTransaction;
use App\Support\QatarMarketGeo;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Nnjeim\World\Models\City;
use Nnjeim\World\Models\Country;

/**
 * Customer marketplace class programs / memberships — parity with storefront data.js.
 * Public program IDs are UUIDs; FKs use numeric class_programs.id.
 */
class CustomerProgramService
{
    private const INTERVAL_LABEL = [
        'weekly' => 'week', 'biweekly' => '2 weeks', 'monthly' => 'month',
        'quarterly' => 'quarter', 'yearly' => 'year', 'one_time' => 'one-time',
        'session' => 'session', 'term' => 'term', 'package' => 'package', 'day' => 'day',
    ];

    private const INTERVAL_MONTHS = [
        'weekly' => 0.25, 'biweekly' => 0.5, 'monthly' => 1, 'quarterly' => 3, 'yearly' => 12,
    ];

    public function __construct(protected WalletPayoutService $wallets)
    {}

    public function list(Request $request)
    {
        $cityId = $request->query('city_id');
        $brandOwner = $request->query('brand_owner') ?: null;
        $iso = strtoupper(trim((string) $request->query('country_iso', '')));
        $countryId = $request->query('country_id');

        $qatarId = QatarMarketGeo::countryId();
        $isQatar = ($iso === QatarMarketGeo::ISO)
            || ($countryId && (int) $countryId === $qatarId)
            || ($cityId && $qatarId && QatarMarketGeo::touchesQatar([(int) $cityId]));

        // Country-only browsing (no city chosen yet): scope by the country's
        // venues instead of returning nothing, matching the web.
        $scopeCountryId = null;
        if (! $cityId && ! $brandOwner && ! $isQatar) {
            $scopeCountryId = $countryId ? (int) $countryId : ($iso !== '' ? (int) Country::where('iso2', $iso)->value('id') : null);
            if (! $scopeCountryId) {
                return sendSuccess('Programs fetched', ['programs' => []]);
            }
        }

        $query = ClassProgram::query()
            ->with(['venue', 'listingSubscriptions'])
            ->where('status', 'active')
            ->where(function ($q) {
                $q->where('is_draft', false)->orWhereNull('is_draft');
            });

        if ($brandOwner) {
            $query->where(function ($q) use ($brandOwner) {
                $q->where('owner_user_id', $brandOwner)
                    ->orWhere('mapped_owner_user_id', $brandOwner);
            })->where(function ($q) {
                $q->where('show_on_provider_site', true)->orWhereNull('show_on_provider_site');
            });
        } else {
            $query->where(function ($q) {
                $q->where('show_on_marketplace', true)->orWhereNull('show_on_marketplace');
            })->where(function ($q) {
                $q->where('approval_status', 'approved')->orWhereNull('approval_status');
            })->where(function ($q) {
                $q->whereNull('eligibility_status')->orWhere('eligibility_status', '!=', 'paused');
            });
        }

        // A listing belongs to its actual venue, never every market its owner serves.
        // Orphan/imported records have no reliable geography and stay out of discovery.
        if ($isQatar) {
            $query->whereHas('venue', function ($v) use ($qatarId) {
                $v->where('country_id', $qatarId)->orWhere(fn ($fallback) => $fallback->whereNull('country_id')->whereIn('city_id', QatarMarketGeo::allCityIds()));
            });
        } elseif ($scopeCountryId) {
            $query->whereHas('venue', function ($v) use ($scopeCountryId) {
                $v->where('country_id', $scopeCountryId)
                    ->orWhere(fn ($fallback) => $fallback->whereNull('country_id')->whereIn('city_id', City::where('country_id', $scopeCountryId)->select('id')));
            });
        } elseif ($cityId) {
            $query->whereHas('venue', fn ($v) => $v->where('city_id', (int) $cityId));
        }

        $programs = $query->orderBy('sort_order')->orderBy('name')->get()->filter(fn ($p) => $this->isVisible($p, $brandOwner));

        // A program with no venue_id shows no venue name — never borrow another
        // venue's, which would label every orphan with the same wrong place.
        // Run ProgramVenuesFromSupabaseSeeder to link imported programs.
        $counts = [];

        $cards = $programs->map(
            fn (ClassProgram $p) => $this->mapCard($p, (int) ($counts[$p->id] ?? 0))
        )->values()->all();

        return sendSuccess('Programs fetched', ['programs' => $cards]);
    }

    /**
     * Resolve a program from the identifier in a public URL.
     *
     * The canonical public id is the uuid, but links built from the numeric id
     * are already out in the world: the admin share dialog, its QR code, the
     * website-embed snippet and banner deep-links all used to emit `/programs/{id}`.
     * Those are printed, pasted into provider sites and sent to members — they
     * cannot be recalled, so both forms have to resolve or the links 404.
     *
     * This exposes nothing extra: isVisible() still decides what a visitor may
     * see, and it is applied to whichever form was used.
     */
    protected function findPublicProgram(string $key): ?ClassProgram
    {
        $program = ClassProgram::with(['venue', 'listingSubscriptions'])->where('uuid', $key)->first();
        if ($program) {
            return $program;
        }

        return ctype_digit($key)
            ? ClassProgram::with(['venue', 'listingSubscriptions'])->whereKey((int) $key)->first()
            : null;
    }

    public function show(string $uuid, Request $request)
    {
        $brandOwner = $request->query('brand_owner') ?: null;
        $program = $this->findPublicProgram($uuid);
        if (! $program || ! $this->isVisible($program, $brandOwner)) {
            return sendError('Program not found.', [], 404);
        }

        $venue = $program->venue;
        $location = $venue ? [
            'id'      => (string) $venue->id,
            'name'    => $venue->name ?? '',
            'address' => $venue->address ?? '',
            'phone'   => $venue->phone ?? '',
        ] : null;

        $openingHours = null;
        if (($program->category ?: '') === 'gym' && $venue) {
            $openingHours = $this->venueHours((int) $venue->id);
        }

        $mapped = $this->mapProgramRow($program);
        $memberCount = 0; $isMember = false; $mySubscriptionId = null;
        return sendSuccess('Program fetched', [
            'program'          => $mapped,
            'image'            => $this->firstPhoto($program),
            'photos'           => $this->allPhotos($program),
            'location'         => $location,
            'openingHours'     => $openingHours,
            'memberCount'      => $memberCount,
            'isMember'         => $isMember,
            'mySubscriptionId' => $mySubscriptionId,
        ]);
    }

    public function hours(string $uuid)
    {
        $program = $this->findPublicProgram($uuid);
        if (! $program?->venue_id || !$this->isVisible($program, request()->query('brand_owner') ?: null)) {
            return sendSuccess('Hours', ['openingHours' => null]);
        }

        return sendSuccess('Hours', [
            'openingHours' => $this->venueHours((int) $program->venue_id),
        ]);
    }

    public function checkIntroOffer(Request $request)
    {
        $data = $request->validate([
            'owner_user_id' => 'nullable|string|max:64',
            'email'         => 'nullable|string|max:255',
            'card_fp'       => 'nullable|string|max:128',
        ]);

        $used = $this->hasUsedIntroOffer(
            $data['email'] ?? null,
            $data['card_fp'] ?? null,
            $data['owner_user_id'] ?? null
        );

        return sendSuccess('Offer check', ['used' => $used]);
    }

    public function enroll(User $user, string $uuid, array $payload)
    {
        return \App\Support\MembershipListingPolicy::retired();
        if (! $user->uuid) {
            return sendError('Please sign in to continue.');
        }

        $program = $this->findPublicProgram($uuid);
        if (! $program || ! $this->isVisible($program, null)) {
            return sendError('Program not found.');
        }

        $capacity = (int) ($program->capacity ?: 0);
        if ($capacity > 0) {
            $active = ClassSubscription::where('program_id', $program->id)
                ->where('status', 'active')
                ->count();
            if ($active >= $capacity) {
                return sendError('This program is full.');
            }
        }

        $email = strtolower(trim((string) ($user->email ?: '')));
        if ($this->hasLiveMembership($program->id, $user->uuid, $email)) {
            return sendError('You’re already a member of this program — manage it in My Memberships.');
        }

        $ownerKey = $program->owner_user_id ?: ($program->mapped_owner_user_id ? (string) $program->mapped_owner_user_id : null);
        $cardFp = (string) ($payload['card_fp'] ?? '');
        $rawMode = $this->offerModeFor($program);
        $introUsed = $rawMode === 'trial'
            ? $this->hasUsedIntroOffer($email, $cardFp, $ownerKey)
            : false;
        $mode = $introUsed ? 'none' : $rawMode;

        $interval = $program->billing_interval ?: 'monthly';
        $isFixed = $program->program_type === 'fixed_duration';
        $oneTime = $interval === 'one_time';
        $trialDays = $mode === 'trial' ? (int) ($program->free_trial_days ?: 0) : 0;
        $trialSessions = $mode === 'trial' ? (int) ($program->free_trial_sessions ?: 0) : 0;
        $discountMonths = $mode === 'discount' ? (int) ($program->discount_months ?: 0) : 0;
        $listPrice = (float) ($program->monthly_price ?: 0);
        $amount = $mode === 'discount'
            ? $this->discountedPrice($listPrice, $interval, $discountMonths)
            : $listPrice;
        $hasTrial = $mode === 'trial' && ($trialDays > 0 || $trialSessions > 0);
        $commission = (float) ($program->commission_percentage ?? 15);
        $platformFee = round($amount * $commission / 100, 2);
        $providerPayout = round($amount - $platformFee, 2);
        $joiningFee = max(0, (float) ($program->joining_fee ?: 0));
        $startedAt = now()->toDateString();

        if ($hasTrial) {
            $trialStatus = 'in_trial';
            if ($trialSessions > 0) {
                $nextBilling = $this->nthSessionDate($startedAt, $program->day_of_week_mask ?? [], 2)
                    ?: $this->addIntervalToDate($startedAt, 'monthly');
            } else {
                $nextBilling = \Carbon\Carbon::parse($startedAt)->addDays($trialDays)->toDateString();
            }
            $trialEndsAt = $nextBilling;
        } else {
            $trialEndsAt = $startedAt;
            $trialStatus = 'active';
            if ($oneTime) {
                $nextBilling = null;
            } elseif ($isFixed) {
                $nb = $this->addIntervalToDate($startedAt, 'monthly');
                $end = optional($program->end_date)?->toDateString();
                $nextBilling = ($end && $nb >= $end) ? null : $nb;
            } else {
                $nextBilling = $this->addIntervalToDate($startedAt, $interval);
            }
        }

        $countryId = $this->resolveCountryId($program);
        $wallet = null;
        if ($ownerKey) {
            $wallet = $this->ensureSubscriptionWallet($ownerKey, $countryId, $program->currency);
        }

        try {
            $result = DB::transaction(function () use (
                $program, $user, $email, $startedAt, $nextBilling, $trialEndsAt, $trialStatus,
                $amount, $providerPayout, $platformFee, $interval, $countryId, $wallet,
                $joiningFee, $hasTrial, $ownerKey, $cardFp, $payload, $commission, $introUsed
            ) {
                $sub = ClassSubscription::create([
                    'program_id'       => $program->id,
                    'customer_id'      => $user->uuid,
                    'customer_name'    => $user->name ?: $email,
                    'customer_email'   => $email,
                    'started_at'       => $startedAt,
                    'next_billing_at'  => $nextBilling,
                    'trial_ends_at'    => $trialEndsAt,
                    'trial_status'     => $trialStatus,
                    'monthly_amount'   => $amount,
                    'provider_payout'  => $providerPayout,
                    'platform_fee'     => $platformFee,
                    'status'           => 'active',
                    'billing_interval' => $interval,
                    'country_id'       => $countryId,
                    'wallet_id'        => $wallet?->id,
                    'form_answers'     => $payload['answers'] ?? [],
                    'joining_fee'      => $joiningFee,
                    'first_charge_at'  => $trialStatus === 'active' ? now() : null,
                    'card_on_file_id'  => $cardFp ?: null,
                ]);

                if ($hasTrial && $ownerKey) {
                    OfferRedemption::create([
                        'customer_email'   => $email ?: null,
                        'card_fingerprint' => $cardFp ?: null,
                        'owner_user_id'    => $ownerKey,
                        'program_id'       => $program->uuid,
                        'subscription_id'  => $sub->uuid,
                        'offer_type'       => 'trial',
                        'source'           => 'customer_web',
                    ]);
                }

                $discount = max(0, (float) ($payload['discount'] ?? 0));
                $loyaltyRedeem = max(0, (float) ($payload['loyalty_redeem'] ?? 0));
                $firstChargeDiscount = $trialStatus === 'active'
                    ? min($discount, $amount)
                    : 0;
                $loyaltyApplied = 0;

                if ($trialStatus === 'active') {
                    $chargeAmount = $amount;
                    $chargeFee = $platformFee;
                    if ($joiningFee > 0) {
                        $chargeAmount = round($amount + $joiningFee, 2);
                        $chargeFee = round($chargeAmount * $commission / 100, 2);
                    }
                    $loyaltyApplied = $this->recordFirstCharge(
                        $sub,
                        $program,
                        $chargeAmount,
                        $chargeFee,
                        $firstChargeDiscount,
                        $loyaltyRedeem,
                        $wallet,
                        $user
                    );
                }

                $joiningNow = $trialStatus === 'active' ? $joiningFee : 0;

                return [
                    'subscription'     => $this->mapSubscriptionPublic($sub->fresh(), $program),
                    'charged'          => $trialStatus === 'active',
                    'amount'           => $amount,
                    'joiningFee'       => $joiningFee,
                    'charged_amount'   => round($amount - $firstChargeDiscount + $joiningNow - $loyaltyApplied, 2),
                    'discountApplied'  => $firstChargeDiscount,
                    'loyaltyApplied'   => $loyaltyApplied,
                    'introUsed'        => $introUsed,
                    'countryId'        => $countryId,
                    'currency'         => $program->currency ?: 'AED',
                ];
            });
        } catch (\Throwable $e) {
            return sendError($e->getMessage() ?: 'Could not complete sign-up.');
        }

        return sendSuccess('Enrolled', $result);
    }

    public function mine(User $user, Request $request)
    {
        $brandOwner = $request->query('brand_owner') ?: null;
        $email = strtolower(trim((string) ($user->email ?: '')));

        $subs = ClassSubscription::query()
            ->with('program.venue')
            // 'incomplete' is a checkout that was never paid for — not a membership.
            ->where('status', '!=', 'incomplete')
            ->where(function ($q) use ($user, $email) {
                $q->where('customer_id', $user->uuid);
                if ($email) {
                    $q->orWhereRaw('LOWER(customer_email) = ?', [$email]);
                }
            })
            ->orderByDesc('started_at')
            ->get()
            ->unique('id')
            ->values();

        $out = [];
        foreach ($subs as $s) {
            $p = $s->program;
            if ($brandOwner && $p) {
                $owner = $p->owner_user_id ?: (string) $p->mapped_owner_user_id;
                if ($owner && $owner !== $brandOwner && (string) $p->mapped_owner_user_id !== $brandOwner) {
                    continue;
                }
            }
            $out[] = [
                'id'          => $s->uuid,
                'programId'   => $p?->uuid,
                'name'        => $p?->name ?: 'Membership',
                'sport'       => $p?->sport_category ?: '',
                'category'    => $p?->category ?: '',
                'image'       => $p ? $this->firstPhoto($p) : '',
                'color'       => $p?->color ?: '#7c3aed',
                'amount'      => (float) ($s->monthly_amount ?: 0),
                'currency'    => $p?->currency ?: 'AED',
                'interval'    => $this->intervalLabel($s->billing_interval ?: $p?->billing_interval),
                'status'      => $s->status ?: 'active',
                'trialStatus' => $s->trial_status ?: '',
                'startedAt'   => optional($s->started_at)?->toDateString(),
                'nextBilling' => optional($s->next_billing_at)?->toDateString(),
                'trialEndsAt' => optional($s->trial_ends_at)?->toDateString(),
                // Cancelled but still inside the period already paid for. Without
                // this the page could not tell "cancelled" from "nothing happened"
                // and kept offering Cancel on a membership already winding down.
                'cancelPending' => $s->status !== 'cancelled' && $s->cancelled_at !== null,
                'endsAt'        => optional($s->current_period_end ?: $s->next_billing_at)?->toDateString(),
            ];
        }

        return sendSuccess('My memberships', ['subscriptions' => $out]);
    }

    public function cancel(User $user, string $subUuid)
    {
        $email = strtolower(trim((string) ($user->email ?: '')));
        $sub = ClassSubscription::where('uuid', $subUuid)->first();
        if (! $sub) {
            return sendError('Subscription not found.', [], 404);
        }
        $owns = $sub->customer_id === $user->uuid
            || ($email && strtolower((string) $sub->customer_email) === $email);
        if (! $owns) {
            return sendError('Not allowed.', [], 403);
        }

        // Stripe-backed memberships stop at the end of the paid period, so access
        // continues until then; the webhook flips the status when Stripe ends it.
        if ($sub->stripe_subscription_id) {
            $res = app(MembershipBillingService::class)->cancel($sub);

            return isset($res['error']) ? sendError($res['error']) : sendSuccess('Cancelled', ['ok' => true]);
        }

        $sub->update([
            'status'          => 'cancelled',
            'next_billing_at' => null,
            'cancelled_at'    => now(),
        ]);

        return sendSuccess('Cancelled', ['ok' => true]);
    }

    /**
     * The member changes their mind: keep a membership that was set to stop at
     * the end of the current period.
     *
     * Only the SCHEDULED state can be undone — `cancelled_at` set while the
     * status is still live, which is the same condition `cancel_pending`
     * reports to the apps. Two cases deliberately cannot be resumed:
     *
     *   • status 'cancelled' — already over. A cancelled Stripe subscription is
     *     terminal, so there is nothing to re-open; they have to join again.
     *   • a cancelled trial — cancel() ends those immediately for exactly that
     *     reason, so they land in the case above.
     *
     * Saying "already ended, please join again" is the honest answer in both,
     * and far better than appearing to succeed and leaving them with no
     * membership and no charge.
     */
    public function resume(User $user, string $subUuid)
    {
        $email = strtolower(trim((string) ($user->email ?: '')));
        $sub = ClassSubscription::where('uuid', $subUuid)->first();
        if (! $sub) {
            return sendError('Subscription not found.', [], 404);
        }

        $owns = $sub->customer_id === $user->uuid
            || ($email && strtolower((string) $sub->customer_email) === $email);
        if (! $owns) {
            return sendError('Not allowed.', [], 403);
        }

        if ($sub->status === 'cancelled') {
            return sendError('This membership has already ended. Please join again.');
        }
        if (! $sub->cancelled_at) {
            return sendError('This membership is not scheduled to cancel.');
        }

        if ($sub->stripe_subscription_id) {
            $res = app(MembershipBillingService::class)->resume($sub);

            return isset($res['error'])
                ? sendError($res['error'])
                : sendSuccess('Membership resumed', ['ok' => true]);
        }

        // Free / non-Stripe membership: the schedule lives only in our columns.
        $sub->update(['cancelled_at' => null, 'cancel_reason' => null]);

        return sendSuccess('Membership resumed', ['ok' => true]);
    }

    public function passes(User $user)
    {
        $email = strtolower(trim((string) ($user->email ?: '')));
        $memberships = $this->liveMembershipRows($user->uuid, $email);
        if (! $memberships) {
            return sendSuccess('Passes', ['pass' => null, 'memberships' => [], 'legacyPasses' => []]);
        }

        $pass = $this->ensurePass('member', $user->uuid, null, $user->uuid, $email);
        $legacy = [];
        foreach ($memberships as $row) {
            $sub = $row['sub'];
            $prog = $row['prog'];
            $owner = $prog?->owner_user_id ?: ($prog?->mapped_owner_user_id ? (string) $prog->mapped_owner_user_id : null);
            $lp = $this->ensurePass('membership', $sub->uuid, $owner, $user->uuid, $email);
            if (! $lp) {
                continue;
            }
            $joiningFee = max(0, (float) ($sub->joining_fee ?? $prog?->joining_fee ?? 0));
            $legacy[] = [
                'pass'      => $this->mapPass($lp),
                'kind'      => 'membership',
                'title'     => $prog?->name ?: 'Membership',
                'venue'     => '',
                'date'      => '',
                'start'     => '',
                'end'       => '',
                'status'    => $sub->trial_status === 'in_trial' ? 'trial' : $sub->status,
                'joiningFee'=> $joiningFee,
                'feePaid'   => $joiningFee > 0 && ($sub->first_charge_at || $sub->trial_status !== 'in_trial'),
            ];
        }

        return sendSuccess('Passes', [
            'pass'         => $pass ? $this->mapPass($pass) : null,
            'memberships'  => array_map(function ($row) {
                $sub = $row['sub'];
                $prog = $row['prog'];
                $joiningFee = max(0, (float) ($sub->joining_fee ?? $prog?->joining_fee ?? 0));

                return [
                    'sub'         => $this->mapSubscriptionPublic($sub, $prog),
                    'prog'        => $prog ? $this->mapProgramRow($prog) : null,
                    'title'       => $prog?->name ?: 'Membership',
                    'status'      => $sub->trial_status === 'in_trial' ? 'trial' : $sub->status,
                    'nextBilling' => optional($sub->next_billing_at)?->toDateString() ?: '',
                    'joiningFee'  => $joiningFee,
                    'feePaid'     => $joiningFee > 0 && ($sub->first_charge_at || $sub->trial_status !== 'in_trial'),
                ];
            }, $memberships),
            'legacyPasses' => $legacy,
        ]);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    public function isVisible(ClassProgram $p, ?string $brandOwner): bool
    {
        return \App\Support\MembershipListingPolicy::visible($p, $brandOwner);
    }

    protected function activeMemberCounts(array $programIds): array
    {
        if (! $programIds) {
            return [];
        }

        return ClassSubscription::whereIn('program_id', $programIds)
            ->where('status', 'active')
            ->selectRaw('program_id, COUNT(*) as cnt')
            ->groupBy('program_id')
            ->pluck('cnt', 'program_id')
            ->all();
    }

    protected function mapCard(ClassProgram $p, int $memberCount): array
    {
        $offerBadge = ''; $memberCount = 0; $price = (float) $p->monthly_price;
        $venue = $p->venue;

        return [
            'id'           => $p->uuid,
            'contact_phone' => \App\Support\MembershipListingPolicy::phone($p),
            'name'         => $p->name,
            'sport'        => $p->sport_category,
            'category'     => $p->category ?: 'academy',
            'audience'     => $p->audience ?: '',
            'gender'       => $this->effectiveGenderPolicy($p),
            'color'        => $p->color ?: '#7c3aed',
            'image'        => $this->firstPhoto($p),
            'locationName' => $venue?->name ?: '',
            'lat'          => $venue?->latitude !== null ? (float) $venue->latitude : null,
            'lng'          => $venue?->longitude !== null ? (float) $venue->longitude : null,
            'price'        => $price,
            'currency'     => $p->currency ?: 'AED',
            'interval'     => $this->intervalLabel($p->billing_interval),
            'offerBadge'   => $offerBadge,
            'memberCount'  => $memberCount,
        ];
    }

    protected function mapProgramRow(ClassProgram $p): array
    {
        return [
            'id'                       => $p->uuid,
            'contact_phone' => \App\Support\MembershipListingPolicy::phone($p),
            'schedule_description' => $p->schedule_description,
            'listing_details' => $p->listing_details ?? [],
            'membership_model' => 'contact_listing',
            'name'                     => $p->name,
            'description'              => $p->description,
            'sport_category'           => $p->sport_category,
            'coach_name'               => $p->coach_name,
            'monthly_price'            => (float) $p->monthly_price,
            'currency'                 => $p->currency ?: 'AED',
            'sessions_per_week'        => $p->sessions_per_week,
            'session_duration_minutes' => $p->session_duration_minutes,
            'day_of_week_mask'         => $p->day_of_week_mask ?? [],
            'session_time'             => $p->session_time,
            'capacity'                 => $p->capacity,
            'commission_percentage'    => (float) $p->commission_percentage,
            'status'                   => $p->status,
            'photo_url'                => $p->photo_url,
            'images'                   => $p->images ?? [],
            'location_id'              => $p->venue_id ? (string) $p->venue_id : $p->location_id,
            'venue_id'                 => $p->venue_id,
            'owner_user_id'            => $p->owner_user_id ?: ($p->mapped_owner_user_id ? (string) $p->mapped_owner_user_id : null),
            'billing_interval'         => $p->billing_interval,
            'program_type'             => $p->program_type,
            'duration_weeks'           => $p->duration_weeks,
            'start_date'               => optional($p->start_date)?->toDateString(),
            'end_date'                 => optional($p->end_date)?->toDateString(),
            'bonus_months'             => $p->bonus_months,
            'free_trial_days'          => $p->free_trial_days,
            'free_trial_enabled'       => (bool) $p->free_trial_enabled,
            'free_trial_sessions'      => $p->free_trial_sessions,
            'discount_months'          => $p->discount_months,
            'joining_fee'              => (float) $p->joining_fee,
            'category'                 => $p->category ?: 'academy',
            'audience'                 => $p->audience,
            'gender_policy'            => $p->gender_policy,
            'signup_form'              => $p->signup_form ?? [],
            'color'                    => $p->color,
            'show_on_marketplace'      => (bool) $p->show_on_marketplace,
            'show_on_provider_site'    => (bool) $p->show_on_provider_site,
            'approval_status'          => $p->approval_status,
            'eligibility_status'       => $p->eligibility_status,
        ];
    }

    public function mapSubscriptionPublic(ClassSubscription $s, ?ClassProgram $p = null): array
    {
        $p = $p ?: $s->program;

        return [
            'id'               => $s->uuid,
            'program_id'       => $p?->uuid,
            'customer_id'      => $s->customer_id,
            'customer_name'    => $s->customer_name,
            'customer_email'   => $s->customer_email,
            'started_at'       => optional($s->started_at)?->toDateString(),
            'next_billing_at'  => optional($s->next_billing_at)?->toDateString(),
            'trial_ends_at'    => optional($s->trial_ends_at)?->toDateString(),
            'status'           => $s->status,
            'trial_status'     => $s->trial_status,
            // A cancellation that has been scheduled but not yet taken effect:
            // the member keeps the period they paid for, and the page needs to
            // say so instead of offering Cancel again as if nothing happened.
            'cancelled_at'     => optional($s->cancelled_at)?->toIso8601String(),
            'cancel_pending'   => $s->status !== 'cancelled' && $s->cancelled_at !== null,
            'ends_at'          => optional($s->current_period_end ?: $s->next_billing_at)?->toDateString(),
            'billing_interval' => $s->billing_interval,
            'monthly_amount'   => (float) $s->monthly_amount,
            'provider_payout'  => (float) $s->provider_payout,
            'platform_fee'     => (float) $s->platform_fee,
            'joining_fee'      => (float) $s->joining_fee,
            'first_charge_at'  => optional($s->first_charge_at)?->toIso8601String(),
            'wallet_id'        => $s->wallet_id,
            'form_answers'     => $s->form_answers ?? [],
            'currency'         => $p?->currency ?: 'AED',
        ];
    }

    public function mapPass(EntryPass $p): array
    {
        return [
            'id'             => $p->uuid,
            'kind'           => $p->kind,
            'ref_id'         => $p->ref_id,
            'owner_user_id'  => $p->owner_user_id,
            'customer_id'    => $p->customer_id,
            'customer_email' => $p->customer_email,
            'code'           => $p->code,
            'created_at'     => optional($p->created_at)?->toIso8601String(),
        ];
    }

    protected function firstPhoto(ClassProgram $p): string
    {
        if ($p->photo_url) {
            return (string) $p->photo_url;
        }
        $imgs = $p->images ?? [];
        if (is_array($imgs) && count($imgs)) {
            $first = $imgs[0];

            return is_string($first) ? $first : (string) ($first['url'] ?? '');
        }

        return '';
    }

    protected function allPhotos(ClassProgram $p): array
    {
        $out = [];
        if ($p->photo_url) {
            $out[] = (string) $p->photo_url;
        }
        foreach ($p->images ?? [] as $img) {
            $url = is_string($img) ? $img : (string) ($img['url'] ?? '');
            if ($url && ! in_array($url, $out, true)) {
                $out[] = $url;
            }
        }

        return $out;
    }

    protected function effectiveGenderPolicy(ClassProgram $p): string
    {
        $g = strtolower((string) ($p->gender_policy ?: ''));
        if (in_array($g, ['mixed', 'men', 'women'], true)) {
            return $g;
        }
        $a = strtolower((string) ($p->audience ?: ''));
        if ($a === 'ladies') {
            return 'women';
        }
        if ($a === 'men') {
            return 'men';
        }

        return 'mixed';
    }

    protected function intervalLabel(?string $v): string
    {
        return self::INTERVAL_LABEL[$v ?? ''] ?? 'month';
    }

    /**
     * What a reward may be funded from on this membership's first charge.
     *
     * Mirrors the first-charge arithmetic in subscribe() deliberately field for
     * field — the amount, any intro discount, the joining fee and the rate the
     * programme actually charges — so the reward quote and the charge cannot
     * disagree.
     *
     * A programme still in its free trial collects nothing today. That returns
     * a due-now of zero rather than a rate applied to an amount nobody paid:
     * the funding rule then declines, which is the honest answer.
     *
     * @return array{currency:string, order_total:float, due_now:float, share:array{commission:float,event_fee:float,booking_fee:float}}|null
     */
    public function rewardBasis(ClassProgram $program, ?string $promocode = null, ?int $userId = null): ?array
    {
        $interval = $program->billing_interval ?: 'monthly';
        $mode = $this->offerModeFor($program);

        $listPrice = (float) ($program->monthly_price ?: 0);
        $amount = $mode === 'discount'
            ? $this->discountedPrice($listPrice, $interval, (int) ($program->discount_months ?: 0))
            : $listPrice;

        $hasTrial = $mode === 'trial'
            && ((int) ($program->free_trial_days ?: 0) > 0 || (int) ($program->free_trial_sessions ?: 0) > 0);

        $joiningFee = max(0, (float) ($program->joining_fee ?: 0));
        $fullCharge = round($amount + $joiningFee, 2);

        // A promocode is re-validated here from the CODE alone. The browser
        // also sends the discount it calculated, and that number is ignored:
        // understating it would inflate the fee and hand out a bigger reward
        // than Bookboll earned.
        $discount = $this->promoDiscountFor($promocode, $fullCharge, $userId);
        $chargeAmount = round(max(0, $fullCharge - $discount), 2);

        // The rate this programme charges, with the same fallback the charge
        // uses. Inventing a rate here would discount revenue nobody collected.
        $commissionPct = (float) ($program->commission_percentage ?? 15);

        return [
            'currency'    => strtoupper((string) ($program->currency ?: 'AED')),
            'order_total' => $chargeAmount,
            // Nothing is collected while a trial is running.
            'due_now'     => $hasTrial ? 0.0 : $chargeAmount,
            'share' => [
                'commission'  => $hasTrial ? 0.0 : round($chargeAmount * $commissionPct / 100, 2),
                'event_fee'   => 0.0,
                'booking_fee' => 0.0,
            ],
        ];
    }

    /**
     * A promocode's discount, derived from the code rather than taken on trust.
     *
     * An unusable code discounts nothing, which is also what the charge would
     * do with it — so the quote and the charge still agree.
     */
    protected function promoDiscountFor(?string $code, float $orderAmount, ?int $userId): float
    {
        $code = trim((string) $code);
        if ($code === '' || $orderAmount <= 0) {
            return 0.0;
        }

        try {
            $promocode = app(PromocodeService::class)
                ->validateForUse($code, $orderAmount, $userId, 'online');

            return round(min($orderAmount, max(0, (float) $promocode->applyDiscount($orderAmount))), 2);
        } catch (\Throwable $e) {
            return 0.0;
        }
    }

    protected function offerModeFor(ClassProgram $p): string
    {
        $ongoing = $p->program_type !== 'fixed_duration';
        $months = self::INTERVAL_MONTHS[$p->billing_interval ?? 'monthly'] ?? 1;

        return ($ongoing && $months > 1) ? 'discount' : 'trial';
    }

    protected function discountedPrice(float $price, ?string $interval, int $discountMonths): float
    {
        $m = self::INTERVAL_MONTHS[$interval ?? 'yearly'] ?? 12;
        $d = min($discountMonths, $m);

        return max(0, round($price * (1 - $d / $m), 2));
    }

    protected function addIntervalToDate(string $dateStr, string $interval): string
    {
        $d = \Carbon\Carbon::parse($dateStr);
        match ($interval) {
            'weekly' => $d->addDays(7),
            'biweekly' => $d->addDays(14),
            'quarterly' => $d->addMonths(3),
            'yearly' => $d->addYear(),
            default => $d->addMonth(),
        };

        return $d->toDateString();
    }

    protected function nthSessionDate(string $startISO, array $dayMask, int $n): ?string
    {
        $set = array_map('intval', $dayMask);
        if (! $set || $n < 1) {
            return null;
        }
        $cur = \Carbon\Carbon::parse($startISO);
        $count = 0;
        for ($i = 0; $i < 400; $i++) {
            if (in_array((int) $cur->dayOfWeek, $set, true)) {
                $count++;
                if ($count === $n) {
                    return $cur->toDateString();
                }
            }
            $cur->addDay();
        }

        return null;
    }

    protected function venueHours(int $venueId): ?array
    {
        // Venue hours are `schedules` rows with no listing_id — one table, the
        // same rows the booking engine reads.
        $rows = Schedule::where('venue_id', $venueId)->whereNull('listing_id')->get();
        if ($rows->isEmpty()) {
            return null;
        }
        $DOW = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];

        // Collect raw periods per day, then merge: stored rows can be one per
        // half hour, which would read as "12:00am–12:30am, 12:30am–1:00am, …".
        $periodsByDay = [];
        foreach ($rows as $r) {
            $d = $r->day_of_week;
            if ($d === null) {
                continue;
            }
            $periodsByDay[$d] ??= [];
            if ($r->is_open && $r->open_time && $r->close_time) {
                $periodsByDay[$d][] = ['open_time' => $r->open_time, 'close_time' => $r->close_time];
            }
        }

        $byDay = [];
        foreach ($periodsByDay as $d => $periods) {
            $byDay[$d] = array_map(
                fn ($p) => $this->fmt12Range($p['open_time'], $p['close_time']),
                HourPeriods::merge($periods)
            );
        }

        return collect([1, 2, 3, 4, 5, 6, 0])->map(fn ($d) => [
            'day'   => $DOW[$d],
            'open'  => ! empty($byDay[$d]),
            'label' => ! empty($byDay[$d]) ? implode(', ', $byDay[$d]) : 'Closed',
        ])->values()->all();
    }

    protected function fmt12Range(?string $open, ?string $close): string
    {
        return $this->fmt12($open).'–'.$this->fmt12($close);
    }

    protected function fmt12(?string $t): string
    {
        if (! $t) {
            return '';
        }
        [$h, $m] = array_pad(explode(':', substr($t, 0, 5)), 2, '0');
        $h = (int) $h;
        $ampm = $h >= 12 ? 'PM' : 'AM';
        $h12 = $h % 12 ?: 12;

        return $h12.':'.str_pad($m, 2, '0', STR_PAD_LEFT).' '.$ampm;
    }

    protected function resolveCountryId(ClassProgram $program): ?string
    {
        $venue = $program->venue;
        if ($venue?->country_id) {
            return (string) $venue->country_id;
        }
        if ($venue?->city_id) {
            $city = City::query()->find($venue->city_id);

            return $city?->country_id ? (string) $city->country_id : null;
        }

        return null;
    }

    /**
     * Every key a provider's wallet might be filed under.
     *
     * `provider_wallets.owner_user_id` is not consistent: booking wallets store
     * the numeric users.id (venues.owner_user_id is a real FK), while membership
     * wallets stored the users.uuid, because class_programs.owner_user_id holds a
     * uuid. Legacy Supabase rows are uuid too. Looking up under one shape only is
     * what left a provider with an invisible wallet — and would mint a second one.
     *
     * @return string[] both forms, so a lookup finds the wallet whichever was used
     */
    /** The provider behind a wallet owner key, whichever form it is in. */
    protected function ownerUserFor(string $ownerUserId): ?User
    {
        return ctype_digit($ownerUserId)
            ? User::find((int) $ownerUserId)
            : User::where('uuid', $ownerUserId)->first();
    }

    protected function ownerWalletKeys(string $ownerUserId): array
    {
        $user = $this->ownerUserFor($ownerUserId);

        if (! $user) {
            return [$ownerUserId];
        }

        return array_values(array_unique(array_filter([
            (string) $user->id,
            (string) $user->uuid,
            $ownerUserId,
        ])));
    }

    /**
     * Keep a wallet's label honest about the money inside it.
     *
     * A subscription stays bound to its wallet, so a wallet created with the wrong
     * currency would keep that label for ever while PKR kept landing in it. If no
     * transaction in the wallet was ever booked in another currency, the label is
     * simply wrong and is corrected. A wallet that really does hold two currencies
     * is left alone — relabelling that would misstate money.
     */
    protected function reconcileWalletCurrency(?ProviderWallet $wallet, string $currency): ?ProviderWallet
    {
        if (! $wallet || $currency === '') {
            return $wallet;
        }
        if (strtoupper(trim((string) $wallet->currency)) === $currency) {
            return $wallet;
        }

        $mixed = WalletTransaction::query()
            ->where('wallet_id', $wallet->id)
            ->whereNotNull('currency')
            ->where('currency', '!=', $currency)
            ->exists();

        if ($mixed) {
            Log::warning('Wallet holds more than one currency; leaving its label alone', [
                'wallet' => $wallet->id, 'label' => $wallet->currency, 'charged' => $currency,
            ]);

            return $wallet;
        }

        Log::info('Corrected a wallet currency to match the money in it', [
            'wallet' => $wallet->id, 'from' => $wallet->currency, 'to' => $currency,
        ]);
        $wallet->update(['currency' => $currency]);

        return $wallet;
    }

    /**
     * The wallet a membership's earnings belong in.
     *
     * A wallet's currency must be the currency the money actually arrived in —
     * the program's, which is what Stripe charged. There is deliberately NO
     * fallback: inventing a default is how a Pakistani provider paid in PKR ended
     * up with a wallet labelled AED, its balance reading as a currency nobody had
     * charged. With no currency to bank in, no wallet is opened and the caller
     * logs it, rather than quietly filing the money under a guess.
     */
    protected function ensureSubscriptionWallet(string $ownerUserId, ?string $countryId, ?string $currency): ?ProviderWallet
    {
        $currency = strtoupper(trim((string) $currency));
        if ($currency === '') {
            Log::error('Subscription wallet skipped: the program has no currency', [
                'owner' => $ownerUserId,
            ]);

            return null;
        }

        $keys = $this->ownerWalletKeys($ownerUserId);

        $scoped = fn () => ProviderWallet::query()
            ->whereIn('owner_user_id', $keys)
            ->where('purpose', 'subscription')
            ->where('is_current', true)
            ->when($countryId, fn ($q) => $q->where('country_id', $countryId));

        // 1. The right wallet already exists.
        $wallet = $scoped()->where('currency', $currency)->first();
        if ($wallet) {
            return $wallet;
        }

        // 2. A wallet for this country exists but carries a different currency.
        //    If nothing in it was ever booked in another currency, the label is
        //    simply wrong — correct it rather than opening a rival wallet and
        //    splitting the provider's balance in two.
        $other = $scoped()->first();
        if ($other) {
            $mixed = WalletTransaction::query()
                ->where('wallet_id', $other->id)
                ->whereNotNull('currency')
                ->where('currency', '!=', $currency)
                ->exists();

            if (! $mixed) {
                $other->update(['currency' => $currency]);
                Log::info('Corrected a wallet currency to match the money in it', [
                    'wallet' => $other->id, 'from' => $other->getOriginal('currency'), 'to' => $currency,
                ]);

                return $other;
            }
            // Genuinely holds another currency's money: this one needs its own wallet.
        }

        // New wallets are filed under the numeric id — the same key bookings use
        // and the only one the admin UI knows the provider by.
        $ownerUserId = $keys[0];

        return ProviderWallet::create([
            'owner_user_id'      => $ownerUserId,
            'country_id'         => $countryId,
            'purpose'            => 'subscription',
            'is_current'         => true,
            'status'             => 'current',
            'currency'           => $currency,
            'available_balance'  => 0,
            'pending_balance'    => 0,
            'lifetime_earnings'  => 0,
            'lifetime_withdrawn' => 0,
            'hold_days'          => 7,
        ]);
    }

    public function hasUsedIntroOffer(?string $email, ?string $cardFp, ?string $ownerUserId): bool
    {
        if (! $ownerUserId) {
            return false;
        }
        $em = strtolower(trim((string) $email));
        if (! $em && ! $cardFp) {
            return false;
        }
        $q = OfferRedemption::where('owner_user_id', $ownerUserId)
            ->where(function ($w) use ($em, $cardFp) {
                if ($em) {
                    $w->orWhere('customer_email', $em);
                }
                if ($cardFp) {
                    $w->orWhere('card_fingerprint', $cardFp);
                }
            });

        return $q->exists();
    }

    public function hasLiveMembership(int $programId, string $customerId, string $email): bool
    {
        $statuses = ['active', 'trial', 'trialing', 'paused', 'past_due'];
        $rows = ClassSubscription::where('program_id', $programId)
            ->whereIn('status', $statuses)
            ->where(function ($q) use ($customerId, $email) {
                $q->where('customer_id', $customerId);
                if ($email) {
                    $q->orWhereRaw('LOWER(customer_email) = ?', [$email]);
                }
            })
            ->get(['trial_status', 'trial_ends_at']);

        $today = now()->toDateString();
        foreach ($rows as $s) {
            if ($s->trial_status === 'in_trial' && $s->trial_ends_at && $s->trial_ends_at->toDateString() < $today) {
                continue;
            }

            return true;
        }

        return false;
    }

    public function liveMembershipRows(string $customerId, string $email): array
    {
        $statuses = ['active', 'trial', 'trialing', 'paused', 'past_due'];
        $rows = ClassSubscription::with('program')
            ->whereIn('status', $statuses)
            ->where(function ($q) use ($customerId, $email) {
                $q->where('customer_id', $customerId);
                if ($email) {
                    $q->orWhereRaw('LOWER(customer_email) = ?', [$email]);
                }
            })
            ->orderByDesc('created_at')
            ->limit(40)
            ->get()
            ->unique('id')
            ->values();

        $today = now()->toDateString();
        $out = [];
        foreach ($rows as $s) {
            if ($s->trial_status === 'in_trial' && $s->trial_ends_at && $s->trial_ends_at->toDateString() < $today) {
                continue;
            }
            $out[] = ['sub' => $s, 'prog' => $s->program];
        }

        return $out;
    }

    public function ensurePass(string $kind, string $refId, ?string $ownerUserId, ?string $customerId, ?string $email): ?EntryPass
    {
        $existing = EntryPass::where('kind', $kind)->where('ref_id', $refId)->first();
        if ($existing) {
            return $existing;
        }
        for ($i = 0; $i < 4; $i++) {
            try {
                return EntryPass::create([
                    'kind'           => $kind,
                    'ref_id'         => $refId,
                    'owner_user_id'  => $ownerUserId,
                    'customer_id'    => $customerId,
                    'customer_email' => $email,
                    'code'           => $this->genPassCode(),
                ]);
            } catch (\Throwable $e) {
                $again = EntryPass::where('kind', $kind)->where('ref_id', $refId)->first();
                if ($again) {
                    return $again;
                }
            }
        }

        return null;
    }

    protected function genPassCode(int $len = 6): string
    {
        $alphabet = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
        $out = '';
        for ($i = 0; $i < $len; $i++) {
            $out .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        return $out;
    }

    /**
     * @return float loyaltyApplied
     */
    /**
     * Spend loyalty points for a currency amount off the first charge.
     * Converts the amount to points with the country's program rate, caps it at the
     * customer's balance, writes the debit, and returns the amount actually funded.
     */
    protected function spendLoyalty(?User $user, ClassSubscription $sub, float $amount): float
    {
        if (! $user || $amount <= 0) {
            return 0;
        }

        $cfg = LoyaltyProgram::where('country_id', $sub->country_id)->first();
        $rate = (int) ($cfg->redeem_rate ?? LoyaltyProgram::defaults()['redeem_rate']);
        $unit = (float) ($cfg->redeem_value ?? LoyaltyProgram::defaults()['redeem_value']) ?: 1;

        $points = (int) ceil($amount / $unit * $rate);
        $balance = (int) (LoyaltyPoint::balance($user->id) ?? 0);
        $points = min($points, $balance);
        if ($points <= 0) {
            return 0;
        }

        LoyaltyPoint::create([
            'user_id' => $user->id,
            'type'    => 'debit',
            'points'  => $points,
            'reason'  => 'Redeemed on membership sign-up',
        ]);
        $user->updateLoyaltyTier();

        return round(min($amount, $points / $rate * $unit), 2);
    }

    /**
     * Record a paid membership invoice: the two payment rows and the provider's
     * wallet credit. The provider's share is always computed on the full price, so
     * a discount or loyalty redemption only ever comes out of Bookboll's fee.
     */
    public function recordSubscriptionCharge(
        ClassSubscription $sub,
        float $amountPaid,
        float $loyalty,
        string $reference = '',
        ?string $chargedCurrency = null,
        ?string $invoiceUrl = null
    ): void {
        $program = $sub->program;
        if (! $program) {
            return;
        }

        // The currency Stripe actually took is the only fact here — the program's
        // setting is what we ASKED for, and a stale one must never relabel money
        // that has already moved. No default: money is never booked under a
        // currency nobody charged.
        $currency = strtoupper(trim((string) ($chargedCurrency ?: $program->currency)));
        if ($currency === '') {
            Log::error('Subscription charge has no currency — not booked', [
                'subscription' => $sub->uuid, 'reference' => $reference,
            ]);

            return;
        }

        if ($program->currency && strtoupper(trim((string) $program->currency)) !== $currency) {
            Log::warning('Charged currency differs from the program currency', [
                'subscription' => $sub->uuid,
                'program'      => $program->currency,
                'charged'      => $currency,
            ]);
        }
        $commission = (float) ($program->commission_percentage ?: 0);
        $fullGross = round((float) $sub->monthly_amount + (! $sub->first_charge_at ? (float) $sub->joining_fee : 0), 2);

        // Stripe can collect MORE than this membership costs: an unpaid balance
        // from elsewhere on the customer's account is added to whatever they are
        // billed next (Stripe's starting_balance). That money is not this
        // membership's revenue, and the old `amountPaid - providerShare` quietly
        // booked every fil of it as Bookboll commission.
        //
        // Paying LESS than full price is normal and unchanged — a promocode or
        // loyalty redemption comes out of Bookboll's cut, so the provider keeps
        // their full share and the platform fee absorbs the difference.
        $billable = min($amountPaid, $fullGross);
        $overCollected = round($amountPaid - $billable, 2);

        $providerShare = round($fullGross * (1 - $commission / 100), 2);
        $providerShare = min($providerShare, $billable);
        $netPlatformFee = round($billable - $providerShare, 2);

        if ($overCollected > 0.001) {
            // Someone has to give this back — it is not ours and not the
            // provider's. Loud, and on the platform's alert feed.
            \Illuminate\Support\Facades\Log::error('Subscription over-collected — refund due', [
                'subscription' => $sub->uuid,
                'program'      => $program->id,
                'charged'      => $amountPaid,
                'owed'         => $fullGross,
                'excess'       => $overCollected,
                'currency'     => $currency,
            ]);

            try {
                \App\Support\AdminAlerts::toPlatform('wallet', 'warn',
                    'Membership over-charged — refund '.$currency.' '.number_format($overCollected, 2),
                    ($sub->customer_email ?: 'a member').' paid '.number_format($amountPaid, 2)
                        .' for a '.number_format($fullGross, 2).' membership',
                    '/wallet',
                    is_numeric($sub->country_id) ? (int) $sub->country_id : null);
            } catch (\Throwable $e) {
                // An alert must never break the money booking.
            }
        }

        $ownerKey = $program->owner_user_id ?: ($program->mapped_owner_user_id ? (string) $program->mapped_owner_user_id : null);

        // A wallet with no country is invisible: the dashboard lists wallets by
        // country, so a null one renders nowhere however much money is in it.
        // A program with no venue (LOCATION "—") produced exactly that, so fall
        // back to the venue's country and then the owner's own.
        $countryId = $sub->country_id
            ?: ($program->venue?->country_id
                ?: ($ownerKey ? optional($this->ownerUserFor($ownerKey))->country_id : null));

        $wallet = $sub->wallet_id
            ? $this->reconcileWalletCurrency(ProviderWallet::find($sub->wallet_id), $currency)
            : ($ownerKey ? $this->ensureSubscriptionWallet($ownerKey, $countryId, $currency) : null);

        $user = User::where('uuid', $sub->customer_id)->first();
        $desc = $sub->first_charge_at ? 'Membership renewal' : 'First membership charge';
        $now = now();

        // Stripe's hosted page for the invoice that collected this money, so the
        // dashboard can hand staff the document Stripe issued. Stored rather
        // than re-fetched per row; a non-Stripe value is dropped here so it can
        // never be served as a link.
        $invoiceUrl = StripeDocumentLink::sanitize($invoiceUrl);

        Payment::create([
            'subscription_id' => $sub->id,
            'source_type'     => 'subscription',
            'type'            => 'subscription_commission',
            'method'          => 'online',
            'amount'          => $netPlatformFee,
            'currency'        => $currency,
            'recipient'       => '@bookboll',
            'status'          => 'paid',
            'paid_at'         => $now,
            'date'            => $now->toDateString(),
            'notes'           => $loyalty > 0 ? "{$desc} (− {$currency} {$loyalty} loyalty)" : $desc,
            'payment_id'      => $reference ?: 'sub-c-'.Str::uuid(),
            'stripe_invoice_url' => $invoiceUrl,
            'user_id'         => $user?->id,
        ]);

        Payment::create([
            'subscription_id' => $sub->id,
            'source_type'     => 'subscription',
            'type'            => 'subscription_earning',
            'method'          => 'online',
            'amount'          => $providerShare,
            'currency'        => $currency,
            'recipient'       => '@provider',
            'status'          => 'paid',
            'paid_at'         => $now,
            'date'            => $now->toDateString(),
            'notes'           => $desc,
            'payment_id'      => 'sub-e-'.Str::uuid(),
            'stripe_invoice_url' => $invoiceUrl,
            'user_id'         => $user?->id,
        ]);

        if (! $wallet) {
            return;
        }

        $holdDays = (int) ($wallet->hold_days ?: 7);
        $automatic = in_array($wallet->setup_method, ['stripe', 'stripe_connect', 'automatic'], true);

        WalletTransaction::create([
            'owner_user_id'      => $wallet->owner_user_id,
            'type'               => 'subscription_online',
            'direction'          => 'credit',
            // Billable, not charged: an over-collection is a refund owed, not
            // income, and must not make amount != provider_share + platform_fee.
            'amount'             => $billable,
            'provider_share'     => $providerShare,
            'platform_fee'       => $netPlatformFee,
            'source_id'          => $sub->uuid,
            'source_label'       => 'Subscription · '.($sub->customer_name ?: $sub->customer_email ?: 'customer'),
            'description'        => $desc,
            'status'             => $automatic ? 'completed' : 'on_hold',
            'currency'           => $currency,
            'country_id'         => $wallet->country_id,
            'wallet_id'          => $wallet->id,
            'available_on'       => $automatic ? null : now()->addDays($holdDays)->toDateString(),
            'hold_days_snapshot' => $automatic ? 0 : $holdDays,
        ]);

        $this->wallets->applyDelta((string) $wallet->id, $automatic
            ? ['earned' => $providerShare, 'withdrawn' => $providerShare]
            : ['pending' => $providerShare, 'earned' => $providerShare]);

        $sub->update(['wallet_id' => $wallet->id, 'provider_payout' => $providerShare, 'platform_fee' => $netPlatformFee]);
    }

    protected function recordFirstCharge(
        ClassSubscription $sub,
        ClassProgram $program,
        float $chargeGross,
        float $chargePlatformFeeBeforeLoyalty,
        float $firstChargeDiscount,
        float $loyaltyRedeem,
        ?ProviderWallet $wallet,
        ?User $user = null
    ): float {
        $fullGross = $chargeGross;
        $disc = min(max(0, $firstChargeDiscount), $fullGross);
        $gross = round($fullGross - $disc, 2);
        $feeRate = $fullGross > 0 ? $chargePlatformFeeBeforeLoyalty / $fullGross : 0;
        $platformFee = round($gross * $feeRate, 2);
        $providerShare = round($gross - $platformFee, 2);
        // Redemption is capped by the platform fee AND by what the balance can fund;
        // spendLoyalty writes the debit so points can't be redeemed twice.
        $loyalty = $this->spendLoyalty($user, $sub, min(max(0, $loyaltyRedeem), $platformFee));
        $netPlatformFee = round($platformFee - $loyalty, 2);
        $currency = $program->currency ?: 'AED';

        if (! $wallet) {
            $ownerKey = $program->owner_user_id ?: ($program->mapped_owner_user_id ? (string) $program->mapped_owner_user_id : null);
            if ($ownerKey) {
                $wallet = $this->ensureSubscriptionWallet($ownerKey, $sub->country_id, $currency);
            }
        }
        if (! $wallet) {
            return $loyalty;
        }

        $recipient = '@provider';
        if ($program->mapped_owner_user_id) {
            $u = User::find($program->mapped_owner_user_id);
            if ($u) {
                $recipient = '@'.preg_replace('/\s+/', '', strtolower($u->name ?: 'provider'));
            }
        }

        $desc = 'First membership charge';
        $now = now();
        // payments.user_id is the paying customer (same convention as bookings).
        // Imported programs have no mapped_owner_user_id, which used to write 0 here
        // and break the FK on every paid sign-up.
        $userId = $user?->id ?: $program->mapped_owner_user_id;

        Payment::create([
            'booking_id'      => null,
            'subscription_id' => $sub->id,
            'source_type'     => 'subscription',
            'type'            => 'subscription_commission',
            'method'          => 'online',
            'amount'          => $netPlatformFee,
            'currency'        => $currency,
            'recipient'       => '@bookboll',
            'status'          => 'paid',
            'paid_at'         => $now,
            'date'            => $now->toDateString(),
            'notes'           => $loyalty > 0 ? "{$desc} (− {$currency} {$loyalty} loyalty)" : $desc,
            'payment_id'      => 'sub-c-'.Str::uuid(),
            'user_id'         => $userId,
        ]);

        Payment::create([
            'booking_id'      => null,
            'subscription_id' => $sub->id,
            'source_type'     => 'subscription',
            'type'            => 'subscription_earning',
            'method'          => 'online',
            'amount'          => $providerShare,
            'currency'        => $currency,
            'recipient'       => $recipient,
            'status'          => 'paid',
            'paid_at'         => $now,
            'date'            => $now->toDateString(),
            'notes'           => $desc,
            'payment_id'      => 'sub-e-'.Str::uuid(),
            'user_id'         => $userId,
        ]);

        $holdDays = (int) ($wallet->hold_days ?: 7);
        $availableOn = now()->addDays($holdDays)->toDateString();
        $ownerKey = $wallet->owner_user_id;
        $automatic = in_array($wallet->setup_method, ['stripe', 'stripe_connect', 'automatic'], true);

        WalletTransaction::create([
            'owner_user_id'       => $ownerKey,
            'type'                => 'subscription_online',
            'direction'           => 'credit',
            'amount'              => round($gross - $loyalty, 2),
            'provider_share'      => $providerShare,
            'platform_fee'        => $netPlatformFee,
            'source_id'           => $sub->uuid,
            'source_label'        => 'Subscription · '.($sub->customer_name ?: $sub->customer_email ?: 'customer'),
            'description'         => $desc,
            'status'              => $automatic ? 'completed' : 'on_hold',
            'currency'            => $currency,
            'country_id'          => $wallet->country_id,
            'wallet_id'           => $wallet->id,
            'available_on'        => $automatic ? null : $availableOn,
            'hold_days_snapshot'  => $automatic ? 0 : $holdDays,
        ]);

        if ($automatic) {
            $this->wallets->applyDelta((string) $wallet->id, [
                'earned'    => $providerShare,
                'withdrawn' => $providerShare,
            ]);
        } else {
            $this->wallets->applyDelta((string) $wallet->id, [
                'pending' => $providerShare,
                'earned'  => $providerShare,
            ]);
        }

        return $loyalty;
    }
}
