<?php

namespace App\Support;

/**
 * The smallest amount Stripe will take, per currency.
 *
 * Stripe enforces a floor of roughly US$0.50 on every charge. Before currencies
 * were resolved correctly this never showed up: a PKR booking was sent as QAR,
 * and QAR 50 is about US$13, comfortably over. Charging in the real currency
 * put ₨50 in front of Stripe, which is about US$0.26, and it refused — with a
 * message about cent conversions aimed at a developer, not a customer.
 *
 * Checked before the Checkout Session is created. A currency with no configured
 * floor is passed through untouched rather than guessed at.
 */
final class StripeMinimumCharge
{
    /** The floor for a currency in major units, or null when none is configured. */
    public static function floorFor(?string $currency): ?float
    {
        $code = strtoupper(trim((string) $currency));

        if ($code === '') {
            return null;
        }

        $floor = config('payments.minimum_charge.'.$code);

        return is_numeric($floor) ? (float) $floor : null;
    }

    /** True when this amount is below what Stripe will accept in this currency. */
    public static function isBelowFloor(float $amount, ?string $currency): bool
    {
        $floor = self::floorFor($currency);

        return $floor !== null && $amount < $floor;
    }

    /**
     * What to tell the person trying to pay. Names the shortfall and the way
     * out, because the amount is rarely something they can change themselves.
     */
    public static function message(float $amount, ?string $currency): string
    {
        $code = strtoupper(trim((string) $currency));
        $floor = self::floorFor($currency);

        return sprintf(
            'This payment of %s %s is below the %s %s minimum for online card payments. '
            .'Please pay the full amount online, or choose to pay at the venue.',
            $code,
            number_format($amount, 2),
            $code,
            number_format((float) $floor, 2)
        );
    }

    /**
     * The same shortfall, told to the admin at the desk rather than the
     * customer. There is no card link to send for this amount; the way out
     * is to take the payment directly, which is what the admin app's button
     * is called.
     */
    public static function adminMessage(float $amount, ?string $currency): string
    {
        $code = strtoupper(trim((string) $currency));
        $floor = self::floorFor($currency);

        return sprintf(
            'Online card payment is not available for %s %s: the minimum is %s %s. '
            .'Accept this payment directly instead.',
            $code,
            number_format($amount, 2),
            $code,
            number_format((float) $floor, 2)
        );
    }
}
