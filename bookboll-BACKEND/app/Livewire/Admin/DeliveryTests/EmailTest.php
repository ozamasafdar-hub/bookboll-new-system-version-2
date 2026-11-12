<?php

namespace App\Livewire\Admin\DeliveryTests;

use App\Livewire\Admin\Concerns\RequiresSuperAdmin;
use App\Mail\AdminDeliveryTestMail;
use App\Models\User;
use App\Support\SmtpErrors;
use Illuminate\Contracts\Mail\Mailer;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

/**
 * Send a real email and report what happened, per recipient.
 *
 * Two senders are available. "Platform mailer" is whatever `config('mail')`
 * says — the same transport every booking confirmation leaves through, so a
 * green result here means the live setup works. "Custom SMTP" opens a
 * connection with settings typed into the form, for trying a new host or a
 * rotated password before anyone touches `.env`.
 *
 * Recipients are existing users (picked by name or email) and/or addresses
 * typed in by hand. Each recipient gets its own message, so one bad address
 * does not hide a working server, and one bad server is reported once per
 * address with the mail layer's reason translated into plain words.
 */
class EmailTest extends Component
{
    use RequiresSuperAdmin;

    public const MAX_RECIPIENTS = 20;

    public const ENCRYPTIONS = ['tls', 'ssl', 'none'];

    public string $subject = 'Bookboll SMTP test';

    public string $body = "This is a test email from the Bookboll admin panel.\n\nIf you are reading it, outbound email is working.";

    /* ---- recipients ------------------------------------------------- */

    public string $userSearch = '';

    /** user id => ['id', 'name', 'email'] */
    public array $selectedUsers = [];

    /** Free-text addresses: comma, semicolon, whitespace or newline separated. */
    public string $emails = '';

    /* ---- sender ----------------------------------------------------- */

    public bool $useCustomSmtp = false;

    public string $smtpHost = '';

    public string $smtpPort = '587';

    public string $smtpEncryption = 'tls';

    public string $smtpUsername = '';

    public string $smtpPassword = '';

    public string $fromEmail = '';

    public string $fromName = '';

    /* ---- outcome ---------------------------------------------------- */

    /** @var array<int, array{to: string, ok: bool, message: string}> */
    public array $results = [];

    public ?string $statusMessage = null;

    public ?string $errorMessage = null;

    public function mount(): void
    {
        $this->fromEmail = (string) config('mail.from.address', '');
        $this->fromName = (string) config('mail.from.name', '');
    }

    /* ---------------------------------------------------------------------
     | Recipients
     --------------------------------------------------------------------- */

    public function addUser(int $userId): void
    {
        $user = User::query()->whereNotNull('email')->find($userId, ['id', 'name', 'email']);

        if ($user === null) {
            return;
        }

        $this->selectedUsers[$user->id] = [
            'id' => $user->id,
            'name' => (string) $user->name,
            'email' => (string) $user->email,
        ];

        $this->userSearch = '';
    }

    public function removeUser(int $userId): void
    {
        unset($this->selectedUsers[$userId]);
    }

    /** The quickest useful test: send it to the person clicking the button. */
    public function addMyself(): void
    {
        $this->addUser((int) Auth::id());
    }

    public function clearRecipients(): void
    {
        $this->selectedUsers = [];
        $this->emails = '';
    }

    /** @return array<int, array{id: int, name: string, email: string}> */
    private function userMatches(): array
    {
        $term = trim($this->userSearch);

        if (mb_strlen($term) < 2) {
            return [];
        }

        $like = '%'.addcslashes($term, '%_\\').'%';

        return User::query()
            ->whereNotNull('email')
            ->whereKeyNot(array_keys($this->selectedUsers) ?: [0])
            ->where(function ($q) use ($like, $term) {
                $q->where('name', 'like', $like)
                    ->orWhere('email', 'like', $like)
                    ->when(ctype_digit($term), fn ($q) => $q->orWhere('id', (int) $term));
            })
            ->orderBy('name')
            ->limit(8)
            ->get(['id', 'name', 'email'])
            ->map(fn (User $u) => ['id' => $u->id, 'name' => (string) $u->name, 'email' => (string) $u->email])
            ->all();
    }

