<?php

use App\Models\Invoice;
use App\Models\LoyaltyPoint;
use App\Models\Setting;
use App\Models\SlotLock;
use App\Models\User;
use App\Notifications\BookingConfirmedUserNotification;
use App\Notifications\BookingCreatedAdminNotification;
use App\Services\AuditLogService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

if (! class_exists('MobileApps')) {
    class MobileApps
    {
        public const ADMIN    = 'admin_mobile_app';
        public const CUSTOMER = 'customer_mobile_app';

        public static function list(): array
        {
            return [
                self::ADMIN,
                self::CUSTOMER,
            ];
        }
    }
}

if (! class_exists('BookingStatus')) {
    class BookingStatus
    {
        public const PENDING   = 'pending';
        public const CONFIRMED = 'confirmed';
        public const COMPLETED = 'completed';
        public const CANCELLED = 'cancelled';

        public static function list(): array
        {
            return [
                self::PENDING,
                self::CONFIRMED,
                self::COMPLETED,
                self::CANCELLED,
            ];
        }
    }
}

if (! class_exists('PaymentStatus')) {
    class PaymentStatus
    {
        public const PAID       = 'paid';
        public const UNPAID     = 'unpaid';
        public const PENDING    = 'pending';
        public const REFUNDED   = 'refunded';
        public const CANCELLED  = 'cancelled';

        public static function list(): array
        {
            return [
                self::PAID,
                self::PENDING,
                self::UNPAID,
                self::CANCELLED,
                self::REFUNDED,
            ];
        }

        public static function activeForRevenue(): array
        {
            return [
                self::PAID,
                self::PENDING,
                self::UNPAID,
            ];
        }
    }
}

if (! class_exists('BookingPaymentStatus')) {
    class BookingPaymentStatus
    {
        public const PENDING         = 'pending';
        public const CANCELLED       = 'cancelled';
        public const FULLY_PAID      = 'fully_paid';
        public const DEPOSIT_PAID    = 'deposit_paid';
        public const PAYMENT_SKIPPED = 'payment_skipped';

        public static function list(): array
        {
            return [
                self::PENDING,
                self::CANCELLED,
                self::FULLY_PAID,
                self::DEPOSIT_PAID,
                self::PAYMENT_SKIPPED,
            ];
        }
    }
}

if (! class_exists('BookingPaymentMethod')) {
    class BookingPaymentMethod
    {
        public const PAID_CASH                    = 'paid_cash';
        public const PAID_CHEQUE                  = 'paid_cheque';
        public const ONLINE_PAYMENT               = 'online_payment';
        public const PAYMENT_SKIPPED              = 'payment_skipped';
        public const CASH_ON_ARRIVAL              = 'cash_on_arrival';
        public const PAID_CREDIT_CARD             = 'paid_credit_card';
        public const PAID_WIRE_TRANSFER           = 'paid_wire_transfer';
        public const DEPOSIT_REMAINING_ON_ARRIVAL = 'deposit_remaining_on_arrival';

        public static function list(): array
        {
            return [
                self::PAID_CASH,
                self::PAID_CHEQUE,
                self::ONLINE_PAYMENT,
                self::PAYMENT_SKIPPED,
                self::CASH_ON_ARRIVAL,
                self::PAID_CREDIT_CARD,
                self::PAID_WIRE_TRANSFER,
                self::DEPOSIT_REMAINING_ON_ARRIVAL,
            ];
        }
    }
}

if (! class_exists('PaymentMethod')) {
    class PaymentMethod
    {
        public const CASH          = 'cash';
        public const ONLINE        = 'online';
        public const CHEQUE        = 'cheque';
        public const CREDIT_CARD   = 'credit_card';
        public const WIRE_TRANSFER = 'wire_transfer';

        public static function list()
        {
            return [
                self::CASH,
                self::ONLINE,
                self::CHEQUE,
                self::CREDIT_CARD,
                self::WIRE_TRANSFER,
            ];
        }
    }
}

if (! class_exists('PaymentType')) {
    class PaymentType
    {
        public const FULL      = 'full';
        public const DEPOSIT   = 'deposit';
        public const REMAINING = 'remaining';
        public const SUBSCRIPTION_COMMISSION = 'subscription_commission';
        public const SUBSCRIPTION_EARNING    = 'subscription_earning';
        // Split-ledger rows for a full online BOOKING payment (BookingWalletService).
        public const BOOKING_COMMISSION      = 'booking_commission';
        public const BOOKING_EARNING         = 'booking_earning';

        public static function list()
        {
            return [
                self::FULL,
                self::DEPOSIT,
                self::REMAINING,
                self::SUBSCRIPTION_COMMISSION,
                self::SUBSCRIPTION_EARNING,
                self::BOOKING_COMMISSION,
                self::BOOKING_EARNING,
            ];
        }
    }
}

if (! class_exists('SubscriberStatus')) {
    class SubscriberStatus
    {
        public const SUBSCRIBED   = 'subscribed';
        public const UNSUBSCRIBED = 'unsubscribed';

        public static function list()
        {
            return [
                self::SUBSCRIBED,
                self::UNSUBSCRIBED,
            ];
        }
    }
}

if (! class_exists('CompnayInfo')) {
    class CompnayInfo
    {
        public const EMAIL       = 'info@bookboll.com';
        public const NAME        = 'BOOKBOLL';
        public const CITY        = 'Doha';
        public const POSTAL_CODE = '12345';
        public const PHONE       = '123456789';
        public const ADDRESS     = 'Qatar';
    }
}

if (! function_exists('sendSuccess')) {
    function sendSuccess(string $message = 'Success', array $data = [], int $code = 200, $logActivity = true): \Illuminate\Http\JsonResponse
    {
        $user = auth()->user();

        if ($logActivity && $user && ! request()->is('api/login*', 'api/register*', 'api/business/register*', 'api/admin-app/register*', 'api/admin-app/forgot-password*', 'api/admin-app/reset-password*', 'api/forgot-password*', 'api/reset-password*', 'api/customer/login*', 'api/customer/google-login*', 'api/customer/apple-login*', 'api/customer/forgot-password*', 'api/customer/reset-password*', 'api/health*', 'api/audit-logs*') && ! request()->isMethod('GET')) {
            try {
                $routeName  = request()->route()?->getName();
                $action     = getActionFromMethod(request()->method());
                $resource   = getResourceFromRoute($routeName);
                $resourceId = getResourceIdFromRequest(request());
                $details    = extractSuccessDetails($message, $routeName, $data);

                app(AuditLogService::class)->logActivity(
                    $action,
                    $resource,
                    $resourceId,
                    $details,
                    $user
                );
            } catch (\Exception $e) {
                \Log::error('Failed to log success activity: ' . $e->getMessage());
            }
        }

        return response()->json([
            'success' => true,
            'message' => $message,
            'data'    => $data,
        ], $code);
    }
}

if (! function_exists('sendError')) {
    function sendError(string $message = 'Error', array $errors = [], int $code = 400)
    {
        return response()->json([
            'success' => false,
            'message' => $message,
            'errors'  => $errors,
            'code'    => $code,
        ], $code);
    }
}

if (! function_exists('getActionFromMethod')) {
    function getActionFromMethod(string $method): string
    {
        return match ($method) {
            'POST'   => 'Created',
            'PUT', 'PATCH' => 'Updated',
            'DELETE' => 'Deleted',
            default  => 'Accessed'
        };
    }
}

