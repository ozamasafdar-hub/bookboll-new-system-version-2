<?php

namespace App\Http\Middleware;

use App\Models\{Business, BusinessSupportSession, PersonalAccessToken, User};
use App\Support\{AccountAdministration, BusinessSupport};
use Closure;
use Illuminate\Http\Request;

/** Runs globally, including legacy/web routes: support tokens must never escape this policy. */
class BusinessSupportAccess
{
    public function handle(Request $request, Closure $next)
    {
        $token = $request->bearerToken() ? PersonalAccessToken::findToken($request->bearerToken()) : null;
        // An expired/deleted bearer must never fall back to a more privileged web cookie.
        if ($request->bearerToken() && !$token) return response()->json(['success'=>false, 'message'=>'This session has ended. Sign in again.'], 401);
        if (!$token || ($token->name !== 'bookboll-business-support' && !in_array('business-support', $token->abilities ?? [], true))) return $next($request);
        $session = BusinessSupportSession::where('token_id', $token->id)->first();
        $actor = $session ? User::find($session->actor_id) : null;
        $business = $session ? Business::find($session->business_id) : null;
        $source = $session ? PersonalAccessToken::find($session->source_token_id) : null;
        $expiration = config('sanctum.expiration');
        $valid = $session && !$session->ended_at && $session->expires_at->isFuture()
            && $token->expires_at?->isFuture() && $token->name === 'bookboll-business-support' && $token->abilities === ['business-support']
            && $source && $source->tokenable_type === User::class && (int)$source->tokenable_id === (int)$actor?->id
            && (!$source->expires_at || $source->expires_at->isFuture()) && !in_array('business-support', $source->abilities ?? [], true)
            && (!$expiration || $source->created_at->gt(now()->subMinutes($expiration)))
            && $actor?->status === User::ACTIVE && AccountAdministration::isSuperAdmin($actor)
            && $business && in_array($business->status, ['active', 'approved', 'pending'], true)
            && $token->tokenable_type === User::class && (int)$token->tokenable_id === (int)$session->effective_user_id
            && BusinessSupport::eligibleOwner($token->tokenable, $business);
        if (!$valid) {
            $session?->finish('expired_or_access_revoked');
            $token->delete();
            return response()->json(['success'=>false, 'message'=>'Business support ended. Return to your superadmin account.'], 401);
        }
        $request->attributes->set('business_support_session', $session);
        $request->attributes->set('business_support_actor', $actor);
        // A web-session cookie must not override the scoped bearer identity.
        $owner = $token->tokenable->withAccessToken($token);
        \Illuminate\Support\Facades\Auth::guard('sanctum')->setUser($owner);
        \Illuminate\Support\Facades\Auth::shouldUse('sanctum');
        $request->setUserResolver(fn () => $owner);
        if (!$this->allows($request)) {
            $session->audit('business.support.blocked', ['method'=>$request->method(), 'path'=>$request->path()]);
            return response()->json(['success'=>false, 'message'=>'Return to superadmin for payment, cancellation, team, security or integration changes. Support sessions allow routine booking, listing and website edits.'], 403);
        }
        try {
            $response = $next($request);
            return $response;
        } catch (\Throwable $error) {
            $failureStatus = $error instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface ? $error->getStatusCode() : 500;
            throw $error;
        } finally {
            if (!in_array($request->method(), ['GET','HEAD','OPTIONS'], true) && !$request->is('api/business-support/exit')) {
                $session->audit('business.support.action', ['method'=>$request->method(), 'path'=>$request->path(), 'status'=>isset($response)?$response->getStatusCode():($failureStatus ?? 500)]);
            }
        }
    }

    private function allows(Request $r): bool
    {
        $path = $r->path(); $method = $r->method();
        if ($path === 'api/business-support/exit' && $method === 'POST') return true;
        if (in_array($method, ['GET','HEAD'], true)) {
            // Some legacy GET endpoints confirm checkout or trigger delivery.
            if (preg_match('~(?:checkout|stripe|sweep|confirm|generate|connect|security|password|token)~i', $path)) return false;
            return $path === 'api/me' || str_starts_with($path, 'api/admin/')
                || in_array($path, ['api/business/settings','api/business/team','api/business/notification-templates'], true);
        }
        if ($path === 'api/admin/bookings/dashboard' && $method === 'POST') {
            return in_array($r->input('payment_method'), ['cash','payment_skipped'], true);
        }
        if (preg_match('~^api/admin/bookings/\d+/dashboard$~', $path) && $method === 'PUT') {
            return !$r->hasAny(['payment_method','total']) && $r->input('booking_status') !== 'cancelled';
        }
        if (in_array($path, ['api/business/brand','api/admin/site-settings','api/admin/storefront-sort'], true)) return $method === 'PUT';
        if ($path === 'api/uploads/image') return $method === 'POST';
        // Exact dashboard routes only, never venue transfers, ownership or gateway endpoints.
        if (preg_match('~^api/admin/(listings|venues)/dashboard(?:/\d+)?$~', $path)) return in_array($method, ['POST','PUT','PATCH'], true);
        if (preg_match('~^api/admin/(listings|venues)/dashboard/(?:sort|\d+/(?:duplicate|apply-hours))$~', $path)) return $method === 'POST';
        return false;
    }
}
