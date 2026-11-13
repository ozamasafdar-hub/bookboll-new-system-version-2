<?php

namespace App\Support;

use App\Models\{Business, BusinessMembership, User, Venue};
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** One server-owned catalogue for the invite form and its submitted grants. */
final class BusinessTeamAccess
{
    public static function options(User $actor, Business $business): array
    {
        abort_unless($actor->hasCapability('manage_team') || AccessScope::isGlobal($actor), 403);
        if (AccessScope::isProviderScoped($actor)) {
            abort_unless(AccessScope::businessId($actor) === $business->id, 403);
        } elseif (!AccessScope::isGlobal($actor)) {
            $countries = AccessScope::jurisdictionCountryIds($actor);
            abort_if($countries !== null && !in_array((int) $business->country_id, $countries, true), 403);
        }
        $owner = $business->owner_id ?: $business->activeMemberships()->whereIn('role', BusinessMembership::MANAGING_ROLES)->value('user_id');
        $roles = array_values(array_filter(BusinessMembership::ASSIGNABLE_ROLES, fn ($role) =>
            RoleGrantGuard::mayGrant($actor, $role) && ($owner ? $role !== 'provider_admin' : $role === 'provider_admin')));
        $venues = Venue::where(fn ($q) => $q->where('business_id', $business->id)
            ->when($owner, fn ($q) => $q->orWhere(fn ($legacy) => $legacy->whereNull('business_id')->where('owner_user_id', $owner))));
        $allowed = AccessScope::allowedVenueIds($actor);
        if ($allowed !== null) $venues->whereIn('id', $allowed);
        return [
            'roles' => $roles, 'has_owner' => (bool) $owner,
            'venues' => $venues->orderBy('name')->get(['id', 'name'])->toArray(),
            'all_venues_allowed' => !AccessScope::isProviderScoped($actor) || AccessScope::pinnedVenueIds($actor) === [],
            'capabilities' => array_values(array_intersect(Capabilities::ownerCaps(), $actor->capabilities())),
        ];
    }

    public static function validate(Request $request, array $options): array
    {
        $data = $request->validate([
            'name' => 'required|string|max:100', 'email' => 'required|email|max:255|unique:users,email',
            'role' => ['required', Rule::in($options['roles'])],
            'status' => ['sometimes', Rule::in([User::ACTIVE, User::INACTIVE])],
            'location_ids' => 'sometimes|array', 'location_ids.*' => ['integer', Rule::in(array_column($options['venues'], 'id'))],
            'permissions' => 'sometimes|array:grant,revoke',
            'permissions.grant' => 'sometimes|array', 'permissions.revoke' => 'sometimes|array',
            'permissions.grant.*' => ['string', Rule::in($options['capabilities'])],
            'permissions.revoke.*' => ['string', Rule::in(Capabilities::ownerCaps())],
        ]);
        $data['location_ids'] = array_values(array_unique(array_map('intval', $data['location_ids'] ?? [])));
        abort_if(!$options['all_venues_allowed'] && !$data['location_ids'], 422, 'Select the venues this person may access. Your account cannot grant whole-business access.');
        abort_if($data['role'] === 'provider_admin' && $data['location_ids'], 422, 'The owner must have whole-business access.');
        $data['permissions'] = $data['permissions'] ?? ['grant'=>[], 'revoke'=>[]];
        return $data;
    }
}
