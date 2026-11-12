<?php
namespace App\Http\Middleware;
use Closure;
use Illuminate\Http\Request;
use App\Support\MembershipListingPolicy;
class RetiredMembershipOperations
{
    public function handle(Request $request, Closure $next)
    {
        $path = $request->path();
        $legacy = str_starts_with($path, 'api/customer/programs/')
            || str_starts_with($path, 'api/admin/checkin')
            || str_starts_with($path, 'api/admin/subscriptions/dashboard/');
        if ($path === 'api/admin/subscriptions/dashboard' && !$request->isMethod('get')) $legacy = true;
        if (str_contains($path, 'wallet-payouts') && in_array($request->input('purpose') ?? $request->input('type') ?? $request->input('wallet_type'), ['subscription', 'membership'], true)) $legacy = true;
        // Old wallet IDs must not bypass retirement by omitting the purpose field.
        // Historical records stay readable; new money operations are retired.
        if (str_contains($path, 'wallet-payouts') && !$request->isMethod('get')) {
            $walletId = $request->input('wallet_id');
            if (preg_match('#wallet-payouts/wallets/(\d+)$#', $path, $m)) $walletId = $m[1];
            if (preg_match('#wallet-payouts/withdrawals/(\d+)/#', $path, $m)) $walletId = \App\Models\WithdrawalRequest::find($m[1])?->wallet_id;
            if (preg_match('#wallet-payouts/transactions/(\d+)$#', $path, $m)) $walletId = \App\Models\WalletTransaction::find($m[1])?->wallet_id;
            if ($walletId && in_array(\App\Models\ProviderWallet::find($walletId)?->purpose, ['subscription', 'membership'], true)) $legacy = true;
        }
        return $legacy ? MembershipListingPolicy::retired() : $next($request);
    }
}
