<?php

namespace App\Support;

use App\Models\{Business, BusinessMembership, User};
use Illuminate\Http\Request;

/** Membership grants access; owner_id is the primary contact, legacy_owner_id is a stable data key. */
final class BusinessOwnership
{
    public static function roleOf(User $user): string
    {
        return $user->hasRole('provider_admin') ? 'provider_admin' : (BusinessMembershipSync::teamRoleOf($user) ?: 'staff');
    }

    public static function owners(Business $business)
    {
        return User::query()->join('business_memberships as ownership','ownership.user_id','=','users.id')->where('ownership.business_id',$business->id)->where('ownership.status','active')->whereIn('ownership.role',BusinessMembership::MANAGING_ROLES)->select('users.*');
    }

    public static function owns(User $user, Business $business): bool
    {
        $membership = $user->businessMemberships()->where('business_id',$business->id)->first();
        return $membership ? $membership->manages() : ($business->owner_id === $user->id && $user->hasRole('provider_admin'));
    }

    public static function mayManage(User $actor, Business $business): bool
    {
        if (AccountAdministration::isSuperAdmin($actor)) return true;
        $actor = User::whereKey($actor->id)->lockForUpdate()->first();
        $membership = $actor?->businessMemberships()->where('business_id',$business->id)->lockForUpdate()->first();
        $owns = $membership ? $membership->manages() : ($actor && $business->owner_id === $actor->id && $actor->hasRole('provider_admin'));
        return $actor && $actor->status === User::ACTIVE && $owns && $actor->hasCapability('manage_team');
    }

    /** Called with the business row locked, serializing concurrent owner removals. */
    public static function keepActiveOwner(Business $business, User $target): void
    {
        abort_unless(self::owners($business)->where('users.status',User::ACTIVE)->where('users.id','!=',$target->id)->lockForUpdate()->get()->isNotEmpty(),422,'Keep at least one active business owner. Add or reactivate another owner first.');
    }

    /** Only changes the primary contact. Historical revenue and website keys never move. */
    public static function refreshPrimary(Business $business): void
    {
        $active = self::owners($business)->where('users.status',User::ACTIVE)->orderBy('users.id')->lockForUpdate()->get();
        if ($business->owner_id && $active->contains('id',$business->owner_id)) return;
        $next = $active->first()?->id;
        if ($next) $business->forceFill(['owner_id'=>$next, 'legacy_owner_id'=>$business->legacy_owner_id ?: $business->owner_id ?: $next])->save();
    }

    public static function initialize(Business $business, User $owner): void
    {
        if ($business->owner_id && User::whereKey($business->owner_id)->whereHas('roles',fn ($q)=>$q->where('name','provider_admin'))->exists()) {
            BusinessMembership::firstOrCreate(['business_id'=>$business->id,'user_id'=>$business->owner_id],['role'=>'owner','scope'=>'all','status'=>'active','version'=>1]);
        }
        $anchor=$business->legacy_owner_id ?: $business->owner_id ?: $owner->id;
        abort_if(Business::where('legacy_owner_id',$anchor)->where('id','!=',$business->id)->exists(),422,'This account is linked to another business’s historical records. Add another first owner or invite it as a co-owner after setup.');
        $business->forceFill(['legacy_owner_id'=>$anchor])->save();
        self::refreshPrimary($business);
    }

    public static function guardChange(Request $request, Business $business, User $target, string $role, string $status, bool $removing = false): void
    {
        $membership = $target->businessMemberships()->where('business_id',$business->id)->lockForUpdate()->first();
        abort_if($membership && $membership->status !== 'active',409,'This person is no longer in this business. Refresh the team before making changes.');
        $wasOwner = $membership ? $membership->manages() : ($business->owner_id === $target->id && $target->hasRole('provider_admin'));
        if ($wasOwner || $role === 'provider_admin') {
            abort_unless(self::mayManage($request->user(),$business),403,'Only a business owner or platform superadmin can manage business owners.');
            if ($wasOwner && $target->status === User::ACTIVE && ($removing || $role !== 'provider_admin' || $status !== User::ACTIVE)) self::keepActiveOwner($business,$target);
        }
        if ($role === 'provider_admin' && !$removing) {
            abort_if($request->input('assigned_location_ids') || $request->input('venues') || $request->input('custom_permissions.grant') || $request->input('custom_permissions.revoke'),422,'Owners have full business access. Remove venue restrictions and custom permission overrides first.');
        }
    }

    public static function syncEdited(Business $business, User $user, string $role, int $actorId): void
    {
        $membership = BusinessMembership::where('business_id',$business->id)->where('user_id',$user->id)->lockForUpdate()->first()
            ?: new BusinessMembership(['business_id'=>$business->id,'user_id'=>$user->id]);
        $membership->fill(['role'=>$role === 'provider_admin' ? 'owner' : (BusinessMembershipSync::membershipRoleFor($role) ?: $role), 'status'=>'active','version'=>($membership->version ?? 0)+1,'created_by'=>$membership->created_by ?: $actorId,'revoked_at'=>null]);
        if ($role === 'provider_admin') {
            $membership->fill(['scope'=>'all','location_ids'=>null]);
            $user->forceFill(['owner_user_id'=>null,'assigned_location_ids'=>null,'assigned_listing_ids'=>null,'assigned_program_ids'=>null,'assigned_country_ids'=>null,'custom_permissions'=>null])->save();
            $user->venues()->detach();
            $user->syncPermissions([]);
        } else {
            $user->forceFill(['owner_user_id'=>BusinessMembershipSync::ownerUserIdForBusiness($business->id)])->save();
        }
        $membership->save();
        self::refreshPrimary($business);
    }
}
