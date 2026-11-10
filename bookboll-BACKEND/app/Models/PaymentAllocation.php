<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Internal business-unit allocation, never an additional receipt or payout. */
class PaymentAllocation extends Model
{
    protected $fillable = ['payment_id','bucket','amount_minor','currency','fee_rate','basis'];
    protected $casts = ['amount_minor'=>'integer', 'fee_rate'=>'float'];
}
