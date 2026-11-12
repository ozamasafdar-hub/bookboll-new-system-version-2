<?php
namespace App\Services;

use App\Exceptions\CustomerAuthException;
use App\Mail\CustomerForgotPasswordOtpEmail;
use App\Support\AccessScope;
use App\Support\RoleGrantGuard;
use App\Mail\ForgetPasswordEmail;
use App\Mail\UserRejectionEmail;
use App\Mail\UserWelcomeEmail;
use App\Models\Business;
use App\Models\DeletedAccount;
use App\Models\LoyaltyPoint;
use App\Models\OnesignalSubscription;
use App\Models\Preference;
use App\Models\RegistrationRequest;
use App\Models\SportsCategory;
use App\Models\User;
use App\Models\Venue;
use Carbon\Carbon;
use Exception;
use Google_Client;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Maatwebsite\Excel\Facades\Excel;
use MobileApps;
use Spatie\Permission\Models\Role;

class UserService
{
    public function __construct(protected User $userModel)
    {
    }

    public function register($request)
    {
        $formData = $request->validate([
            'name'     => 'required|string|max:100',
            'email'    => 'required|email',
            'phone'    => 'required|min:6|max:20',
            'password' => 'required|min:8|confirmed',
        ]);

        try {
            DB::beginTransaction();

            $user = $this->userModel->where("email", $formData['email'])->first();

            if ($user) {
                if ($user->status == User::BLOCKED) {
                    DB::rollBack();
                    return sendError('Your account is blocked by admin. Please contact for further details.');
                }

                // Proof of ownership before this email's existing account is touched.
                //
                // This endpoint is public, and it ends by minting a Sanctum token for
                // whatever account it resolved. Without this check, anyone who knew a
                // provider's email could register as a "customer" with a password of
                // their own choosing and be handed a token carrying that provider's
                // roles — the victim's password untouched, so nothing looked wrong.
                // BusinessService::register has always proved ownership this way; this
                // is the same rule in the other direction.
                $ownershipProven = Hash::check($formData['password'], (string) $user->password);

                $ownershipError = 'An account already exists with this email. Enter that '
                    . "account's password to continue, or sign up with a different email.";

                if ($this->customerAccountWasDeleted($user)) {
                    // A self-deleted customer signing up again gets a fresh start —
                    // but only when the account holds nothing else. One that still
                    // carries roles is live (a provider, say) and has to be proved.
                    if (! $user->getRoleNames()->isEmpty() && ! $ownershipProven) {
                        DB::rollBack();

                        return sendError($ownershipError, ['email' => [$ownershipError]], 422);
                    }

                    $restoreData = $user->getRoleNames()->isEmpty()
                        ? [
                            'name'     => $formData['name'],
                            'phone'    => $formData['phone'],
                            'password' => $formData['password'],
                        ]
                        : [];

                    $this->restoreCustomerAccount($user, $restoreData);
                } elseif (! $user->hasRole('customer')) {
                    // A live account adding the customer side — most often a business
                    // owner making their first booking. Correct password: they gain
                    // the role and keep everything they had.
                    if (! $ownershipProven) {
                        DB::rollBack();

                        return sendError($ownershipError, ['email' => [$ownershipError]], 422);
                    }

                    $user->assignRole('customer');

                    if (! $user->preferences) {
                        Preference::create([
                            'user_id'         => $user->id,
                            'notifications'   => [
                                'booking_emails'   => true,
                                'sms_reminders'    => false,
                                'marketing_emails' => true,
                            ],
                            'favorite_sports' => [],
                            'language'        => 'english',
                            'currency'        => 'QAR',
                        ]);
                    }
                } else {
                    return sendError("Customer with this email already exists");
                }

            } else {

                $user = $this->userModel->create([
                    'name'     => $formData['name'],
                    'email'    => $formData['email'],
                    'phone'    => $formData['phone'],
                    'password' => $formData['password'],
                    'status'   => $this->userModel::ACTIVE,
                ]);

                $user->assignRole('customer');

                if (! $user->preferences) {
                    Preference::create([
                        'user_id'         => $user->id,
                        'notifications'   => [
                            'booking_emails'   => true,
                            'sms_reminders'    => false,
                            'marketing_emails' => true,
                        ],
                        'favorite_sports' => [],
                        'language'        => 'english',
                        'currency'        => 'QAR',
                    ]);
                }
            }

            DB::commit();

            try {
                Mail::to($user->email)->send(new UserWelcomeEmail($user, $formData['password']));
            } catch (Exception $e) {
                Log::error("Failed to send welcome email: " . $e->getMessage(), ["Exception" => $e]);
            }

            $user->load(['roles', 'venues']);
            $user->makeHidden('roles');
            $this->ensurePublicUuid($user);
            $token = $user->createToken('bookboll-api-token')->plainTextToken;
            $roles = $user->getRoleNames();

            return sendSuccess("User registered successfully", compact('user', 'roles', 'token'));

        } catch (Exception $e) {
            DB::rollBack();
            Log::error("User registration error: " . $e->getMessage(), ["Exception" => $e]);
            return sendError("Something went wrong please try again later");
        }
    }

    public function getCustomerProfile()
    {

        $user = auth()->user();
        $this->ensurePublicUuid($user);

        $user->load(['roles', 'venues', 'loyaltyTier', 'assets']);
        $user->makeHidden(['roles']);
        $roles = $user->getRoleNames();

        $response = [
            'roles'           => $roles,
            'assigned_venues' => $user->venues,
            'user'            => $user,
            'loyalty_points'  => LoyaltyPoint::balance($user->id) ?? 0,
            'loyalty_tier'    => [
                'name'           => $user->loyaltyTier->name ?? 'Bronze',
                'slug'           => $user->loyaltyTier->slug ?? 'bronze',
                'progress'       => $user->loyalty_progress ?? 0,
                'description'    => $user->loyaltyTier->description ?? '',
                'points_to_next' => $user->points_to_next_tier ?? 0,
                'next_tier'      => $user->next_tier?->name ?? 'Max Level',
            ],
        ];

        return sendSuccess("Profile fetched successfully", $response);
    }

    public function updateCustomerProfile($request, $id)
    {
        $user = $this->findById($id);

        if (! $user) {
            return sendError("User not found");
        }

        if (Auth::id() !== $user->id && ! Auth::user()->hasRole(['super_admin', 'admin'])) {
            return sendError("Unauthorized to update this profile");
        }

        $formData = $request->validate([
            'name'          => 'sometimes|string|max:100',
            'bio'           => 'nullable|string|max:2000',
            'photos'        => 'nullable|array',
            'photos.*'      => 'nullable|string|max:2048',
            'is_private'    => 'sometimes|boolean',
            'is_discoverable' => 'sometimes|boolean',
            'date_of_birth' => 'nullable|date|before:today',
            'gender'        => 'nullable',
            'nationality'   => 'nullable|string',
            'address'       => 'nullable|max:255',
            'phone'         => 'nullable|max:20',
        ]);

        try {
            DB::beginTransaction();

            $updateData = [];

            if (isset($formData['name'])) {
                $updateData['name'] = $formData['name'];
            }

            if (isset($formData['date_of_birth'])) {
                $updateData['date_of_birth'] = $formData['date_of_birth'];
            }

            if (isset($formData['gender'])) {
                $updateData['gender'] = $formData['gender'];
            }

            if (isset($formData['nationality'])) {
                $updateData['nationality'] = $formData['nationality'];
            }

            if (isset($formData['address'])) {
                $updateData['address'] = $formData['address'];
            }

            if (isset($formData['phone'])) {
                $updateData['phone'] = $formData['phone'];
            }

            if (array_key_exists('bio', $formData)) {
                $updateData['bio'] = $formData['bio'];
            }

            if (array_key_exists('is_private', $formData)) {
                $updateData['is_private'] = (bool) $formData['is_private'];
            }

            // Separate from is_private on purpose: a private profile is still
            // reachable at /u/:id, so it cannot also mean "do not list me by
            // name". This is the flag people search honours.
            if (array_key_exists('is_discoverable', $formData)) {
                $updateData['is_discoverable'] = (bool) $formData['is_discoverable'];
            }

            $this->ensurePublicUuid($user);

            if (! empty($updateData)) {
                $user->update($updateData);
            }

            if (array_key_exists('photos', $formData)) {
                $user->syncGalleryPhotos(array_values(array_filter($formData['photos'] ?? [])));
            }

            DB::commit();

            $user->refresh();
            $user->load(['roles', 'venues', 'assets']);
            $user->makeHidden(['roles']);
            $roles = $user->getRoleNames();

            return sendSuccess("Profile updated successfully", compact('user', 'roles'));

        } catch (Exception $e) {
            DB::rollBack();
            Log::error("Customer profile update error: " . $e->getMessage(), ['user_id' => $id, 'Exception' => $e]);
            return sendError("Something went wrong: " . $e->getMessage());
        }
    }

