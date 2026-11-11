<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('payment_review_decisions', function(Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('payment_id')->index(); $t->unsignedBigInteger('actor_id');
            $t->string('actor_name'); $t->string('action',40); $t->text('reason');
            $t->string('fingerprint',64)->unique(); $t->json('evidence'); $t->json('outcome'); $t->timestamp('created_at');
        });
    }
    public function down(): void { Schema::dropIfExists('payment_review_decisions'); }
};
