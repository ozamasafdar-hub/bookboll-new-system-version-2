<?php
namespace App\Console\Commands;

use App\Models\User;
use App\Services\PaymentReportService;
use Illuminate\Console\Command;
use Illuminate\Http\Request;

class WarmPaymentReport extends Command
{
    protected $signature = 'payments:warm-report';
    protected $description = 'Warm the isolated workspace administrator payment report after deployment';

    public function handle(): int
    {
        if (!config('preview.enabled')) return self::SUCCESS;
        $admin = User::where('email', config('preview.admin_email'))->firstOrFail();
        if (config('preview.workspace') && !$admin->workspace_access) {
            $admin->forceFill(['workspace_access'=>true])->save();
        }
        $request = Request::create('/api/admin/payments/dashboard', 'GET', ['refresh'=>1]);
        $request->setUserResolver(fn () => $admin);
        app(PaymentReportService::class)->dashboard($request);
        $this->info('Payment report cache ready.');
        return self::SUCCESS;
    }
}
