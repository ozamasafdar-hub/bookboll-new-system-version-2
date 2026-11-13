<?php

namespace App\Support;

use App\Models\Business;
use App\Models\ClassProgram;
use App\Models\User;
use App\Models\Venue;
use Illuminate\Support\Facades\DB;
use Nnjeim\World\Models\City;

/**
 * Which rows a signed-in admin may see — the server-side twin of the dashboard's
 * lib/currentUser.js (ownership) and lib/jurisdiction.js (geography).
 *
 * Two different models, by design:
 *
 *   PROVIDER roles are scoped by OWNERSHIP. A provider_admin owns venues via
 *   venues.owner_user_id; their team inherits through users.owner_user_id.
 *   Managers/Staff may additionally be PINNED to specific venues; an owner never is.
 *
 *   PLATFORM roles are scoped by JURISDICTION — their assigned countries (falling
 *   back to their own country), with platform_staff optionally pinned to venues.
 *   super_admin (and legacy `admin`) see everything.
 *
 * Venue pins come from either users.assigned_location_ids (what the dashboard
 * writes) or the older user_venue pivot, so existing assignments keep working.
 */
class AccessScope
{
    /**
     * The business this user belongs to, by `businesses.id`.
     *
     * Reads the membership table first, because that is the only answer that
     * works for a business whose owner account has gone or was never created.
     * Falls back to the owner-keyed lookup so every account that resolved to a
     * business before this table existed still resolves to the same one — this
     * is the bridge, not a replacement. `owner_user_id` is still read in 382
     * places and is retired per-area, with its own tests.
     *
     * Returns null for platform-side users, who are not scoped to a business.
     */
    public static function businessId(?User $user): ?int
    {
        if (! $user) {
            return null;
        }

        $membership = \App\Models\BusinessMembership::query()
            ->where('user_id', $user->id)
            ->where('status', \App\Models\BusinessMembership::STATUS_ACTIVE)
            ->value('business_id');

        if ($membership) {
            return (int) $membership;
        }

        // Pre-membership accounts: resolve the owner, then their business. The
        // owner may stand for an OWNERLESS business (businesses.owner_id null,
        // owner attached by membership), so this reads both links — without the
        // second a manager invited under such an owner resolved to nothing.
        return BusinessMembershipSync::businessIdForOwner(self::ownerUserId($user));
    }

    /** The Business row behind businessId(), or null for platform-side users. */
    public static function business(?User $user): ?Business
    {
        $id = self::businessId($user);

        return $id ? Business::find($id) : null;
    }

    /**
     * The business this user RUNS — owned outright, or held through a managing
     * membership (owner / provider_admin). Null for staff and platform users.
     *
     * The owner-only writes (business profile, brand) used to read
     * `$user->ownedBusiness`, which is null for the owner of a business created
     * ownerless: they were refused their own profile page.
     */
    public static function managedBusiness(?User $user): ?Business
    {
        if (! $user) {
            return null;
        }
        if ($user->ownedBusiness) {
            return $user->ownedBusiness;
        }

        $id = \App\Models\BusinessMembership::query()
            ->where('user_id', $user->id)
            ->where('status', \App\Models\BusinessMembership::STATUS_ACTIVE)
            ->whereIn('role', \App\Models\BusinessMembership::MANAGING_ROLES)
            ->value('business_id');

        return $id ? Business::find($id) : null;
    }

    /** The provider business this user belongs to, or null for platform-side users. */
    public static function ownerUserId(?User $user): ?int
    {
        if (! $user) {
            return null;
        }
        if ($user->hasRole('provider_admin')) {
            return (int) $user->id;
        }
        if ($user->owner_user_id) {
            return (int) $user->owner_user_id;
        }

        // Moved into a business by membership: owner_user_id is null when that
        // business is ownerless, and every owner-keyed read then took them for
        // a PLATFORM user — site settings and storefront sort showed, and could
        // write, the platform's values. Stand them under the business instead.
        return BusinessMembershipSync::ownerUserIdForMember($user);
    }

    /** Sees everything, unscoped. */
    public static function isGlobal(?User $user): bool
    {
        return (bool) $user?->hasAnyRole(Capabilities::globalRoles());
    }

    public static function isPlatform(?User $user): bool
    {
        return (bool) $user?->hasAnyRole(Capabilities::platformRoles());
    }

    public static function isProviderScoped(?User $user): bool
    {
        return (bool) $user?->hasAnyRole(Capabilities::providerRoles());
    }

    /**
     * Only Managers/Staff are narrowed by venue pins — an Owner sees their whole
     * business, and platform admins their whole jurisdiction (currentUser.js
     * scopeNarrows()).
     */
    public static function pinsApply(?User $user): bool
    {
        if (! $user || self::isGlobal($user)) {
            return false;
        }

        return ! $user->hasAnyRole(['country_admin', 'provider_admin']);
    }

