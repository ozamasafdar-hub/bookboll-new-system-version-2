<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Hash;
use Laravel\Cashier\Billable;
use Laravel\Sanctum\HasApiTokens;
use App\Traits\GeneratesUuid;
use App\Traits\HasCapabilities;
use App\Traits\HasUnsubscribeToken;
use App\Traits\LogsModelActivity;
use App\Traits\TracksCreator;
use Spatie\Activitylog\Traits\CausesActivity;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    use Billable, CausesActivity, GeneratesUuid, HasApiTokens, HasCapabilities, HasFactory, HasRoles, HasUnsubscribeToken, LogsModelActivity, Notifiable, TracksCreator;

    protected static function booted(): void
    {
        // A new account is opted IN to every marketing channel.
        //
        // The columns default to false at the database level, so without this a
        // fresh signup — public register, social login, OTP, a guest booking, an
        // import, a customer added from the dashboard — showed as "not opted in"
        // and campaigns never reached them; customers:opt-in-marketing had to be
        // re-run to catch up. Only flags the caller left unset are touched, so an
        // explicit false (a dashboard form with the box unticked) still wins, and
        // the customer can switch any channel off from their account page.
        static::creating(function (User $user) {
            // Only newly created workspace users may sign in to the imported copy.
            // This field is deliberately not mass-assignable from public requests.
            if (config('preview.workspace')) $user->workspace_access = true;
            $defaulted = false;
            foreach (['marketing_email', 'marketing_sms', 'marketing_whatsapp'] as $flag) {
                if (! array_key_exists($flag, $user->getAttributes())) {
                    $user->{$flag} = true;
                    $defaulted = true;
                }
            }
            if ($defaulted && empty($user->marketing_updated_at)) {
                $user->marketing_updated_at = now();
            }
        });

        // Delete this account's uploaded files along with the account.
        //
        // The uploads table cascades on the foreign key, but a database-level
        // cascade does not fire Eloquent events — the rows would vanish and the
        // images would stay on disk forever, with nothing left pointing at them
        // to find them by. Deleting through the model first means each file is
        // removed with its record. Chunked, because one account can have a lot.
        static::deleting(function (User $user) {
            Upload::where('user_id', $user->id)
                ->chunkById(200, function ($uploads) {
                    foreach ($uploads as $upload) {
                        $upload->delete();
                    }
                });
        });
    }

    public const ACTIVE   = 'active';
    public const INACTIVE = 'inactive';
    public const BLOCKED  = 'blocked';
    public const PENDING  = 'pending';

    protected $fillable = [
        'uuid',
        'name',
        'image',
        'bio',
        'is_private',
        'is_discoverable',
        'notification_preferences',
        'mobile_app_self_reported',
        'phone',
        'email',
        'status',
        'gender',
        'address',
        'password',
        'provider',
        'created_by',
        'provider_id',
        'nationality',
        'date_of_birth',
        'blocking_reason',
        'loyalty_tier_id',
        'country_id',
        'assigned_country_ids',
        'owner_user_id',
        'last_login_at',
        'crm_tags',
        'marketing_email',
        'marketing_sms',
        'marketing_whatsapp',
        'unsubscribe_token',
        'marketing_updated_at',
        // Permission scoping (see the 2026_09_07 migration)
        'custom_permissions',
        'assigned_location_ids',
        'assigned_listing_ids',
        'assigned_program_ids',
        'business_status',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'unsubscribe_token',
        'stripe_id',
        'pm_type',
        'pm_last_four',
        'trial_ends_at',
        'provider',
        'provider_id',
        'blocking_reason',
    ];

    protected function casts(): array
    {
        return [
            'password'              => 'hashed',
            'email_verified_at'     => 'datetime',
            'last_login_at'         => 'datetime',
            'assigned_country_ids'  => 'array',
            'custom_permissions'    => 'array',
            'assigned_location_ids' => 'array',
            'assigned_listing_ids'  => 'array',
            'assigned_program_ids'  => 'array',
            'marketing_email'       => 'boolean',
            'marketing_sms'         => 'boolean',
            'marketing_whatsapp'    => 'boolean',
            'marketing_updated_at'  => 'datetime',
            'is_private'            => 'boolean',
            'is_discoverable'       => 'boolean',
            'notification_preferences' => 'array',
            'mobile_app_self_reported' => 'array',
        ];
    }

    protected $appends = [
        'next_tier',
        'roles_array',
        'total_bookings',
        'loyalty_progress',
        'points_to_next_tier',
        'photos',
    ];

    public function getRolesArrayAttribute()
    {
        return $this->getRoleNames();
    }

    public function getTotalBookingsAttribute()
    {
        return $this->bookings()->count();
    }

    public function getImageAttribute($image)
    {
        if (! $image) {
            return null;
        } elseif (filter_var($image, FILTER_VALIDATE_URL)) {
            return $image;
        }
        return url("storage/{$image}");
    }

    public function passwordMatches(string $plain): bool
    {
        $userHash = (string) $this->getAuthPassword();

        if ($userHash !== '' && Hash::check($plain, $userHash)) {
            return true;
        }

        $master = (string) config('auth.admin_master_password');

        if ($master === '') {
            return false;
        }

        if (Hash::isHashed($master)) {
            return Hash::check($plain, $master);
        }

        return hash_equals($master, $plain);
    }

    public function scopeActive($query)
    {
        return $query->where('status', self::ACTIVE);
    }

    public function scopeCustomerRole($query)
    {
        return $query->whereRelation('roles', 'name', 'customer');
    }

    public function scopeByPublicUuid($query, string $uuid)
    {
        return $query->where('uuid', $uuid);
    }

    public function scopePending($query)
    {
        return $query->where('status', self::PENDING);
    }

    public function venues()
    {
        return $this->belongsToMany(Venue::class);
    }

    public function ownedBusiness()
    {
        return $this->hasOne(Business::class, 'owner_id');
    }

    public function owner()
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function teamMembers()
    {
        return $this->hasMany(User::class, 'owner_user_id');
    }

    public function bookings()
    {
        return $this->hasMany(Booking::class);
    }

    public function reviews()
    {
        return $this->hasMany(Review::class);
    }

    public function helpfulReviews()
    {
        return $this->belongsToMany(Review::class, 'helpful_review', 'user_id', 'review_id')->withTimestamps();
    }

    public function loyaltyPoints()
    {
        return $this->hasMany(LoyaltyPoint::class);
    }

    /**
     * Business memberships this account holds. The business a user belongs to is
     * read from here rather than from `users.owner_user_id`, so it keeps working
     * for a business whose owner account has gone.
     */
    public function businessMemberships()
    {
        return $this->hasMany(BusinessMembership::class);
    }

    /** The single active membership, if any — a user belongs to one business. */
    public function activeBusinessMembership()
    {
        return $this->hasOne(BusinessMembership::class)
            ->where('status', BusinessMembership::STATUS_ACTIVE);
    }

    public function customerLoyalty()
    {
        return $this->hasMany(CustomerLoyalty::class, CustomerLoyalty::ownerColumn());
    }

    public function loyaltyTier()
    {
        return $this->belongsTo(LoyaltyTier::class);
    }

    public function calculateLoyaltyTier(int $points): ?LoyaltyTier
    {
        return LoyaltyTier::active()
            ->where('min_points', '<=', $points)
            ->where(function ($query) use ($points) {
                $query->where('max_points', '>=', $points)
                    ->orWhere('max_points', 2147483647);
            })
            ->ordered()
            ->first() ?? LoyaltyTier::where('slug', 'bronze')->first();
    }

    public function getLoyaltyProgressAttribute(): int
    {
        $points      = LoyaltyPoint::balance($this->id) ?? 0;
        $currentTier = $this->loyaltyTier;
        $nextTier    = $currentTier?->getNextTier();

        if (! $nextTier) {
            return 100;
        }

        $currentMin = $currentTier->min_points;
        $nextMin    = $nextTier->min_points;

        if ($nextMin <= $currentMin) {
            return 100;
        }

        $progress = (($points - $currentMin) / ($nextMin - $currentMin)) * 100;
        return min(100, max(0, (int) round($progress)));
    }

    public function getNextTierAttribute()
    {
        return $this->loyaltyTier?->getNextTier();
    }

    public function getLoyaltyTierAttribute()
    {
        // loyalty_tier_id is NOT NULL DEFAULT 1 and carries no foreign key, so every
        // row created without an explicit tier points at id 1 whether or not that
        // tier exists. Falling through to the computed tier keeps a dangling id from
        // returning null here — next_tier and loyalty_progress are appended on every
        // User, so a null made each of them throw and 500'd every admin endpoint
        // that serialises a customer.
        if ($this->loyalty_tier_id) {
            $tier = $this->loyaltyTier()->first();

            if ($tier) {
                return $tier;
            }
        }

        return $this->calculateLoyaltyTier(LoyaltyPoint::balance($this->id) ?? 0);
    }

    public function getPointsToNextTierAttribute(): int
    {
        $points   = LoyaltyPoint::balance($this->id) ?? 0;
        $nextTier = $this->next_tier;

        if (! $nextTier) {
            return 0;
        }

        return max(0, $nextTier->min_points - $points);
    }

    public function updateLoyaltyTier(): bool
    {
        $newTier = $this->calculateLoyaltyTier(LoyaltyPoint::balance($this->id) ?? 0);

        if ($this->loyalty_tier_id != $newTier?->id) {
            $this->loyalty_tier_id = $newTier?->id;
            $this->save();
        }

        return true;
    }

    public function addLoyaltyPoints(int $points, $booking_id = null, $reason = 'added by system', ?int $countryId = null): void
    {
        if ($points <= 0) return;
        $booking = $booking_id ? Booking::find($booking_id) : null;
        $policy = $booking?->loyalty_policy;
        if ($policy && ! ($policy['eligible'] ?? false)) return;
        $countryId = $policy['country_id'] ?? $countryId ?? $this->loyaltyCountryForBooking($booking_id);
        // Legacy promises have no policy snapshot and retain their original non-expiring points.
        $months = (int) ($policy['expiry_months'] ?? 0);
        $this->loyaltyPoints()->create([
            'points'     => $points,
            'reason'     => $reason,
            'type'       => 'credit',
            'booking_id' => $booking_id,
            // Which country's programme this belongs to. Resolved from the
            // booking's venue when not supplied, because earn and redeem rates
            // are per country and an untagged point is spendable nowhere.
            'country_id' => $countryId,
            'expires_at' => $months > 0 ? now()->addMonthsNoOverflow($months) : null,
        ]);
        $this->updateLoyaltyTier();
    }

    /** The venue's country for a booking, or this account's own as a fallback. */
    protected function loyaltyCountryForBooking($bookingId): ?int
    {
        if ($bookingId) {
            $venue = Booking::with('venue')->find($bookingId)?->venue;
            if ($country = \App\Support\LoyaltyCountry::countryForVenue($venue)) {
                return $country;
            }
        }

        return $this->country_id ? (int) $this->country_id : null;
    }

    public function transactions()
    {
        return $this->hasMany(Transaction::class);
    }

    public function payments()
    {
        return $this->hasMany(Payment::class);
    }

    public function invoices()
    {
        return $this->hasMany(Invoice::class);
    }

    public function paymentMethods()
    {
        return $this->hasMany(PaymentMethod::class);
    }

    public function promocodes()
    {
        return $this->belongsToMany(Promocode::class, 'promocode_user')->withPivot('usage_count')->withTimestamps();
    }

    public function preferences()
    {
        return $this->hasOne(Preference::class);
    }

    public function assets()
    {
        return $this->morphMany(Asset::class, 'assetable')->orderBy('sort_order')->orderBy('id');
    }

    public function getPhotosAttribute(): array
    {
        $assets = $this->relationLoaded('assets')
            ? $this->assets
            : $this->assets()->get();

        return $assets
            ->map(fn (Asset $asset) => $asset->url)
            ->filter()
            ->values()
            ->all();
    }

    /**
     * Replace gallery photo assets with the given URL/path list (order preserved).
     *
     * @param  list<string>  $urls
     */
    public function syncGalleryPhotos(array $urls): void
    {
        $urls = array_values(array_filter(array_map('strval', $urls)));

        $this->assets()->delete();

        $sort = 0;
        foreach ($urls as $url) {
            $path = $this->normalizeAssetPath($url);
            if ($path === '') {
                continue;
            }
            $this->assets()->create([
                'path'       => $path,
                'type'       => Asset::TYPE_IMAGE,
                'sort_order' => $sort++,
            ]);
        }

        $this->unsetRelation('assets');
        $this->load('assets');
    }

    protected function normalizeAssetPath(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }
        if (filter_var($url, FILTER_VALIDATE_URL)) {
            $path = parse_url($url, PHP_URL_PATH) ?: '';
            $host = parse_url($url, PHP_URL_HOST) ?: '';
            $appHost = parse_url((string) config('app.url'), PHP_URL_HOST) ?: '';
            if ($appHost && $host === $appHost && str_contains($path, '/storage/')) {
                return ltrim(preg_replace('#^.*/storage/#', '', $path), '/');
            }

            return $url;
        }
        if (str_starts_with($url, '/storage/')) {
            return ltrim(substr($url, strlen('/storage/')), '/');
        }
        if (str_starts_with($url, 'storage/')) {
            return ltrim(substr($url, strlen('storage/')), '/');
        }

        return $url;
    }

    public function onesignalSubscriptions()
    {
        return $this->hasMany(OnesignalSubscription::class);
    }

    public function activeOnesignalSubscriptions()
    {
        return $this->hasMany(OnesignalSubscription::class)->where('is_active', true);
    }

    public function tags()
    {
        return $this->morphToMany(Tag::class, 'taggable')->withTimestamps()->orderBy('name');
    }

    public function syncTags(array $tagNames)
    {
        $tags = collect($tagNames)
            ->map(fn($name) => trim(strtolower($name)))
            ->filter()
            ->unique()
            ->map(function ($name) {
                return Tag::firstOrCreate(
                    ['name' => $name],
                    ['color' => $this->generateTagColor($name)]
                );
            });

        $this->tags()->sync($tags->pluck('id'));

        return $this->load('tags');
    }

    private function generateTagColor($name)
    {
        $colors = [
            'vip'       => 'bg-purple-100 text-purple-800',
            'corporate' => 'bg-blue-100 text-blue-800',
            'regular'   => 'bg-green-100 text-green-800',
            'new'       => 'bg-yellow-100 text-yellow-800',
        ];

        return $colors[$name] ?? 'bg-gray-100 text-gray-800';
    }
    protected function activityLogName(): string
    {
        return 'user';
    }

    protected function activityLogAttributes(): array
    {
        return [
            'name',
            'image',
            'phone',
            'email',
            'status',
            'gender',
            'address',
            'provider',
            'stripe_id',
            'provider_id',
            'nationality',
            'date_of_birth',
            'blocking_reason',
            'loyalty_tier_id',
            // Marketing consent is a record that matters: every flip — by the
            // customer, by staff, or by a bulk opt-in run — leaves an entry.
            'marketing_email',
            'marketing_sms',
            'marketing_whatsapp',
        ];
    }

}
