<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Traits\LogsModelActivity;

class MembershipListingSubscription extends Model
{
    use LogsModelActivity;
    protected $guarded = ['id'];
    protected $casts = ['paid_until' => 'datetime', 'current_period_end' => 'datetime', 'cancel_at_period_end' => 'boolean', 'amount' => 'decimal:2'];
    public function program() { return $this->belongsTo(ClassProgram::class, 'program_id'); }
    public function invoices() { return $this->hasMany(MembershipListingInvoice::class, 'listing_subscription_id'); }
    protected function activityLogName(): string { return 'membership_listing_subscription'; }
    protected function activityLogAttributes(): array { return ['program_id', 'business_id', 'payer_id', 'amount', 'currency', 'status', 'paid_until', 'cancel_at_period_end']; }
}
