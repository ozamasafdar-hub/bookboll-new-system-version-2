<?php

/**
 * The admin panel's Delivery Tests page: send a real email or OneSignal push
 * and see what came back.
 *
 * What these pin: who may use it, that an email goes to every chosen user and
 * typed address through the platform mailer, that a push is narrowed to the
 * chosen app and platform and goes through OneSignalService (the production
 * path), and that a send is written to the activity log either way.
 */

use App\Livewire\Admin\DeliveryTests\EmailTest;
use App\Livewire\Admin\DeliveryTests\Index as DeliveryTestsPage;
use App\Livewire\Admin\DeliveryTests\PushTest;
use App\Mail\AdminDeliveryTestMail;
use App\Models\OnesignalSubscription;
use App\Models\User;
use App\Services\OneSignalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    foreach (['super_admin', 'customer'] as $role) {
        Role::findOrCreate($role, 'web');
    }
});

function deliveryAdmin(): User
{
    $admin = User::factory()->create(['status' => User::ACTIVE, 'name' => 'Ops Admin']);
    $admin->assignRole('super_admin');

    return $admin->fresh();
}

function deviceFor(User $user, string $app = 'customer', string $platform = 'ios', bool $active = true): OnesignalSubscription
{
    return OnesignalSubscription::create([
        'app' => $app,
        'user_id' => $user->id,
        'is_active' => $active,
        'device_type' => $platform,
        'device_info' => ucfirst($platform).' test device',
        'onesignal_id' => fake()->uuid(),
    ]);
}

/* ---------------------------------------------------------------------------
 | Access
 --------------------------------------------------------------------------- */

it('is reachable only by a super admin', function () {
    $this->withoutVite();

    $this->actingAs(deliveryAdmin())
        ->get(route('delivery-tests.index'))
        ->assertOk()
        ->assertSeeLivewire(DeliveryTestsPage::class)
        ->assertSeeLivewire(EmailTest::class)
        ->assertSee('Delivery Tests');

    $this->actingAs(deliveryAdmin())
        ->get(route('delivery-tests.index', ['tab' => 'push']))
        ->assertOk()
        ->assertSeeLivewire(PushTest::class);

    $customer = User::factory()->create(['status' => User::ACTIVE]);
    $customer->assignRole('customer');

    $this->actingAs($customer)
        ->get(route('delivery-tests.index'))
        ->assertRedirect(route('login'));
});

it('refuses the child components to anyone who is not a super admin', function () {
    $customer = User::factory()->create(['status' => User::ACTIVE]);
    $customer->assignRole('customer');

    $this->actingAs($customer);

    Livewire::test(EmailTest::class)->assertForbidden();
    Livewire::test(PushTest::class)->assertForbidden();
});

/* ---------------------------------------------------------------------------
 | Email
 --------------------------------------------------------------------------- */

it('emails every chosen user and typed address through the platform mailer and records it', function () {
    Mail::fake();

    $admin = deliveryAdmin();
    $alice = User::factory()->create(['name' => 'Alice Owner', 'email' => 'alice@example.com']);

    $this->actingAs($admin);

    $component = Livewire::test(EmailTest::class)
        ->set('userSearch', 'alice')
        ->assertSee('alice@example.com')
        ->call('addUser', $alice->id)
        ->call('addMyself')
        ->set('emails', "Bob@Example.com, carol@example.com\nalice@example.com")
        ->set('subject', 'Ping from the panel')
        ->set('body', 'Hello there')
        ->call('send')
        ->assertHasNoErrors()
        ->assertSet('errorMessage', null);

    expect($component->get('statusMessage'))->toContain('Sent to 4 addresses');

    foreach (['alice@example.com', $admin->email, 'bob@example.com', 'carol@example.com'] as $to) {
        Mail::assertSent(AdminDeliveryTestMail::class, fn (AdminDeliveryTestMail $mail) => $mail->hasTo($to)
            && $mail->subjectLine === 'Ping from the panel'
            && $mail->body === 'Hello there');
    }

    // alice was both picked and typed — once, not twice.
    Mail::assertSent(AdminDeliveryTestMail::class, 4);

    $results = collect($component->get('results'));
    expect($results)->toHaveCount(4)
        ->and($results->every(fn ($r) => $r['ok'] === true))->toBeTrue();

    $entry = Activity::query()->where('log_name', 'delivery_test')->latest('id')->first();
    expect($entry)->not->toBeNull()
        ->and($entry->causer_id)->toBe($admin->id)
        ->and($entry->properties['channel'])->toBe('email')
        ->and($entry->properties['sent'])->toBe(4)
        ->and($entry->properties['recipients'])->toContain('carol@example.com');
});

