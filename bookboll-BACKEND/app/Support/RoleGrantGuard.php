<?php

namespace App\Support;

use App\Models\User;

/**
 * Who may hand out which role.
 *
 * `capability:manage_team` answers "may this person administer a team at all";
 * it does not answer "may this person create a SUPER ADMIN". Without that second
 * question a provider's own manager — who legitimately holds manage_team so they
 * can invite staff — could grant themselves the whole platform.
 *
 * The ladder, mirroring how the dashboard's RoleSelect offers roles:
 *   • global roles (super_admin, admin) may only be granted by a global role;
 *   • platform roles may only be granted by a global role;
 *   • provider roles may be granted by a global role, or by a provider-side
 *     manager holding manage_team (for their own business).
 *
 * Nobody may edit or delete an account that outranks them, and nobody may
 * delete themselves.
 */
class RoleGrantGuard
{
    /** Rank: higher wins. Used only for "may I touch this account". */
    protected static function rank(string $role): int
    {
        return match ($role) {
            'super_admin'                      => 100,
            'admin'                            => 90,
            'country_admin', 'platform_staff'  => 70,
            'provider_admin'                   => 50,
            'facility_admin', 'provider_supervisor' => 40,
            default                            => 10,
        };
    }

    /** The strongest role an account holds. */
    public static function rankOf(?User $user): int
    {
        if (! $user) {
            return 0;
        }

        return $user->roles->reduce(fn ($carry, $r) => max($carry, self::rank($r->name)), 0);
    }

    /** May $actor grant $role to someone? */
    public static function mayGrant(?User $actor, string $role): bool
    {
        if (! $actor) {
            return false;
        }

        if ($role === 'super_admin') return AccountAdministration::isSuperAdmin($actor);
        $isGlobal = $actor->hasAnyRole(Capabilities::globalRoles());

        // Only a global admin creates global or platform-side accounts.
        if (in_array($role, Capabilities::platformRoles(), true)) {
            return $isGlobal && !AccessScope::businessId($actor);
        }

        if ($role === 'provider_admin' && AccessScope::businessId($actor)) return false;
        if (in_array($role, Capabilities::providerRoles(), true)) {
            // A provider manager may staff their own business; never above themselves.
            return $isGlobal || self::rankOf($actor) >= self::rank($role);
        }

        // Unknown / customer-side roles are not team roles.
        return $isGlobal;
    }

    /** May $actor modify or delete $target? */
    public static function mayTouch(?User $actor, ?User $target): bool
    {
        if (! $actor || ! $target) {
            return false;
        }
        if ($businessId = AccessScope::businessId($actor)) {
            if (AccessScope::businessId($target) !== $businessId) return false;
        }
        if ($target->hasRole('super_admin')) return AccountAdministration::isSuperAdmin($actor);
        if (AccountAdministration::isSuperAdmin($actor)) return true;

        return self::rankOf($actor) >= self::rankOf($target);
    }

    /** Standard refusal, shaped like every other API error. */
    public static function denied(string $what = 'do that')
    {
        return sendError("You do not have permission to {$what}.", [], 403);
    }
}
