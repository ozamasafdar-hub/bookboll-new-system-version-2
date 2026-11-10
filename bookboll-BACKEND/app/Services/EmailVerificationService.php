<?php

namespace App\Services;

use App\Mail\BusinessEmailOtpEmail;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Email ownership proof for provider signup.
 *
 * The signup form proves the address BEFORE the account is written: the visitor asks
 * for a code, types it back, and registration then presents the token this issues.
 * So `users.email_verified_at` is true from the first moment the row exists — there is
 * no window in which an unverified provider account is sitting in the database.
 *
 * Three endpoints' worth of rules live here rather than in the controller, because the
 * send and the verify halves have to agree on the same expiry and attempt budget.
 */
class EmailVerificationService
{
    /** How long a code is good for. Short: it is six digits sitting in an inbox. */
    public const OTP_TTL_MINUTES = 10;

    /** How long the proof lasts once the code is accepted — enough to finish the form. */
    public const TOKEN_TTL_MINUTES = 30;

    /** Wrong guesses allowed against one code before it is burned. */
    public const MAX_ATTEMPTS = 5;

    /** Minimum gap between two sends to the same address (the Resend cooldown). */
    public const RESEND_COOLDOWN_SECONDS = 60;

    public function sendOtp(Request $request)
    {
        $data = $request->validate([
            'email' => 'required|email|max:255',
        ]);

        $email = $this->normalize($data['email']);
        $existing = $this->row($email);

        // Resend cooldown. The UI counts down too, but this endpoint is public — the
        // button is not the only way to call it, and the cost of getting it wrong is
        // someone else's inbox being used as a mailbomb target.
        if ($existing && $existing->created_at) {
            $elapsed = Carbon::parse($existing->created_at)->diffInSeconds(now());
            if ($elapsed < self::RESEND_COOLDOWN_SECONDS) {
                return sendError(
                    'Please wait a moment before requesting another code.',
                    ['retry_after' => (int) ceil(self::RESEND_COOLDOWN_SECONDS - $elapsed)],
                    429
                );
            }
        }

        $otp = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        DB::table('email_verification_otps')->updateOrInsert(
            ['email' => $email],
            [
                'otp_hash'            => Hash::make($otp),
                'attempts'            => 0,
                'verified_at'         => null,
                'verified_token_hash' => null,
                'created_at'          => now(),
            ]
        );

        try {
            Mail::to($email)->send(new BusinessEmailOtpEmail($otp, $email));
        } catch (\Throwable $e) {
            Log::error('Signup OTP email failed', ['email' => $email, 'error' => $e->getMessage()]);

            // The row is left in place: the code is valid, the delivery is what broke,
            // and a retry must not be refused by the cooldown above for an email that
            // never arrived.
            DB::table('email_verification_otps')->where('email', $email)
                ->update(['created_at' => now()->subSeconds(self::RESEND_COOLDOWN_SECONDS)]);

            return sendError('We could not send the code right now. Please try again.');
        }

        return sendSuccess('Code sent — check your inbox.', [
            'expires_in_minutes' => self::OTP_TTL_MINUTES,
            'resend_after'       => self::RESEND_COOLDOWN_SECONDS,
            ...(config('preview.workspace') ? ['workspace_code'=>$otp] : []),
        ]);
    }

    public function verifyOtp(Request $request)
    {
        $data = $request->validate([
            'email' => 'required|email|max:255',
            'otp'   => 'required|string|size:6',
        ]);

        $email = $this->normalize($data['email']);
        $row = $this->row($email);

        if (! $row) {
            return sendError('Request a code first.', ['otp' => ['No code was requested for this address.']], 422);
        }

        if (Carbon::parse($row->created_at)->addMinutes(self::OTP_TTL_MINUTES)->isPast()) {
            $this->forget($email);

            return sendError('That code has expired. Request a new one.', ['otp' => ['Code expired.']], 422);
        }

        if ((int) $row->attempts >= self::MAX_ATTEMPTS) {
            $this->forget($email);

            return sendError('Too many incorrect attempts. Request a new code.', ['otp' => ['Too many attempts.']], 429);
        }

        if (! Hash::check($data['otp'], $row->otp_hash)) {
            DB::table('email_verification_otps')->where('email', $email)->increment('attempts');
            $left = max(0, self::MAX_ATTEMPTS - ((int) $row->attempts + 1));

            return sendError(
                $left > 0 ? "That code is not right. {$left} attempt(s) left." : 'That code is not right.',
                ['otp' => ['Incorrect code.']],
                422
            );
        }

        // Accepted. The code is spent; what the form carries from here is the token.
        $token = Str::random(48);

        DB::table('email_verification_otps')->where('email', $email)->update([
            'otp_hash'            => Hash::make(Str::random(16)),   // unusable, not blank
            'attempts'            => 0,
            'verified_at'         => now(),
            'verified_token_hash' => hash('sha256', $token),
        ]);

        return sendSuccess('Email verified.', [
            'email'                     => $email,
            'email_verification_token'  => $token,
            'expires_in_minutes'        => self::TOKEN_TTL_MINUTES,
        ]);
    }

    /**
     * Is this token a valid, unexpired proof for this address?
     *
     * Called by registration. Read-only on purpose — the row is only cleared once the
     * account has actually been created, so a failed registration (a taken username,
     * say) does not force the visitor to redo the whole email round-trip.
     */
    public function tokenIsValid(?string $email, ?string $token): bool
    {
        if (! $email || ! $token) {
            return false;
        }

        $row = $this->row($this->normalize($email));

        if (! $row || ! $row->verified_at || ! $row->verified_token_hash) {
            return false;
        }

        if (Carbon::parse($row->verified_at)->addMinutes(self::TOKEN_TTL_MINUTES)->isPast()) {
            return false;
        }

        return hash_equals($row->verified_token_hash, hash('sha256', $token));
    }

    /** Called once the account exists — the proof has done its job. */
    public function consume(?string $email): void
    {
        if ($email) {
            $this->forget($this->normalize($email));
        }
    }

    protected function normalize(string $email): string
    {
        return strtolower(trim($email));
    }

    protected function row(string $email)
    {
        return DB::table('email_verification_otps')->where('email', $email)->first();
    }

    protected function forget(string $email): void
    {
        DB::table('email_verification_otps')->where('email', $email)->delete();
    }
}
