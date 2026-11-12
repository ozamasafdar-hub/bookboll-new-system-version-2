<?php

namespace App\Services;

use App\Models\Business;
use App\Models\BusinessMembership;
use App\Support\AccessScope;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Businesses as entities in their own right — created, listed and staffed
 * without requiring an owner account to exist first.
 *
 * Every success payload carries `contract_version: 1`. The dashboard refuses a
 * response without it rather than treating an older API's 200 as agreement,
 * which is what stops a half-deployed backend from looking like it worked.
 */
class BusinessIdentityService
{
    public const CONTRACT_VERSION = 1;

    /**
     * What this deployment can actually do.
     *
     * `create_without_owner` is true only because ownerless businesses are
     * genuinely supported here: businesses.owner_id is nullable and membership
     * is keyed by business id. It must never be hardcoded true ahead of that —
     * the dashboard reads it to decide whether to offer the owner-free form at
     * all, and a false promise strands a half-filled draft.
     */
    public function capabilities(Request $request)
    {
        $user = $request->user();

        if (! $this->canManageBusinesses($user)) {
            return sendError('You do not have access to business administration.', [], 403);
        }

        return sendSuccess('Business capabilities', [
            'contract_version'    => self::CONTRACT_VERSION,
            'create_without_owner' => Business::ownerlessSupported(),
        ]);
    }

    /**
     * Register a business with no owner account.
     *
     * Deliberately creates no user: no placeholder login, no synthetic email,
     * no shared password. An unclaimed business is a valid record, and someone
     * is attached to it later through the assignment flow.
     */
    public function store(Request $request)
    {
        $user = $request->user();

        if (! $this->canManageBusinesses($user)) {
            return sendError('You do not have access to business administration.', [], 403);
        }

        $data = $request->validate([
            'business_name'   => 'required|string|max:160',
            'username'        => 'required|string|regex:/^[a-z0-9][a-z0-9-]{2,29}$/',
            'business_types'  => 'nullable|array',
            'business_types.*' => 'string|max:40',
            'country_id'      => 'nullable|integer|exists:countries,id',
            'status'          => ['nullable', Rule::in(Business::STATUSES)],
            'publish_website' => 'sometimes|boolean',
            // Accepted for contract symmetry; only null is supported here. An
            // owner is attached through the assignment flow, which audits it.
            'owner_user_id'   => 'nullable|in:',
            'idempotency_key' => 'required|string|max:120',
        ], [
            'owner_user_id.in' => 'This endpoint creates businesses without an owner. Assign one afterwards.',
        ]);

        $slug = BusinessService::normalizeUsername($data['username']);
        if ($slug === '' || BusinessService::isReservedUsername($slug)) {
            return sendError('That web address is reserved. Please choose another.', [
                'username' => ['That web address is reserved.'],
            ], 422);
        }

        // Country permission is the server's call, never the browser's.
        if (! empty($data['country_id']) && ! $this->mayUseCountry($user, (int) $data['country_id'])) {
            return sendError('You cannot create a business in that country.', [
                'country_id' => ['Outside your jurisdiction.'],
            ], 403);
        }

        // A retried request must return the original business, not a second one.
        $existing = Business::where('idempotency_key', $data['idempotency_key'])->first();
        if ($existing) {
            return sendSuccess('Business created', [
                'contract_version' => self::CONTRACT_VERSION,
                'business'         => $this->map($existing),
            ]);
        }

        if (Business::where('username', $slug)->orWhere('brand_subdomain', $slug)->exists()) {
            return sendError('That web address is already taken.', [
                'username' => ['That web address is already taken.'],
            ], 422);
        }

        $business = DB::transaction(function () use ($data, $slug, $user) {
            return Business::create([
                'owner_id'        => null,
                'name'            => trim($data['business_name']),
                'username'        => $slug,
                'brand_subdomain' => $slug,
                'brand_name'      => trim($data['business_name']),
                'brand_enabled'   => (bool) ($data['publish_website'] ?? false),
                'plan'            => 'free',
                'business_types'  => $data['business_types'] ?? [],
                'country_id'      => $data['country_id'] ?? null,
                'status'          => $data['status'] ?? Business::STATUS_PENDING,
                'idempotency_key' => $data['idempotency_key'],
                'created_by'      => $user->id,
            ]);
        });

        return sendSuccess('Business created', [
            'contract_version' => self::CONTRACT_VERSION,
            'business'         => $this->map($business),
        ]);
    }

    /** The row shape the dashboard groups by business id. */
    protected function map(Business $b): array
    {
        return [
            'id'             => $b->id,
            'name'           => $b->name,
            'business_name'  => $b->name,
            'username'       => $b->username,
            'brand_enabled'  => (bool) $b->brand_enabled,
            'business_types' => $b->business_types ?? [],
            'country_id'     => $b->country_id,
            'status'         => $b->status,
            // Compatibility metadata only — null means unclaimed, and callers
            // must not read it as "this is Bookboll's own business".
            'owner_user_id'  => $b->owner_id,
            'staff_count'    => BusinessMembership::where('business_id', $b->id)
                ->where('status', BusinessMembership::STATUS_ACTIVE)
                ->where('role', '!=', BusinessMembership::ROLE_OWNER)
                ->count(),
            'created_at'     => $b->created_at?->toIso8601String(),
        ];
    }

    /**
     * Superadmins globally; country admins within their jurisdiction; anyone
     * else only with the explicit capability. Providers never.
     */
    protected function canManageBusinesses($user): bool
    {
        if (! $user || AccessScope::isProviderScoped($user)) {
            return false;
        }

        return AccessScope::isGlobal($user)
            || $user->hasAnyCapability(['manage_team']);
    }

    protected function mayUseCountry($user, int $countryId): bool
    {
        if (AccessScope::isGlobal($user)) {
            return true;
        }

        $allowed = AccessScope::jurisdictionCountryIds($user);

        return $allowed === null || in_array($countryId, $allowed, true);
    }
}
