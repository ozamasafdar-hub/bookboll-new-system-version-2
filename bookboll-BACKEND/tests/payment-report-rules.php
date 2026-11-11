<?php
// Standalone, deterministic tests: no framework, database, network or payment calls.
require __DIR__.'/../app/Support/Money.php';
require __DIR__.'/../app/Support/OwnedVenueAllocation.php';
require __DIR__.'/../app/Support/PaymentReportRules.php';
require __DIR__.'/../app/Support/PaymentReviewRules.php';
require __DIR__.'/../app/Support/PaymentReportTotals.php';
require __DIR__.'/../app/Support/PaymentReportFilter.php';
require __DIR__.'/../app/Support/PaymentReportPage.php';
use App\Support\PaymentReportRules as Rules;
use App\Support\PaymentReportTotals as Totals;
use App\Support\PaymentReportFilter as Filter;
use App\Support\PaymentReportPage as Page;

$checks = 0;
function eq($actual, $expected, string $message): void {
    global $checks; $checks++;
    if ($actual !== $expected) throw new RuntimeException($message.' expected '.json_encode($expected).', got '.json_encode($actual));
}
function p($id, $type, $amount, $method = 'online', $status = 'paid', $extra = []): array {
    return array_merge(['id'=>$id, 'type'=>$type, 'amount'=>$amount, 'method'=>$method, 'status'=>$status, 'currency'=>'QAR', 'date'=>'2026-11-10'], $extra);
}
function context($operator = 'provider', $channel = 'marketplace', $extra = []): array {
    return ['financial_context'=>array_merge(['operator'=>$operator, 'business_id'=>7, 'business_name'=>'Fixture Sports', 'channel'=>$channel, 'fee_rate'=>15], $extra)];
}
function totals(array $rows): array { $t = new Totals; foreach ($rows as $r) $t->add($r); return [$t->result(), $t->reviewCount]; }

$deposit = Rules::rows([p(1,'deposit',15), p(2,'remaining',85,'cash','pending')], context());
eq($deposit[0]['category'],'provider_booking_fee','1a deposit is platform fee');
eq($deposit[1]['recipient_kind'],'provider','1a arrival belongs to provider');
[$sum] = totals($deposit);
eq($sum[0]['bookboll_received'],15.0,'1a Bookboll receives only deposit');
eq($sum[0]['provider_pending'],85.0,'1a provider sees its pending money');
eq($sum[0]['owned_pending'],0.0,'1a no provider debt in Bookboll pending');
eq($sum[0]['provider_sales'],100.0,'1a sales delivered includes arrival balance');
$site = Rules::rows([p(1,'deposit',7.5),p(2,'remaining',92.5,'cash')], context('provider','site',['fee_rate'=>7.5]));
[$sum] = totals($site);
eq($sum[0]['site_fees'],7.5,'1a own-site fee uses recorded amount');
eq($site[0]['fee_rate'],7.5,'recorded fractional rate retained');
eq($sum[0]['provider_received'],92.5,'provider cash collected');

$split = [p(1,'full',100),p(2,'booking_commission',15),p(3,'booking_earning',85)];
$full = Rules::rows($split, context());
eq($full[0]['row_kind'],'receipt','1b gross is a parent receipt');
[$sum,$review] = totals($full);
eq($sum[0]['bookboll_received'],15.0,'1b fee counted once');
eq($sum[0]['provider_received'],85.0,'1b share counted once');
eq($sum[0]['provider_sales'],100.0,'1b no gross plus allocation double-count');
eq($review,0,'valid conserving pair needs no review');
$withLinks = array_map(fn($x)=>array_merge($x, $x['id'] === 1 ? [] : ['financial_context'=>['receipt_ids'=>[1]]]), $split);
[$sum] = totals(Rules::rows($withLinks, context()));
eq($sum[0]['bookboll_received'],15.0,'explicit receipt links preserve booking ownership context');
$lateSplit = $withLinks;
$lateSplit[0]['date'] = '2026-11-01';
$dated = Rules::rows($lateSplit, context());
eq($dated[1]['payment_date'],'2026-11-01','late allocations use the actual receipt date');
eq($dated[1]['source_receipt_ids'],[1],'allocation exposes the original receipt link');
$page = new Page(1, 2, 'date', 'desc');
foreach ([$dated[1], ['id'=>'10','payment_date'=>'2026-11-05'], $dated[2], ['id'=>'11','payment_date'=>'2026-11-10']] as $r) $page->add($r);
eq(array_column($page->rows(),'id'),['10','3'],'pagination uses normalized date and stable ID');
$page = new Page(0, 2, 'amount', 'asc');
foreach ([['id'=>'1','amount'=>'100'],['id'=>'2','amount'=>'7.5'],['id'=>'3','amount'=>'15']] as $r) $page->add($r);
eq(array_column($page->rows(),'id'),['2','3'],'amount ordering is numeric');
$split[0]['status'] = 'refunded';
[$sum] = totals(Rules::rows($split, context()));
eq($sum[0]['bookboll_received'],0.0,'refunded receipt cannot remain paid via allocation');
eq($sum[0]['refunded'],100.0,'refund counted once');
$bad = Rules::rows([p(1,'full',100),p(2,'booking_commission',15),p(3,'booking_earning',80)],context());
[$sum,$review] = totals($bad);
eq($sum,[],'nonconserving split never reports invented totals');
eq($review,3,'all inconsistent rows flagged');
$duplicate = Rules::rows([p(1,'full',100),p(2,'full',100),p(3,'booking_commission',15),p(4,'booking_earning',85)],context());
[$sum,$review] = totals($duplicate);
eq($review,4,'ambiguous legacy receipt pairs require review');

