<?php
namespace App\Http\Controllers;
use App\Models\{ClassProgram, ProviderMember, ProviderMemberPayment};
use App\Support\MembershipListingPolicy as Policy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Private provider register. Never charges a customer, creates a platform payment or grants public visibility. */
class ProviderMemberController extends Controller {
    private function programs(Request $r) { return Policy::scope(ClassProgram::query(), $r->user()); }
    private function members(Request $r) { return ProviderMember::whereIn('program_id', $this->programs($r)->select('id')); }
    private function member(Request $r, int $id) { return $this->members($r)->findOrFail($id); }
    private function audit(Request $r, ProviderMember $m, string $action, array $details=[]): void {
        DB::table('provider_member_activity')->insert(['member_id'=>$m->id,'actor_id'=>$r->user()->id,'action'=>$action,'details'=>json_encode($details),'created_at'=>now()]);
    }
    private function map(ProviderMember $m): array {
        $paid = (float)($m->received ?? $m->payments()->whereNull('voided_at')->sum('amount'));
        return array_merge($m->toArray(), ['effective_status'=>$m->effectiveStatus(),'received'=>$paid,'balance'=>round((float)$m->agreed_fee-$paid,2),'program_name'=>$m->program?->name,'venue_name'=>$m->program?->venue?->name]);
    }
    public function index(Request $r) {
        $d=$r->validate(['search'=>'nullable|string|max:150','program_id'=>'nullable|integer','status'=>'nullable|in:active,paused,cancelled,upcoming,expired','page'=>'nullable|integer|min:1']);
        $q=$this->members($r)->with('program.venue')->withSum(['payments as received'=>fn($p)=>$p->whereNull('voided_at')],'amount')->withCount('attendance');
        if (!empty($d['program_id'])) $q->where('program_id',$d['program_id']);
        if (!empty($d['search'])) $q->where(fn($w)=>$w->where('name','like','%'.$d['search'].'%')->orWhere('phone','like','%'.$d['search'].'%')->orWhere('email','like','%'.$d['search'].'%'));
        $today=now()->toDateString();
        if ($s=$d['status']??null) {
            if (in_array($s,['paused','cancelled'])) $q->where('status',$s);
            else { $q->where('status','active');
                if ($s==='upcoming') $q->where('starts_on','>',$today);
                elseif ($s==='expired') $q->where('starts_on','<=',$today)->where('ends_on','<',$today);
                else $q->where('starts_on','<=',$today)->where(fn($w)=>$w->whereNull('ends_on')->orWhere('ends_on','>=',$today));
            }
        }
        $page=$q->latest('id')->paginate(30);
        return sendSuccess('Member register loaded',['members'=>$page->getCollection()->map(fn($m)=>$this->map($m)), 'total'=>$page->total(),'page'=>$page->currentPage(),'last_page'=>$page->lastPage(),
            'programs'=>$this->programs($r)->with('venue')->orderBy('name')->get()->map(fn($p)=>['id'=>$p->id,'name'=>$p->name,'venue_name'=>$p->venue?->name,'currency'=>$p->currency ?: 'QAR'])]);
    }
    public function show(Request $r, int $id) {
        $m=$this->member($r,$id); $m->load('program.venue');
        return sendSuccess('Member loaded',['member'=>$this->map($m),'attendance'=>$m->attendance()->orderByDesc('attended_on')->orderByDesc('id')->get(), 'payments'=>$m->payments()->latest('id')->get(),
            'activity'=>DB::table('provider_member_activity as a')->leftJoin('users as u','u.id','=','a.actor_id')->where('a.member_id',$id)->orderByDesc('a.id')->select('a.*','u.name as actor_name')->get()]);
    }
    private function validated(Request $r): array {
        $d=$r->validate(['program_id'=>'required|integer','name'=>'required|string|max:150','phone'=>'nullable|string|max:40','email'=>'nullable|email|max:190','guardian_name'=>'nullable|string|max:150','emergency_phone'=>'nullable|string|max:40','starts_on'=>'required|date_format:Y-m-d','ends_on'=>'nullable|date_format:Y-m-d|after_or_equal:starts_on','notes'=>'nullable|string|max:3000','agreed_fee'=>'required|numeric|min:0|max:1000000','currency'=>'required|regex:/^[A-Z]{3}$/']);
        $this->programs($r)->findOrFail($d['program_id']);
        return $d;
    }
    public function store(Request $r) {
        $d=$this->validated($r);
        return DB::transaction(function() use($r,$d){$m=ProviderMember::create($d+['status'=>'active','created_by'=>$r->user()->id]);$this->audit($r,$m,'Member added');return sendSuccess('Member added',['member'=>$this->map($m)]);});
    }
    public function update(Request $r, int $id) {
        $d=$this->validated($r);
        return DB::transaction(function() use($r,$id,$d){
            $m=$this->members($r)->lockForUpdate()->findOrFail($id);
            abort_if((int)$d['program_id']!==$m->program_id,422,'Add a separate membership to change programmes.');
            abort_if($d['currency']!==$m->currency && $m->payments()->exists(),422,'Currency cannot change after recording a payment.');
            $before=$m->only(array_keys($d)); $m->update($d); $this->audit($r,$m,'Details updated',['before'=>$before,'after'=>$d]);
            return sendSuccess('Member updated',['member'=>$this->map($m)]);
        });
    }
    public function state(Request $r, int $id) {
        $d=$r->validate(['status'=>'required|in:active,paused,cancelled','reason'=>'required|string|max:500']);
        return DB::transaction(function() use($r,$id,$d){$m=$this->members($r)->lockForUpdate()->findOrFail($id);$old=$m->status;$m->update(['status'=>$d['status']]);$this->audit($r,$m,'Status changed',['from'=>$old,'to'=>$d['status'],'reason'=>$d['reason']]);return sendSuccess('Membership status updated');});
    }
    public function attendance(Request $r, int $id) {
        $d=$r->validate(['attended_on'=>'required|date_format:Y-m-d|before_or_equal:today','session'=>'required|string|max:100','status'=>'required|in:present,absent,excused','note'=>'nullable|string|max:500']);
        return DB::transaction(function() use($r,$id,$d){
            $m=$this->members($r)->lockForUpdate()->findOrFail($id);
            abort_if($d['attended_on']<$m->starts_on || ($m->ends_on && $d['attended_on']>$m->ends_on),422,'Attendance must be within the membership dates.');
            abort_if($d['status']==='present' && $m->status!=='active',422,'Resume this membership before marking the member present.');
            $log=$m->attendance()->updateOrCreate(['attended_on'=>$d['attended_on'],'session'=>trim($d['session'])],array_merge($d,['session'=>trim($d['session']),'recorded_by'=>$r->user()->id]));
            $this->audit($r,$m,'Attendance recorded',$d);return sendSuccess('Attendance saved',['attendance'=>$log]);
        });
    }
    public function payment(Request $r, int $id) {
        $d=$r->validate(['request_id'=>'required|uuid','amount'=>'required|numeric|gt:0|max:1000000','method'=>'required|in:cash,bank_transfer,card_terminal,other','paid_on'=>'required|date_format:Y-m-d|before_or_equal:today','reference'=>'nullable|string|max:150','note'=>'nullable|string|max:500']);
        return DB::transaction(function() use($r,$id,$d){
            $m=$this->members($r)->lockForUpdate()->findOrFail($id);
            $existing=ProviderMemberPayment::where('request_id',$d['request_id'])->first();
            abort_if($existing && $existing->member_id!==$m->id,409,'This payment reference has already been used.');
            abort_if($existing && ((float)$existing->amount!==(float)$d['amount'] || $existing->method!==$d['method'] || $existing->paid_on!==$d['paid_on']),409,'This receipt was already saved with different details. Refresh the member before recording another payment.');
            if (!$existing) { $m->payments()->create($d+['recorded_by'=>$r->user()->id]);$this->audit($r,$m,'Offline payment recorded',$d); }
            return sendSuccess('Offline payment recorded. No money was charged.');
        });
    }
    public function voidPayment(Request $r, int $id, int $payment) {
        $d=$r->validate(['reason'=>'required|string|max:500']);
        return DB::transaction(function() use($r,$id,$payment,$d){
            $m=$this->members($r)->lockForUpdate()->findOrFail($id);$p=$m->payments()->findOrFail($payment);
            if (!$p->voided_at) { $p->update(['voided_at'=>now(),'void_reason'=>$d['reason']]);$this->audit($r,$m,'Offline payment voided',['payment_id'=>$payment,'reason'=>$d['reason']]); }
            return sendSuccess('Record voided. This does not refund any money.');
        });
    }
}