if (! function_exists('getResourceFromRoute')) {
    function getResourceFromRoute(?string $routeName): string
    {
        if (! $routeName) {
            return 'System';
        }

        $resourceMap = [
            'posts.store'                  => 'Blog Post',
            'posts.update'                 => 'Blog Post',
            'posts.destroy'                => 'Blog Post',
            'posts.change-status'          => 'Blog Post',

            'post-categories.store'        => 'Post Category',
            'post-categories.update'       => 'Post Category',
            'post-categories.destroy'      => 'Post Category',

            'bookings.store'               => 'Booking',
            'bookings.update'              => 'Booking',
            'bookings.destroy'             => 'Booking',
            'bookings.cancel'              => 'Booking',
            'customer.bookings.cancel'     => 'Booking',
            'bookings.completed'           => 'Booking',
            'bookings.confirm'             => 'Booking',
            'bookings.generate-invoice'    => 'Booking',
            'bookings.confirmBooking'      => 'Booking',
            'bookings.pay'                 => 'Booking',

            'users.store'                  => 'User',
            'users.update'                 => 'User',
            'users.destroy'                => 'User',
            'users.update-profile'         => 'User',
            'users.updateProfile'          => 'User',
            'users.updateCustomerProfile'  => 'User',
            'users.updateProfileImage'     => 'User',
            'users.updatePreferences'      => 'User',
            'users.changePassword'         => 'User',

            'venues.store'                 => 'Venue',
            'venues.update'                => 'Venue',
            'venues.destroy'               => 'Venue',
            'venues.sort'                  => 'Venue',

            'resources.store'              => 'Resource',
            'resources.update'             => 'Resource',
            'resources.destroy'            => 'Resource',

            'gift-cards.store'             => 'Gift Card',
            'gift-cards.update'            => 'Gift Card',
            'gift-cards.destroy'           => 'Gift Card',
            'gift-cards.redeem'            => 'Gift Card',
            'gift-cards.redeemGiftCard'    => 'Gift Card',

            'sports-categories.store'      => 'Sports Category',
            'sports-categories.update'     => 'Sports Category',
            'sports-categories.destroy'    => 'Sports Category',
            'sports-categories.sort'       => 'Sports Category',

            'sports-types.store'           => 'Sports Type',
            'sports-types.update'          => 'Sports Type',
            'sports-types.destroy'         => 'Sports Type',
            'sports-types.sort'            => 'Sports Type',

            'add-ons.store'                => 'Add-On',
            'add-ons.update'               => 'Add-On',
            'add-ons.destroy'              => 'Add-On',
            'add-ons.sort'                 => 'Add-On',

            'listings.store'               => 'Listing',
            'listings.update'              => 'Listing',
            'listings.destroy'             => 'Listing',
            'listings.sort'                => 'Listing',
            'listings.uploadImage'         => 'Listing',
            'listings.deleteImage'         => 'Listing',

            'promocodes.store'             => 'Promocode',
            'promocodes.update'            => 'Promocode',
            'promocodes.destroy'           => 'Promocode',
            'promocodes.apply'             => 'Promocode',
            'promocodes.remove'            => 'Promocode',
            'promocodes.applyPromocode'    => 'Promocode',

            'special-days.store'           => 'Special Day',
            'special-days.update'          => 'Special Day',
            'special-days.destroy'         => 'Special Day',
            'special-days.bulk'            => 'Special Day',
            'special-days.bulkCreate'      => 'Special Day',

            'settings.update'              => 'Setting',
            'settings.updateSingle'        => 'Setting',

            'customers.store'              => 'Customer',
            'customers.update'             => 'Customer',
            'customers.destroy'            => 'Customer',
            'customers.register'           => 'Customer',
            'customers.createCustomer'     => 'Customer',
            'customers.updateTags'         => 'Customer',
            'customers.updateStatus'       => 'Customer',
            'customer.delete-account'      => 'Customer',

            'reviews.store'                => 'Review',
            'reviews.update'               => 'Review',
            'reviews.destroy'              => 'Review',
            'reviews.helpful'              => 'Review',

            'payments.store'               => 'Payment',
            'payments.update'              => 'Payment',
            'payments.destroy'             => 'Payment',
            'payments.confirm'             => 'Payment',
            'payments.confirmPayment'      => 'Payment',
            'payments.generate-link'       => 'Payment',
            'payments.generatePaymentLink' => 'Payment',
            'payments.createPaymentIntent' => 'Payment',

            'newsletter.subscribe'         => 'Newsletter',
        ];

        foreach ($resourceMap as $routePattern => $resource) {
            if (str_contains($routeName, $routePattern)) {
                return $resource;
            }
        }

        $routeUri = request()->route()?->uri() ?? '';
        foreach ($resourceMap as $routePattern => $resource) {
            $uriPattern = str_replace('.', '/', $routePattern);
            if (str_contains($routeUri, $uriPattern) || str_contains($routeUri, str_replace('-', '/', $routePattern))) {
                return $resource;
            }
        }

        if (str_contains($routeName, '.')) {
            $parts        = explode('.', $routeName);
            $resourceName = ucwords(str_replace(['store', 'update', 'destroy', 'create'], '', $parts[0]));
            return $resourceName ?: 'System';
        }

        if ($routeUri) {
            $uriParts = explode('/', $routeUri);
            foreach ($uriParts as $part) {
                if (in_array($part, ['posts', 'venues', 'users', 'bookings', 'listings', 'customers', 'reviews', 'payments', 'promocodes', 'gift-cards', 'resources', 'add-ons', 'sports-categories', 'sports-types', 'special-days', 'post-categories', 'settings', 'newsletter'])) {
                    return ucwords(str_replace('-', ' ', $part));
                }
            }
        }

        return 'System';
    }
}

if (! function_exists('getResourceIdFromRequest')) {
    function getResourceIdFromRequest($request): string
    {
        $id = $request->route('id') ??
        $request->route('booking_id') ??
        $request->route('user_id') ??
        $request->route('venue_id') ??
        $request->route('resource_id') ??
        $request->route('gift_card_id') ??
        $request->route('sports_category_id') ??
        $request->route('promocode') ??
        $request->route('review') ??
        $request->route('customer_id') ??
        $request->route('key') ??
            'N/A';

        if ($id === 'N/A' && $request->isMethod('post')) {
            $responseData = $request->getContent();
            if (str_contains($responseData, '"id":')) {
                preg_match('/"id":(\d+)/', $responseData, $matches);
                if (isset($matches[1])) {
                    $id = $matches[1];
                }
            }
        }

        return $id;
    }
}

if (! function_exists('extractSuccessDetails')) {
    function extractSuccessDetails(string $message, ?string $routeName, array $data = []): string
    {
        if (isset($data['id'])) {
            $id = $data['id'];

            $detailsMap = [
                'posts.store' => "Created Post #{$id}",
                'posts.update' => "Updated Post #{$id}",
                'posts.destroy' => "Deleted Post #{$id}",
                'posts.change-status' => "Changed status for Post #{$id}",

                'post-categories.store' => "Created Blog Category #{$id}",
                'post-categories.update' => "Updated Blog Category #{$id}",
                'post-categories.destroy' => "Deleted Blog Category #{$id}",

                'users.store' => "Created user #{$id}",
                'users.update' => "Updated user #{$id}",
                'users.destroy' => "Deleted user #{$id}",
                'users.update-profile' => "Updated profile for user #{$id}",
                'users.updateProfile' => "Updated profile for user #{$id}",
                'users.updateCustomerProfile' => "Updated customer profile #{$id}",
                'users.updateProfileImage' => "Updated profile image for user #{$id}",
                'users.updatePreferences' => "Updated preferences for user #{$id}",
                'users.changePassword' => "Changed password for user #{$id}",
                'users.approve' => "Approved user registration #{$id}",
                'users.reject' => "Rejected user registration #{$id}",

                'venues.store' => "Created venue #{$id}",
                'venues.update' => "Updated venue #{$id}",
                'venues.destroy' => "Deleted venue #{$id}",
                'venues.sort' => "Updated venue sort order",

                'resources.store' => "Created resource #{$id}",
                'resources.update' => "Updated resource #{$id}",
                'resources.destroy' => "Deleted resource #{$id}",

                'gift-cards.store' => "Created gift card #{$id}",
                'gift-cards.update' => "Updated gift card #{$id}",
                'gift-cards.destroy' => "Deleted gift card #{$id}",
                'gift-cards.redeem' => "Redeemed gift card #{$id}",
                'gift-cards.redeemGiftCard' => "Redeemed gift card #{$id}",

                'sports-categories.store' => "Created sports category #{$id}",
                'sports-categories.update' => "Updated sports category #{$id}",
                'sports-categories.destroy' => "Deleted sports category #{$id}",
                'sports-categories.sort' => "Updated sports category sort order",

                'sports-types.store' => "Created sports type #{$id}",
                'sports-types.update' => "Updated sports type #{$id}",
                'sports-types.destroy' => "Deleted sports type #{$id}",
                'sports-types.sort' => "Updated sports type sort order",

                'add-ons.store' => "Created Add-On #{$id}",
                'add-ons.update' => "Updated Add-On #{$id}",
                'add-ons.destroy' => "Deleted Add-On #{$id}",
                'add-ons.sort' => "Updated Add-On sort order",

                'listings.store' => "Created Listing #{$id}",
                'listings.update' => "Updated Listing #{$id}",
                'listings.destroy' => "Deleted Listing #{$id}",
                'listings.sort' => "Updated Listing sort order",
                'listings.uploadImage' => "Uploaded image for Listing #{$id}",
                'listings.deleteImage' => "Deleted image for Listing #{$id}",

                'promocodes.store' => "Created Promocode #{$id}",
                'promocodes.update' => "Updated Promocode #{$id}",
                'promocodes.destroy' => "Deleted Promocode #{$id}",
                'promocodes.apply' => "Applied Promocode #{$id}",
                'promocodes.remove' => "Removed Promocode from booking #{$id}",
                'promocodes.applyPromocode' => "Applied Promocode",

                'special-days.store' => "Created Special Day #{$id}",
                'special-days.update' => "Updated Special Day #{$id}",
                'special-days.destroy' => "Deleted Special Day #{$id}",
                'special-days.bulk' => "Bulk created Special Days",
                'special-days.bulkCreate' => "Bulk created Special Days",

                'settings.update' => "Updated settings",
                'settings.updateSingle' => "Updated setting",

                'customers.store' => "Created customer #{$id}",
                'customers.update' => "Updated customer #{$id}",
                'customers.destroy' => "Deleted customer #{$id}",
                'customers.register' => "Registered customer #{$id}",
                'customers.createCustomer' => "Created customer #{$id}",
                'customers.updateTags' => "Updated tags for customer #{$id}",
                'customers.updateStatus' => "Updated status for customer #{$id}",
                'customer.delete-account' => "Deleted customer account #{$id}",

                'bookings.store' => "Created booking #{$id}",
                'bookings.update' => "Updated booking #{$id}",
                'bookings.destroy' => "Deleted booking #{$id}",
                'bookings.cancel' => "Cancelled booking #{$id}",
                'bookings.completed' => "Marked booking #{$id} as completed",
                'bookings.confirm' => "Confirmed booking #{$id}",
                'bookings.generate-invoice' => "Generated invoice for booking #{$id}",
                'bookings.confirmBooking' => "Confirmed booking #{$id}",
                'bookings.pay' => "Processed payment for booking #{$id}",
                'bookings.mobileUpdate' => "Updated booking #{$id} via mobile",
                'bookings.validate' => "Validated booking request",

                'reviews.store' => "Created review #{$id}",
                'reviews.update' => "Updated review #{$id}",
                'reviews.destroy' => "Deleted review #{$id}",
                'reviews.helpful' => "Marked review #{$id} as helpful",

                'payments.store' => "Created payment #{$id}",
                'payments.update' => "Updated payment #{$id}",
                'payments.destroy' => "Deleted payment #{$id}",
                'payments.confirm' => "Confirmed payment #{$id}",
                'payments.confirmPayment' => "Confirmed payment #{$id}",
                'payments.generate-link' => "Generated payment link",
                'payments.generatePaymentLink' => "Generated payment link",
                'payments.createPaymentIntent' => "Created payment intent",
                'payments.mark-booking-paid' => "Marked booking as paid",
                'payments.markBookingAsPaid' => "Marked booking as paid",

                'newsletter.subscribe' => "Subscribed to newsletter",

                'lock-slot' => "Locked time slot",
                'unlock-slot' => "Unlocked time slot",
                'unlock-all-slots' => "Unlocked all time slots",
                'extend-slot-lock' => "Extended slot lock",
                'check-slot-availability' => "Checked slot availability",

                'contact-us' => "Submitted contact form",

                'customer.subscribe-onesignal' => "Subscribed to OneSignal notifications",
            ];

            foreach ($detailsMap as $routePattern => $detail) {
                if (str_contains($routeName ?? '', $routePattern)) {
                    return $detail;
                }
            }
        }

        $cleanMessage = strtolower($message);
        $cleanMessage = preg_replace('/\s+(successfully|success)$/i', '', $cleanMessage);
        $cleanMessage = ucwords(trim($cleanMessage));

        return $cleanMessage ?: 'Performed action';
    }
}

