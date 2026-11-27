<?php

namespace App\Console\Commands;

use App\Models\Invoice;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Housekeeping for booking invoices.
 *
 * Every payment change regenerates a booking's invoice: the old row is marked
 * re_generated and a new PDF is written. Nothing ever removed the old ones, so
 * a booking paid in three steps carried four PDFs, and the invoices directory
 * grew with every payment on the platform. Only the newest invoice per booking
 * is ever read (Booking::latestInvoice, the confirmation email, the dashboard).
 *
 * Reports by default. Nothing is deleted without --delete, and --delete asks
 * before it starts unless run with --no-interaction. The newest invoice of a
 * booking is never deleted, whatever the other options say.
 */
class PruneBookingInvoices extends Command
{
    protected $signature = 'invoices:prune
        {--keep=1 : Invoices to keep per booking, newest first (never below 1)}
        {--booking=* : Only these booking ids}
        {--older-than=0 : Only delete rows created more than this many days ago}
        {--missing-files : Also delete rows whose PDF is no longer on disk}
        {--orphan-files : Also delete PDFs in the invoices directory that no row points to}
        {--limit=0 : Most bookings to process in one run (0 = all)}
        {--delete : Actually delete; without it the command only reports}';

    protected $description = 'Delete superseded booking invoice PDFs and rows, keeping the newest per booking';

    public function handle(): int
    {
        $keep      = max(1, (int) $this->option('keep'));
        $olderThan = max(0, (int) $this->option('older-than'));
        $limit     = max(0, (int) $this->option('limit'));
        $delete    = (bool) $this->option('delete');
        $bookings  = array_values(array_filter(array_map('intval', (array) $this->option('booking'))));
        $disk      = Storage::disk('public');

        $this->line($delete ? '<fg=yellow>DELETE MODE</>' : '<fg=green>Report only</> — add --delete to remove anything.');

        // ── 1. Superseded invoices: everything but the newest N per booking ──
        $multi = Invoice::query()
            ->select('booking_id', DB::raw('COUNT(*) as total'))
            ->whereNotNull('booking_id')
            ->whereNull('payment_id')                 // per-payment invoices are out of scope
            ->when($bookings, fn ($q) => $q->whereIn('booking_id', $bookings))
            ->groupBy('booking_id')
            ->having('total', '>', $keep)
            ->orderByDesc('total')
            ->when($limit, fn ($q) => $q->limit($limit))
            ->get();

        $rows = [];
        $toDelete = [];          // Invoice models
        $bytes = 0;

        foreach ($multi as $group) {
            $invoices = Invoice::where('booking_id', $group->booking_id)
                ->whereNull('payment_id')
                ->orderByDesc('id')
                ->get();

            // The newest N stay, whatever their status or age.
            $superseded = $invoices->slice($keep)
                ->filter(fn (Invoice $i) => $olderThan === 0 || $i->created_at?->lt(now()->subDays($olderThan)));

            if ($superseded->isEmpty()) {
                continue;
            }

            $size = 0;
            foreach ($superseded as $inv) {
                if ($inv->path && $disk->exists($inv->path)) {
                    $size += $disk->size($inv->path);
                }
                $toDelete[] = $inv;
            }
            $bytes += $size;

            $rows[] = [
                $group->booking_id,
                $invoices->count(),
                $invoices->first()->invoice_number,
                $superseded->count(),
                $this->human($size),
            ];
        }

        if ($rows) {
            $this->table(['Booking', 'Invoices', 'Keeping (newest)', 'Superseded', 'Disk'], array_slice($rows, 0, 50));
            if (count($rows) > 50) {
                $this->line('… and ' . (count($rows) - 50) . ' more bookings.');
            }
        }
        $this->info(sprintf('%d superseded invoice(s) across %d booking(s), %s on disk.', count($toDelete), count($rows), $this->human($bytes)));

        // ── 2. Rows whose file is gone (nothing on disk to lose) ───────────
        $missing = collect();
        if ($this->option('missing-files')) {
            $missing = Invoice::query()
                ->whereNotNull('path')
                ->when($bookings, fn ($q) => $q->whereIn('booking_id', $bookings))
                ->cursor()
                ->filter(fn (Invoice $i) => ! $disk->exists($i->path))
                // Never the newest of a booking — a missing latest is a problem
                // to fix by regenerating, not by forgetting it existed.
                ->reject(fn (Invoice $i) => $i->booking_id && Invoice::where('booking_id', $i->booking_id)->max('id') === $i->id)
                ->values();
            $this->info(sprintf('%d invoice row(s) point at a PDF that is not on disk.', $missing->count()));
        }

        // ── 3. Files no row points to ──────────────────────────────────────
        $orphans = [];
        $orphanBytes = 0;
        if ($this->option('orphan-files')) {
            $known = Invoice::whereNotNull('path')->pluck('path')->flip();
            foreach ($disk->files('invoices') as $file) {
                if (! isset($known[$file])) {
                    $orphans[] = $file;
                    $orphanBytes += $disk->size($file);
                }
            }
            $this->info(sprintf('%d orphan PDF(s) in invoices/, %s.', count($orphans), $this->human($orphanBytes)));
        }

        $totalActions = count($toDelete) + $missing->count() + count($orphans);
        if ($totalActions === 0) {
            $this->info('Nothing to do.');

            return self::SUCCESS;
        }

        if (! $delete) {
            $this->line("Re-run with <options=bold>--delete</> to remove the above ({$totalActions} item(s)).");

            return self::SUCCESS;
        }

        if ($this->input->isInteractive() && ! $this->confirm("Delete {$totalActions} item(s)? This cannot be undone.")) {
            $this->line('Aborted.');

            return self::SUCCESS;
        }

        // ── Delete: file first, then row. A row without a file is harmless;
        //    a file without a row is exactly what --orphan-files is for. ──
        $filesGone = 0;
        $rowsGone = 0;
        foreach ($toDelete as $inv) {
            if ($inv->path && $disk->exists($inv->path)) {
                $disk->delete($inv->path);
                $filesGone++;
            }
            $inv->delete();
            $rowsGone++;
        }
        foreach ($missing as $inv) {
            $inv->delete();
            $rowsGone++;
        }
        foreach ($orphans as $file) {
            $disk->delete($file);
            $filesGone++;
        }

        $this->info("Deleted {$rowsGone} row(s) and {$filesGone} file(s), freeing " . $this->human($bytes + $orphanBytes) . '.');

        return self::SUCCESS;
    }

    protected function human(int $bytes): string
    {
        foreach (['B', 'KB', 'MB', 'GB'] as $unit) {
            if ($bytes < 1024 || $unit === 'GB') {
                return round($bytes, 1) . ' ' . $unit;
            }
            $bytes /= 1024;
        }

        return (string) $bytes;
    }
}
