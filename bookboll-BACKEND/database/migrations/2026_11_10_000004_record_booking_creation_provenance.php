<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('bookings', fn (Blueprint $t) => $t->json('creation_context')->nullable());
        Schema::create('booking_creator_corrections', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('booking_id')->index();
            $t->unsignedBigInteger('payment_id')->nullable()->index();
            $t->json('before'); $t->json('after'); $t->json('evidence');
            $t->timestamp('created_at');
        });
    }
    public function down(): void
    {
        Schema::dropIfExists('booking_creator_corrections');
        Schema::table('bookings', fn (Blueprint $t) => $t->dropColumn('creation_context'));
    }
};
