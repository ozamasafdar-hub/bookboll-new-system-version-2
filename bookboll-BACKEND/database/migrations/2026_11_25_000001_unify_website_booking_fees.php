<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::transaction(function () {
            $key = 'unified_website_booking_fees_20261125';
            if (DB::table('settings')->where('key', $key)->exists()) return;
            $rows = DB::table('market_settings')->lockForUpdate()->get();
            $updates = [];
            foreach ($rows as $row) {
                $values = json_decode($row->values, true, 512, JSON_THROW_ON_ERROR);
                if ($row->scope === 'global') {
                    // Approved new global defaults. No booking/payment snapshots are touched.
                    $values['facility'] = 15;
                    $values['site'] = 10;
                } elseif (!isset($values['site'])) {
                    $legacy = array_filter(array_intersect_key($values, array_flip(['site_free', 'site_premium'])), fn ($v) => $v !== null);
                    $rates = array_unique(array_map(fn ($v) => (float) $v, array_values($legacy)));
                    if (count($rates) > 1) {
                        throw new RuntimeException("Conflicting legacy website fees at {$row->scope}:{$row->scope_id}. Review both rates and set one explicit market_settings.values.site rate before retrying. Nothing has been migrated.");
                    }
                    // A single explicit local rate remains an override, including zero.
                    if ($rates !== []) $values['site'] = reset($rates);
                }
                unset($values['site_free'], $values['site_premium']);
                $updates[$row->id] = $values;
            }
            // Keep the original settings as a durable audit trail, including retired fields.
            DB::table('settings')->insert([
                'key' => $key, 'value' => json_encode(['previous_settings' => $rows, 'policy' => '15% marketplace / 10% own website, every plan']),
                'type' => 'json', 'description' => 'Unified website fee applied once. Historical bookings/payments unchanged.',
                'created_at' => now(), 'updated_at' => now(),
            ]);
            foreach ($updates as $id => $values) {
                DB::table('market_settings')->where('id', $id)->update(['values' => json_encode((object) $values), 'updated_at' => now()]);
            }
            if (!$rows->contains(fn ($row) => $row->scope === 'global' && (int) $row->scope_id === 0)) {
                DB::table('market_settings')->insert(['scope' => 'global', 'scope_id' => 0, 'values' => json_encode(['facility' => 15, 'site' => 10]), 'created_at' => now(), 'updated_at' => now()]);
            }
        });
    }

    public function down(): void
    {
        // Do not overwrite later administrator pricing decisions during a code rollback.
        // The audit marker retains the previous configuration for a deliberate restoration.
    }
};
