<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('class_programs', function (Blueprint $t) {
            $t->string('contact_phone', 40)->nullable();
            $t->text('schedule_description')->nullable();
        });
        Schema::create('membership_listing_subscriptions', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('program_id')->index();
            $t->unsignedBigInteger('business_id')->nullable()->index();
            $t->unsignedBigInteger('payer_id');
            $t->decimal('amount', 12, 2);
            $t->string('currency', 3)->default('USD');
            $t->string('status', 40)->default('incomplete');
            $t->string('stripe_subscription_id')->nullable()->unique('mls_stripe_unique');
            $t->string('stripe_checkout_session_id')->nullable()->unique('mls_checkout_unique');
            $t->timestamp('paid_until')->nullable();
            $t->timestamp('current_period_end')->nullable();
            $t->boolean('cancel_at_period_end')->default(false);
            $t->unsignedBigInteger('last_event_at')->default(0);
            $t->timestamps();
        });
        Schema::create('membership_listing_invoices', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('listing_subscription_id')->index();
            $t->string('stripe_invoice_id')->unique();
            $t->string('stripe_payment_intent_id')->nullable()->index();
            $t->string('status', 40);
            $t->decimal('amount_due', 12, 2)->default(0);
            $t->decimal('amount_paid', 12, 2)->default(0);
            $t->decimal('amount_refunded', 12, 2)->default(0);
            $t->string('currency', 3);
            $t->timestamp('paid_at')->nullable();
            $t->timestamp('period_start')->nullable();
            $t->timestamp('period_end')->nullable();
            $t->text('hosted_invoice_url')->nullable();
            $t->timestamps();
        });
    }
    public function down(): void
    {
        Schema::dropIfExists('membership_listing_invoices');
        Schema::dropIfExists('membership_listing_subscriptions');
        Schema::table('class_programs', fn (Blueprint $t) => $t->dropColumn(['contact_phone', 'schedule_description']));
    }
};
