<?php

namespace App\Services;

use App\Models\LoyaltyPoint;
use App\Models\LoyaltyTier;
use App\Models\Transaction;
use App\Models\User;
use App\Support\LoyaltyCountry;
use Illuminate\Http\Request;
use PaymentStatus;

class LoyaltyPointService
{
    public function __construct(protected LoyaltyPoint $loyaltyPointModel) {}

    public function extraDataForLoyalty(Request $request)
    {
        $customers = User::whereRelation('roles', 'name', 'customer')
            ->withCount(['bookings'])
            ->addSelect([
                'loyalty_balance' => function ($q) {
                    $q->selectRaw('
                COALESCE(SUM(CASE WHEN type = "credit" THEN points ELSE 0 END), 0)
                - COALESCE(SUM(CASE WHEN type = "debit" THEN points ELSE 0 END), 0)
            ')
                        ->from('loyalty_points')
                        ->whereColumn('loyalty_points.user_id', 'users.id');
                },
                'total_spent'     => function ($q) {
                    $q->selectRaw('COALESCE(SUM(amount), 0)')
                        ->from('payments')
                        ->whereColumn('payments.user_id', 'users.id')
                        ->where('payments.status', PaymentStatus::PAID);
                },
            ])
            ->orderByDesc('loyalty_balance')->take(10)->get();

        $recentRedeemptions = Transaction::with(['user:id,name,email', 'giftCard:id,title,points_required'])->latest('id')
            ->whereNotNull('gift_card_id')->credit()->take(5)->get();

        $totalEarned   = $this->loyaltyPointModel::where('type', 'credit')->sum('points');
        $totalRedeemed = $this->loyaltyPointModel::where('type', 'debit')->sum('points');

        $stats = [
            'active_members'  => User::active()->whereRelation('roles', 'name', 'customer')->count(),
            'redemption_rate' => $totalEarned > 0 ? round(($totalRedeemed / $totalEarned) * 100, 2) : 0,
            'points_issued'   => $totalEarned ?? 0,
            'points_redeemed' => $totalRedeemed ?? 0,
        ];

        return sendSuccess('Loyalty data fetched successfully', compact('customers', 'recentRedeemptions', 'stats'));
    }

    /**
     * This customer's loyalty in ONE country.
     *
     * The country is required and always echoed back, including on a zero
     * balance, because the storefront refuses to present an unscoped number as
     * a country's balance — rightly, since earn and redeem rates differ per
     * country and a global total is not spendable anywhere in particular.
     *
     * The customer comes from the token. A customer id in the request is never
     * accepted.
     */
    public function customerLoyalty(Request $request)
    {
        $user = auth()->user();
        if (! $user) {
            return sendError('Not authenticated.', [], 401);
        }

        $validated = $request->validate([
            'country_id' => 'required|integer|exists:countries,id',
        ], [
            'country_id.required' => 'A country is required: loyalty balances are per country.',
        ]);

        $countryId = (int) $validated['country_id'];
        $program   = LoyaltyCountry::program($countryId);
        $totals    = LoyaltyCountry::balance($user->id, $countryId);

        $ledger = $this->loyaltyPointModel::query()
            ->where('user_id', $user->id)
            ->where('country_id', $countryId)
            ->orderByDesc('id')
            ->limit(40)
            ->get(['id', 'points', 'type', 'reason', 'booking_id', 'created_at', 'expires_at'])
            ->map(fn ($row) => [
                'id'         => $row->id,
                'country_id' => $countryId,
                // Signed, so a spend reads as a spend rather than an earn.
                'points'     => $row->type === 'debit' ? -(int) $row->points : (int) $row->points,
                'reason'     => $row->reason ?: '',
                'booking_id' => $row->booking_id,
                'created_at' => optional($row->created_at)?->toIso8601String(),
                'expires_at' => $row->expires_at?->toIso8601String(),
            ])->values()->all();

        return sendSuccess('Loyalty fetched successfully', [
            // Always present, so a stale response for another country can be
            // rejected by the client rather than shown as this one's.
            'country_id'      => $countryId,
            'balance'         => $totals['balance'],
            'lifetime_earned' => $totals['lifetime_earned'],
            // Points on movements recorded before the ledger was country-scoped.
            // Reported so the gap is visible; never spendable, and never added
            // to a country's balance.
            'unscoped_points' => $totals['unscoped'],
            'program'         => $program ? [
                'earn_rate'     => (float) $program->earn_rate,
                'redeem_rate'   => (float) $program->redeem_rate,
                'redeem_value'  => (float) $program->redeem_value,
                'min_redeem'    => (int) $program->min_redeem,
                'max_redeem_pct' => $program->max_redeem_pct === null ? null : (float) $program->max_redeem_pct,
                'expiry_months' => (int) $program->expiry_months,
                'is_active'     => (bool) $program->is_active,
            ] : null,
            'tiers'  => LoyaltyCountry::tiers($program),
            'ledger' => $ledger,
            // Venue checkout enforces the quote: it is validated against the
            // server's own prices, its points are reserved when payment is
            // attempted, consumed on confirmation and released on failure.
            // Membership, game and tournament payments cannot honour a quote
            // yet and REFUSE one outright rather than charging full price
            // while the customer believes their points were applied, so this
            // promises nothing that silently fails.
            'redemption_checkout_v1' => true,
        ]);
    }
}
