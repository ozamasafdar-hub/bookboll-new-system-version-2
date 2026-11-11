<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('payment_bulk_actions',function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('actor_id'); $t->string('actor_name'); $t->string('action');
            $t->string('fingerprint',64)->unique(); $t->text('reason'); $t->json('evidence'); $t->json('outcome'); $t->timestamp('created_at');
        });
        Schema::create('payment_bulk_action_items',function (Blueprint $t) {
            $t->id(); $t->foreignId('bulk_action_id')->constrained('payment_bulk_actions');
            $t->unsignedBigInteger('payment_id')->index(); $t->string('row_id',100);
            $t->json('before'); $t->json('after');
            $t->unique(['bulk_action_id','row_id']);
        });
    }
    public function down(): void { Schema::dropIfExists('payment_bulk_action_items'); Schema::dropIfExists('payment_bulk_actions'); }
};
