<?php

namespace App\Events;

use App\Models\Payment;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A payment's status changed — it landed, or was cancelled or refunded.
 *
 * Raised by PaymentObserver for every Eloquent save, so every path that marks
 * a payment paid (dashboard collect, mobile mark-paid, mark-received, Stripe,
 * admin edit, hosted games) feeds the SAME booking sync without having to
 * remember to call it. Dispatched after the surrounding transaction commits,
 * so the listener sees the booking's final state and never acts on a write
 * that is then rolled back.
 */
final class PaymentStatusChanged implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly Payment $payment,
        public readonly ?string $from,
        public readonly string $to,
    ) {}
}
