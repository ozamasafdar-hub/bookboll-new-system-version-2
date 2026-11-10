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
}
