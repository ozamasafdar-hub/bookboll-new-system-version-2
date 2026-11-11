<?php
namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\PaymentRefundAccess;
use App\Support\PaymentReleaseGate;
use Illuminate\Http\Request;

class PaymentReleaseController extends Controller
{
    public function show(Request $request, PaymentReleaseGate $gate)
    {
        abort_unless(PaymentRefundAccess::allows($request->user()), 403);
        return sendSuccess('Payment release checks', $gate->status());
    }
}
