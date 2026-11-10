<?php

namespace App\Services;

use App\Models\HostPayoutAccount;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Nnjeim\World\Models\Country;
use Stripe\StripeClient;

/**
 * Getting an event host paid when their tickets are sold online.
 *
 * Two ways, and the host picks one:
 *
 *   connect — Bookboll creates a Stripe Express account for them and Stripe hosts
 *             the onboarding form. Joiners are charged on Bookboll's own account
 *             with the money transferred onward, so Bookboll's commission comes
 *             off automatically at the moment of payment.
 *
 *   keys    — the host supplies their own Stripe keys and joiners are charged
 *             straight on their account. Every riyal lands with them, so Bookboll's
 *             commission is NOT collected here and has to be billed separately.
 *
 * Either way the host's Stripe account has to say `charges_enabled` before we let
 * them sell anything — a connected account exists long before it may take money.
 *
 * An Express account is created IN A COUNTRY, and that country decides the whole
 * onboarding: which ID, which bank details, which currency it can be paid out in.
 * Stripe fixes it at creation and it can never be changed afterwards, so it has to
 * be right the first time. Left unset, Stripe falls back to the PLATFORM account's
 * own country, which handed every host — wherever they live — a US onboarding form.
 */
class HostPayoutService
{
    public function __construct(protected StripeService $stripe) {}

    /** What the host wizard needs to render the payouts box. */
    public function status(User $host)
    {
        $account = HostPayoutAccount::where('user_id', $host->id)->first();

        return sendSuccess('Payout account', ['account' => $this->map($account)]);
    }

    /**
     * Start (or resume) Express onboarding. Stripe hosts the form, so we hand back
     * a one-time link and the host finishes it there.
     */
    public function startConnect(User $host, string $returnUrl, string $refreshUrl, ?string $wantedCountry = null)
    {
        $client = $this->platformClient();

        if (! $client) {
            return sendError('Payouts are not configured. Please try again later.', [], 503);
        }

        $account = HostPayoutAccount::where('user_id', $host->id)->first();

        // Their own keys and a connected account are alternatives, not a stack.
        if ($account && $account->mode === 'keys') {
            return sendError('Disconnect your own Stripe keys first, then connect with Stripe.', [], 422);
        }

        try {
            if (! $account?->stripe_account_id) {
                $country = $this->resolveConnectCountry($host, $wantedCountry);

                if (! $country) {
                    // Distinguish "we don't know where you are" from "we know, and
                    // Stripe won't open an account there" — they need different
                    // things from the host.
                    $asked = strtoupper(trim((string) $wantedCountry));

                    if ($asked !== '') {
                        $name = Country::where('iso2', $asked)->value('name') ?: $asked;

                        return sendError(
                            "Stripe cannot open a payout account in {$name} for us. Pick another country, "
                            .'or connect your own Stripe account with your API keys instead.',
                            ['country' => ["{$name} is not available."]],
                            422
                        );
                    }

                    return sendError(
                        'Please choose the country your bank account is in so Stripe can set up the right onboarding.',
                        ['country' => ['Choose a country.']],
                        422
                    );
                }

                $created = $client->accounts->create([
                    'type'    => 'express',
                    // Without this Stripe uses the PLATFORM's country and every host
                    // is onboarded as if they banked there.
                    'country' => $country,
                    'email'   => $host->email,
                    // `business_type` is deliberately NOT set: it is not valid in
                    // every country (the UAE, for one, rejects 'individual'), and
                    // Stripe's own onboarding asks for it with the right options
                    // for wherever the host actually is.
                    'capabilities' => [
                        'card_payments' => ['requested' => true],
                        'transfers'     => ['requested' => true],
                    ],
                    'metadata' => ['bookboll_user_id' => (string) $host->id],
                ]);

                $account = HostPayoutAccount::updateOrCreate(
                    ['user_id' => $host->id],
                    [
                        'mode'              => 'connect',
                        'status'            => 'pending',
                        'stripe_account_id' => $created->id,
                        'country'           => $created->country,
                        'livemode'          => $this->platformIsLive(),
                        'connected_at'      => now(),
                    ]
                );
            }

            $link = $client->accountLinks->create([
                'account'     => $account->stripe_account_id,
                'return_url'  => $returnUrl,
                'refresh_url' => $refreshUrl,
                'type'        => 'account_onboarding',
            ]);
        } catch (\Stripe\Exception\InvalidRequestException $e) {
            // Stripe refuses a country plainly and says why — a US platform cannot
            // open a Qatari account, for instance. Passing that message through is
            // far more use to the host than "try again", and we drop the country
            // from the picker so the next host is never offered it.
            Log::warning('Stripe refused a connected account', [
                'user'    => $host->id,
                'country' => $country ?? null,
                'error'   => $e->getMessage(),
            ]);

            if (! empty($country)) {
                $this->rememberRefusedCountry($country);
            }

            return sendError($this->explainRefusal($e->getMessage(), $country ?? ''), ['country' => [$e->getMessage()]], 422);
        } catch (\Throwable $e) {
            Log::error('Stripe Connect onboarding failed', ['user' => $host->id, 'error' => $e->getMessage()]);

            return sendError('Could not start Stripe onboarding. Please try again.', [], 502);
        }

        return sendSuccess('Onboarding link created', [
            'url'     => $link->url,
            'account' => $this->map($account->fresh()),
        ]);
    }

