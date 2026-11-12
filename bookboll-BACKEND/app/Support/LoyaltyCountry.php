<?php

namespace App\Support;

use App\Models\LoyaltyPoint;
use App\Models\LoyaltyProgram;
use App\Models\User;

/**
 * Loyalty, scoped to the country whose programme actually governs it.
 *
 * Earn rate, redeem rate, minimum, cap and expiry are all per country, so a
 * balance only means something next to the country it was earned in. The ledger
 * was global, which made "250 points" an answer to a question nobody had asked.
 *
 * Legacy movements carry no country and are deliberately NOT counted toward any
 * of them. Folding them into the country being asked about would invent
 * spendable points in a programme the customer may never have used; folding
 * them in everywhere would let the same point be spent twice. They are reported
 * separately as unscoped so the gap is visible rather than quietly absorbed.
 */
class LoyaltyCountry
{
    /**
     * Spendable balance, lifetime earned, and what is stranded on legacy rows.
     *
     * @return array{balance:int, lifetime_earned:int, unscoped:int}
     */
    public static function balance(int $userId, int $countryId): array
    {
        $sum = function (string $type, bool $scoped) use ($userId, $countryId) {
            return (int) LoyaltyPoint::query()
                ->where('user_id', $userId)
                ->where('type', $type)
                ->when($scoped, fn ($q) => $q->where('country_id', $countryId))
                ->when(! $scoped, fn ($q) => $q->whereNull('country_id'))
                ->sum('points');
        };

        $rows = LoyaltyPoint::where('user_id', $userId)->where('country_id', $countryId)
            ->orderBy('created_at')->orderBy('id')->get();
        $credit = 0;
        $lots = [];
        $overdrawn = 0;
        foreach ($rows as $row) {
            $points = max(0, (int) $row->points);
            if ($row->type === 'credit') {
                $credit += $points;
                $offset = min($overdrawn, $points);
                $overdrawn -= $offset;
                $lots[] = ['points' => $points - $offset, 'expires' => $row->expires_at?->getTimestamp()];
                continue;
            }
            // Spend earliest-expiring points first. Expired lots cannot fund a later debit.
            usort($lots, fn ($a, $b) => ($a['expires'] ?? PHP_INT_MAX) <=> ($b['expires'] ?? PHP_INT_MAX));
            foreach ($lots as &$lot) {
                if ($lot['expires'] !== null && $lot['expires'] <= $row->created_at->getTimestamp()) continue;
                $used = min($lot['points'], $points);
                $lot['points'] -= $used;
                $points -= $used;
                if ($points === 0) break;
            }
            unset($lot);
            $overdrawn += $points;
        }
        $balance = array_sum(array_map(fn ($lot) => $lot['expires'] === null || $lot['expires'] > now()->getTimestamp() ? $lot['points'] : 0, $lots));

        return [
            'balance'         => max(0, $balance - $overdrawn),
            'lifetime_earned' => $credit,
            // Visible, never spendable, and never attributed to a country.
            'unscoped'        => max(0, $sum('credit', false) - $sum('debit', false)),
        ];
    }

    /** The active programme for a country, or null when none is configured. */
    public static function program(int $countryId): ?LoyaltyProgram
    {
        $program = LoyaltyProgram::effective($countryId);
        return $program->is_active ? $program : null;

    }

    /**
     * The tiers of THIS country's programme — thresholds and multipliers read
     * from it, never reused from another country or a global default.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function tiers(?LoyaltyProgram $program): array
    {
        if (! $program) {
            return [];
        }

        return $program->tiers->where('is_active', true)->map(fn ($t) => [
            'name'       => $t->name,
            'slug'       => $t->slug,
            'threshold'  => (int) $t->min_points,
            'multiplier' => (float) ($t->multiplier ?? 1),
        ])->values()->all();
    }

    /**
     * Country of the programme that governs a booking: the venue's country.
     * Null when it cannot be resolved, so the movement is recorded unscoped
     * rather than attributed to a guess.
     */
    public static function countryForVenue($venue): ?int
    {
        if (! $venue) {
            return null;
        }

        if ($venue->country_id) {
            return (int) $venue->country_id;
        }

        return $venue->city_id
            ? (int) (\Nnjeim\World\Models\City::where('id', $venue->city_id)->value('country_id') ?: 0) ?: null
            : null;
    }

    /** The customer's own country, as a last resort for non-venue activity. */
    public static function countryForUser(?User $user): ?int
    {
        return $user?->country_id ? (int) $user->country_id : null;
    }
}
