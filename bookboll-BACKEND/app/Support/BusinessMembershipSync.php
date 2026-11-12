<?php

namespace App\Support;

use App\Models\BusinessMembership;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Keeps the legacy "staff under an owner" path and business memberships in step.
 *
 * Two ways of saying "this person works for that business" coexist during the
 * owner_user_id retirement:
 *
 *   legacy   users.owner_user_id → the owner's account → businesses.owner_id
 *   current  business_memberships.user_id → businesses.id
 *
 * The Team page's "Invite user" still writes only the legacy link. That broke the
 * moment a business could be OWNERLESS (Add business writes businesses.owner_id
 * as null and attaches its owner through a membership): the owner's id resolves
 * to no business row, so a manager invited under them signed in to an empty
 * dashboard — no business, no venues, no bookings. Live case: user 40183
 * (ozama@yopmail.com), a facility_admin under Badminton City's owner.
 *
 * This is the one place that bridges the two, so every writer of the legacy link
 * also leaves a membership behind, and every reader that starts from an owner id
 * can still find the business when owner_id is null.
 */
class BusinessMembershipSync
{
    /**
     * Legacy employee roles the membership enum does not know. They work IN the
     * business like front-desk staff, and Spatie keeps the real role — nothing
     * authorises off business_memberships.role (see BusinessAssignmentService).
     */
    public const LEGACY_ROLE_TO_MEMBERSHIP_ROLE = [
        'senior_employee' => 'staff',
        'junior_employee' => 'staff',
        'guard'           => 'staff',
    ];

    /**
     * The business an OWNER account stands for, by `businesses.id`.
     *
     * Reads businesses.owner_id first (a business registered by its owner), then
     * the owner's own active membership (a business created ownerless and claimed
     * through "Assign platform account"). A managing membership wins over a
     * plain one so an owner who also happens to be staff elsewhere resolves to
     * the business they run.
     */
    public static function businessIdForOwner(?int $ownerUserId): ?int
    {
        if (! $ownerUserId) {
            return null;
        }

        $owned = DB::table('businesses')->where('owner_id', $ownerUserId)->value('id');
        if ($owned) {
            return (int) $owned;
        }

        $memberships = BusinessMembership::query()
            ->where('user_id', $ownerUserId)
            ->where('status', BusinessMembership::STATUS_ACTIVE)
            ->orderBy('id')
            ->get(['business_id', 'role']);

        if ($memberships->isEmpty()) {
            return null;
        }

        $managing = $memberships->first(
            fn (BusinessMembership $m) => in_array($m->role, BusinessMembership::MANAGING_ROLES, true)
        );

        return (int) ($managing ?: $memberships->first())->business_id;
    }

    /**
     * The owner-side user id a MEMBER stands under, read through their membership.
     *
     * The inverse of businessIdForOwner(): the ~380 legacy reads key a business
     * by its owner's user id, and an account moved into an ownerless business
     * (BusinessAssignmentService) carries owner_user_id null — so every one of
     * those reads saw a platform user, and site settings, storefront sort and
     * the plan page answered with the PLATFORM's data. This hands them the id
     * the business's records are actually written under: owner_id when set,
     * else the managing member who runs it.
     */
    public static function ownerUserIdForMember(User $user): ?int
    {
        $membership = BusinessMembership::query()
            ->where('user_id', $user->id)
            ->where('status', BusinessMembership::STATUS_ACTIVE)
            ->first(['business_id']);

        if (! $membership) {
            return null;
        }

        $ownerId = DB::table('businesses')->where('id', $membership->business_id)->value('owner_id');
        if ($ownerId) {
            return (int) $ownerId;
        }

        $managing = BusinessMembership::query()
            ->where('business_id', $membership->business_id)
            ->where('status', BusinessMembership::STATUS_ACTIVE)
            ->whereIn('role', BusinessMembership::MANAGING_ROLES)
            ->orderByRaw("CASE WHEN role = ? THEN 0 ELSE 1 END", [BusinessMembership::ROLE_OWNER])
            ->orderBy('id')
            ->value('user_id');

        return $managing ? (int) $managing : null;
    }

    /** The membership role that stands for a Spatie role, or null if none should. */
    public static function membershipRoleFor(?string $spatieRole): ?string
    {
        if (! $spatieRole) {
            return null;
        }
        if (in_array($spatieRole, BusinessMembership::ASSIGNABLE_ROLES, true)) {
            return $spatieRole;
        }

        return self::LEGACY_ROLE_TO_MEMBERSHIP_ROLE[$spatieRole] ?? null;
    }

    /** The provider-team role this user carries, if any (the one the membership mirrors). */
    public static function teamRoleOf(User $user): ?string
    {
        $teamRoles = config('roles.provider_team_roles', []);
        $roles     = $user->getRoleNames()->all();

        foreach ($teamRoles as $role) {
            if (in_array($role, $roles, true)) {
                return $role;
            }
        }

        return null;
    }