    public function login($request)
    {
        $requestFrom = $request->header('from') ?? null;

        try {

            $user = $this->userModel->where("email", $request->email)->first();

            if (! $user || ! $user->passwordMatches((string) $request->password)) {
                return sendError('Invalid credentials');
            }

            if ($user->status === $this->userModel::PENDING) {
                return sendError('Your account is pending admin approval. Please wait for approval before logging in.');
            }

            $user->forceFill(['last_login_at' => now()])->save();

            if ($requestFrom == MobileApps::ADMIN) {
                $token = $user->createToken('bookboll-admin-mobile-app-api-token')->plainTextToken;
            } else {
                $token = $user->createToken('bookboll-admin-web-api-token')->plainTextToken;
            }

            $roles = $user->getRoleNames();

            $user->load(['venues', 'ownedBusiness', 'activeBusinessMembership.business']);
            $permissions = $user->getAllPermissions()->pluck('name');

            $user->makeHidden(['roles']);

            $permissionNames = $permissions->values()->all();

            $response = [
                'user'            => $user,
                'token'           => $token,
                'roles'           => $roles,
                'permissions'     => $permissionNames,
                'assigned_venues' => $user->venues,
                'access'          => \App\Support\RolePermissionMatrix::accessFromPermissionNames($permissionNames),
                // Same shape as /me, so the dashboard can gate its UI from the
                // login response instead of waiting for a second round trip.
                'capabilities'       => $user->capabilities(),
                'custom_permissions' => $user->capabilityOverrides(),
                'scope'              => [
                    'owner_user_id'         => \App\Support\AccessScope::ownerUserId($user),
                    'is_global'             => \App\Support\AccessScope::isGlobal($user),
                    'is_platform'           => \App\Support\AccessScope::isPlatform($user),
                    'is_provider_scoped'    => \App\Support\AccessScope::isProviderScoped($user),
                    'assigned_country_ids'  => array_values((array) ($user->assigned_country_ids ?? [])),
                    'assigned_location_ids' => array_values((array) ($user->assigned_location_ids ?? [])),
                    'assigned_listing_ids'  => array_values((array) ($user->assigned_listing_ids ?? [])),
                    'assigned_program_ids'  => array_values((array) ($user->assigned_program_ids ?? [])),
                ],
            ];

            return sendSuccess('Login successful', $response);

        } catch (Exception $e) {
            Log::error("Admin login error: " . $e->getMessage(), ['email' => $request->email ?? null, 'Exception' => $e]);
            return sendError('Something went wrong during login');
        }
    }

    public function loginWithGoogle($request)
    {
        $request->validate([
            'id_token' => 'required|string',
        ]);

        $payload = $this->verifyGoogleIdToken($request->id_token);

        if (! $payload) {
            throw ValidationException::withMessages([
                'id_token' => ['Invalid Google token.'],
            ]);
        }

        try {
            [$user, $token] = $this->issueCustomerSessionFromGoogle($payload);
        } catch (CustomerAuthException $e) {
            return sendError($e->getMessage());
        } catch (Exception $e) {
            Log::error("Customer Google Login Failed: " . $e->getMessage(), ["exception" => $e]);
            return sendError("Something went wrong try again later");
        }

        return sendSuccess("Logged in with google", compact('token', 'user'));
    }

    /**
     * Verify a Google ID token against Bookboll's single OAuth client.
     *
     * Shared with OAuthBrokerService, which verifies the id_token returned by its code
     * exchange the same way. Note what is *not* checked here: where the token was
     * obtained. `aud` is the whole test, which is precisely why one OAuth client can
     * serve every tenant storefront — the per-domain restriction Google enforces lives
     * in the browser, not in this verification.
     */
    public function verifyGoogleIdToken(string $idToken): ?array
    {
        $client  = new Google_Client(['client_id' => config('services.google.client_id')]);
        $payload = $client->verifyIdToken($idToken);

        return is_array($payload) ? $payload : null;
    }