    /**
     * Countries a platform user administers.
     *
     * @return int[]|null null = every country (super admin / provider-side)
     */
    public static function jurisdictionCountryIds(?User $user): ?array
    {
        if (! $user || self::isGlobal($user) || ! self::isPlatform($user)) {
            return null;
        }

        $assigned = array_values(array_filter((array) ($user->assigned_country_ids ?? [])));
        if ($assigned) {
            return array_map('intval', $assigned);
        }

        return $user->country_id ? [(int) $user->country_id] : [];
    }

    /**
     * Venue ids explicitly pinned to this user, from any source.
     *
     * @return int[]
     */
    public static function pinnedVenueIds(?User $user): array
    {
        if (! $user) {
            return [];
        }

        $ids = array_map('intval', array_values(array_filter((array) ($user->assigned_location_ids ?? []))));

        $pivot = DB::table('user_venue')->where('user_id', $user->id)->pluck('venue_id')->all();

        // Third source, and for a business member the only one: moving an account
        // into a business nulls assigned_location_ids and detaches user_venue, so
        // the pin now lives on the membership row. A membership scoped to `all` is
        // not a pin — it is the whole business — and must contribute nothing here,
        // or an unpinned member would be narrowed to an empty set. Conversely,
        // ignoring a scoped membership's location_ids would widen that member from
        // their venues to the entire business.
        $fromMembership = [];
        $membership = \App\Models\BusinessMembership::query()
            ->where('user_id', $user->id)
            ->where('status', \App\Models\BusinessMembership::STATUS_ACTIVE)
            ->first();

        if ($membership && $membership->scope !== 'all') {
            $fromMembership = array_map(
                'intval',
                array_values(array_filter((array) ($membership->location_ids ?? [])))
            );
        }

        return array_values(array_unique(array_merge(
            $ids,
            array_map('intval', $pivot),
            $fromMembership
        )));
    }

    /**
     * The venues this user may touch.
     *
     * @return int[]|null null = unrestricted; [] = nothing
     */
    /**
     * The customers this user may see — a direct port of the dashboard's
     * `relevantCustomerIds`, and the single definition shared by the Customers list
     * and the marketing composer. If those two ever disagreed, a provider would see
     * people they cannot email, or email people they cannot see.
     *
     * A customer is in scope when they have BOOKED at a venue in scope, SUBSCRIBED
     * to a programme in scope, or were ADDED by the business (pivot / created_by).
     * Subscriptions match on email because that is the only link class_subscriptions
     * carries. Nobody else: a marketplace signup is not a business's customer.
     *
     * @return array<int, int>|null  null = unrestricted (global admin); [] = nobody
     */
    public static function customerIdsInScope(?User $user): ?array
    {
        if (! $user) {
            return [];
        }

        if (self::allowedVenueIds($user) === null) {
            return null;   // super admin / platform owner — the whole marketplace
        }

        return self::applyCustomerScope(User::query(), $user)
            ->pluck('users.id')->map(fn ($i) => (int) $i)->unique()->values()->all();
    }