it('rejects a send with no recipients or with a malformed address', function () {
    Mail::fake();
    $this->actingAs(deliveryAdmin());

    Livewire::test(EmailTest::class)
        ->call('send')
        ->assertHasErrors(['emails']);

    Livewire::test(EmailTest::class)
        ->set('emails', 'good@example.com, not-an-email')
        ->call('send')
        ->assertHasErrors(['emails']);

    Mail::assertNothingSent();
});

it('requires host, port and from address before trying custom SMTP', function () {
    Mail::fake();
    $this->actingAs(deliveryAdmin());

    Livewire::test(EmailTest::class)
        ->set('emails', 'good@example.com')
        ->set('useCustomSmtp', true)
        ->set('smtpHost', '')
        ->set('fromEmail', 'not-an-address')
        ->call('send')
        ->assertHasErrors(['smtpHost', 'fromEmail']);

    Mail::assertNothingSent();
});

it('reports a mail server it cannot reach per address instead of failing the page', function () {
    $this->actingAs(deliveryAdmin());

    // Nothing listens on this port, so the connection is refused immediately.
    config([
        'mail.default' => 'smtp',
        'mail.mailers.smtp.host' => '127.0.0.1',
        'mail.mailers.smtp.port' => 1,
        'mail.mailers.smtp.scheme' => 'smtp',
        'mail.mailers.smtp.timeout' => 2,
    ]);

    $component = Livewire::test(EmailTest::class)
        ->set('emails', 'nobody@example.com')
        ->call('send')
        ->assertHasNoErrors()
        ->assertSet('statusMessage', null);

    expect($component->get('errorMessage'))->toContain('Nothing was sent');

    $results = $component->get('results');
    expect($results)->toHaveCount(1)
        ->and($results[0]['ok'])->toBeFalse()
        ->and($results[0]['message'])->toContain('Could not reach that mail server');

    $entry = Activity::query()->where('log_name', 'delivery_test')->latest('id')->first();
    expect($entry->properties['failed'])->toBe(1);
});

/* ---------------------------------------------------------------------------
 | Push
 --------------------------------------------------------------------------- */

it('pushes to the chosen users\' devices in the chosen app and platform only, through OneSignalService', function () {
    $admin = deliveryAdmin();
    $alice = User::factory()->create(['name' => 'Alice Player', 'email' => 'alice@example.com']);
    $bob = User::factory()->create(['name' => 'Bob Player', 'email' => 'bob@example.com']);

    $aliceIos = deviceFor($alice, 'customer', 'ios');
    $aliceAndroid = deviceFor($alice, 'customer', 'android');
    deviceFor($alice, 'admin', 'ios');                       // other app
    deviceFor($alice, 'customer', 'ios', active: false);     // unsubscribed
    deviceFor($bob, 'customer', 'ios');                      // not chosen

    $sent = [];
    $this->mock(OneSignalService::class, function ($mock) use (&$sent) {
        $mock->shouldReceive('isConfigured')->andReturn(true);
        $mock->shouldReceive('sendToPlayerIds')
            ->once()
            ->andReturnUsing(function (array $ids, string $title, string $message, array $data, string $app) use (&$sent, $mock) {
                $sent = compact('ids', 'title', 'message', 'data', 'app');
                $mock->lastResult = ['app' => $app, 'strategy' => 'include_subscription_ids', 'status' => 200, 'notification_id' => 'notif-123', 'recipients' => count($ids), 'errors' => null, 'exception' => null];

                return true;
            });
    });

    $this->actingAs($admin);

    // Every platform: both of alice's customer-app devices.
    $component = Livewire::test(PushTest::class)
        ->set('app', 'customer')
        ->set('userSearch', 'alice')
        ->assertSee('Alice Player')
        ->assertSee('2 devices')
        ->call('addUser', $alice->id)
        ->assertSee($aliceIos->onesignal_id)
        ->assertSee($aliceAndroid->onesignal_id)
        ->assertDontSee($bob->email)
        ->set('platform', 'ios')
        ->assertDontSee($aliceAndroid->onesignal_id)
        ->set('title', 'Hello')
        ->set('message', 'From the panel')
        ->set('type', 'booking_created')
        ->set('extraData', '{"booking_id": 42}')
        ->call('send')
        ->assertHasNoErrors()
        ->assertSet('errorMessage', null);

    expect($sent['ids'])->toBe([$aliceIos->onesignal_id])
        ->and($sent['app'])->toBe('customer')
        ->and($sent['title'])->toBe('Hello')
        ->and($sent['message'])->toBe('From the panel')
        ->and($sent['data'])->toBe(['booking_id' => 42, 'type' => 'booking_created']);

    expect($component->get('statusMessage'))->toContain('accepted the notification for 1 customer-app iOS device')
        ->and($component->get('lastSend')['notification_ids'])->toBe(['notif-123']);

    $entry = Activity::query()->where('log_name', 'delivery_test')->latest('id')->first();
    expect($entry->causer_id)->toBe($admin->id)
        ->and($entry->properties['channel'])->toBe('push')
        ->and($entry->properties['app'])->toBe('customer')
        ->and($entry->properties['platform'])->toBe('ios')
        ->and($entry->properties['device_count'])->toBe(1)
        ->and($entry->properties['notification_ids'])->toBe(['notif-123']);
});