if (! function_exists('logActivity')) {
    function logActivity(string $action, string $resource, string $resourceId, string $details): void
    {
        $user = auth()->user();
        if (! $user) {
            return;
        }

        try {
            app(AuditLogService::class)->logActivity(
                $action,
                $resource,
                $resourceId,
                $details,
                $user
            );
        } catch (\Exception $e) {
            \Log::error('Manual activity logging failed: ' . $e->getMessage());
        }
    }
}

if (! function_exists('generatePaymentId')) {
    function generatePaymentId(int $bookingId, string $type): string
    {
        $typeAbbr = match (strtolower($type)) {
            'deposit'            => 'DEP',
            'remaining'          => 'REM',
            'full'               => 'FUL',
            'booking_commission' => 'COM',
            'booking_earning'    => 'EARN',
            default              => 'PAY',
        };

        $uniqueSuffix = substr(str_replace('.', '', microtime(true)), -6);

        return 'PAY-' . $bookingId . '-' . $typeAbbr . '-' . $uniqueSuffix;
    }
}

if (! function_exists('determineSlotPrice')) {
    /**
     * What a single slot costs. Thin wrapper so every existing caller — slots,
     * quotes, bookings, games — prices through the same resolver.
     *
     * @see resolveSlotPricing() for the breakdown behind the number.
     */
    function determineSlotPrice($listing, $weekday, $slotStart, $slotEnd, $specialDay)
    {
        return resolveSlotPricing($listing, $weekday, $slotStart, $slotEnd, $specialDay)['price'];
    }
}

if (! function_exists('resolveSlotPricing')) {
    /**
     * The one authority on what a slot costs, and why.
     *
     * Resolution mirrors the admin dashboard's own grid (PricingGrid.vue +
     * NewBookingDrawer.resolveSlotPrice), most specific source first:
     *
     *   1. pricing cell for (day_of_week, slot_time) — a direct_price, or the
     *      group the cell is painted with
     *   2. pricing group whose days + window cover the slot
     *   3. the listing's base_price
     *
     * A special day is then applied as an OVERLAY on that figure: a flat price
     * (time block price_per_hour, else the day's own price) replaces it, and
     * otherwise price_multiplier scales it. Previously the special day was
     * checked first and returned outright, so a multiplier-only special day —
     * the "1.5x on public holidays" case — priced at plain base_price.
     *
     * @return array{price: float, breakdown: array<string, mixed>}
     */
    function resolveSlotPricing($listing, $weekday, $slotStart, $slotEnd, $specialDay): array
    {
        // determineSlotPrice() used to read `pricingGroups` only when the caller
        // happened to have eager-loaded it, and silently billed base_price when
        // they hadn't — which is how games and customer quotes lost every
        // pricing group. Load what we need instead of guessing.
        if ($listing instanceof \App\Models\Listing) {
            $listing->loadMissing('pricingGroups');
        }

        $basePrice = (float) ($listing->base_price ?? 0);
        $currency  = null;

        $source       = 'base_price';
        $matchedGroup = null;

        $listPrice = $basePrice;

        /*
         * A pricing group is the ONLY thing that moves a slot off base price.
         *
         * There used to be a second store, `pricing_cells` — one row per painted
         * square, naming a group — and it was read FIRST and won outright. It
         * never re-checked that the group it named still covered that day or
         * hour, so a square painted before a group's Days were edited went on
         * charging the old group's price for ever, while the group card in the
         * admin said something else. Two writable sources for one number, with
         * no reconciliation: the group editor and the customer's price could
         * disagree indefinitely, and did.
         *
         * Groups are now the single source of truth and the grid is a view of
         * them, so the two cannot drift. See the drop migration for the data.
         */
        $matchedGroup = matchingPricingGroupForSlot($listing, $weekday, $slotStart, $slotEnd);

        if ($matchedGroup) {
            $listPrice = (float) $matchedGroup->price_per_hour;
            $currency  = $matchedGroup->currency ?: null;
            $source    = 'pricing_group';
        }

        $price = $listPrice;

        // 3. The special day overlay.
        $specialDayDetail = null;
        if ($specialDay) {
            $block = null;
            if ($specialDay->timeBlocks && $specialDay->timeBlocks->count()) {
                $block = $specialDay->timeBlocks
                    ->first(fn($b) => timeSlotFallsInRange($slotStart, $slotEnd, $b->start_time, $b->end_time));
            }

            $flat = null;
            if ($block && $block->price_per_hour !== null) {
                $flat = (float) $block->price_per_hour;
            } elseif ($specialDay->price !== null) {
                $flat = (float) $specialDay->price;
            }

            $multiplier = $specialDay->price_multiplier !== null
                ? (float) $specialDay->price_multiplier
                : 1.0;
            if ($multiplier <= 0) {
                $multiplier = 1.0;
            }

            $appliedMultiplier = 1.0;
            if ($flat !== null) {
                $price  = $flat;
                $source = 'special_day';
            } elseif ($multiplier !== 1.0) {
                $price             = round($listPrice * $multiplier, 2);
                $appliedMultiplier = $multiplier;
                $source            = 'special_day';
            }

            if ($block || $flat !== null || $appliedMultiplier !== 1.0) {
                $specialDayDetail = [
                    'id'           => $specialDay->id,
                    'name'         => $specialDay->description,
                    'type'         => $specialDay->type,
                    'flat_price'   => $flat,
                    'multiplier'   => $appliedMultiplier,
                    'block_start'  => $block ? substr((string) $block->start_time, 0, 5) : null,
                    'block_end'    => $block ? substr((string) $block->end_time, 0, 5) : null,
                ];
                $currency = $block?->price_currency ?: $currency;
            }
        }

        $price = round($price, 2);

        return [
            'price'     => $price,
            'breakdown' => [
                'price'         => $price,
                'base_price'    => round($basePrice, 2),
                'list_price'    => round($listPrice, 2),
                'source'        => $source,
                'is_dynamic'    => $source !== 'base_price',
                'currency'      => $currency,
                'pricing_group' => $matchedGroup ? [
                    'id'             => $matchedGroup->id,
                    'name'           => $matchedGroup->name,
                    'color'          => $matchedGroup->color,
                    'start_time'     => substr((string) $matchedGroup->start_time, 0, 5),
                    'end_time'       => substr((string) $matchedGroup->end_time, 0, 5),
                    'days'           => $matchedGroup->days,
                    'price_per_hour' => (float) $matchedGroup->price_per_hour,
                    'matched_by'     => 'time_window',
                ] : null,
                'special_day'   => $specialDayDetail,
            ],
        ];
    }
}

if (! function_exists('compactSlotPricing')) {
    /**
     * The small version of resolveSlotPricing()'s breakdown, for the slot list.
     *
     * The full breakdown answers "why" in detail and is worth having internally,
     * but it repeats the matched group's whole definition — times, price and its
     * seven-entry `days` array — on EVERY slot of EVERY day. A 24-slot day carries
     * that two dozen times over for one group, and the month view asks for thirty
     * of those days at once. This keeps the question the caller actually has
     * ("normal, dynamic group, grid cell or special day — and which one?") and
     * drops the parts it can look up once from the listing.
     *
     * `source_id` is read through `source`: the pricing group's id, the special
     * day's id, or the grid cell's — whichever decided the number.
     *
     * @param  array<string, mixed>  $breakdown
     * @return array<string, mixed>
     */
    function compactSlotPricing(array $breakdown): array
    {
        $source  = $breakdown['source'] ?? 'base_price';
        $group   = $breakdown['pricing_group'] ?? null;
        $special = $breakdown['special_day'] ?? null;

        $sourceId = null;
        $label    = null;
        $color    = null;

        if ($source === 'special_day' && $special) {
            $sourceId = $special['id'] ?? null;
            $label    = $special['name'] ?? null;
        } elseif ($group) {
            $sourceId = $group['id'] ?? null;
            $label    = $group['name'] ?? null;
            $color    = $group['color'] ?? null;
        }

        return [
            'source'     => $source,
            'is_dynamic' => (bool) ($breakdown['is_dynamic'] ?? false),
            // What the slot would have cost untouched, so a caller can show the
            // move without a second lookup.
            'base_price' => $breakdown['base_price'] ?? null,
            'source_id'  => $sourceId,
            'label'      => $label,
            'color'      => $color,
        ];
    }
}

