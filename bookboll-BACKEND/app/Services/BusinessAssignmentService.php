<?php

namespace App\Services;

use App\Models\Business;
use App\Models\BusinessAssignmentPreview;
use App\Models\BusinessMembership;
use App\Models\User;
use App\Models\Venue;
use App\Support\AccessScope;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Moving an account into a business: options, a reviewed preview, then commit.
 *
 * Three endpoints rather than one because this is destructive. The move strips
 * whatever platform access the account had — roles, jurisdiction, venue,
 * listing and program grants, custom permission overrides — and replaces it
 * with a single business membership. An admin sees exactly what disappears
 * before anything happens.
 *
 * The commit is atomic and re-checked: a token proves which move was reviewed,
 * everything is reauthorised and recomputed inside the transaction, and a stale
 * or replayed token is refused rather than applied on an old review.
 */
class BusinessAssignmentService
{
    public const CONTRACT_VERSION = 1;

    /** A review is good for fifteen minutes. */
    public const PREVIEW_TTL_MINUTES = 15;

    /* ─────────────────────────────── options ─────────────────────────────── */

    public function options(Request $request, int $businessId)
    {
        [$business, $actor, $error] = $this->resolve($request, $businessId);
        if ($error) {
            return $error;
        }

        $roles = BusinessMembership::ASSIGNABLE_ROLES;

        // The owner seat is not assignable while it is taken. Offering it would
        // promise something the one-active-owner constraint will refuse.
        if ($this->hasOwner($business)) {
            $roles = array_values(array_diff($roles, ['provider_admin']));
        }

        return sendSuccess('Assignment options', [
            'contract_version' => self::CONTRACT_VERSION,
            'can_assign'       => true,
            'roles'            => array_values($roles),
            'has_owner'        => $this->hasOwner($business),
            'candidates'       => $this->candidates($actor, trim((string) $request->input('search', ''))),
            'venues'           => $this->assignableVenues($business),
        ]);
    }

