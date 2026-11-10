<?php
namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/** Private snapshot preview: only its dedicated admin, with no financial writes. */
class PaymentsPreviewAccess
{
    public function handle(Request $request, Closure $next)
    {
        if (!config('preview.enabled')) return $next($request);
        if (config('preview.workspace')) return $this->workspace($request, $next);
        if ($request->is('api/login') && $request->isMethod('POST')) {
            if (strtolower((string)$request->input('email')) !== strtolower(config('preview.admin_email'))) {
                return response()->json(['success'=>false,'message'=>'Use the separate preview administrator account.'],403);
            }
            return $next($request);
        }
        $user = Auth::guard('sanctum')->user();
        if (!$user || $user->email !== config('preview.admin_email')) {
            return response()->json(['success'=>false,'message'=>'Preview sign-in required.'],401);
        }
        if (!in_array($request->method(), ['GET','HEAD','OPTIONS'],true) && !$request->is('api/logout')) {
            return response()->json(['success'=>false,'message'=>'This database snapshot is read-only. Payments, messages and account changes are disabled.'],403);
        }
        return $next($request);
    }

    private function workspace(Request $request, Closure $next)
    {
        // Never expose a live checkout or social login from the copied database.
        if ($request->is('api/auth/*', 'api/customer/google-login', 'api/customer/apple-login',
            'api/customer/auth/exchange', 'api/*webhook*', 'api/*checkout*', 'api/*create-payment-intent',
            'api/*payment-link', 'api/*generate-link', 'api/*stripe/*', 'api/*notifications/email',
            'api/*notifications/whatsapp', 'api/*campaigns/send', 'api/*email-sender/test',
            'api/*whatsapp/action', 'api/*whatsapp/queue/process', 'api/*forgot-password', 'api/*reset-password')) {
            return response()->json(['success'=>false,'message'=>'This integration is disabled in the Railway workspace. No real payment or message is sent.'], 409);
        }
        if ($request->is('api/login', 'api/customer/login')) {
            $allowed = \App\Models\User::where('email', strtolower(trim((string) $request->input('email'))))
                ->where(fn ($q) => $q->where('workspace_access', true)->orWhere('email', config('preview.admin_email')))->exists();
            if (!$allowed) return response()->json(['success'=>false,'message'=>'Use a workspace account. Copied customer and staff logins are disabled.'],403);
            return $next($request);
        }
        if ($request->is('api/register', 'api/business/register', 'api/business/email/*')) {
            // Registration must not reactivate or grant roles to a copied identity.
            if (\App\Models\User::where('email', strtolower(trim((string) $request->input('email'))))->where('workspace_access', false)->exists()) {
                return response()->json(['success'=>false,'message'=>'Use a new email for your workspace test account.'],422);
            }
            return $next($request);
        }
        $user = Auth::guard('sanctum')->user();
        if (!$user && !in_array($request->method(), ['GET','HEAD','OPTIONS'], true)) {
            return response()->json(['success'=>false,'message'=>'Sign in with a workspace account to save changes.'],401);
        }
        if ($user && !$user->workspace_access && $user->email !== config('preview.admin_email')) {
            return response()->json(['success'=>false,'message'=>'This copied account is not enabled in the workspace.'],403);
        }
        // Public marketplace reads remain public; protected routes keep their
        // normal Sanctum, role and capability checks downstream.
        return $next($request);
    }
}
