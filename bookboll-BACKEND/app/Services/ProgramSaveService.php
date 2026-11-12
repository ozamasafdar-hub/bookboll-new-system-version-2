<?php

namespace App\Services;

use App\Models\ClassProgram;
use Illuminate\Support\Facades\Log;
use App\Support\AdminAlerts;
use App\Models\ProviderTrialAgreement;
use App\Models\Venue;
use App\Support\AccessScope;
use App\Support\CityModules;
use App\Support\PremiumPlan;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Creating and editing membership programmes, including the approval staging the
 * dashboard relies on.
 *
 * Only CONTENT (title, description, photos) needs Bookboll's approval. Operational
 * edits — price, schedule, capacity, trial — go live immediately. So a content
 * change to a programme that is already live is STAGED in `pending_content` while
 * the approved content keeps serving, and only that goes into the review queue.
 *
 * The four outcomes, mirroring the dashboard's stageMode:
 *   admin        a platform admin edited it — stays approved, no snapshot
 *   full         first submission, or a resubmit while not live — whole programme
 *                goes to review with a snapshot to roll back to
 *   content      live + content changed — operational fields written, content staged
 *   operational  live + only operational fields changed — live at once, no review
 *
 * Deciding this server-side matters: the browser must not be able to declare its
 * own edit "operational" and skip review.
 */
class ProgramSaveService
{
    public const PLATFORM_COMMISSION = 15;

    /** Fields that require approval before customers see them. */
    protected const CONTENT_KEYS = ['name', 'description', 'photo_url', 'images'];

    public function store(Request $request)
    {
        return $this->save($request, null);
    }

    public function update(Request $request, $id)
    {
        $program = ClassProgram::find($id);
        if (! $program) {
            return sendError('Program not found.', [], 404);
        }
        if (! $this->mayTouch($request, $program)) {
            return sendError('You do not have permission to edit this program.', [], 403);
        }

        return $this->save($request, $program);
    }

    /**
     * Lifecycle changes that are not content edits: activate / archive (status),
     * and Bookboll pausing or resuming a programme (eligibility).
     *
     * Pausing takes it off the storefront, so its reserved calendar slots free up
     * automatically — they are derived from the recurrence, never stored.
     */
    public function setState(Request $request, $id)
    {
        $program = ClassProgram::find($id);
        if (! $program) {
            return sendError('Program not found.', [], 404);
        }
        if (! $this->mayTouch($request, $program)) {
            return sendError('You do not have permission to change this program.', [], 403);
        }

        $data = $request->validate([
            'status'             => 'nullable|string|in:active,inactive',
            'eligibility_status' => 'nullable|string|in:eligible,paused,ineligible',
            'admin_note'         => 'nullable|string|max:2000',
        ]);

        $patch = [];

        if (array_key_exists('status', $data) && $data['status'] !== null) {
            $patch['status'] = $data['status'];
        }

        if (array_key_exists('eligibility_status', $data) && $data['eligibility_status'] !== null) {
            // Only a platform admin may pause or reinstate a provider's programme.
            if (! AccessScope::isPlatform($request->user())) {
                return sendError('Only Bookboll staff can pause or resume a program.', [], 403);
            }
            $patch['eligibility_status'] = $data['eligibility_status'];
            $patch['admin_note']         = $data['admin_note'] ?? '';
            // The programme is back to a settled state either way, so the rollback
            // snapshot is no longer needed.
            $patch['approval_status']    = 'approved';
            $patch['previous_snapshot']  = null;
        }

        if (! $patch) {
            return sendError('Nothing to change.');
        }

        $program->update($patch);

        return sendSuccess('Program updated', ['program' => $program->fresh()]);
    }

