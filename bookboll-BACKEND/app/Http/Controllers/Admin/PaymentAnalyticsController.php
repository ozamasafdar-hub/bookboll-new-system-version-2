<?php

namespace App\Http\Controllers\Admin;

use App\Services\PaymentReportService;
use Illuminate\Http\Request;

final class PaymentAnalyticsController
{
    public function __invoke(Request $request, PaymentReportService $report)
    {
        return $report->dashboard($request, analyticsOnly: true);
    }
}
