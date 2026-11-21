<?php

namespace App\Support;

use App\Models\{Business, User, Venue};
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/** Apply the same business boundary to directory reads and access edits. */
final class TeamDirectory
{
    public static function forBusiness(Builder $query, Business $business): Builder
    {
        $ownerId = BusinessMembershipSync::ownerUserIdForBusiness($business->id);
        return $query->where(function ($q) use ($business, $ownerId) {
            $q->whereHas('businessMemberships', fn ($m) => $m->where('business_id', $business->id)->where('status', 'active'));
            if ($ownerId) $q->orWhere(fn ($legacy) => $legacy->whereDoesntHave('businessMemberships')->where(fn ($l) => $l->where('users.id',$ownerId)->orWhere('owner_user_id',$ownerId)));
        });
    }

    public static function scope(Builder $query, User $actor): Builder
    {
        if ($business = AccessScope::business($actor)) return self::forBusiness($query, $business);
        if (AccessScope::isProviderScoped($actor)) return $query->whereRaw('1 = 0');
        if (AccessScope::isGlobal($actor)) return $query;
        $countries = AccessScope::jurisdictionCountryIds($actor) ?? [];
        return $query->where(function ($q) use ($countries) {
            $q->whereIn('country_id', $countries);
            foreach ($countries as $id) $q->orWhereJsonContains('assigned_country_ids', $id)->orWhereJsonContains('assigned_country_ids', (string) $id);
        });
    }

    public static function venues(User $actor): Builder
    {
        $query = Venue::query(); $ids = AccessScope::allowedVenueIds($actor);
        return $ids === null ? $query : $query->whereIn('id', $ids);
    }

    public static function validateVenueGrant(Request $request, ?User $target = null, ?Business $business = null): void
    {
        $field = $request->has('assigned_location_ids') ? 'assigned_location_ids' : ($request->has('venues') ? 'venues' : null);
        $actor = $request->user();
        $business ??= $target ? AccessScope::business($target) : null;
        if (!$business && $request->filled('owner_user_id')) $business = Business::forOwnerId((int) $request->owner_user_id);
        $allowed = self::venues($actor);
        if ($business) {
            $owner = BusinessMembershipSync::ownerUserIdForBusiness($business->id);
            $allowed->where(fn ($q) => $q->where('business_id', $business->id)->when($owner, fn ($q) => $q->orWhere(fn ($l) => $l->whereNull('business_id')->where('owner_user_id', $owner))));
        }
        if ($field) {
            $ids = array_values(array_unique(array_map('intval', (array) $request->input($field, []))));
            if (array_diff($ids, $allowed->pluck('id')->all())) throw ValidationException::withMessages([$field => 'Choose only venues belonging to this business and within your access.']);
            if (!$ids && AccessScope::businessId($actor) && AccessScope::pinsApply($actor) && AccessScope::pinnedVenueIds($actor)) {
                throw ValidationException::withMessages([$field => 'Select the venues this person may access. Your account cannot grant whole-business access.']);
            }
        } elseif (!$target && AccessScope::businessId($actor) && AccessScope::pinsApply($actor) && AccessScope::pinnedVenueIds($actor)) {
            throw ValidationException::withMessages(['assigned_location_ids' => 'Select the venues this person may access.']);
        }
    }
}
