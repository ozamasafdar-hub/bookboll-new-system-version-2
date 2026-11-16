<?php

namespace App\Services;

use App\Models\Setting;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SettingService
{
    public function __construct(protected Setting $settingModel) {}

    /**
     * Reading settings is open to every dashboard role — the brand/theme loader
     * needs it on every page — so anything flagged `encrypted` is withheld here.
     *
     * That flag is only a label: nothing on the model encrypts the column, so a
     * secret stored in this table sits in plaintext and used to be handed to all
     * twelve roles that can reach this endpoint. Secrets belong in .env, not in
     * `settings`; this filter is the backstop for the day someone adds one anyway.
     */
    public function all()
    {
        $settings = $this->settingModel::query()
            ->where('encrypted', false)
            ->get(['key', 'value'])
            ->pluck('value', 'key')
            ->toArray();

        return sendSuccess('Settings retrieved successfully', compact('settings'));
    }

    public function update(Request $request)
    {
        try {
            $formData = $request->validate([
                '*.key' => 'required|string|max:255',
                '*.value' => 'nullable',
            ]);

            DB::beginTransaction();

            $updated = [];
            foreach ($formData as $item) {
                $updated += $this->put($item['key'], $item['value']);
            }
            DB::commit();

            return sendSuccess('Settings updated successfully', ['settings' => $updated]);
        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Settings update failed: '.$e->getMessage());

            return sendError('Settings update failed: '.$e->getMessage());
        }
    }

    public function updateSingle(Request $request, string $key)
    {
        $request->validate(['value' => 'nullable']);

        $written = $this->put($key, $request->value);

        return sendSuccess('Setting updated successfully', ['key' => $key, 'value' => $written[$key]]);
    }

    /**
     * Write one setting and whatever else that key implies.
     *
     * The single write path for every caller — this API, and the admin panel's
     * Settings page — so a linked key like the slot lock below cannot be kept in
     * step by one of them and left stale by the other.
     *
     * @return array<string, string|null> every key actually written, with its stored value
     */
    public function put(string $key, mixed $value): array
    {
        abort_if($key === ChatService::SETTINGS_KEY, 403, 'Use the superadmin chat alert controls.');
        $value = self::normaliseValue($value);

        $setting = $this->settingModel::updateOrCreate(['key' => $key], ['value' => $value]);

        $written = [$key => $setting->value];

        // The booking lock duration is not independently meaningful: it is the
        // checkout hold, expressed for the slot locker. Kept in step here rather
        // than trusted to whoever edits the two separately.
        if ($key === 'booking_hold_minutes') {
            $linked = (string) max(1, (int) $value);

            $this->settingModel::updateOrCreate(
                ['key' => 'default_bookings_slot_lock_time'],
                ['value' => $linked]
            );

            $written['default_bookings_slot_lock_time'] = $linked;
        }

        return $written;
    }

    /**
     * The settings table stores everything as text, so booleans become '1'/'0'
     * and structures become JSON. Null stays null — it is how a setting is
     * cleared, and Setting::scopeActive() treats it as unset.
     */
    public static function normaliseValue(mixed $value): ?string
    {
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_array($value) || is_object($value)) {
            return json_encode($value);
        }

        return $value === null ? null : (string) $value;
    }
}
