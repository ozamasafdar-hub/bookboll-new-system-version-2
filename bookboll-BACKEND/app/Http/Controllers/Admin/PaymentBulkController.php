<?php
namespace App\Http\Controllers\Admin;
use App\Http\Controllers\Controller;
use App\Services\PaymentBulkService;
use Illuminate\Http\Request;

final class PaymentBulkController extends Controller
{
    private function input(Request $r,PaymentBulkService $service,bool $save=false): array
    {
        $service->authorize($r->user());
        $r->merge(['reason'=>trim((string)$r->input('reason'))]);
        return $r->validate(['row_ids'=>'required|array|min:1|max:500','row_ids.*'=>'required|string|distinct|max:100|regex:/^[1-9][0-9]*(?::[a-z_]+)?$/D',
            'action'=>'required|in:mark_paid,reassign','destination'=>'nullable|required_if:action,reassign|in:platform_fee,venue_revenue',
            'reason'=>'required|string|min:10|max:1500','fingerprint'=>($save ? 'required' : 'nullable').'|string|size:64']);
    }
    public function preview(Request $r,PaymentBulkService $service) { return sendSuccess('Review selected changes',$service->preview($r->user(),$this->input($r,$service))); }
    public function save(Request $r,PaymentBulkService $service) { return sendSuccess('Selected changes saved',$service->save($r->user(),$this->input($r,$service,true))); }
}
