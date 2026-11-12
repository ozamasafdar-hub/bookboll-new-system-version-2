<?php

namespace App\Services;

use App\Models\Business;
use App\Models\EmailOutbound;
use App\Models\User;
use App\Support\AccessScope;
use Illuminate\Mail\Mailer;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Actually delivering email — marketing campaigns and automated notifications.
 *
 * WHO IT SENDS AS
 *   A provider sends from THEIR OWN SMTP server (businesses.smtp_config). Bookboll
 *   sends from the platform mailer. A provider marketing their own list from
 *   Bookboll's shared address is not allowed — it would put the platform's sending
 *   reputation behind mail nobody at Bookboll wrote.
 *
 * WHO IT SENDS TO
 *   Resolved server-side and narrowed twice:
 *     1. to customers who have actually transacted with THAT provider, and
 *     2. to those who opted in to marketing email.
 *   The browser sends a list of ids, not addresses, and that list can only ever be
 *   narrowed here — never widened. Both checks are compliance requirements, not
 *   conveniences, so neither can live in the dashboard.
 *
 * EVERY RECIPIENT GETS THEIR OWN UNSUBSCRIBE LINK
 *   The token is per-customer, so the HTML is rendered per recipient. Sending one
 *   pre-rendered body to everyone would give the whole list the FIRST recipient's
 *   unsubscribe link — one click would silently opt somebody else out.
 */
class MarketingMailService
{
    /** Sent per queue job run, so one huge campaign cannot monopolise a worker. */
    public const CHUNK = 50;

    /* ─────────────────────────── Sender resolution ────────────────────────── */

    /**
     * The mailer to send as, plus the identity to put on the envelope.
     *
     * @return array{mailer: Mailer, from_email: string, from_name: string, reply_to: ?string, owner_user_id: ?int, is_provider: bool}|array{error: string}
     */
    public function senderFor(?int $ownerId, bool $requireProviderSmtp = true): array
    {
        if ($ownerId === null) {
            return [
                'mailer'        => Mail::mailer(),
                'from_email'    => (string) config('mail.from.address'),
                'from_name'     => (string) config('mail.from.name', 'Bookboll'),
                'reply_to'      => null,
                'owner_user_id' => null,
                'is_provider'   => false,
            ];
        }

        $business = Business::forOwnerId($ownerId);
        $cfg = is_array($business?->smtp_config) ? $business->smtp_config : [];

        $ready = ! empty($cfg['enabled'])
            && ! empty($cfg['host'])
            && ! empty($cfg['from_email']);

        if (! $ready) {
            if ($requireProviderSmtp) {
                return ['error' => 'Connect your own email address on the Email sender page before sending marketing emails.'];
            }

            // Automated notifications may fall back to Bookboll — they are service
            // messages the platform is entitled to send on the provider's behalf.
            return $this->senderFor(null);
        }

        return [
            'mailer'        => Mail::build([
                'transport'  => 'smtp',
                'host'       => $cfg['host'],
                'port'       => (int) ($cfg['port'] ?: 587),
                'encryption' => ($cfg['encryption'] ?? 'tls') === 'none' ? null : ($cfg['encryption'] ?? 'tls'),
                'username'   => $cfg['username'] ?: null,
                'password'   => $cfg['password'] ?: null,
                'timeout'    => 20,
            ]),
            'from_email'    => (string) $cfg['from_email'],
            'from_name'     => (string) ($cfg['from_name'] ?: ($business->name ?: 'Bookboll')),
            'reply_to'      => $cfg['reply_to'] ?: null,
            'owner_user_id' => $ownerId,
            'is_provider'   => true,
        ];
    }

    /** Does this provider's own sender also carry their automated notifications? */
    public function usesOwnSenderForAutomated(?int $ownerId): bool
    {
        if ($ownerId === null) {
            return false;
        }
        $business = Business::forOwnerId($ownerId);
        $cfg = is_array($business?->smtp_config) ? $business->smtp_config : [];

        return ! empty($cfg['enabled']) && ! empty($cfg['host']) && ($cfg['use_for_automated'] ?? true);
    }

    /* ───────────────────────────── Recipients ─────────────────────────────── */

    /**
     * The customers this caller may reach — the SAME definition the Customers list
     * uses (AccessScope::customerIdsInScope). Keeping one rule is the point: a
     * provider must never be able to email someone the list would not show them.
     *
     * @return array<int, int>|null  null = unrestricted (global admin)
     */
    public function customerIdsInScope(?User $user): ?array
    {
        return AccessScope::customerIdsInScope($user);
    }

