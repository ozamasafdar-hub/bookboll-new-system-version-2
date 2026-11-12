<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::table('bookings', fn (Blueprint $t) => $t->json('loyalty_policy')->nullable());
        Schema::table('loyalty_points', fn (Blueprint $t) => $t->timestamp('expires_at')->nullable()->index());
    }
    public function down(): void {
        Schema::table('loyalty_points', fn (Blueprint $t) => $t->dropColumn('expires_at'));
        Schema::table('bookings', fn (Blueprint $t) => $t->dropColumn('loyalty_policy'));
    }
};
