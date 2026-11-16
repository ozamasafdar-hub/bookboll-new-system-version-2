<?php

namespace App\Services;

use App\Support\AdminAlerts;

use App\Models\Business;
use App\Models\BusinessMembership;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Nnjeim\World\Models\Country;

class BusinessService
{

    public function __construct(
        private User $user,
        private Business $business,
        private UserService $userService,
        private EmailVerificationService $emailVerification,
    ) {}

    /**
     * Subdomains a provider may never take, because their white-label site is
     * served at <username>.bookboll.com.
     *
     * Two separate reasons:
     *   • Ours — www / app / admin / api / business / staging / dev already point
     *     at Bookboll's own hosts. The storefront treats these as the apex
     *     (apps/web lib/brand.js RESERVED_SUBS), so such a site would never even
     *     resolve — the visitor would land on the marketplace instead.
     *   • Infrastructure — mail/smtp/ns1/… are conventional records; handing one
     *     to a provider risks breaking mail or DNS for the whole domain.
     *
     * Keep the first group in step with RESERVED_SUBS in apps/web/src/lib/brand.js.
     */
    public const RESERVED_USERNAMES = [
        // Bookboll's own hosts
        'www', 'app', 'admin', 'api', 'business', 'staging', 'dev',
        // Reserved for us
        'bookboll', 'support', 'help', 'status', 'blog', 'docs', 'careers',
        'about', 'contact', 'legal', 'privacy', 'terms', 'security',
        'billing', 'account', 'accounts', 'login', 'signup', 'register',
        'dashboard', 'portal', 'partner', 'partners', 'pay', 'checkout',
        // Infrastructure / DNS conventions
        'mail', 'smtp', 'imap', 'pop', 'webmail', 'email', 'ftp', 'sftp',
        'ns', 'ns1', 'ns2', 'ns3', 'dns', 'mx', 'cdn', 'static', 'assets',
        'img', 'images', 'media', 'files', 'download', 'downloads',
        'test', 'testing', 'demo', 'sandbox', 'preview', 'beta', 'alpha',
        'internal', 'private', 'secure', 'vpn', 'proxy', 'gateway',
    ];

    public static function normalizeUsername(?string $username): string
    {
        $u = strtolower(trim((string) $username));
        $u = preg_replace('/[^a-z0-9-]/', '', $u) ?? '';
        $u = trim($u, '-');

        return substr($u, 0, 30);
    }

    public static function isReservedUsername(string $username): bool
    {
        return in_array(strtolower($username), self::RESERVED_USERNAMES, true);
    }

    public function usernameAvailable(Request $request)
    {
        $username = self::normalizeUsername($request->query('username'));

        if (strlen($username) < 3) {
            return sendSuccess('Username checked', [
                'available' => false,
                'username'  => $username,
                'reason'    => 'Username must be at least 3 characters.',
            ]);
        }

        if (! preg_match('/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/', $username)) {
            return sendSuccess('Username checked', [
                'available' => false,
                'username'  => $username,
                'reason'    => 'Invalid username format.',
            ]);
        }

        if (self::isReservedUsername($username)) {
            return sendSuccess('Username checked', [
                'available' => false,
                'username'  => $username,
                'reason'    => 'That name is reserved. Please choose another.',
            ]);
        }

        $taken = $this->business::where('username', $username)->orWhere('brand_subdomain', $username)->exists();

        return sendSuccess('Username checked', [
            'available' => ! $taken,
            'username'  => $username,
        ]);
    }

