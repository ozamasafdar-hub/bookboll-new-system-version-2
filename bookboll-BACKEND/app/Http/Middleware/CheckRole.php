<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if (! $user) {
            if ($request->expectsJson()) {
                return response()->json(['message' => 'Unauthenticated.'], 401);
            }

            return redirect()->guest(route('login'));
        }

        if ($roles === []) {
            abort(403, 'No roles specified for this route.');
        }

        if (! $user->hasAnyRole($roles)) {
            // An API caller gets a JSON refusal whether or not it sent an Accept
            // header: the routes behind this alias all live under /api and are
            // authenticated by a Sanctum token, whose guard has no logout() —
            // the session branch below threw a 500 for every such caller
            // instead of the 403 it meant.
            if ($request->expectsJson() || $request->is('api/*') || ! $request->hasSession()) {
                return response()->json(['message' => 'Forbidden. You do not have the required role.'], 403);
            }

            auth()->guard('web')->logout();
            $request->session()->regenerateToken();

            return redirect()->route('login')->withErrors(['email' => 'Access denied. Admin privileges required.']);
        }

        return $next($request);
    }
}
