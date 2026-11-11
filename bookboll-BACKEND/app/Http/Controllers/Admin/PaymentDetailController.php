<?php
namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\PaymentDetailService;
use App\Services\PaymentRefundService;
use Illuminate\Http\Request;

class PaymentDetailController extends Controller
{
    public function show(Request $request, int $id, PaymentDetailService $details)
    {
        $request->validate(['row_id'=>'nullable|string|max:100']);
        return sendSuccess('Payment details', $details->details($request->user(), $id, $request->input('row_id')));
    }
    public function preview(Request $request, int $id, PaymentRefundService $refunds)
    {
        return sendSuccess('Review full Stripe refund', $refunds->preview($request->user(), $id));
    }
    private function reviewInput(Request $request, bool $save = false): array
    {
        $request->merge(['reason'=>trim((string)$request->input('reason'))]);
        return $request->validate(['action'=>'required|in:cancelled_uncollected,retained_deposit,provider_split,note,reopen',
            'reason'=>'required|string|min:10|max:1500','fee_amount'=>'nullable|numeric|min:0',
            'fingerprint'=>($save ? 'required' : 'nullable').'|string|size:64']);
    }
    public function reviewPreview(Request $request, int $id, \App\Services\PaymentReviewService $reviews)
    {
        return sendSuccess('Review reporting change',$reviews->preview($request->user(),$id,$this->reviewInput($request)));
    }
    public function reviewSave(Request $request, int $id, \App\Services\PaymentReviewService $reviews)
    {
        return sendSuccess('Payment review saved',$reviews->save($request->user(),$id,$this->reviewInput($request,true)));
    }
    public function collectionPreview(Request $request, int $id, \App\Services\CheckoutPaymentCollection $collections)
    {
        $request->validate(['row_id'=>'nullable|string|max:100']);
        return sendSuccess('Review remaining payment', $collections->preview($request->user(), $id, $request->input('row_id')));
    }
    public function collectCheckout(Request $request, int $id, \App\Services\CheckoutPaymentCollection $collections)
    {
        $data = $request->validate(['row_id'=>'nullable|string|max:100','fingerprint'=>'required|string|size:64','method'=>'required|in:cash,card,cheque,wire_transfer']);
        return sendSuccess('Remaining payment recorded', $collections->collect($request->user(), $id, $data));
    }
    public function refund(Request $request, int $id, PaymentRefundService $refunds)
    {
        $data = $request->validate(['fingerprint'=>'required|string|size:64', 'reason'=>'required|in:requested_by_customer,duplicate,fraudulent']);
        return sendSuccess('Stripe refund request recorded', $refunds->refund($request->user(), $id, $data['fingerprint'], $data['reason']));
    }
    public function refresh(Request $request, int $id, PaymentRefundService $refunds)
    {
        return sendSuccess('Refund status refreshed', $refunds->refresh($request->user(), $id));
    }
}
