<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class ProviderMember extends Model {
    protected $guarded = ['id'];
    protected $casts = ['agreed_fee'=>'decimal:2'];
    public function program() { return $this->belongsTo(ClassProgram::class, 'program_id'); }
    public function attendance() { return $this->hasMany(ProviderMemberAttendance::class, 'member_id'); }
    public function payments() { return $this->hasMany(ProviderMemberPayment::class, 'member_id'); }
    public function effectiveStatus(): string {
        if ($this->status !== 'active') return $this->status;
        if ($this->starts_on > now()->toDateString()) return 'upcoming';
        return $this->ends_on && $this->ends_on < now()->toDateString() ? 'expired' : 'active';
    }
}
