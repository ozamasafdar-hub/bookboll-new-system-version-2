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
 * Public content (including contact phone and schedule information) needs approval.
 * Placement and advertised pricing changes are validated separately. So a content
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
    protected const CONTENT_KEYS = ['name', 'description', 'photo_url', 'images', 'contact_phone', 'schedule_description'];

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
     * Pausing removes public visibility. Contact listings never reserve calendar slots.
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

        abort_if($existing && (int) $existing->venue_id !== (int) $venueId && $existing->listingSubscriptions()->whereNotIn('status', ['canceled','incomplete_expired'])->exists(), 409, 'Stop the listing subscription before moving this listing to another venue.');
        $venue = $venueId ? Venue::findOrFail($venueId) : null;
        $allowed = AccessScope::allowedVenueIds($user);
        abort_if($venue && $allowed !== null && !in_array((int) $venue->id, $allowed, true), 403, 'This venue is outside your business or jurisdiction.');
        // Ownership follows the venue, never an arbitrary browser-supplied owner.
        $ownerId = $venue ? ((int) $venue->owner_user_id ?: null) : $ownerId;
        $candidate = $existing ? clone $existing : new ClassProgram();
        $candidate->fill(['venue_id' => $venue?->id, 'mapped_owner_user_id' => $ownerId, 'owner_user_id' => $ownerId]);
        $candidate->setRelation('venue', $venue);
        if (!empty($data['show_on_provider_site']) && !\App\Support\MembershipListingPolicy::websiteEntitled($candidate)) {
            return sendError('Premium is required to show membership listings on this business’s website.', ['feature' => 'membership_website'], 403);
        }
        $data['contact_phone'] = trim($data['contact_phone'] ?? $venue?->phone ?? '');
        if (!$isDraft) {
            abort_unless($venue, 422, 'Choose a venue before publishing.');
            $candidate->contact_phone = $data['contact_phone'];
            abort_unless(\App\Support\MembershipListingPolicy::phoneReady($candidate), 422, 'Enter a valid academy contact phone number.');
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
            'contact_phone' => $base['contact_phone'] ?? '',
            'schedule_description' => $base['schedule_description'] ?? '',
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
            'contact_phone' => $existing->contact_phone ?: '',
            'schedule_description' => $existing->schedule_description ?: '',
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
            'contact_phone' => $p->contact_phone,
            'schedule_description' => $p->schedule_description,
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
        return [
            'owner_user_id' => $ownerId ? (string) $ownerId : null, 'mapped_owner_user_id' => $ownerId,
            'venue_id' => $d['location_id'] ?? null, 'location_id' => $d['location_id'] ?? null,
            'name' => $d['name'], 'description' => $d['description'] ?? '',
            'contact_phone' => $d['contact_phone'] ?? '', 'schedule_description' => $d['schedule_description'] ?? '',
            'sport_category' => $d['sport_category'] ?? '', 'coach_name' => $d['coach_name'] ?? '',
            // Advertised academy price is informational; it never creates a charge.
            'monthly_price' => $d['monthly_price'] ?? 0, 'currency' => $d['currency'] ?? '',
            'billing_interval' => $d['billing_interval'] ?? 'monthly',
            'photo_url' => $d['photo_url'] ?? ($d['images'][0] ?? ''), 'images' => $d['images'] ?? [],
            'status' => $d['status'] ?? 'active', 'category' => $d['category'] ?? 'academy',
            'audience' => $d['audience'] ?? 'all', 'gender_policy' => $d['gender_policy'] ?? 'all',
            'show_on_marketplace' => $d['show_on_marketplace'] ?? true,
            'show_on_provider_site' => $d['show_on_provider_site'] ?? false,
        ];
    }

    protected function validated(Request $request): array
    {
        return $request->validate([
            'name' => 'required|string|max:150', 'location_id' => 'nullable|integer|exists:venues,id',
            'description' => 'nullable|string|max:10000', 'contact_phone' => 'nullable|string|max:40',
            'schedule_description' => 'nullable|string|max:2000', 'sport_category' => 'nullable|string|max:100',
            'coach_name' => 'nullable|string|max:150', 'monthly_price' => 'nullable|numeric|min:0|max:1000000',
            'currency' => 'nullable|string|max:10', 'billing_interval' => 'nullable|in:monthly,weekly,yearly,session,term',
            'photo_url' => 'nullable|url:http,https|max:2048', 'images' => 'nullable|array|max:12', 'images.*' => 'url:http,https|max:2048',
            'category' => 'nullable|string|max:40', 'audience' => 'nullable|string|max:40', 'gender_policy' => 'nullable|string|max:40',
            'show_on_marketplace' => 'nullable|boolean', 'show_on_provider_site' => 'nullable|boolean',
            'status' => 'nullable|in:active,inactive', 'is_draft' => 'nullable|boolean',
        ]);
    }

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
        return $request->user() && \App\Support\MembershipListingPolicy::scope(ClassProgram::query(), $request->user())->whereKey($program->id)->exists();
    }
}
