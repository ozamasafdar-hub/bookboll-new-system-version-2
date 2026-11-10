<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PaymentRefund extends Model
{
    protected $guarded = ['id'];
    protected $casts = ['payment_ids'=>'array', 'before'=>'array', 'processor'=>'array', 'synced_at'=>'datetime'];
}
