<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('payment_release_identity', function (Blueprint $table) {
            $table->unsignedTinyInteger('id')->primary();
            $table->uuid('database_uuid')->unique();
        });
        DB::table('payment_release_identity')->insert(['id'=>1, 'database_uuid'=>(string) Str::uuid()]);
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_release_identity');
    }
};