    /**
     * Turn a verified Google payload into a customer and a Sanctum token.
     *
     * Two front doors reach this: the mobile apps POST a native id_token to
     * /customer/google-login, and the web storefronts arrive via OAuthBrokerService
     * after its server-side code exchange. Both must create the identical account, so
     * the upsert lives here once rather than being written twice.
     *
     * @param  array<string, mixed>  $payload  A payload already verified by Google.
     * @return array{0: User, 1: string}  The customer and their plain-text token.
     *
     * @throws CustomerAuthException  Reason is safe to show the customer.
     */
    public function issueCustomerSessionFromGoogle(array $payload): array
    {
        try {
            DB::beginTransaction();

            $googleId = $payload['sub'];
            $email    = $payload['email'];
            $name     = $payload['name'];
            $picture  = $payload['picture'] ?? null;

            $user = User::where('provider_id', $googleId)->orWhere('email', $email)->first();

            if ($user && $user->status == User::BLOCKED) {
                throw new CustomerAuthException('Your account is block by admin please contact for further details');
            }

            if ($user && $this->customerAccountWasDeleted($user)) {
                $restoreData = $user->getRoleNames()->isEmpty()
                    ? [
                        'name'              => $name,
                        'image'             => $picture,
                        'provider'          => 'google',
                        'provider_id'       => $googleId,
                        'email_verified_at' => now(),
                    ]
                    : [];

                $this->restoreCustomerAccount($user, $restoreData);
            }

            if (! $user) {
                $user = $this->userModel->create([
                    'name'              => $name,
                    'email_verified_at' => now(),
                    'email'             => $email,
                    'image'             => $picture,
                    'provider'          => "google",
                    'provider_id'       => $googleId,
                    'password'          => "bookboll-000009",
                    'status'            => $this->userModel::ACTIVE,
                ]);
            }

            if (! $user->hasRole('customer')) {
                $user->assignRole('customer');
            }

            if (! $user->preferences) {
                Preference::create([
                    'user_id'         => $user->id,
                    'notifications'   => [
                        'booking_emails'   => true,
                        'sms_reminders'    => false,
                        'marketing_emails' => true,
                    ],
                    'favorite_sports' => [],
                    'language'        => 'english',
                    'currency'        => 'QAR',
                ]);
            }

            $token = $user->createToken('auth-token')->plainTextToken;

            $this->ensurePublicUuid($user);

            DB::commit();

            $user->makeHidden(['roles']);

            return [$user, $token];

        } catch (Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function loginWithApple($request)
    {
        $request->validate([
            'identity_token' => 'required|string',
            'name'           => 'nullable|string|max:100',
            'email'          => 'nullable|email',
        ]);

        try {
            $payload = app(AppleTokenVerifier::class)->verify($request->identity_token);
        } catch (Exception $e) {
            Log::warning('Apple identity token verification failed: '.$e->getMessage());
            throw ValidationException::withMessages([
                'identity_token' => ['Invalid Apple token.'],
            ]);
        }

        $appleId = $payload->sub;
        $email   = $payload->email ?? $request->email;
        $name    = trim((string) $request->name);

        try {
            DB::beginTransaction();

            $user = $this->userModel->where(function ($query) use ($appleId, $email) {
                $query->where(function ($inner) use ($appleId) {
                    $inner->where('provider', 'apple')->where('provider_id', $appleId);
                });

                if ($email) {
                    $query->orWhere('email', $email);
                }
            })->first();

            if ($user && $user->status == User::BLOCKED) {
                DB::rollBack();
                return sendError('Your account is block by admin please contact for further details');
            }

            if (! $user) {
                if (! $email) {
                    DB::rollBack();
                    return sendError('Apple did not share an email address. Please try again or use another sign-in method.');
                }

                if ($name === '') {
                    $name = strstr($email, '@', true) ?: 'Apple User';
                }

                $user = $this->userModel->create([
                    'name'              => $name,
                    'email_verified_at' => now(),
                    'email'             => $email,
                    'provider'          => 'apple',
                    'provider_id'       => $appleId,
                    'password'          => 'bookboll-000009',
                    'status'            => $this->userModel::ACTIVE,
                ]);
            } elseif (! $user->provider_id) {
                $user->provider    = $user->provider ?: 'apple';
                $user->provider_id = $appleId;
                $user->save();
            }

            if (! $user->hasRole('customer')) {
                $user->assignRole('customer');
            }

            if (! $user->preferences) {
                Preference::create([
                    'user_id'         => $user->id,
                    'notifications'   => [
                        'booking_emails'   => true,
                        'sms_reminders'    => false,
                        'marketing_emails' => true,
                    ],
                    'favorite_sports' => [],
                    'language'        => 'english',
                    'currency'        => 'QAR',
                ]);
            }

            $token = $user->createToken('auth-token')->plainTextToken;

            $this->ensurePublicUuid($user);

            DB::commit();

            $user->makeHidden(['roles']);

            return sendSuccess('Logged in with Apple', compact('token', 'user'));
        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Customer Apple Login Failed: '.$e->getMessage(), ['exception' => $e]);

            return sendError('Something went wrong try again later');
        }
    }

    public function customerLogin($request)
    {
        try {
            // Resolved by email alone, not filtered to the customer role. A business
            // owner booking for the first time holds provider roles and no customer
            // role; the old role-scoped lookup returned null for them, so a correct
            // password still answered "Invalid credentials". The customer side is
            // granted below, once the password has proved ownership.
            $user = $this->userModel->whereEmail($request->email)->first();

            // Kept scoped to accounts that already hold the customer role: this is a
            // public endpoint, and widening it would let anyone trigger reset mail for
            // a passwordless provider or admin account.
            if ($user && ! $user->password && $user->hasRole('customer')) {
                $otp = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

                DB::table('password_reset_tokens')->updateOrInsert(
                    ['email' => $request->email],
                    ['token' => Hash::make($otp), 'created_at' => Carbon::now()]
                );

                try {
                    Mail::to($user->email)->send(new CustomerForgotPasswordOtpEmail($otp, $user));
                } catch (Exception $e) {
                    Log::error("Failed to send OTP email for customer login: " . $e->getMessage(), ["Exception" => $e]);
                    return sendError('Failed to send OTP email. Please try again later.');
                }

                return sendSuccess('Your pasword was reset please change your password', ['reset_password' => true]);
            }

            if (! $user || ! $user->password || ! password_verify($request->password, $user->password)) {
                return sendError('Invalid credentials');
            }

            if ($user->status == User::BLOCKED) {
                return sendError('Your account is blocked by admin. Please contact for further details.');
            }

            // A customer account the holder deleted themselves stays deleted here.
            // Coming back is register()'s job, where the revival is explicit; letting
            // a login quietly undo a deletion would be a surprise. Same answer the
            // role-scoped lookup used to give, so this is not a behaviour change.
            if ($this->customerAccountWasDeleted($user)) {
                return sendError('Invalid credentials');
            }

            // A live account signing in to book for the first time - most often a
            // business owner. The password above is the same proof of ownership
            // register() demands before it grants this role, so grant it and let
            // them through with everything else they already had intact.
            if (! $user->hasRole('customer')) {
                DB::transaction(function () use ($user) {
                    $user->assignRole('customer');

                    if (! $user->preferences) {
                        Preference::create([
                            'user_id'         => $user->id,
                            'notifications'   => [
                                'booking_emails'   => true,
                                'sms_reminders'    => false,
                                'marketing_emails' => true,
                            ],
                            'favorite_sports' => [],
                            'language'        => 'english',
                            'currency'        => 'QAR',
                        ]);
                    }
                });

                $user->refresh();
            }

            $this->ensurePublicUuid($user);

            $token = $user->createToken('bookboll-customer-login-api-token')->plainTextToken;

            $user->makeHidden(['roles']);

            $response = [
                'token' => $token,
                'user'  => $user,
            ];

            return sendSuccess('Login successful', $response);
        } catch (Exception $e) {
            Log::error("Customer Login Error: " . $e->getMessage(), ["Exception" => $e]);
            return sendError('Something went wrong during login');
        }
    }

    public function logout()
    {
        $user = auth()->user();

        if ($user && $user->currentAccessToken()) {
            $user->currentAccessToken()->delete();
        }

        return sendSuccess("Logout successfully");
    }

    public function loginUser()
    {
        $user = auth()->user();
        $user->load(['venues', 'ownedBusiness', 'activeBusinessMembership.business']);
        $user->makeHidden(['roles']);

        $roles           = $user->getRoleNames();
        $permissionNames = $user->getAllPermissions()->pluck('name')->values()->all();

        $response = [
            'user'            => $user,
            'roles'           => $roles,
            'permissions'     => $permissionNames,
            'assigned_venues' => $user->venues,
            'access'          => \App\Support\RolePermissionMatrix::accessFromPermissionNames($permissionNames),
            // What the dashboard's can(CAP.…) needs: the effective capability list
            // (role base ± per-user overrides) plus the scope pins, so the UI hides
            // exactly what the API would refuse.
            'capabilities'        => $user->capabilities(),
            'custom_permissions'  => $user->capabilityOverrides(),
            'scope'               => [
                'owner_user_id'         => \App\Support\AccessScope::ownerUserId($user),
                'is_global'             => \App\Support\AccessScope::isGlobal($user),
                'is_platform'           => \App\Support\AccessScope::isPlatform($user),
                'is_provider_scoped'    => \App\Support\AccessScope::isProviderScoped($user),
                'assigned_country_ids'  => array_values((array) ($user->assigned_country_ids ?? [])),
                'assigned_location_ids' => array_values((array) ($user->assigned_location_ids ?? [])),
                'assigned_listing_ids'  => array_values((array) ($user->assigned_listing_ids ?? [])),
                'assigned_program_ids'  => array_values((array) ($user->assigned_program_ids ?? [])),
            ],
        ];

        return sendSuccess('Logged in user fetched susccessfully', $response);
    }

    public function updateProfile($request, $id)
    {
        $user = $this->findById($id);

        if (! $user) {
            return sendError("User not found");
        }

        if (Auth::id() !== $user->id && ! Auth::user()->hasRole(['super_admin', 'admin'])) {
            return sendError("Unauthorized to update this profile");
        }

        $formData = $request->validate([
            'name'             => 'sometimes|string|max:100',
            'email'            => 'sometimes|email|unique:users,email,' . $user->id,
            'phone'            => 'nullable',
            'password'         => 'sometimes|min:8|confirmed',
            'current_password' => 'required_with:password|current_password',
        ]);

        try {
            DB::beginTransaction();

            $updateData = [];

            if (isset($formData['name'])) {
                $updateData['name'] = $formData['name'];
            }

            if (isset($formData['email'])) {
                $updateData['email'] = $formData['email'];
            }

            if (isset($formData['password'])) {
                $updateData['password'] = $formData['password'];
            }

            if (isset($formData['phone'])) {
                $updateData['phone'] = $formData['phone'];
            }

            if (! empty($updateData)) {
                $user->update($updateData);
            }

            DB::commit();

            $user->refresh();
            $user->load(['roles', 'venues']);
            $user->makeHidden(['roles']);
            $roles = $user->getRoleNames();

            return sendSuccess("Profile updated successfully", compact('user', 'roles'));

        } catch (Exception $e) {
            DB::rollBack();
            Log::error("User profile update error: " . $e->getMessage(), ['user_id' => $id, 'Exception' => $e]);
            return sendError("Something went wrong: " . $e->getMessage());
        }
    }

    public function requiredDataForUser()
    {
        $roles = Role::select(['id', 'name'])
            ->get()
            ->map(function ($role) {
                \Log::info("Original role name: " . $role->name);
                $transformedName = ucwords(str_replace('_', ' ', $role->name));
                \Log::info("Transformed role name: " . $transformedName);

                return (object) [
                    'id'   => $role->id,
                    'name' => $transformedName,
                ];
            });

        $venues = Venue::select(['id', 'name'])->get();

        $response = [
            'roles'  => $roles,
            'venues' => $venues,
        ];

        return sendSuccess("User data fetched successfully", $response);
    }

    public function list($request)
    {
        $search           = $request->search ?? null;
        $records_per_page = $request->records_per_page ?? 50;
        $scope            = $request->scope ?? 'platform';
        $roleFilter       = $request->role ?? null;
        $countryId        = $request->country_id ?? null;
        $ownerUserId      = $request->owner_user_id ?? null;

        $platformRoles     = config('roles.platform_team_roles', ['super_admin', 'country_admin', 'platform_staff', 'admin']);
        $providerTeamRoles = config('roles.provider_team_roles', []);
        $legacyStaff       = ['senior_employee', 'junior_employee', 'guard'];

        $users = $this->userModel->latest()
            ->with(['venues:id,name', 'ownedBusiness', 'activeBusinessMembership.business'])
            ->when($scope === 'provider_staff', function ($query) use ($providerTeamRoles, $ownerUserId) {
                /*
                 * Two ways to belong to a business, and an account moved by the
                 * assignment flow has only the second.
                 *
                 * That move clears every platform role and leaves owner_user_id
                 * null, so requiring BOTH a role and an owner put the account in
                 * no scope at all: absent from provider_staff, and excluded from
                 * `platform` too, which needs a role. The person an admin had
                 * just assigned simply vanished from the Team page.
                 */
                $query->where(function ($q) use ($providerTeamRoles) {
                    $q->where(fn ($legacy) => $legacy
                        ->whereNotNull('owner_user_id')
                        ->whereHas('roles', fn ($r) => $r->whereIn('name', $providerTeamRoles)))
                        ->orWhereHas('businessMemberships', fn ($m) => $m->where('status', \App\Models\BusinessMembership::STATUS_ACTIVE));
                });
                if ($ownerUserId) {
                    $query->where('owner_user_id', $ownerUserId);
                }
            })
            ->when($scope === 'platform' || $scope === null || $scope === '', function ($query) use ($platformRoles, $legacyStaff, $providerTeamRoles) {
                // Platform team: platform roles, OR provider-team roles with no owner (orphans / HQ), OR legacy staff without owner.
                $query->where(function ($outer) use ($platformRoles, $legacyStaff, $providerTeamRoles) {
                    $outer->whereHas('roles', fn ($q) => $q->whereIn('name', $platformRoles))
                        ->orWhere(function ($q) use ($legacyStaff) {
                            $q->whereNull('owner_user_id')
                                ->whereHas('roles', fn ($r) => $r->whereIn('name', $legacyStaff));
                        })
                        ->orWhere(function ($q) use ($providerTeamRoles) {
                            $q->whereNull('owner_user_id')
                                ->whereHas('roles', fn ($r) => $r->whereIn('name', $providerTeamRoles));
                        });
                });
            })
            ->when($roleFilter, function ($query, $roleFilter) {
                $query->whereHas('roles', fn ($q) => $q->where('name', $roleFilter));
            })
            ->when($countryId, function ($query, $countryId) {
                $query->where(function ($q) use ($countryId) {
                    $q->where('country_id', $countryId)
                        ->orWhereJsonContains('assigned_country_ids', (int) $countryId)
                        ->orWhereJsonContains('assigned_country_ids', (string) $countryId);
                });
            })
            ->when($search, function ($query, $search) {
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'LIKE', "%{$search}%")
                        ->orWhere('email', 'LIKE', "%{$search}%")
                        ->orWhere('status', 'LIKE', "%{$search}%")
                        ->orWhere('phone', 'LIKE', "%{$search}%");
                });
            });

        // Country admin jurisdiction
        $actor = auth()->user();
        if ($actor && $actor->hasRole('country_admin') && ! $actor->hasRole('super_admin')) {
            $allowed = collect($actor->assigned_country_ids ?? [])->filter()->map(fn ($id) => (int) $id)->all();
            if ($actor->country_id) {
                $allowed[] = (int) $actor->country_id;
            }
            $allowed = array_values(array_unique($allowed));
            if ($allowed) {
                $users->where(function ($q) use ($allowed) {
                    $q->whereIn('country_id', $allowed);
                    foreach ($allowed as $cid) {
                        $q->orWhereJsonContains('assigned_country_ids', $cid)
                            ->orWhereJsonContains('assigned_country_ids', (string) $cid);
                    }
                });
            }
        }

        $paginated = $users->paginate($records_per_page);

        $paginated->getCollection()->transform(fn (User $u) => $this->formatTeamUser($u));

        $roles  = Role::get(['id', 'name']);
        // owner_user_id is what the dashboard filters the scope picker by: it only
        // offers venues belonging to the business the member is being added to.
        // Selecting just id+name meant that filter matched nothing and the picker
        // always read "No locations yet".
        $venues = Venue::orderBy('name')->get(['id', 'name', 'owner_user_id', 'country_id', 'city_id']);

