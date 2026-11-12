<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::table('stripe_sessions', fn(Blueprint $t) => $t->json('settlement')->nullable());
        Schema::create('business_payment_connections', function (Blueprint $t) {
            $t->id(); $t->foreignId('business_id')->unique()->constrained()->cascadeOnDelete();
            $t->string('stripe_account_id')->unique(); $t->boolean('livemode');
            $t->string('status')->default('pending'); $t->string('account_name')->nullable();
            $t->string('country', 2)->nullable(); $t->boolean('charges_enabled')->default(false);
            $t->boolean('payouts_enabled')->default(false); $t->boolean('transfers_enabled')->default(false);
            $t->json('requirements')->nullable(); $t->timestamp('verified_at')->nullable();
            $t->foreignId('connected_by')->constrained('users'); $t->timestamps();
        });
        Schema::create('business_connect_states', function (Blueprint $t) {
            $t->string('state_hash', 64)->primary(); $t->foreignId('business_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete(); $t->timestamp('expires_at');
        });
    }
    public function down(): void { Schema::dropIfExists('business_connect_states'); Schema::dropIfExists('business_payment_connections'); Schema::table('stripe_sessions', fn(Blueprint $t) => $t->dropColumn('settlement')); }
};
