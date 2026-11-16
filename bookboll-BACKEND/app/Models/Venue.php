<?php

namespace App\Models;

use App\Traits\LogsModelActivity;
use App\Traits\TracksCreator;
use Illuminate\Database\Eloquent\Model;
use Nnjeim\World\Models\City;
use Nnjeim\World\Models\Country;
use Nnjeim\World\Models\State;

/**
 * Venue (= Supabase / Postgres `locations`).
 *
 * Column mapping from Postgres `public.locations`:
 *   google_maps_url  → maps_url
 *   city_id (uuid)   → city_id (world package bigint) + country_id / state_id
 *   photos / previous_snapshot (jsonb) → JSON columns
 *
 * Laravel-only fields kept for the existing admin / booking APIs:
 *   state_id, country_id, min_advance_booking, max_bookings_per_slot, created_by
 */
class Venue extends Model
{
    use LogsModelActivity, TracksCreator;

    public const ACTIVE   = 'active';
    public const INACTIVE = 'inactive';

    public const APPROVAL_PENDING  = 'pending';
    public const APPROVAL_APPROVED = 'approved';
    public const APPROVAL_REJECTED = 'rejected';

    public const ELIGIBILITY_ELIGIBLE   = 'eligible';
    public const ELIGIBILITY_INELIGIBLE = 'ineligible';
    /** Dashboard UI pause state (Listings eligibility toggle). */
    public const ELIGIBILITY_PAUSED     = 'paused';

    protected $fillable = [
        // Core (shared with Postgres locations)
        'name',
        'status',
        'address',
        'phone',
        'description',
        'photo_url',
        'photos',
        'approval_status',
        'eligibility_status',
        'admin_note',
        'previous_snapshot',
        'owner_user_id',
        'latitude',
        'longitude',
        'timezone_override',
        'currency_override',
        'maps_url', // Postgres: google_maps_url
        'submitted_at',
        'submitted_by_name',
        'sort_order',

        // Geo (Laravel world package; Postgres only had city_id uuid)
        'country_id',
        'state_id',
        'city_id',

        // Existing Laravel booking / admin fields
        'min_advance_booking',
        'max_bookings_per_slot',
        'created_by',
    ];

    protected $casts = [
        'photos'             => 'array',
        'previous_snapshot'  => 'array',
        'latitude'           => 'decimal:7',
        'longitude'          => 'decimal:7',
        'submitted_at'       => 'datetime',
        'sort_order'         => 'integer',
        'min_advance_booking'=> 'integer',
        'max_bookings_per_slot' => 'integer',
    ];

    protected $appends = ['location_label', 'google_maps_url'];

    /**
     * Venues that belong to the business an owner-side user id stands for.
     *
     * A venue reaches its business two ways: the legacy owner key, and
     * venues.business_id — the ONLY link for a business created ownerless
     * (owner_id null). Filtering on owner_user_id alone returned nothing for
     * such a business, so its owner and every manager under them opened an
     * empty Listings page while Bookings (scoped through AccessScope, which
     * reads both) still showed their bookings.
     */
    public function scopeOwnedBy($query, int $ownerId)
    {
        $businessId = \App\Support\BusinessMembershipSync::businessIdForOwner($ownerId);

        return $query->where(function ($q) use ($ownerId, $businessId) {
            $q->where('owner_user_id', $ownerId);
            if ($businessId && schemaHas('venues', 'business_id')) {
                $q->orWhere('business_id', $businessId);
            }
        });
    }

    public function scopeActive($query)
    {
        return $query->where('status', self::ACTIVE);
    }

    public function scopeApproved($query)
    {
        return $query->where('approval_status', self::APPROVAL_APPROVED);
    }

    public function country()
    {
        return $this->belongsTo(Country::class);
    }

    public function state()
    {
        return $this->belongsTo(State::class);
    }

    public function city()
    {
        return $this->belongsTo(City::class);
    }

    /** Provider / facility owner (Postgres locations.owner_user_id). */
    public function owner()
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function getLocationLabelAttribute(): ?string
    {
        $parts = array_filter([
            $this->city?->name,
            $this->state?->name,
            $this->country?->name,
        ]);

        return $parts ? implode(', ', $parts) : null;
    }

    /** Alias for dashboard UI that still speaks `google_maps_url`. */
    public function getGoogleMapsUrlAttribute(): ?string
    {
        return $this->maps_url;
    }

    public function setGoogleMapsUrlAttribute(?string $value): void
    {
        $this->attributes['maps_url'] = $value;
    }

    public function schedules()
    {
        return $this->hasMany(Schedule::class)->whereNull('listing_id');
    }

    public function users()
    {
        return $this->belongsToMany(User::class);
    }

    public function listings()
    {
        return $this->hasMany(Listing::class);
    }

    protected function activityLogName(): string
    {
        return 'venue';
    }

    protected function activityLogAttributes(): array
    {
        return [
            'name',
            'status',
            'address',
            'phone',
            'description',
            'photo_url',
            'approval_status',
            'eligibility_status',
            'admin_note',
            'owner_user_id',
            'business_id',
            'operator_type',
            'latitude',
            'longitude',
            'timezone_override',
            'currency_override',
            'maps_url',
            'city_id',
            'state_id',
            'country_id',
            'sort_order',
            'min_advance_booking',
            'max_bookings_per_slot',
            'submitted_at',
            'submitted_by_name',
        ];
    }
}
