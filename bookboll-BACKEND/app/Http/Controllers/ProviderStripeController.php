<?php
namespace App\Http\Controllers;
use App\Services\ProviderStripeService;
use Illuminate\Http\Request;
class ProviderStripeController extends Controller {
    public function __construct(private ProviderStripeService $service) {}
    public function index(Request $r) { return $this->service->index($r); }
    public function connect(Request $r, int $id) { return $this->service->connect($r,$id); }
    public function refresh(Request $r, int $id) { return $this->service->refresh($r,$id); }
    public function callback(Request $r) { return $this->service->callback($r); }
}