    /**
     * Ask Stripe what the account can actually do. Called when the host returns
     * from onboarding — the link itself tells us nothing about the outcome.
     */
    public function syncConnect(User $host)
    {
        $account = HostPayoutAccount::where('user_id', $host->id)->where('mode', 'connect')->first();

        if (! $account?->stripe_account_id) {
            return sendError('No connected account to check.', [], 404);
        }

        $client = $this->platformClient();

        if (! $client) {
            return sendError('Payouts are not configured.', [], 503);
        }

        try {
            $remote = $client->accounts->retrieve($account->stripe_account_id);
        } catch (\Throwable $e) {
            Log::error('Stripe Connect sync failed', ['account' => $account->stripe_account_id, 'error' => $e->getMessage()]);

            return sendError('Could not reach Stripe. Please try again.', [], 502);
        }

        $account->update([
            'account_name'      => $remote->business_profile->name ?? $remote->email,
            'country'           => $remote->country,
            'livemode'          => $this->platformIsLive(),
            'charges_enabled'   => (bool) $remote->charges_enabled,
            'payouts_enabled'   => (bool) $remote->payouts_enabled,
            'details_submitted' => (bool) $remote->details_submitted,
            'status'            => $remote->charges_enabled ? 'active' : 'pending',
            'synced_at'         => now(),
        ]);

        return sendSuccess('Account refreshed', ['account' => $this->map($account->fresh())]);
    }

    /**
     * Save the host's own Stripe keys, but only after proving they work: the secret
     * has to authenticate against Stripe and the account has to be able to charge.
     */
    public function saveKeys(User $host, string $secretKey, ?string $publishableKey)
    {
        $secretKey = trim($secretKey);
        $publishableKey = trim((string) $publishableKey);

        if (! preg_match('/^sk_(test|live)_/', $secretKey)) {
            return sendError('That is not a Stripe secret key — it should start with sk_test_ or sk_live_.', [], 422);
        }

        if ($publishableKey !== '' && ! preg_match('/^pk_(test|live)_/', $publishableKey)) {
            return sendError('That is not a Stripe publishable key — it should start with pk_test_ or pk_live_.', [], 422);
        }

        // Prove the key is real and the account can take money, before storing it.
        try {
            $remote = (new StripeClient($secretKey))->accounts->retrieve();
        } catch (\Stripe\Exception\AuthenticationException) {
            return sendError('Stripe rejected that secret key. Check you copied it in full.', [], 422);
        } catch (\Throwable $e) {
            Log::error('Host Stripe key check failed', ['user' => $host->id, 'error' => $e->getMessage()]);

            return sendError('Could not verify that key with Stripe. Please try again.', [], 502);
        }

        if (! $remote->charges_enabled) {
            return sendError('That Stripe account cannot accept payments yet — finish its setup in Stripe first.', [], 422);
        }

        // Test and live keys must not be mixed, or the publishable key won't match
        // the charges the secret creates.
        $secretLive = str_starts_with($secretKey, 'sk_live_');

        if ($publishableKey !== '' && str_starts_with($publishableKey, 'pk_live_') !== $secretLive) {
            return sendError('Your secret and publishable keys are from different modes — use both test or both live.', [], 422);
        }

        $account = HostPayoutAccount::updateOrCreate(
            ['user_id' => $host->id],
            [
                'mode'              => 'keys',
                'status'            => 'active',
                'stripe_account_id' => $remote->id,
                'account_name'      => $remote->business_profile->name ?? $remote->email,
                'country'           => $remote->country,
                'livemode'          => $secretLive,
                'charges_enabled'   => (bool) $remote->charges_enabled,
                'payouts_enabled'   => (bool) $remote->payouts_enabled,
                'details_submitted' => true,
                'secret_key'        => $secretKey,
                'publishable_key'   => $publishableKey ?: null,
                'connected_at'      => now(),
                'synced_at'         => now(),
            ]
        );

        return sendSuccess('Stripe keys verified and saved', ['account' => $this->map($account->fresh())]);
    }

