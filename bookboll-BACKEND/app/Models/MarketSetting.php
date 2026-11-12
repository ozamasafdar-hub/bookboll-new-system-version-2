<?php

namespace App\Models;

use App\Traits\LogsModelActivity;
use Illuminate\Database\Eloquent\Model;

class MarketSetting extends Model
{
    use LogsModelActivity;

    protected $fillable = ['scope', 'scope_id', 'values'];
    protected $casts = ['values' => 'array', 'scope_id' => 'integer'];
    protected function activityLogName(): string { return 'market_setting'; }
    protected function activityLogAttributes(): array { return ['scope', 'scope_id', 'values']; }
}
