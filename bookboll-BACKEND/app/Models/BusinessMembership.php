<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One person's place in one business.
 *
 * Replaces identifying a business by its owner's user id: membership is its own
 * fact here, so a business can be ownerless, change owner, or carry staff whose
 * access is pinned to particular venues.
 */
class BusinessMembership extends Model
{
    public const ROLE_OWNER = 'owner';

    /** Roles the assignment endpoints may grant — owner is not one of them. */
    public const ASSIGNABLE_ROLES = [
        'provider_admin',
        'facility_admin',
        'provider_supervisor',
        'staff',
        'provider_viewer',
    ];

    /** Roles that run the business, as opposed to working in it. */
    public const MANAGING_ROLES = [self::ROLE_OWNER, 'provider_admin'];

    public const STATUS_ACTIVE = 'active';
    public const STATUS_REVOKED = 'revoked';

    protected $fillable = [
        'business_id',
        'user_id',
        'role',
        'scope',
        'location_ids',
        'status',
        'version',
        'created_by',
        'revoked_at',
    ];

    protected $casts = [
        'location_ids' => 'array',
        'version'      => 'integer',
        'revoked_at'   => 'datetime',
    ];

    // Multiple active owners are supported; the business row serializes changes.

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeActive($query)
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    /** Runs the business: configures it, and assigns other people to it. */
    public function manages(): bool
    {
        return $this->isActive() && in_array($this->role, self::MANAGING_ROLES, true);
    }

    /**
     * Venues this membership reaches, or null for the whole business.
     *
     * Null and [] mean different things and must not be conflated: null is "no
     * restriction", while an empty selected list reaches nothing at all.
     *
     * @return array<int, int>|null
     */
    public function allowedLocationIds(): ?array
    {
        if ($this->scope !== 'selected') {
            return null;
        }

        return array_values(array_map('intval', $this->location_ids ?? []));
    }
}
