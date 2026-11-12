<?php
use App\Models\{User, Venue, Business, ClassProgram, ProviderMember, ProviderMemberPayment};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
uses(RefreshDatabase::class);
beforeEach(function(){
    foreach(['super_admin','provider_admin','provider_viewer','staff'] as $r) Role::findOrCreate($r,'web');
    $this->owner=User::factory()->create();$this->owner->assignRole('provider_admin');
    $this->business=Business::create(['name'=>'Manual QA','username'=>'manual-qa','owner_id'=>$this->owner->id,'plan'=>'free']);
    $this->venue=Venue::create(['name'=>'Manual QA venue','business_id'=>$this->business->id,'owner_user_id'=>$this->owner->id,'status'=>'active','maps_url'=>'https://maps.google.com/?q=0,0','min_advance_booking'=>0,'max_bookings_per_slot'=>1]);
    $this->program=ClassProgram::create(['name'=>'Gym access','venue_id'=>$this->venue->id,'mapped_owner_user_id'=>$this->owner->id,'owner_user_id'=>(string)$this->owner->id,'status'=>'inactive','is_draft'=>true,'currency'=>'QAR']);
    $this->data=['program_id'=>$this->program->id,'name'=>'QA Offline Member','starts_on'=>now()->subDay()->toDateString(),'ends_on'=>now()->addMonth()->toDateString(),'agreed_fee'=>250,'currency'=>'QAR'];
    $this->actingAs($this->owner)->withHeader('from','admin-web');
});
function manualMember($t,array $data=[]){return ProviderMember::create(array_merge($t->data,['status'=>'active','created_by'=>$t->owner->id],$data));}
it('allows a free provider without a gateway or customer account to add and manage an offline member',function(){
    $this->postJson('/api/admin/provider-members',$this->data)->assertOk()->assertJsonPath('data.member.name','QA Offline Member');
    $this->getJson('/api/admin/provider-members')->assertOk()->assertJsonPath('data.total',1);
    $this->assertDatabaseCount('payments',0);$this->assertDatabaseCount('class_subscriptions',0);$this->assertDatabaseCount('provider_wallets',0);
    $id=ProviderMember::first()->id;
    $this->putJson('/api/admin/provider-members/'.$id,array_merge($this->data,['guardian_name'=>'QA Parent','phone'=>'+97400000000']))->assertOk();
    $this->getJson('/api/admin/provider-members/'.$id)->assertOk()->assertJsonPath('data.member.guardian_name','QA Parent')->assertJsonCount(2,'data.activity');
});
it('enforces tenant boundaries on every manual operation and refuses another business programme',function(){
    $m=manualMember($this);$other=User::factory()->create();$other->assignRole('provider_admin');Business::create(['owner_id'=>$other->id,'name'=>'Other','username'=>'other-manual']);$this->actingAs($other);
    $this->getJson('/api/admin/provider-members')->assertOk()->assertJsonPath('data.total',0)->assertJsonCount(0,'data.programs');
    $this->getJson('/api/admin/provider-members/'.$m->id)->assertNotFound();
    $this->postJson('/api/admin/provider-members',$this->data)->assertNotFound();
    $this->putJson('/api/admin/provider-members/'.$m->id,$this->data)->assertNotFound();
    $this->postJson('/api/admin/provider-members/'.$m->id.'/state',['status'=>'cancelled','reason'=>'QA'])->assertNotFound();
    $this->postJson('/api/admin/provider-members/'.$m->id.'/attendance',['attended_on'=>now()->toDateString(),'session'=>'6pm','status'=>'present'])->assertNotFound();
    $this->postJson('/api/admin/provider-members/'.$m->id.'/payments',['request_id'=>(string)Str::uuid(),'amount'=>10,'paid_on'=>now()->toDateString(),'method'=>'cash'])->assertNotFound();
    $this->postJson('/api/admin/provider-members/'.$m->id.'/payments/1/void',['reason'=>'QA'])->assertNotFound();
});
it('denies every writer to read-only staff',function(){
    $m=manualMember($this);$viewer=User::factory()->create(['provider_id'=>$this->owner->id]);$viewer->assignRole('provider_viewer');$this->actingAs($viewer);
    foreach([['POST','/api/admin/provider-members'],['PUT','/api/admin/provider-members/'.$m->id],['POST','/api/admin/provider-members/'.$m->id.'/state'],['POST','/api/admin/provider-members/'.$m->id.'/attendance'],['POST','/api/admin/provider-members/'.$m->id.'/payments'],['POST','/api/admin/provider-members/'.$m->id.'/payments/1/void']] as [$method,$url]) $this->json($method,$url,[])->assertForbidden();
});
it('records attendance per member date and session without duplicate checkins or financial writes',function(){
    $m=manualMember($this);$url='/api/admin/provider-members/'.$m->id.'/attendance';$d=['attended_on'=>now()->toDateString(),'session'=>'6pm','status'=>'present'];
    $this->postJson($url,$d)->assertOk();$this->postJson($url,$d)->assertOk();$this->assertDatabaseCount('provider_member_attendance',1);
    $this->postJson($url,array_merge($d,['session'=>'7pm','status'=>'absent']))->assertOk();$this->assertDatabaseCount('provider_member_attendance',2);
    $this->postJson($url,array_merge($d,['attended_on'=>now()->addDay()->toDateString()]))->assertUnprocessable();
    $this->postJson($url,array_merge($d,['attended_on'=>now()->subYear()->toDateString()]))->assertUnprocessable();
    $this->assertDatabaseCount('payments',0);$this->assertDatabaseCount('class_sessions',0);
});
it('supports cancellation pause reactivation expiry and renewal without contacting Stripe',function(){
    $m=manualMember($this);$url='/api/admin/provider-members/'.$m->id;
    foreach(['paused','cancelled'] as $s){$this->postJson($url.'/state',['status'=>$s,'reason'=>'Requested by member'])->assertOk();$this->postJson($url.'/attendance',['attended_on'=>now()->toDateString(),'session'=>'6pm','status'=>'present'])->assertUnprocessable();}
    $this->postJson($url.'/state',['status'=>'active','reason'=>'Rejoined'])->assertOk();
    $this->putJson($url,array_merge($this->data,['ends_on'=>now()->subDay()->toDateString()]))->assertOk();
    $this->getJson('/api/admin/provider-members?status=expired')->assertOk()->assertJsonPath('data.total',1);
    $this->putJson($url,array_merge($this->data,['ends_on'=>now()->addMonth()->toDateString()]))->assertOk();
    $this->getJson('/api/admin/provider-members?status=active')->assertOk()->assertJsonPath('data.total',1);
});
it('keeps offline receipts idempotent separate from platform money and voidable with a reason',function(){
    $m=manualMember($this);$url='/api/admin/provider-members/'.$m->id;
    $d=['request_id'=>(string)Str::uuid(),'amount'=>100,'paid_on'=>now()->toDateString(),'method'=>'bank_transfer'];
    $this->postJson($url.'/payments',$d)->assertOk();$this->postJson($url.'/payments',$d)->assertOk();
    $this->assertDatabaseCount('provider_member_payments',1);$this->assertDatabaseCount('payments',0);$this->assertDatabaseCount('wallet_transactions',0);
    $this->getJson($url)->assertOk()->assertJsonPath('data.member.balance',150);
    $this->postJson($url.'/payments',array_merge($d,['request_id'=>(string)Str::uuid(),'method'=>'stripe']))->assertUnprocessable();
    $payment=ProviderMemberPayment::first();$this->postJson($url.'/payments/'.$payment->id.'/void',[])->assertUnprocessable();
    $this->postJson($url.'/payments/'.$payment->id.'/void',['reason'=>'Duplicate paper receipt'])->assertOk();
    $this->getJson($url)->assertOk()->assertJsonPath('data.member.balance',250);
});
it('rejects date inversions currency changes after receipts and programme moves',function(){
    $m=manualMember($this);$url='/api/admin/provider-members/'.$m->id;
    $this->putJson($url,array_merge($this->data,['ends_on'=>now()->subMonth()->toDateString()]))->assertUnprocessable();
    $this->postJson($url.'/payments',['request_id'=>(string)Str::uuid(),'amount'=>25,'paid_on'=>now()->toDateString(),'method'=>'cash'])->assertOk();
    $this->putJson($url,array_merge($this->data,['currency'=>'USD']))->assertUnprocessable();
    $another=$this->program->replicate();$another->uuid=(string)Str::uuid();$another->save();
    $this->putJson($url,array_merge($this->data,['program_id'=>$another->id]))->assertUnprocessable();
});
it('saves different membership formats and exposes only approved public listing information',function(){
    $admin=User::factory()->create();$admin->assignRole('super_admin');$this->actingAs($admin);
    $d=['name'=>'Ten badminton lessons','location_id'=>$this->venue->id,'contact_phone'=>'+97400000000','category'=>'coaching','billing_interval'=>'package','listing_details'=>['type'=>'pack','inclusions'=>'Ten lessons with a coach','age_group'=>'Adults','level'=>'Beginner','duration'=>'10 sessions','cancellation_policy'=>'Contact the provider'],'show_on_marketplace'=>false,'show_on_provider_site'=>false];
    $r=$this->postJson('/api/admin/subscriptions/programs',$d)->assertOk()->assertJsonPath('data.program.listing_details.type','pack');
    $p=ClassProgram::find($r->json('data.program.id'));
    $p->update(['show_on_marketplace'=>true]);
    \App\Models\MembershipListingSubscription::create(['program_id'=>$p->id,'business_id'=>$this->business->id,'payer_id'=>$this->owner->id,'amount'=>20,'currency'=>'USD','status'=>'active','paid_until'=>now()->addMonth()]);
    $this->getJson('/api/marketplace/programs/'.$p->uuid)->assertOk()->assertJsonPath('data.program.listing_details.duration','10 sessions');
});
it('lets scoped provider staff manage members and limits viewers to reads',function(){
    $staff=User::factory()->create();$staff->assignRole('staff');
    \App\Models\BusinessMembership::create(['business_id'=>$this->business->id,'user_id'=>$staff->id,'role'=>'staff','scope'=>'all','status'=>'active','version'=>1]);
    $this->actingAs($staff->fresh());
    $this->postJson('/api/admin/provider-members',$this->data)->assertOk();
    $this->getJson('/api/admin/provider-members')->assertOk()->assertJsonPath('data.total',1);
    $viewer=User::factory()->create();$viewer->assignRole('provider_viewer');
    \App\Models\BusinessMembership::create(['business_id'=>$this->business->id,'user_id'=>$viewer->id,'role'=>'provider_viewer','scope'=>'all','status'=>'active','version'=>1]);
    $this->actingAs($viewer->fresh());$this->getJson('/api/admin/provider-members')->assertOk()->assertJsonPath('data.total',1);
    $this->postJson('/api/admin/provider-members',$this->data)->assertForbidden();
});
it('stages membership information changes and publishes them only on approval',function(){
    $this->program->update(['status'=>'active','is_draft'=>false,'approval_status'=>'approved','contact_phone'=>'+97400000000','listing_details'=>['type'=>'pack','inclusions'=>'Ten lessons']]);
    $d=['name'=>$this->program->name,'location_id'=>$this->venue->id,'contact_phone'=>'+97400000000','listing_details'=>['type'=>'pack','inclusions'=>'Twelve lessons']];
    $this->putJson('/api/admin/subscriptions/programs/'.$this->program->id,$d)->assertOk()->assertJsonPath('data.stage_mode','content');
    expect($this->program->fresh()->listing_details['inclusions'])->toBe('Ten lessons');
    $admin=User::factory()->create();$admin->assignRole('super_admin');$this->actingAs($admin);
    $this->postJson('/api/admin/approvals/program/'.$this->program->id.'/approve',[])->assertOk();
    expect($this->program->fresh()->listing_details['inclusions'])->toBe('Twelve lessons');
});
