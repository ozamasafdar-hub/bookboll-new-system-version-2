<?php
namespace App\Http\Controllers;
use App\Services\MembershipListingService;
use Illuminate\Http\Request;

class MembershipListingController extends Controller
{
    public function __construct(protected MembershipListingService $service) {}
    public function index(Request $r) { return $this->service->index($r); }
    public function checkout(Request $r, int $id) { return $this->service->checkout($r, $id); }
    public function billing(Request $r, int $id) { return $this->service->billingAction($r, $id); }
}
