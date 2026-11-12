<?php
namespace App\Models;

use App\Traits\LogsModelActivity;
use App\Traits\TracksCreator;

use Illuminate\Database\Eloquent\Model;

class Promocode extends Model
{
    use LogsModelActivity, TracksCreator;

    protected $fillable = [
        'comeback_booking_id', 'starts_at', 'ends_at',
        'created_by',
        'code',
        'status',
        'valid_from',
        'expires_at',
        'used_count',
        'usage_limit',
        'description',
        'discount_type',
        'used_by_users',
        'discount_value',
        'per_user_limit',
        'payment_methods',
        'min_order_amount',
        'max_discount_amount',
        'is_reminder_promocode',
        'is_gift_card_promocode',
        'gift_card_id',
        'recipient',
        'creator_role',
        'owner_user_id',
        'applicable_to',
        'location_id',
        'owner_user_ids',
        'location_ids',
        'listing_ids',
        'program_ids',
        'audience',
        'target_emails',
        'per_customer_limit',
    ];

    protected $casts = [
        'starts_at' => 'datetime', 'ends_at' => 'datetime',
        'valid_from'             => 'date',
        'expires_at'             => 'date',
        'used_by_users'          => 'array',
        'discount_value'         => 'decimal:2',
        'is_reminder_promocode'  => 'boolean',
        'is_gift_card_promocode' => 'boolean',
        'min_order_amount'       => 'decimal:2',
        'max_discount_amount'    => 'decimal:2',
        'owner_user_ids'         => 'array',
        'location_ids'           => 'array',
        'listing_ids'            => 'array',
        'program_ids'            => 'array',
        'target_emails'          => 'array',
        'per_customer_limit'     => 'integer',
    ];

    public function venues()
    {
        return $this->belongsToMany(Venue::class);
    }

    public function listings()
    {
        return $this->belongsToMany(Listing::class);
    }

    public function giftCard()
    {
        return $this->belongsTo(GiftCard::class);
    }

    public function scopeGiftCardPromocodes($query)
    {
        return $query->where('is_gift_card_promocode', true);
    }

    public function users()
    {
        return $this->belongsToMany(User::class, 'promocode_user')->withPivot('usage_count')->withTimestamps();
    }

    public function redemptions()
    {
        return $this->hasMany(PromocodeRedemption::class);
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active')
            ->where(function ($q) {
                $q->whereNull('valid_from')
                    ->orWhere('valid_from', '<=', now());
            })
            ->where(function ($q) {
                $q->whereNull('expires_at')
                    ->orWhereDate('expires_at', '>=', now()->toDateString());
            });
    }

    public function scopeReminderPromocodes($query)
    {
        return $query->where('is_reminder_promocode', true);
    }

    public function scopeNonReminderPromocodes($query)
    {
        return $query->where('is_reminder_promocode', false);
    }

