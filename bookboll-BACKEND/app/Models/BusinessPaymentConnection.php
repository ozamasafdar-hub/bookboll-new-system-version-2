<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class BusinessPaymentConnection extends Model {
    protected $guarded = ['id'];
    protected $casts = ['livemode'=>'boolean','charges_enabled'=>'boolean','payouts_enabled'=>'boolean','transfers_enabled'=>'boolean','requirements'=>'array','verified_at'=>'datetime'];
    public function ready(): bool { return $this->status === 'active' && $this->charges_enabled && $this->payouts_enabled && $this->transfers_enabled; }
}