$ownedPayments = [p(1,'deposit',15),p(2,'remaining',85,'cash'),p(3,'full',100),p(4,'full',50,'card'),p(5,'full',60,'cash','pending')];
foreach ($ownedPayments as &$payment) {
    $payment['owned_allocations'] = \App\Support\OwnedVenueAllocation::plan($payment, context('bookboll')['financial_context'] + [
        'payment_method'=>'deposit_remaining_on_arrival', 'currency'=>'QAR',
        'total_minor'=>\App\Support\Money::toMinor($payment['amount'],'QAR'),
        'fee_minor'=>\App\Support\Money::percentOf(\App\Support\Money::toMinor($payment['amount'],'QAR'),15),
    ]);
}
unset($payment);
$owned = Rules::rows($ownedPayments, context('bookboll'));
[$sum] = totals($owned);
eq($sum[0]['bookboll_received'],250.0,'1c all paid methods belong to Bookboll');
eq($sum[0]['owned_online'],85.0,'1c venue online share excludes the internal platform fee');
eq($sum[0]['owned_onsite'],127.5,'1c terminal card venue share is on-site');
eq($sum[0]['owned_pending'],60.0,'1c unpaid owned balance only');
eq($sum[0]['platform_fees'],37.5,'1c own venue bookings also credit the platform');
eq($sum[0]['owned_platform_pending'],9.0,'unpaid internal fee stays out of received fees');
eq($sum[0]['owned_venue_pending'],51.0,'unpaid venue share is separate');
$manual = Rules::rows([p(1,'full',100,'cash'),p(2,'full',80,'cash','pending')], context('provider','admin'));
[$sum] = totals($manual);
eq($sum[0]['provider_manual'],100.0,'2a provider keeps staff-added payment');

$manualSplit = Rules::rows([p(1,'full',100,'online'),p(2,'booking_commission',15,'online'),p(3,'booking_earning',85,'online')], context('provider','admin'));
[$manualSum] = totals($manualSplit);
eq($manualSum[0]['provider_received'],100.0,'manual connected receipt belongs entirely to provider, counted once');
eq($manualSum[0]['bookboll_received'],0.0,'manual connected receipt gives no platform revenue');
eq($manualSum[0]['platform_fees'],0.0,'manual connected receipt gives no platform fee');
eq($sum[0]['bookboll_received'],0.0,'2a no platform earnings');
eq($sum[0]['provider_sales'],0.0,'2a not a sale sent from marketplace');
$manualOwn = Rules::rows([p(1,'full',100,'wire_transfer')],context('bookboll','admin'));
[$sum] = totals($manualOwn);
eq($sum[0]['owned_onsite'],100.0,'2b Bookboll keeps staff-added own venue payment');
eq(Rules::channel(['origin'=>'manual_admin','commission_channel'=>'site']),'admin','manual origin wins over default commission channel');
$skip = Rules::rows([p(1,'full',100,'cash')], context() + ['payment_method'=>'payment_skipped']);
[$sum,$review] = totals($skip);
eq($sum,[],'skipped booking never fabricates earnings');
eq($review,1,'unexpected skipped payment row is flagged');
eq(Rules::rows([],context()),[],'normal skipped booking has no payment record');
$event = Rules::rows([p(1,'commission',20),p(2,'deposit',15),p(3,'remaining',85,'cash','pending')],context()+['origin'=>'customer_game']);
[$sum] = totals($event);
eq($sum[0]['event_fees'],20.0,'event host fee separated from court deposit');
eq($sum[0]['platform_fees'],35.0,'event and court fees both counted once');
eq($event[0]['fee_rate'],null,'court rate is not mislabeled as event rate');
eq(Rules::rows([p(1,'commission',20,'online','paid',['source_type'=>'hosted_game'])])[0]['category'],'event_fee','own-spot event fee recognized without court');
$unknown = Rules::rows([p(1,'full',100,'cash','paid',['recipient'=>null])]);
[$sum,$review] = totals($unknown);
eq($sum,[],'missing recipient and ownership never default to Bookboll');
eq($review,1,'unknown ownership review');
$frozen = Rules::rows([p(1,'deposit',7.5,'online','paid',context('provider','site',['fee_rate'=>7.5]))],context('bookboll','marketplace',['fee_rate'=>30]));
eq($frozen[0]['category'],'provider_booking_fee','payment snapshot survives later venue transfer');
eq($frozen[0]['fee_rate'],7.5,'payment rate survives later booking/settings changes');
$reconciled = Rules::rows([p(1,'booking_commission',20,'online','paid',
    ['reconciliation_batch_id'=>'reviewed-transfer'] + context('provider','marketplace',['reconciliation_kind'=>'deposit_to_fee','fee_rate'=>15]))]);