if (! function_exists('matchingPricingGroupForSlot')) {
    /**
     * The group that owns this slot. Deterministic: a slot straddling the seam
     * between two adjacent groups (17:00-18:00 against 06:00-17:30 and
     * 17:30-23:00) used to take whichever group the unordered hasMany happened
     * to yield first. Prefer the group containing the slot's start, then the
     * dashboard's own sort_order, then id.
     */
    function matchingPricingGroupForSlot($listing, $weekday, $slotStart, $slotEnd)
    {
        if ($listing instanceof \App\Models\Listing) {
            $listing->loadMissing('pricingGroups');
        }

        $candidates = collect($listing->pricingGroups ?? [])
            ->filter(fn($group) => pricingGroupCoversWeekday($group, $weekday))
            ->filter(fn($group) => timeSlotFallsInRange($slotStart, $slotEnd, $group->start_time, $group->end_time));

        if ($candidates->isEmpty()) {
            return null;
        }

        $startMinutes = timeToMinutes(substr((string) $slotStart, 0, 5));

        /*
         * Overlapping groups are legal (nothing validates against them on the
         * dashboard save path), so the tiebreak decides the price the customer
         * pays: prefer the group the slot STARTS inside, then the lowest
         * sort_order, then the lowest id.
         *
         * This was written as `sortBy([fn, fn, fn])`. Collection::sortByMany()
         * calls a callable comparison as `$prop($a, $b)` — it treats each
         * closure as a full COMPARATOR, not a key extractor — so these returned
         * `$a`'s own sort_order/id as if it were the <=> result. Any non-zero
         * value read as "$a is greater", which pushed the group with the LOWEST
         * sort_order to the end and picked an effectively arbitrary group
         * whenever two covered the same hour. Compare the ranks explicitly.
         */
        $rank = fn($group) => [
            slotStartInsidePricingGroup($startMinutes, $group) ? 0 : 1,
            (int) ($group->sort_order ?? 0),
            (int) $group->id,
        ];

        return $candidates
            ->sort(fn($a, $b) => $rank($a) <=> $rank($b))
            ->first();
    }
}

if (! function_exists('pricingGroupCoversWeekday')) {
    /**
     * `pricing_groups.days` holds two incompatible shapes depending on which
     * editor wrote the listing: the API validates lowercase day NAMES
     * ("monday"), while the dashboard's pricing grid posts numeric indices
     * ("1,2,3,4,5", Sunday = 0) that ListingDashboardService stores verbatim.
     * The old `in_array($weekday, $group->days)` only ever matched the names,
     * so every group built in the dashboard grid silently never applied and the
     * slot fell through to base_price. Normalize both sides to the index.
     */
    function pricingGroupCoversWeekday($group, $weekday): bool
    {
        $days = $group->days;
        if (is_string($days)) {
            $days = array_filter(array_map('trim', explode(',', $days)), fn($d) => $d !== '');
        }
        if (! is_array($days) || ! $days) {
            return false;
        }

        $wanted = \App\Models\Schedule::DAY_INDEX[strtolower((string) $weekday)] ?? null;
        if ($wanted === null) {
            return false;
        }

        foreach ($days as $day) {
            $day = is_string($day) ? strtolower(trim($day)) : $day;

            $index = is_numeric($day)
                ? (int) $day
                : (\App\Models\Schedule::DAY_INDEX[$day] ?? null);

            if ($index === $wanted) {
                return true;
            }
        }

        return false;
    }
}

if (! function_exists('slotStartInsidePricingGroup')) {
    function slotStartInsidePricingGroup(int $slotStartMinutes, $group): bool
    {
        $groupStart = timeToMinutes($group->start_time);
        $groupEnd   = in_array($group->end_time, ['00:00', '24:00'], true)
            ? 24 * 60
            : timeToMinutes($group->end_time);

        return $slotStartMinutes >= $groupStart && $slotStartMinutes < $groupEnd;
    }
}

if (! function_exists('timeSlotFallsInRange')) {
    function timeSlotFallsInRange($slotStart, $slotEnd, $rangeStart, $rangeEnd)
    {
        $slotStartMinutes  = timeToMinutes($slotStart);
        $slotEndMinutes    = timeToMinutes($slotEnd);
        $rangeStartMinutes = timeToMinutes($rangeStart);
        $rangeEndMinutes   = ($rangeEnd === '24:00') ? 24 * 60 : timeToMinutes($rangeEnd);

        $slotSpansMidnight = ($slotEnd === '00:00' || ($slotEndMinutes < $slotStartMinutes && $slotEnd !== '24:00'));
        if ($slotSpansMidnight) {
            $slotEndMinutes = 24 * 60;
        }

        if ($rangeEnd === '00:00') {
            $rangeEndMinutes = 24 * 60;
        }

        if ($rangeStart === '00:00' && $rangeEnd === '24:00') {
            return true;
        }

        return $slotStartMinutes < $rangeEndMinutes && $slotEndMinutes > $rangeStartMinutes;
    }
}

if (! function_exists('timeToMinutes')) {
    function timeToMinutes($time)
    {
        if ($time === '00:00' || $time === '24:00') {
            return ($time === '24:00') ? 24 * 60 : 0;
        }

        list($hours, $minutes) = explode(':', $time);
        return (int) $hours * 60 + (int) $minutes;
    }
}

if (! function_exists('generateInvoiceId')) {
    function generateInvoiceId()
    {
        $invoiceNumber = null;
        DB::transaction(function () use (&$invoiceNumber) {

            $year              = now()->year;
            $latestInvoice     = Invoice::lockForUpdate()->whereYear('created_at', $year)->latest('id')->first();
            $nextInvoiceNumber = $latestInvoice ? (int) substr($latestInvoice->invoice_number, -5) + 1 : 1;
            $invoiceNumber     = sprintf('INV_%s_%05d', $year, $nextInvoiceNumber);

        });

        return $invoiceNumber;
    }
}

if (! function_exists('generateAndStoreInvoicePdf')) {
    function generateAndStoreInvoicePdf($booking, $invoiceNumber)
    {
        $pdf = Pdf::loadView('pdfs.booking-invoice', compact('booking', 'invoiceNumber'));

        $fileName     = 'invoice_' . $invoiceNumber . '_' . $booking->id . '.pdf';
        $relativePath = 'invoices/' . $fileName;

        Storage::disk('public')->makeDirectory('invoices');
        Storage::disk('public')->put($relativePath, $pdf->output());

        return $relativePath;
    }
}

if (! function_exists('getSessionId')) {
    function getSessionId()
    {
        return "slot_locked_from_" . md5(request()->ip() . request()->userAgent());
    }
}

if (! function_exists('isRequestFromAdminSurface')) {
    /**
     * Staff surfaces are the admin console and the admin mobile app. Everything
     * else (customer website, customer mobile app, unknown callers) is treated
     * as a customer surface.
     */
    function isRequestFromAdminSurface($request = null): bool
    {
        $from = ($request ?: request())->header('from');

        return in_array($from, ['admin-web', MobileApps::ADMIN], true);
    }
}

if (! function_exists('unlockListingSlot')) {
    function unlockListingSlot($listing, $user, $booking)
    {
        $sessionId = getSessionId();

        SlotLock::where('date', $booking->date)
            ->where('session_id', $sessionId)
            ->where('listing_id', $listing->id)
            ->delete();
    }
}

if (! function_exists('createBookingInvoiceWithNotification')) {
    /**
     * Generate the booking's invoice, and raise the admin's record of the booking.
     *
     * The customer "Your Booking is Created!" email was REMOVED: a paid booking
     * auto-confirms moments later and sends "Your Booking Has Been Confirmed!",
     * so the pair arrived seconds apart saying much the same thing. The invoice is
     * still generated and stored against the booking either way — customers fetch
     * it from their booking rather than from an attachment.
     *
     * @param bool $notify Pass false to generate the invoice without notifying
     *                     anyone — used for bookings still waiting on a Stripe
     *                     payment.
     */
    function createBookingInvoiceWithNotification($booking, $user, bool $notify = true)
    {
        try {

            $invoiceNumber = generateInvoiceId();

            $invoicePath = generateAndStoreInvoicePdf($booking, $invoiceNumber);

            $user->invoices()->create([
                'user_id'        => $user->id,
                'status'         => 'generated',
                'path'           => $invoicePath,
                'booking_id'     => $booking->id,
                'invoice_number' => $invoiceNumber,
            ]);

            if (! $notify) {
                return;
            }

            $admins = User::whereHas("roles", function ($query) {
                $query->whereIn("name", ["super_admin", "admin"]);
            })->get();

            try {
                Notification::send($admins, new BookingCreatedAdminNotification($booking));
            } catch (Exception $e) {
                Log::error("Booking created admin notification failed: " . $e->getMessage(), [
                    'booking_id' => $booking->id,
                    'exception'  => $e,
                ]);
            }

        } catch (Exception $e) {
            Log::error("Booking created notification errors: " . $e->getMessage(), ["Exception" => $e]);
        }
    }
}

if (! function_exists('getFormattedDuration')) {
    function getFormattedDuration($slotMinutes)
    {
        $hours   = floor($slotMinutes / 60);
        $minutes = $slotMinutes % 60;
        if ($hours > 0 && $minutes > 0) {
            $duration = $hours . ' hour' . ($hours > 1 ? 's' : '') . ' ' . $minutes . ' minute' . ($minutes > 1 ? 's' : '');
        } elseif ($hours > 0) {
            $duration = $hours . ' hour' . ($hours > 1 ? 's' : '');
        } else {
            $duration = $minutes . ' minute' . ($minutes > 1 ? 's' : '');
        }

        return $duration;
    }
}

