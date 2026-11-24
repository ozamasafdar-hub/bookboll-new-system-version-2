<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Give a public UUID to users that were created without one.
 *
 * `users.uuid` was added on 2026-08-28 as a nullable column and the GeneratesUuid
 * trait fills it on `creating`, so every account registered since then has one.
 * The accounts that already existed were never back-filled — and the trait only
 * fires on create, so saving those rows later does not help. A user without a
 * uuid cannot be looked up by public profile, cannot be the `owner_user_id` of
 * wallets, passes or redemptions (those columns hold the users.uuid), and is
 * skipped by every join on `users.uuid`.
 *
 * Writes go through the query builder on purpose: a model save would bump
 * updated_at and write an activity-log row for every one of thousands of users.
 * Each UUID is generated in PHP so the unique index is honoured without a
 * database-specific function.
 *
 * Dry run by default; pass --apply to write.
 */
class BackfillUserUuids extends Command
{
    protected $signature = 'users:backfill-uuids
                            {--apply : Write the UUIDs (default is a dry run)}
                            {--chunk=500 : Rows per batch}';

    protected $description = 'Assign a uuid to every user that does not have one';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $chunk = max(1, (int) $this->option('chunk'));

        $query = User::withoutGlobalScopes()
            ->where(fn ($q) => $q->whereNull('uuid')->orWhere('uuid', ''));

        $total = (clone $query)->count();
        if ($total === 0) {
            $this->info('Every user already has a uuid.');

            return self::SUCCESS;
        }

        $this->info(($apply ? 'Assigning uuids to ' : 'DRY RUN — would assign uuids to ')."{$total} user(s).");

        if (! $apply) {
            $sample = (clone $query)->orderBy('id')->limit(5)->get(['id', 'name', 'email', 'created_at']);
            foreach ($sample as $u) {
                $this->line(sprintf('  #%-7d %-30s %-35s created %s', $u->id, Str::limit((string) $u->name, 28), Str::limit((string) $u->email, 33), $u->created_at));
            }
            if ($total > $sample->count()) {
                $this->line('  … and '.($total - $sample->count()).' more.');
            }
            $this->newLine();
            $this->comment('Re-run with --apply to write.');

            return self::SUCCESS;
        }

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $updated = 0;

        // chunkById pages with `id > last`, not an offset. The where clause drops
        // rows as they are filled, so an offset-based chunk() would skip every
        // other batch once the first batch vanished from the result set.
        $query->orderBy('id')->chunkById($chunk, function ($users) use (&$updated, $bar) {
            DB::transaction(function () use ($users, &$updated, $bar) {
                foreach ($users as $user) {
                    DB::table('users')
                        ->where('id', $user->id)
                        ->where(fn ($q) => $q->whereNull('uuid')->orWhere('uuid', ''))
                        ->update(['uuid' => (string) Str::uuid()]);
                    $updated++;
                    $bar->advance();
                }
            });
        });

        $bar->finish();
        $this->newLine(2);

        $remaining = User::withoutGlobalScopes()
            ->where(fn ($q) => $q->whereNull('uuid')->orWhere('uuid', ''))
            ->count();

        $this->info("Updated: {$updated}");
        if ($remaining > 0) {
            $this->warn("Still missing a uuid: {$remaining}");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
