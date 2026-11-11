<?php

namespace App\Console\Commands;

use App\Models\HostedGame;
use App\Models\Setting;
use App\Models\StripeSession;
use App\Services\CustomerGameService;
use App\Services\StripeService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * A host who starts paying for an event and then closes the tab never reaches the
 * cancel URL, so the unpublished event and its court reservations would sit there
 * forever holding slots nobody can book.
 *
 * This sweeps them up on the same timeout as an unpaid booking — but only after
 * asking Stripe, because our own rows do not move until the webhook lands and a
 * host whose payment is a few seconds behind must not lose their event.
 */
class DiscardUnpaidHostedGames extends Command
{
    protected $signature = 'games:discard-unpaid {--apply : Actually discard. Without it, only report.}';

    protected $description = 'Release courts held by hosted events whose host never completed payment';

    public function handle(StripeService $stripe, CustomerGameService $games): int
    {
        if ($this->option('apply')) app(\App\Support\PaymentReleaseGate::class)->assertReady();
        $timeout = (int) (Setting::where('key', 'deposit_payment_timeout_minutes')->value('value') ?: 10);
        $grace   = max(0, (int) Setting::where('key', 'payment_timeout_grace_minutes')->value('value'));

        if ($timeout <= 0) {
            $this->info('Timeout is not set, nothing to do.');

            return self::SUCCESS;
        }

        $cutoff  = now()->subMinutes($timeout + $grace);
        $pending = HostedGame::withUnpublished()
            ->whereNull('published_at')
            ->where('created_at', '<=', $cutoff)
            ->orderBy('id')
            ->get();

        if ($pending->isEmpty()) {
            $this->info('No unpaid hosted events past the timeout.');

            return self::SUCCESS;
        }

        $apply     = (bool) $this->option('apply');
        $discarded = 0;
        $settled   = 0;

        foreach ($pending as $game) {
            $sessionIds = StripeSession::where('source', CustomerGameService::CHECKOUT_SOURCE)
                ->where(function ($q) use ($game) {
                    $q->where('booking_id', $game->booking_id)
                        ->orWhereJsonContains('booking_ids', (int) $game->booking_id);
                })
                ->pluck('session_id');

            // Stripe, not our column, decides whether the money arrived.
            $paid = false;

            foreach ($sessionIds as $sessionId) {
                try {
                    $state = $stripe->getSessionReturnState($sessionId);

                    if (($state['status'] ?? '') === 'completed') {
                        $paid = true;
                        break;
                    }
                } catch (\Throwable $e) {
                    // Cannot reach Stripe — leave the event alone rather than
                    // discarding an event that may well have been paid for.
                    Log::warning('Could not check Stripe before discarding a hosted event', [
                        'uuid'  => $game->uuid,
                        'error' => $e->getMessage(),
                    ]);
                    $paid = true;
                    break;
                }
            }

            if ($paid) {
                $settled++;
                $this->line("  kept    {$game->uuid} — Stripe reports it paid");

                continue;
            }

            $this->line(($apply ? '  discard ' : '  would discard ')."{$game->uuid} — {$game->title} ({$game->game_date})");

            if ($apply) {
                $games->discardUnpaidGame((string) $game->uuid, 'The host did not complete payment.');
                $discarded++;
            }
        }

        $this->info($apply
            ? "Discarded {$discarded}, kept {$settled} already paid."
            : 'Dry run — pass --apply to release these courts.');

        return self::SUCCESS;
    }
}
