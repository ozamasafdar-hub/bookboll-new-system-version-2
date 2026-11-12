<?php
namespace App\Services;

use App\Models\{Business, BusinessPaymentConnection, Booking, User, Venue};
use App\Support\{AccessScope, BusinessMembershipSync, Money};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ProviderStripeService
{
    public function __construct(private ProviderStripeGateway $gateway) {}
    public function live(): bool { return str_starts_with((string) config('services.stripe.secret'), 'sk_live_'); }
    public function businessForVenue(?Venue $venue): ?Business {
        return $venue?->business_id ? Business::find($venue->business_id) : Business::forOwnerId($venue?->owner_user_id);
    }
    public function ownVenue(?Venue $venue): bool {
        $venue = $this->completeVenue($venue);
        return $venue && ($venue->operator_type === 'bookboll' || (!$venue->operator_type && !$venue->business_id && !$venue->owner_user_id));
    }
    private function completeVenue(?Venue $venue): ?Venue {
        if (!$venue) return null;
        foreach (['operator_type','business_id','owner_user_id'] as $key) {
            if (!array_key_exists($key,$venue->getAttributes())) return Venue::find($venue->id);
        }
        return $venue;
    }
    public function available(?Venue $venue): bool {
        $venue = $this->completeVenue($venue);
        if ($this->ownVenue($venue)) return true;
        $b = $this->businessForVenue($venue);
        $a = $b ? BusinessPaymentConnection::where('business_id',$b->id)->first() : null;
        return $a && $a->livemode === $this->live() && $a->ready();
    }
    public function index(Request $request) {
        $actor = $request->user();
        $q = Business::query()->orderBy('name');
        if (AccessScope::isProviderScoped($actor)) $q->whereKey(AccessScope::businessId($actor) ?: 0);
        elseif (!AccessScope::isGlobal($actor)) {
            $countries = AccessScope::jurisdictionCountryIds($actor);
            if ($countries !== null) $q->whereIn('country_id',$countries);
        }
        $businesses = $q->get();
        $connections = BusinessPaymentConnection::whereIn('business_id',$businesses->pluck('id'))->get()->keyBy('business_id');
        return sendSuccess('Payment connections', [
            'preview'=> (bool) config('preview.workspace'),
            'configured'=> (bool) config('services.stripe.connect_client_id') && (bool) config('services.stripe.secret'),
            'businesses'=>$businesses->map(fn($b)=>[
                'id'=>$b->id,'name'=>$b->name,'username'=>$b->username,'owner_user_id'=>$b->owner_id,
                'ready'=>($connections->get($b->id)?->ready() ?? false) && $connections->get($b->id)?->livemode === $this->live(),
                'can_connect'=> $this->canConnect($actor,$b),
                'connection'=>$connections->get($b->id),
            ]),
        ]);
    }
    private function canConnect(User $actor, Business $b): bool {
        return AccessScope::isProviderScoped($actor) && AccessScope::businessId($actor) === $b->id
            && $actor->hasRole('provider_admin') && $actor->hasAnyCapability(['manage_payouts']);
    }
    public function connect(Request $request, int $id) {
        $b = Business::findOrFail($id);
        abort_unless($this->canConnect($request->user(),$b),403,'Only this business owner can connect its Stripe account.');
        abort_if(config('preview.workspace'),409,'Stripe connections are disabled in this test workspace.');
        abort_unless(config('services.stripe.connect_client_id') && config('services.stripe.secret'),409,'Stripe Connect is not configured yet.');
        $existing = BusinessPaymentConnection::where('business_id',$id)->first();
        abort_if($existing && $existing->status !== 'disconnected',422,'This business already has a connected account. Refresh its status or manage it in Stripe.');
        $state = Str::random(64);
        DB::table('business_connect_states')->where('expires_at','<',now())->delete();
        DB::table('business_connect_states')->where('business_id',$id)->delete();
        DB::table('business_connect_states')->insert(['state_hash'=>hash('sha256',$state),'business_id'=>$id,'user_id'=>$request->user()->id,'expires_at'=>now()->addMinutes(15)]);
        return sendSuccess('Connect Stripe', ['url'=>'https://connect.stripe.com/oauth/authorize?'.http_build_query([
            'response_type'=>'code','client_id'=>config('services.stripe.connect_client_id'),
            'scope'=>'read_write','state'=>$state,'redirect_uri'=>url('/api/provider-stripe/callback'),
        ])]);
    }
    public function callback(Request $request) {
        abort_if(config('preview.workspace'),409,'Stripe is disabled in this workspace.');
        $request->validate(['state'=>'required|string|max:100','code'=>'nullable|string|max:500','error'=>'nullable|string|max:100']);
        $state = DB::transaction(function () use ($request) {
            $q=DB::table('business_connect_states')->where('state_hash',hash('sha256',$request->state));
            $row=(clone $q)->lockForUpdate()->first();
            abort_unless($row && $row->expires_at > now()->toDateTimeString(), 422,'Connection link expired. Start again from Payment setup.');
            $q->delete(); return $row;
        });
        $b=Business::findOrFail($state->business_id); $actor=User::findOrFail($state->user_id);
        abort_unless($actor->status === User::ACTIVE && $this->canConnect($actor,$b),403);
        $return=rtrim(config('app.admin_url'),'/').'/payment-setup';
        if ($request->error || !$request->code) return redirect($return.'?stripe=cancelled');
        $token=$this->gateway->exchange($request->code);
        abort_unless(isset($token['stripe_user_id'],$token['livemode']) && (bool)$token['livemode'] === $this->live(),422,'Stripe mode does not match this environment.');
        $remote=$this->gateway->account($token['stripe_user_id']);
        DB::transaction(function () use ($b,$actor,$token,$remote) {
            Business::whereKey($b->id)->lockForUpdate()->firstOrFail();
            $existing=BusinessPaymentConnection::where('business_id',$b->id)->first();
            abort_if($existing && $existing->stripe_account_id !== $remote['id'] && $existing->status !== 'disconnected',409,'A different account is already connected.');
            $a=BusinessPaymentConnection::updateOrCreate(['business_id'=>$b->id],['stripe_account_id'=>$remote['id'],'livemode'=>(bool)$token['livemode'],'connected_by'=>$actor->id]);
            $this->recordStatus($a,$remote);
        });
        return redirect($return.'?stripe=connected');
    }
    public function refresh(Request $request, int $id) {
        $b=Business::findOrFail($id);
        abort_unless($this->canConnect($request->user(),$b),403);
        abort_if(config('preview.workspace'),409,'Stripe is disabled in this workspace.');
        $a=BusinessPaymentConnection::where('business_id',$id)->firstOrFail();
        $this->recordStatus($a,$this->gateway->account($a->stripe_account_id));
        return sendSuccess('Connection refreshed',['connection'=>$a->fresh()]);
    }
    public function recordStatus(BusinessPaymentConnection $a, array $r): void {
        abort_unless(($r['id']??null) === $a->stripe_account_id,422,'Stripe account mismatch.');
        $ready=!empty($r['charges_enabled']) && !empty($r['payouts_enabled']) && ($r['capabilities']['transfers']??null)==='active';
        $a->update(['account_name'=>$r['business_profile']['name']??$r['email']??null,'country'=>$r['country']??null,
            'charges_enabled'=>(bool)($r['charges_enabled']??false),'payouts_enabled'=>(bool)($r['payouts_enabled']??false),
            'transfers_enabled'=>($r['capabilities']['transfers']??null)==='active','status'=>$ready?'active':'restricted',
            'requirements'=>$r['requirements']['currently_due']??[],'verified_at'=>now()]);
    }
    public function webhook(\Stripe\Event $event): bool {
        if (!in_array($event->type,['account.updated','account.application.deauthorized'],true)) return false;
        $id=$event->type==='account.updated' ? $event->data->object->id : $event->account;
        $a=BusinessPaymentConnection::where('stripe_account_id',$id)->first();
        if (!$a) return false;
        if ((bool)$event->livemode !== $a->livemode) return true;
        if ($event->type==='account.application.deauthorized') $a->update(['status'=>'disconnected','charges_enabled'=>false,'payouts_enabled'=>false,'transfers_enabled'=>false]);
        else $this->recordStatus($a,$this->gateway->account($id)); // Fetch latest, so out-of-order events cannot restore stale capability.
        return true;
    }
    /** Frozen per-booking fees; deposits never transfer provider money. */
    public function checkoutData(array $bookingIds, string $paymentType, float $amount): array {
        if ($paymentType === 'deposit') return [];
        $bookings=Booking::with('venue')->whereIn('id',$bookingIds)->get();
        abort_unless($bookings->count() === count(array_unique($bookingIds)) && $bookings->isNotEmpty(),422,'Bookings are missing.');
        $external=$bookings->filter(function ($b) {
            $operator=$b->financial_context['operator']??null;
            return in_array($operator,['provider','bookboll'],true) ? $operator==='provider' : !$this->ownVenue($b->venue);
        });
        if ($external->isEmpty()) return [];
        abort_unless($external->count()===$bookings->count(),422,'Pay bookings from different businesses separately.');
        $businessIds=$external->map(fn($b)=>$b->financial_context['business_id']??$this->businessForVenue($b->venue)?->id)->unique();
        abort_unless($businessIds->count()===1 && $businessIds->first(),422,'Pay each business separately.');
        $a=BusinessPaymentConnection::where('business_id',$businessIds->first())->first();
        abort_unless($a && $a->livemode===$this->live() && $a->status !== 'disconnected',422,'This business must connect Stripe before accepting full online payments.');
        $this->recordStatus($a,$this->gateway->account($a->stripe_account_id));
        abort_unless($a->fresh()->ready(),422,'The provider’s Stripe account is not ready. Choose deposit + on arrival.');
        $fee=0;
        foreach($bookings as $b) {
            $context=$b->financial_context??[];
            $paidFee=Money::toMinor($b->payments()->where('type',\PaymentType::DEPOSIT)->where('status',\PaymentStatus::PAID)->sum('amount'),$b->currency);
            $manual=($context['channel']??null)==='admin' || $b->origin==='manual_admin';
            $bookingFee=$manual?0:($context['net_fee_minor']??null);
            abort_if($bookingFee===null,422,'This booking needs a fee allocation review before online collection.');
            $fee+=max(0,(int)$bookingFee-$paidFee);
        }
        $currency=$bookings->first()->currency;
        abort_if($bookings->pluck('currency')->unique()->count()!==1,422,'Pay each currency separately.');
        abort_if($fee>Money::toMinor($amount,$currency),422,'The recorded fee exceeds this payment. Review the allocation first.');
        $data=['application_fee_amount'=>$fee,'transfer_data'=>['destination'=>$a->stripe_account_id]];
        if (config('services.stripe.connect_on_behalf_of')) $data['on_behalf_of']=$a->stripe_account_id;
        return $data;
    }

    /** Validate the actual processor allocation before marking any booking paid. */
    public function snapshot(array $data, float $amount, string $currency): ?array {
        return $data ? array_merge($data,['amount_minor'=>Money::toMinor($amount,$currency),'currency'=>strtolower($currency)]) : null;
    }
    public function verifySettlement(?array $expected, \Stripe\PaymentIntent $intent): void {
        $actual=$intent->transfer_data?->destination;
        if (is_object($actual)) $actual=$actual->id;
        if (!$expected) {
            abort_if($actual,409,'Connected payment has no saved allocation. Reconcile this checkout before settlement.');
            return; // Older non-Connect sessions retain their existing reconciliation path.
        }
        abort_unless($actual === ($expected['transfer_data']['destination']??null)
            && (int)$intent->application_fee_amount === (int)$expected['application_fee_amount'],409,'Stripe recipient or fee does not match the checkout allocation.');
        if (isset($expected['amount_minor'])) abort_unless((int)$intent->amount_received === (int)$expected['amount_minor'] && strtolower((string)$intent->currency) === $expected['currency'],409,'Stripe amount or currency does not match the checkout.');
    }
}