    public function isValidForOrder($orderAmount, $userId = null, $paymentMethod = null, $venueId = null, $listingId = null)
    {
        if ($this->status !== 'active') {
            return false;
        }

        if (($this->starts_at && now()->lt($this->starts_at)) || ($this->ends_at && now()->gte($this->ends_at))) return false;

        if ($this->valid_from && now()->lt($this->valid_from)) {
            return false;
        }

        if ($this->expires_at && now()->gt($this->expires_at->copy()->endOfDay())) {
            return false;
        }

        if ($this->min_order_amount && $orderAmount < $this->min_order_amount) {
            return false;
        }

        if ($this->payment_methods !== 'all') {
            if ($this->payment_methods == 'online_only' && !in_array($paymentMethod, ['online', 'online_payment', 'deposit_remaining_on_arrival'])) {
                return false;
            }
        }

        if (!$venueId && ($this->venues->isNotEmpty() || $this->location_ids || $this->recipient === 'provider')) return false;
        if (!$listingId && ($this->listings->isNotEmpty() || $this->listing_ids)) return false;
        if ($this->location_ids && !in_array((int) $venueId, array_map('intval', $this->location_ids), true)) return false;
        if ($this->listing_ids && !in_array((int) $listingId, array_map('intval', $this->listing_ids), true)) return false;
        $venue = $venueId ? Venue::find($venueId) : null;
        if ($listingId && (int) Listing::whereKey($listingId)->value('venue_id') !== (int) $venueId) return false;
        if ($this->location_id && !$this->location_ids && (int)$venueId !== (int)$this->location_id) return false;
        $providerOwners = $this->owner_user_ids ?: ($this->owner_user_id ? [$this->owner_user_id] : []);
        if ($this->recipient === 'provider' && (!$providerOwners || !in_array((int)$venue?->owner_user_id, array_map('intval',$providerOwners), true))) return false;
        if ($this->owner_user_ids && !in_array((int) $venue?->owner_user_id, array_map('intval', $this->owner_user_ids), true)) return false;
        if ($this->applicable_to && !in_array($this->applicable_to, ['all','facility'], true)) return false;
        $user = $userId ? User::find($userId) : null;
        if ($this->audience === 'specific' && (!$user || !in_array(strtolower($user->email), array_map('strtolower', $this->target_emails ?? []), true))) return false;
        if ($this->audience === 'new' && (!$user || Booking::where('user_id', $userId)->whereNotIn('status', ['cancelled'])->where('is_free', false)->exists())) return false;
        if (!$userId && $this->users->isNotEmpty()) return false;

        if ($venueId && $this->venues->isNotEmpty() && ! $this->venues->contains($venueId)) {
            return false;
        }

        if ($listingId && $this->listings->isNotEmpty() && ! $this->listings->contains($listingId)) {
            return false;
        }

        if ($this->usage_limit && $this->used_count >= $this->usage_limit) {
            return false;
        }

        $perUserLimit = $this->per_user_limit ?: $this->per_customer_limit;
        if ($perUserLimit && $userId) {
            $userUsed = $this->used_by_users[$userId] ?? 0;
            if ($userUsed >= $perUserLimit) {
                return false;
            }
        }

        if ($userId) {
            if (! $this->relationLoaded('users')) {
                $this->load('users');
            }
            $assignedUserIds = $this->users->pluck('id')->toArray();
            if (! empty($assignedUserIds) && ! in_array($userId, $assignedUserIds)) {
                return false;
            }
        }

        return true;
    }

    public function applyDiscount($orderAmount)
    {
        $discount = 0;

        if ($this->discount_type == 'percentage') {
            $discount = ($orderAmount * $this->discount_value) / 100;
        } elseif ($this->discount_type == 'fixed') {
            $discount = $this->discount_value;
        }

        if ($this->max_discount_amount && $discount > $this->max_discount_amount) {
            $discount = $this->max_discount_amount;
        }

        if ($discount > $orderAmount) {
            $discount = $orderAmount;
        }

        return $discount;
    }

    public function incrementUsage($userId = null)
    {
        $this->increment('used_count');

        if ($userId) {
            $usedByUsers          = $this->used_by_users;
            $usedByUsers[$userId] = ($usedByUsers[$userId] ?? 0) + 1;
            $this->used_by_users  = $usedByUsers;
            $this->save();
        }
    }

    public function decrementUsage($userId = null)
    {
        $this->decrement('used_count');

        if ($userId) {
            $usedByUsers = $this->used_by_users;
            if (isset($usedByUsers[$userId]) && $usedByUsers[$userId] > 0) {
                $usedByUsers[$userId]--;
                if ($usedByUsers[$userId] === 0) {
                    unset($usedByUsers[$userId]);
                }
                $this->used_by_users = $usedByUsers;
                $this->save();
            }
        }
    }

    public function getFormattedDiscountAttribute()
    {
        if ($this->discount_type == 'percentage') {
            return $this->discount_value . '%';
        }
        return 'QAR ' . number_format($this->discount_value, 2);
    }

    public function getNeverExpiresAttribute()
    {
        return is_null($this->expires_at);
    }

    public function isAvailableToAllUsers(): bool
    {
        if (! $this->relationLoaded('users')) {
            $this->load('users');
        }
        return $this->users->isEmpty();
    }

    public function isAvailableToUser(int $userId): bool
    {
        if ($this->isAvailableToAllUsers()) {
            return true;
        }

        if (! $this->relationLoaded('users')) {
            $this->load('users');
        }
        return $this->users->contains('id', $userId);
    }
    protected function activityLogName(): string
    {
        return 'promocode';
    }

    protected function activityLogAttributes(): array
    {
        return [
            'code',
            'status',
            'valid_from',
            'expires_at',
            'used_count',
            'usage_limit',
            'description',
            'discount_type',
            'used_by_users',
            'discount_value',
            'per_user_limit',
            'payment_methods',
            'min_order_amount',
            'max_discount_amount',
            'is_reminder_promocode',
            'is_gift_card_promocode',
            'gift_card_id',
            'recipient',
            'creator_role',
            'owner_user_id',
            'applicable_to',
            'audience',
        ];
    }

}
