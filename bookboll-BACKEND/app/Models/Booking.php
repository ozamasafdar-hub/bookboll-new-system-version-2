<?php
namespace App\Models;

use App\Traits\GeneratesUuid;
use App\Traits\LogsModelActivity;
use App\Traits\TracksCreator;

use BookingStatus;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use PaymentMethod;
use PaymentStatus;
use PaymentType;

class Booking extends Model
{
    use GeneratesUuid, LogsModelActivity, TracksCreator;

    protected $fillable = [
        'uuid',
        'date',
        'notes',
        'status',
        'user_id',
        'is_free',
        'end_time',
        'duration',
        'subtotal',
        'venue_id',
        'created_by',
        'listing_id',
        'start_time',
        'booking_id',
        'total_price',
        'currency',
        'refunded_at',
        'promocode_id',
        'cancelled_by',
        'cancelled_at',
        'cancel_reason',
        'payment_status',
        'payment_method',
        'refunded_amount',
        'discount_amount',
        'gift_card_amount',
        'payment_breakdown',
        'full_payment_received',
        'loyalty_points_earned',
        'origin',
        'players',
        'program_id',
        'commission_channel',
        'commission_standard_pct',
        'commission_effective_pct',
        'commission_plan_eligible',
        'commission_fee_minor',
        'merchant_share_minor',
        'commission_currency',
        'loyalty_quote_id',
        'loyalty_discount',
        'record_purpose',
    ];

    protected $casts = [
        'creation_context'      => 'array',
        'financial_context'     => 'array',
        'payment_breakdown'     => 'array',
        'full_payment_received' => 'boolean',
        'subtotal'              => 'decimal:2',
        'discount_amount'       => 'decimal:2',
        'total_price'           => 'decimal:2',
    ];

    protected $appends = ['due_amount', 'status_label'];

    public function getStatusLabelAttribute()
    {
        if (
            $this->status == BookingStatus::PENDING
            && $this->payment_status === \BookingPaymentStatus::DEPOSIT_PAID
            && (
                $this->relationLoaded('payments')
                    ? $this->payments->count() > 1
                    : $this->payments()->count() > 1
            )
        ) {

            $remainingPaymentExists = $this->relationLoaded('payments')
                ? $this->payments
                ->where('type', PaymentType::REMAINING)
                ->where('method', PaymentMethod::CASH)
                ->where('status', PaymentStatus::PENDING)
                ->isNotEmpty()
                : $this->payments()
                ->where('type', PaymentType::REMAINING)
                ->where('method', PaymentMethod::CASH)
                ->where('status', PaymentStatus::PENDING)
                ->exists();

            if ($remainingPaymentExists) {
                return 'Deposit paid - confirmed';
            }
        }

        return $this->status;
    }

