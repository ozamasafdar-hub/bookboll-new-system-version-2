<?php
namespace App\Http\Middleware;

use App\Support\PaymentReleaseGate;
use Closure;
use Illuminate\Http\Request;

/** Until cutover is verified the unapproved production application is read-only. */
class RequirePaymentRelease
{
    public function handle(Request $request, Closure $next)
    {
        // Sign-in and sign-out must work so the release owner can inspect blockers.
        // No blanket /auth/* exception: OAuth and other routes can create accounts.
        $access = $request->is('api/login', 'api/logout', 'api/customer/login', 'api/customer/logout',
            'api/admin-app/login', 'login-000009', 'admin/logout-000009');
        // These legacy GET routes settle a payment and are not read-only.
        $settles = $request->is('payment/success/*', 'payment/status/*', 'payment/cancel');
        if ((!in_array($request->method(), ['GET','HEAD','OPTIONS'], true) && !$access) || $settles) {
            try { app(PaymentReleaseGate::class)->assertReady(); }
            catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
                // In particular, never acknowledge a processor webhook that was
                // not processed. Stripe can retry the 503 after controlled cutover.
                return response()->json(['success'=>false, 'code'=>'payment_release_blocked',
                    'message'=>$e->getMessage()], 503, ['Retry-After'=>'60']);
            }
        }
        return $next($request);
    }
}
