<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Keep historical owner-keyed records stable when the primary contact changes.
        if (!Schema::hasColumn('businesses', 'legacy_owner_id')) {
            Schema::table('businesses', fn (Blueprint $t) => $t->unsignedBigInteger('legacy_owner_id')->nullable()->index());
        }
        if (collect(Schema::getIndexes('business_memberships'))->contains('name', 'business_memberships_one_active_owner')) {
            Schema::table('business_memberships', fn (Blueprint $t) => $t->dropUnique('business_memberships_one_active_owner'));
        }
        if (Schema::hasColumn('business_memberships', 'active_owner_business_id')) {
            Schema::table('business_memberships', fn (Blueprint $t) => $t->dropColumn('active_owner_business_id'));
        }
        DB::table('businesses')->orderBy('id')->each(function ($b) {
            $owner = $b->owner_id ?: DB::table('business_memberships')->where('business_id', $b->id)->where('status', 'active')->whereIn('role', ['owner','provider_admin'])->orderBy('id')->value('user_id');
            if (!$owner) return;
            DB::table('businesses')->where('id', $b->id)->update(['legacy_owner_id' => $b->legacy_owner_id ?: $owner, 'owner_id' => $b->owner_id ?: $owner]);
            if (!DB::table('business_memberships')->where('business_id', $b->id)->where('user_id', $owner)->exists()) {
                DB::table('business_memberships')->insert(['business_id'=>$b->id,'user_id'=>$owner,'role'=>'owner','scope'=>'all','status'=>'active','version'=>1,'created_at'=>now(),'updated_at'=>now()]);
            }
        });
    }

    public function down(): void
    {
        // Never silently discard co-owners or detach legacy records on rollback.
        if (DB::table('business_memberships')->where('status','active')->whereIn('role',['owner','provider_admin'])->groupBy('business_id')->havingRaw('COUNT(*) > 1')->exists()
            || DB::table('businesses')->whereNotNull('legacy_owner_id')->whereColumn('legacy_owner_id','!=','owner_id')->exists()) {
            throw new RuntimeException('Multiple-owner data exists. Reconcile ownership before rolling back this migration.');
        }
        DB::statement("ALTER TABLE business_memberships ADD COLUMN active_owner_business_id BIGINT UNSIGNED AS (CASE WHEN role = 'owner' AND status = 'active' THEN business_id END) VIRTUAL, ADD UNIQUE KEY business_memberships_one_active_owner (active_owner_business_id)");
        Schema::table('businesses', fn (Blueprint $t) => $t->dropColumn('legacy_owner_id'));
    }
};
