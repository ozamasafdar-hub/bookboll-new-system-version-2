<?php

namespace App\Models;

use App\Traits\LogsModelActivity;

use Illuminate\Database\Eloquent\Model;

class StripeSession extends Model
{
    use LogsModelActivity;

    protected $fillable = [
        'settlement',
        'session_id',
        'booking_id',
        'booking_ids',
        'source',
        'processed',
    ];

    protected $casts = [
        'settlement' => 'array',
        'processed'   => 'boolean',
        'booking_ids' => 'array',
    ];
    protected function activityLogName(): string
    {
        return 'stripe_session';
    }

    protected function activityLogAttributes(): array
    {
        return [
            'session_id',
            'booking_id',
            'booking_ids',
            'source',
            'processed',
        ];
    }

}
