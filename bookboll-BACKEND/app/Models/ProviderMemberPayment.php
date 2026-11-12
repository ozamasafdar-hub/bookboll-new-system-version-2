<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class ProviderMemberPayment extends Model {
    protected $guarded = ['id'];
    protected $casts = ['amount'=>'decimal:2','voided_at'=>'datetime'];
}
