<?php

namespace App\Support;

use App\Models\{Business, BusinessSupportSession, User};

class BusinessSupport
{
    public static function session(): ?BusinessSupportSession
    {
        return app()->bound('request') ? request()->attributes->get('business_support_session') : null;
    }
    public static function actorId(): ?int { return self::session()?->actor_id ?? auth()->id(); }

    /** Use the same current/legacy ownership evidence as business management. */
    public static function ownerFor(Business $business): ?User
    {
        $owners = BusinessOwnership::owners($business)->orderBy('users.id')->get();
        // Pre-membership businesses still use owner_id. Revoked memberships are
        // never resurrected: eligibleOwner checks ownership and effective scope.
        if ($business->owner_id && !$owners->contains('id', $business->owner_id)) {
            if ($legacy = User::find($business->owner_id)) $owners->push($legacy);
        }
        return $owners->sortByDesc(fn ($u) => (int)$u->id === (int)$business->owner_id)
            ->first(fn ($u) => self::eligibleOwner($u, $business));
    }

    public static function eligibleOwner(?User $user, Business $business): bool
    {
        return $user && $user->status === User::ACTIVE && $user->hasRole('provider_admin')
            && !$user->hasAnyRole(config('roles.platform_team_roles'))
            && (int)AccessScope::businessId($user) === (int)$business->id
            && BusinessOwnership::owns($user, $business);
    }
}