    public function getDueAmountAttribute()
    {
        if ($this->is_free) {
            return 0;
        }

        if ($this->status === BookingStatus::CANCELLED) {
            return 0;
        }

        if (
            $this->payment_status === \BookingPaymentStatus::FULLY_PAID
            || $this->payment_status === \BookingPaymentStatus::PAYMENT_SKIPPED
            || $this->full_payment_received
        ) {
            return 0;
        }

        $totalPrice = (float) $this->total_price;

        $totalPaid = $this->relationLoaded('payments')
            ? (float) $this->payments
                ->where('status', PaymentStatus::PAID)
                ->sum('amount')
            : (float) $this->payments()
                ->where('status', PaymentStatus::PAID)
                ->sum('amount');

        $dueAmount = $totalPrice - $totalPaid;

        if ($dueAmount <= 0) {
            return 0;
        }

        if ($this->payment_status === \BookingPaymentStatus::DEPOSIT_PAID && $totalPaid <= 0) {
            $breakdown = $this->payment_breakdown;
            return (float) ($breakdown['remaining_amount'] ?? $dueAmount);
        }

        return $dueAmount;
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function venue()
    {
        return $this->belongsTo(Venue::class);
    }

    public function listing()
    {
        return $this->belongsTo(Listing::class);
    }

    public function promocode()
    {
        return $this->belongsTo(Promocode::class);
    }

    public function addOns()
    {
        return $this->belongsToMany(AddOn::class)->withPivot(['quantity', 'unit_price', 'subtotal']);
    }

    public function payments()
    {
        return $this->hasMany(Payment::class);
    }

    public function invoices()
    {
        return $this->hasMany(Invoice::class);
    }

    public function latestInvoice()
    {
        return $this->hasOne(Invoice::class)->latestOfMany();
    }

    public function history()
    {
        return $this->hasMany(BookingHistory::class, 'booking_id');
    }

    public function creationHistory()
    {
        return $this->hasOne(BookingHistory::class, 'booking_id')->ofMany(['id'=>'min'],
            fn ($q) => $q->where('action', 'booking_created'));
    }

    public function review()
    {
        return $this->hasOne(Review::class);
    }

    public function resourcesAllocated()
    {
        return $this->belongsToMany(Resource::class, 'booking_resource')->withPivot('quantity');
    }

    public function scopeActive($query)
    {
        return $query->whereIn('status', [BookingStatus::PENDING, BookingStatus::CONFIRMED]);
    }

    public function scopeCompleted($query)
    {
        return $query->where('status', BookingStatus::COMPLETED);
    }

    public function scopeForDate($query, $date)
    {
        return $query->whereDate('start_time', $date);
    }

    // public function calculateDuration()
    // {
    //     return $this->start_time->diffInHours($this->end_time);
    // }

    public function isFullyPaid()
    {
        return $this->total_paid >= $this->total_price;
    }

    public function scopeWithTotals($query)
    {
        return $query->with(['payments' => function ($q) {
            $q->select('id', 'booking_id', 'amount', 'status');
        }]);
    }

    public function isAvailable()
    {
        $overlapping = self::where('listing_id', $this->listing_id)
            ->where('id', '!=', $this->id)
            ->where('status', '!=', BookingStatus::CANCELLED)
            ->where(function ($q) {
                $q->whereBetween('start_time', [$this->start_time, $this->end_time])
                    ->orWhereBetween('end_time', [$this->start_time, $this->end_time])
                    ->orWhere(function ($q) {
                        $q->where('start_time', '<=', $this->start_time)
                            ->where('end_time', '>=', $this->end_time);
                    });
            })->exists();

        if ($overlapping) {
            return false;
        }

        $listingSchedule = $this->listing->schedules()->where('day', strtolower($this->start_time->format('l')))->first();
        if (! $listingSchedule || ! $listingSchedule->is_open) {
            return false;
        }

        $startHour = $this->start_time->format('H:i');
        $endHour   = $this->end_time->format('H:i');
        if ($startHour < $listingSchedule->start_time || $endHour > $listingSchedule->end_time) {
            return false;
        }

        return true;
    }

    /**
     * The currency a venue charges in: its own override, then its country's, then
     * the platform default. Only ever read when a booking is created.
     */
    public static function currencyForVenue($venueId): string
    {
        $venue = $venueId
            ? Venue::select(['id', 'country_id', 'city_id', 'currency_override'])->find($venueId)
            : null;

        if ($venue && ! blank($venue->currency_override)) {
            return (string) $venue->currency_override;
        }

        $countryId = $venue?->country_id
            ?: ($venue?->city_id ? \Nnjeim\World\Models\City::whereKey($venue->city_id)->value('country_id') : null);

        if ($countryId && class_exists(\Nnjeim\World\Models\Country::class)) {
            $code = \Nnjeim\World\Models\Country::with('currency')->find($countryId)?->currency?->code;

            if (! blank($code)) {
                return (string) $code;
            }
        }

        // Last resort, and the only one: an amount with no currency at all is worse
        // than one wearing the platform's own.
        return (string) (DB::table('settings')->where('key', 'platform_default_currency')->value('value') ?: 'QAR');
    }

    protected static function booted()
    {
        static::creating(function ($booking) {
            if (auth()->check()) $booking->created_by ??= auth()->id();
            if (\Illuminate\Support\Facades\Schema::hasColumn('bookings', 'creation_context')) {
                $booking->creation_context = \App\Support\BookingProvenance::forNew($booking);
                if ($booking->creation_context['channel'] === 'admin') {
                    $booking->origin = 'manual_admin';
                    $booking->commission_effective_pct = 0;
                    $booking->commission_fee_minor = 0;
                    $booking->payment_breakdown = array_merge($booking->payment_breakdown ?? [], ['commission_amount'=>0]);
                }
            }
            if (\Illuminate\Support\Facades\Schema::hasColumn('bookings', 'financial_context')) {
                $booking->financial_context = \App\Support\PaymentFinancialContext::forNewBooking($booking);
            }
            if (auth()->check()) {
                $booking->created_by = $booking->created_by ?? auth()->id();
            }

            /*
             * Say what this row IS, at the moment it is written.
             *
             * Three different things share this table — a real sale, a provider
             * reserving a slot for their own customer, and a membership session
             * placeholder — and they were told apart by inspecting payment
             * fields at every call site. Recording it here means every creation
             * path says so, including ones added later, and the backfill's
             * inference never has to be relied on for new rows.
             */
            if ($booking->record_purpose === null) {
                if (($booking->origin ?? null) === 'class_program') {
                    $booking->record_purpose = \App\Support\BookingPurpose::SESSION;
                } else {
                    $skipped = ($booking->payment_method ?? null) === 'payment_skipped'
                        || ($booking->payment_status ?? null) === 'payment_skipped';

                    $booking->record_purpose = $skipped
                        ? \App\Support\BookingPurpose::BLOCK
                        : \App\Support\BookingPurpose::SALE;
                }
            }

            // Stamp the currency once, here, rather than in each of the five places
            // that create a booking. Stored, not derived on read: a venue that later
            // changes currency must not relabel money already taken in another.
            if (blank($booking->currency)) {
                $booking->currency = self::currencyForVenue($booking->venue_id);
            }
        });

        static::saving(function ($booking) {
            if ($booking->start_time && $booking->end_time) {
                // Use date if available, otherwise use today's date
                $date = $booking->date ?? now()->format('Y-m-d');

                // Parse start_time and end_time - they might be datetime strings or time strings
                $startTimeParsed = Carbon::parse($booking->start_time);
                $endTimeParsed   = Carbon::parse($booking->end_time);

                // Extract just the time portion (H:i format)
                $startTimeStr = $startTimeParsed->format('H:i');
                $endTimeStr   = $endTimeParsed->format('H:i');

                // Create datetime objects using the booking date
                $start = Carbon::parse($date . ' ' . $startTimeStr);
                $end   = Carbon::parse($date . ' ' . $endTimeStr);

                // If end time is less than start time, it means end time is on the next day
                if ($end->lt($start)) {
                    $end->addDay();
                }

                $booking->duration = abs($end->diffInHours($start));
            }
        });

        static::creating(function ($booking) {

            $now        = now();
            $year       = $now->format('Y');
            $month      = $now->format('m');
            $prefix     = "BKN_{$year}_{$month}_";
            $maxRetries = 5;
            $attempts   = 0;

            do {

                $attempts++;

                $latestBooking = static::lockForUpdate()
                    ->where('booking_id', 'like', "{$prefix}%")
                    ->orderByDesc('id')
                    ->first();

                $nextNumber = $latestBooking
                    ? ((int) substr($latestBooking->booking_id, -5)) + 1
                    : 1;

                $bookingId = sprintf('%s%05d', $prefix, $nextNumber);

                $exists = static::where('booking_id', $bookingId)->exists();

                if ($attempts >= $maxRetries && $exists) {
                    throw new \Exception('Failed to generate unique booking ID after multiple attempts');
                }

            } while ($exists);

            $booking->booking_id = $bookingId;
        });
    }
    protected function activityLogName(): string
    {
        return 'booking';
    }

    protected function activityLogAttributes(): array
    {
        return [
            'date',
            'notes',
            'status',
            'user_id',
            'is_free',
            'end_time',
            'duration',
            'subtotal',
            'venue_id',
            'created_by',
            'listing_id',
            'start_time',
            'booking_id',
            'total_price',
            'refunded_at',
            'promocode_id',
            'cancelled_by',
            'cancelled_at',
            'cancel_reason',
            'payment_status',
            'payment_method',
            'refunded_amount',
            'discount_amount',
            'gift_card_amount',
            'payment_breakdown',
            'full_payment_received',
            'loyalty_points_earned',
        ];
    }

}
