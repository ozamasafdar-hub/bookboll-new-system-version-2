<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\GeographyService;
use Illuminate\Http\Request;

class GeographyController extends Controller
{
    public function __construct(protected GeographyService $geography) {}

    public function countries(Request $request)
    {
        return $this->geography->countries($request);
    }

    public function cities(Request $request)
    {
        return $this->geography->cities($request);
    }

    public function updateCountryStatus(Request $request, int $id)
    {
        return $this->geography->updateCountryStatus($request, $id);
    }

    public function updateCityStatus(Request $request, int $id)
    {
        return $this->geography->updateCityStatus($request, $id);
    }

    public function bulkUpdateCityStatus(Request $request)
    {
        return $this->geography->bulkUpdateCityStatus($request);
    }

    public function updateCityModule(Request $request, int $id)
    {
        return $this->geography->updateCityModule($request, $id);
    }

    public function upsertCommission(Request $request)
    {
        return $this->geography->upsertCommission($request);
    }

    public function loyaltyPrograms()
    {
        return $this->geography->loyaltyPrograms();
    }

    public function upsertLoyaltyProgram(Request $request)
    {
        return $this->geography->upsertLoyaltyProgram($request);
    }
    public function settings(Request $request, string $scope, int $id)
    {
        return $this->geography->settings($request, $scope, $id);
    }

}
