<?php
namespace App\Support;

use App\Models\Booking;
use Illuminate\Support\Str;

final class CheckoutReference
{
    /** Called inside the customer-checkout transaction for its newly made slots. */
    public static function stamp($bookings): string
    {
        $reference = 'CHK-'.strtoupper((string) Str::ulid());
        Booking::whereIn('id', collect($bookings)->pluck('id'))->whereNull('checkout_reference')->update(['checkout_reference'=>$reference]);
        return $reference;
    }
}