if (! function_exists('regenerateBookingInvoice')) {
    function regenerateBookingInvoice($booking)
    {
        try {
            $booking->loadMissing('user');
            $user = $booking->user;

            if (! $user) {
                return;
            }

            $existingInvoices = $user->invoices()->where('booking_id', $booking->id)->get();
            foreach ($existingInvoices as $invoice) {
                $invoice->status = 're_generated';
                $invoice->save();
            }

            $invoiceNumber = generateInvoiceId();
            $invoicePath   = generateAndStoreInvoicePdf($booking, $invoiceNumber);

            $user->invoices()->create([
                'user_id'        => $user->id,
                'status'         => 'generated',
                'path'           => $invoicePath,
                'booking_id'     => $booking->id,
                'invoice_number' => $invoiceNumber,
            ]);
        } catch (Exception $e) {
            Log::error("Failed to regenerate invoice for booking #{$booking->id}: " . $e->getMessage(), [
                'booking_id' => $booking->id,
                'exception'  => $e,
            ]);
        }
    }
}

if (! function_exists('initialBookingStatus')) {
    /**
     * The status a brand-new booking starts at.
     *
     * A settled deposit confirms the reservation; the arrival balance remains
     * unpaid. Full payment, skipped payment and free reservations also confirm.
     * An unpaid deposit or cash-on-arrival booking still starts pending.
     *
     * Every path that creates a booking must go through here, so admin-made,
     * customer-made and hosted-game bookings all answer the question the
     * same way.
     */
    function initialBookingStatus(?string $paymentStatus, float $totalPrice): string
    {
        if ($paymentStatus === BookingPaymentStatus::FULLY_PAID
            || $paymentStatus === BookingPaymentStatus::DEPOSIT_PAID
            || $paymentStatus === BookingPaymentStatus::PAYMENT_SKIPPED
            || $totalPrice <= 0) {
            return BookingStatus::CONFIRMED;
        }

        return BookingStatus::PENDING;
    }
}

if (! function_exists('autoConfirmBookingIfPaid')) {
    function autoConfirmBookingIfPaid($booking)
    {
        try {

            if (in_array($booking->status, [BookingStatus::CONFIRMED, BookingStatus::CANCELLED, BookingStatus::COMPLETED])) {
                return;
            }

            $totalPaid  = (float) $booking->payments()->where('status', PaymentStatus::PAID)->sum('amount');
            $totalPrice = (float) $booking->total_price;
            $depositConfirms = $totalPaid < $totalPrice && $booking->hasPaidDeposit();

            // Nothing owed at all: a free booking, or one whose price was fully
            // covered by a promocode or loyalty. There is no payment left to
            // wait for, so it confirms on the same rule as a paid booking —
            // otherwise a 100%-discount booking sits at pending for ever.
            $nothingToCollect = $totalPrice <= 0
                && ! $booking->payments()->where('status', PaymentStatus::PENDING)->exists();

            if (! $depositConfirms && ! $nothingToCollect && ($totalPrice <= 0 || $totalPaid < $totalPrice)) {
                return;
            }

            // A payment-skipped booking keeps that payment_status — no money
            // changed hands, so calling it "fully paid" would misreport revenue.
            if (! $depositConfirms && $booking->payment_status !== BookingPaymentStatus::FULLY_PAID
                && $booking->payment_status !== BookingPaymentStatus::PAYMENT_SKIPPED) {
                $booking->update([
                    'payment_status'        => BookingPaymentStatus::FULLY_PAID,
                    'full_payment_received' => true,
                ]);
            }

            $booking->update(['status' => BookingStatus::CONFIRMED]);

            $booking->history()->create([
                'user_id'     => $booking->user_id,
                'action'      => 'booking_confirmed',
                'description' => $depositConfirms
                    ? 'Booking automatically confirmed after deposit payment. Remaining balance is due on arrival.'
                    : 'Booking automatically confirmed after payment completion.',
            ]);

            if ($booking->loyalty_points_earned > 0) {
                $pointsAlreadyAdded = LoyaltyPoint::where('booking_id', $booking->id)
                    ->where('type', 'credit')
                    ->exists();

                if (! $pointsAlreadyAdded) {
                    $user = $booking->user;
                    $user->addLoyaltyPoints($booking->loyalty_points_earned, $booking->id, "Loyalty points earned for booking auto-confirmation");
                    $booking->history()->create([
                        'user_id'     => auth()->id() ?? $booking->user_id,
                        'action'      => 'loyalty_points_earned',
                        'description' => "{$booking->loyalty_points_earned} loyalty points added to user #{$user->id} ({$user->name}) for booking auto-confirmation.",
                    ]);
                }
            }

            try {
                $booking->load('venue:id,name,maps_url');
                $user = $booking->user;
                $user->notify(new BookingConfirmedUserNotification($booking));
            } catch (Exception $e) {
                Log::error("Booking confirmation notification failed: " . $e->getMessage(), [
                    'booking_id' => $booking->id,
                    'exception'  => $e,
                ]);
            }
        } catch (Exception $e) {
            Log::error("Failed to auto-confirm booking #{$booking->id}: " . $e->getMessage(), [
                'booking_id' => $booking->id,
                'exception'  => $e,
            ]);
        }
    }
}

if (! function_exists('pusherTrigger')) {
    /**
     * Trigger a Pusher event. No-ops gracefully if the Pusher SDK isn't
     * installed or credentials aren't configured. Failures are logged but
     * never thrown — broadcasting must never break business flow.
     */
    function pusherTrigger(string $channel, string $event, array $data = []): bool
    {
        try {
            if (! class_exists(\Pusher\Pusher::class)) {
                Log::warning('Pusher SDK not installed; skipping broadcast.', [
                    'channel' => $channel,
                    'event'   => $event,
                ]);
                return false;
            }

            $appId   = config('pusher.app_id');
            $key     = config('pusher.key');
            $secret  = config('pusher.secret');
            $cluster = config('pusher.cluster', 'ap2');

            if (empty($appId) || empty($key) || empty($secret)) {
                Log::warning('Pusher credentials missing; skipping broadcast.', [
                    'channel' => $channel,
                    'event'   => $event,
                ]);
                return false;
            }

            $pusher = new \Pusher\Pusher($key, $secret, $appId, [
                'cluster' => $cluster,
                'useTLS'  => (bool) config('pusher.use_tls', true),
            ]);

            $pusher->trigger($channel, $event, $data);
            return true;
        } catch (\Throwable $e) {
            Log::error('Pusher trigger failed: ' . $e->getMessage(), [
                'channel'   => $channel,
                'event'     => $event,
                'exception' => $e,
            ]);
            return false;
        }
    }
}

if (! function_exists('applyVenueFilter')) {
    /**
     * Constrain a query to the venues the signed-in admin may see.
     *
     * Delegates to App\Support\AccessScope, which understands OWNERSHIP
     * (a provider's own venues, inherited by their team) as well as the older
     * user_venue pins and platform jurisdiction. Previously this only honoured
     * the pivot, so a provider who owned venues but had no pivot rows — which is
     * every provider created by signup — matched nothing at all.
     */
    function applyVenueFilter($query, string $venueColumn = 'venue_id')
    {
        return App\Support\AccessScope::applyVenue($query, $venueColumn);
    }
}

if (! function_exists('applyVenueFilterToPayments')) {
    function applyVenueFilterToPayments($query)
    {
        $user = auth()->user();

        if (! $user) {
            return $query->whereRaw('1 = 0');
        }

        $venueIds = App\Support\AccessScope::allowedVenueIds($user);

        if ($venueIds === null) {
            return $query;
        }

        if (empty($venueIds)) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereHas('booking', function ($q) use ($venueIds) {
            $q->whereIn('venue_id', $venueIds);
        });
    }
}

if (! function_exists('schemaHas')) {
    /**
     * Does this table (or column) exist on the current connection?
     *
     * Cached per process: these answers cannot change inside a request, and the
     * notification helpers ask on every booking.
     *
     * This exists because production runs migrations on its own schedule, so the
     * SaaS business tables (`venues.business_id`, `business_memberships`) are not
     * guaranteed to be there yet. Selecting a column that does not exist throws,
     * and a throw inside a notification lookup means nobody gets told anything —
     * so every optional SaaS column is probed through here first.
     */
    function schemaHas(string $table, ?string $column = null): bool
    {
        static $cache = [];

        $key = $table . ':' . ($column ?? '*');

        if (array_key_exists($key, $cache)) {
            return $cache[$key];
        }

        try {
            $cache[$key] = $column === null
                ? \Illuminate\Support\Facades\Schema::hasTable($table)
                : \Illuminate\Support\Facades\Schema::hasColumn($table, $column);
        } catch (\Throwable $e) {
            $cache[$key] = false;
        }

        return $cache[$key];
    }
}