    /**
     * Narrow a `users` query to the customers $user may see — the PREDICATE form of
     * the rule above, and now the single place that rule is defined.
     * customerIdsInScope() is derived from this, so the Customers list, the export,
     * the CRM lookups and the marketing composer cannot drift apart.
     *
     * Expressed as whereExists rather than whereIn(ids) so a paginated list does not
     * have to materialise every customer id of a large business in PHP first.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public static function applyCustomerScope($query, ?User $user)
    {
        if (! $user) {
            return $query->whereRaw('1 = 0');
        }

        $venueIds = self::allowedVenueIds($user);
        if ($venueIds === null) {
            return $query;   // unrestricted
        }

        $ownerIds   = self::scopeOwnerIds($user);
        $programIds = self::scopedProgramIds($user, $venueIds);
        $businessId = self::businessId($user);

        return $query->where(function ($q) use ($venueIds, $programIds, $ownerIds, $businessId) {
            // Nothing is in scope yet; each branch below widens it. Without this seed
            // a caller owning no venue and no programme would match every customer.
            $q->whereRaw('1 = 0');

            // ── Explicitly linked to the business ──
            // The authoritative link: a client the business typed in has no booking
            // and no subscription, so only this branch can see them.
            if ($ownerIds) {
                $q->orWhereExists(function ($sub) use ($ownerIds) {
                    $sub->selectRaw('1')->from('business_customer')
                        ->whereColumn('business_customer.user_id', 'users.id')
                        ->whereIn('business_customer.owner_user_id', $ownerIds);
                });

                // Belt and braces beside the pivot: users.created_by records who typed
                // the row in (TracksCreator). Covers anything created before the pivot
                // existed, or by a path that forgets to link. A creator counts as the
                // business's own through the legacy owner key OR a membership — a
                // member assigned into an ownerless business carries no owner key.
                $q->orWhereExists(function ($sub) use ($ownerIds, $businessId) {
                    $sub->selectRaw('1')->from('users as creators')
                        ->whereColumn('creators.id', 'users.created_by')
                        ->where(function ($c) use ($ownerIds, $businessId) {
                            $c->whereIn('creators.id', $ownerIds)
                                ->orWhereIn('creators.owner_user_id', $ownerIds);
                            if ($businessId) {
                                $c->orWhereExists(function ($m) use ($businessId) {
                                    $m->selectRaw('1')->from('business_memberships')
                                        ->whereColumn('business_memberships.user_id', 'creators.id')
                                        ->where('business_memberships.business_id', $businessId)
                                        ->where('business_memberships.status', 'active');
                                });
                            }
                        });
                });
            }

            // ── Booked at a venue in scope ──
            if ($venueIds) {
                $q->orWhereExists(function ($sub) use ($venueIds) {
                    $sub->selectRaw('1')->from('bookings')
                        ->whereColumn('bookings.user_id', 'users.id')
                        ->whereIn('bookings.venue_id', $venueIds);
                });
            }

            // ── Subscribed to a programme in scope ──
            // Matched on email: the only link class_subscriptions carries.
            if ($programIds) {
                $q->orWhereExists(function ($sub) use ($programIds) {
                    $sub->selectRaw('1')->from('class_subscriptions')
                        ->whereIn('class_subscriptions.program_id', $programIds)
                        ->whereNotNull('class_subscriptions.customer_email')
                        ->where('class_subscriptions.customer_email', '!=', '')
                        ->whereRaw('LOWER(class_subscriptions.customer_email) = LOWER(users.email)');
                });
            }

            // That is the whole of it. Marketplace signups who have booked nowhere
            // and joined nothing are NOT shown: a shared pool of every Bookboll
            // customer was briefly offered to every business (2026-11-03, "no
            // claim") and withdrawn 2026-11-13 — on live it put 12,000+ strangers
            // in front of a business with 450 customers of its own. A business
            // sees the people it has a relationship with: booked with it, joined
            // its programme, or typed in by its team.
        });
    }

    /**
     * Programmes belonging to this caller's business (or, platform-side, jurisdiction).
     *
     * A programme whose venue was removed still belongs to its owner, so the owner
     * fallback keeps it in scope; venue alone would silently drop those members.
     *
     * @param  int[]  $venueIds
     * @return int[]
     */
    /**
     * The businesses this caller administers, as owner user ids.
     *
     * One entry for a provider (their own business); for a platform role, every
     * provider in their jurisdiction. Empty for a global admin, who is never
     * narrowed by it.
     *
     * @return int[]
     */
    protected static function scopeOwnerIds(?User $user): array
    {
        $ownerId = self::ownerUserId($user);

        return $ownerId !== null
            ? [$ownerId]
            : array_map('intval', self::jurisdictionOwnerIds($user));
    }

    /**
     * Programmes this user may see. Public because customer-type classification
     * needs exactly this boundary — a membership in a programme outside it must
     * not show up as a type, or the badge leaks that the activity exists.
     */
    public static function scopedProgramIds(?User $user, array $venueIds): array
    {
        $ownerIds = self::scopeOwnerIds($user);

        // No venues AND no owners means no programmes in scope. Without this guard
        // the where() below adds no constraint at all and matches EVERY programme,
        // which then pulled every subscriber's email into scope.
        if (! $venueIds && ! $ownerIds) {
            return [];
        }

        return ClassProgram::query()
            ->where(function ($q) use ($venueIds, $ownerIds) {
                if ($venueIds) {
                    $q->whereIn('venue_id', $venueIds);
                }
                foreach ($ownerIds as $oid) {
                    $q->orWhere('mapped_owner_user_id', (int) $oid)
                        ->orWhere('owner_user_id', (string) $oid);
                }
            })
            ->pluck('id')->map(fn ($i) => (int) $i)->all();
    }

