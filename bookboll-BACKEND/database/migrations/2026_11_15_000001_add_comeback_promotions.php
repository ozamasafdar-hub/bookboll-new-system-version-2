<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::table('bookings', function (Blueprint $t) {
            $t->decimal('platform_promo_discount', 12, 2)->nullable();
            $t->json('promotion_snapshot')->nullable();
        });
        Schema::table('promocodes', function (Blueprint $t) {
            $t->unsignedBigInteger('comeback_booking_id')->nullable()->unique();
            $t->dateTime('starts_at')->nullable();
            $t->dateTime('ends_at')->nullable();
        });
        Schema::create('booking_comeback_offers', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('user_id'); $t->unsignedBigInteger('venue_id');
            $t->unsignedBigInteger('booking_id'); $t->unsignedBigInteger('promocode_id')->nullable();
            $t->dateTime('session_ended_at'); $t->string('status')->default('pending');
            $t->string('email_status')->default('pending'); $t->string('whatsapp_status')->default('pending');
            $t->text('last_error')->nullable(); $t->timestamps();
            $t->unique(['user_id', 'venue_id']);
        });
    }
    public function down(): void {
        Schema::dropIfExists('booking_comeback_offers');
        Schema::table('promocodes', fn (Blueprint $t) => $t->dropColumn(['comeback_booking_id','starts_at','ends_at']));
        Schema::table('bookings', fn (Blueprint $t) => $t->dropColumn(['platform_promo_discount','promotion_snapshot']));
    }
};
