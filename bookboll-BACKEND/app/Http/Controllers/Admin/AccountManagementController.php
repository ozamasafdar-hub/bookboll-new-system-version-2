<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\{AuditLog, Business, User};
use App\Services\UserService;
use App\Support\{AccessScope, AccountAdministration};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rules\Password;

class AccountManagementController extends Controller
{
    private function authorizeActor(Request $request): void
    {
        abort_unless(AccountAdministration::isSuperAdmin($request->user()), 403, 'Only platform superadmins can manage account security or complimentary plans.');
    }

    private function target(int $id): User
    {
        $user = User::findOrFail($id);
        abort_unless($user->hasAnyRole(array_merge(config('roles.staff_roles'), config('roles.provider_roles'), config('roles.provider_team_roles'))) || AccessScope::businessId($user), 422, 'Choose a staff or business team account.');
        return $user;
    }

    public function account(Request $request, int $id)
    {
        $this->authorizeActor($request);
        $user = $this->target($id);
        return sendSuccess('Account security', [
            'active_sessions' => $user->tokens()->count(),
            'history' => AccountAdministration::history('user', $id),
        ]);
    }

    public function security(Request $request, int $id)
    {
        $this->authorizeActor($request);
        $user = $this->target($id);
        abort_if($user->id === $request->user()->id, 422, 'Use your profile settings to change your own password.');
        $data = $request->validate([
            'action' => 'required|in:password,reset_email,revoke_sessions',
            'reason' => 'required|string|min:5|max:500',
            'password' => ['required_if:action,password', 'nullable', 'string', 'max:128', Password::min(12)->letters()->numbers(), 'confirmed'],
        ]);
        $delivery = null;
        if ($data['action'] === 'reset_email') {
            abort_unless($user->status === User::ACTIVE, 422, 'Activate this account before sending a reset email.');
            if (config('preview.workspace') || config('preview.enabled')) {
                $delivery = 'preview';
            } else {
                $reset = Request::create('/', 'POST', ['email' => $user->email]);
                $response = app(UserService::class)->adminForgotPassword($reset);
                if (!$response->getData(true)['success']) return $response;
                $delivery = 'sent';
            }
            AccountAdministration::audit($request, 'account.reset_email', 'user', $id, ['delivery' => $delivery, 'reason' => $data['reason']]);
        } else {
            DB::transaction(function () use ($request, $id, $data) {
                $target = User::whereKey($id)->lockForUpdate()->firstOrFail();
                if ($data['action'] === 'password') {
                    $target->forceFill(['password' => $data['password']])->save();
                    DB::table('password_reset_tokens')->where('email', $target->email)->delete();
                }
                AccountAdministration::revokeSessions($target);
                AccountAdministration::audit($request, 'account.'.$data['action'], 'user', $id, ['reason' => $data['reason']]);
            });
        }
        return sendSuccess($delivery === 'preview' ? 'Reset email simulated: this workspace does not send emails.' : ($delivery === 'sent' ? 'Password reset code sent by email.' : 'Account updated. Existing sessions have been signed out.'), ['delivery' => $delivery]);
    }

    public function plan(Request $request, int $id)
    {
        $this->authorizeActor($request);
        $business = Business::findOrFail($id);
        return sendSuccess('Business plan', [
            'plan' => $business->plan ?: 'free',
            'override' => $business->plan_override,
            'billing_conflict' => $this->billingConflict($business),
            'history' => AccountAdministration::history('business', $id),
        ]);
    }

    private function billingConflict(Business $business): bool
    {
        return $business->subscriptions()->whereIn('status', ['active', 'trialing', 'past_due', 'unpaid', 'incomplete', 'paused'])
            ->where(fn ($q) => $q->whereNotNull('stripe_subscription_id')->orWhereNotNull('stripe_checkout_session_id'))->exists();
    }

    public function updatePlan(Request $request, int $id)
    {
        $this->authorizeActor($request);
        $data = $request->validate(['plan' => 'required|in:premium,free,billing', 'reason' => 'required|string|min:5|max:500', 'expected_plan' => 'required|in:premium,free', 'expected_override' => 'present|nullable|in:premium,free']);
        return DB::transaction(function () use ($request, $id, $data) {
            $business = Business::whereKey($id)->lockForUpdate()->firstOrFail();
            abort_if(($business->plan ?: 'free') !== $data['expected_plan'] || $business->plan_override !== $data['expected_override'], 409, 'The plan changed. Refresh this review before saving.');
            abort_if($data['plan'] !== 'billing' && $this->billingConflict($business), 409, 'This business has a Stripe subscription or unfinished checkout. Resolve its billing first; a complimentary plan must not leave it being charged.');
            $before = ['plan' => $business->plan, 'override' => $business->plan_override];
            $override = $data['plan'] === 'billing' ? null : $data['plan'];
            $plan = $override ?? ($business->currentSubscription()->first()?->isEntitling() ? 'premium' : 'free');
            $business->forceFill([
                'plan' => $plan, 'plan_override' => $override,
                'plan_override_by' => $request->user()->id, 'plan_override_at' => now(),
                'premium_since' => $plan === 'premium' ? ($business->premium_since ?: now()) : null,
                'brand_whitelabel' => $plan === 'free' ? false : $business->brand_whitelabel,
            ])->save();
            AccountAdministration::audit($request, 'business.plan_changed', 'business', $id, ['before' => $before, 'after' => ['plan' => $plan, 'override' => $override], 'reason' => $data['reason']]);
            return sendSuccess('Business plan updated. No payment was taken.', ['plan' => $plan, 'override' => $override]);
        });
    }
}