    /**
     * Providers a PLATFORM user administers — those with a venue in their jurisdiction,
     * plus (the dashboard's fallback) those whose own country is in it, so a provider
     * who has not added a venue yet is still administered by the right country admin.
     *
     * @return array<int, int>
     */
    public static function jurisdictionOwnerIds(?User $user): array
    {
        $venueIds = self::allowedVenueIds($user);
        if ($venueIds === null) {
            return [];   // unrestricted — the caller does not need an owner filter
        }

        $byVenue = $venueIds
            ? Venue::whereIn('id', $venueIds)->whereNotNull('owner_user_id')->distinct()->pluck('owner_user_id')
            : collect();

        $countryIds = self::jurisdictionCountryIds($user);
        $byCountry = ($countryIds === null || ! $countryIds)
            ? collect()
            : User::whereIn('country_id', $countryIds)
                ->whereHas('roles', fn ($q) => $q->where('name', 'provider_admin'))
                ->pluck('id');

        return $byVenue->merge($byCountry)->map(fn ($i) => (int) $i)->unique()->values()->all();
    }

    public static function allowedVenueIds(?User $user): ?array
    {
        if (! $user) {
            return [];
        }
        if (self::isGlobal($user)) {
            return null;
        }

        $pins = self::pinnedVenueIds($user);

        // ── Provider side: everything their business owns, then any pins ──
        //
        // A venue reaches its business two ways: the legacy owner key, and
        // venues.business_id — which is the ONLY link for a business created
        // owner-less (BusinessIdentityService writes owner_id as null, so the
        // owner key resolves to nothing for every member of it). Reading both
        // keeps pre-business providers working while owner_user_id is retired
        // per-area; without the business branch a member of such a business
        // signed in to an empty dashboard.
        $ownerId    = self::ownerUserId($user);
        $businessId = self::businessId($user);

        if ($ownerId !== null || $businessId !== null) {
            $owned = Venue::query()
                ->where(function ($q) use ($ownerId, $businessId) {
                    if ($ownerId !== null) {
                        $q->orWhere('owner_user_id', $ownerId);
                    }
                    if ($businessId !== null) {
                        $q->orWhere('business_id', $businessId);
                    }
                })
                ->pluck('id')->map(fn ($i) => (int) $i)->all();

            if (self::pinsApply($user) && $pins) {
                return array_values(array_intersect($owned, $pins));
            }

            return $owned;
        }

        // ── Platform side: jurisdiction, or explicit pins for platform_staff ──
        if (self::isPlatform($user)) {
            if ($user->hasRole('platform_staff') && $pins) {
                return $pins;
            }

            $countryIds = self::jurisdictionCountryIds($user);
            if ($countryIds === null) {
                return null;
            }
            if (! $countryIds) {
                return [];
            }

            return Venue::query()
                ->where(function ($q) use ($countryIds) {
                    $q->whereIn('country_id', $countryIds)
                        ->orWhereIn('city_id', City::whereIn('country_id', $countryIds)->select('id'));
                })
                ->pluck('id')->map(fn ($i) => (int) $i)->all();
        }

        // A user with no business and no platform role sees only their pins.
        return $pins;
    }

    /**
     * May this user act on this one venue?
     *
     * THE authority for per-venue permission. Services used to ask
     * `DB::table('user_venue')` directly, which only knows about explicitly
     * assigned venues — so a provider who OWNED a venue (venues.owner_user_id,
     * which is what allowedVenueIds uses) was refused by those checks. The venue
     * appeared in their list and then every write on it came back 403.
     */
    public static function canTouchVenue(?User $user, int $venueId): bool
    {
        if (! $user || $venueId <= 0) {
            return false;
        }

        $ids = self::allowedVenueIds($user);

        // null = unrestricted (global admin); [] = nothing allowed.
        return $ids === null ? true : in_array($venueId, $ids, true);
    }

    /**
     * Constrain a query to the venues this user may see.
     *
     * @param  string  $column  the venue foreign key on the query's table
     */
    public static function applyVenue($query, string $column = 'venue_id', ?User $user = null)
    {
        $user = $user ?: auth()->user();
        if (! $user) {
            return $query->whereRaw('1 = 0');
        }

        $ids = self::allowedVenueIds($user);
        if ($ids === null) {
            return $query;
        }
        if (! $ids) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereIn($column, $ids);
    }

    /**
     * Listing pins (currentUser.js scopeListings) — empty means no restriction
     * beyond the venue scope.
     *
     * @return int[]
     */
    public static function pinnedListingIds(?User $user): array
    {
        if (! $user || ! self::pinsApply($user)) {
            return [];
        }

        return array_map('intval', array_values(array_filter((array) ($user->assigned_listing_ids ?? []))));
    }

    /** @return int[] */
    public static function pinnedProgramIds(?User $user): array
    {
        if (! $user || ! self::pinsApply($user)) {
            return [];
        }

        return array_map('intval', array_values(array_filter((array) ($user->assigned_program_ids ?? []))));
    }
}