    /** Stop selling through this account. Stripe keeps the account; we forget it. */
    public function disconnect(User $host)
    {
        HostPayoutAccount::where('user_id', $host->id)->delete();

        return sendSuccess('Disconnected', ['account' => null]);
    }

    /**
     * How to charge a joiner for this host's ticket.
     *
     * Connect keeps the charge on Bookboll's account and transfers the host's share,
     * so `application_fee` is Bookboll's cut. Their own keys charge on their account
     * instead, where Bookboll cannot take a fee at all.
     *
     * @return array{client: StripeClient, destination: ?string, applicationFee: bool}|null
     */
    public function chargeContext(User $host): ?array
    {
        $account = HostPayoutAccount::where('user_id', $host->id)->first();

        if (! $account || ! $account->isReady()) {
            return null;
        }

        if ($account->mode === 'keys') {
            return [
                'client'         => new StripeClient($account->secret_key),
                'destination'    => null,
                'applicationFee' => false,
            ];
        }

        $client = $this->platformClient();

        return $client ? [
            'client'         => $client,
            'destination'    => $account->stripe_account_id,
            'applicationFee' => true,
        ] : null;
    }

    /**
     * The countries a host may open a Stripe account in, for the picker.
     *
     * Read from Stripe itself (`country_specs`) rather than a list we maintain by
     * hand, so it can never drift out of date — Stripe adds countries regularly and
     * a stale list would either hide a country that works or offer one that fails
     * half-way through onboarding. Cached for a day; the list moves rarely.
     */
    public function connectCountries(User $host)
    {
        $account = HostPayoutAccount::where('user_id', $host->id)->first();

        $supported = $this->supportedCountries();

        if ($supported === []) {
            return sendError('Could not reach Stripe for the country list. Please try again.', [], 502);
        }

        $names = Country::whereIn('iso2', $supported)
            ->pluck('name', 'iso2')
            ->map(fn ($n) => (string) $n)
            ->all();

        $countries = collect($supported)
            ->map(fn (string $code) => ['code' => $code, 'name' => $names[$code] ?? $code])
            ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)
            ->values()
            ->all();

        $guess = $this->guessCountry($host);

