<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::transaction(function () {
            // A durable marker makes the rollout safe to rerun without replacing
            // later administrator edits. Retain the previous global values for audit.
            $key = 'booking_fee_defaults_20261122';
            if (DB::table('settings')->where('key', $key)->exists()) return;
            $row = DB::table('market_settings')->where('scope', 'global')->where('scope_id', 0)->lockForUpdate()->first();
            $previous = $row ? json_decode($row->values, true, 512, JSON_THROW_ON_ERROR) : [];
            // Explicitly approved reset of the old Qatar/Pakistan marketplace
            // country overrides. Resolve by ISO code, never environment-specific IDs.
            $countries = DB::table('countries')->whereIn('iso2', ['QA', 'PK'])->pluck('id');
            $countrySettings = DB::table('market_settings')->where('scope', 'country')->whereIn('scope_id', $countries)->lockForUpdate()->get();
            $rates = DB::table('commission_rates')->whereIn('country_id', $countries)->where('listing_type', 'facility');
            DB::table('settings')->insert([
                'key' => $key, 'value' => json_encode(['previous_global' => $previous, 'previous_country_settings' => $countrySettings, 'previous_country_rates' => $rates->get()]),
                'type' => 'json', 'description' => 'Batch 4 booking fee defaults applied once; historical bookings unchanged.',
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $rates->delete();
            foreach ($countrySettings as $country) {
                $values = json_decode($country->values, true, 512, JSON_THROW_ON_ERROR);
                unset($values['facility']);
                DB::table('market_settings')->where('id', $country->id)->update(['values' => json_encode((object) $values), 'updated_at' => now()]);
            }
            DB::table('market_settings')->updateOrInsert(['scope' => 'global', 'scope_id' => 0], [
                'values' => json_encode(array_replace($previous, ['facility' => 15, 'site_free' => 15, 'site_premium' => 10])),
                'created_at' => $row?->created_at ?? now(), 'updated_at' => now(),
            ]);
        });
    }

    public function down(): void
    {
        // Prices are business configuration. A code rollback must not overwrite
        // subsequent administrator decisions. Previous values remain in settings.
    }
};
