<?php

namespace App\Console\Commands;

use App\Models\Business;
use App\Models\Payment;
use App\Support\PaymentRecipient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Name the provider on cash receipts that were left with a blank recipient.
 *
 * PaymentService counts `recipient IS NULL` towards "to Bookboll" on the
 * payments stats card. For Bookboll's own venues that is correct — Bookboll's
 * staff took the cash — and on this platform that is nearly all of them, so a
 * blanket backfill would move Bookboll's own money into the providers' column
 * and make the report worse. This only touches rows whose venue actually
 * belongs to a provider.
 *
 * Deliberately narrow, because "who took this cash" has to be beyond doubt
 * before a label is written:
 *
 *  - Cash only. Online money is split into its own commission and earning rows
 *    which carry their own recipients; a blank one there is a different bug.
 *  - Arrival balances only (`remaining` / `full`). A paid cash DEPOSIT is what
 *    the transfer reconciliation re-labels as Bookboll's fee, so guessing at it
 *    here would contradict that and is left alone.
 *  - Blank only. A recipient somebody already wrote is never overwritten.
 *  - Dry run unless --commit is passed, and safe to run twice.
 */
class BackfillPaymentRecipient extends Command
{
    protected $signature = 'payments:backfill-recipient {--commit : Write the changes}';

    protected $description = 'Label blank-recipient provider cash receipts so they stop reporting as Bookboll revenue';

    public function handle(): int
    {
        $rows = DB::table('payments as p')
            ->join('bookings as b', 'b.id', '=', 'p.booking_id')
            ->join('venues as v', 'v.id', '=', 'b.venue_id')
            ->leftJoin('users as u', 'u.id', '=', 'v.owner_user_id')
            ->whereNull('p.recipient')
            ->where('p.status', 'paid')
            ->where('p.method', 'cash')
            ->whereIn('p.type', ['remaining', 'full'])
            // A provider venue, by either of the two ways one is recorded.
            ->where(fn ($q) => $q->whereNotNull('v.business_id')->orWhereNotNull('v.owner_user_id'))
            ->select([
                'p.id', 'p.amount', 'p.currency',
                'v.id as venue_id', 'v.name as venue_name',
                'v.business_id', 'v.owner_user_id', 'u.name as owner_name',
            ])
            ->orderBy('p.id')
            ->get();

        if ($rows->isEmpty()) {
            $this->info('Nothing to do: no blank-recipient provider cash receipts.');

            return self::SUCCESS;
        }

        // Resolved once per business, not once per row.
        $businesses = Business::whereIn('id', $rows->pluck('business_id')->filter()->unique())
            ->get()->keyBy('id');
        // businesses.owner_id is the user; venues.owner_user_id is the same
        // person seen from the venue. Different spellings, one relationship.
        $byOwner = Business::whereIn('owner_id', $rows->pluck('owner_user_id')->filter()->unique())
            ->orWhereIn('legacy_owner_id',$rows->pluck('owner_user_id')->filter()->unique())->get()->keyBy(fn ($b) => $b->legacy_owner_id ?: $b->owner_id);

        $updates = [];
        $skipped = [];

        foreach ($rows as $r) {
            $business = ($r->business_id ? $businesses->get($r->business_id) : null)
                ?: ($r->owner_user_id ? $byOwner->get($r->owner_user_id) : null);

            // A venue with an owner but no business row: the owner's name is the
            // best label available, and it is still not Bookboll's money.
            $label = PaymentRecipient::forBusiness(
                $business,
                $r->owner_name ? new \App\Models\User(['name' => $r->owner_name]) : null
            );

            if ($label === PaymentRecipient::PROVIDER_FALLBACK && ! $business) {
                $skipped[] = $r;
            }

            $updates[] = [$r, $label];
        }

        $this->table(
            ['Payment', 'Venue', 'Amount', 'New recipient'],
            collect($updates)->map(fn ($u) => [
                $u[0]->id, $u[0]->venue_name,
                ($u[0]->currency ?: '?').' '.number_format((float) $u[0]->amount, 2),
                $u[1],
            ])->all()
        );

        $total = collect($updates)
            ->groupBy(fn ($u) => $u[0]->currency ?: '?')
            ->map(fn ($g) => $g->sum(fn ($u) => (float) $u[0]->amount));

        $this->newLine();
        $this->line(sprintf('%d receipt(s) would move out of the Bookboll column:', count($updates)));
        foreach ($total as $currency => $amount) {
            $this->line('  '.$currency.' '.number_format($amount, 2));
        }
        if ($skipped) {
            $this->warn(sprintf(
                '%d of these have no business row and will be labelled "%s".',
                count($skipped),
                PaymentRecipient::PROVIDER_FALLBACK
            ));
        }

        if (! $this->option('commit')) {
            $this->newLine();
            $this->info('Dry run. Re-run with --commit to write these.');

            return self::SUCCESS;
        }

        // Written through the model, one row at a time, NOT as a bulk query
        // builder update. Payment logs its own activity on the Eloquent events,
        // and a `DB::table(...)->update()` fires none of them — the label would
        // change with nothing recording who changed it or what it was before.
        // Slower, and that is the right trade for a financial field.
        //
        // Each row is re-read under a lock and re-checked while still blank, so
        // a second run — or one racing a transfer — cannot overwrite a label.
        $written = 0;
        DB::transaction(function () use ($updates, &$written) {
            foreach ($updates as [$row, $label]) {
                $payment = Payment::whereKey($row->id)->lockForUpdate()->first();

                if (! $payment || $payment->recipient !== null) {
                    continue;
                }

                $payment->forceFill(['recipient' => $label])->save();
                $written++;
            }
        });

        $this->info($written.' receipt(s) labelled.');

        return self::SUCCESS;
    }
}
