<?php

namespace App\Console\Commands;

use App\Models\ProviderWallet;
use App\Models\WalletTransaction;
use App\Services\WalletPayoutService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Mature provider earnings whose hold period has passed.
 *
 * A booking or membership payment credits the provider as `on_hold`: the money
 * lands in `pending_balance` with an `available_on` date `hold_days` out. Nothing
 * ever moved it on, so `available_balance` stayed at 0 — the provider saw an empty
 * wallet, and withdrawals (which check `available_balance`) could never be made.
 *
 * This walks the holds that are due and moves them: pending → available, and the
 * transaction from `on_hold` → `completed`. Idempotent: a row is only released
 * under a `WHERE status = 'on_hold'` guard, so a second run is a no-op and a
 * concurrent run cannot pay it twice.
 */
class ReleaseWalletHolds extends Command
{
    protected $signature = 'wallet:release-holds
                            {--apply : Write the changes (default is a dry run)}
                            {--days=0 : Also release holds maturing within this many days (testing: 0 = only what is due today)}
                            {--wallet= : Restrict to one wallet id, so a test does not release every provider\'s money}
                            {--chunk=200 : Rows per batch}';

    protected $description = 'Move provider earnings out of hold once their hold period has passed';

    public function handle(WalletPayoutService $wallets): int
    {
        if ($this->option('apply')) app(\App\Support\PaymentReleaseGate::class)->assertReady();
        $apply    = (bool) $this->option('apply');
        $days     = max(0, (int) $this->option('days'));
        $walletId = $this->option('wallet') ?: null;

        // Holds clear on their own date. `--days` brings that date forward so a
        // test does not have to wait out the hold period; it never releases a
        // hold that has no maturity date at all.
        $cutoff = now()->addDays($days)->toDateString();

        $query = WalletTransaction::query()
            ->where('status', 'on_hold')
            ->where('direction', 'credit')
            ->whereNotNull('available_on')
            ->whereDate('available_on', '<=', $cutoff);

        if ($walletId) {
            $query->where('wallet_id', $walletId);
        }

        if ($days > 0) {
            $this->warn(sprintf(
                'Releasing %d day(s) EARLY — holds maturing up to %s are treated as due. Use this for testing only.',
                $days, $cutoff
            ));
        }
        if ($walletId) {
            $this->line("Restricted to wallet {$walletId}.");
        }

        $total = (clone $query)->count();

        if ($total === 0) {
            $this->info('No holds are due for release.');

            // "Nothing happened" is ambiguous: it could mean there is no money,
            // or money that simply has not cleared yet. Say which, so nobody has
            // to go digging in the database to find out.
            $waitingQuery = WalletTransaction::query()
                ->where('status', 'on_hold')
                ->where('direction', 'credit');
            if ($walletId) {
                $waitingQuery->where('wallet_id', $walletId);
            }
            $waiting = $waitingQuery->get(['currency', 'provider_share', 'available_on']);

            if ($waiting->isEmpty()) {
                $this->line('  There are no held earnings at all — nothing has been credited yet.');

                return self::SUCCESS;
            }

            $this->newLine();
            $this->line(sprintf('  %d hold(s) still clearing:', $waiting->count()));
            foreach ($waiting->groupBy(fn ($t) => $t->currency ?: '?') as $cur => $rows) {
                $this->line(sprintf('    %s %s', $cur, number_format((float) $rows->sum('provider_share'), 2)));
            }

            $next = $waiting->filter(fn ($t) => $t->available_on)->sortBy('available_on')->first();
            if ($next) {
                $this->line(sprintf('  Earliest clears on %s.', (string) $next->available_on));
                $ahead = max(1, now()->startOfDay()->diffInDays(\Illuminate\Support\Carbon::parse($next->available_on)->startOfDay()));
                $this->comment("  To release them now for testing: --days={$ahead} --apply");
            } else {
                $this->warn('  None of them carry an available_on date, so this command can never release them.');
            }

            return self::SUCCESS;
        }

        $this->info(($apply ? 'Releasing ' : 'DRY RUN — would release ')."{$total} hold(s).");

        $released = 0;
        $skipped  = 0;
        $sum      = [];

        $query->orderBy('id')->chunkById((int) $this->option('chunk'), function ($rows) use ($apply, $wallets, &$released, &$skipped, &$sum) {
            foreach ($rows as $tx) {
                // The provider's cut is what was ever promised to them; `amount`
                // is the gross including Bookboll's fee.
                $share = round((float) ($tx->provider_share ?? 0), 2);

                if ($share <= 0 || ! $tx->wallet_id) {
                    $skipped++;
                    continue;
                }

                if (! $apply) {
                    $this->line(sprintf('  would release %s %s to wallet %s (due %s)',
                        $tx->currency ?: '', number_format($share, 2), $tx->wallet_id,
                        (string) $tx->available_on));
                    $sum[$tx->currency ?: '?'] = ($sum[$tx->currency ?: '?'] ?? 0) + $share;
                    $released++;
                    continue;
                }

                try {
                    DB::transaction(function () use ($tx, $wallets, $share, &$released, &$sum) {
                        // Claim the row first. If another run already took it the
                        // update affects nothing and we must not touch balances.
                        $claimed = WalletTransaction::query()
                            ->whereKey($tx->id)
                            ->where('status', 'on_hold')
                            ->update(['status' => 'completed']);

                        if (! $claimed) {
                            return;
                        }

                        $wallet = ProviderWallet::whereKey($tx->wallet_id)->lockForUpdate()->first();
                        if (! $wallet) {
                            throw new \RuntimeException("wallet {$tx->wallet_id} not found");
                        }

                        // lifetime_earnings was already counted when the hold was
                        // created, so only the pending → available move happens here.
                        $wallets->applyDelta((string) $wallet->id, [
                            'pending'   => -$share,
                            'available' => $share,
                        ]);

                        $released++;
                        $sum[$tx->currency ?: '?'] = ($sum[$tx->currency ?: '?'] ?? 0) + $share;
                    });
                } catch (\Throwable $e) {
                    $skipped++;
                    $this->error("  transaction {$tx->id}: {$e->getMessage()}");
                    Log::error('Wallet hold release failed', ['tx' => $tx->id, 'error' => $e->getMessage()]);
                }
            }
        });

        $this->newLine();
        foreach ($sum as $cur => $amt) {
            $this->line(sprintf('  %s %s', $cur, number_format($amt, 2)));
        }
        $this->info(($apply ? 'Released: ' : 'Would release: ')."{$released}".($skipped ? "   skipped: {$skipped}" : ''));

        if (! $apply) {
            $this->comment('Re-run with --apply to write these changes.');
        }

        return self::SUCCESS;
    }
}