eq($reconciled[0]['amount'],20,'reviewed legacy deposit remains the actual fee amount');
eq($reconciled[0]['fee_rate'],null,'legacy deposit correction does not display an unrelated old quote percentage');
$mixed = Rules::rows([p(1,'full',100,'cash'),p(2,'full',10.123,'cash','paid',['currency'=>'KWD']),p(3,'full',1000,'cash','paid',['currency'=>'JPY'])],context('bookboll','admin'));
[$sum] = totals($mixed);
eq(count($sum),3,'currencies never combined');
eq($sum[1]['bookboll_received'],10.123,'three-decimal currency conserved');
eq($sum[0]['bookboll_received'],1000.0,'zero-decimal currency conserved');
$cancel = Rules::rows([p(1,'full',100,'cash','pending')],context('bookboll','admin')+['status'=>'cancelled']);
[$sum] = totals($cancel);
eq($sum[0]['owned_pending'],0.0,'cancelled booking no longer due');
$broken = Rules::rows([p(1,'full',100,'cash','paid',['currency'=>'']),p(2,'full',-10,'cash')],context('bookboll'));
[$sum,$review] = totals($broken);
eq($review,2,'invalid currency/amount excluded');
$member = Rules::rows([p(1,'subscription_commission',10,'online','paid',['source_type'=>'subscription']), p(2,'subscription_earning',90,'online','paid',['source_type'=>'subscription'])]);
[$sum,$review] = totals($member);
eq($sum[0]['bookboll_received'],10.0,'membership allocations have no redundant gross receipt');
eq($sum[0]['provider_received'],90.0,'membership share remains separate');
eq($review,0,'valid direct membership allocations need no parent');
$freeCourtEvent = Rules::rows([p(1,'commission',20)],context()+['origin'=>'customer_game','payment_method'=>'payment_skipped']);
eq($freeCourtEvent[0]['category'],'event_fee','free court does not erase independent event fee');
$row = $deposit[0] + ['customer_name'=>'Fixture Customer','payment_id'=>'PAY-001','source'=>'venue','venue_id'=>'3'];
eq(Filter::matches($row,['status'=>'paid,pending','scope'=>'bookboll','from_date'=>'2026-11-10','to_date'=>'2026-11-10']),true,'filters include both date boundaries');
eq(Filter::matches($row,['to_date'=>'2026-11-09']),false,'end-only date filter');
eq(Filter::matches($row,['amount_max'=>'0']),false,'zero is a real amount filter');
eq(Filter::matches($row,['search'=>'fixture']),true,'case-insensitive customer search');
eq(Filter::matches($row,['channel'=>'site']),false,'channel filter uses snapshot');
eq(Filter::matches($row,['scope'=>'provider']),false,'recipient filter does not match platform fee');
eq(Filter::matches($row,['source'=>'event']),false,'event and booking sources separate');
eq(Filter::matches($row,['location_id'=>['2','3']]),true,'multiple venue filters');
eq(Filter::matches($row,['currency'=>'PKR']),false,'currency filter exact');
echo "Payment report: $checks assertions passed. No database or network used.\n";
