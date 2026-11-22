<?php

namespace App\Support;

use App\Models\{Business, BusinessMembership, BusinessSupportSession, User};

class BusinessSupport
{
    public static function session(): ?BusinessSupportSession
    {
        return app()->bound('request') ? request()->attributes->get('business_support_session') : null;
    }
    public static function actorId(): ?int { return self::session()?->actor_id ?? auth()->id(); }
    public static function eligibleOwner(?User $user, Business $business): bool
    {
        return $user && $user->status === User::ACTIVE && $user->hasRole('provider_admin')
            && !$user->hasAnyRole(config('roles.platform_team_roles'))
            && (int)AccessScope::businessId($user) === (int)$business->id
            && BusinessMembership::active()->where('business_id', $business->id)->where('user_id', $user->id)->where('role', 'owner')->exists();
    }
}
