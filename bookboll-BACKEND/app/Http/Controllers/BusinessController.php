<?php

namespace App\Http\Controllers;

use App\Services\BusinessService;
use Illuminate\Http\Request;

class BusinessController extends Controller
{
    public function __construct(
        protected BusinessService $businessService,
        protected \App\Services\EmailVerificationService $emailVerification,
        protected \App\Services\BusinessIdentityService $identity,
        protected \App\Services\BusinessAssignmentService $assignments,
    ) {}

    /** What business administration this deployment actually supports. */
    public function capabilities(Request $request)
    {
        return $this->identity->capabilities($request);
    }

    /** Register a business with no owner account (readmeAlamgir §1). */
    public function adminStore(Request $request)
    {
        return $this->identity->store($request);
    }

    public function inviteMember(Request $request, $id)
    {
        return app(\App\Services\BusinessTeamInviteService::class)->invite($request, (int) $id);
    }

    public function invitationOptions(Request $request, $id)
    {
        return app(\App\Services\BusinessTeamInviteService::class)->options($request, (int) $id);
    }

    /* Moving an account into a business: options, reviewed preview, commit. */

    /** Assignable accounts for a business that does not exist yet (Add business form). */
    public function assignmentCandidates(Request $request)
    {
        return $this->assignments->newBusinessCandidates($request);
    }

    public function assignmentOptions(Request $request, $id)
    {
        return $this->assignments->options($request, (int) $id);
    }

    public function assignmentPreview(Request $request, $id)
    {
        return $this->assignments->preview($request, (int) $id);
    }

    public function assignmentStore(Request $request, $id)
    {
        return $this->assignments->assign($request, (int) $id);
    }

    public function register(Request $request)
    {
        return $this->businessService->register($request);
    }

    /** Signup step 1: email the six-digit code. */
    public function sendEmailOtp(Request $request)
    {
        return $this->emailVerification->sendOtp($request);
    }

    /** Signup step 1: accept the code and issue the proof registration will present. */
    public function verifyEmailOtp(Request $request)
    {
        return $this->emailVerification->verifyOtp($request);
    }

    public function usernameAvailable(Request $request)
    {
        return $this->businessService->usernameAvailable($request);
    }

    public function countries(Request $request)
    {
        return $this->businessService->countries($request);
    }

    public function updateBrand(Request $request)
    {
        return $this->businessService->updateBrand($request);
    }

    public function adminIndex(Request $request)
    {
        return $this->businessService->adminIndex($request);
    }

    public function teamIndex(Request $request)
    {
        return $this->businessService->teamIndex($request);
    }

    public function teamStore(Request $request)
    {
        return $this->businessService->teamStore($request);
    }

    public function teamUpdate(Request $request, $user)
    {
        return $this->businessService->teamUpdate($request, $user);
    }

    public function teamDestroy(Request $request, $user)
    {
        return $this->businessService->teamDestroy($request, $user);
    }
}