if (! function_exists('venueNotificationRecipientIds')) {
    /**
     * Resolve the user IDs that should receive notifications/broadcasts for
     * activity tied to a specific venue.
     *
     * Recipients mirror AccessScope: if someone cannot see the venue, they are
     * never told about it. Three groups, each scoped the way that role family is
     * scoped everywhere else —
     *
     *   GLOBAL    super_admin / admin — the whole platform, every venue.
     *
     *   PLATFORM  country_admin / platform_staff — scoped by JURISDICTION, so
     *             only when the venue sits in a country they administer.
     *             platform_staff carrying venue pins are narrowed to those pins.
     *
     *   PROVIDER  the business operating the venue — its owner plus their team.
     *             An owner is NEVER pinned (AccessScope::pinsApply excludes
     *             provider_admin), so they are reached through
     *             venues.owner_user_id — and, where the schema has them,
     *             venues.business_id / business_memberships — rather than the
     *             user_venue pivot. Team members carrying pins hear only about
     *             venues they are pinned to; unpinned ones hear about the whole
     *             business.
     *
     * Anyone explicitly pinned to the venue is always included, from either pin
     * source (user_venue pivot or users.assigned_location_ids). That is what
     * keeps the legacy senior_employee / junior_employee assignments working.
     *
     * Degrades rather than falls silent: the global admins are resolved first and
     * returned even if the venue-specific lookup fails, because the failure mode
     * that hid this bug for months was an empty recipient list that looked
     * exactly like "nothing to send".
     *
     * Use this helper for ANY delivery channel — OneSignal push, Pusher
     * broadcasting, email, in-app notifications, etc. It returns only
     * user IDs so the caller picks the transport.
     *
     * Pass `$venueId = null` to get only the global admins (for events
     * that aren't tied to a single venue).
     *
     * @return array<int>  De-duplicated list of recipient user IDs
     */
    function venueNotificationRecipientIds(?int $venueId): array
    {
        $finalise = fn (array $ids): array => array_values(array_unique(array_map('intval', $ids)));

        // ── GLOBAL ─────────────────────────────────────────────────────────
        // Resolved outside the try below so a failure in the venue-specific
        // work still reaches the platform admins.
        $globalIds = [];

        try {
            $globalIds = \App\Models\User::whereHas('roles', function ($q) {
                $q->whereIn('name', \App\Support\Capabilities::globalRoles());
            })
                ->active()
                ->pluck('id')
                ->all();
        } catch (\Throwable $e) {
            Log::error('venueNotificationRecipientIds: global admin lookup failed: ' . $e->getMessage(), [
                'exception' => $e,
            ]);
        }

        if (! $venueId) {
            return $finalise($globalIds);
        }

        try {
            $recipientIds = $globalIds;

            // The SaaS business columns are not on every deployed schema yet.
            $hasBusinessId   = schemaHas('venues', 'business_id');
            $hasMemberships  = schemaHas('business_memberships');
            $hasBusinesses   = schemaHas('businesses');

            $venueColumns = ['id', 'country_id', 'city_id', 'owner_user_id'];
            if ($hasBusinessId) {
                $venueColumns[] = 'business_id';
            }

            $venue = \App\Models\Venue::query()->select($venueColumns)->find($venueId);

            if (! $venue) {
                Log::info('venueNotificationRecipientIds: venue not found.', ['venue_id' => $venueId]);
                return $finalise($recipientIds);
            }

            $businessId = $hasBusinessId ? $venue->business_id : null;

            $platformRoles = \App\Support\Capabilities::platformRoles();
            $providerRoles = \App\Support\Capabilities::providerRoles();
            $staffRoles    = array_values(array_unique(array_merge($platformRoles, $providerRoles)));

            // ── PINNED to this venue, from either pin source ───────────────
            $pinnedQuery = \App\Models\User::query()
                ->whereHas('roles', fn ($q) => $q->whereIn('name', $staffRoles))
                ->where(function ($q) use ($venueId) {
                    $q->whereHas('venues', fn ($v) => $v->where('venues.id', $venueId));

                    if (schemaHas('users', 'assigned_location_ids')) {
                        // JSON_CONTAINS raises on malformed JSON, and a single
                        // bad legacy row would throw the whole lookup into the
                        // catch below — i.e. nobody gets notified. The
                        // JSON_VALID guard keeps one bad row from muting
                        // everyone. Both forms are checked: ids were written as
                        // numbers, but older rows hold them as strings.
                        $q->orWhereRaw(
                            'JSON_VALID(`assigned_location_ids`)'
                            . ' AND (JSON_CONTAINS(`assigned_location_ids`, ?)'
                            . ' OR JSON_CONTAINS(`assigned_location_ids`, ?))',
                            [(string) $venueId, '"' . $venueId . '"']
                        );
                    }
                })
                ->active();

            $recipientIds = array_merge($recipientIds, $pinnedQuery->pluck('id')->all());

            // ── PROVIDER: the business operating this venue ────────────────
            $ownerIds = [];

            if ($venue->owner_user_id) {
                $ownerIds[] = (int) $venue->owner_user_id;
            }

            if ($businessId && $hasBusinesses) {
                // A business whose owner account is gone still has members, so
                // the owner is resolved separately from the membership table.
                $businessOwnerId = DB::table('businesses')->where('id', $businessId)->value('owner_id');
                if ($businessOwnerId) {
                    $ownerIds[] = (int) $businessOwnerId;
                }
            }

            $ownerIds = array_values(array_unique($ownerIds));

            if ($ownerIds || ($businessId && $hasMemberships)) {
                $team = \App\Models\User::query()
                    ->whereHas('roles', fn ($q) => $q->whereIn('name', $providerRoles))
                    ->where(function ($q) use ($ownerIds, $businessId, $hasMemberships) {
                        if ($ownerIds) {
                            $q->whereIn('id', $ownerIds)
                                ->orWhereIn('owner_user_id', $ownerIds);
                        }

                        if ($businessId && $hasMemberships) {
                            $q->orWhereIn('id', \App\Models\BusinessMembership::query()
                                ->where('business_id', $businessId)
                                ->where('status', \App\Models\BusinessMembership::STATUS_ACTIVE)
                                ->select('user_id'));
                        }
                    })
                    ->active()
                    ->with('roles:id,name')
                    ->get(['id', 'assigned_location_ids']);

                // One query for the pivot instead of one per member.
                $pivotPinnedIds = $team->isEmpty() ? [] : DB::table('user_venue')
                    ->whereIn('user_id', $team->pluck('id'))
                    ->distinct()
                    ->pluck('user_id')
                    ->map(fn ($i) => (int) $i)
                    ->all();

                foreach ($team as $member) {
                    $hasPins = in_array((int) $member->id, $pivotPinnedIds, true)
                        || (bool) array_filter((array) ($member->assigned_location_ids ?? []));

                    // Narrowed to their pins — if this venue is one of them they
                    // were already collected above, so skip them here.
                    if ($hasPins && \App\Support\AccessScope::pinsApply($member)) {
                        continue;
                    }

                    $recipientIds[] = (int) $member->id;
                }
            }

            // ── PLATFORM: jurisdiction over the venue's country ────────────
            $venueCountryIds = [];

            if ($venue->country_id) {
                $venueCountryIds[] = (int) $venue->country_id;
            }

            if ($venue->city_id) {
                $cityCountryId = \Nnjeim\World\Models\City::where('id', $venue->city_id)->value('country_id');
                if ($cityCountryId) {
                    $venueCountryIds[] = (int) $cityCountryId;
                }
            }

            $jurisdictionRoles = array_values(array_diff($platformRoles, \App\Support\Capabilities::globalRoles()));

            $platformColumns = ['id', 'country_id'];
            foreach (['assigned_country_ids', 'assigned_location_ids'] as $optional) {
                if (schemaHas('users', $optional)) {
                    $platformColumns[] = $optional;
                }
            }

            $platformUsers = \App\Models\User::query()
                ->whereHas('roles', fn ($q) => $q->whereIn('name', $jurisdictionRoles))
                ->active()
                ->with('roles:id,name')
                ->get($platformColumns);

            foreach ($platformUsers as $platformUser) {
                // Pinned platform_staff are scoped to their pins, and were
                // already collected above if this venue is one of them.
                if ($platformUser->hasRole('platform_staff')
                    && \App\Support\AccessScope::pinnedVenueIds($platformUser)) {
                    continue;
                }

                $countryIds = \App\Support\AccessScope::jurisdictionCountryIds($platformUser);

                if ($countryIds === null) {
                    $recipientIds[] = (int) $platformUser->id;
                    continue;
                }

                if (! $countryIds || ! $venueCountryIds) {
                    continue;
                }

                if (array_intersect($countryIds, $venueCountryIds)) {
                    $recipientIds[] = (int) $platformUser->id;
                }
            }

            return $finalise($recipientIds);
        } catch (\Throwable $e) {
            Log::error('venueNotificationRecipientIds failed: ' . $e->getMessage(), [
                'venue_id'  => $venueId,
                'exception' => $e,
            ]);

            // Degrade to the platform admins rather than muting everyone.
            return $finalise($globalIds);
        }
    }
}
if (! function_exists('notifyUserRefresh')) {
    /**
     * Broadcast a private "refresh" event to a single user.
     * Channel: private-refresh.{userId} · event: refresh
     */
    function notifyUserRefresh(int $userId, array $payload = []): bool
    {
        try {
            if ($userId <= 0) {
                return false;
            }
            broadcast(new \App\Events\AdminAppRefresh($userId, $payload));

            return true;
        } catch (\Throwable $e) {
            Log::error('notifyUserRefresh failed: ' . $e->getMessage(), [
                'user_id'  => $userId,
                'payload'  => $payload,
                'exception'=> $e,
            ]);

            return false;
        }
    }
}

