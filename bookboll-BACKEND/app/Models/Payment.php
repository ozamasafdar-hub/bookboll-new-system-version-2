<?php
namespace App\Models;

use App\Traits\LogsModelActivity;
use App\Traits\TracksCreator;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payment extends Model
{
    use LogsModelActivity, TracksCreator;

    protected $fillable = [
        'created_by',
        'type',
        'date',
        'notes',
        'amount',
        'currency',
        'recipient',
        'method',
        'status',
        'user_id',
        'charge_id',
        'payment_id',
        'booking_id',
        'source_type',
        'subscription_id',
        'paid_at',
        'marked_paid_by',
        'payment_intent_id',
        'stripe_invoice_url',
        'stripe_receipt_url',
        'financial_context',
    ];

    protected $casts = [
        'financial_context' => 'array',
        'amount'  => 'decimal:2',
        'paid_at' => 'datetime',
        'date'    => 'date',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function booking()
    {
        return $this->belongsTo(Booking::class);
    }

    public function subscription()
    {
        return $this->belongsTo(ClassSubscription::class, 'subscription_id');
    }

    public function ownedAllocations()
    {
        return $this->hasMany(PaymentAllocation::class);
    }

    public function markedBy()
    {
        return $this->belongsTo(User::class, 'marked_paid_by');
    }

    public function scopeMethod($query, $method)
    {
        return $query->when($method, fn($q) => $q->where('method', $method));
    }

    public function scopeStatus($query, $status)
    {
        return $query->when($status, fn($q) => $q->where('status', $status));
    }

    public function scopeDateRange($query, $from, $to)
    {
        return $query->when($from, fn($q) => $q->where('date', '>=', $from))
            ->when($to, fn($q) => $q->where('date', '<=', $to));
    }
    protected function activityLogName(): string
    {
        return 'payment';
    }

    protected function activityLogAttributes(): array
    {
        return [
            'type',
            'date',
            'notes',
            'amount',
            'currency',
            'recipient',
            'method',
            'status',
            'user_id',
            'charge_id',
            'payment_id',
            'booking_id',
            'source_type',
            'subscription_id',
            'paid_at',
            'marked_paid_by',
            'payment_intent_id',
            'collection_method',
            'collected_by_business_id',
            'reconciliation_batch_id',
        ];
    }

}
