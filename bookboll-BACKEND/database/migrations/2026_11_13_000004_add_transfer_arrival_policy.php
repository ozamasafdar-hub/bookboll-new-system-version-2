<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        foreach (['venue_transfer_previews', 'venue_transfers'] as $name) {
            // Existing reviews retain their explicitly reviewed cash interpretation.
            Schema::table($name, fn (Blueprint $table) => $table->string('arrival_policy', 30)->default('confirm_cash'));
        }
    }
    public function down(): void
    {
        foreach (['venue_transfer_previews', 'venue_transfers'] as $name) Schema::table($name, fn (Blueprint $table) => $table->dropColumn('arrival_policy'));
    }
};