if (! function_exists('notifyVenueStaffRefresh')) {
    /**
     * Broadcast a private "refresh" event to every staff member who has
     * access to the given venue, plus all super_admins / admins.
     *
     * Each recipient gets their own private channel:
     *      private-refresh.{userId}      event: refresh
     *
     * This is exactly the same scoping as `applyVenueFilter()` uses for data
     * visibility, and the same targeting strategy as `OneSignalService::sendToUsers()`,
     * so we never refresh a staff member who can't see the underlying record.
     *
     * Active users only. Falls back to log + returns 0 on misconfiguration —
     * a broken broadcast must never break the underlying business action.
     *
     * @param  int|null  $venueId  Booking's venue id (nullable — global event with no venue)
     * @param  array     $payload  Arbitrary payload sent to mobile clients
     * @return int                 Number of users notified
     */
    function notifyVenueStaffRefresh(?int $venueId, array $payload = []): int
    {
        try {
            $userIds = venueNotificationRecipientIds($venueId);

            if (empty($userIds)) {
                Log::info('notifyVenueStaffRefresh: no recipients resolved.', [
                    'venue_id' => $venueId,
                    'payload'  => $payload,
                ]);
                return 0;
            }

            foreach ($userIds as $userId) {
                broadcast(new \App\Events\AdminAppRefresh($userId, $payload));
            }

            return count($userIds);
        } catch (\Throwable $e) {
            Log::error('notifyVenueStaffRefresh failed: ' . $e->getMessage(), [
                'venue_id'  => $venueId,
                'payload'   => $payload,
                'exception' => $e,
            ]);
            return 0;
        }
    }
}

if (! function_exists('nextMonthBookingsOpenDays')) {
    /**
     * How many days of the current month must REMAIN (counting today) before
     * next month's slots open. Admin-configurable; falls back to 7.
     *
     * Exists so the three readers can't drift, and because the inline version
     * they shared was silently broken: `(int) $x ?? 7` parses as
     * `((int) $x) ?? 7`, and `(int) null` is 0 — never null — so the ?? branch
     * was unreachable. A missing row yielded 0, and `$daysRemaining <= 0` is
     * never true, which shut next month off entirely instead of defaulting to 7.
     */
    function nextMonthBookingsOpenDays(): int
    {
        $configured = \App\Models\Setting::where('key', 'days_until_next_month_bookings_open')->value('value');

        // Cast only AFTER the fallback, and treat '' / non-numeric as unset —
        // a blank settings row must not mean "never open next month".
        return is_numeric($configured) ? (int) $configured : 7;
    }
}

if (! function_exists('paymentStateInPlainWords')) {
    /**
     * A booking's payment state, written for the person reading the history.
     *
     * Staff open a booking's history to answer one question above all others —
     * "has this customer been charged?" — and the raw column says `deposit_paid`,
     * which is a database value, not an answer. Worse, it tells them nothing
     * about what to DO: a cancelled booking that took a deposit needs a refund
     * decision, and a cancelled booking that took nothing needs no action at all.
     */
    function paymentStateInPlainWords(?string $paymentStatus): string
    {
        return match ($paymentStatus) {
            BookingPaymentStatus::PENDING         => 'nothing had been paid',
            BookingPaymentStatus::DEPOSIT_PAID    => 'the deposit had been paid',
            BookingPaymentStatus::FULLY_PAID      => 'it had been paid in full',
            BookingPaymentStatus::PAYMENT_SKIPPED => 'payment had been skipped',
            BookingPaymentStatus::CANCELLED       => 'the payment was already cancelled',
            default                               => 'the payment state was not recorded',
        };
    }
}

if (! function_exists('bookingMoney')) {
    /**
     * An amount as staff should read it: with the currency the booking is in.
     *
     * History lines carried bare numbers, and a bare number is not money — a
     * reader cannot tell 45 riyals from 45 rupees on a platform that now runs
     * in several countries.
     */
    function bookingMoney($amount, $booking = null): string
    {
        $currency = $booking?->currency
            ?: ($booking?->venue_id ? \App\Models\Booking::currencyForVenue($booking->venue_id) : '');

        $formatted = number_format((float) $amount, 2);

        return trim($currency . ' ' . $formatted);
    }
}

if (! function_exists('nextMonthBookingsOpenOn')) {
    /**
     * The day NEXT month's calendar opens for booking.
     *
     * isDateAvailableForBooking() opens it once `daysInMonth - day + 1 <= N`,
     * which is the same as saying "from day `daysInMonth - N + 1` onwards".
     * Derived from that one inequality rather than restated, so the date a
     * customer is promised cannot drift from the date the gate actually lifts.
     */
    function nextMonthBookingsOpenOn($today = null): \Carbon\Carbon
    {
        $today = $today instanceof \Carbon\Carbon
            ? $today->copy()->startOfDay()
            : \Carbon\Carbon::today();

        $openingDay = max(1, $today->daysInMonth - nextMonthBookingsOpenDays() + 1);

        return $today->copy()->day($openingDay);
    }
}

if (! function_exists('slotsNotOpenYetMessage')) {
    /**
     * Why a date has no slots, said the way a customer would say it.
     *
     * The gate used to answer with its own rule — "Next month slots become
     * available when there are 7 or fewer days remaining in the current month"
     * — which asks the reader to do the arithmetic the server already did. And
     * the storefront never showed even that: a failed response became the flat
     * word "Closed", which reads as "this venue does not open that day" rather
     * than "not released yet, come back".
     *
     * Three different situations reach this, and only one of them is about next
     * month, so each gets its own sentence.
     */
    function slotsNotOpenYetMessage($date): string
    {
        $dateCarbon = $date instanceof \Carbon\Carbon ? $date->copy() : \Carbon\Carbon::parse($date);
        $today      = \Carbon\Carbon::today();

        if ($dateCarbon->lt($today)) {
            return 'That date has already passed. Please pick an upcoming day.';
        }

        $nextMonth = $today->copy()->addMonthNoOverflow();

        if ($dateCarbon->month === $nextMonth->month && $dateCarbon->year === $nextMonth->year) {
            $opensOn = nextMonthBookingsOpenOn($today);

            return $nextMonth->format('F') . " slots are not open yet — they go live on "
                . $opensOn->format('j M') . '. Keep an eye on this listing.';
        }

        return 'Bookings open one month at a time, so ' . $dateCarbon->format('F Y')
            . ' is too far ahead. Please pick a day in ' . $today->format('F')
            . ' or ' . $nextMonth->format('F') . '.';
    }
}

if (! function_exists('isDateAvailableForBooking')) {
    function isDateAvailableForBooking($date)
    {
        $dateCarbon = $date instanceof \Carbon\Carbon  ? $date : \Carbon\Carbon::parse($date);
        $today      = \Carbon\Carbon::today();

        if ($dateCarbon->lt($today)) {
            return false;
        }

        if ($dateCarbon->month === $today->month && $dateCarbon->year === $today->year) {
            return true;
        }

        $nextMonth = $today->copy()->addMonthNoOverflow();

        if ($dateCarbon->month === $nextMonth->month && $dateCarbon->year === $nextMonth->year) {
            $daysBeforeNextMonth = nextMonthBookingsOpenDays();

            // Plain subtraction, NOT a Carbon diff. endOfMonth() is 23:59:59 and
            // Carbon 3 returns diffInDays() as a float, so Oct 25 -> Oct 31 came
            // back as 6.9999... not 6; the +1 (written when diffInDays truncated
            // to an int) then made daysRemaining 7.9999, and `7.9999 <= 7` is
            // false — the window opened a full day late for every threshold.
            // Both dates are in the same month, so the day numbers are the answer.
            $daysRemaining = $today->daysInMonth - $today->day + 1;

            return $daysRemaining <= $daysBeforeNextMonth;
        }

        return false;
    }
}

if (! function_exists('formatLeadTime')) {
    /**
     * A lead time in minutes as something readable: "45 minutes", "2 hours",
     * "1 hour 30 minutes", "3 days".
     */
    function formatLeadTime(int $minutes): string
    {
        if ($minutes < 60) {
            return $minutes . ' ' . \Illuminate\Support\Str::plural('minute', $minutes);
        }

        if ($minutes % 1440 === 0) {
            $days = intdiv($minutes, 1440);
            return $days . ' ' . \Illuminate\Support\Str::plural('day', $days);
        }

        $hours            = intdiv($minutes, 60);
        $remainingMinutes = $minutes % 60;

        $label = $hours . ' ' . \Illuminate\Support\Str::plural('hour', $hours);

        if ($remainingMinutes > 0) {
            $label .= ' ' . $remainingMinutes . ' ' . \Illuminate\Support\Str::plural('minute', $remainingMinutes);
        }

        return $label;
    }
}

if (! function_exists('minAdvanceBookingError')) {
    /**
     * Null when the slot satisfies the listing's minimum lead time — which is
     * always the case on a staff surface, where walk-ins are booked minutes
     * before they start. Otherwise the message to show the customer.
     */
    function minAdvanceBookingError($listing, $date, $startTime): ?string
    {
        $cutoff = $listing->minAdvanceCutoff();

        if (! $cutoff) {
            return null;
        }

        $dateString = $date instanceof \Carbon\Carbon
            ? $date->toDateString()
            : \Carbon\Carbon::parse($date)->toDateString();

        $slotStart = \Carbon\Carbon::parse($dateString . ' ' . \Carbon\Carbon::parse($startTime)->format('H:i'));

        if ($slotStart->gte($cutoff)) {
            return null;
        }

        return 'This slot must be booked at least '
            . formatLeadTime($listing->minAdvanceMinutes())
            . ' in advance.';
    }
}