        return sendSuccess('Users fetched successfully', [
            'users'  => $paginated,
            'roles'  => $roles,
            'venues' => $venues,
            'search' => $search,
            'scope'  => $scope,
        ]);
    }

    /** Shape expected by the Vue Team page (maps Laravel user → admin_users-like row). */
    public function formatTeamUser(User $user): array
    {
        $user->loadMissing(['ownedBusiness', 'venues', 'activeBusinessMembership.business']);
        $roles = $user->getRoleNames()->values()->all();
        $role  = $roles[0] ?? null;

        foreach (['super_admin', 'country_admin', 'platform_staff', 'admin', 'provider_admin', 'facility_admin', 'provider_supervisor', 'staff', 'provider_viewer', 'senior_employee', 'junior_employee', 'guard'] as $preferred) {
            if (in_array($preferred, $roles, true)) {
                $role = $preferred;
                break;
            }
        }

        /*
         * An assigned account belongs to its business through a membership, not
         * through `businesses.owner_id` — and the move clears every platform
         * role, so `$role` above is null for them. Read both here, or the person
         * the admin just moved into a business shows up with no role and no
         * business and the page cannot group them.
         */
        $membership = $user->activeBusinessMembership;
        $business   = $user->ownedBusiness ?: $membership?->business;
        $role       = $role ?: $membership?->role;

        return [
            'id'                    => $user->id,
            'full_name'             => $user->name,
            'name'                  => $user->name,
            'email'                 => $user->email,
            'phone'                 => $user->phone,
            'status'                => $user->status,
            'role'                  => $role,
            'roles'                 => $roles,
            'country_id'            => $user->country_id,
            'assigned_country_ids'  => $user->assigned_country_ids ?? [],
            'owner_user_id'         => $user->owner_user_id,
            'last_login'            => $user->last_login_at?->toIso8601String(),
            'last_login_at'         => $user->last_login_at?->toIso8601String(),
            'created_at'            => $user->created_at?->toIso8601String(),
            'business_name'         => $business?->name,
            'username'              => $business?->username,
            'business_types'        => $business?->business_types ?? [],
            'business_status'       => $business?->status,
            'business_id'           => $business?->id,
            'custom_permissions'    => null,
            // The venues this person is pinned to. Was hardcoded [], so the
            // dashboard's scope picker always opened empty and every existing
            // assignment — including the ones carried over from the old system —
            // looked like "no venues". Read through AccessScope so BOTH sources
            // are reflected: the json column the dashboard writes and the
            // user_venue pivot older members still hold.
            'assigned_location_ids' => AccessScope::pinnedVenueIds($user),
            'assigned_listing_ids'  => [],
            'assigned_program_ids'  => [],
            'plan'                  => 'free',
            'premium_since'         => null,
        ];
    }

    public function createUser($request)
    {
        // manage_team says you may run a team; it does not say which roles you may
        // hand out. Without this a provider's own manager could mint a super admin.
        if (! RoleGrantGuard::mayGrant(auth()->user(), (string) $request->input('role'))) {
            return RoleGrantGuard::denied('create a user with that role');
        }

        $providerTeamRoles = config('roles.provider_team_roles', []);
        $platformRoles     = array_merge(
            config('roles.platform_team_roles', []),
            ['senior_employee', 'junior_employee', 'guard']
        );

        $formData = $request->validate([
            'name'                   => 'required|string|max:100',
            'email'                  => 'required|email',
            'role'                   => 'required|exists:roles,name',
            'status'                 => 'required|in:' . $this->userModel::ACTIVE . ',' . $this->userModel::INACTIVE,
            'password'               => 'nullable|min:3',
            'phone'                  => 'nullable|string|max:30',
            'country_id'             => 'nullable|integer',
            'assigned_country_ids'   => 'nullable|array',
            'assigned_country_ids.*' => 'integer',
            'owner_user_id'          => 'nullable|integer|exists:users,id',
            'venues'                 => 'nullable|array',
            // The dashboard's name for the same pins — accepted here too so a new
            // team member can be scoped at creation, not only on a later edit.
            'assigned_location_ids'   => 'sometimes|nullable|array',
            'assigned_location_ids.*' => 'numeric|exists:venues,id',
            'venues.*'               => 'numeric|exists:venues,id',
            'business_name'          => 'nullable|string|max:150',
            'username'               => 'nullable|string|min:3|max:30',
            'business_types'         => 'nullable|array',
        ]);

        if (in_array($formData['role'], $providerTeamRoles, true) && empty($formData['owner_user_id'])) {
            return sendError('Provider staff require an owner_user_id.', [
                'owner_user_id' => ['Select the business owner for this team member.'],
            ], 422);
        }

        if (! empty($formData['owner_user_id'])) {
            $owner = $this->userModel->find($formData['owner_user_id']);
            if (! $owner || ! $owner->hasRole('provider_admin')) {
                return sendError('owner_user_id must be a provider_admin.', [
                    'owner_user_id' => ['Invalid business owner.'],
                ], 422);
            }
        }

        $existing = $this->userModel::where('email', $formData['email'])->first();

        if ($existing) {
            return $this->createUserWhenEmailExists($existing, $formData);
        }

        try {
            DB::beginTransaction();

            $role = Role::where('name', $formData['role'])->firstOrFail();

            $isGlobalPlatform = in_array($formData['role'], ['super_admin', 'admin', 'country_admin'], true);

            if ($isGlobalPlatform) {
                $formData['venues'] = Venue::pluck('id')->toArray();
            }

            if (empty($formData['password'])) {
                $formData['password'] = '12345678';
            }

            $plainPassword = $formData['password'];

            $user = $this->userModel->create([
                'name'                  => $formData['name'],
                'email'                 => $formData['email'],
                'status'                => $formData['status'],
                'password'              => $formData['password'],
                'phone'                 => $formData['phone'] ?? null,
                'country_id'            => $formData['country_id'] ?? null,
                'assigned_country_ids'  => $formData['assigned_country_ids'] ?? null,
                'owner_user_id'         => in_array($formData['role'], $providerTeamRoles, true)
                    ? ($formData['owner_user_id'] ?? null)
                    : null,
            ]);

            $user->assignRole($formData['role']);

            if ($formData['role'] === 'customer' && ! $user->preferences) {
                Preference::create([
                    'user_id'         => $user->id,
                    'notifications'   => [
                        'booking_emails'   => true,
                        'sms_reminders'    => false,
                        'marketing_emails' => true,
                    ],
                    'favorite_sports' => [],
                    'language'        => 'english',
                    'currency'        => 'QAR',
                ]);
            }

            if (array_key_exists('assigned_location_ids', $formData)) {
                $formData['venues'] = array_values(array_unique(array_map(
                    'intval', (array) ($formData['assigned_location_ids'] ?? [])
                )));
            }

            if (! empty($formData['venues'])) {
                $user->venues()->sync($formData['venues']);
                // Mirror into the json column the dashboard reads, so both pin
                // sources always agree.
                $user->forceFill([
                    'assigned_location_ids' => array_values(array_map('intval', $formData['venues'])),
                ])->save();
            }

            // The legacy owner link alone is not enough: for a business created
            // ownerless it resolves to nothing, and the invitee signs in to an
            // empty dashboard. Leave the membership the rest of the system reads.
            \App\Support\BusinessMembershipSync::syncForStaff($user, $user->owner_user_id, auth()->id());

            if ($formData['role'] === 'provider_admin') {
                $username = BusinessService::normalizeUsername($formData['username'] ?? null);
                if ($username === '' || strlen($username) < 3) {
                    $username = BusinessService::normalizeUsername(explode('@', $formData['email'])[0] ?? 'business');
                    if (strlen($username) < 3) {
                        $username = 'biz-' . $user->id;
                    }
                }
                if (Business::where('username', $username)->orWhere('brand_subdomain', $username)->exists()) {
                    $username = $username . '-' . $user->id;
                }
                Business::create([
                    'owner_id'        => $user->id,
                    'name'            => $formData['business_name'] ?? $formData['name'],
                    'username'        => $username,
                    'business_types'  => array_values($formData['business_types'] ?? []),
                    'country_id'      => $formData['country_id'] ?? null,
                    'status'          => Business::STATUS_PENDING,
                    'brand_subdomain' => $username,
                ]);
            }

            DB::commit();

            try {
                Mail::to($user->email)->send(new UserWelcomeEmail($user, $plainPassword));
            } catch (Exception $e) {
                Log::error('Failed to send welcome email: ' . $e->getMessage(), ['Exception' => $e]);
            }

            $user->load(['roles', 'venues', 'ownedBusiness']);
            $user->makeHidden('roles');
            $roles = $user->getRoleNames();

            return sendSuccess('User created successfully', [
                'user'  => $this->formatTeamUser($user),
                'roles' => $roles,
            ]);

        } catch (Exception $e) {
            DB::rollBack();
            Log::error('User creation error: ' . $e->getMessage(), ['Exception' => $e]);

            return sendError('Something went wrong ' . $e->getMessage());
        }
    }

    /**
     * When POST /admin/users uses an email that already belongs to a customer-only account,
     * assign the requested staff role while keeping the user as a customer (bookings, loyalty, customer app).
     */
    private function createUserWhenEmailExists(User $existing, array $formData)
    {
        $staffRoleNames = array_values(array_unique(array_merge(
            config('roles.staff_roles', []),
            config('roles.provider_roles', []),
            config('roles.provider_team_roles', []),
        )));

        if ($existing->hasAnyRole($staffRoleNames)) {
            return sendError(
                'A staff account with this email already exists. Open the Users list, find this account, and use Edit instead of Add.',
                ['email' => ['This email is already used by a staff account.']],
                422
            );
        }

        if (! $existing->hasRole('customer')) {
            return sendError(
                'An account with this email already exists.',
                ['email' => ['The email has already been taken.']],
                422
            );
        }

        if ($formData['role'] === 'customer') {
            return sendError(
                'This email is already registered as a customer.',
                ['email' => ['The email has already been taken.']],
                422
            );
        }

        try {
            DB::beginTransaction();

            Role::where('name', $formData['role'])->firstOrFail();

            $isGlobalPlatform = in_array($formData['role'], ['super_admin', 'admin', 'country_admin'], true);

            if ($isGlobalPlatform) {
                $formData['venues'] = Venue::pluck('id')->toArray();
            }

            if (empty($formData['password'])) {
                $formData['password'] = '12345678';
            }

            $plainPassword = $formData['password'];
            $providerTeamRoles = config('roles.provider_team_roles', []);

            $existing->update([
                'name'                 => $formData['name'],
                'status'               => $formData['status'],
                'password'             => $formData['password'],
                'phone'                => $formData['phone'] ?? $existing->phone,
                'country_id'           => $formData['country_id'] ?? $existing->country_id,
                'assigned_country_ids' => $formData['assigned_country_ids'] ?? $existing->assigned_country_ids,
                'owner_user_id'        => in_array($formData['role'], $providerTeamRoles, true)
                    ? ($formData['owner_user_id'] ?? null)
                    : null,
            ]);

            if (! $existing->hasRole($formData['role'])) {
                $existing->assignRole($formData['role']);
            }

            $venues = $formData['venues'] ?? [];
            if (! empty($venues)) {
                $existing->venues()->sync($venues);
            }

            // Same bridge as createUser(): a customer promoted to business staff
            // must be a member of that business, not only pointed at its owner.
            \App\Support\BusinessMembershipSync::syncForStaff($existing, $existing->owner_user_id, auth()->id());

            DB::commit();

            try {
                Mail::to($existing->email)->send(new UserWelcomeEmail($existing, $plainPassword));
            } catch (Exception $e) {
                Log::error('Failed to send welcome email: ' . $e->getMessage(), ['Exception' => $e]);
            }

            $existing->load(['roles', 'venues', 'ownedBusiness']);
            $existing->makeHidden('roles');
            $user  = $existing;
            $roles = $user->getRoleNames();

            return sendSuccess(
                'Staff role added. This user remains a customer and now also has the role you selected.',
                [
                    'user'  => $this->formatTeamUser($user),
                    'roles' => $roles,
                ]
            );

        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Customer to staff promotion error: ' . $e->getMessage(), ['Exception' => $e]);

            return sendError('Something went wrong ' . $e->getMessage());
        }
    }

    public function findById($id)
    {
        return $this->userModel->findOrFail($id);
    }

    public function updateUser($request, $id)
    {
        $user = $this->findById($id);

        if (! $user) {
            return sendError('User not found');
        }

        // Never edit an account that outranks you, and never promote past yourself.
        if (! RoleGrantGuard::mayTouch(auth()->user(), $user)) {
            return RoleGrantGuard::denied('edit that user');
        }
        if ($request->filled('role') && ! RoleGrantGuard::mayGrant(auth()->user(), (string) $request->input('role'))) {
            return RoleGrantGuard::denied('assign that role');
        }

        $providerTeamRoles = config('roles.provider_team_roles', []);

        $formData = $request->validate([
            'name'                   => 'sometimes|string|max:100',
            'email'                  => 'sometimes|email|unique:users,email,' . $user->id,
            'role'                   => 'sometimes|exists:roles,name',
            'status'                 => 'sometimes|in:' . $this->userModel::ACTIVE . ',' . $this->userModel::INACTIVE,
            'password'               => 'sometimes|min:3',
            'phone'                  => 'nullable|string|max:30',
            'country_id'             => 'nullable|integer',
            'assigned_country_ids'   => 'nullable|array',
            'assigned_country_ids.*' => 'integer',
            'owner_user_id'          => 'nullable|integer|exists:users,id',
            'venues'                 => 'sometimes|nullable|array',
            'venues.*'               => 'numeric|exists:venues,id',
            // The dashboard's name for the same thing. Both are accepted and both
            // are written, because a pin can arrive through either and
            // AccessScope::pinnedVenueIds() reads both — leaving one stale means a
            // venue you "removed" is still enforced.
            'assigned_location_ids'   => 'sometimes|nullable|array',
            'assigned_location_ids.*' => 'numeric|exists:venues,id',
            'business_name'          => 'nullable|string|max:150',
        ]);

        try {
            DB::beginTransaction();

            $updateData = array_filter([
                'name'   => $formData['name'] ?? null,
                'email'  => $formData['email'] ?? null,
                'status' => $formData['status'] ?? null,
                'phone'  => array_key_exists('phone', $formData) ? $formData['phone'] : null,
            ], fn ($value) => $value !== null);

            if (array_key_exists('country_id', $formData)) {
                $updateData['country_id'] = $formData['country_id'];
            }
            if (array_key_exists('assigned_country_ids', $formData)) {
                $updateData['assigned_country_ids'] = $formData['assigned_country_ids'];
            }
            if (array_key_exists('owner_user_id', $formData)) {
                $updateData['owner_user_id'] = $formData['owner_user_id'];
            }
            if (isset($formData['password'])) {
                $updateData['password'] = $formData['password'];
            }

            if ($updateData) {
                $user->update($updateData);
            }

            if (isset($formData['role'])) {
                $roleName = $formData['role'];
                $user->syncRoles([]);
                $user->assignRole($roleName);

                if (in_array($roleName, $providerTeamRoles, true)) {
                    // keep owner_user_id
                } elseif ($roleName === 'provider_admin') {
                    $user->update(['owner_user_id' => null]);
                } else {
                    $user->update(['owner_user_id' => null]);
                }
            }

            // Pins arrive as `venues` (API) or `assigned_location_ids` (dashboard).
            // Whichever came, write BOTH so they can never disagree.
            if (array_key_exists('assigned_location_ids', $formData)) {
                $formData['venues'] = array_values(array_unique(array_map(
                    'intval', (array) ($formData['assigned_location_ids'] ?? [])
                )));
            }

            if (isset($formData['venues'])) {
                $currentRole         = $user->roles->first();
                $roleName            = $currentRole ? $currentRole->name : null;
                $isSuperAdminOrAdmin = in_array($roleName, ['super_admin', 'admin', 'country_admin'], true);

                if ($isSuperAdminOrAdmin) {
                    $formData['venues'] = Venue::pluck('id')->toArray();
                }

                $user->venues()->sync($formData['venues'] ?? []);

                // Mirror into the json column the dashboard reads, so removing a
                // venue in the UI actually removes it everywhere.
                $user->forceFill([
                    'assigned_location_ids' => array_values(array_map('intval', $formData['venues'] ?? [])),
                ])->save();
            }

            if (! empty($formData['business_name']) && $user->ownedBusiness) {
                $user->ownedBusiness->update(['name' => $formData['business_name']]);
            }

            // Role or owner may have changed above; the membership has to follow
            // (moved to another business, or left for a platform role), or
            // AccessScope keeps reading the business they no longer belong to.
            if (isset($formData['role']) || array_key_exists('owner_user_id', $formData)) {
                \App\Support\BusinessMembershipSync::syncForStaff($user->fresh(), $user->fresh()->owner_user_id, auth()->id());
            }

            DB::commit();

            $user->load(['roles', 'venues', 'ownedBusiness']);
            $user->makeHidden(['roles']);
            $roles = $user->getRoleNames();

            return sendSuccess('User updated successfully', [
                'user'  => $this->formatTeamUser($user->fresh(['ownedBusiness'])),
                'roles' => $roles,
            ]);

        } catch (Exception $e) {
            DB::rollBack();
            Log::error('User update error: ' . $e->getMessage(), ['user_id' => $id, 'Exception' => $e]);

            return sendError('Something went wrong: ' . $e->getMessage());
        }
    }

    public function deleteUser($id)
    {
        $user = $this->findById($id);
        if (! $user) {
            return sendError('User not found');
        }
        if ($user->id == auth()->id() || $user->id == 1) {
            return sendError('This user cannot be deleted');
        }
        // A junior could delete an administrator here — rank now decides.
        if (! RoleGrantGuard::mayTouch(auth()->user(), $user)) {
            return RoleGrantGuard::denied('delete that user');
        }

        $user->delete();

        return sendSuccess('User deleted successfully');
    }

    public function forgotPassword($request)
    {
        $request->validate(['email' => 'required|email']);

        $token = Str::random(64);

        DB::table('password_reset_tokens')->updateOrInsert(
            ['email' => $request->email],
            ['token' => Hash::make($token), 'created_at' => Carbon::now()]
        );

        $domain = $request->domain ?? config('app.frontend_url');

        $url = $domain . '/reset-password?token=' . $token . '&email=' . urlencode($request->email);

        Mail::to($request->email)->send(new ForgetPasswordEmail($url));

        return response()->json(['message' => 'Password reset link sent']);
    }

    public function resetPassword($request)
    {
        $request->validate([
            'email'    => 'required|email',
            'token'    => 'required|string',
            'password' => 'required|confirmed|min:3',
        ]);

        $tokenRecord = DB::table('password_reset_tokens')->where('email', $request->email)->first();

        if (! $tokenRecord || ! Hash::check($request->token, $tokenRecord->token)) {
            return sendError('Invalid link please try again');
        }

        if (Carbon::parse($tokenRecord->created_at)->addMinutes(30)->isPast()) {
            return sendError('Link expired please try again');
        }

        $user = User::where('email', $request->email)->first();
        if (! $user) {
            return sendError('User not found');
        }

        $user->password = $request->password;
        $user->save();

        return sendSuccess('Password reset successfully');
    }

    public function customerForgotPassword($request)
    {
        $request->validate(['email' => 'required|email']);

        try {
            $user = $this->userModel->whereRelation('roles', 'name', 'customer')
                ->where('email', $request->email)
                ->first();

            if (! $user) {
                return sendError('No customer account found with this email address');
            }

            $otp = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

            DB::table('password_reset_tokens')->updateOrInsert(
                ['email' => $request->email],
                ['token' => Hash::make($otp), 'created_at' => Carbon::now()]
            );

            try {
                Mail::to($user->email)->send(new CustomerForgotPasswordOtpEmail($otp, $user));
            } catch (Exception $e) {
                Log::error("Failed to send OTP email: " . $e->getMessage(), ["Exception" => $e]);
                return sendError('Failed to send OTP email. Please try again later.');
            }

            return sendSuccess('OTP has been sent to your email address. Please check your inbox.');

        } catch (Exception $e) {
            Log::error("Customer forgot password error: " . $e->getMessage(), ["Exception" => $e]);
            return sendError('Something went wrong. Please try again later.');
        }
    }

    public function customerResetPassword($request)
    {
        $request->validate([
            'email'    => 'required|email',
            'otp'      => 'required|string|size:6',
            'password' => 'required|min:8|confirmed',
        ]);

        try {
            $user = $this->userModel->whereRelation('roles', 'name', 'customer')
                ->where('email', $request->email)
                ->first();

            if (! $user) {
                return sendError('No customer account found with this email address');
            }

            $otpRecord = DB::table('password_reset_tokens')
                ->where('email', $request->email)
                ->first();

            if (! $otpRecord) {
                return sendError('Invalid or expired OTP. Please request a new one.');
            }

            if (Carbon::parse($otpRecord->created_at)->addMinutes(10)->isPast()) {
                DB::table('password_reset_tokens')->where('email', $request->email)->delete();
                return sendError('OTP has expired. Please request a new one.');
            }

            if (! Hash::check($request->otp, $otpRecord->token)) {
                return sendError('Invalid OTP. Please check and try again.');
            }

            DB::beginTransaction();

            $user->password = $request->password;
            $user->save();

            DB::table('password_reset_tokens')->where('email', $request->email)->delete();

            DB::commit();

            return sendSuccess('Password has been reset successfully. You can now login with your new password.');

        } catch (Exception $e) {
            DB::rollBack();
            Log::error("Customer reset password error: " . $e->getMessage(), ["Exception" => $e]);
            return sendError('Something went wrong. Please try again later.');
        }
    }

    /**
     * Staff roles allowed to use the admin mobile app (excludes customer-only accounts).
     */
    protected function adminAppStaffRoleQuery($query)
    {
        return $query->whereIn('name', ['super_admin', 'admin', 'senior_employee', 'junior_employee', 'guard']);
    }

    /**
     * The roles the admin dashboard lets in.
     *
     * Deliberately NOT adminAppStaffRoleQuery()'s list: that one is the mobile app's,
     * and it covers five roles. The dashboard admits twelve — the same set its own
     * login gate enforces (apps/admin currentUser.js ALLOWED_PORTAL_ROLES) and the
     * same set the authenticated `admin` route group lists. Reusing the mobile app's
     * list here would have told every provider_admin — the business owners, who are
     * most of the people signing in — that they have no account.
     *
     * @return array<int, string>
     */
    protected function adminPortalRoles(): array
    {
        return array_values(array_unique(array_merge(
            (array) config('roles.staff_roles', []),
            (array) config('roles.provider_roles', []),
        )));
    }

    /** The one account that may reset through the dashboard, or null. */
    protected function findAdminPortalUser(string $email): ?User
    {
        return $this->userModel
            ->where('email', $email)
            ->whereHas('roles', fn ($q) => $q->whereIn('name', $this->adminPortalRoles()))
            ->first();
    }

    protected function adminResetAttemptKey(string $email): string
    {
        return 'admin-reset.attempts.'.sha1(strtolower($email));
    }

    /**
     * Email a six-digit code to an admin who has forgotten their password.
     *
     * The answer is the same whether or not the address belongs to an account. That
     * differs from the mobile app's version, which replies "No admin account found
     * with this email address" — on a staff portal that is a free oracle for which
     * addresses are privileged, which is exactly what a targeted phishing campaign
     * wants. The cost is that a typo looks like success; the code's absence is the
     * signal, and the UI says so.
     */
    public function adminForgotPassword($request)
    {
        $data = $request->validate(['email' => 'required|email']);
        $email = strtolower(trim($data['email']));

        $generic = 'If that address belongs to a Bookboll admin account, a reset code is on its way.';

        try {
            $user = $this->findAdminPortalUser($email);

            if (! $user) {
                return sendSuccess($generic);
            }

            if ($user->status === $this->userModel::BLOCKED) {
                // A blocked account must not be recoverable by its holder.
                return sendSuccess($generic);
            }

            $otp = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

            DB::table('password_reset_tokens')->updateOrInsert(
                ['email' => $user->email],
                ['token' => Hash::make($otp), 'created_at' => Carbon::now()]
            );

            // A fresh code gets a fresh budget of guesses.
            Cache::forget($this->adminResetAttemptKey($user->email));

            Mail::to($user->email)->send(new CustomerForgotPasswordOtpEmail($otp, $user));

            return sendSuccess($generic);
        } catch (Exception $e) {
            Log::error('Admin portal forgot password failed: '.$e->getMessage(), ['Exception' => $e]);

            return sendError('We could not send the code right now. Please try again shortly.');
        }
    }

    /**
     * Verify the code and set a new password.
     *
     * Two things the mobile app's twin does not do, both of which matter more on a
     * portal that can refund money and read every customer:
     *
     *   • the code is guessable. Six digits is a million combinations, and with no
     *     ceiling on attempts an attacker gets all of them inside the ten-minute
     *     window. Five tries, then the code dies.
     *   • every existing session is revoked. A password reset is what someone does
     *     when they suspect they have been compromised; leaving the attacker's
     *     bearer token alive makes the reset theatre.
     */
    public function adminResetPassword($request)
    {
        $data = $request->validate([
            'email'    => 'required|email',
            'otp'      => 'required|string|size:6',
            'password' => 'required|min:8|confirmed',
        ]);

        $email = strtolower(trim($data['email']));
        $invalid = 'That code is not valid or has expired. Please request a new one.';

        try {
            $user = $this->findAdminPortalUser($email);
            $record = $user
                ? DB::table('password_reset_tokens')->where('email', $user->email)->first()
                : null;

            // Unknown address, no code pending, or a blocked account all answer the
            // same way — see adminForgotPassword() on why.
            if (! $user || ! $record || $user->status === $this->userModel::BLOCKED) {
                return sendError($invalid, [], 422);
            }

            if (Carbon::parse($record->created_at)->addMinutes(10)->isPast()) {
                $this->clearAdminResetCode($user->email);

                return sendError('That code has expired. Please request a new one.', [], 422);
            }

            $attempts = (int) Cache::get($this->adminResetAttemptKey($user->email), 0);

            if ($attempts >= 5) {
                $this->clearAdminResetCode($user->email);

                return sendError('Too many incorrect codes. Please request a new one.', [], 429);
            }

            if (! Hash::check($data['otp'], $record->token)) {
                Cache::put($this->adminResetAttemptKey($user->email), $attempts + 1, now()->addMinutes(15));

                return sendError($invalid, [], 422);
            }

            DB::beginTransaction();

            $user->password = $data['password'];
            $user->save();

            // Whoever else was holding this account is now signed out.
            $user->tokens()->delete();

            DB::commit();

            $this->clearAdminResetCode($user->email);

            return sendSuccess('Your password has been reset. You can sign in with it now.');
        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Admin portal reset password failed: '.$e->getMessage(), ['Exception' => $e]);

            return sendError('Something went wrong. Please try again later.');
        }
    }

    protected function clearAdminResetCode(string $email): void
    {
        DB::table('password_reset_tokens')->where('email', $email)->delete();
        Cache::forget($this->adminResetAttemptKey($email));
    }

    public function adminAppForgotPassword($request)
    {
        $request->validate(['email' => 'required|email']);

        try {
            $user = $this->userModel
                ->where('email', $request->email)
                ->whereHas('roles', fn ($q) => $this->adminAppStaffRoleQuery($q))
                ->first();

            if (! $user) {
                return sendError('No admin account found with this email address');
            }

            $otp = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

            DB::table('password_reset_tokens')->updateOrInsert(
                ['email' => $request->email],
                ['token' => Hash::make($otp), 'created_at' => Carbon::now()]
            );

            try {
                Mail::to($user->email)->send(new CustomerForgotPasswordOtpEmail($otp, $user));
            } catch (Exception $e) {
                Log::error('Failed to send admin app OTP email: '.$e->getMessage(), ['Exception' => $e]);

                return sendError('Failed to send OTP email. Please try again later.');
            }

            return sendSuccess('OTP has been sent to your email address. Please check your inbox.');
        } catch (Exception $e) {
            Log::error('Admin app forgot password error: '.$e->getMessage(), ['Exception' => $e]);

            return sendError('Something went wrong. Please try again later.');
        }
    }

    public function adminAppResetPassword($request)
    {
        $request->validate([
            'email'    => 'required|email',
            'otp'      => 'required|string|size:6',
            'password' => 'required|min:8|confirmed',
        ]);

        try {
            $user = $this->userModel
                ->where('email', $request->email)
                ->whereHas('roles', fn ($q) => $this->adminAppStaffRoleQuery($q))
                ->first();

            if (! $user) {
                return sendError('No admin account found with this email address');
            }

            $otpRecord = DB::table('password_reset_tokens')
                ->where('email', $request->email)
                ->first();

            if (! $otpRecord) {
                return sendError('Invalid or expired OTP. Please request a new one.');
            }

            if (Carbon::parse($otpRecord->created_at)->addMinutes(10)->isPast()) {
                DB::table('password_reset_tokens')->where('email', $request->email)->delete();

                return sendError('OTP has expired. Please request a new one.');
            }

            if (! Hash::check($request->otp, $otpRecord->token)) {
                return sendError('Invalid OTP. Please check and try again.');
            }

            DB::beginTransaction();

            $user->password = $request->password;
            $user->save();

            DB::table('password_reset_tokens')->where('email', $request->email)->delete();

            DB::commit();

            return sendSuccess('Password has been reset successfully. You can now login with your new password.');
        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Admin app reset password error: '.$e->getMessage(), ['Exception' => $e]);

            return sendError('Something went wrong. Please try again later.');
        }
    }

    public function changePassword($request, $id)
    {
        $user = $this->findById($id);

        if (! $user) {
            return sendError("User not found");
        }

        $formData = $request->validate([
            'current_password' => 'required|current_password',
            'password'         => 'required|min:8|confirmed|different:current_password',
        ]);

        try {
            DB::beginTransaction();

            $user->password = $formData['password'];
            $user->save();

            DB::commit();

            return sendSuccess("Password changed successfully");

        } catch (Exception $e) {
            DB::rollBack();
            Log::error("Password change error: " . $e->getMessage(), ['user_id' => auth()->id(), 'Exception' => $e]);
            return sendError("Something went wrong: " . $e->getMessage());
        }
    }

    public function getPreferences()
    {
        $preferences = Preference::where('user_id', auth()->id())->first();
        $sports_types = SportsCategory::active()
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get(['id', 'name', 'slug', 'icon'])
            ->map(function (SportsCategory $category) {
                $slug = $category->slug ?: Str::slug($category->name);
                return [
                    'id'    => $slug,
                    'slug'  => $slug,
                    'name'  => $category->name,
                    'label' => $category->name,
                    'icon'  => $category->icon,
                ];
            })
            ->values();
        // Report consent from the users table, so the toggles show what campaigns will
        // actually honour rather than a stale copy in the preferences blob.
        $user = auth()->user();
        if ($preferences) {
            $preferences->notifications = array_merge($preferences->notifications ?? [], [
                'marketing_emails'   => (bool) $user?->marketing_email,
                'marketing_sms'      => (bool) $user?->marketing_sms,
                'marketing_whatsapp' => (bool) $user?->marketing_whatsapp,
            ]);
        }

        return sendSuccess('Preferences fetched successfully', compact('preferences', 'sports_types'));
    }

    public function updatePreferences($request)
    {
        $user = auth()->user();

        $formData = $request->validate([
            'notifications.booking_emails'     => 'nullable|boolean',
            'notifications.sms_reminders'      => 'nullable|boolean',
            'notifications.marketing_emails'   => 'nullable|boolean',
            'notifications.marketing_sms'      => 'nullable|boolean',
            'notifications.marketing_whatsapp' => 'nullable|boolean',
            'favorite_sports'                  => 'nullable|array',
            'favorite_sports.*'                => 'required|string',
            'language'                         => 'nullable|string',
            'currency'                         => 'nullable|string',
        ]);

        $preferences = $user->preferences ?? Preference::create(['user_id' => $user->id]);

        if (isset($formData['notifications'])) {
            $currentNotifications       = $preferences->notifications ?? [];
            $preferences->notifications = array_merge($currentNotifications, $formData['notifications']);
        }

        if (isset($formData['favorite_sports'])) {
            $preferences->favorite_sports = $formData['favorite_sports'];
        }

        if (isset($formData['language'])) {
            $preferences->language = $formData['language'];
        }

        if (isset($formData['currency'])) {
            $preferences->currency = $formData['currency'];
        }

        $preferences->save();

        // MARKETING CONSENT lives on the users table, because that is what campaign
        // eligibility reads. Writing it only into the preferences JSON — as this
        // endpoint used to — meant a customer could switch "email me offers" on and
        // never actually become reachable, and could never switch it off either.
        $consent = [];
        foreach ([
            'marketing_emails'   => 'marketing_email',
            'marketing_sms'      => 'marketing_sms',
            'marketing_whatsapp' => 'marketing_whatsapp',
        ] as $key => $column) {
            if (array_key_exists($key, $formData['notifications'] ?? [])) {
                $consent[$column] = (bool) $formData['notifications'][$key];
            }
        }
        if ($consent) {
            // Timestamped: when consent was given or withdrawn is the part that has to
            // be answerable later.
            $user->forceFill($consent + ['marketing_updated_at' => now()])->save();
        }

        $user->load('preferences');

        return sendSuccess('Preferences updated successfully', compact('user', 'preferences'));
    }

    public function updateProfileImage($request)
    {
        $request->validate(["image" => "required|image"]);
        $image       = $request->image->store("profile-images", "public");
        $user        = auth()->user();
        $user->image = $image;
        $user->save();

        $image = $user->image;
        return sendSuccess("Profile image updated successfully", compact('image'));
    }

    public function subscribeOneSignal($request)
    {
        $user = auth()->user();

        if (! $user) {
            return sendError('User not authenticated', [], 401);
        }

        $formData = $request->validate([
            'onesignal_id' => 'required|string|max:255',
            'device_type'  => 'nullable|string|in:ios,android,web',
            'device_info'  => 'nullable|string|max:255',
        ]);

        // Which OneSignal app issued this id — taken from the `from` header the
        // apps already send, never from the body: a client must not be able to
        // file its device under an app whose credentials cannot reach it.
        $app = OnesignalSubscription::appFromSurface($request->header('from'));

        try {
            DB::beginTransaction();

            $existingSubscription = OnesignalSubscription::where('onesignal_id', $formData['onesignal_id'])
                ->where('user_id', $user->id)
                ->first();

            if ($existingSubscription) {
                $existingSubscription->update([
                    'is_active'   => true,
                    'app'         => $app,
                    'device_type' => $formData['device_type'] ?? $existingSubscription->device_type,
                    'device_info' => $formData['device_info'] ?? $existingSubscription->device_info,
                ]);
                $subscription = $existingSubscription;
            } else {
                $otherUserSubscription = OnesignalSubscription::where('onesignal_id', $formData['onesignal_id'])
                    ->where('user_id', '!=', $user->id)
                    ->first();

                if ($otherUserSubscription) {
                    $otherUserSubscription->update(['user_id' => $user->id, 'app' => $app, 'is_active' => true]);
                    $subscription = $otherUserSubscription;
                } else {
                    $subscription = OnesignalSubscription::create([
                        'user_id'      => $user->id,
                        'onesignal_id' => $formData['onesignal_id'],
                        'app'          => $app,
                        'device_type'  => $formData['device_type'] ?? null,
                        'device_info'  => $formData['device_info'] ?? null,
                        'is_active'    => true,
                    ]);
                }
            }

            DB::commit();

            return sendSuccess('Successfully subscribed to push notifications', [
                'subscription'  => $subscription,
                'total_devices' => $user->activeOnesignalSubscriptions()->count(),
            ]);

        } catch (Exception $e) {
            DB::rollBack();
            Log::error("OneSignal subscription error: " . $e->getMessage(), ["Exception" => $e]);
            return sendError('Something went wrong while subscribing to push notifications');
        }
    }

    public function importUsersFromExcel($filename = 'bookboll_live_users.xls')
    {
        $filePath = public_path($filename);

        if (! file_exists($filePath)) {
            return sendError("Excel file not found: {$filename}. Please place the file in the public folder.");
        }

        try {
            $import = new \App\Imports\UsersImport();

            Excel::import($import, $filePath);

            $successCount = $import->getSuccessCount();
            $errorCount   = $import->getErrorCount();
            $skippedCount = $import->getSkippedCount();
            $errors       = $import->getErrors();

            $message = "Import completed. Successfully imported {$successCount} user(s)";
            if ($skippedCount > 0) {
                $message .= ", {$skippedCount} skipped";
            }
            if ($errorCount > 0) {
                $message .= ", {$errorCount} error(s) occurred";
            }

            $summary  = [
                'success_count'   => $successCount,
                'error_count'     => $errorCount,
                'skipped_count'   => $skippedCount,
                'total_processed' => $successCount + $errorCount + $skippedCount,
            ];

            if (! empty($errors)) {
                $summary['errors'] = $errors;
            }

            return sendSuccess($message, $summary);

        } catch (Exception $e) {
            Log::error("User import error: " . $e->getMessage(), [
                'file'      => $filename,
                'exception' => $e,
            ]);
            return sendError("Failed to import users: " . $e->getMessage());
        }
    }

    public function deleteAdminAppAccount($request)
    {
        $request->validate(['password' => 'required|current_password']);

        $user = auth()->user();

        if ($user->id === 1) {
            return sendError('This account cannot be deleted.');
        }

        if ($user->hasRole('super_admin')) {
            return sendError('Super admin accounts cannot be deleted from the app.');
        }

        $staffRoles = ['admin', 'senior_employee', 'junior_employee', 'guard'];

        if (! $user->hasAnyRole($staffRoles)) {
            return sendError('This account is not eligible for deletion from the admin app.');
        }

        try {
            DB::beginTransaction();

            $remainingRoles = $user->getRoleNames()->diff($staffRoles);
            $hasNonStaffRoles = $remainingRoles->isNotEmpty();
            $deletionType = $hasNonStaffRoles
                ? DeletedAccount::TYPE_ACCESS_REMOVED
                : DeletedAccount::TYPE_ACCOUNT_DELETED;

            $this->recordDeletedAccount($user, $request, $deletionType, $staffRoles, DeletedAccount::SOURCE_ADMIN_MOBILE_APP);

            $user->tokens()
                ->whereIn('name', ['bookboll-admin-mobile-app-api-token', 'bookboll-admin-web-api-token'])
                ->delete();

            $user->onesignalSubscriptions()->delete();
            $user->venues()->detach();

            foreach ($staffRoles as $role) {
                if ($user->hasRole($role)) {
                    $user->removeRole($role);
                }
            }

            if ($hasNonStaffRoles) {
                DB::commit();

                return sendSuccess('Your admin app account has been removed successfully.');
            }

            $user->tokens()->delete();
            $user->delete();

            DB::commit();

            return sendSuccess('Your account has been deleted successfully.', [], 200, false);

        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Admin app account deletion error: '.$e->getMessage(), [
                'user_id'   => $user->id,
                'Exception' => $e,
            ]);

            return sendError('Failed to delete account. Please try again later.');
        }
    }

    public function deleteCustomerAccount($request)
    {
        $user = auth()->user();

        if (! $user || ! $user->hasRole('customer')) {
            return sendError('This account is not eligible for deletion from the customer app.');
        }

        try {
            DB::beginTransaction();

            $remainingRoles = $user->getRoleNames()->diff(['customer']);
            $hasOtherRoles = $remainingRoles->isNotEmpty();
            $deletionType = $hasOtherRoles
                ? DeletedAccount::TYPE_ACCESS_REMOVED
                : DeletedAccount::TYPE_ACCOUNT_DELETED;

            $this->recordDeletedAccount(
                $user,
                $request,
                $deletionType,
                ['customer'],
                DeletedAccount::SOURCE_CUSTOMER_MOBILE_APP
            );

            $user->tokens()
                ->whereIn('name', [
                    'bookboll-customer-login-api-token',
                    'bookboll-api-token',
                    'auth-token',
                ])
                ->delete();

            $user->removeRole('customer');

            if ($hasOtherRoles) {
                DB::commit();

                return sendSuccess('Your customer account has been removed successfully.');
            }

            $user->tokens()->delete();
            $user->onesignalSubscriptions()->delete();
            $user->status = User::INACTIVE;
            $user->save();

            DB::commit();

            return sendSuccess('Your account has been deleted successfully.');

        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Customer account deletion error: '.$e->getMessage(), [
                'user_id'   => $user->id,
                'Exception' => $e,
            ]);

            return sendError('Failed to delete account. Please try again later.');
        }
    }

    protected function customerAccountWasDeleted(User $user): bool
    {
        if ($user->hasRole('customer')) {
            return false;
        }

        return DeletedAccount::query()
            ->where('user_id', $user->id)
            ->where('source', DeletedAccount::SOURCE_CUSTOMER_MOBILE_APP)
            ->exists();
    }

    protected function restoreCustomerAccount(User $user, array $attributes = []): User
    {
        $user->update(array_merge($attributes, [
            'status' => User::ACTIVE,
        ]));

        if (! $user->hasRole('customer')) {
            $user->assignRole('customer');
        }

        $user->refresh();

        if (! $user->preferences) {
            Preference::create([
                'user_id'         => $user->id,
                'notifications'   => [
                    'booking_emails'   => true,
                    'sms_reminders'    => false,
                    'marketing_emails' => true,
                ],
                'favorite_sports' => [],
                'language'        => 'english',
                'currency'        => 'QAR',
            ]);
        }

        return $user;
    }

    protected function recordDeletedAccount(User $user, $request, string $deletionType, array $rolesToRemove, string $source = DeletedAccount::SOURCE_ADMIN_MOBILE_APP): DeletedAccount
    {
        $user->loadMissing('venues');

        $removedRoles = $user->getRoleNames()->intersect($rolesToRemove)->values()->all();

        return DeletedAccount::create([
            'user_id'          => $user->id,
            'name'             => $user->name,
            'email'            => $user->email,
            'phone'            => $user->phone,
            'deletion_type'    => $deletionType,
            'source'           => $source,
            'roles'            => $removedRoles,
            'assigned_venues'  => $user->venues->map(fn ($venue) => [
                'id'     => $venue->id,
                'name'   => $venue->name,
                'status' => $venue->status,
            ])->values()->all(),
            'user_snapshot'    => [
                'id'                => $user->id,
                'name'              => $user->name,
                'email'             => $user->email,
                'phone'             => $user->phone,
                'status'            => $user->status,
                'gender'            => $user->gender,
                'nationality'       => $user->nationality,
                'address'           => $user->address,
                'image'             => $user->image,
                'date_of_birth'     => $user->date_of_birth,
                'loyalty_tier_id'   => $user->loyalty_tier_id,
                'email_verified_at' => $user->email_verified_at,
                'created_at'        => $user->created_at,
                'updated_at'        => $user->updated_at,
                'all_roles'         => $user->getRoleNames()->values()->all(),
            ],
            'ip_address'       => $request->ip(),
            'user_agent'       => $request->userAgent(),
            'deleted_at'       => now(),
        ]);
    }

    public function registerAdminAppUser($request)
    {
        $formData = $request->validate([
            'name'     => 'required|string|max:100',
            'email'    => 'required|email|unique:registration_requests,email',
            'phone'    => 'required|min:6|max:20',
            'password' => 'required|min:8|confirmed',
        ]);

        try {
            DB::beginTransaction();

            $existingUser = $this->userModel->where('email', $formData['email'])->first();
            if ($existingUser) {
                $existingRequest = RegistrationRequest::where('email', $formData['email'])
                    ->where('status', RegistrationRequest::PENDING)
                    ->first();

                if ($existingRequest) {
                    return sendError("A registration request with this email is already pending approval.");
                }
            }

            $registrationRequest = RegistrationRequest::create([
                'name'     => $formData['name'],
                'email'    => $formData['email'],
                'phone'    => $formData['phone'],
                'password' => $formData['password'],
                'status'   => RegistrationRequest::PENDING,
            ]);

            DB::commit();

            return sendSuccess("Registration request submitted successfully. Your request is pending admin approval.", [
                'registration_request' => [
                    'id'         => $registrationRequest->id,
                    'name'       => $registrationRequest->name,
                    'email'      => $registrationRequest->email,
                    'phone'      => $registrationRequest->phone,
                    'status'     => $registrationRequest->status,
                    'created_at' => $registrationRequest->created_at,
                ],
            ]);

        } catch (Exception $e) {
            DB::rollBack();
            Log::error("Admin app user registration error: " . $e->getMessage(), ["Exception" => $e]);
            return sendError("Something went wrong please try again later");
        }
    }
    public function listPendingUsers($request)
    {
        $search           = $request->search ?? null;
        $records_per_page = $request->records_per_page ?? 10;

        $registrationRequests = RegistrationRequest::where('status', RegistrationRequest::PENDING)
            ->when($search, function ($query, $search) {
                $query->where("name", "LIKE", "%{$search}%")
                    ->orWhere("email", "LIKE", "%{$search}%")
                    ->orWhere("phone", "LIKE", "%{$search}%");
            })
            ->latest()
            ->paginate($records_per_page);

        $roles  = Role::select(['id', 'name'])->get();
        $venues = Venue::select(['id', 'name'])->active()->get();

        return sendSuccess("Pending registration requests fetched successfully", [
            'registration_requests' => $registrationRequests,
            'roles'                 => $roles,
            'venues'                => $venues,
            'search'                => $search,
        ]);
    }

    public function approveUser($request, $id)
    {
        $registrationRequest = RegistrationRequest::find($id);

        if (! $registrationRequest) {
            return sendError("Registration request not found");
        }

        if ($registrationRequest->status !== RegistrationRequest::PENDING) {
            return sendError("Registration request is not in pending status");
        }

        $formData = $request->validate([
            'role'     => 'required|exists:roles,name',
            'venues'   => 'nullable|array',
            'venues.*' => 'numeric|exists:venues,id',
        ]);

        // Approving a pending signup CREATES an account with the role you name.
        // `role` is validated only as "a role that exists", so without this an
        // approver could approve someone (or themselves) straight to super admin.
        if (! RoleGrantGuard::mayGrant(auth()->user(), $formData['role'])) {
            return RoleGrantGuard::denied('approve a registration with that role');
        }

        try {
            DB::beginTransaction();

            $role     = Role::where('name', $formData['role'])->firstOrFail();
            $roleName = $role->name;

            $isSuperAdminOrAdmin = in_array($roleName, ['super_admin', 'admin']);

            $existingUser = $this->userModel->where('email', $registrationRequest->email)->first();

            if ($existingUser) {
                $existingUser->syncRoles([]);
                $existingUser->assignRole($roleName);

                if (! empty($formData['venues'])) {
                    $existingUser->venues()->sync($formData['venues']);
                }

                if ($existingUser->status !== $this->userModel::ACTIVE) {
                    $existingUser->status = $this->userModel::ACTIVE;
                    $existingUser->save();
                }

                $user = $existingUser;
            } else {
                $user = $this->userModel->create([
                    'status' => $this->userModel::ACTIVE,
                    'name'   => $registrationRequest->name,
                    'email'  => $registrationRequest->email,
                    'phone'  => $registrationRequest->phone,
                ]);

                DB::table('users')
                    ->where('id', $user->id)
                    ->update(['password' => $registrationRequest->password]);

                $user->refresh();

                $user->assignRole($roleName);

                if (! empty($formData['venues'])) {
                    $user->venues()->sync($formData['venues']);
                }

                Preference::create([
                    'user_id'         => $user->id,
                    'notifications'   => [
                        'booking_emails'   => true,
                        'sms_reminders'    => false,
                        'marketing_emails' => true,
                    ],
                    'favorite_sports' => [],
                    'language'        => 'english',
                    'currency'        => 'QAR',
                ]);
            }

            $registrationRequest->delete();

            DB::commit();

            try {
                Mail::to($user->email)->send(new UserWelcomeEmail($user, null));
            } catch (Exception $e) {
                Log::error("Failed to send welcome email after approval: " . $e->getMessage(), ["Exception" => $e]);
            }

            $user->load(['roles', 'venues']);
            $user->makeHidden(['roles']);
            $roles = $user->getRoleNames();

            $message = $existingUser
                ? "Registration request approved. User already existed - roles and venues have been updated."
                : "Registration request approved. New user created successfully.";

            return sendSuccess($message, compact('user', 'roles'));

        } catch (Exception $e) {
            DB::rollBack();
            Log::error("Registration request approval error: " . $e->getMessage(), ["Exception" => $e]);
            return sendError("Something went wrong: " . $e->getMessage());
        }
    }

    public function rejectUser($request, $id)
    {
        $registrationRequest = RegistrationRequest::find($id);

        if (! $registrationRequest) {
            return sendError("Registration request not found");
        }

        if ($registrationRequest->status !== RegistrationRequest::PENDING) {
            return sendError("Registration request is not in pending status");
        }

        $formData = $request->validate(['reason' => 'nullable|string|max:500']);

        try {
            $rejectionReason = $formData['reason'] ?? null;

            try {
                $userForEmail = new User([
                    'name'  => $registrationRequest->name,
                    'email' => $registrationRequest->email,
                ]);

                Mail::to($registrationRequest->email)->send(new UserRejectionEmail($userForEmail, $rejectionReason));
            } catch (Exception $e) {
                Log::error("Failed to send rejection email: " . $e->getMessage(), ["Exception" => $e]);
            }

            $registrationRequest->delete();

            return sendSuccess("Registration request rejected and deleted successfully. Rejection email has been sent.");

        } catch (Exception $e) {
            Log::error("Registration request rejection error: " . $e->getMessage(), ["Exception" => $e]);
            return sendError("Something went wrong: " . $e->getMessage());
        }
    }

    protected function ensurePublicUuid(User $user): void
    {
        if (! empty($user->uuid)) {
            return;
        }

        $user->uuid = (string) \Illuminate\Support\Str::uuid();
        $user->save();
    }
}
