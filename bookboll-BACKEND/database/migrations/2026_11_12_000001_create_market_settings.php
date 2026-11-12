<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('market_settings', function (Blueprint $table) {
            $table->id();
            $table->string('scope', 16);
            $table->unsignedBigInteger('scope_id')->default(0);
            $table->json('values');
            $table->timestamps();
            $table->unique(['scope', 'scope_id']);
        });
        // Preserve the legacy event default if it was explicitly configured.
        $event = DB::table('settings')->where('key', 'global_commission_event')->value('value');
        DB::table('market_settings')->insert([
            'scope' => 'global', 'scope_id' => 0,
            'values' => json_encode(['facility' => 15, 'program' => 15,
                'event' => is_numeric($event) ? max(0, min(100, (float) $event)) : 10,
                'module_listings' => true, 'module_classes' => true, 'module_matchmaking' => true]),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        // Do not reinterpret existing city switches: they remain explicit overrides.
        // New cities inherit; the editor provides a deliberate reset for old cities.
        Schema::table('cities', function (Blueprint $table) {
            foreach (['module_listings', 'module_classes', 'module_matchmaking'] as $key) {
                $table->boolean($key)->nullable()->default(null)->change();
            }
        });
        Schema::table('loyalty_programs', function (Blueprint $table) {
            $table->unsignedBigInteger('country_id')->nullable()->change();
            $table->boolean('inherits_global')->default(false);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('market_settings');
        Schema::table('loyalty_programs', fn (Blueprint $table) => $table->dropColumn('inherits_global'));
        foreach (['module_listings', 'module_classes', 'module_matchmaking'] as $key) {
            DB::table('cities')->whereNull($key)->update([$key => true]);
            Schema::table('cities', fn (Blueprint $table) => $table->boolean($key)->default(true)->nullable(false)->change());
        }
    }
};