    /**
     * Accounts this actor may move — the PLATFORM TEAM, not every user.
     *
     * This filtered on "active, not already in a business" alone, which on real
     * data is 16,074 people: the entire customer base. Only 8 of them held a
     * platform role, and a 200-row cap sorted by name meant the admins the
     * screen exists to move were not in the list at all. So the picker offered
     * thousands of customers and none of the colleagues.
     *
     * The definition here is the same one the Team page's "Platform Team" tab
     * uses (UserService::list, scope=platform): a platform role, or a
     * provider-team / legacy role held with no owner — someone on Bookboll's own
     * side rather than inside a business.
     *
     * `search` is applied in SQL so a large team is narrowed by the server
     * rather than by whatever happened to fit in the first page.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function candidates(User $actor, string $search = ''): array
    {
        $platformRoles = config('roles.platform_team_roles', ['super_admin', 'country_admin', 'platform_staff', 'admin']);
        $teamRoles     = config('roles.provider_team_roles', []);

        $query = User::query()
            ->with('roles:id,name')
            ->where('status', User::ACTIVE)
            ->where('id', '!=', $actor->id)
            ->whereDoesntHave('businessMemberships', function ($q) {
                $q->where('status', BusinessMembership::STATUS_ACTIVE);
            })
            ->where(function ($q) use ($platformRoles, $teamRoles) {
                $q->whereHas('roles', fn ($r) => $r->whereIn('name', $platformRoles))
                    ->orWhere(fn ($o) => $o
                        ->whereNull('owner_user_id')
                        ->whereHas('roles', fn ($r) => $r->whereIn('name', $teamRoles)));
            });

        if ($search !== '') {
            $like = '%'.$search.'%';
            $query->where(fn ($q) => $q->where('name', 'LIKE', $like)->orWhere('email', 'LIKE', $like));
        }

        if (! AccessScope::isGlobal($actor)) {
            $countries = AccessScope::jurisdictionCountryIds($actor);
            if (is_array($countries)) {
                $query->whereIn('country_id', $countries ?: [0]);
            }
        }

        return $query->orderBy('name')->limit(200)
            ->get(['id', 'name', 'email', 'country_id', 'last_login_at'])
            ->map(function (User $u) {
                $roles = $u->roles->pluck('name')->all();

                return [
                    'id'    => (string) $u->id,
                    'name'  => $u->name ?: '',
                    'email' => $u->email ?: '',
                    // Enough for the picker to show WHO this is — the old list
                    // was name and email only, which on similar-looking test
                    // accounts told the admin nothing about who they were moving.
                    'role'       => $roles[0] ?? null,
                    'roles'      => $roles,
                    'venues'     => count(AccessScope::pinnedVenueIds($u)),
                    'last_login' => $u->last_login_at?->toIso8601String(),
                ];
            })->all();
    }

    /** @return array<int, array{id:string,name:string}> */
    protected function assignableVenues(Business $business): array
    {
        $ownerId = $business->owner_id;

        // Both links, for the same reason AccessScope reads both: a business
        // created through BusinessIdentityService has owner_id null and owns its
        // venues by business_id alone. Keying on the owner only, this returned
        // nothing for such a business — so its venue picker came up empty and
        // every selected-scope assignment was refused as "not belonging".
        return Venue::query()
            ->where(function ($q) use ($business, $ownerId) {
                if ($ownerId) {
                    $q->orWhere(fn ($legacy) => $legacy->whereNull('business_id')->where('owner_user_id', $ownerId));
                }
                $q->orWhere('business_id', $business->id);
            })
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (Venue $v) => ['id' => (string) $v->id, 'name' => $v->name ?: ''])
            ->all();
    }

    /* ─────────────────────────────── preview ─────────────────────────────── */

    public function preview(Request $request, int $businessId)
    {
        [$business, $actor, $error] = $this->resolve($request, $businessId);
        if ($error) {
            return $error;
        }

        $data = $request->validate([
            'user_id'        => 'required|integer|exists:users,id',
            'role'           => ['required', 'string', Rule::in(BusinessMembership::ASSIGNABLE_ROLES)],
            'scope'          => ['required', Rule::in(['all', 'selected'])],
            'location_ids'   => 'nullable|array',
            'location_ids.*' => 'integer',
        ]);

        $target = User::find($data['user_id']);
        $assessment = $this->assess($business, $actor, $target, $data);

        $preview = BusinessAssignmentPreview::create([
            'token'          => 'bap_'.Str::random(56),
            'business_id'    => $business->id,
            'actor_user_id'  => $actor->id,
            'target_user_id' => $target->id,
            'role'           => $data['role'],
            'scope'          => $data['scope'],
            'location_ids'   => $data['scope'] === 'selected' ? array_values($data['location_ids'] ?? []) : null,
            'warnings'       => $assessment['warnings'],
            'blockers'       => $assessment['blockers'],
            'state_hash'     => $this->stateHash($business, $target),
            'expires_at'     => now()->addMinutes(self::PREVIEW_TTL_MINUTES),
        ]);

        return sendSuccess('Assignment preview', [
            'contract_version' => self::CONTRACT_VERSION,
            'preview_token'    => $preview->token,
            'expires_at'       => $preview->expires_at->utc()->toIso8601String(),
            'business_id'      => (string) $business->id,
            'user_id'          => (string) $target->id,
            'user_name'        => $target->name ?: '',
            'user_email'       => $target->email ?: '',
            'role'             => $data['role'],
            'scope_label'      => $this->scopeLabel($data, $business),
            'warnings'         => $assessment['warnings'],
            'blockers'         => $assessment['blockers'],
        ]);
    }

    /**
     * What this move would do, and what stops it.
     *
     * Warnings are things the admin must see happen; blockers prevent the
     * commit entirely. Both are recomputed at commit, so a review cannot
     * authorise a move whose circumstances have since changed.
     *
     * @return array{warnings: array<int,string>, blockers: array<int,string>}
     */
    protected function assess(Business $business, User $actor, ?User $target, array $data): array
    {
        $warnings = [];
        $blockers = [];

        if ($data['role'] === 'provider_admin') {
            if ($this->hasOwner($business)) $blockers[] = 'This business already has an owner. Choose a staff role; this move cannot replace the owner.';
            if ($data['scope'] !== 'all') $blockers[] = 'The business owner must have access to the whole business.';
        }

        if (! $target) {
            return ['warnings' => [], 'blockers' => ['That account no longer exists.']];
        }

        // Protecting the actor: nobody strips their own platform access, which
        // would lock them out mid-operation.
        if ((int) $target->id === (int) $actor->id) {
            $blockers[] = 'You cannot assign your own account to a business.';
        }

        if ($target->status !== User::ACTIVE) {
            $blockers[] = 'That account is not active.';
        }

        $existing = BusinessMembership::where('user_id', $target->id)
            ->where('status', BusinessMembership::STATUS_ACTIVE)->first();
        if ($existing) {
            $blockers[] = $existing->business_id === $business->id
                ? 'That account already belongs to this business.'
                : 'That account already belongs to another business. Remove it there first.';
        }

        // The last active superadmin must not be moved out of the platform.
        if ($target->hasRole('super_admin') && $this->activeSuperAdmins() <= 1) {
            $blockers[] = 'This is the last active super admin and cannot be moved.';
        }

        if ($data['scope'] === 'selected' && empty($data['location_ids'])) {
            $blockers[] = 'Choose at least one venue for a limited scope.';
        }

        if ($data['scope'] === 'selected') {
            // Not ->map('intval'): Collection::map hands the callback ($value,
            // $key), and intval's second parameter is the NUMERIC BASE. Every
            // element after the first was parsed in the base of its own index —
            // index 1 is an invalid base and yields 0, index 2 reads the id as
            // binary — so a business with more than one venue had a corrupt
            // allow-list and refused its own venues.
            $allowed = collect($this->assignableVenues($business))
                ->pluck('id')->map(fn ($id) => (int) $id)->all();
            $foreign = array_diff(array_map('intval', $data['location_ids'] ?? []), $allowed);
            if ($foreign) {
                $blockers[] = 'Some selected venues do not belong to this business.';
            }
        }

        // Everything below is lost by the move. readmeAlamgir: "Return
        // inherited permissions that will be removed in warnings."
        foreach ($target->getRoleNames() as $role) {
            $warnings[] = "Platform role \"{$role}\" will be removed.";
        }
        if (! empty($target->assigned_country_ids)) {
            $warnings[] = 'Assigned countries will be cleared.';
        }
        foreach ([
            'assigned_location_ids' => 'venue',
            'assigned_listing_ids'  => 'listing',
            'assigned_program_ids'  => 'programme',
        ] as $column => $label) {
            if (! empty($target->{$column})) {
                $warnings[] = "Existing {$label} assignments will be cleared.";
            }
        }
        if (! empty($target->custom_permissions)) {
            $warnings[] = 'Custom platform permissions will be removed.';
        }
        $warnings[] = 'Active sessions will be signed out so the new access takes effect.';

        return ['warnings' => array_values($warnings), 'blockers' => array_values($blockers)];
    }

    protected function activeSuperAdmins(): int
    {
        return User::role('super_admin')->where('status', User::ACTIVE)->count();
    }

    protected function hasOwner(Business $business): bool
    {
        return (bool) $business->owner_id || $business->activeMemberships()->whereIn('role', BusinessMembership::MANAGING_ROLES)->exists();
    }

    protected function scopeLabel(array $data, Business $business): string
    {
        if ($data['scope'] !== 'selected') {
            return 'All venues in ' . ($business->name ?: 'this business');
        }

        $n = count($data['location_ids'] ?? []);

        return $n === 1 ? '1 selected venue' : "{$n} selected venues";
    }

    /**
     * A fingerprint of everything the review depended on. Recomputed at commit:
     * if the target's roles, status or business have moved since, the token no
     * longer describes the move being asked for.
     */
    protected function stateHash(Business $business, User $target): string
    {
        return hash('sha256', json_encode([
            'business'   => $business->id,
            'owner'      => $business->owner_id,
            'owner_memberships' => $business->activeMemberships()->whereIn('role', BusinessMembership::MANAGING_ROLES)->orderBy('id')->pluck('user_id')->all(),
            'target'     => $target->id,
            'status'     => $target->status,
            'roles'      => $target->getRoleNames()->sort()->values()->all(),
            'membership' => BusinessMembership::where('user_id', $target->id)
                ->where('status', BusinessMembership::STATUS_ACTIVE)->value('business_id'),
        ]));
    }

    /* ──────────────────────────────── commit ─────────────────────────────── */

    public function assign(Request $request, int $businessId)
    {
        [$business, $actor, $error] = $this->resolve($request, $businessId);
        if ($error) {
            return $error;
        }

        $data = $request->validate([
            'preview_token'   => 'required|string|max:80',
            'idempotency_key' => 'required|string|max:120',
        ]);

        // A retry of a completed move returns what it produced the first time.
        $done = BusinessAssignmentPreview::where('idempotency_key', $data['idempotency_key'])
            ->whereNotNull('membership_id')->first();
        if ($done) {
            if ((int) $done->business_id !== $businessId || (int) $done->actor_user_id !== (int) $actor->id || $done->token !== $data['preview_token']) {
                return sendError('That request key belongs to a different assignment.', [], 409);
            }
            return sendSuccess('Account assigned', [
                'contract_version' => self::CONTRACT_VERSION,
                'membership'       => $this->mapMembership(BusinessMembership::find($done->membership_id)),
            ]);
        }

        return DB::transaction(function () use ($data, $business, $actor) {
            $business = Business::whereKey($business->id)->lockForUpdate()->firstOrFail();
            // Lock the review row so two commits cannot consume it at once.
            $preview = BusinessAssignmentPreview::where('token', $data['preview_token'])
                ->lockForUpdate()->first();

            if (! $preview) {
                return sendError('That review could not be found. Please review the change again.', [], 409);
            }
            // Replay across businesses, actors or targets is refused: the token
            // is only valid for the exact move it was minted for.
            if ((int) $preview->business_id !== (int) $business->id
                || (int) $preview->actor_user_id !== (int) $actor->id) {
                return sendError('That review does not belong to this request.', [], 409);
            }
            if ($preview->isConsumed()) {
                return sendError('That review has already been used. Please review the change again.', [], 409);
            }
            if ($preview->isExpired()) {
                return sendError('That review has expired. Please review the change again.', [], 409);
            }

            $target = User::whereKey($preview->target_user_id)->lockForUpdate()->first();
            if (! $target) {
                return sendError('That account no longer exists.', [], 409);
            }

            // Reauthorise and recompute rather than trusting the review.
            if ($this->stateHash($business, $target) !== $preview->state_hash) {
                return sendError('This account has changed since it was reviewed. Please review it again.', [], 409);
            }

            $recheck = $this->assess($business, $actor, $target, [
                'role'         => $preview->role,
                'scope'        => $preview->scope,
                'location_ids' => $preview->location_ids ?? [],
            ]);
            if ($recheck['blockers']) {
                return sendError($recheck['blockers'][0], ['blockers' => $recheck['blockers']], 409);
            }

            $before = $this->snapshot($target);

            // The move. Platform access goes first so nothing can be retained
            // by an additive merge, then the single business membership is
            // attached.
            //
            // The membership row records the role, but Spatie is what every gate
            // actually reads — hasAnyRole, getAllPermissions, Capabilities and
            // AccessScope all go through it, and nothing authorises off
            // business_memberships.role. Syncing to exactly the granted role is
            // still not additive, so the comment above holds; clearing to []
            // instead left the account with NO role, which the dashboard's own
            // role gate then turned away after the password had verified.
            $target->syncRoles([$preview->role]);
            $target->forceFill([
                'owner_user_id'         => $preview->role === 'provider_admin' ? null : $business->owner_id,
                'assigned_country_ids'  => null,
                'assigned_location_ids' => null,
                'assigned_listing_ids'  => null,
                'assigned_program_ids'  => null,
                'custom_permissions'    => null,
            ])->save();
            $target->venues()->detach();

            $membership = BusinessMembership::create([
                'business_id'  => $business->id,
                'user_id'      => $target->id,
                'role'         => $preview->role === 'provider_admin' ? BusinessMembership::ROLE_OWNER : $preview->role,
                'scope'        => $preview->scope,
                'location_ids' => $preview->location_ids,
                'status'       => BusinessMembership::STATUS_ACTIVE,
                'version'      => 1,
                'created_by'   => $actor->id,
            ]);
            if ($preview->role === 'provider_admin') $business->update(['owner_id' => $target->id]);

            // Stale tokens would otherwise keep the old platform privileges
            // alive until they expired.
            $target->tokens()->delete();

            $preview->forceFill([
                'consumed_at'     => now(),
                'idempotency_key' => $data['idempotency_key'],
                'membership_id'   => $membership->id,
            ])->save();

            // Before and after, so a move can be investigated later.
            \App\Models\AuditLog::create([
                'action'      => 'business.assignment',
                'resource'    => 'business_membership',
                'resource_id' => (string) $membership->id,
                'actor_id'    => $actor->id,
                'actor_email' => $actor->email,
                'timestamp'   => now(),
                // `details` is a plain string column, not a cast array.
                'details'     => mb_substr((string) json_encode([
                    'business_id' => $business->id,
                    'user_id'     => $target->id,
                    'role'        => $preview->role,
                    'scope'       => $preview->scope,
                    'before'      => $before,
                    'after'       => $this->snapshot($target->fresh()),
                ]), 0, 2000),
            ]);

            return sendSuccess('Account assigned', [
                'contract_version' => self::CONTRACT_VERSION,
                'membership'       => $this->mapMembership($membership),
            ]);
        });
    }

    /** Before/after record for the audit trail. */
    protected function snapshot(User $user): array
    {
        return [
            'roles'                 => $user->getRoleNames()->values()->all(),
            'owner_user_id'         => $user->owner_user_id,
            'assigned_country_ids'  => $user->assigned_country_ids,
            'assigned_location_ids' => $user->assigned_location_ids,
            'custom_permissions'    => $user->custom_permissions,
        ];
    }

    protected function mapMembership(?BusinessMembership $m): ?array
    {
        return $m ? [
            'id'           => (string) $m->id,
            'business_id'  => (string) $m->business_id,
            'user_id'      => (string) $m->user_id,
            'role'         => $m->role,
            'scope'        => $m->scope,
            'location_ids' => $m->location_ids ?? [],
            'status'       => $m->status,
            'version'      => (int) $m->version,
        ] : null;
    }

    /* ─────────────────────────────── shared ──────────────────────────────── */

    /** @return array{0: ?Business, 1: ?User, 2: mixed} */
    /**
     * The actor half of resolve(), on its own.
     *
     * `candidates()` is scoped by the ACTOR, not the business, so the Add
     * business form can offer the same list before a business exists. Sharing
     * this check is the point: a second copy would be a second answer to "who
     * may move accounts", and the two would drift.
     *
     * @return array{0: ?User, 1: ?\Illuminate\Http\JsonResponse}
     */
    protected function resolveActor(Request $request): array
    {
        $actor = $request->user();

        if (! $actor || AccessScope::isProviderScoped($actor)) {
            return [null, sendError('You do not have access to business administration.', [], 403)];
        }
        if (! AccessScope::isGlobal($actor) && ! $actor->hasAnyCapability(['manage_team'])) {
            return [null, sendError('You do not have access to business administration.', [], 403)];
        }

        return [$actor, null];
    }

    /**
     * Accounts assignable to a business that does not exist yet.
     *
     * The Add business form offers an owner at creation time, but creation
     * itself still refuses one (BusinessIdentityService::store) — the account is
     * attached straight afterwards through preview + assign, so the move is
     * audited, reviewable and reversible in exactly one place. This endpoint
     * only fills the picker.
     */
    public function newBusinessCandidates(Request $request)
    {
        [$actor, $error] = $this->resolveActor($request);
        if ($error) {
            return $error;
        }

        return sendSuccess('Assignment candidates', [
            'contract_version' => self::CONTRACT_VERSION,
            'roles'            => array_values(BusinessMembership::ASSIGNABLE_ROLES),
            'candidates'       => $this->candidates($actor, trim((string) $request->input('search', ''))),
        ]);
    }

    protected function resolve(Request $request, int $businessId): array
    {
        [$actor, $error] = $this->resolveActor($request);
        if ($error) {
            return [null, null, $error];
        }

        $business = Business::find($businessId);
        if (! $business) {
            return [null, null, sendError('Business not found.', [], 404)];
        }

        // A country admin may only touch businesses in their jurisdiction.
        if (! AccessScope::isGlobal($actor)) {
            $countries = AccessScope::jurisdictionCountryIds($actor);
            if (is_array($countries) && $business->country_id && ! in_array((int) $business->country_id, $countries, true)) {
                return [null, null, sendError('That business is outside your jurisdiction.', [], 403)];
            }
        }

        return [$business, $actor, null];
    }
}