    /** @return array<int, string> typed-in addresses, lower-cased and de-duplicated, invalid ones included */
    private function typedEmails(): array
    {
        $parts = preg_split('/[\s,;]+/', $this->emails, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_values(array_unique(array_map(fn (string $e) => mb_strtolower(trim($e)), $parts)));
    }

    /** @return array<int, string> every address the send would go to */
    private function recipients(): array
    {
        $fromUsers = array_map(fn (array $u) => mb_strtolower($u['email']), $this->selectedUsers);

        return array_values(array_unique(array_merge($fromUsers, $this->typedEmails())));
    }

    /* ---------------------------------------------------------------------
     | Sending
     --------------------------------------------------------------------- */

    public function send(): void
    {
        $this->results = [];
        $this->statusMessage = null;
        $this->errorMessage = null;

        $this->validate($this->rules(), [], [
            'smtpHost' => 'host',
            'smtpPort' => 'port',
            'smtpUsername' => 'username',
            'smtpPassword' => 'password',
            'fromEmail' => 'from address',
            'fromName' => 'from name',
        ]);

        $invalid = array_filter($this->typedEmails(), fn (string $e) => filter_var($e, FILTER_VALIDATE_EMAIL) === false);
        if ($invalid !== []) {
            throw ValidationException::withMessages([
                'emails' => 'Not a valid address: '.implode(', ', $invalid),
            ]);
        }

        $recipients = $this->recipients();

        if ($recipients === []) {
            throw ValidationException::withMessages([
                'emails' => 'Pick at least one user or type an address.',
            ]);
        }

        if (count($recipients) > self::MAX_RECIPIENTS) {
            throw ValidationException::withMessages([
                'emails' => 'A test goes to at most '.self::MAX_RECIPIENTS.' addresses at once; this one has '.count($recipients).'.',
            ]);
        }

        try {
            $mailer = $this->mailer();
        } catch (\Throwable $e) {
            $this->errorMessage = 'Could not set up the mailer: '.SmtpErrors::readable($e);

            return;
        }

        $sentBy = trim((string) (Auth::user()->name ?? '')).' <'.Auth::user()->email.'>';
        $label = $this->mailerLabel();

        foreach ($recipients as $to) {
            $mailable = new AdminDeliveryTestMail($this->subject, $this->body, $sentBy, $label);

            if ($this->useCustomSmtp && $this->fromEmail !== '') {
                $mailable->from($this->fromEmail, $this->fromName !== '' ? $this->fromName : null);
            }

            try {
                $mailer->to($to)->send($mailable);

                $this->results[] = ['to' => $to, 'ok' => true, 'message' => 'Accepted by the mail server.'];
            } catch (\Throwable $e) {
                Log::warning('Admin delivery test: email failed', ['to' => $to, 'mailer' => $label, 'error' => $e->getMessage()]);

                $this->results[] = ['to' => $to, 'ok' => false, 'message' => SmtpErrors::readable($e)];
            }
        }

        $ok = count(array_filter($this->results, fn (array $r) => $r['ok']));
        $failed = count($this->results) - $ok;

        activity('delivery_test')
            ->causedBy(Auth::user())
            ->withProperties([
                'channel' => 'email',
                'mailer' => $label,
                'custom_smtp' => $this->useCustomSmtp,
                'host' => $this->useCustomSmtp ? $this->smtpHost : (string) config('mail.mailers.'.config('mail.default').'.host', ''),
                'subject' => $this->subject,
                'recipients' => $recipients,
                'sent' => $ok,
                'failed' => $failed,
            ])
            ->log("Sent a test email to {$ok} of ".count($recipients).' address(es) via '.$label);

        if ($failed === 0) {
            $this->statusMessage = "Sent to {$ok} address".($ok === 1 ? '' : 'es').' via '.$label.'. Check the inbox (and spam folder).';
        } elseif ($ok === 0) {
            $this->errorMessage = 'Nothing was sent. The mail server turned down every message — see the reasons below.';
        } else {
            $this->errorMessage = "{$ok} sent, {$failed} failed — see below.";
        }
    }

    /** @return array<string, string> */
    private function rules(): array
    {
        $rules = [
            'subject' => 'required|string|max:150',
            'body' => 'required|string|max:5000',
            'emails' => 'nullable|string|max:5000',
        ];

        if ($this->useCustomSmtp) {
            $rules += [
                'smtpHost' => 'required|string|max:191',
                'smtpPort' => 'required|integer|min:1|max:65535',
                'smtpEncryption' => 'required|in:'.implode(',', self::ENCRYPTIONS),
                'smtpUsername' => 'nullable|string|max:191',
                'smtpPassword' => 'nullable|string|max:255',
                'fromEmail' => 'required|email|max:191',
                'fromName' => 'nullable|string|max:120',
            ];
        }

        return $rules;
    }

    private function mailer(): Mailer
    {
        if (! $this->useCustomSmtp) {
            return Mail::mailer();
        }

        $port = (int) $this->smtpPort;

        return Mail::build([
            'transport' => 'smtp',
            'host' => trim($this->smtpHost),
            'port' => $port,
            // Laravel picks implicit TLS from the port alone; say so explicitly for
            // "ssl" on a non-standard port and leave 587/25 to STARTTLS.
            'scheme' => $this->smtpEncryption === 'ssl' ? 'smtps' : 'smtp',
            'username' => $this->smtpUsername !== '' ? $this->smtpUsername : null,
            'password' => $this->smtpPassword !== '' ? $this->smtpPassword : null,
            // A wrong host must fail fast rather than hold the request open.
            'timeout' => 15,
        ]);
    }

    private function mailerLabel(): string
    {
        if ($this->useCustomSmtp) {
            return 'custom SMTP ('.trim($this->smtpHost).':'.$this->smtpPort.')';
        }

        $name = (string) config('mail.default', 'smtp');
        $host = config("mail.mailers.{$name}.host");

        return $host ? "{$name} ({$host})" : $name;
    }

    /**
     * What the platform mailer is right now, with the password hidden. Shown
     * beside the form so a wrong `.env` is visible before the first send.
     *
     * @return array<string, string>
     */
    private function platformMailer(): array
    {
        $name = (string) config('mail.default', '');
        $cfg = (array) config("mail.mailers.{$name}", []);

        $scheme = (string) ($cfg['scheme'] ?? '');
        $port = $cfg['port'] ?? null;

        if ($scheme !== '') {
            $encryption = $scheme;
        } elseif (($cfg['transport'] ?? '') !== 'smtp') {
            $encryption = '—';
        } elseif ($port !== null && (int) $port === 465) {
            $encryption = 'smtps (implicit TLS)';
        } else {
            $encryption = 'smtp (STARTTLS if offered)';
        }

        $username = (string) ($cfg['username'] ?? '');
        $password = (string) ($cfg['password'] ?? '');

        return [
            'mailer' => $name !== '' ? $name : '—',
            'transport' => (string) ($cfg['transport'] ?? '—'),
            'host' => (string) ($cfg['host'] ?? '—'),
            'port' => $port !== null ? (string) $port : '—',
            'encryption' => $encryption,
            'username' => $username !== '' ? $username : '—',
            'password' => $password !== '' ? str_repeat('•', min(12, max(6, strlen($password)))) : 'not set',
            'from' => trim((string) config('mail.from.name', '').' <'.config('mail.from.address', '').'>'),
        ];
    }

    public function render()
    {
        $recipients = $this->recipients();

        return view('livewire.admin.delivery-tests.email-test', [
            'userMatches' => $this->userMatches(),
            'recipientCount' => count($recipients),
            'platformMailer' => $this->platformMailer(),
            'maxRecipients' => self::MAX_RECIPIENTS,
        ]);
    }
}
