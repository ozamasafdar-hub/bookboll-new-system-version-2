<?php

namespace App\Models;


use App\Traits\LogsModelActivity;
use Illuminate\Database\Eloquent\Model;

class CommissionRate extends Model
{
    use LogsModelActivity;

    protected $fillable = [
        'country_id',
        'listing_type',
        'rate_percent',
    ];

    protected static function booted(): void
    {
        static::saved(fn () => app(\App\Support\MarketSettings::class)->clear());
        static::deleted(fn () => app(\App\Support\MarketSettings::class)->clear());
    }

    protected function casts(): array
    {
        return [
            'rate_percent' => 'float',
        ];
    }

    protected function activityLogName(): string
    {
        return 'commission_rate';
    }

    protected function activityLogAttributes(): array
    {
        return [
            'country_id',
            'listing_type',
            'rate_percent',
        ];
    }
}