if (! function_exists('processBookingCancellation')) {
    /**
     * Apply cancellation side-effects: gift card, loyalty, promocode, history.
     * Payment records and booking payment_status are left unchanged (statuses reserved for future use).
     * Caller must run inside a DB transaction and set booking status/cancel fields.
     *
     * @return array{history_notes: string[]}
     */
    function processBookingCancellation(
        \App\Models\Booking $booking,
        ?int $cancelledByUserId,
        ?string $cancelReason = null,
        bool $isSystemCancel = false,
        ?string $previousStatus = null,
        ?string $previousPaymentStatus = null
    ): array {
        $booking->loadMissing(['user', 'payments', 'promocode', 'listing']);

        $user                  = $booking->user;
        $previousStatus        = $previousStatus ?? $booking->status;
        $previousPaymentStatus = $previousPaymentStatus ?? $booking->payment_status;
        $cancelledByUserId     = $cancelledByUserId ?? ($isSystemCancel ? null : auth()->id());
        $historyNotes          = [];

        $giftCardAmount = (float) ($booking->gift_card_amount ?? 0);
        if ($giftCardAmount > 0 && $user) {
            $existingRefund = \App\Models\Transaction::where('booking_id', $booking->id)
                ->where('type', 'credit')
                ->where('reason', 'LIKE', '%Gift card balance refunded%')
                ->exists();

            if (! $existingRefund) {
                $user->transactions()->create([
                    'type'       => 'credit',
                    'booking_id' => $booking->id,
                    'amount'     => $giftCardAmount,
                    'reason'     => "Gift card balance refunded for cancelled booking #{$booking->booking_id}",
                ]);
                $historyNotes[] = 'Gift card balance refunded.';
            }
        }

        if ($booking->promocode_id && $booking->promocode) {
            $booking->promocode->decrementUsage($booking->user_id);
            $historyNotes[] = 'Promocode usage reversed.';
        }

        $loyaltyCredit = \App\Models\LoyaltyPoint::where('booking_id', $booking->id)
            ->where('type', 'credit')
            ->first();

        if ($loyaltyCredit && $user) {
            $alreadyReversed = \App\Models\LoyaltyPoint::where('booking_id', $booking->id)
                ->where('type', 'debit')
                ->where('reason', 'LIKE', '%cancelled booking%')
                ->exists();

            if (! $alreadyReversed) {
                $user->loyaltyPoints()->create([
                    'points'     => $loyaltyCredit->points,
                    'type'       => 'debit',
                    'booking_id' => $booking->id,
                    // The reversal must land in the SAME country as the credit
                    // it undoes, or one country keeps points it never earned
                    // while another goes negative.
                    'country_id' => $loyaltyCredit->country_id,
                    'reason'     => "Loyalty points reversed for cancelled booking #{$booking->booking_id}",
                ]);
                $user->updateLoyaltyTier();
                $historyNotes[] = "{$loyaltyCredit->points} loyalty points reversed.";
            }
        }

        $paymentCount = $booking->payments->count();

        $actorName = $isSystemCancel
            ? 'System'
            : (($cancelledByUserId && ($actor = \App\Models\User::find($cancelledByUserId))) ? $actor->name : 'Unknown');

        // This read "Booking cancelled from pending to cancelled by X." — it told
        // the reader the new status twice (the row is already a cancellation) and
        // dropped everything that matters. The sprintf was handed the reason, the
        // payment status and the payment count, but the format string had only
        // three placeholders, so all three were silently thrown away: the reason a
        // booking was cancelled NEVER reached the history anyone reads.
        //
        // Lead with who and why, then the one thing staff act on — whether money
        // was taken and is now sitting against a cancelled booking.
        $parts = ["Cancelled by {$actorName}."];

        if ($cancelReason) {
            $parts[] = "Reason: {$cancelReason}.";
        }

        $parts[] = sprintf(
            'Was %s, and %s.',
            $previousStatus ?? 'in an unknown state',
            paymentStateInPlainWords($previousPaymentStatus)
        );

        if (in_array($previousPaymentStatus, [BookingPaymentStatus::DEPOSIT_PAID, BookingPaymentStatus::FULLY_PAID], true)) {
            $parts[] = $paymentCount === 1
                ? 'A refund may be owed — 1 payment record is kept against this booking.'
                : "A refund may be owed — {$paymentCount} payment records are kept against this booking.";
        }

        $description = implode(' ', $parts);

        $booking->history()->create([
            'user_id'     => $cancelledByUserId ?? $booking->user_id,
            'action'      => 'booking_cancelled',
            'description' => trim($description),
        ]);

        if (! empty($historyNotes)) {
            $booking->history()->create([
                'user_id'     => $cancelledByUserId ?? $booking->user_id,
                'action'      => 'cancellation_adjustments',
                'description' => implode(' ', $historyNotes),
            ]);
        }

        return [
            'history_notes' => $historyNotes,
        ];
    }
}

if (! function_exists('expireOpenStripeCheckoutSessionsForBooking')) {
    function expireOpenStripeCheckoutSessionsForBooking(\App\Models\Booking $booking): void
    {
        $stripeSecret = trim((string) config('services.stripe.secret'));
        if ($stripeSecret === '') {
            return;
        }

        try {
            $stripeClient = new \Stripe\StripeClient($stripeSecret);
            $sessions     = \App\Models\StripeSession::where('processed', false)
                ->where(function ($query) use ($booking) {
                    $query->where('booking_id', $booking->id)
                        ->orWhereJsonContains('booking_ids', $booking->id);
                })
                ->get();

            foreach ($sessions as $stripeSession) {
                try {
                    $session = $stripeClient->checkout->sessions->retrieve($stripeSession->session_id);
                    if (($session->status ?? null) === 'open') {
                        $stripeClient->checkout->sessions->expire($stripeSession->session_id);
                    }
                } catch (\Throwable $e) {
                    Log::error('Failed to expire Stripe checkout session', [
                        'booking_id' => $booking->id,
                        'session_id' => $stripeSession->session_id,
                        'error'      => $e->getMessage(),
                    ]);
                }
            }
        } catch (\Throwable $e) {
            Log::error('Failed to expire Stripe sessions for booking', [
                'booking_id' => $booking->id,
                'error'      => $e->getMessage(),
            ]);
        }
    }
}

if (! function_exists('applyBookingOverlapWhere')) {
    /**
     * Add a cross-midnight-aware "does this row overlap [$requestStart,
     * $requestEnd] on $requestDate" filter to a query builder.
     *
     * Handles four cases correctly:
     *   1. Normal request window (end > start) vs normal row (end > start).
     *   2. Normal request window vs cross-midnight row on the previous day
     *      whose tail spills into $requestDate.
     *   3. Cross-midnight request window (end <= start) vs any row on
     *      $requestDate that overlaps its late-night portion.
     *   4. Cross-midnight request window vs any row on the next day that
     *      overlaps its early-morning portion.
     *
     * The caller must apply venue/listing/status filters BEFORE calling this
     * helper. The helper adds its own whereIn(date, ...) so callers should
     * NOT also apply a where('date', ...) filter.
     */
    function applyBookingOverlapWhere(
        $query,
        string $requestDate,
        string $requestStart,
        string $requestEnd,
        string $startCol = 'start_time',
        string $endCol = 'end_time',
        string $dateCol = 'date',
        ?string $tablePrefix = null
    ): void {
        $prefix = $tablePrefix ? $tablePrefix . '.' : '';
        $qStart = $prefix . $startCol;
        $qEnd   = $prefix . $endCol;
        $qDate  = $prefix . $dateCol;

        $reqStartCarbon = \Carbon\Carbon::createFromFormat('Y-m-d H:i', $requestDate . ' ' . $requestStart);
        $reqEndCarbon   = \Carbon\Carbon::createFromFormat('Y-m-d H:i', $requestDate . ' ' . $requestEnd);
        if ($reqEndCarbon->lte($reqStartCarbon)) {
            $reqEndCarbon->addDay();
        }
        $reqStartDt = $reqStartCarbon->format('Y-m-d H:i:s');
        $reqEndDt   = $reqEndCarbon->format('Y-m-d H:i:s');

        $prevDate = \Carbon\Carbon::parse($requestDate)->subDay()->format('Y-m-d');
        $nextDate = \Carbon\Carbon::parse($requestDate)->addDay()->format('Y-m-d');

        $candidateDates = [$prevDate, $requestDate];
        if ($reqEndCarbon->format('Y-m-d') !== $requestDate) {
            $candidateDates[] = $nextDate;
        }

        $endDatetimeSql = 'STR_TO_DATE(CONCAT(
            CASE WHEN TIME(' . $qEnd . ') <= TIME(' . $qStart . ')
                 THEN DATE_ADD(' . $qDate . ', INTERVAL 1 DAY)
                 ELSE ' . $qDate . '
            END, " ", ' . $qEnd . '
        ), "%Y-%m-%d %H:%i:%s")';

        $startDatetimeSql = 'STR_TO_DATE(CONCAT(' . $qDate . ', " ", ' . $qStart . '), "%Y-%m-%d %H:%i:%s")';

        $query->whereIn($qDate, $candidateDates)
            ->whereRaw($startDatetimeSql . ' < ? AND ' . $endDatetimeSql . ' > ?', [$reqEndDt, $reqStartDt]);
    }
}

if (! function_exists('bookingEffectiveEndDatetimeSql')) {
    /**
     * SQL expression that returns a booking's effective end datetime, adding a
     * day when end_time <= start_time (i.e. the booking spans midnight, or
     * ends exactly at midnight stored as '00:00'). Handy for expiry checks.
     */
    function bookingEffectiveEndDatetimeSql(
        string $startCol = 'start_time',
        string $endCol = 'end_time',
        string $dateCol = 'date',
        ?string $tablePrefix = null
    ): string {
        $prefix = $tablePrefix ? $tablePrefix . '.' : '';
        $qStart = $prefix . $startCol;
        $qEnd   = $prefix . $endCol;
        $qDate  = $prefix . $dateCol;

        return 'STR_TO_DATE(CONCAT(
            CASE WHEN TIME(' . $qEnd . ') <= TIME(' . $qStart . ')
                 THEN DATE_ADD(' . $qDate . ', INTERVAL 1 DAY)
                 ELSE ' . $qDate . '
            END, " ", ' . $qEnd . '
        ), "%Y-%m-%d %H:%i:%s")';
    }
}
