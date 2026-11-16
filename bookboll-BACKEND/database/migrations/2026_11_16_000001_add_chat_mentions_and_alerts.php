<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('customer_messages', fn (Blueprint $t) => $t->json('mentions')->nullable());
        Schema::create('chat_reads', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('user_id');
            $t->string('conversation_key', 200);
            $t->unsignedBigInteger('last_message_id')->default(0);
            $t->unique(['user_id', 'conversation_key']);
        });
        Schema::create('chat_alerts', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('message_id');
            $t->unsignedBigInteger('user_id');
            $t->string('conversation_key', 200);
            $t->string('kind', 16);
            $t->string('channel', 16);
            $t->string('status', 24)->default('pending');
            $t->timestamp('due_at');
            $t->timestamp('sent_at')->nullable();
            $t->timestamps();
            $t->unique(['message_id', 'user_id', 'channel']);
            $t->index(['status', 'due_at']);
            $t->index(['user_id', 'conversation_key', 'channel', 'status'], 'chat_alert_thread');
        });
    }
    public function down(): void
    {
        Schema::dropIfExists('chat_alerts');
        Schema::dropIfExists('chat_reads');
        Schema::table('customer_messages', fn (Blueprint $t) => $t->dropColumn('mentions'));
    }
};