    protected function save(Request $request, ?ClassProgram $existing)
    {
        $user = $request->user();
        $data = $this->validated($request);

        $isAdmin = AccessScope::isPlatform($user);
        $isDraft = (bool) ($data['is_draft'] ?? false);

        $ownerId = $this->resolveOwner($request, $data);
        if (! $ownerId && ! $isAdmin) {
            return sendError('No business found for this account.', [], 404);
        }

        // The venue's city may have the Memberships module switched off. Only a NEW
        // programme (or one being moved to another venue) is refused — an existing
        // programme keeps working, matching how the switch stops new supply only.
        $venueId = $data['location_id'] ?? null;
        if ($venueId && (! $existing || (int) $existing->venue_id !== (int) $venueId)) {
            if ($err = CityModules::guard($venueId, CityModules::CLASSES, 'Membership programs')) {
                return $err;
            }
        }

        // Selling a programme only on your own site, off the Bookboll Marketplace, is
        // the `own_site_only` Premium feature (ClassesSubscriptions.vue gates the
        // toggle). Putting it back on the marketplace is always free.
        if (array_key_exists('show_on_marketplace', $data)
            && filter_var($data['show_on_marketplace'], FILTER_VALIDATE_BOOLEAN) === false
            && $existing?->show_on_marketplace !== false
            && ! PremiumPlan::entitled($user)) {
            return PremiumPlan::denied('own_site_only');
        }

        $venue = $venueId ? Venue::find($venueId) : null;
        $defaultRate = app(\App\Support\MarketSettings::class)->rate('program', $venue?->country_id, $venue?->city_id);
        // Providers cannot set their own platform fee; country/city defaults apply.
        // Existing agreements are retained unless platform staff explicitly edit them.
        if (! $isAdmin || ! isset($data['commission_percentage'])) {
            $data['commission_percentage'] = $existing?->commission_percentage ?? $defaultRate;
        }
        $base = $this->buildAttributes($data, $ownerId);

        // A draft is never live and no money can move, so it skips review entirely.
        if ($isDraft) {
            $base['status']   = 'inactive';
            $base['is_draft'] = true;
        } else {
            $base['is_draft'] = false;
        }

        $content = [
            'name'        => $base['name'] ?? '',
            'description' => $base['description'] ?? '',
            'photo_url'   => $base['photo_url'] ?? '',
            'images'      => $base['images'] ?? [],
        ];

        // Captured before the write so a re-save of something already queued does
        // not raise a second alert.
        $wasPending = ($existing?->approval_status ?? null) === 'pending';
        $entersReview = false;

        $result = DB::transaction(function () use ($request, $existing, $base, $content, $isAdmin, $isDraft, $data, $ownerId, &$entersReview) {
            [$program, $stageMode] = $existing
                ? $this->applyUpdate($existing, $base, $content, $isAdmin, $isDraft)
                : $this->applyCreate($base, $isAdmin, $isDraft);

            // Staged content only survives on the 'content' path; every other
            // outcome clears it so a stale stage can't resurface.
            $program->forceFill([
                'pending_content' => $stageMode === 'content' ? $content : null,
            ])->save();

            // Submission stamp — only when something actually entered the queue.
            if (! $isAdmin && in_array($stageMode, ['full', 'content'], true)) {
                $program->forceFill([
                    'submitted_at'      => now(),
                    'submitted_by_name' => $request->user()?->name ?: '',
                ])->save();
                $entersReview = true;
            }

            $this->recordTrialAgreement($program, $data, $ownerId);

            return sendSuccess($existing ? 'Program updated' : 'Program created', [
                'program'    => $program->fresh(),
                'stage_mode' => $stageMode,
            ]);
        });

        // After the commit, never inside it: a rolled-back save must not leave staff
        // chasing a programme that was never written. Nothing here may break the save.
        if ($entersReview && ! $wasPending) {
            try {
                $program = ClassProgram::find($existing?->id ?? ($result->getData(true)['data']['program']['id'] ?? null));
                $venue = $program?->venue;
                AdminAlerts::toPlatform(
                    'approval',
                    'info',
                    ($existing ? 'Programme resubmitted for review — ' : 'New programme awaiting review — ')
                        .($program?->name ?: 'Untitled'),
                    $venue?->name ?: '',
                    '/approvals',
                    $venue?->country_id ? (int) $venue->country_id : null,
                );
            } catch (\Throwable $e) {
                Log::warning('programme approval alert failed: '.$e->getMessage());
            }
        }

        return $result;
    }

    /** @return array{0: ClassProgram, 1: string} */
    protected function applyCreate(array $base, bool $isAdmin, bool $isDraft): array
    {
        $program = ClassProgram::create(array_merge($base, [
            'approval_status'    => $isDraft ? 'draft' : ($isAdmin ? 'approved' : 'pending'),
            'eligibility_status' => 'eligible',
            'admin_note'         => '',
            'previous_snapshot'  => null,
        ]));

        return [$program, $isDraft ? 'draft' : ($isAdmin ? 'admin' : 'full')];
    }

