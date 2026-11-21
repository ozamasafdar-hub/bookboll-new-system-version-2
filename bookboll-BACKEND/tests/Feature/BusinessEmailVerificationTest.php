<?php

/**
 * Provider signup proves the email BEFORE the account is written.
 *
 * The point of taking the proof first is that `users.email_verified_at` is true from
 * the moment the row exists — there is never an unconfirmed provider account sitting
 * in the database. These tests pin that ordering, and the refusals around it: the code
 * is a six-digit secret on a public endpoint, so expiry, the attempt budget and the
 * resend cooldown are the whole security story.
 */

use App\Mail\BusinessEmailOtpEmail;
use App\Models\Business;
use App\Models\User;
use App\Services\EmailVerificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

const SIGNUP_EMAIL = 'newprovider@example.com';

beforeEach(function () {
    Mail::fake();
    Role::findOrCreate('provider_admin', 'web');
    Role::findOrCreate('customer', 'web');
    config(['api-restriction.disable_in_local' => true]);
});

/** Ask for a code and read it back out of the mail that was queued. */
function requestOtp(string $email = SIGNUP_EMAIL): string
{
    test()->postJson('/api/business/email/send-otp', ['email' => $email])->assertOk();

    $otp = null;
    Mail::assertSent(BusinessEmailOtpEmail::class, function ($mail) use (&$otp, $email) {
        if ($mail->email === $email) { $otp = $mail->otp; return true; }
        return false;
    });

    return $otp;
}

function verifyOtp(string $otp, string $email = SIGNUP_EMAIL)
{
    return test()->postJson('/api/business/email/verify-otp', ['email' => $email, 'otp' => $otp]);
}

function signupPayload(array $over = []): array
{
    return array_merge([
        'name'                  => 'Alamgir Khan',
        'business_name'         => 'Lahore Sports Club',
        'phone' => '+923001234567',
        'username'              => 'lahoresports',
        'business_types'        => ['sports_facility'],
        'email'                 => SIGNUP_EMAIL,
        'password'              => 'secret12345',
        'password_confirmation' => 'secret12345',
    ], $over);
}

it('emails a six-digit code', function () {
    $otp = requestOtp();

    expect($otp)->toMatch('/^\d{6}$/');
    // Never stored in the clear — the table is a one-time password otherwise.
    expect(DB::table('email_verification_otps')->where('email', SIGNUP_EMAIL)->value('otp_hash'))
        ->not->toBe($otp);
});

it('creates a verified account when the code is confirmed first', function () {
    $otp = requestOtp();
    $token = verifyOtp($otp)->assertOk()->json('data.email_verification_token');

    $this->postJson('/api/business/register', signupPayload([
        'email_verification_token' => $token,
    ]))->assertSuccessful();

    $user = User::where('email', SIGNUP_EMAIL)->first();
    expect($user)->not->toBeNull();
    expect($user->email_verified_at)->not->toBeNull();
    expect($user->hasRole('provider_admin'))->toBeTrue();
    expect(Business::where('owner_id', $user->id)->exists())->toBeTrue();
});

it('refuses to create an account without the proof', function () {
    $this->postJson('/api/business/register', signupPayload([
        'email_verification_token' => 'not-a-real-token',
    ]))->assertStatus(422);

    expect(User::where('email', SIGNUP_EMAIL)->exists())->toBeFalse();
});

it('will not accept a token issued for a different address', function () {
    $otp = requestOtp('someone.else@example.com');
    $token = verifyOtp($otp, 'someone.else@example.com')->assertOk()->json('data.email_verification_token');

    // The token is real, just not for the address being registered.
    $this->postJson('/api/business/register', signupPayload([
        'email_verification_token' => $token,
    ]))->assertStatus(422);

    expect(User::where('email', SIGNUP_EMAIL)->exists())->toBeFalse();
});

it('rejects a wrong code and burns the budget', function () {
    requestOtp();

    for ($i = 0; $i < EmailVerificationService::MAX_ATTEMPTS; $i++) {
        verifyOtp('000000')->assertStatus(422);
    }

    // Budget spent — refused outright, and the code is burned rather than left
    // sitting there for the next script to carry on against.
    verifyOtp('000000')->assertStatus(429);
    expect(DB::table('email_verification_otps')->where('email', SIGNUP_EMAIL)->exists())->toBeFalse();
});

it('rejects an expired code', function () {
    $otp = requestOtp();

    DB::table('email_verification_otps')->where('email', SIGNUP_EMAIL)
        ->update(['created_at' => now()->subMinutes(EmailVerificationService::OTP_TTL_MINUTES + 1)]);

    verifyOtp($otp)->assertStatus(422);
});

it('rejects a proof token that has gone stale', function () {
    $otp = requestOtp();
    $token = verifyOtp($otp)->assertOk()->json('data.email_verification_token');

    DB::table('email_verification_otps')->where('email', SIGNUP_EMAIL)
        ->update(['verified_at' => now()->subMinutes(EmailVerificationService::TOKEN_TTL_MINUTES + 1)]);

    $this->postJson('/api/business/register', signupPayload([
        'email_verification_token' => $token,
    ]))->assertStatus(422);

    expect(User::where('email', SIGNUP_EMAIL)->exists())->toBeFalse();
});

it('holds the resend cooldown', function () {
    requestOtp();

    $this->postJson('/api/business/email/send-otp', ['email' => SIGNUP_EMAIL])
        ->assertStatus(429);
});

it('keeps the proof usable when registration fails for another reason', function () {
    // A taken username must not cost the visitor another trip through their inbox.
    $owner = User::create(['name' => 'Taken', 'email' => 'taken@example.com', 'password' => 'secret12345', 'status' => User::ACTIVE]);
    Business::create(['owner_id' => $owner->id, 'name' => 'Taken', 'username' => 'lahoresports', 'business_types' => ['sports_facility'], 'status' => Business::STATUS_PENDING]);

    $otp = requestOtp();
    $token = verifyOtp($otp)->assertOk()->json('data.email_verification_token');

    $this->postJson('/api/business/register', signupPayload(['email_verification_token' => $token]))
        ->assertStatus(422);

    // Same token, a free username — straight through.
    $this->postJson('/api/business/register', signupPayload([
        'username'                 => 'lahoresports2',
        'email_verification_token' => $token,
    ]))->assertSuccessful();

    expect(User::where('email', SIGNUP_EMAIL)->first()->email_verified_at)->not->toBeNull();
});

it('spends the proof once the account exists', function () {
    $otp = requestOtp();
    $token = verifyOtp($otp)->assertOk()->json('data.email_verification_token');

    $this->postJson('/api/business/register', signupPayload(['email_verification_token' => $token]))->assertSuccessful();

    expect(DB::table('email_verification_otps')->where('email', SIGNUP_EMAIL)->exists())->toBeFalse();
});
