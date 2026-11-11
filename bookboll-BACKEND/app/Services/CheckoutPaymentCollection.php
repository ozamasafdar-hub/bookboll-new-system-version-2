<?php
namespace App\Services;

use App\Models\Booking;
use App\Models\Payment;
use App\Support\AccessScope;
use App\Support\Capabilities;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** One reviewed collection, with the existing booking receipts retained as allocations. */
final class CheckoutPaymentCollection
{
    public function __construct(private PaymentDetailService $details) {}

    public function preview($actor, int $id, ?string $rowId = null): array
    {
        abort_unless($actor->hasCapability(Capabilities::ACCEPT_PAYMENTS),403);
        $group = $this->details->details($actor,$id,$rowId)['checkout_payment'];
        abort_unless($group && $group['kind'] === 'remaining' && !$group['partial'],422,'Select a complete remaining payment.');
        $rows = collect($group['bookings'])->flatMap(fn ($b) => $b['allocations']);
        $pending = $rows->where('status','pending');
        abort_unless($pending->isNotEmpty(),409,'This payment has already changed or been collected.');
        $recipient = AccessScope::isPlatform($actor) ? 'bookboll' : 'provider';
        abort_unless($pending->every(fn ($r) => $r['can_collect'] && $r['recipient_kind'] === $recipient),403,'Only the business receiving this balance can record its collection.');
        $ids = $pending->map(fn ($r) => (int)($r['source_payment_id'] ?? $r['id']))->unique()->sort()->values();
        $payments = Payment::with('booking.venue')->whereIn('id',$ids)->orderBy('id')->get();
        abort_unless($payments->count() === $ids->count() && $payments->every(fn ($p) =>
            $p->booking && $p->booking->status !== 'cancelled' && $p->method !== 'online'
            && in_array($p->status,['pending','unpaid'],true)),409,'A booking or payment changed. Review again.');
        // Amounts must conserve the underlying offline receipts, including internal fee splits.
        $minor = $pending->sum(fn ($r) => Money::toMinor($r['amount'],$r['currency']));
        abort_unless($payments->sum(fn ($p) => Money::toMinor($p->amount,$group['currency'])) === $minor,422,'The receipt allocations need review before collection.');
        $facts = $payments->map(fn ($p) => [$p->id,$p->amount,$p->status,$p->method,$p->updated_at?->toISOString(),
            $p->booking->status,$p->booking->venue?->business_id,$p->booking->venue?->operator_type,$p->financial_context])->all();
        return ['fingerprint'=>hash('sha256',json_encode([$actor->id,$group['id'],$facts])),
            'payment_reference'=>$group['payment_id'],'amount'=>Money::toMajor($minor,$group['currency']),
            'currency'=>$group['currency'],'payment_ids'=>$ids->all(),'booking_count'=>$pending->pluck('booking_id')->unique()->count()];
    }

    public function collect($actor, int $id, array $data): array
    {
        app(\App\Support\PaymentReleaseGate::class)->assertReady();
        abort_unless($actor->hasCapability(Capabilities::ACCEPT_PAYMENTS),403);
        $this->details->find($actor,$id);
        $replay = fn () => DB::table('checkout_payment_collections')->where('fingerprint',$data['fingerprint'])->where('actor_id',$actor->id)->first();
        if ($saved = $replay()) return ['payment_reference'=>$saved->payment_reference,'already_recorded'=>true];
        $preview = $this->preview($actor,$id,$data['row_id'] ?? null);
        abort_unless(hash_equals($preview['fingerprint'],$data['fingerprint']),409,'The balance changed. Review it again before recording payment.');
        return DB::transaction(function () use ($actor,$id,$data,$preview,$replay) {
            $bookingIds = Payment::whereIn('id',$preview['payment_ids'])->pluck('booking_id')->unique()->sort()->values();
            Booking::whereIn('id',$bookingIds)->orderBy('id')->lockForUpdate()->get();
            Payment::whereIn('booking_id',$bookingIds)->orderBy('id')->lockForUpdate()->get();
            if ($saved = $replay()) return ['payment_reference'=>$saved->payment_reference,'already_recorded'=>true];
            $checked = $this->preview($actor,$id,$data['row_id'] ?? null);
            abort_unless(hash_equals($checked['fingerprint'],$data['fingerprint']),409,'The balance changed. Review it again before recording payment.');
            foreach ($preview['payment_ids'] as $paymentId) {
                $payment = Payment::findOrFail($paymentId);
                $request = Request::create('/','PUT',['method'=>$data['method']]);
                $response = app(BookingService::class)->dashboardCollect($request,$payment->booking_id,$paymentId);
                abort_unless($response->getStatusCode() === 200 && $response->getData(true)['success'],409,'A booking could not be collected. No payments were recorded.');
                $payment->booking->history()->create(['user_id'=>$actor->id,'action'=>'checkout_payment_collected',
                    'description'=>'Included in remaining payment '.$preview['payment_reference']]);
            }
            DB::table('checkout_payment_collections')->insert(['fingerprint'=>$data['fingerprint'],'actor_id'=>$actor->id,
                'payment_reference'=>$preview['payment_reference'],'payment_ids'=>json_encode($preview['payment_ids']),
                'currency'=>$preview['currency'],'amount_minor'=>Money::toMinor($preview['amount'],$preview['currency']),
                'method'=>$data['method'],'created_at'=>now()]);
            return ['payment_reference'=>$preview['payment_reference'],'already_recorded'=>false];
        },3);
    }
}