    /** @return array{0: ClassProgram, 1: string} */
    protected function applyUpdate(ClassProgram $existing, array $base, array $content, bool $isAdmin, bool $isDraft): array
    {
        if ($isDraft) {
            $existing->update(array_merge($base, [
                'approval_status' => $existing->approval_status ?: 'approved',
            ]));

            return [$existing->fresh(), 'draft'];
        }

        if ($isAdmin) {
            $existing->update(array_merge($base, [
                'approval_status'    => 'approved',
                'eligibility_status' => $existing->eligibility_status ?: 'eligible',
            ]));

            return [$existing->fresh(), 'admin'];
        }

        $isPaused   = $existing->eligibility_status === 'paused';
        $notLiveYet = ($existing->approval_status ?: 'approved') !== 'approved' || $isPaused;

        if ($notLiveYet) {
            // Offline anyway — write the content live and (re)submit the whole thing.
            $patch = array_merge($base, [
                'approval_status'   => 'pending',
                'previous_snapshot' => $existing->previous_snapshot ?: $this->snapshotOf($existing),
            ]);
            if ($isPaused) {
                $patch['admin_note'] = $existing->admin_note;
            }
            $existing->update($patch);

            return [$existing->fresh(), 'full'];
        }

        $liveApproved = [
            'name'        => $existing->name ?: '',
            'description' => $existing->description ?: '',
            'photo_url'   => $existing->photo_url ?: '',
            'images'      => is_array($existing->images) ? $existing->images : [],
        ];

        // Either way only the operational fields are written; the difference is
        // whether the changed content gets staged for review.
        $operational = $base;
        foreach (self::CONTENT_KEYS as $k) {
            unset($operational[$k]);
        }
        $existing->update($operational);

        $changed = $this->contentChanged($content, $liveApproved);

        return [$existing->fresh(), $changed ? 'content' : 'operational'];
    }

    protected function contentChanged(array $next, array $live): bool
    {
        foreach (self::CONTENT_KEYS as $k) {
            $a = $next[$k] ?? null;
            $b = $live[$k] ?? null;
            if (is_array($a) || is_array($b)) {
                if (array_values((array) $a) !== array_values((array) $b)) {
                    return true;
                }

                continue;
            }
            if (trim((string) $a) !== trim((string) $b)) {
                return true;
            }
        }

        return false;
    }

    protected function snapshotOf(ClassProgram $p): array
    {
        return [
            'name'        => $p->name,
            'description' => $p->description,
            'photo_url'   => $p->photo_url,
            'images'      => is_array($p->images) ? $p->images : [],
        ];
    }

    /**
     * Stamp the provider's acceptance of the trial terms, once per programme.
     * The commission and trial length are frozen at acceptance.
     */
    protected function recordTrialAgreement(ClassProgram $program, array $data, ?int $ownerId): void
    {
        if (empty($data['accept_trial_terms'])) {
            return;
        }
        if (ProviderTrialAgreement::where('program_id', $program->id)->exists()) {
            return;
        }

        ProviderTrialAgreement::create([
            'program_id'               => $program->id,
            'owner_user_id'            => $ownerId,
            'terms_version'            => $data['terms_version'] ?? null,
            'commission_at_acceptance' => $program->commission_percentage,
            'trial_days_at_acceptance' => (int) ($program->free_trial_days ?? 0),
        ]);
    }

    /** Column values from the validated form, with the gym rules applied. */
    protected function buildAttributes(array $d, ?int $ownerId): array
    {
        $isGym = ($d['category'] ?? '') === 'gym';

        $attrs = [
            'owner_user_id'            => $ownerId ? (string) $ownerId : null,
            'mapped_owner_user_id'     => $ownerId,
            'venue_id'                 => $d['location_id'] ?? null,
            'location_id'              => $d['location_id'] ?? null,
            'name'                     => $d['name'],
            'description'              => $d['description'] ?? '',
            'sport_category'           => $d['sport_category'] ?? '',
            'coach_name'               => $isGym ? '' : ($d['coach_name'] ?? ''),
            'monthly_price'            => (float) ($d['monthly_price'] ?? 0),
            'currency'                 => $d['currency'] ?? '',
            'billing_interval'         => $d['billing_interval'] ?? 'monthly',
            'sessions_per_week'        => $isGym ? 0 : (int) ($d['sessions_per_week'] ?? 1),
            'session_duration_minutes' => $isGym ? 0 : (int) ($d['session_duration_minutes'] ?? 60),
            'day_of_week_mask'         => $isGym ? [0, 1, 2, 3, 4, 5, 6] : ($d['day_of_week_mask'] ?? []),
            'session_time'             => $isGym ? null : ($d['session_time'] ?? '18:00'),
            'capacity'                 => $isGym ? 0 : (int) ($d['capacity'] ?? 12),
            'commission_percentage'    => (float) ($d['commission_percentage'] ?? self::PLATFORM_COMMISSION),
            'free_trial_days'          => (int) ($d['free_trial_days'] ?? 0),
            'free_trial_enabled'       => (bool) ($d['free_trial_enabled'] ?? false),
            'program_type'             => $isGym ? 'ongoing' : ($d['program_type'] ?? 'ongoing'),
            'duration_weeks'           => (! $isGym && ($d['program_type'] ?? '') === 'fixed_duration')
                ? ((int) ($d['duration_weeks'] ?? 0) ?: null) : null,
            'start_date'               => $isGym ? null : ($d['start_date'] ?? null),
            'end_date'                 => $isGym ? null : ($d['end_date'] ?? null),
            'photo_url'                => $d['photo_url'] ?? (($d['images'][0] ?? '') ?: ''),
            'images'                   => $d['images'] ?? [],
        ];

        foreach ([
            'status', 'color', 'category', 'audience', 'gender_policy',
            'show_on_marketplace', 'show_on_provider_site',
            'free_trial_sessions', 'discount_months', 'bonus_months',
            'signup_form', 'joining_fee', 'listing_id', 'listing_ids',
        ] as $optional) {
            if (array_key_exists($optional, $d)) {
                $attrs[$optional] = $d[$optional];
            }
        }

        if ($isGym) {
            $attrs['gender_policy'] = $attrs['gender_policy'] ?? null;
        }

        return $attrs;
    }