    /**
     * Narrow a requested id list to who may actually be emailed.
     *
     * @param  array<int, mixed>  $requestedIds
     * @return \Illuminate\Support\Collection<int, User>
     */
    public function eligibleRecipients(?User $user, array $requestedIds)
    {
        $ids = collect($requestedIds)->map(fn ($i) => (int) $i)->filter()->unique();
        if ($ids->isEmpty()) {
            return collect();
        }

        // Narrowed to what this caller can see at all — a global admin is unrestricted.
        $scope = $this->customerIdsInScope($user);
        if ($scope !== null) {
            $ids = $ids->intersect($scope);
            if ($ids->isEmpty()) {
                return collect();
            }
        }

        return User::whereIn('id', $ids)
            ->whereHas('roles', fn ($q) => $q->where('name', 'customer'))
            ->where('marketing_email', true)          // consent, re-checked server-side
            ->whereNotNull('email')
            ->where('email', '!=', '')
            ->get(['id', 'name', 'email', 'unsubscribe_token']);
    }

    /* ─────────────────────────── Compliant HTML ───────────────────────────── */

    /** One-click unsubscribe link for a customer + channel. */
    public function unsubscribeUrl(?string $token, string $channel = 'email'): string
    {
        $base = rtrim((string) config('app.frontend_url', 'https://bookboll.com'), '/');

        return $base.'/unsubscribe?t='.rawurlencode((string) $token).'&ch='.rawurlencode($channel);
    }

    /**
     * The marketing email itself, with the two things the law requires: who really
     * sent it, and a working way out. Ported from the dashboard's marketingEmailHtml
     * so the preview a provider approves is the message that goes out.
     */
    public function renderHtml(array $o): string
    {
        $subject   = (string) ($o['subject'] ?? '');
        $body      = (string) ($o['body'] ?? '');
        $sender    = (string) ($o['sender_label'] ?? 'Bookboll');
        $isProv    = (bool) ($o['is_provider'] ?? false);
        $color     = (string) ($o['brand_color'] ?? '#0f766e');
        $unsubUrl  = (string) ($o['unsub_url'] ?? '#');

        $safeColor = preg_match('/^#[0-9a-fA-F]{3,8}$/', $color) ? $color : '#0f766e';

        $paras = collect(preg_split('/\n{2,}/', $body))
            ->map(fn ($p) => '<p style="margin:0 0 14px;font-size:15px;line-height:1.6;color:#334155;">'
                .nl2br(e($p)).'</p>')
            ->implode('');

        $disclaimer = $isProv
            ? 'This email was sent by <strong>'.e($sender).'</strong> via the <strong>Bookboll</strong> platform.'
            : 'This email was sent by <strong>Bookboll</strong>.';

        $heading = $subject !== ''
            ? '<h1 style="margin:0 0 14px;font-size:21px;font-weight:800;color:#0f172a;">'.e($subject).'</h1>'
            : '';

        return '<!doctype html><html><body style="margin:0;padding:0;background:#f1f5f9;">
  <div style="max-width:560px;margin:0 auto;padding:24px 16px;font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;">
    <div style="background:#fff;border-radius:16px;overflow:hidden;border:1px solid #e2e8f0;">
      <div style="background:'.$safeColor.';padding:18px 24px;">
        <span style="color:#fff;font-size:18px;font-weight:800;letter-spacing:.2px;">'.e($sender).'</span>
      </div>
      <div style="padding:24px;">
        '.$heading.'
        '.($paras ?: '<p style="color:#94a3b8;">(no message)</p>').'
      </div>
    </div>
    <div style="text-align:center;padding:18px 12px 4px;font-size:12px;line-height:1.6;color:#94a3b8;">
      <p style="margin:0 0 12px;">'.$disclaimer.'</p>
      <a href="'.e($unsubUrl).'" style="display:inline-block;padding:9px 18px;border:1px solid #cbd5e1;border-radius:999px;color:#475569;text-decoration:none;font-weight:700;font-size:12.5px;">Unsubscribe from marketing emails</a>
      <p style="margin:14px 0 0;">You\'re receiving this because you opted in to marketing from '.e($sender).' on Bookboll.</p>
    </div>
  </div></body></html>';
    }

    /* ──────────────────────────── Delivery ────────────────────────────────── */

