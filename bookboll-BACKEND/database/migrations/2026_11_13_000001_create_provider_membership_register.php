<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::table('class_programs', function (Blueprint $t) { $t->json('listing_details')->nullable(); });
        Schema::create('provider_members', function (Blueprint $t) {
            $t->id(); $t->foreignId('program_id')->constrained('class_programs')->restrictOnDelete();
            $t->string('name',150); $t->string('phone',40)->nullable(); $t->string('email',190)->nullable();
            $t->string('guardian_name',150)->nullable(); $t->string('emergency_phone',40)->nullable();
            $t->date('starts_on'); $t->date('ends_on')->nullable(); $t->string('status',20)->default('active');
            $t->text('notes')->nullable(); $t->decimal('agreed_fee',12,2)->default(0); $t->string('currency',3);
            $t->unsignedBigInteger('created_by'); $t->timestamps(); $t->index(['program_id','status','ends_on']);
        });
        Schema::create('provider_member_attendance', function (Blueprint $t) {
            $t->id(); $t->foreignId('member_id')->constrained('provider_members')->cascadeOnDelete();
            $t->date('attended_on'); $t->string('session',100)->default('Daily attendance');
            $t->string('status',20); $t->string('note',500)->nullable(); $t->unsignedBigInteger('recorded_by'); $t->timestamps();
            $t->unique(['member_id','attended_on','session'],'member_attendance_session_unique');
        });
        Schema::create('provider_member_payments', function (Blueprint $t) {
            $t->id(); $t->foreignId('member_id')->constrained('provider_members')->cascadeOnDelete();
            $t->uuid('request_id')->unique(); $t->decimal('amount',12,2); $t->string('method',30); $t->date('paid_on');
            $t->string('reference',150)->nullable(); $t->string('note',500)->nullable(); $t->unsignedBigInteger('recorded_by');
            $t->timestamp('voided_at')->nullable(); $t->string('void_reason',500)->nullable(); $t->timestamps();
        });
        Schema::create('provider_member_activity', function (Blueprint $t) {
            $t->id(); $t->foreignId('member_id')->constrained('provider_members')->cascadeOnDelete();
            $t->string('action',60); $t->json('details')->nullable(); $t->unsignedBigInteger('actor_id'); $t->timestamp('created_at')->useCurrent();
        });
    }
    public function down(): void {
        foreach (['provider_member_activity','provider_member_payments','provider_member_attendance','provider_members'] as $table) Schema::dropIfExists($table);
        Schema::table('class_programs', fn (Blueprint $t) => $t->dropColumn('listing_details'));
    }
};
