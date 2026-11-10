<?php
use App\Models\{Booking,Payment,User,Venue};
use App\Services\HistoricalOwnedFeeReconciliation;
use App\Support\{PaymentReportRules,PaymentReportTotals};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function missingOwnedReceipt(string $status='paid',float $amount=100,string $channel='marketplace',string $operator='bookboll'): Payment {
    $user=User::factory()->create();
    $venue=Venue::create(['name'=>'Historical split QA','status'=>'active','maps_url'=>'https://maps.google.com/?q=0,0','currency_override'=>'QAR']);
    $booking=Booking::withoutEvents(fn()=>Booking::create(['user_id'=>$user->id,'venue_id'=>$venue->id,'date'=>'2026-01-01',
        'start_time'=>'10:00','end_time'=>'11:00','subtotal'=>$amount,'total_price'=>$amount,'currency'=>'QAR',
        'payment_method'=>'online_payment','origin'=>'bookboll_web','status'=>$status==='cancelled'?'cancelled':'confirmed','payment_status'=>$status==='paid'?'fully_paid':'pending']));
    $context=['operator'=>$operator,'channel'=>'marketplace','ownership_basis'=>'preview_owner_confirmed_2026_11_10','fee_minor'=>null];
    $booking->forceFill(['financial_context'=>$context,'creation_context'=>['version'=>1,'channel'=>$channel,'actor_kind'=>$channel==='admin'?'staff':'customer']])->saveQuietly();
    return Payment::withoutEvents(fn()=>Payment::create(['booking_id'=>$booking->id,'user_id'=>$user->id,'payment_id'=>'legacy-rate-'.uniqid(),
        'amount'=>$amount,'currency'=>'QAR','method'=>'online','type'=>'full','status'=>$status,'recipient'=>'@bookboll','date'=>'2026-01-01','financial_context'=>$context]));
}

it('writes an audited 20/80 split once without changing any original receipt fields',function(){
    $p=missingOwnedReceipt()->fresh(); $before=$p->getAttributes(); $service=app(HistoricalOwnedFeeReconciliation::class);
    expect($service->apply($p))->toBeTrue()->and($p->fresh()->getAttributes())->toBe($before);
    $alloc=$p->ownedAllocations()->orderBy('id')->get();
    expect($alloc->pluck('amount_minor')->all())->toBe([2000,8000])
        ->and($alloc->pluck('fee_rate')->all())->toBe([20.0,20.0]);
    expect($service->apply($p->fresh()))->toBeFalse();
    $this->assertDatabaseCount('payment_allocation_corrections',1);
    $audit=DB::table('payment_allocation_corrections')->first();
    expect(json_decode($audit->before,true)['internal_allocations'])->toBe([])
        ->and(json_decode($audit->evidence,true)['rate'])->toBe(20);
});

it('keeps pending and cancelled allocations out of received and conserves partial receipt amounts',function(){
    $totals=new PaymentReportTotals;
    foreach([['paid',40],['pending',5],['cancelled',100]] as [$status,$amount]){
        $p=missingOwnedReceipt($status,$amount); app(HistoricalOwnedFeeReconciliation::class)->apply($p);
        $p->refresh()->load(['booking','ownedAllocations']);
        $raw=array_merge($p->getAttributes(),['financial_context'=>$p->financial_context,'owned_allocations'=>$p->ownedAllocations->toArray()]);
        $b=array_merge($p->booking->getAttributes(),['financial_context'=>$p->booking->financial_context,'creation_context'=>$p->booking->creation_context]);
        foreach(PaymentReportRules::rows([$raw],$b) as $r)$totals->add($r);
        expect($p->status)->toBe($status);
    }
    $s=$totals->result()[0];
    expect($s['bookboll_received'])->toBe(40.0)->and($s['platform_fees'])->toBe(8.0)
        ->and($s['owned_venue_received'])->toBe(32.0)->and($s['owned_platform_pending'])->toBe(1.0)
        ->and($s['owned_venue_pending'])->toBe(4.0)->and($totals->feeSplitCount)->toBe(0);
});

it('does not charge staff, external or unknown-channel bookings',function(string $channel,string $operator){
    $p=missingOwnedReceipt('paid',100,$channel,$operator);
    expect(app(HistoricalOwnedFeeReconciliation::class)->apply($p))->toBeFalse();
    $this->assertDatabaseCount('payment_allocations',0);
})->with([['admin','bookboll'],['unknown','bookboll'],['marketplace','provider']]);

it('does not replace an original fee quote, saved split or retained-deposit rule',function(){
    $service=app(HistoricalOwnedFeeReconciliation::class);
    $p=missingOwnedReceipt();$p->booking->forceFill(['commission_fee_minor'=>750,'commission_effective_pct'=>7.5])->saveQuietly();
    expect($service->apply($p->fresh()))->toBeFalse();
    $p=missingOwnedReceipt();$p->ownedAllocations()->createMany([
        ['bucket'=>'platform','amount_minor'=>1500,'currency'=>'QAR','fee_rate'=>15,'basis'=>'booking_quote'],
        ['bucket'=>'venue','amount_minor'=>8500,'currency'=>'QAR','fee_rate'=>15,'basis'=>'booking_quote'],
    ]);
    expect($service->apply($p))->toBeFalse()->and($p->ownedAllocations()->sum('amount_minor'))->toBe('10000');
    $p=missingOwnedReceipt();$p->forceFill(['type'=>'deposit'])->saveQuietly();
    expect($service->apply($p))->toBeFalse();
});

it('restores the exact saved fee on an original online receipt without taxing an additional balance again',function(){
    $p=missingOwnedReceipt('paid',150);$b=$p->booking;
    $context=$p->financial_context+['fee_rate'=>20,'net_fee_minor'=>3000,'merchant_share_minor'=>12000,'currency'=>'QAR'];
    $context['fee_minor']=3000;
    $b->forceFill(['total_price'=>175,'commission_fee_minor'=>3000,'commission_effective_pct'=>20,'financial_context'=>$context])->saveQuietly();
    $p->forceFill(['financial_context'=>$context])->saveQuietly();
    $pending=Payment::withoutEvents(fn()=>Payment::create(['booking_id'=>$b->id,'user_id'=>$b->user_id,'payment_id'=>'balance-'.uniqid(),
        'amount'=>25,'currency'=>'QAR','method'=>'cash','type'=>'full','status'=>'pending','financial_context'=>$context]));
    $service=app(HistoricalOwnedFeeReconciliation::class);
    expect($service->apply($p))->toBeTrue()->and($service->apply($pending))->toBeTrue();
    expect($p->ownedAllocations()->pluck('amount_minor')->all())->toBe([3000,12000])
        ->and($pending->ownedAllocations()->pluck('amount_minor')->all())->toBe([2500]);
    expect($pending->fresh()->status)->toBe('pending');
});

it('keeps the restatement limited to the approved imported ownership snapshot',function(){
    $p=missingOwnedReceipt();$context=$p->financial_context;unset($context['ownership_basis']);
    $p->forceFill(['financial_context'=>$context])->saveQuietly();
    expect(app(HistoricalOwnedFeeReconciliation::class)->apply($p))->toBeFalse();
    $this->artisan('payments:reconcile-owned-history --apply')->assertExitCode(1);
});