    protected function validated(Request $request): array
    {
        return $request->validate([
            'name'                     => 'required|string|max:150',
            'location_id'              => 'nullable|integer|exists:venues,id',
            'owner_user_id'            => 'nullable',
            'description'              => 'nullable|string',
            'sport_category'           => 'nullable|string|max:100',
            'coach_name'               => 'nullable|string|max:150',
            'monthly_price'            => 'nullable|numeric|min:0',
            'currency'                 => 'nullable|string|max:10',
            'billing_interval'         => 'nullable|string|max:20',
            'sessions_per_week'        => 'nullable|integer|min:0',
            'session_duration_minutes' => 'nullable|integer|min:0',
            'day_of_week_mask'         => 'nullable|array',
            'session_time'             => 'nullable|string|max:8',
            'capacity'                 => 'nullable|integer|min:0',
            'commission_percentage'    => 'nullable|numeric|min:0|max:100',
            'free_trial_days'          => 'nullable|integer|min:0',
            'free_trial_enabled'       => 'nullable|boolean',
            'free_trial_sessions'      => 'nullable|integer|min:0',
            'discount_months'          => 'nullable|integer|min:0',
            'bonus_months'             => 'nullable|integer|min:0',
            'joining_fee'              => 'nullable|numeric|min:0',
            'program_type'             => 'nullable|string|max:30',
            'duration_weeks'           => 'nullable|integer|min:0',
            'start_date'               => 'nullable|date',
            'end_date'                 => 'nullable|date',
            'photo_url'                => 'nullable|string|max:2048',
            'images'                   => 'nullable|array',
            'color'                    => 'nullable|string|max:20',
            'category'                 => 'nullable|string|max:40',
            'audience'                 => 'nullable|string|max:40',
            'gender_policy'            => 'nullable|string|max:40',
            'show_on_marketplace'      => 'nullable|boolean',
            'show_on_provider_site'    => 'nullable|boolean',
            'signup_form'              => 'nullable|array',
            'status'                   => 'nullable|string|max:20',
            'listing_id'               => 'nullable|integer',
            'listing_ids'              => 'nullable|array',
            'is_draft'                 => 'nullable|boolean',
            'accept_trial_terms'       => 'nullable|boolean',
            'terms_version'            => 'nullable|string|max:40',
        ]);
    }

    /**
     * A provider always writes to their own business; a platform admin may act
     * for the venue's owner (or an explicitly named one).
     */
    protected function resolveOwner(Request $request, array $data): ?int
    {
        $user = $request->user();

        $own = AccessScope::ownerUserId($user);
        if ($own !== null) {
            return $own;
        }

        $asked = $data['owner_user_id'] ?? null;
        if ($asked && ctype_digit((string) $asked)) {
            return (int) $asked;
        }

        if (! empty($data['location_id'])) {
            $venueOwner = Venue::whereKey($data['location_id'])->value('owner_user_id');
            if ($venueOwner) {
                return (int) $venueOwner;
            }
        }

        return null;
    }

    protected function mayTouch(Request $request, ClassProgram $program): bool
    {
        $user = $request->user();
        if (! $user) {
            return false;
        }
        if (AccessScope::isPlatform($user)) {
            return true;
        }

        $own = AccessScope::ownerUserId($user);

        return $own !== null && (
            (int) $program->mapped_owner_user_id === $own
            || (string) $program->owner_user_id === (string) $own
        );
    }
}