    /**
     * Send one message and record the outcome. Never throws: one bad address must not
     * abandon the rest of a campaign.
     */
    public function deliver(array $sender, string $to, string $subject, string $html, array $meta = []): bool
    {
        $ok = true;
        $error = null;

        try {
            $sender['mailer']->html($html, function ($m) use ($to, $subject, $sender) {
                $m->to($to)->subject($subject)->from($sender['from_email'], $sender['from_name']);
                if (! empty($sender['reply_to'])) {
                    $m->replyTo($sender['reply_to']);
                }
            });
        } catch (\Throwable $e) {
            $ok = false;
            $error = $e->getMessage();
            Log::warning('Email delivery failed', ['to' => $to, 'error' => $error]);
        }

        try {
            EmailOutbound::create([
                'owner_user_id' => $sender['owner_user_id'],
                'to_email'      => $to,
                'customer_id'   => $meta['customer_id'] ?? null,
                'campaign_id'   => $meta['campaign_id'] ?? null,
                'kind'          => $meta['kind'] ?? 'campaign',
                'subject'       => mb_strimwidth($subject, 0, 190, ''),
                'status'        => $ok ? 'sent' : 'failed',
                'error'         => $error ? mb_strimwidth($error, 0, 500, '…') : null,
            ]);
        } catch (\Throwable $e) {
            Log::error('email_outbound insert failed: '.$e->getMessage());
        }

        return $ok;
    }

    /**
     * An automated service notification (booking confirmed, reminder, receipt).
     *
     * NOT gated on marketing consent — a booking confirmation is not marketing, and a
     * customer who opted out of offers still needs their receipt. It rides the
     * provider's own sender when they have one and asked for it, otherwise Bookboll's,
     * so a provider without SMTP still gets their notifications delivered.
     */
    public function sendNotification(?int $ownerId, string $to, string $subject, string $bodyText): array
    {
        $to = trim($to);
        if ($to === '' || ! filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'error' => 'A valid recipient address is required.'];
        }
        if (trim($subject) === '') {
            return ['ok' => false, 'error' => 'A subject is required.'];
        }

        $useOwn = $this->usesOwnSenderForAutomated($ownerId);
        $sender = $this->senderFor($useOwn ? $ownerId : null, requireProviderSmtp: false);
        if (isset($sender['error'])) {
            return ['ok' => false, 'error' => $sender['error']];
        }

        $html = $this->renderNotificationHtml($sender['from_name'], $subject, $bodyText);
        $ok = $this->deliver($sender, $to, $subject, $html, ['kind' => 'notification']);

        return $ok ? ['ok' => true] : ['ok' => false, 'error' => 'The message could not be delivered.'];
    }

    /**
     * A service message carries no unsubscribe link — it is not marketing, and offering
     * to opt out of your own booking confirmation would be misleading.
     */
    protected function renderNotificationHtml(string $senderName, string $subject, string $body): string
    {
        $paras = collect(preg_split('/\n{2,}/', $body))
            ->map(fn ($p) => '<p style="margin:0 0 12px;font-size:15px;line-height:1.6;color:#334155;">'.nl2br(e($p)).'</p>')
            ->implode('');

        return '<!doctype html><html><body style="margin:0;padding:0;background:#f1f5f9;">
  <div style="max-width:520px;margin:0 auto;padding:24px 16px;font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;">
    <div style="background:#fff;border-radius:14px;border:1px solid #e2e8f0;padding:24px;">
      <h1 style="margin:0 0 14px;font-size:19px;font-weight:700;color:#0f172a;">'.e($subject).'</h1>
      '.($paras ?: '').'
    </div>
    <p style="text-align:center;margin:16px 0 0;font-size:12px;color:#94a3b8;">Sent by '.e($senderName).' via Bookboll.</p>
  </div></body></html>';
    }

    /** The provider's storefront accent, so their email matches their site. */
    public function brandColor(?int $ownerId): string
    {
        if ($ownerId === null) {
            return '#0f766e';
        }
        $primary = Business::forOwnerId($ownerId)?->brand_primary;

        return (is_string($primary) && preg_match('/^#[0-9a-fA-F]{3,8}$/', $primary)) ? $primary : '#0f766e';
    }

    /** The owner whose sender/scope applies to this caller. */
    public function ownerFor(?User $user): ?int
    {
        return $user ? AccessScope::ownerUserId($user) : null;
    }
}
