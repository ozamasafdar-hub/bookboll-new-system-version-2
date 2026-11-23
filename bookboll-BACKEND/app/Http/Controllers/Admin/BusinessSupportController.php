<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\{Business, BusinessSupportSession, PersonalAccessToken, User};
use App\Support\{AccountAdministration, BusinessSupport};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BusinessSupportController extends Controller
{
    public function store(Request $request, Business $business)
    {
        abort_unless(AccountAdministration::isSuperAdmin($request->user()) && $request->user()->status === User::ACTIVE && !BusinessSupport::session(), 403, 'Only active platform superadmins can start business support.');
        $source = $request->user()->currentAccessToken();
        abort_unless($source instanceof PersonalAccessToken && !in_array('business-support', $source->abilities ?? [], true), 403, 'Sign in again before starting business support.');
        abort_unless(in_array($business->status, ['active', 'approved', 'pending'], true), 422, 'Activate this business before starting a support session.');
        $owner = BusinessSupport::ownerFor($business);
        abort_unless($owner, 422, 'Add an active business owner before using Log in as business.');
        return DB::transaction(function () use ($request, $business, $owner, $source) {
            $expires = now()->addMinutes(30);
            if ($source->expires_at && $source->expires_at->lt($expires)) $expires = $source->expires_at;
            $token = $owner->createToken('bookboll-business-support', ['business-support'], $expires);
            $session = BusinessSupportSession::create(['actor_id'=>$request->user()->id, 'business_id'=>$business->id,
                'effective_user_id'=>$owner->id, 'source_token_id'=>$source->id, 'token_id'=>$token->accessToken->id, 'expires_at'=>$expires]);
            $session->audit('business.support.started');
            return sendSuccess('Business support session started', ['token'=>$token->plainTextToken, 'support_session'=>$session->details()]);
        });
    }

    public function destroy()
    {
        $session = BusinessSupport::session();
        abort_unless($session, 403, 'No business support session is active.');
        $session->finish('returned_to_superadmin');
        return sendSuccess('Returned to superadmin');
    }
}
