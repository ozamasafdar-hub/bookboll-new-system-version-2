<?php

namespace App\Support;

use App\Models\{AuditLog, User};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class AccountAdministration
{
    public static function isSuperAdmin(?User $actor): bool
    {
        return $actor && $actor->hasRole('super_admin') && !AccessScope::isProviderScoped($actor) && !AccessScope::businessId($actor) && !AccessScope::ownerUserId($actor);
    }

    public static function revokeSessions(User $user): void
    {
        $user->tokens()->delete();
        $user->forceFill(['remember_token' => Str::random(60)])->save();
        if (Schema::hasTable('sessions')) DB::table('sessions')->where('user_id', $user->id)->delete();
    }

    public static function audit(Request $request, string $action, string $resource, int $id, array $details): void
    {
        AuditLog::create(['action' => $action, 'resource' => $resource, 'resource_id' => (string) $id,
            'actor_id' => $request->user()->id, 'actor_email' => $request->user()->email, 'timestamp' => now(),
            'ip_address' => $request->ip(), 'details' => json_encode($details)]);
    }

    public static function history(string $resource, int $id)
    {
        return AuditLog::where('resource', $resource)->where('resource_id', (string) $id)
            ->whereIn('action', ['account.password', 'account.reset_email', 'account.revoke_sessions', 'account.access_updated', 'business.plan_changed'])
            ->latest('id')->limit(10)->get(['id', 'action', 'actor_email', 'timestamp', 'details']);
    }

    public static function guardEdit(Request $request, User $target): void
    {
        abort_if($request->has('password'), 422, 'Use Account security to change a password.');
        if ($target->id === $request->user()->id) {
            abort_if(($request->filled('role') && !$target->hasRole($request->role)) || ($request->filled('status') && $request->status !== User::ACTIVE), 422, 'You cannot remove your own access. Ask another superadmin.');
        }
        if ($target->hasRole('super_admin') && (($request->filled('role') && $request->role !== 'super_admin') || ($request->filled('status') && $request->status !== User::ACTIVE))) {
            abort_unless(User::role('super_admin')->where('status', User::ACTIVE)->where('id', '!=', $target->id)->exists(), 422, 'Keep at least one active superadmin.');
        }
        if (($request->filled('role') && ($target->hasRole('provider_admin') !== ($request->role === 'provider_admin')))
            || ($request->has('owner_user_id') && (int) $request->owner_user_id !== (int) $target->owner_user_id)
            || (AccessScope::businessId($target) && in_array($request->role, config('roles.platform_team_roles'), true))) {
            abort(422, 'Business ownership and account moves require the reviewed business assignment flow.');
        }
    }
}