    public function countries(Request $request)
    {
        $search = $request->query('search');

        $countries = Country::select('id', 'name', 'iso2')
            ->when($search, function ($query, $search) {
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                        ->orWhere('iso2', 'like', "%{$search}%");
                });
            })
            ->orderBy('name')
            ->get()
            ->map(fn($country) => [
                'id'   => $country->id,
                'name' => $country->name,
                'iso2' => $country->iso2,
            ])
            ->values();

        return sendSuccess('Countries fetched successfully', compact('countries'));
    }

    public function register(Request $request)
    {
        $data = $request->validate([
            'name'                  => 'required|string|max:100',
            'business_name'         => 'required|string|max:150',
            'username'              => 'required|string|min:3|max:30',
            'business_types'        => 'required|array|min:1',
            'business_types.*'      => ['required', 'string', Rule::in($this->business::BUSINESS_TYPE_KEYS)],
            // NOT unique: one person can be both a customer and a provider. The
            // Supabase design kept `customers` and `admin_users` in separate tables
            // with no unique email between them; consolidating into one `users`
            // table turned that into a wall every existing customer hit. Ownership
            // of an existing account is proved by password below.
            'email'                 => 'required|email|max:255',
            'password'              => 'required|string|min:8|confirmed',
            'country_id'            => 'nullable|integer|exists:countries,id',
            // Proof that the signer can read the address they typed, issued by
            // EmailVerificationService::verifyOtp() before this endpoint is called.
            'email_verification_token' => 'required|string|max:128',
        ]);

        $username = self::normalizeUsername($data['username']);

        if (strlen($username) < 3 || ! preg_match('/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/', $username)) {
            return sendError('Invalid username. Use 3–30 letters, numbers or hyphens.', [
                'username' => ['Invalid username format.'],
            ], 422);
        }

        if (self::isReservedUsername($username)) {
            return sendError('That name is reserved. Please choose another.', [
                'username' => ['That name is reserved. Please choose another.'],
            ], 422);
        }

        if ($this->business::where('username', $username)->orWhere('brand_subdomain', $username)->exists()) {
            return sendError('That username is already taken.', [
                'username' => ['That username is already taken.'],
            ], 422);
        }

        // An email already on file belongs to an existing account — most often a
        // customer adding a business. Attach the business to that account rather
        // than refusing, but only once the password proves they own it; this
        // endpoint is public, so accepting the email alone would be takeover.
        // Checked before the username/password branches below so a stale or forged
        // token is refused on its own terms, rather than surfacing as some unrelated
        // validation error further down.
        if (! $this->emailVerification->tokenIsValid($data['email'], $data['email_verification_token'] ?? null)) {
            $msg = 'Please confirm your email address before continuing.';

            return sendError($msg, ['email_verification_token' => [$msg]], 422);
        }

        $existing = $this->user::where('email', $data['email'])->first();

        if ($existing) {
            if (! Hash::check($data['password'], $existing->password)) {
                // The dashboard surfaces errors[] before message (firstApiError),
                // so the guidance has to live in the field error too.
                $msg = 'An account already exists with this email. Enter that account\'s password to add a business to it, or sign up with a different email.';

                return sendError($msg, ['email' => [$msg]], 422);
            }
            if ($this->business::where('owner_id', $existing->id)->exists()) {
                return sendError('This account already has a business.', [
                    'email' => ['This account already has a business.'],
                ], 422);
            }
        }

        try {
            DB::beginTransaction();

            $user = $existing ?: $this->user::create([
                'name'       => $data['name'],
                'email'      => $data['email'],
                'password'   => $data['password'],
                'status'     => User::ACTIVE,
                // The signup form asks for a country; it used to be written to the
                // business only, so the owner's own country stayed empty and every
                // country-keyed lookup for them (loyalty programme, alert routing)
                // fell back to nothing.
                'country_id' => $data['country_id'] ?? null,
            ]);

            // An existing customer adding a business keeps whatever country they
            // already had — this only fills a blank, never overwrites their answer.
            if ($existing && ! $existing->country_id && ! empty($data['country_id'])) {
                $existing->forceFill(['country_id' => (int) $data['country_id']])->save();
            }

            // The OTP above proved this mailbox, which is exactly what the column
            // records — for a brand-new account and equally for an older one adding a
            // business, so a provider is never left unconfirmed by which branch they
            // came down.
            //
            // forceFill, not mass assignment: `email_verified_at` is deliberately NOT
            // in User::$fillable, and must not be — it would then be settable by any
            // request that reaches a fill(), which is the whole thing this proves.
            if (! $user->email_verified_at) {
                $user->forceFill(['email_verified_at' => now()])->save();
            }

            // Additive: an existing customer keeps the customer role and gains this
            // one, so their bookings and rewards are untouched.
            if (! $user->hasRole('provider_admin')) {
                $user->assignRole('provider_admin');
            }

            $business = $this->business::create([
                'owner_id'        => $user->id,
                'name'            => $data['business_name'],
                'username'        => $username,
                'business_types'  => array_values($data['business_types']),
                'country_id'      => $data['country_id'] ?? null,
                'status'          => $this->business::STATUS_PENDING,
                'brand_subdomain' => $username,
            ]);

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            report($e);

            return sendError('Could not create business account.', [], 500);
        }

        // Only now — a registration that failed on a taken username must not cost the
        // visitor another round-trip through their inbox.
        $this->emailVerification->consume($data['email']);

        AdminAlerts::toPlatform('provider', 'ok',
            'New provider signed up — '.($business->name ?: $user->name),
            'Review their business in My Team', '/team',
            $business->country_id ? (int) $business->country_id : null);

        $token = $user->createToken('bookboll-business-api-token')->plainTextToken;

        return sendSuccess('Business account created successfully', [
            'user' => [
                'id'     => $user->id,
                'name'   => $user->name,
                'email'  => $user->email,
                'status' => $user->status,
            ],
            'business' => $business->fresh(),
            'roles'    => $user->getRoleNames()->values(),
            'token'    => $token,
        ], 201);
    }

    public function updateBrand(Request $request)
    {
        // The relation PROPERTY (the Business), not the method (a HasOne) — a
        // relation object is always truthy, so the guard below never fired and
        // building the response then blew up on HasOne::fresh().
        // Owned outright, or run through a managing membership: the owner of a
        // business created ownerless has no ownedBusiness row to find.
        $business = \App\Support\AccessScope::managedBusiness($request->user());

        if (! $business) {
            return sendError('No business found for this account.', [], 404);
        }

        $hex = ['nullable', 'string', 'regex:/^#([A-Fa-f0-9]{3}|[A-Fa-f0-9]{6})$/'];

        $data = $request->validate([
            'brand_name'             => 'required|string|max:150',
            'brand_tagline'          => 'nullable|string|max:255',
            'brand_logo_url'         => 'nullable|url|max:2048',
            'brand_primary'          => $hex,
            'brand_accent'           => $hex,
            'brand_meta_title'       => 'nullable|string|max:150',
            'brand_meta_description' => 'nullable|string|max:500',
        ]);

        $business->update([
            'brand_name'             => $data['brand_name'],
            'brand_tagline'          => $data['brand_tagline'] ?? null,
            'brand_logo_url'         => $data['brand_logo_url'] ?? null,
            'brand_primary'          => $data['brand_primary'] ?? null,
            'brand_accent'           => $data['brand_accent'] ?? null,
            'brand_meta_title'       => $data['brand_meta_title'] ?? null,
            'brand_meta_description' => $data['brand_meta_description'] ?? null,
            'brand_enabled'          => true,
        ]);

        return sendSuccess('Business brand updated successfully', [
            'business' => $business->fresh(),
        ]);
    }

    public function adminIndex(Request $request)
    {
        $actor = $request->user();
        $search           = $request->search;
        $status           = $request->status;
        $countryId        = $request->country_id;
        $recordsPerPage   = (int) ($request->query('records_per_page') ?? 50);

        // Memberships are the authoritative team record (Business::ownerMembership
        // says so), so they are loaded with the page rather than read per row.
        $query = $this->business::with(['owner', 'activeMemberships.user'])->latest()
            ->when($search, function ($query) use ($search) {
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'LIKE', "%{$search}%")
                        ->orWhere('username', 'LIKE', "%{$search}%")
                        ->orWhereHas('owner', function ($oq) use ($search) {
                            $oq->where('email', 'LIKE', "%{$search}%")
                                ->orWhere('name', 'LIKE', "%{$search}%");
                        });
                });
            })
            ->when($countryId, function ($query) use ($countryId) {
                $query->where('country_id', $countryId);
            })
            ->when($status, function ($query) use ($status) {
                $query->where('status', $status);
            });

        if ($actor && $actor->hasRole('country_admin') && ! $actor->hasRole('super_admin')) {
            $allowed = collect($actor->assigned_country_ids ?? [])->filter()->map(fn($id) => (int) $id)->all();
            if ($actor->country_id) {
                $allowed[] = (int) $actor->country_id;
            }
            $allowed = array_values(array_unique($allowed));
            if ($allowed) {
                $query->whereIn('country_id', $allowed);
            }
        }

        $paginated = $query->paginate($recordsPerPage);

        $paginated->getCollection()->transform(function (Business $b) {
            /*
             * Who runs this business, and how many people are in it.
             *
             * Both were read from `businesses.owner_id` alone, which the
             * assignment flow never sets — it creates a BusinessMembership and
             * clears the account's platform role. So a business whose owner had
             * just been assigned still reported "Owner not assigned" and "0 team
             * members", and the person who had been moved into it vanished from
             * the page entirely. Resolve from the membership first, and keep
             * owner_id as the fallback for businesses that predate memberships.
             */
            $managing = $b->activeMemberships
                ->first(fn (BusinessMembership $m) => in_array($m->role, BusinessMembership::MANAGING_ROLES, true));
            $owner = $managing?->user ?: $b->owner;

            // Union, not either/or: a legacy business counts staff by
            // users.owner_user_id, a current one by its memberships, and one
            // that has been through both must not count anybody twice.
            $memberIds = $b->activeMemberships->pluck('user_id');
            $legacyIds = $b->owner_id
                ? $this->user::where('owner_user_id', $b->owner_id)->pluck('id')
                : collect();
            $staffCount = $memberIds->merge($legacyIds)->unique()->count();

            return [
                'id'              => $b->id,
                'name'            => $b->name,
                'business_name'   => $b->name,
                'username'        => $b->username,
                'business_types'  => $b->business_types ?? [],
                'status'          => $b->status,
                'business_status' => $b->status,
                'country_id'      => $b->country_id,
                'owner_user_id'   => $owner?->id,
                'email'           => $owner?->email,
                'full_name'       => $owner?->name,
                'phone'           => $owner?->phone,
                'staff_count'     => $staffCount,
                // The real plan now — Stripe decides it, so the Team page's Free /
                // Premium chip reflects what the provider is actually paying for.
                'plan'            => $b->plan ?: 'free',
                'premium_since'   => $b->premium_since?->toIso8601String(),
                'created_at'      => $b->created_at?->toIso8601String(),
            ];
        });

        return sendSuccess('Businesses fetched successfully', [
            'businesses' => $paginated,
        ]);
    }

    public function teamIndex(Request $request)
    {
        $owner = $request->user();
        if (! $owner->hasRole('provider_admin')) {
            return sendError('Only business owners can manage their team.', [], 403);
        }

        $members = $this->user::where('owner_user_id', $owner->id)
            ->latest()
            ->get()
            ->map(fn(User $u) => $this->userService->formatTeamUser($u))
            ->values();

        // The owner's own venues, so the dashboard's scope picker has something to
        // offer. Without this the provider side rendered an empty list and no
        // member could be pinned to a venue.
        $venues = \App\Models\Venue::ownedBy((int) $owner->id)
            ->orderBy('name')
            ->get(['id', 'name', 'owner_user_id']);

        return sendSuccess('Team fetched successfully', [
            'users'  => $members,
            'venues' => $venues,
        ]);
    }

    public function teamStore(Request $request)
    {
        $owner = $request->user();
        if (! $owner->hasRole('provider_admin')) {
            return sendError('Only business owners can manage their team.', [], 403);
        }

        $request->merge([
            'owner_user_id' => $owner->id,
            'status'        => $request->input('status', User::ACTIVE),
        ]);

        $allowed = config('roles.provider_team_roles', []);
        if (! in_array($request->input('role'), $allowed, true)) {
            return sendError('Invalid team role.', [
                'role' => ['Choose a role below Owner.'],
            ], 422);
        }

        return $this->userService->createUser($request);
    }

    public function teamUpdate(Request $request, $userId)
    {
        $owner = $request->user();
        if (! $owner->hasRole('provider_admin')) {
            return sendError('Only business owners can manage their team.', [], 403);
        }

        $member = $this->user::where('id', $userId)->where('owner_user_id', $owner->id)->first();
        if (! $member) {
            return sendError('Team member not found.', [], 404);
        }

        if ($request->filled('role')) {
            $allowed = config('roles.provider_team_roles', []);
            if (! in_array($request->input('role'), $allowed, true)) {
                return sendError('Invalid team role.', [
                    'role' => ['Choose a role below Owner.'],
                ], 422);
            }
        }

        $request->merge(['owner_user_id' => $owner->id]);

        return $this->userService->updateUser($request, $member->id);
    }

    public function teamDestroy(Request $request, $userId)
    {
        $owner = $request->user();
        if (! $owner->hasRole('provider_admin')) {
            return sendError('Only business owners can manage their team.', [], 403);
        }

        $member = $this->user::where('id', $userId)->where('owner_user_id', $owner->id)->first();
        if (! $member) {
            return sendError('Team member not found.', [], 404);
        }

        $member->delete();

        return sendSuccess('Team member deleted successfully');
    }
}
