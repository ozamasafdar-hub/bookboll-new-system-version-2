<?php

namespace App\Console\Commands;

use App\Support\BusinessMembershipSync;
use Illuminate\Console\Command;

/**
 * Report, or write, the business memberships missing for staff who are linked to
 * their business only through users.owner_user_id.
 *
 * Without --apply it only lists what would change, so it is safe to run on live
 * first and read the result; with --apply it writes exactly those rows. The
 * backfill migration runs the same code once on deploy — this is for checking
 * afterwards, and for any account that slips through later.
 */
class SyncBusinessMemberships extends Command
{
    protected $signature = 'business:sync-memberships {--apply : Write the missing memberships. Without it, only report.}';

    protected $description = 'Create the business membership for legacy-linked staff accounts that have none';

    public function handle(): int
    {
        $pending = BusinessMembershipSync::pendingBackfill();

        if (! $pending) {
            $this->info('Every legacy-linked staff account already has an active membership.');

            return self::SUCCESS;
        }

        $this->table(
            ['user_id', 'email', 'role', 'owner_user_id', 'business_id'],
            array_map(fn (array $r) => [
                $r['user_id'],
                $r['email'],
                $r['role'] ?? '-',
                $r['owner_user_id'],
                $r['business_id'] ?? 'UNRESOLVED',
            ], $pending)
        );

        $unresolved = count(array_filter($pending, fn (array $r) => ! $r['business_id']));
        if ($unresolved) {
            $this->warn("{$unresolved} account(s) point at an owner with no business; they need a manual assignment via the Team page.");
        }

        if (! $this->option('apply')) {
            $this->line('Dry run. Re-run with --apply to write the memberships above.');

            return self::SUCCESS;
        }

        $result = BusinessMembershipSync::backfill();

        $this->info("Created {$result['created']} membership(s); {$result['unresolved']} left unresolved.");

        return self::SUCCESS;
    }
}
