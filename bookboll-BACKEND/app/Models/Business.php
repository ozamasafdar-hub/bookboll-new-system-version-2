<?php

namespace App\Models;

use App\Traits\LogsModelActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Nnjeim\World\Models\Country;

class Business extends Model
{
    use LogsModelActivity;

    public const STATUS_ACTIVE    = 'active';
    public const STATUS_PENDING   = 'pending';
    public const STATUS_SUSPENDED = 'suspended';

    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_ACTIVE,
        self::STATUS_SUSPENDED,
    ];

    public const BUSINESS_TYPE_KEYS = [
        'sports_facility',
        'gym',
        'academy',
        'classes',
        'other_venue',
    ];

    protected $fillable = [
        'name',
        'phone',
        'description',
        'status',
        'owner_id',
        'username',
        'country_id',
        'brand_name',
        'brand_accent',
        'brand_primary',
        'brand_tagline',
        'brand_enabled',
        'brand_logo_url',
        'business_types',
        'brand_subdomain',
        'brand_meta_title',
        'brand_meta_description',
        'notification_templates',
        'whatsapp_config',
        'smtp_config',
        'hero_banners',
        'sort_space',
        'sort_venue',
        'site_pages',
        'brand_languages',
        'tracking_meta_pixel',
        'tracking_ga4',
        'tracking_gtm',
        'tracking_head',
        'brand_whitelabel',
        'plan',
        'premium_since',
        'brand_show_venues',
        'brand_show_events',
        'brand_show_programs',
        'brand_custom_domain',
        'brand_domain_status',
        'brand_domain_paid_at',
        'created_by',
        'idempotency_key',
    ];

    protected function casts(): array
    {
        return [
            'business_types'          => 'array',
            'brand_enabled'           => 'boolean',
            'notification_templates'  => 'array',
            'whatsapp_config'         => 'array',
            'smtp_config'             => 'array',
            'hero_banners'            => 'array',
            'site_pages'              => 'array',
            'brand_languages'         => 'array',
            'brand_whitelabel'        => 'boolean',
            'premium_since'           => 'datetime',
            'brand_show_venues'       => 'boolean',
            'brand_show_events'       => 'boolean',
            'brand_show_programs'     => 'boolean',
            'brand_domain_paid_at'    => 'datetime',
        ];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /**
     * The business an owner-side user id stands for.
     *
     * Replaces `Business::where('owner_id', $ownerId)`: that only finds a
     * business registered by its owner. One created from the admin "Add
     * business" form has owner_id null and its owner attached by membership,
     * so the owner-keyed lookup returned nothing for the owner AND for every
     * manager invited under them — My Website, storefront sort, WhatsApp and
     * the plan page all answered "No business found".
     */
    public static function forOwnerId(?int $ownerId): ?self
    {
        $id = \App\Support\BusinessMembershipSync::businessIdForOwner($ownerId);

        return $id ? static::find($id) : null;
    }

    /** Every Premium subscription this business has ever had, newest first. */
    /**
     * Everyone who belongs to this business. Membership is keyed by the business
     * id, so these survive an owner change and exist even when `owner_id` is
     * null — an unclaimed business can still have staff.
     */
    /**
     * Whether this deployment can hold a business with no owner account.
     *
     * Checked against the live schema rather than assumed: the create-without-
     * owner capability is advertised from this, and advertising it on a database
     * where owner_id is still NOT NULL would strand a half-filled form with a
     * constraint violation.
     */
    public static function ownerlessSupported(): bool
    {
        try {
            $column = collect(\Illuminate\Support\Facades\Schema::getColumns('businesses'))
                ->firstWhere('name', 'owner_id');

            return (bool) ($column['nullable'] ?? false);
        } catch (\Throwable $e) {
            // Cannot prove it, so do not promise it.
            return false;
        }
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(BusinessMembership::class);
    }

    public function activeMemberships(): HasMany
    {
        return $this->memberships()->where('status', BusinessMembership::STATUS_ACTIVE);
    }

    /** The owner membership, which is the authoritative owner going forward. */
    public function ownerMembership(): HasOne
    {
        return $this->hasOne(BusinessMembership::class)
            ->where('role', BusinessMembership::ROLE_OWNER)
            ->where('status', BusinessMembership::STATUS_ACTIVE);
    }

    /** A business nobody has claimed yet — valid, and not Bookboll's own. */
    public function isOwnerless(): bool
    {
        return $this->owner_id === null;
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(BusinessSubscription::class)->latest('id');
    }

    /**
     * The subscription that governs the plan right now — the newest one that still
     * entitles the business, or simply the newest if none does (so a cancelled or
     * abandoned one can still be shown on the billing page).
     */
    public function currentSubscription(): HasOne
    {
        return $this->hasOne(BusinessSubscription::class)->latestOfMany();
    }

    public function activeSubscription(): HasOne
    {
        return $this->hasOne(BusinessSubscription::class)
            ->whereIn('status', BusinessSubscription::ENTITLING)
            ->latestOfMany();
    }

    /** Custom-domain fee payments, newest first. */
    public function domainPurchases(): HasMany
    {
        return $this->hasMany(BusinessDomainPurchase::class)->latest('id');
    }

    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class, 'country_id');
    }

    public function scopePending($query)
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    public function scopeActive($query)
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    protected function activityLogName(): string
    {
        return 'business';
    }

    protected function activityLogAttributes(): array
    {
        return [
            'owner_id',
            'name',
            'username',
            'business_types',
            'country_id',
            'status',
            'brand_enabled',
            'brand_subdomain',
            'brand_name',
            'brand_tagline',
            'brand_logo_url',
            'brand_primary',
            'brand_accent',
            'brand_meta_title',
            'brand_meta_description',
            'notification_templates',
        ];
    }
}
