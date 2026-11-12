<?php
namespace App\Models;

use App\Traits\LogsModelActivity;
use App\Traits\TracksCreator;

use Illuminate\Database\Eloquent\Model;

class LoyaltyPoint extends Model
{
    use LogsModelActivity, TracksCreator;

    protected $fillable = [
        'created_by',
        'type',
        'points',
        'reason',
        'user_id',
        'booking_id',
        'order_id',
        'country_id',
        'expires_at',
    ];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime'];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public static function balance($user_id)
    {
        $credit = self::where("user_id", $user_id)
            ->where("type", "credit")
            ->sum("points");

        $debit = self::where("user_id", $user_id)
            ->where("type", "debit")
            ->sum("points");

        return $credit - $debit;
    }

    public function scopeType($query, $type)
    {
        return $this->where("type", $type);
    }

    /**
     * Movements that did NOT come from a customer booking on bookboll.com.
     *
     * Loyalty is the main marketplace's reward for a customer booking there
     * themselves. Two other kinds of booking ran the same earn path and should
     * never have credited anything:
     *
     *   staff-entered — the admin dashboard and the admin mobile app write the
     *                   booking as the signed-in staff member (Booking::booted
     *                   fills created_by from auth()->id()), so a creator
     *                   holding any role other than `customer` identifies them.
     *
     *   white-label   — a provider's own storefront at <slug>.bookboll.com,
     *                   recorded as commission_channel 'site'
     *                   (CommissionPolicy::CHANNEL_SITE). Bookboll takes no
     *                   commission there, so it funds no reward either.
     *
     * `bookings.origin` cannot serve any of this: every live row reads
     * 'bookboll_web'. Nor can commission_channel alone — staff-entered bookings
     * are still commercially 'marketplace', so the two tests are independent and
     * both have to be applied.
     *
     * A movement with no booking is NOT matched: its provenance cannot be
     * established, and this scope drives an irreversible delete.
     */
    public function scopeNotMainMarketplace($query)
    {
        return $query->whereNotNull('loyalty_points.booking_id')
            ->whereExists(function ($booking) {
                $booking->selectRaw('1')
                    ->from('bookings')
                    ->whereColumn('bookings.id', 'loyalty_points.booking_id')
                    ->where(function ($q) {
                        $q->where('bookings.commission_channel', \App\Support\CommissionPolicy::CHANNEL_SITE)
                            ->orWhereExists(function ($role) {
                                $role->selectRaw('1')
                                    ->from('model_has_roles')
                                    ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
                                    ->whereColumn('model_has_roles.model_id', 'bookings.created_by')
                                    ->where('model_has_roles.model_type', User::class)
                                    ->where('roles.name', '<>', 'customer');
                            });
                    });
            });
    }
    protected function activityLogName(): string
    {
        return 'loyalty_point';
    }

    protected function activityLogAttributes(): array
    {
        return [
            'type',
            'points',
            'reason',
            'user_id',
            'booking_id',
            'order_id',
        ];
    }

}