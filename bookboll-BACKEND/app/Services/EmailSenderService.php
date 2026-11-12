<?php

namespace App\Services;

use App\Models\Business;
use App\Support\AccessScope;
use App\Support\SmtpErrors;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * A provider's own email sending identity (their SMTP server), so marketing and
 * notification mail leaves from their address rather than Bookboll's.
 *
 * Stored on businesses.smtp_config, and — like the WhatsApp sender — the business it
 * belongs to is resolved from the CALLER, never named by the request.
 */
class EmailSenderService
{
    /** The shape the Email sender page edits. */
    public static function blankConfig(): array
    {
        return [
            'enabled'            => false,
            'from_name'          => '',
            'from_email'         => '',
            'reply_to'           => '',
            'host'               => '',
            'port'               => 587,
            'encryption'         => 'tls',
            'username'           => '',
            'password'           => '',
            'use_for_automated'  => true,
            'verified_at'        => null,
        ];
    }

    public function show(Request $request)
    {
        $business = $this->businessFor($request);

        return sendSuccess('Email sender', [
            'config' => $this->configFor($business),
        ]);
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'enabled'           => 'nullable|boolean',
            'from_name'         => 'nullable|string|max:120',
            'from_email'        => 'nullable|email|max:191',
            'reply_to'          => 'nullable|email|max:191',
            'host'              => 'nullable|string|max:191',
            'port'              => 'nullable|integer|min:1|max:65535',
            'encryption'        => 'nullable|string|in:tls,ssl,none',
            'username'          => 'nullable|string|max:191',
            'password'          => 'nullable|string|max:255',
            'use_for_automated' => 'nullable|boolean',
            'verified_at'       => 'nullable|string|max:64',
        ]);

        $business = $this->businessFor($request);
        if (! $business) {
            return sendError('No business found for this account.', [], 404);
        }

        $merged = array_merge($this->configFor($business), array_intersect_key($data, self::blankConfig()));
        $business->update(['smtp_config' => $merged]);

        return sendSuccess('Email sender saved', ['config' => $this->configFor($business->fresh())]);
    }

    /**
     * Actually open the provider's SMTP connection and deliver a message.
     *
     * The config comes from the REQUEST, not from storage, so the sender can be tested
     * before it is saved — which is the whole point of a test button. Failures are
     * returned verbatim from the mail layer: "authentication failed" and "connection
     * refused" are the two things a provider needs to tell apart, and paraphrasing
     * them helps nobody.
     */
    public function sendTest(Request $request)
    {
        $data = $request->validate([
            'to'         => 'required|email|max:191',
            'host'       => 'required|string|max:191',
            'port'       => 'required|integer',
            'encryption' => 'nullable|string|in:tls,ssl,none',
            'username'   => 'nullable|string|max:191',
            'password'   => 'nullable|string|max:255',
            'from_email' => 'required|email|max:191',
            'from_name'  => 'nullable|string|max:120',
        ]);

        if ($reason = $this->unsafeTarget($data['host'], (int) $data['port'])) {
            return sendError($reason, [], 422);
        }

        $fromName = $data['from_name'] ?: 'Bookboll';

        try {
            Mail::build([
                'transport'  => 'smtp',
                'host'       => $data['host'],
                'port'       => (int) $data['port'],
                // Symfony wants null for "no encryption"; 465 is implicit TLS ('ssl').
                'encryption' => ($data['encryption'] ?? 'tls') === 'none' ? null : ($data['encryption'] ?? 'tls'),
                'username'   => $data['username'] ?: null,
                'password'   => $data['password'] ?: null,
                // A wrong host must fail fast rather than hold the request open.
                'timeout'    => 15,
            ])->html(
                $this->testBody($fromName),
                fn ($m) => $m->to($data['to'])
                    ->from($data['from_email'], $fromName)
                    ->subject('Bookboll — test email from '.$fromName)
            );
        } catch (\Throwable $e) {
            Log::warning('SMTP test send failed', ['host' => $data['host'], 'error' => $e->getMessage()]);

            return sendError($this->readableSmtpError($e), ['smtp' => $e->getMessage()], 422);
        }

        // Only stamp the SAVED config as verified when the settings that were just
        // proven are the settings we hold — otherwise the badge would vouch for
        // credentials nobody tested.
        $this->markVerifiedIfMatches($request, $data);

        return sendSuccess('Test email sent', ['ok' => true, 'to' => $data['to']]);
    }

    protected function testBody(string $fromName): string
    {
        $safe = e($fromName);

        return '<div style="font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;'
            .'max-width:520px;margin:0 auto;padding:24px;color:#0f172a;">'
            .'<h2 style="margin:0 0 10px;font-size:19px;">Your email sender works 🎉</h2>'
            .'<p style="margin:0 0 12px;font-size:15px;line-height:1.6;color:#334155;">'
            .'This is a test message from <strong>'.$safe.'</strong>, sent through your own SMTP server '
            .'via Bookboll. If you are reading it, your settings are correct and your customers will '
            .'receive mail from this address.</p>'
            .'<p style="margin:0;font-size:12.5px;color:#94a3b8;">Sent by Bookboll · you can close this.</p>'
            .'</div>';
    }

    /**
     * The server opens this connection on the caller's behalf, so it must not become a
     * way to probe the private network. Providers legitimately use any public mail host,
     * so hosts stay open — but loopback/private/link-local addresses and non-mail ports
     * do not.
     */
    protected function unsafeTarget(string $host, int $port): ?string
    {
        if (! in_array($port, [25, 465, 587, 2525, 1025], true)) {
            return 'Use a standard SMTP port (587, 465, 2525 or 25).';
        }

        // Locally you test against Mailpit/MailHog on 127.0.0.1, and reaching your own
        // machine is not an attack. Anywhere else, private targets stay blocked.
        if (app()->isLocal()) {
            return null;
        }

        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (gethostbynamel($host) ?: []);
        if (! $ips) {
            return 'That mail server host could not be found. Check the spelling.';
        }

        foreach ($ips as $ip) {
            if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return 'That host resolves to a private address, which cannot be used as a mail server.';
            }
        }

        return null;
    }

    /** Turn the mail layer's exception into something a provider can act on. */
    protected function readableSmtpError(\Throwable $e): string
    {
        return SmtpErrors::readable($e);
    }

    /** @param array<string, mixed> $tested */
    protected function markVerifiedIfMatches(Request $request, array $tested): void
    {
        $business = $this->businessFor($request);
        if (! $business) {
            return;
        }

        $saved = $this->configFor($business);
        $same = $saved['host'] === $tested['host']
            && (int) $saved['port'] === (int) $tested['port']
            && (string) $saved['username'] === (string) ($tested['username'] ?? '');

        if ($same) {
            $business->update(['smtp_config' => array_merge($saved, ['verified_at' => now()->toIso8601String()])]);
        }
    }

    protected function businessFor(Request $request): ?Business
    {
        $ownerId = AccessScope::ownerUserId($request->user());

        return $ownerId === null ? null : Business::forOwnerId($ownerId);
    }

    protected function configFor(?Business $business): array
    {
        $saved = is_array($business?->smtp_config) ? $business->smtp_config : [];

        return array_merge(self::blankConfig(), $saved);
    }
}
