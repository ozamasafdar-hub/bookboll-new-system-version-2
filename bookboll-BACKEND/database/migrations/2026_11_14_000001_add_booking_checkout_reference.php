<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::table('bookings', fn (Blueprint $t) => $t->string('checkout_reference', 64)->nullable()->index());
        Schema::create('checkout_payment_collections', function (Blueprint $t) {
            $t->string('fingerprint',64)->primary();
            $t->unsignedBigInteger('actor_id')->index();
            $t->string('payment_reference',120);
            $t->json('payment_ids');
            $t->string('currency',3);
            $t->unsignedBigInteger('amount_minor');
            $t->string('method',30);
            $t->timestamp('created_at');
        });
    }
    public function down(): void {
        Schema::dropIfExists('checkout_payment_collections');
        Schema::table('bookings', fn (Blueprint $t) => $t->dropColumn('checkout_reference'));
    }
};