it('offers only users who have a device in the chosen app', function () {
    $alice = User::factory()->create(['name' => 'Alice Player']);
    $bob = User::factory()->create(['name' => 'Alice Bobson']);
    deviceFor($alice, 'customer', 'ios');
    deviceFor($bob, 'admin', 'android');

    $this->mock(OneSignalService::class, fn ($mock) => $mock->shouldReceive('isConfigured')->andReturn(true));
    $this->actingAs(deliveryAdmin());

    Livewire::test(PushTest::class)
        ->set('app', 'customer')
        ->set('userSearch', 'alice')
        ->assertSee('Alice Player')
        ->assertDontSee('Alice Bobson')
        ->set('app', 'admin')
        ->set('userSearch', 'alice')
        ->assertSee('Alice Bobson')
        ->assertDontSee('Alice Player');
});

it('sends every active device of the app when "every device" is chosen, in one batch', function () {
    $users = User::factory()->count(3)->create();
    $ids = $users->map(fn (User $u) => deviceFor($u, 'customer', 'android')->onesignal_id)->sort()->values()->all();
    deviceFor($users->first(), 'admin', 'android');

    $sent = null;
    $this->mock(OneSignalService::class, function ($mock) use (&$sent) {
        $mock->shouldReceive('isConfigured')->andReturn(true);
        $mock->shouldReceive('sendToPlayerIds')->once()->andReturnUsing(function (array $deviceIds) use (&$sent, $mock) {
            $sent = $deviceIds;
            $mock->lastResult = ['app' => 'customer', 'strategy' => 'include_subscription_ids', 'status' => 200, 'notification_id' => 'n-1', 'recipients' => 3, 'errors' => null, 'exception' => null];

            return true;
        });
    });

    $this->actingAs(deliveryAdmin());

    Livewire::test(PushTest::class)
        ->set('app', 'customer')
        ->set('target', 'all')
        ->assertSee('3 customer-app devices across 3 users')
        ->call('send')
        ->assertHasNoErrors();

    expect(collect($sent)->sort()->values()->all())->toBe($ids);
});

it('pushes raw subscription ids as given, and refuses an app with no keys', function () {
    $sent = null;
    $this->mock(OneSignalService::class, function ($mock) use (&$sent) {
        $mock->shouldReceive('isConfigured')->with('customer')->andReturn(true);
        $mock->shouldReceive('isConfigured')->with('admin')->andReturn(false);
        $mock->shouldReceive('sendToPlayerIds')->once()->andReturnUsing(function (array $deviceIds, $t, $m, $d, string $app) use (&$sent, $mock) {
            $sent = [$deviceIds, $app];
            $mock->lastResult = ['app' => $app, 'strategy' => 'include_subscription_ids', 'status' => 200, 'notification_id' => '', 'recipients' => 0, 'errors' => ['All included players are not subscribed'], 'exception' => null];

            return false;
        });
    });

    $this->actingAs(deliveryAdmin());

    $component = Livewire::test(PushTest::class)
        ->set('app', 'customer')
        ->set('target', 'ids')
        ->set('rawIds', "aaaa-1111\nbbbb-2222, aaaa-1111")
        ->call('send')
        ->assertHasNoErrors();

    expect($sent)->toBe([['aaaa-1111', 'bbbb-2222'], 'customer'])
        ->and($component->get('errorMessage'))->toContain('reached nobody')
        ->and($component->get('lastSend')['results'][0]['errors'])->toContain('All included players are not subscribed');

    $blocked = Livewire::test(PushTest::class)
        ->set('app', 'admin')
        ->set('target', 'ids')
        ->set('rawIds', 'cccc-3333')
        ->call('send')
        ->assertHasNoErrors();

    expect($blocked->get('errorMessage'))->toContain('no app id or REST key');
});

it('rejects extra data that is not a JSON object', function () {
    $this->mock(OneSignalService::class, function ($mock) {
        $mock->shouldReceive('isConfigured')->andReturn(true);
        $mock->shouldNotReceive('sendToPlayerIds');
    });

    $this->actingAs(deliveryAdmin());

    Livewire::test(PushTest::class)
        ->set('target', 'ids')
        ->set('rawIds', 'aaaa-1111')
        ->set('extraData', '[1,2,3]')
        ->call('send')
        ->assertHasErrors(['extraData']);
});