    /**
     * Make the membership table agree with the legacy link just written on $user.
     *
     * - Team role + resolvable owner: one ACTIVE membership in the owner's
     *   business, created or reactivated; any other active staff membership of
     *   theirs is revoked, because a person belongs to one business at a time
     *   (AccessScope::businessId reads a single row).
     * - No team role: their active staff memberships are revoked — they have
     *   become platform-side.
     * - Team role but no resolvable owner: nothing changes. Members moved in by
     *   BusinessAssignmentService hold a membership and a null owner link, and
     *   that must survive an unrelated edit.
     *
     * Scope is always `all` with no location_ids: the legacy path keeps its pins
     * in user_venue / assigned_location_ids, which AccessScope::pinnedVenueIds
     * already reads. Copying them here would leave a stale pin behind on the
     * next edit, and pins are unioned, so a removed venue would stay enforced.
     *
     * Owner memberships are never touched: this path has no business deciding
     * who owns what.
     *
     * @return BusinessMembership|null the active membership afterwards, if any
     */
    public static function syncForStaff(User $user, ?int $ownerUserId, ?int $actorId = null): ?BusinessMembership
    {
        $user->unsetRelation('roles');

        $teamRole = self::teamRoleOf($user);

        // A provider_admin IS the business; their membership (if any) is the
        // owner's, which is BusinessAssignmentService's job, not this one.
        if ($user->hasRole('provider_admin')) {
            return null;
        }

        $role = self::membershipRoleFor($teamRole);

        // No team role any more: they have gone platform-side, and a staff
        // membership would keep handing them a business they left.
        if (! $role) {
            self::revokeStaffMembershipsExcept($user, null);

            return null;
        }

        $businessId = self::businessIdForOwner($ownerUserId);

        // Team role, but the legacy link points nowhere. That is the normal
        // state of an account moved in by BusinessAssignmentService into an
        // ownerless business (owner_user_id null), and the Team page re-sends
        // that null on every edit — so the membership they already hold is the
        // record, and revoking it here would sign them out of their business
        // for changing their phone number.
        if (! $businessId) {
            return BusinessMembership::query()
                ->where('user_id', $user->id)
                ->where('status', BusinessMembership::STATUS_ACTIVE)
                ->first();
        }

        self::revokeStaffMembershipsExcept($user, $businessId);

        $membership = BusinessMembership::query()
            ->where('business_id', $businessId)
            ->where('user_id', $user->id)
            ->first();

        if (! $membership) {
            return BusinessMembership::create([
                'business_id'  => $businessId,
                'user_id'      => $user->id,
                'role'         => $role,
                'scope'        => 'all',
                'location_ids' => null,
                'status'       => BusinessMembership::STATUS_ACTIVE,
                'version'      => 1,
                'created_by'   => $actorId,
            ]);
        }

        if ($membership->role === BusinessMembership::ROLE_OWNER) {
            return $membership;
        }

        $changes = [];
        if ($membership->status !== BusinessMembership::STATUS_ACTIVE) {
            $changes['status']     = BusinessMembership::STATUS_ACTIVE;
            $changes['revoked_at'] = null;
        }
        if ($membership->role !== $role) {
            $changes['role'] = $role;
        }
        if ($changes) {
            $changes['version'] = (int) $membership->version + 1;
            $membership->forceFill($changes)->save();
        }

        return $membership;
    }

    /** Revoke every active non-owner membership of $user outside $keepBusinessId. */
    protected static function revokeStaffMembershipsExcept(User $user, ?int $keepBusinessId): void
    {
        BusinessMembership::query()
            ->where('user_id', $user->id)
            ->where('status', BusinessMembership::STATUS_ACTIVE)
            ->where('role', '!=', BusinessMembership::ROLE_OWNER)
            ->when($keepBusinessId, fn ($q) => $q->where('business_id', '!=', $keepBusinessId))
            ->get()
            ->each(fn (BusinessMembership $m) => $m->forceFill([
                'status'     => BusinessMembership::STATUS_REVOKED,
                'revoked_at' => now(),
                'version'    => (int) $m->version + 1,
            ])->save());
    }

    /**
     * Every account on the legacy link with no active membership, and what the
     * sync would do for each. Shared by the backfill migration and the console
     * command so a dry run reports exactly what an apply will write.
     *
     * @return array<int, array{user_id:int,email:string,role:?string,owner_user_id:int,business_id:?int}>
     */
    public static function pendingBackfill(): array
    {
        $teamRoles = config('roles.provider_team_roles', []);

        $users = User::query()
            ->whereNotNull('owner_user_id')
            ->whereHas('roles', fn ($q) => $q->whereIn('name', $teamRoles))
            ->whereDoesntHave('roles', fn ($q) => $q->where('name', 'provider_admin'))
            ->whereDoesntHave('activeBusinessMembership')
            ->orderBy('id')
            ->get();

        return $users->map(fn (User $u) => [
            'user_id'       => (int) $u->id,
            'email'         => (string) $u->email,
            'role'          => self::teamRoleOf($u),
            'owner_user_id' => (int) $u->owner_user_id,
            'business_id'   => self::businessIdForOwner((int) $u->owner_user_id),
        ])->all();
    }

    /**
     * Write the memberships pendingBackfill() describes.
     *
     * @return array{created:int,unresolved:int}
     */
    public static function backfill(?int $actorId = null): array
    {
        $created    = 0;
        $unresolved = 0;

        foreach (self::pendingBackfill() as $row) {
            if (! $row['business_id']) {
                $unresolved++;
                continue;
            }

            $user = User::find($row['user_id']);
            if ($user && self::syncForStaff($user, $row['owner_user_id'], $actorId)) {
                $created++;
            } else {
                $unresolved++;
            }
        }

        return ['created' => $created, 'unresolved' => $unresolved];
    }
}
