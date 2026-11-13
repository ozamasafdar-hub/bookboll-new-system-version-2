<?php

namespace App\Services;

use App\Models\{Business, BusinessMembership, User};
use App\Support\{AccessScope, RoleGrantGuard};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB, Mail};
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class BusinessTeamInviteService
{
    public function options(Request $request, int $id)
    {
        return sendSuccess('Invitation options', \App\Support\BusinessTeamAccess::options($request->user(), Business::findOrFail($id)));
    }

    public function invite(Request $request, int $id)
    {
        $business = Business::findOrFail($id);
        $actor = $request->user();
        if (AccessScope::isProviderScoped($actor)) {
            abort_unless(AccessScope::businessId($actor) === $business->id, 403);
        } elseif (!AccessScope::isGlobal($actor)) {
            $countries = AccessScope::jurisdictionCountryIds($actor);
            abort_if($countries !== null && !in_array((int) $business->country_id, $countries, true), 403);
        }
        $data = \App\Support\BusinessTeamAccess::validate($request, \App\Support\BusinessTeamAccess::options($actor, $business));
        abort_unless(RoleGrantGuard::mayGrant($actor, $data['role']), 403);
        $user = DB::transaction(function () use ($business, $data, $actor, $request) {
            $business = Business::whereKey($business->id)->lockForUpdate()->firstOrFail();
            $data = \App\Support\BusinessTeamAccess::validate($request, \App\Support\BusinessTeamAccess::options($actor, $business));
            $ownerId = $business->owner_id ?: $business->activeMemberships()->whereIn('role', BusinessMembership::MANAGING_ROLES)->value('user_id');
            abort_if($data['role'] !== 'provider_admin' && !$ownerId,422,'Invite or assign the business owner before adding its staff.');
            if ($data['role'] === 'provider_admin' && ($business->owner_id || $business->activeMemberships()->whereIn('role', BusinessMembership::MANAGING_ROLES)->exists())) {
                abort(422, 'This business already has an owner. Choose a staff role; invitations cannot replace the owner.');
            }
            $user = User::create([
                'name' => trim($data['name']), 'email' => strtolower($data['email']),
                'password' => Str::random(48), 'status' => $data['status'] ?? User::ACTIVE,
                'custom_permissions' => $data['permissions'],
                'assigned_location_ids' => $data['location_ids'] ?: null,
                'country_id' => $business->country_id,
                'owner_user_id' => $data['role'] === 'provider_admin' ? null : $ownerId,
            ]);
            $user->assignRole($data['role']);
            BusinessMembership::create([
                'business_id' => $business->id, 'user_id' => $user->id,
                'role' => $data['role'] === 'provider_admin' ? BusinessMembership::ROLE_OWNER : $data['role'],
                'scope' => $data['location_ids'] ? 'selected' : 'all', 'location_ids' => $data['location_ids'] ?: null,
                'status' => BusinessMembership::STATUS_ACTIVE,
                'version' => 1, 'created_by' => $actor->id,
            ]);
            if ($data['role'] === 'provider_admin') $business->update(['owner_id' => $user->id]);
            return $user;
        });
        // The invite contains no shared or generated password. The normal email
        // verification/reset flow lets the new member choose their own password.
        $delivery = 'preview';
        if (!config('preview.workspace')) {
            try {
                $url = rtrim(config('app.admin_url', config('app.frontend_url', config('app.url'))), '/') . '/login';
                Mail::raw("You have been invited to manage {$business->name} on Bookboll.\n\nOpen {$url}, choose Forgot password and enter {$user->email} to receive your setup code.\n\nYour access is limited to this business.", fn ($m) => $m->to($user->email)->subject("Join {$business->name} on Bookboll"));
                $delivery = 'sent';
            } catch (\Throwable $e) { report($e); $delivery = 'failed'; }
        }
        return sendSuccess('Business team member created', ['user_id' => $user->id, 'business_id' => $business->id, 'delivery' => $delivery]);
    }
}
