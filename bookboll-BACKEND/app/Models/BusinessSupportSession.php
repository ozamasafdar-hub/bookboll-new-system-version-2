<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class BusinessSupportSession extends Model
{
    protected $guarded = ['id'];
    protected $casts = ['expires_at'=>'datetime', 'ended_at'=>'datetime'];

    public function details(): array
    {
        return ['session_id'=>$this->id, 'business_id'=>$this->business_id,
            'business_name'=>Business::find($this->business_id)?->name,
            'actor_id'=>$this->actor_id, 'effective_user_id'=>$this->effective_user_id,
            'expires_at'=>$this->expires_at->toIso8601String()];
    }

    public function audit(string $action, array $details = []): void
    {
        AuditLog::create(['action'=>$action, 'resource'=>'business_support_session', 'resource_id'=>(string)$this->id,
            'actor_id'=>$this->actor_id, 'actor_email'=>User::find($this->actor_id)?->email,
            'timestamp'=>now(), 'ip_address'=>request()->ip(),
            'details'=>json_encode(array_merge($this->details(), $details))]);
    }

    public function finish(string $reason): void
    {
        DB::transaction(function () use ($reason) {
            $session = self::lockForUpdate()->find($this->id);
            if (!$session || $session->ended_at) return;
            $session->update(['ended_at'=>now(), 'end_reason'=>$reason]);
            PersonalAccessToken::whereKey($session->token_id)->delete();
            $session->audit('business.support.ended', ['reason'=>$reason]);
        });
    }
}