        return sendSuccess('Stripe countries', [
            'countries' => $countries,
            'default'   => $this->resolveConnectCountry($host, null),
            // Where we think they are, and whether Stripe will open an account
            // there — so the page can say why the picker may be no use to them.
            'detected'  => $guess,
            'detected_name' => $guess ? (Country::where('iso2', $guess)->value('name') ?: $guess) : null,
            'detected_supported' => $guess ? in_array($guess, $supported, true) : null,
            // Stripe fixes an account's country at creation, so once one exists the
            // choice is gone — the host has to disconnect to start over elsewhere.
            'locked'    => $account?->stripe_account_id ? ($account->country ?: null) : null,
        ]);
    }

    /**
     * Can this host actually sell tickets through their own Stripe right now?
     *
     * The only honest source is the account Stripe has verified — never a flag the
     * client sent us. Waiving Bookboll's seats fee on the strength of a request
     * field let anyone host for free by posting `ticket_mode=stripe`.
     */
    public function hasReadyAccount(User $host): bool
    {
        return (bool) HostPayoutAccount::where('user_id', $host->id)->first()?->isReady();
    }

    /**
     * Where we think this host is, whether or not Stripe will open an account
     * there. The picker needs this to explain itself: a host in Pakistan should be
     * told Stripe cannot open a Pakistani account and pointed at their own keys,
     * not shown a bare list of forty-four countries they have no connection to.
     */
    public function guessCountry(User $host): ?string
    {
        if ($host->country_id) {
            $iso = strtoupper((string) Country::find($host->country_id)?->iso2);

            if ($iso !== '') {
                return $iso;
            }
        }

        return $this->countryFromPhone($host->phone);
    }

    /**
     * ISO-2 codes this platform can actually open a connected account in.
     *
     * Not the same as "countries Stripe supports": which ones work depends on the
     * PLATFORM's own country and what Stripe has approved it for. A US platform is
     * refused in South Africa, Pakistan and Qatar, even though Stripe lists all
     * three — so offering Stripe's full list means a host completes the form and
     * only then gets "You cannot request the `card_payments` capability".
     *
     * The answer lives on the country row, seeded by migration, so every
     * environment agrees without anyone having to run a command first.
     * `stripe:sync-connect-countries` re-verifies it against Stripe.
     *
     * @return array<int,string>
     */
    public function supportedCountries(): array
    {
        $flagged = Country::where('stripe_connect_supported', true)
            ->orderBy('iso2')
            ->pluck('iso2')
            ->map(fn ($c) => strtoupper((string) $c))
            ->filter()
            ->values()
            ->all();

        if ($flagged !== []) {
            return $flagged;
        }

        // Nothing flagged at all — the migration has not run, or a world re-seed
        // cleared it. Fall back to everything Stripe lists so hosting still works,
        // and say so, because some of those countries will be refused.
        Log::warning('No countries flagged for Stripe Connect; falling back to Stripe\'s full list. Run stripe:sync-connect-countries --apply.');

        return $this->stripeListedCountries();
    }

    /** Every country Stripe operates in — the outer bound, not what works for us. */
    protected function stripeListedCountries(): array
    {
        return Cache::remember('stripe.connect.country_specs', now()->addDay(), function () {
            $client = $this->platformClient();

            if (! $client) {
                return [];
            }

            try {
                $codes = [];

                // 100 per page and there are more than that — page through them all
                // rather than silently cutting the list off at the first page.
                foreach ($client->countrySpecs->all(['limit' => 100])->autoPagingIterator() as $spec) {
                    $codes[] = strtoupper((string) $spec->id);
                }

                sort($codes);

                return array_values(array_unique(array_filter($codes)));
            } catch (\Throwable $e) {
                Log::error('Could not load Stripe country specs', ['error' => $e->getMessage()]);

                return [];
            }
        });
    }

    /**
     * Record what Stripe accepted. Anything not in the list is marked unsupported,
     * so a country Stripe has stopped accepting disappears from the picker too.
     */
    public function storeSupportedCountries(array $codes): void
    {
        $codes = array_values(array_unique(array_filter(array_map(
            fn ($c) => strtoupper(trim((string) $c)),
            $codes
        ))));

        if ($codes === []) {
            return;   // never blank the whole table on an empty result
        }

        DB::transaction(function () use ($codes) {
            Country::query()->update(['stripe_connect_supported' => false]);

            Country::whereIn('iso2', $codes)->update([
                'stripe_connect_supported'  => true,
                'stripe_connect_checked_at' => now(),
            ]);
        });
    }

    /** Stripe turned this country down — take it off the list for everyone else. */
    protected function rememberRefusedCountry(string $code): void
    {
        $code = strtoupper(trim($code));

        if ($code === '') {
            return;
        }

        Country::where('iso2', $code)->update([
            'stripe_connect_supported'  => false,
            'stripe_connect_checked_at' => now(),
        ]);
    }

    /**
     * Which country to open the host's account in.
     *
     * What the host picked wins. Failing that we work it out from what we already
     * know about them — their profile country, then their phone's dial code — and
     * if none of that lands we return null and ask, rather than quietly defaulting
     * to the platform's country and giving them the wrong onboarding.
     */
    protected function resolveConnectCountry(User $host, ?string $wanted): ?string
    {
        $supported = $this->supportedCountries();

        $ok = function (?string $code) use ($supported): ?string {
            $code = strtoupper(trim((string) $code));

            return $code !== '' && in_array($code, $supported, true) ? $code : null;
        };

        if ($picked = $ok($wanted)) {
            return $picked;
        }

        if ($host->country_id && ($fromProfile = $ok(Country::find($host->country_id)?->iso2))) {
            return $fromProfile;
        }

        return $ok($this->countryFromPhone($host->phone));
    }

    /**
     * The country a phone number belongs to, by dial code. Longest code first so
     * +1868 (Trinidad) is not read as +1 (US), and a shared code like +1 or +44
     * is left undecided rather than guessed — the host picks in that case.
     */
    protected function countryFromPhone(?string $phone): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $phone);

        if ($digits === '' || $digits === null) {
            return null;
        }

        $byCode = Cache::remember('world.phone_codes', now()->addDay(), function () {
            return Country::whereNotNull('phone_code')
                ->get(['iso2', 'phone_code'])
                ->groupBy(fn ($c) => preg_replace('/\D+/', '', (string) $c->phone_code))
                ->map(fn ($g) => $g->pluck('iso2')->filter()->values()->all())
                ->filter(fn ($codes, $dial) => $dial !== '')
                ->all();
        });

        for ($len = 4; $len >= 1; $len--) {
            $prefix = substr($digits, 0, $len);
            $match  = $byCode[$prefix] ?? null;

            // Exactly one country on this dial code — anything else is a guess.
            if (is_array($match) && count($match) === 1) {
                return strtoupper($match[0]);
            }
        }

        return null;
    }

    /**
     * Turn Stripe's refusal into something a host can act on. The underlying
     * message is still returned alongside, so nothing is hidden.
     */
    protected function explainRefusal(string $stripeMessage, string $country): string
    {
        $name = $country !== ''
            ? (Country::where('iso2', $country)->value('name') ?: $country)
            : 'that country';

        // Not about the country at all: the PLATFORM account has never switched
        // Connect on. Only the Stripe dashboard can fix that, so say so plainly
        // rather than blaming the country the host chose.
        if (str_contains($stripeMessage, 'signed up for Connect')) {
            Log::error('Stripe Connect is not enabled on the platform account — enable it at https://dashboard.stripe.com/connect');

            return 'Online payouts are not switched on for Bookboll yet (Stripe Connect is not enabled on '
                .'the platform account). Please try again later, or collect payment on arrival.';
        }

        if (str_contains($stripeMessage, 'card_payments') || str_contains($stripeMessage, 'service agreement')) {
            return "Stripe cannot open a payout account in {$name} for us yet. Pick another country, "
                .'or connect your own Stripe account with your API keys instead.';
        }

        if (str_contains($stripeMessage, 'cannot be created by platforms')) {
            return "Stripe does not allow us to open accounts in {$name}. Pick another country, "
                .'or connect your own Stripe account with your API keys instead.';
        }

        return "Stripe could not open a payout account in {$name}: ".$stripeMessage;
    }

    /** Whether Bookboll's own Stripe keys are live ones. */
    protected function platformIsLive(): bool
    {
        return str_starts_with(trim((string) config('services.stripe.secret')), 'sk_live_');
    }

    protected function platformClient(): ?StripeClient
    {
        $secret = trim((string) config('services.stripe.secret'));

        return $secret === '' ? null : new StripeClient($secret);
    }

    /** Safe shape for the storefront — never includes the secret key. */
    protected function map(?HostPayoutAccount $a): ?array
    {
        if (! $a) {
            return null;
        }

        return [
            'id'                => $a->uuid,
            'mode'              => $a->mode,
            'status'            => $a->status,
            'account_id'        => $a->stripe_account_id,
            'account_name'      => $a->account_name ?? '',
            'country'           => $a->country ?? '',
            'livemode'          => $a->livemode,
            'charges_enabled'   => $a->charges_enabled,
            'payouts_enabled'   => $a->payouts_enabled,
            'details_submitted' => $a->details_submitted,
            'publishable_key'   => $a->publishable_key ?? '',
            'ready'             => $a->isReady(),
            'connected_at'      => optional($a->connected_at)?->toIso8601String(),
        ];
    }
}
