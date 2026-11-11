<?php
namespace App\Console\Commands;

use App\Support\PaymentReleaseGate;
use Illuminate\Console\Command;

class CheckPaymentRelease extends Command
{
    protected $signature = 'payments:release-check {--json} {--identity : Print the identity to bind release evidence} {--scope=payments : payments or refunds} {--allow-workspace : Accept the guarded isolated workspace, never for refunds}';
    protected $description = 'Verify required release evidence; exits nonzero when launch is blocked';

    public function handle(PaymentReleaseGate $gate): int
    {
        if (!in_array($this->option('scope'), ['payments','refunds'], true)) {
            $this->error('Scope must be payments or refunds.');
            return self::FAILURE;
        }
        $status = $gate->status();
        if ($this->option('identity')) {
            try { $status['identity'] = $gate->identity(); }
            catch (\Throwable $e) { $this->error('Install the required migrations before exporting release identity.'); return self::FAILURE; }
        }
        if ($this->option('json')) $this->line(json_encode($status, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        else {
            $this->table(['Check','Result'], array_map(fn ($c) => [$c['label'], $c['passed'] ? 'PASS' : 'BLOCKED'], $status['checks']));
            if (isset($status['identity'])) $this->line(json_encode($status['identity'], JSON_PRETTY_PRINT));
        }
        $allowed = $this->option('scope') === 'refunds' ? $status['refunds_ready'] : ($status['production_ready']
            || ($this->option('allow-workspace') && $status['workspace_ready']));
        if (!$this->option('json')) $this->{$allowed ? 'info' : 'error'}($allowed ? 'Selected environment checks passed.' : 'Release blocked. No approval or payment data has been changed.');
        return $allowed ? self::SUCCESS : self::FAILURE;
    }
}
