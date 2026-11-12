<?php

namespace App\Services;

use App\Models\Business;
use App\Models\User;
use App\Support\AccessScope;
use App\Support\ProviderNotifications;
use Illuminate\Http\Request;

/**
 * Settings → Business Profile / My Account / Notifications.
 *
 * None of these saved anything. The profile save handler reported that updates
 * were unavailable, and the notification switches changed local component state
 * and nothing else — all three were honestly labelled as not working.
 *
 * Everything here is resolved from the ACTING user. No endpoint takes a
 * business id, a user id or an owner id, so provider staff cannot reach another
 * business or rewrite owner identity by changing a number in a request.
 */
class ProviderSettingsService
{
    public function show(Request $request)
    {
        $user = $request->user();
        $business = $this->businessFor($user);

        return sendSuccess('Settings fetched successfully', [
            'business'      => $business ? $this->businessProfile($business) : null,
            'account'       => $this->account($user),
            'notifications' => $this->notifications($user),
            // Said plainly, so the dashboard can show a read-only field rather
            // than an editable one that silently refuses.
            'can_edit_business' => (bool) $this->ownedBusiness($user),
        ]);
    }

    /**
     * The business's own name, phone and description.
     *
     * Deliberately NOT the brand: `brand_name` and the rest are the public
     * website's identity, edited in My Website, and the dashboard says so.
     * Changing one must not change the other.
     */
    public function updateBusiness(Request $request)
    {
        $user = $request->user();

        // The owner's record, found from the owner, never from the request.
        // Staff may read the profile; only the owner rewrites it — the same
        // boundary the brand editor already draws.
        $business = $this->ownedBusiness($user);
        if (! $business) {
            return $this->businessFor($user)
                ? sendError('Only the business owner can change the business profile.', [], 403)
                : sendError('No business found for this account.', [], 404);
        }

        $data = $request->validate([
            'name'        => 'required|string|max:150',
            'phone'       => 'nullable|string|max:32',
            'description' => 'nullable|string|max:2000',
        ]);

        $business->update([
            'name'        => trim($data['name']),
            'phone'       => $this->nullIfBlank($data['phone'] ?? null),
            'description' => $this->nullIfBlank($data['description'] ?? null),
        ]);

        // Read back from the row, because the dashboard confirms success only
        // when what it sent is what came back.
        return sendSuccess('Business profile updated', [
            'business' => $this->businessProfile($business->fresh()),
        ]);
    }

    /**
     * The person's own name and phone.
     *
     * Email is not editable here and refusing is the point: changing the
     * address that receives password resets needs identity checks this endpoint
     * does not perform. A silently ignored email field would let someone leave
     * believing it had changed.
     */
    public function updateAccount(Request $request)
    {
        $user = $request->user();

        if ($request->has('email') && trim((string) $request->input('email')) !== (string) $user->email) {
            return sendError('Email changes need to be verified by support.', [
                'email' => ['Contact support to change the address on your account.'],
            ], 422);
        }

        foreach (['id', 'user_id', 'owner_user_id', 'business_id', 'role', 'roles', 'status'] as $field) {
            if ($request->has($field)) {
                return sendError('That field cannot be changed here.', [
                    $field => ['Not editable through account settings.'],
                ], 422);
            }
        }

        $data = $request->validate([
            'name'  => 'required|string|max:100',
            'phone' => 'nullable|string|max:32',
        ]);

        $user->update([
            'name'  => trim($data['name']),
            'phone' => $this->nullIfBlank($data['phone'] ?? null),
        ]);

        return sendSuccess('Account updated', ['account' => $this->account($user->fresh())]);
    }

    /**
     * Personal notification switches.
     *
     * Per person, not per business: two people on one business choose
     * separately. Saved, returned, and consulted by delivery — a preference
     * nothing reads is the same empty promise as a switch that saves nowhere.
     */
    public function updateNotifications(Request $request)
    {
        $user = $request->user();

        $patch = $request->input('preferences');
        if (! is_array($patch)) {
            return sendError('Send the switches you changed.', [
                'preferences' => ['Expected an object of notification settings.'],
            ], 422);
        }

        [$merged, $errors] = ProviderNotifications::normalize($user, $patch);
        if ($errors) {
            return sendError('Those notification settings could not be saved.', $errors, 422);
        }

        $user->notification_preferences = $merged;
        $user->save();

        return sendSuccess('Notification preferences saved', [
            'notifications' => $this->notifications($user->fresh()),
        ]);
    }

    /** @return array<string, mixed> */
    protected function businessProfile(Business $business): array
    {
        return [
            'name'        => $business->name ?? '',
            'phone'       => $business->phone ?? '',
            'description' => $business->description ?? '',
            // Shown for context and not editable here — the dashboard points
            // at My Website for it rather than offering two names to change.
            'brand_name'  => $business->brand_name ?? '',
        ];
    }

    /** @return array<string, mixed> */
    protected function account(?User $user): array
    {
        return [
            'name'           => $user?->name ?? '',
            'phone'          => $user?->phone ?? '',
            'email'          => $user?->email ?? '',
            'email_editable' => false,
        ];
    }

    /** @return array<string, mixed> */
    protected function notifications(?User $user): array
    {
        return [
            'preferences' => ProviderNotifications::forUser($user),
            'available'   => ProviderNotifications::catalogue(),
            // Alerts nobody may switch off, with the reason, so the dashboard
            // shows them as always-on instead of appearing to have lost them.
            'always_on'   => collect(ProviderNotifications::ALWAYS_ON)
                ->map(fn ($why, $kind) => ['kind' => $kind, 'reason' => $why])
                ->values()->all(),
            'not_offered' => collect(ProviderNotifications::NOT_OFFERED)
                ->map(fn ($why, $key) => ['key' => $key, 'reason' => $why])
                ->values()->all(),
        ];
    }

    /** The business this person belongs to — theirs, or their owner's. */
    protected function businessFor(?User $user): ?Business
    {
        $ownerId = AccessScope::ownerUserId($user);

        return $ownerId === null ? null : Business::forOwnerId($ownerId);
    }

    /** The business this person owns outright, if any. */
    protected function ownedBusiness(?User $user): ?Business
    {
        // Owned outright, or run through a managing membership — the owner of a
        // business created ownerless holds it the second way.
        return AccessScope::managedBusiness($user);
    }

    protected function nullIfBlank(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
