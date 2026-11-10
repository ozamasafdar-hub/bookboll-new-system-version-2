<?php
use App\Models\{Booking,Payment,User,Venue};
use App\Services\HistoricalRetainedDepositReconciliation;
use App\Support\{PaymentReportRules,PaymentReportTotals};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function confirmedRetainedDeposit(string $reference='PAY-2723-FUL-066381'): Payment {
    $expected=HistoricalRetainedDepositReconciliation::RECEIPTS[$reference];
    $user=User::factory()->create();
    $venue=Venue::create(['name'=>'Retained deposit QA','status'=>'active','maps_url'=>'https://maps.google.com/?q=0,0']);
    $context=['operator'=>'provider','business_id'=>123,'channel'=>'marketplace','fee_rate'=>20];
    $booking=Booking::withoutEvents(function()use($expected,$user,$venue,$context){
        $b=new Booking(['user_id'=>$user->id,'venue_id'=>$venue->id,'date'=>'2026-01-01',
            'start_time'=>'10:00','end_time'=>'11:00','subtotal'=>149,'total_price'=>149,'currency'=>'QAR',
            'payment_method'=>'online_payment','origin'=>'bookboll_web','status'=>'confirmed','payment_status'=>'fully_paid']);
        $b->forceFill(['id'=>$expected['booking_id'],'financial_context'=>$context,
            'creation_context'=>['version'=>1,'channel'=>'marketplace','actor_kind'=>'customer']])->save();return $b;
    });
    return Payment::withoutEvents(fn()=>Payment::create(['booking_id'=>$booking->id,'user_id'=>$user->id,'payment_id'=>$reference,
        'amount'=>$expected['amount_minor']/100,'currency'=>'QAR','method'=>'online','type'=>'full','status'=>'paid',
        'recipient'=>'provider','date'=>'2026-01-01','paid_at'=>'2026-01-01 10:00:00','financial_context'=>$context]));
}

it('attributes exactly 448 to platform fees once and preserves all receipt facts and booking fields',function(){
    $service=app(HistoricalRetainedDepositReconciliation::class);$totals=new PaymentReportTotals;
    foreach(array_keys(HistoricalRetainedDepositReconciliation::RECEIPTS) as $reference){
        $p=confirmedRetainedDeposit($reference)->fresh();$before=$p->getAttributes();$bookingBefore=$p->booking->getAttributes();
        expect($service->apply($p))->toBeTrue();$p->refresh()->load('booking');
        $changed=['type','recipient','financial_context','reconciliation_batch_id'];
        expect(array_diff_key($p->getAttributes(),array_flip($changed)))->toBe(array_diff_key($before,array_flip($changed)))
            ->and($p->booking->getAttributes())->toBe($bookingBefore)->and($p->type)->toBe('deposit');
        $raw=array_merge($p->getAttributes(),['financial_context'=>$p->financial_context]);
        $b=array_merge($p->booking->getAttributes(),['financial_context'=>$p->booking->financial_context,'creation_context'=>$p->booking->creation_context]);
        $rows=PaymentReportRules::rows([$raw],$b);
        expect($rows)->toHaveCount(1)->and($rows[0]['category'])->toBe('provider_booking_fee')
            ->and($rows[0]['fee_rate'])->toBeNull()->and($rows[0]['recipient_kind'])->toBe('bookboll');
        foreach($rows as $r)$totals->add($r);
        expect($service->apply($p))->toBeFalse();
        $audit=DB::table('payment_allocation_corrections')->where('payment_id',$p->id)->first();
        expect(json_decode($audit->before,true)['payment']['type'])->toBe('full')
            ->and(json_decode($audit->after,true)['payment']['type'])->toBe('deposit');
    }
    $s=$totals->result()[0];
    expect($s['bookboll_received'])->toBe(448.0)->and($s['platform_fees'])->toBe(448.0)
        ->and($s['provider_received'])->toBe(0.0)->and($s['provider_pending'])->toBe(0.0);
    $this->assertDatabaseCount('payments',3);$this->assertDatabaseCount('payment_allocation_corrections',3);
});

it('refuses changed receipt identity, money, status, method or operator',function(array $changes){
    $p=confirmedRetainedDeposit();$p->forceFill($changes)->saveQuietly();
    expect(app(HistoricalRetainedDepositReconciliation::class)->apply($p))->toBeFalse();
    $this->assertDatabaseCount('payment_allocation_corrections',0);
})->with([
    [['payment_id'=>'another-receipt']], [['amount'=>150]], [['currency'=>'AED']], [['status'=>'cancelled']],
    [['status'=>'pending']], [['method'=>'cash']], [['financial_context'=>['operator'=>'bookboll']]],
]);

it('does not override manual creation evidence or saved internal allocations',function(){
    $p=confirmedRetainedDeposit();
    $p->booking->forceFill(['creation_context'=>['channel'=>'admin','actor_kind'=>'staff']])->saveQuietly();
    expect(app(HistoricalRetainedDepositReconciliation::class)->apply($p))->toBeFalse();
    $p=confirmedRetainedDeposit('PAY-2722-FUL-055751');
    $p->ownedAllocations()->create(['bucket'=>'venue','amount_minor'=>14900,'currency'=>'QAR','basis'=>'booking_quote']);
    expect(app(HistoricalRetainedDepositReconciliation::class)->apply($p))->toBeFalse();
    $this->artisan('payments:reconcile-retained-deposits --apply')->assertExitCode(1);
});
