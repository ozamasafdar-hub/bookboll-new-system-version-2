<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('payment_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->constrained('payments')->cascadeOnDelete();
            $table->string('bucket', 24);
            $table->unsignedBigInteger('amount_minor');
            $table->string('currency', 3);
            $table->decimal('fee_rate', 8, 4)->nullable();
            $table->string('basis', 32);
            $table->timestamps();
            $table->unique(['payment_id','bucket']);
        });
    }
    public function down(): void { Schema::dropIfExists('payment_allocations'); }
};
