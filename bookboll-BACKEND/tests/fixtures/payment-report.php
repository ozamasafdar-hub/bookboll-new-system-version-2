<?php
// Local UI fixture endpoint. Never boots Laravel or accesses a database.
require __DIR__.'/../../app/Support/Money.php';
require __DIR__.'/../../app/Support/OwnedVenueAllocation.php';
require __DIR__.'/../../app/Support/PaymentReportRules.php';
require __DIR__.'/../../app/Support/PaymentReviewRules.php';
require __DIR__.'/../../app/Support/PaymentBulkRules.php';
require __DIR__.'/../../app/Support/PaymentReportTotals.php';
require __DIR__.'/../../app/Support/PaymentReportFilter.php';
require __DIR__.'/../../app/Support/PaymentReportPage.php';
use App\Support\PaymentReportRules as Rules;
use App\Support\PaymentReportTotals as Totals;
use App\Support\PaymentReportFilter as Filter;
$filters = json_decode(stream_get_contents(STDIN), true) ?: [];
$perspective = $filters['perspective'] ?? 'platform';
$rows = []; $id = 1;
foreach ([
    ['provider','marketplace','Court One', [['deposit',15,'online','paid'],['remaining',85,'cash','pending']]],
    ['provider','site','Court One', [['full',200,'online','paid'],['booking_commission',15,'online','paid'],['booking_earning',185,'online','paid']]],
    ['bookboll','marketplace','Bookboll Arena', [['deposit',30,'online','paid'],['remaining',170,'cash','paid']]],
    ['bookboll','admin','Bookboll Arena', [['full',120,'card','paid'],['full',80,'cash','pending']]],
    ['provider','admin','Court One', [['full',150,'cash','paid']]],
    ['bookboll','marketplace','Bookboll Arena', [['commission',20,'online','paid']]],
    ['bookboll','site','Bookboll Arena', [['full',100,'cash','paid']]],
] as $index => [$operator,$channel,$venue,$payments]) {
    $b = ['origin'=>$index === 5 ? 'customer_game' : 'bookboll_web','financial_context'=>['operator'=>$operator,'channel'=>$channel,'business_id'=>$operator === 'provider' ? 7 : null,'business_name'=>'Example Sports Club','fee_rate'=>$channel === 'site' ? 7.5 : 15,'event_fee_rate'=>10]];
    $group = [];
    foreach ($payments as [$type,$amount,$method,$status]) $group[] = ['id'=>$id++,'type'=>$type,'amount'=>$amount,'method'=>$method,'status'=>$status,'currency'=>'QAR','date'=>'2026-11-10'];
    foreach ($group as &$p) $p['owned_allocations'] = \App\Support\OwnedVenueAllocation::plan($p, $b['financial_context'] + [
        'payment_method'=>'deposit_remaining_on_arrival','currency'=>'QAR',
        'total_minor'=>\App\Support\Money::toMinor($p['amount'],'QAR'),
        'fee_minor'=>\App\Support\Money::percentOf(\App\Support\Money::toMinor($p['amount'],'QAR'),$b['financial_context']['fee_rate']),
    ]) ?? [];
    unset($p);
    foreach (Rules::rows($group,$b) as $row) {
        if ($row['row_kind'] === 'receipt') continue;
        if ($perspective === 'provider' && $operator !== 'provider') continue;
        $rows[] = $row + ['payment_id'=>'QA-'.str_pad($row['source_payment_id'] ?? $row['id'],4,'0',STR_PAD_LEFT),'booking_id'=>(string)($index+1),'booking_ref'=>'QA-B'.($index+1),'customer_name'=>'Example Customer '.($index+1),'venue_id'=>$operator === 'provider' ? '1' : '2','venue_name'=>$venue,'listing_id'=>$operator === 'provider' ? ($index === 1 ? '12' : '11') : '21','listing_name'=>$index === 1 ? 'Padel court' : 'Badminton court','source'=>$index === 5 ? 'event' : 'venue','can_collect'=>false];
    }
}
$venues=[]; $listings=[];
foreach ($rows as $row) {
    $venues[$row['venue_id']]=['value'=>$row['venue_id'],'label'=>$row['venue_name']];
    $listings[$row['listing_id']]=['value'=>$row['listing_id'],'label'=>$row['listing_name'],'venue_id'=>$row['venue_id'],'venue_name'=>$row['venue_name']];
}
$businesses=[['value'=>'7','label'=>'Example Sports Club']];
if ($perspective === 'platform') array_unshift($businesses,['value'=>'bookboll','label'=>'Bookboll · own venues']);
$rows = array_values(array_filter($rows,fn($r)=>Filter::matches($r,$filters)));
$ordered = new \App\Support\PaymentReportPage(0, max(1,count($rows)), $filters['sort'] ?? 'date', $filters['sort_dir'] ?? 'desc');
foreach ($rows as $row) $ordered->add($row);
$rows = $ordered->rows();
$totals = new Totals;
foreach ($rows as $row) $totals->add($row);
$page = max(1,(int)($filters['page'] ?? 1)); $per = max(1,(int)($filters['records_per_page'] ?? 25));
echo json_encode(['success'=>true,'data'=>['report_version'=>2,'perspective'=>$perspective,'payments'=>['data'=>array_slice($rows,($page-1)*$per,$per),'total'=>count($rows),'current_page'=>$page,'per_page'=>$per],'summary'=>$totals->result(),'review_count'=>$totals->reviewCount,'filter_options'=>['currencies'=>['QAR'],'venues'=>array_values($venues),'listings'=>array_values($listings),'businesses'=>$businesses]]]);
