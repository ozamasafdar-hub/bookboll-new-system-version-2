<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('payment_refunds', function (Blueprint $t) {
            $t->id();
            $t->string('charge_id')->unique();
            $t->string('stripe_account_id')->nullable();
            $t->string('stripe_refund_id')->nullable()->unique();
            $t->uuid('idempotency_key')->unique();
            $t->unsignedBigInteger('requested_by')->nullable();
            $t->unsignedBigInteger('source_payment_id');
            $t->unsignedBigInteger('amount_minor');
            $t->string('currency', 10);
            $t->string('status', 32)->default('submitting');
            $t->string('reason', 64);
            $t->json('payment_ids');
            $t->json('before');
            $t->json('processor')->nullable();
            $t->text('receipt_url')->nullable();
            $t->timestamp('synced_at')->nullable();
            $t->timestamps();
        });
        Schema::table('payments', function (Blueprint $t) {
            $t->unsignedBigInteger('payment_refund_id')->nullable()->index();
            $t->index('charge_id', 'payments_refund_charge_idx');
            $t->index('payment_intent_id', 'payments_refund_intent_idx');
        });
    }
    public function down(): void {
        Schema::table('payments', function (Blueprint $t) {
            $t->dropIndex('payments_refund_charge_idx');
            $t->dropIndex('payments_refund_intent_idx');
            $t->dropColumn('payment_refund_id');
        });
        Schema::dropIfExists('payment_refunds');
    }
};
