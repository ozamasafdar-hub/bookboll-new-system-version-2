<?php

use App\Models\{Community, CommunityMember, CustomerMessage, User, Setting, HostedGame, GameJoinRequest};
use App\Services\{ChatService, ChatAlertService};
use App\Notifications\ChatUnreadNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{DB, Notification, Http};
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['preview.enabled' => false, 'cache.default' => 'array']);
    Notification::fake(); Http::preventStrayRequests();
    foreach (['customer','super_admin','admin','provider_admin'] as $role) Role::findOrCreate($role, 'web');
    $this->sender = User::factory()->create(['name' => 'Sender', 'status' => User::ACTIVE]);
    $this->recipient = User::factory()->create(['name' => 'Player', 'status' => User::ACTIVE]);
    $this->outsider = User::factory()->create(['name' => 'Outsider', 'status' => User::ACTIVE]);
    foreach ([$this->sender,$this->recipient,$this->outsider] as $u) $u->assignRole('customer');
    $this->community = Community::create(['owner_customer_id' => $this->sender->uuid, 'name' => 'QA Chat', 'moderation_status' => 'active']);
    CommunityMember::create(['community_id' => $this->community->id, 'customer_id' => $this->recipient->uuid, 'status' => 'accepted', 'role' => 'member']);
    $this->key = 'community__'.$this->community->uuid;
});

function chatSend($test, string $body = 'Hello', array $mentions = []) {
    return $test->actingAs($test->sender)->postJson('/api/customer/messages/group', ['key' => $test->key, 'body' => $body, 'mentions' => $mentions]);
}

it('validates tagged group participants and UTF-16 text offsets then returns the tag', function () {
    $r = chatSend($this, '🏸 @Player hello', [['user_id'=>$this->recipient->uuid, 'start'=>3, 'length'=>7]])->assertOk();
    expect($r->json('data.message.mentions.0.label'))->toBe('@Player');
    $this->assertDatabaseHas('chat_alerts', ['user_id'=>$this->recipient->id,'kind'=>'mention','channel'=>'email','status'=>'pending']);
    expect(DB::table('chat_alerts')->count())->toBe(1); // No generic group duplicate, self alert or WhatsApp without opt-in.
    $this->getJson('/api/customer/messages/group?key='.$this->key)->assertOk()->assertJsonPath('data.messages.0.mentions.0.start',3);
    chatSend($this, '@Outsider', [['user_id'=>$this->outsider->uuid,'start'=>0,'length'=>9]])->assertUnprocessable();
    chatSend($this, 'Wrong text', [['user_id'=>$this->recipient->uuid,'start'=>0,'length'=>7]])->assertUnprocessable();
    expect(CustomerMessage::count())->toBe(1);
});

it('keeps participant search private and excludes pending members', function () {
    CommunityMember::create(['community_id'=>$this->community->id,'customer_id'=>$this->outsider->uuid,'status'=>'pending','role'=>'member']);
    $this->actingAs($this->outsider)->getJson('/api/customer/messages/participants?key='.$this->key)->assertForbidden();
    $r=$this->actingAs($this->sender)->getJson('/api/customer/messages/participants?key='.$this->key)->assertOk();
    expect($r->json('data.participants'))->toHaveCount(1);
    expect(array_keys($r->json('data.participants.0')))->toBe(['id','name','avatar_url']);
});

it('batches unread messages and never resends a processed batch', function () {
    chatSend($this)->assertOk(); chatSend($this, 'Second message')->assertOk();
    $this->travel(3)->minutes();
    app(ChatAlertService::class)->deliver(); app(ChatAlertService::class)->deliver();
    Notification::assertSentToTimes($this->recipient, ChatUnreadNotification::class, 1);
    expect(DB::table('chat_alerts')->where('status','sent')->count())->toBe(2);
    chatSend($this, 'Another reply')->assertOk(); $this->travel(3)->minutes(); app(ChatAlertService::class)->deliver();
    Notification::assertSentToTimes($this->recipient, ChatUnreadNotification::class, 1);
    $this->travel(13)->minutes(); app(ChatAlertService::class)->deliver();
    Notification::assertSentToTimes($this->recipient, ChatUnreadNotification::class, 2);
});

