<?php

use App\Support\BusinessMembershipSync;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Log;

/**
 * Give every legacy-linked staff account the membership the rest of the system
 * now reads.
 *
 * "Invite user" wrote only users.owner_user_id. For a business created
 * ownerless (businesses.owner_id null) that link resolves to nothing, so the
 * invitee signed in to an empty dashboard — live: user 40183 under Badminton
 * City. BusinessMembershipSync now writes the membership on every such path;
 * this is the one-off for the accounts created before it did.
 *
 * Idempotent: only accounts with a team role, an owner, and NO active membership
 * are touched, and the sync itself upserts. `business:sync-memberships` reports
 * or re-runs the same thing from the console.
 */
return new class extends Migration
{
    public function up(): void
    {
        $result = BusinessMembershipSync::backfill();

        Log::info('Backfilled business memberships for legacy staff', $result);
    }

    public function down(): void
    {
        // Data repair; nothing to undo. The memberships are now the record.
    }
};
