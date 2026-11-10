<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('payment_allocation_corrections', function(Blueprint $t){
            $t->id(); $t->unsignedBigInteger('payment_id'); $t->unsignedBigInteger('booking_id')->index();
            $t->string('policy',64); $t->json('before'); $t->json('after'); $t->json('evidence'); $t->timestamp('created_at');
            $t->unique(['payment_id','policy']);
        });
    }
    public function down(): void { Schema::dropIfExists('payment_allocation_corrections'); }
};