it('suppresses read deleted and no-longer-member messages', function () {
    $id=chatSend($this)->assertOk()->json('data.message.id');
    $this->actingAs($this->recipient)->postJson('/api/customer/messages/group/read',['key'=>$this->key,'through'=>$id])->assertOk();
    $this->travel(3)->minutes(); app(ChatAlertService::class)->deliver(); Notification::assertNothingSent();
    chatSend($this, 'Deleted')->assertOk(); CustomerMessage::latest('id')->first()->delete();
    $this->travel(3)->minutes(); app(ChatAlertService::class)->deliver(); Notification::assertNothingSent();
    chatSend($this, 'Left group')->assertOk(); CommunityMember::where('customer_id',$this->recipient->uuid)->update(['status'=>'pending']);
    $this->travel(3)->minutes(); app(ChatAlertService::class)->deliver(); Notification::assertNothingSent();
});

it('does not mark a newer message read when acknowledging an older response', function () {
    $id=chatSend($this)->assertOk()->json('data.message.id'); chatSend($this,'Newer')->assertOk();
    $this->actingAs($this->recipient)->postJson('/api/customer/messages/group/read',['key'=>$this->key,'through'=>$id])->assertOk();
    $this->travel(3)->minutes(); app(ChatAlertService::class)->deliver();
    Notification::assertSentToTimes($this->recipient,ChatUnreadNotification::class,1);
    expect(DB::table('chat_alerts')->where('status','skipped')->count())->toBe(1);
});

it('enforces superadmin settings and rechecks toggles before delivery', function () {
    chatSend($this)->assertOk();
    $settings=app(ChatService::class)->settings(); $settings['group']['email']=false;
    foreach (['admin','provider_admin'] as $role) {
        $u=User::factory()->create(['status'=>User::ACTIVE]);$u->assignRole($role);
        $this->actingAs($u)->putJson('/api/admin/chat-notifications',$settings)->assertForbidden();
    }
    $u=User::factory()->create(['status'=>User::ACTIVE]);$u->assignRole('super_admin');
    $this->actingAs($u)->putJson('/api/admin/chat-notifications',$settings)->assertOk()->assertJsonPath('data.settings.group.email',false);
    $this->postJson('/api/admin/settings/'.ChatService::SETTINGS_KEY,['value'=>$settings])->assertForbidden();
    $this->travel(3)->minutes(); app(ChatAlertService::class)->deliver(); Notification::assertNothingSent();
});

it('honours independent mention settings and customer opt-outs', function () {
    $s=app(ChatService::class)->settings();$s['group']['email']=false;
    Setting::updateOrCreate(['key'=>ChatService::SETTINGS_KEY],['value'=>json_encode($s)]);
    chatSend($this,'Plain')->assertOk(); expect(DB::table('chat_alerts')->count())->toBe(0);
    chatSend($this,'@Player',[['user_id'=>$this->recipient->uuid,'start'=>0,'length'=>7]])->assertOk();
    $this->actingAs($this->recipient)->putJson('/api/customer/messages/preferences',['email'=>false,'whatsapp'=>false])->assertOk();
    $this->travel(3)->minutes();app(ChatAlertService::class)->deliver();Notification::assertNothingSent();
});

it('simulates both channels in preview without sending messages', function () {
    $this->recipient->update(['notification_preferences'=>['chat'=>['email'=>true,'whatsapp'=>true]]]);
    chatSend($this)->assertOk(); config(['preview.enabled'=>true]); $this->travel(3)->minutes();app(ChatAlertService::class)->deliver();
    expect(DB::table('chat_alerts')->where('status','preview')->count())->toBe(2);
    Notification::assertNothingSent();Http::assertNothingSent();
});

