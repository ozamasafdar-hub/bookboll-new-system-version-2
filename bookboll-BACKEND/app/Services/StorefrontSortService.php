<?php

namespace App\Services;

use App\Models\Business;
use App\Models\Setting;
use App\Support\AccessScope;
use Illuminate\Http\Request;

/**
 * Fallback ordering for the customer storefront — what shows first when the
 * visitor has NOT shared their location (with location, it is nearest-first).
 *
 * One control, two destinations, exactly as the dashboard presents it:
 *   • a platform admin sets it for the Bookboll marketplace  → settings rows
 *   • a provider sets it for their own white-label site      → their business row
 *
 * `space` is the flat "All listings" view; `venue` is the grouped by-venue view.
 */
class StorefrontSortService
{
    public const DEFAULT = 'recommended';

    protected const KEYS = ['space' => 'storefront_sort_space', 'venue' => 'storefront_sort_venue'];
    protected const COLUMNS = ['space' => 'sort_space', 'venue' => 'sort_venue'];

    public function show(Request $request)
    {
        $user = $request->user();
        $ownerId = AccessScope::ownerUserId($user);

        if ($ownerId !== null) {
            $b = Business::forOwnerId($ownerId);

            return sendSuccess('Storefront sort', ['sort' => [
                'space' => $b?->sort_space ?: self::DEFAULT,
                'venue' => $b?->sort_venue ?: self::DEFAULT,
            ]]);
        }

        $rows = Setting::whereIn('key', array_values(self::KEYS))->pluck('value', 'key');

        return sendSuccess('Storefront sort', ['sort' => [
            'space' => $rows[self::KEYS['space']] ?? self::DEFAULT,
            'venue' => $rows[self::KEYS['venue']] ?? self::DEFAULT,
        ]]);
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'view'  => 'required|string|in:space,venue',
            'value' => 'required|string|max:40',
        ]);

        $user = $request->user();
        $ownerId = AccessScope::ownerUserId($user);

        if ($ownerId !== null) {
            $business = Business::forOwnerId($ownerId);
            if (! $business) {
                return sendError('No business found for this account.', [], 404);
            }
            $business->update([self::COLUMNS[$data['view']] => $data['value']]);
        } elseif (! AccessScope::isPlatform($user)) {
            // A provider account under no business must not reach the
            // marketplace's own ordering.
            return sendError('No business found for this account.', [], 404);
        } else {
            Setting::updateOrCreate(
                ['key' => self::KEYS[$data['view']]],
                ['value' => $data['value']]
            );
        }

        return $this->show($request);
    }
}
