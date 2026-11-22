<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('business_support_sessions', function (Blueprint $table) {
            $table->id();
            // Retain attribution even after an account/token is removed.
            $table->unsignedBigInteger('actor_id')->index();
            $table->unsignedBigInteger('business_id')->index();
            $table->unsignedBigInteger('effective_user_id');
            $table->unsignedBigInteger('source_token_id')->index();
            $table->unsignedBigInteger('token_id')->unique();
            $table->timestamp('expires_at')->index();
            $table->timestamp('ended_at')->nullable();
            $table->string('end_reason')->nullable();
            $table->timestamps();
        });
    }
    public function down(): void { Schema::dropIfExists('business_support_sessions'); }
};
