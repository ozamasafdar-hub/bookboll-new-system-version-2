<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MembershipListingInvoice extends Model
{
    protected $guarded = ['id'];
    protected $casts = ['paid_at' => 'datetime', 'period_start' => 'datetime', 'period_end' => 'datetime'];
    public function subscription() { return $this->belongsTo(MembershipListingSubscription::class, 'listing_subscription_id'); }
}
