<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->string('plan_override', 16)->nullable();
            $table->unsignedBigInteger('plan_override_by')->nullable();
            $table->timestamp('plan_override_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('businesses', fn (Blueprint $table) => $table->dropColumn(['plan_override', 'plan_override_by', 'plan_override_at']));
    }
};
