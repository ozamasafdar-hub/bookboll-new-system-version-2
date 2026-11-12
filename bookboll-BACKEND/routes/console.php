<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('slot-locks:clear')->everyFiveMinutes();

// Not at 00:00: that minute already carries the five-minute, hourly and
// every-minute tasks in one schedule:run, and on shared hosting the account's
// memory limit is shared by all of them plus the queue worker. A pile-up there
// got a task SIGKILLed by the host. The reminders only queue mail now, but they
// still walk the customer base, so they get a quiet minute of their own.
Schedule::command('send-booking-reminders')->everyMinute()->withoutOverlapping();

// withoutOverlapping() for the same reason its sibling below has it: a run that
// outlasts its minute would otherwise have the next one start alongside it, and
// every concurrent run holds its own MySQL connection out of the account's 30.
Schedule::command('bookings:auto-cancel-unpaid-deposit')->everyMinute()->withoutOverlapping();

// The same timeout, for hosted events whose host walked away from Stripe: their
// courts have to go back on sale like any other unpaid booking's would.
Schedule::command('games:discard-unpaid --apply')->everyMinute()->withoutOverlapping();

// Provider earnings sit in `pending_balance` until their hold period is up.
// Without this they never reach `available_balance`, so the wallet reads empty
// and no withdrawal can ever be requested against the money.
Schedule::command('wallet:release-holds --apply')->dailyAt('02:00');

Schedule::command('backup:run --only-db')->daily()->at('01:30');

// Stripe's hosted invoice/receipt link for payments it collected. Membership
// invoices store theirs straight from the webhook; one-off charges are resolved
// here instead of during checkout, so a booking can never fail because a Stripe
// lookup did. Hourly means a new charge has its receipt within the hour, and
// history fills in over the first passes. Off the hour, and capped at 100 rows
// (up to two Stripe calls each), so a pass stays well under a minute and never
// lands on top of the midnight tasks.
Schedule::command('payments:backfill-stripe-urls --limit=100')->hourlyAt(17)->withoutOverlapping();

// Upload records whose file is already gone. Deletes no files, so it is safe to
// run unattended. The unreferenced-file sweep is deliberately NOT scheduled:
// it needs --delete-unreferenced and a human who has read its --dry-run first.
Schedule::command('uploads:prune')->dailyAt('03:10');

// Contract uploads nobody attached to an agreement. Only unlinked rows are
// ever considered, so a contract in use is never at risk.
Schedule::command('contracts:prune-temporary')->dailyAt('03:25');
