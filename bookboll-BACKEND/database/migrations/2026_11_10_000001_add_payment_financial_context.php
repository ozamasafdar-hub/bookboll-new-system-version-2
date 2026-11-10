<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('bookings', fn (Blueprint $t) => $t->json('financial_context')->nullable());
        Schema::table('payments', fn (Blueprint $t) => $t->json('financial_context')->nullable());
    }

    public function down(): void
    {
        Schema::table('payments', fn (Blueprint $t) => $t->dropColumn('financial_context'));
        Schema::table('bookings', fn (Blueprint $t) => $t->dropColumn('financial_context'));
    }
};
