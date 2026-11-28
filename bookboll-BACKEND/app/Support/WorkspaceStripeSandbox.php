<?php
namespace App\Support;

use Illuminate\Support\Facades\DB;

/** Opt-in Stripe sandbox on the Railway database copy; never a production release bypass. */
final class WorkspaceStripeSandbox
{
    public static function enabled(): bool
    {
        return config('preview.enabled') && config('preview.workspace') && config('preview.stripe_sandbox')
            && str_ends_with((string) parse_url((string) config('app.url'), PHP_URL_HOST), '.up.railway.app')
            && DB::connection()->getDatabaseName() === 'bookboll_preview'
            && preg_match('/^sk_test_[A-Za-z0-9]+$/D', (string) config('services.stripe.secret')) === 1
            && preg_match('/^pk_test_[A-Za-z0-9]+$/D', (string) config('services.stripe.key')) === 1;
    }

    public static function allowsBookingRoute(\Illuminate\Http\Request $request): bool
    {
        if (!self::enabled()) return false;
        $path = $request->path();
        return ($request->isMethod('POST') && in_array($path, ['api/customer/payments/create-checkout-session', 'api/customer/loyalty/checkout-quote'], true))
            || ($request->isMethod('GET') && preg_match('#^api/customer/payments/checkout-session/cs_test_[A-Za-z0-9]+$#D', $path))
            || ($request->isMethod('POST') && preg_match('#^api/customer/payments/checkout-session/cs_test_[A-Za-z0-9]+/abandon$#D', $path));
    }
}
