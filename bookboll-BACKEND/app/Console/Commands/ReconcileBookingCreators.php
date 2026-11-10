<?php
namespace App\Console\Commands;

use App\Models\Booking;
use App\Services\BookingCreatorReconciliation;
use App\Support\BookingProvenance;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ReconcileBookingCreators extends Command
{
    protected $signature = 'bookings:reconcile-creators {--apply : Save audited corrections in the Railway workspace copy}';
    protected $description = 'Inspect original booking authors and correct legacy staff attribution';

    public function handle(BookingCreatorReconciliation $reconciliation): int
    {
        if ($this->option('apply') && (!config('preview.workspace') || DB::connection()->getDatabaseName() !== 'bookboll_preview')) {
            $this->error('Writes are restricted to the isolated Railway workspace database.'); return 1;
        }
        $counts = [];
        Booking::whereNull('creation_context')->with(['creator.roles','creationHistory','user','payments.ownedAllocations'])
            ->chunkById(500, function ($rows) use (&$counts, $reconciliation) {
                $process = function () use ($rows, &$counts, $reconciliation) {
                    foreach ($rows as $booking) {
                        $creation = BookingProvenance::legacy($booking);
                        $counts[$creation['channel']] = ($counts[$creation['channel']] ?? 0) + 1;
                        if ($this->option('apply')) $reconciliation->apply($booking, $creation);
                    }
                };
                // One disk commit per batch; a failed batch resumes safely.
                if ($this->option('apply')) DB::transaction($process); else $process();
            });
        $this->info(($this->option('apply') ? 'Applied' : 'Dry run').': '.json_encode($counts).'; receipt amounts and statuses preserved.');
        return self::SUCCESS;
    }
}