it('suppresses direct-message alerts after the recipient reads the thread', function () {
    $this->actingAs($this->sender)->postJson('/api/customer/messages',['recipient_id'=>$this->recipient->uuid,'body'=>'Direct'])->assertOk();
    $this->actingAs($this->recipient)->postJson('/api/customer/messages/read',['peer_id'=>$this->sender->uuid])->assertOk();
    $this->travel(3)->minutes();app(ChatAlertService::class)->deliver();Notification::assertNothingSent();
});

it('returns the newest messages rather than freezing long chats at the oldest page', function () {
    for ($i=0;$i<4;$i++) CustomerMessage::create(['conversation_key'=>$this->key,'sender_id'=>$this->sender->uuid,'body'=>'Message '.$i]);
    $r=$this->actingAs($this->recipient)->getJson('/api/customer/messages/group?key='.$this->key.'&limit=2')->assertOk();
    expect(array_column($r->json('data.messages'),'body'))->toBe(['Message 2','Message 3']);
});

it('uses the same mentions and precise read tracking for accepted event participants', function () {
    $game=HostedGame::create(['host_customer_id'=>$this->sender->uuid,'title'=>'QA Event','game_date'=>now()->addWeek()->toDateString(),'status'=>'open','price_per_person'=>0,'published_at'=>now()]);
    GameJoinRequest::create(['game_id'=>$game->id,'joiner_customer_id'=>$this->recipient->uuid,'status'=>'accepted']);
    $this->key='meet__'.$game->uuid;
    $id=chatSend($this,'@Player',[['user_id'=>$this->recipient->uuid,'start'=>0,'length'=>7]])->assertOk()->json('data.message.id');
    $this->actingAs($this->recipient)->postJson('/api/customer/messages/group/read',['key'=>$this->key,'through'=>$id])->assertOk();
    $this->travel(3)->minutes();app(ChatAlertService::class)->deliver();Notification::assertNothingSent();
    $this->actingAs($this->outsider)->getJson('/api/customer/messages/participants?key='.$this->key)->assertForbidden();
});

it('uses the configured WhatsApp template only with explicit chat consent', function () {
    config(['services.whatsapp.chat_template'=>'bookboll_chat_alert','services.whatsapp.chat_language'=>'en_US']);
    $this->recipient->update(['phone'=>'+97455555555','notification_preferences'=>['chat'=>['email'=>false,'whatsapp'=>true]]]);
    $wa=Mockery::mock(\App\Services\WhatsappService::class)->makePartial();
    $wa->shouldReceive('platformSender')->andReturn(['phone_number_id'=>'test','token'=>'test']);
    $wa->shouldReceive('sendTemplate')->once()->withArgs(fn($sender,$input)=>$input['template']['name']==='bookboll_chat_alert' && count($input['variables'])===3 && str_contains($input['variables'][2],'/messages/'))->andReturn(['ok'=>true]);
    app()->instance(\App\Services\WhatsappService::class,$wa);
    chatSend($this)->assertOk();app(ChatService::class)->queue(CustomerMessage::first());
    expect(DB::table('chat_alerts')->count())->toBe(1);
    $this->travel(3)->minutes();app(ChatAlertService::class)->deliver();app(ChatAlertService::class)->deliver();
    $this->assertDatabaseHas('chat_alerts',['status'=>'sent','channel'=>'whatsapp']);Notification::assertNothingSent();
});

it('does not retry an uncertain WhatsApp delivery', function () {
    config(['services.whatsapp.chat_template'=>'bookboll_chat_alert']);
    $this->recipient->update(['phone'=>'+97455555555','notification_preferences'=>['chat'=>['email'=>false,'whatsapp'=>true]]]);
    $wa=Mockery::mock(\App\Services\WhatsappService::class)->makePartial();
    $wa->shouldReceive('platformSender')->andReturn(['phone_number_id'=>'test','token'=>'test']);
    $wa->shouldReceive('sendTemplate')->once()->andReturn(['ok'=>false]);
    app()->instance(\App\Services\WhatsappService::class,$wa);
    chatSend($this)->assertOk();$this->travel(3)->minutes();app(ChatAlertService::class)->deliver();app(ChatAlertService::class)->deliver();
    $this->assertDatabaseHas('chat_alerts',['status'=>'needs_review']);
});
