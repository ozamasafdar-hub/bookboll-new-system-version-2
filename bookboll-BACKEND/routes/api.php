<?php

use App\Http\Controllers\AddOnController;
use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\BookingController;
use App\Http\Controllers\BookingPayLinkController;
use App\Http\Controllers\BusinessController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\Admin\GeographyController;
use App\Http\Controllers\Admin\AnalyticsController;
use App\Http\Controllers\Admin\OverviewController;
use App\Http\Controllers\Admin\PaymentAccountController;
use App\Http\Controllers\Admin\StorefrontSortController;
use App\Http\Controllers\Admin\ReferenceDataController;
use App\Http\Controllers\Admin\NotificationEmailController;
use App\Http\Controllers\Admin\ProviderPlanController;
use App\Http\Controllers\Admin\ProviderSenderController;
use App\Http\Controllers\Admin\SiteSettingsController;
use App\Http\Controllers\Admin\WhatsappChannelController;
use App\Http\Controllers\Admin\NotificationTemplateController;
use App\Http\Controllers\BusinessNotificationTemplateController;
use App\Http\Controllers\CheckInController;
use App\Http\Controllers\NotificationFeedController;
use App\Http\Controllers\GiftCardController;
use App\Http\Controllers\HelperController;
use App\Http\Controllers\ListingController;
use App\Http\Controllers\ClassMembershipController;
use App\Http\Controllers\ApprovalsController;
use App\Http\Controllers\WalletPayoutController;
use App\Http\Controllers\EventModerationController;
use App\Http\Controllers\CommunityModerationController;
use App\Http\Controllers\CustomerSocialController;
use App\Http\Controllers\CustomerCommunityController;
use App\Http\Controllers\CustomerGameController;
use App\Http\Controllers\CustomerMessageController;
use App\Http\Controllers\HostPayoutController;
use App\Http\Controllers\CustomerProgramController;
use App\Http\Controllers\PublicProfileController;
use App\Http\Controllers\LocationCityController;
use App\Http\Controllers\LocationController;
use App\Http\Controllers\LocationCountryController;
use App\Http\Controllers\LocationStateController;
use App\Http\Controllers\LoyaltyPointController;
use App\Http\Controllers\NewsletterController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\PostCategoryController;
use App\Http\Controllers\PostController;
use App\Http\Controllers\MobileAppAdoptionController;
use App\Http\Controllers\OwnSiteReviewController;
use App\Http\Controllers\PromocodeController;
use App\Http\Controllers\ProviderSettingsController;
use App\Http\Controllers\ResourceController;
use App\Http\Controllers\OAuthBrokerController;
use App\Http\Controllers\ReviewController;
use App\Http\Controllers\RolePermissionController;
use App\Http\Controllers\SettingController;
use App\Http\Controllers\SpecialDayController;
use App\Http\Controllers\SportsCategoryController;
use App\Http\Controllers\SportsTypeController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\MarketingController;
use App\Http\Controllers\MarketplaceCatalogController;
use App\Http\Controllers\VenueController;
use App\Http\Controllers\UploadController;
use App\Http\Controllers\WhatsappController;
use App\Http\Controllers\WhatsappWebhookController;
use Illuminate\Support\Facades\Route;

// Public, password-based entry points — keyed by IP. Unlimited attempts here are a
// credential-stuffing and reset-spam surface; the budgets below stop automated abuse
// while leaving room for a human fat-fingering a password or retrying a slow send.
// (A whole office or a carrier-NAT may share one IP, so these are deliberately not
// as tight as a single user's real need — tune down if you add username-keyed limits.)
Route::post('login', [UserController::class, 'login'])->middleware('throttle:10,1')->name('api.login');
Route::post('register', [UserController::class, 'register'])->middleware('throttle:10,10')->name('api.register');
Route::post('update-profile', [UserController::class, 'updateProfile'])->middleware('auth:sanctum')->name('update-profile');
Route::post('change-password', [UserController::class, 'changePassword'])->middleware('auth:sanctum')->name('change-password');
Route::post('forgot-password', [UserController::class, 'sendResetLinkEmail'])->middleware('throttle:8,10')->name('forgot-password');
Route::post('reset-password', [UserController::class, 'reset'])->middleware('throttle:10,10')->name('reset-password');

Route::post('admin-app/register', [UserController::class, 'registerAdminAppUser'])->middleware('throttle:10,10')->name('admin-app.register');
// Admin DASHBOARD password reset (apps/admin). A six-digit code by email, so there
// is no reset page to route to and no link whose host could be tampered with.
//
// Separate from admin-app/* below, which is the mobile app's and only admits five
// staff roles — this one admits the twelve the dashboard's own login gate does,
// business owners included. Throttled because both ends are public and one of them
// sends mail.
Route::post('admin/forgot-password', [UserController::class, 'adminForgotPassword'])
    // Keyed by IP, and a whole office shares one — so the budget has to cover a few
    // people plus the "did it send?" retries, while still making an inbox hard to bury.
    ->middleware('throttle:8,10')->name('admin.forgot-password');
Route::post('admin/reset-password', [UserController::class, 'adminResetPassword'])
    ->middleware('throttle:10,10')->name('admin.reset-password');

Route::post('admin-app/forgot-password', [UserController::class, 'adminAppForgotPassword'])
    ->middleware('throttle:8,10')->name('admin-app.forgot-password');
Route::post('admin-app/reset-password', [UserController::class, 'adminAppResetPassword'])
    ->middleware('throttle:10,10')->name('admin-app.reset-password');

// Bookboll for Business — public provider signup
Route::prefix('business')->name('business.')->group(function () {
    Route::post('register', [BusinessController::class, 'register'])->middleware('throttle:10,10')->name('register');
    Route::get('username-available', [BusinessController::class, 'usernameAvailable'])->name('username-available');

    // Email ownership proof, taken BEFORE the account is written (see
    // EmailVerificationService). Public, and each one sends mail or guesses at a
    // secret, so both are throttled per IP on top of the service's own per-address
    // cooldown and attempt budget.
    Route::post('email/send-otp', [BusinessController::class, 'sendEmailOtp'])
        ->middleware('throttle:6,10')->name('email.send-otp');
    Route::post('email/verify-otp', [BusinessController::class, 'verifyEmailOtp'])
        ->middleware('throttle:15,10')->name('email.verify-otp');
    Route::get('countries', [BusinessController::class, 'countries'])->name('countries');
});

Route::middleware(['auth:sanctum'])->group(function () {

    Route::post('logout', [UserController::class, 'logout'])->name('api.logout');
    Route::post('admin-app/delete-account', [UserController::class, 'deleteAdminAppAccount'])->name('admin-app.delete-account');
    Route::get('me', [UserController::class, 'me'])->name('me');
    Route::post('subscribe-onesignal', [UserController::class, 'subscribeOneSignal'])->name('customer.subscribe-onesignal');

    Route::put('business/brand', [BusinessController::class, 'updateBrand'])->name('business.brand.update');

    // Settings → Business Profile / My Account / Notifications. Each acts on
    // the signed-in person and the business they own, never on a submitted id.
    // These are the PERSONAL preferences; notification-templates below is the
    // separate per-business copy that goes out to customers.
    // Mobile-app adoption. The audience is fixed by the route and the account
    // by the session — a body cannot name either. Registration is throttled
    // because an authenticated native call is the only evidence we have, and
    // a chatty or hostile client must not be able to flood it.
    Route::post('customer/mobile-app/sessions', [MobileAppAdoptionController::class, 'registerCustomer'])
        ->middleware('throttle:20,1')->name('customer.mobile-app.sessions');
    Route::get('customer/mobile-app-status', [MobileAppAdoptionController::class, 'customerStatus'])
        ->name('customer.mobile-app-status');
    Route::put('customer/mobile-app-preferences', [MobileAppAdoptionController::class, 'setCustomerPreference'])
        ->name('customer.mobile-app-preferences');

    Route::post('admin/mobile-app/sessions', [MobileAppAdoptionController::class, 'registerAdmin'])
        ->middleware('throttle:20,1')->name('admin.mobile-app.sessions');
    Route::get('admin/mobile-app-status', [MobileAppAdoptionController::class, 'adminStatus'])
        ->name('admin.mobile-app-status');
    Route::put('admin/mobile-app-preferences', [MobileAppAdoptionController::class, 'setAdminPreference'])
        ->name('admin.mobile-app-preferences');

    // Adoption reporting, behind a capability and scoped inside the service.
    Route::get('admin/mobile-app/adoption-report', [MobileAppAdoptionController::class, 'report'])
        ->name('admin.mobile-app.adoption-report');

    Route::get('business/settings', [ProviderSettingsController::class, 'show'])->name('business.settings.show');
    Route::put('business/settings/profile', [ProviderSettingsController::class, 'updateBusiness'])->name('business.settings.profile');
    Route::put('business/settings/account', [ProviderSettingsController::class, 'updateAccount'])->name('business.settings.account');
    Route::put('business/settings/notifications', [ProviderSettingsController::class, 'updateNotifications'])->name('business.settings.notifications');

    Route::get('business/notification-templates', [BusinessNotificationTemplateController::class, 'show'])->name('business.notification-templates.show');
    Route::put('business/notification-templates', [BusinessNotificationTemplateController::class, 'update'])->name('business.notification-templates.update');

    Route::prefix('business/team')->name('business.team.')->group(function () {
        Route::get('/', [BusinessController::class, 'teamIndex'])->name('index');
        Route::post('/', [BusinessController::class, 'teamStore'])->name('store');
        Route::put('{user}', [BusinessController::class, 'teamUpdate'])->name('update');
        Route::delete('{user}', [BusinessController::class, 'teamDestroy'])->name('destroy');
    });

    /*
    |--------------------------------------------------------------------------
    | Admin portal  (/api/admin/*)
    |--------------------------------------------------------------------------
    | Everyone who can open the dashboard reaches this group — platform staff AND
    | providers. What each may DO is decided per section by the `capability`
    | middleware, mirroring PAGE_CAP in the dashboard's lib/permissions.js, and
    | WHICH ROWS they see is decided by App\Support\AccessScope (ownership for
    | providers, jurisdiction for platform roles).
    */
    Route::prefix('admin')->middleware(['checkRoles:super_admin,admin,country_admin,platform_staff,provider_admin,facility_admin,provider_supervisor,staff,provider_viewer,senior_employee,junior_employee,guard'])->group(function () {

        // Marketplace blog (Bookboll HQ)
        Route::middleware('capability:manage_platform_blog')->group(function () {
            Route::apiResource('post-categories', PostCategoryController::class);
            Route::apiResource('posts', PostController::class);
            Route::post('posts/change-status', [PostController::class, 'changeStatus']);
            Route::get('posts-tags', [PostController::class, 'postTagsList']);
        });

        // Shared scope/filter bundle — any admin role, already narrowed per caller.
        Route::get('reference-data', [ReferenceDataController::class, 'index'])->name('reference-data');

        // Landing page: onboarding checklist + the caller's venues (Home, SetupCoach).
        Route::get('overview', [OverviewController::class, 'index'])->name('overview');

        // Analytics dashboard dataset (/dashboard) — raw collections, scoped.
        Route::get('dashboard/analytics', [AnalyticsController::class, 'index'])->name('dashboard.analytics');
        // Totals the DATABASE computed, across every authorised record, with an
        // explicit coverage statement. The raw-array endpoint above is
        // unchanged for existing consumers.
        Route::get('dashboard/analytics/aggregates', [AnalyticsController::class, 'aggregates'])->name('dashboard.analytics.aggregates');

        // A provider's connected bank account (Listings → payment account).
        Route::middleware('capability:manage_listings,manage_platform_catalog')->group(function () {
            Route::get('payment-accounts', [PaymentAccountController::class, 'show'])->name('payment-accounts.show');
            Route::post('payment-accounts', [PaymentAccountController::class, 'store'])->name('payment-accounts.store');
            Route::put('payment-accounts/{id}', [PaymentAccountController::class, 'update'])->name('payment-accounts.update');
            Route::delete('payment-accounts/{id}', [PaymentAccountController::class, 'destroy'])->name('payment-accounts.destroy');

            // Customer-site fallback ordering: the marketplace for platform admins,
            // their own white-label site for providers.
            Route::get('storefront-sort', [StorefrontSortController::class, 'show'])->name('storefront-sort.show');
            Route::put('storefront-sort', [StorefrontSortController::class, 'update'])->name('storefront-sort.update');
        });

        // "My Website": branding, pages, banners, domain, embed and tracking.
        Route::middleware('capability:manage_website,manage_platform_website')->group(function () {
            Route::get('site-settings', [SiteSettingsController::class, 'show'])->name('site-settings.show');
            Route::put('site-settings', [SiteSettingsController::class, 'update'])->name('site-settings.update');

            // The custom domain is a paid add-on: Stripe takes the one-time fee and
            // the webhook connects the domain. Nothing here writes it directly.
            Route::post('site-settings/domain/checkout', [ProviderPlanController::class, 'domainCheckout'])->name('site-settings.domain.checkout');
            Route::get('site-settings/domain/checkout/{session}', [ProviderPlanController::class, 'confirmDomain'])->name('site-settings.domain.confirm');
            Route::post('site-settings/domain/verify', [ProviderPlanController::class, 'verifyDomain'])->name('site-settings.domain.verify');
        });

        /*
        | WhatsApp channel + marketing campaigns.
        |
        | The sender (a provider's own number, or Bookboll's) is resolved server-side
        | from the caller — the browser never names it, so a provider cannot send from
        | the platform number. Automated booking notifications are separate and always
        | use Bookboll's number: see notifications/whatsapp below.
        */
        // The sender's own settings — the /whatsapp page, gated exactly as PAGE_CAP
        // gates it in the dashboard (manage_website / manage_platform_website).
        Route::middleware('capability:manage_website,manage_platform_website')->group(function () {
            Route::get('whatsapp/config', [WhatsappChannelController::class, 'showConfig'])->name('whatsapp.config.show');
            Route::put('whatsapp/config', [WhatsappChannelController::class, 'saveConfig'])->name('whatsapp.config.save');
            Route::get('whatsapp/outbound', [WhatsappChannelController::class, 'outbound'])->name('whatsapp.outbound');
        });

        // Sending and the approval queue are driven from BOTH screens — the WhatsApp
        // page (templates, queue) and the Customers composer (campaigns) — so either
        // page's capability admits them. Which sender they use is never the caller's
        // choice; it is resolved from their own scope.
        Route::middleware('capability:manage_website,manage_platform_website,manage_customers')->group(function () {
            Route::post('whatsapp/action', [WhatsappChannelController::class, 'action'])->name('whatsapp.action');

            Route::get('whatsapp/queue', [WhatsappChannelController::class, 'queueIndex'])->name('whatsapp.queue.index');
            Route::post('whatsapp/queue', [WhatsappChannelController::class, 'queueStore'])->name('whatsapp.queue.store');
            Route::post('whatsapp/queue/process', [WhatsappChannelController::class, 'queueProcess'])->name('whatsapp.queue.process');
            Route::post('whatsapp/queue/{id}/dismiss', [WhatsappChannelController::class, 'queueDismiss'])->name('whatsapp.queue.dismiss');
            Route::post('whatsapp/queue/{id}/cancel', [WhatsappChannelController::class, 'queueCancel'])->name('whatsapp.queue.cancel');
        });

        // A provider's own email sending identity (Marketing → Email sender), gated
        // exactly as PAGE_CAP gates that page in the dashboard.
        Route::middleware('capability:manage_website,manage_platform_website')->group(function () {
            Route::get('email-sender', [ProviderSenderController::class, 'showEmail'])->name('email-sender.show');
            Route::put('email-sender', [ProviderSenderController::class, 'saveEmail'])->name('email-sender.save');
            Route::post('email-sender/test', [ProviderSenderController::class, 'testEmail'])->name('email-sender.test');
        });

        // A provider's own site blog. Same table as the Bookboll blog above, split by
        // owner_user_id — which is taken from the session, so the two can never mix.
        Route::middleware('capability:manage_website_blog,manage_website,manage_platform_website')->group(function () {
            Route::get('site-blog', [ProviderSenderController::class, 'blogIndex'])->name('site-blog.index');
            Route::get('site-blog/count', [ProviderSenderController::class, 'blogCount'])->name('site-blog.count');
            Route::post('site-blog', [ProviderSenderController::class, 'blogStore'])->name('site-blog.store');
            Route::put('site-blog/{id}', [ProviderSenderController::class, 'blogUpdate'])->name('site-blog.update');
            Route::delete('site-blog/{id}', [ProviderSenderController::class, 'blogDestroy'])->name('site-blog.destroy');
        });

        // The shared custom-type catalogue. Reading is open to any admin role (Listings
        // and Memberships both offer the list); suggesting one needs edit rights. A
        // suggestion only ever lands as `pending` — approval promotes it.
        Route::get('custom-types', [ProviderSenderController::class, 'customTypes'])->name('custom-types.index');
        Route::post('custom-types', [ProviderSenderController::class, 'suggestCustomType'])
            ->middleware('capability:manage_listings,manage_platform_catalog')->name('custom-types.store');

        // The campaign ledger lives with the composer on the Customers page.
        Route::middleware('capability:manage_customers')->group(function () {
            Route::get('marketing/campaigns', [MarketingController::class, 'campaigns'])->name('marketing.campaigns.index');
            Route::post('marketing/campaigns', [MarketingController::class, 'recordCampaign'])->name('marketing.campaigns.store');
            // The real send: recipients are re-scoped and re-consent-checked server-side,
            // then delivered on the queue.
            Route::post('marketing/campaigns/send', [MarketingController::class, 'sendCampaign'])->name('marketing.campaigns.send');
            Route::get('marketing/campaigns/{id}', [MarketingController::class, 'campaignStatus'])->name('marketing.campaigns.show');
            Route::delete('marketing/campaigns/{id}', [MarketingController::class, 'deleteCampaign'])->name('marketing.campaigns.destroy');
        });

        // Bookboll for Business PREMIUM — the provider's own subscription. Readable by
        // any admin role (the shell asks on every load to know what to unlock); only
        // the provider side may start, cancel or resume one, enforced in the service.
        Route::get('plan', [ProviderPlanController::class, 'show'])->name('plan.show');
        Route::post('plan/checkout', [ProviderPlanController::class, 'checkout'])->name('plan.checkout');
        Route::get('plan/checkout/{session}', [ProviderPlanController::class, 'confirm'])->name('plan.confirm');
        Route::post('plan/cancel', [ProviderPlanController::class, 'cancel'])->name('plan.cancel');
        Route::post('plan/resume', [ProviderPlanController::class, 'resume'])->name('plan.resume');

        Route::get('locations/countries', [LocationController::class, 'countries'])->name('locations.countries');
        Route::get('locations/states', [LocationController::class, 'states'])->name('locations.states');
        Route::get('locations/cities', [LocationController::class, 'cities'])->name('locations.cities');

        Route::prefix('location-management')->name('location-management.')->middleware('role:super_admin')->group(function () {
            Route::apiResource('countries', LocationCountryController::class);
            Route::apiResource('states', LocationStateController::class);
            Route::apiResource('cities', LocationCityController::class);
        });

        // Countries & Cities admin page (World package + commission rates).
        //
        // READING the country/city list is not administering geography: a provider
        // picking the country for a new venue needs it, and they hold none of the
        // platform capabilities. The payload is a public-ish lookup (name, ISO,
        // dialling code, currency, active flag) — the same shape as the already-open
        // /admin/locations/countries — so it stays open to any admin-area role.
        // Everything that CHANGES geography keeps the platform gate below.
        Route::prefix('geography')->name('geography.')->group(function () {
            Route::get('countries', [GeographyController::class, 'countries'])->name('countries');
            Route::get('cities', [GeographyController::class, 'cities'])->name('cities');
        });

        Route::middleware('capability:manage_geography,manage_loyalty')->group(function () {
            Route::prefix('geography')->name('geography.')->group(function () {
                Route::patch('countries/{id}/status', [GeographyController::class, 'updateCountryStatus'])->name('countries.status');
                Route::patch('cities/{id}/status', [GeographyController::class, 'updateCityStatus'])->name('cities.status');
                Route::post('cities/bulk-status', [GeographyController::class, 'bulkUpdateCityStatus'])->name('cities.bulk-status');
                Route::patch('cities/{id}/module', [GeographyController::class, 'updateCityModule'])->name('cities.module');
                Route::put('commission-rates', [GeographyController::class, 'upsertCommission'])->name('commission-rates');
                Route::get('loyalty-programs', [GeographyController::class, 'loyaltyPrograms'])->name('loyalty-programs.index');
                Route::put('loyalty-programs', [GeographyController::class, 'upsertLoyaltyProgram'])->name('loyalty-programs');
            });
        });

        Route::prefix('roles')->name('roles.')->middleware('role:super_admin')->group(function () {
            Route::get('catalog', [RolePermissionController::class, 'catalog'])->name('catalog');
            Route::get('/', [RolePermissionController::class, 'index'])->name('index');
            Route::post('/', [RolePermissionController::class, 'store'])->name('store');
            Route::get('{role}', [RolePermissionController::class, 'show'])->name('show');
            Route::put('{role}/permissions', [RolePermissionController::class, 'updatePermissions'])->name('permissions.update');
            Route::delete('{role}', [RolePermissionController::class, 'destroy'])->name('destroy');
        });

        // Venues
        Route::middleware('capability:manage_listings,manage_platform_catalog')->group(function () {
            // Operating agreements for venues Bookboll runs itself: append-only,
            // effective-dated, and never edited in place.
            Route::get('venues/{id}/operating-agreements', [VenueController::class, 'operatingAgreements'])->name('venues.agreements.index');
            Route::post('venues/{id}/operating-agreements', [VenueController::class, 'storeOperatingAgreement'])->name('venues.agreements.store');
            // Signed contracts go to the PRIVATE disk, never the public image
            // service, and carry no public URL.
            Route::post('venues/{id}/contracts', [VenueController::class, 'storeVenueContract'])->name('venues.contracts.store');
            // Moving a venue, with an OPTIONAL reconciliation of its history.
            // Reviewed first, because the reconciliation restates what already
            // collected money meant.
            Route::get('venues/{id}/transfer-options', [VenueController::class, 'transferOptions'])->name('venues.transfer-options');
            Route::post('venues/{id}/transfer-preview', [VenueController::class, 'transferPreview'])->name('venues.transfer-preview');
            Route::post('venues/{id}/transfers', [VenueController::class, 'storeTransfer'])->name('venues.transfers.store');
            // Expanding a pasted Google Maps link into a coordinate (short share
            // links redirect, and the browser cannot read where to cross-origin).
            Route::post('venues/resolve-maps-link', [VenueController::class, 'resolveMapsLink'])->name('venues.resolve-maps-link');
            Route::get('venues/dashboard', [VenueController::class, 'dashboard'])->name('venues.dashboard');
            Route::post('venues/dashboard', [VenueController::class, 'dashboardStore'])->name('venues.dashboard.store');
            Route::post('venues/dashboard/sort', [VenueController::class, 'dashboardSort'])->name('venues.dashboard.sort');
            Route::get('venues/dashboard/{id}', [VenueController::class, 'dashboardShow'])->name('venues.dashboard.show');
            Route::put('venues/dashboard/{id}', [VenueController::class, 'dashboardUpdate'])->name('venues.dashboard.update');
            Route::patch('venues/dashboard/{id}', [VenueController::class, 'dashboardPatch'])->name('venues.dashboard.patch');
            Route::delete('venues/dashboard/{id}', [VenueController::class, 'dashboardDestroy'])->name('venues.dashboard.destroy');
            Route::post('venues/dashboard/{id}/apply-hours', [VenueController::class, 'dashboardApplyHours'])->name('venues.dashboard.apply-hours');
            Route::post('venues/sort', [VenueController::class, 'sort'])->name('venues.sort');
            Route::post('venues/{id}/move', [VenueController::class, 'move'])->name('venues.move');
            Route::get('fetch-venues', [VenueController::class, 'adminFetchVenues'])->name('fetch-venues');
            Route::apiResource('venues', VenueController::class);
        });

        // Listings dashboard
        Route::middleware('capability:manage_listings,manage_platform_catalog')->group(function () {
            // The owner review path for own-site listings that can no longer be
            // booked: payment on arrival is gone from that channel, so a
            // cash-only row takes nothing. Read the queue, then apply a method
            // the OWNER names — nothing here chooses for them.
            Route::get('listings/own-site-review', [OwnSiteReviewController::class, 'index'])->name('listings.own-site-review');
            Route::post('listings/own-site-review', [OwnSiteReviewController::class, 'apply'])->name('listings.own-site-review.apply');
            Route::get('listings/dashboard', [ListingController::class, 'dashboard'])->name('listings.dashboard');
            Route::post('listings/dashboard', [ListingController::class, 'dashboardStore'])->name('listings.dashboard.store');
            // Copy a listing into any venue the caller may touch — the target is
            // authorized by the same check a hand-made listing goes through.
            Route::post('listings/dashboard/{id}/duplicate', [ListingController::class, 'dashboardDuplicate'])->name('listings.dashboard.duplicate');
            Route::post('listings/dashboard/sort', [ListingController::class, 'dashboardSort'])->name('listings.dashboard.sort');
            Route::get('listings/dashboard/{id}', [ListingController::class, 'dashboardShow'])->name('listings.dashboard.show');
            Route::put('listings/dashboard/{id}', [ListingController::class, 'dashboardUpdate'])->name('listings.dashboard.update');
            Route::patch('listings/dashboard/{id}', [ListingController::class, 'dashboardPatch'])->name('listings.dashboard.patch');
            Route::delete('listings/dashboard/{id}', [ListingController::class, 'dashboardDestroy'])->name('listings.dashboard.destroy');
        });

        // Approvals
        Route::middleware('capability:approve_listings,approve_withdrawals')->group(function () {
            Route::get('approvals/dashboard', [ApprovalsController::class, 'dashboard'])->name('approvals.dashboard');
            Route::post('approvals/bulk-approve', [ApprovalsController::class, 'bulkApprove'])->name('approvals.bulk-approve');
            Route::post('approvals/{type}/{id}/approve', [ApprovalsController::class, 'approve'])->name('approvals.approve');
            Route::post('approvals/{type}/{id}/request-changes', [ApprovalsController::class, 'requestChanges'])->name('approvals.request-changes');
            Route::post('approvals/businesses/{ownerUserId}/suspend', [ApprovalsController::class, 'suspendBusiness'])->name('approvals.businesses.suspend');
            Route::post('approvals/businesses/{ownerUserId}/reinstate', [ApprovalsController::class, 'reinstateBusiness'])->name('approvals.businesses.reinstate');
        });

        // Event & community moderation
        Route::middleware('capability:moderate_events')->group(function () {
            Route::get('moderation/events/dashboard', [EventModerationController::class, 'dashboard'])->name('moderation.events.dashboard');
            Route::post('moderation/events/{id}/flag', [EventModerationController::class, 'flag'])->name('moderation.events.flag');
            Route::post('moderation/events/{id}/remove', [EventModerationController::class, 'remove'])->name('moderation.events.remove');
            Route::post('moderation/events/{id}/restore', [EventModerationController::class, 'restore'])->name('moderation.events.restore');

            Route::get('moderation/communities/dashboard', [CommunityModerationController::class, 'dashboard'])->name('moderation.communities.dashboard');
            Route::post('moderation/communities/{id}/flag', [CommunityModerationController::class, 'flag'])->name('moderation.communities.flag');
            Route::post('moderation/communities/{id}/remove', [CommunityModerationController::class, 'remove'])->name('moderation.communities.remove');
            Route::post('moderation/communities/{id}/restore', [CommunityModerationController::class, 'restore'])->name('moderation.communities.restore');
        });

        // Wallets & payouts
        Route::middleware('capability:view_wallet,manage_payouts,withdraw_funds,approve_withdrawals,approve_payouts')->group(function () {
            Route::get('wallet-payouts/dashboard', [WalletPayoutController::class, 'dashboard'])->name('wallet-payouts.dashboard');
            Route::get('wallet-payouts/owners/{ownerUserId}/review', [WalletPayoutController::class, 'ownerReview'])->name('wallet-payouts.owners.review');
            Route::get('wallet-payouts/wallets', [WalletPayoutController::class, 'listWallets'])->name('wallet-payouts.wallets.index');
            Route::post('wallet-payouts/wallets', [WalletPayoutController::class, 'createWallet'])->name('wallet-payouts.wallets.store');
            Route::put('wallet-payouts/wallets/{id}', [WalletPayoutController::class, 'updateWallet'])->name('wallet-payouts.wallets.update');
            Route::delete('wallet-payouts/wallets/{id}', [WalletPayoutController::class, 'deleteWallet'])->name('wallet-payouts.wallets.destroy');
            Route::post('wallet-payouts/wallets/apply-method', [WalletPayoutController::class, 'applyMethod'])->name('wallet-payouts.wallets.apply-method');
            Route::post('wallet-payouts/kyc/{ownerUserId}/approve', [WalletPayoutController::class, 'approveKyc'])->name('wallet-payouts.kyc.approve');
            Route::post('wallet-payouts/kyc/{ownerUserId}/reject', [WalletPayoutController::class, 'rejectKyc'])->name('wallet-payouts.kyc.reject');
            Route::get('wallet-payouts/withdrawals', [WalletPayoutController::class, 'listWithdrawals'])->name('wallet-payouts.withdrawals.index');
            Route::post('wallet-payouts/withdrawals', [WalletPayoutController::class, 'createWithdrawal'])->name('wallet-payouts.withdrawals.store');
            Route::post('wallet-payouts/withdrawals/paid-record', [WalletPayoutController::class, 'createPaidWithdrawal'])->name('wallet-payouts.withdrawals.paid-record');
            Route::post('wallet-payouts/withdrawals/{id}/approve', [WalletPayoutController::class, 'approveWithdrawal'])->name('wallet-payouts.withdrawals.approve');
            Route::post('wallet-payouts/withdrawals/{id}/mark-paid', [WalletPayoutController::class, 'markWithdrawalPaid'])->name('wallet-payouts.withdrawals.mark-paid');
            Route::post('wallet-payouts/withdrawals/{id}/reject', [WalletPayoutController::class, 'rejectWithdrawal'])->name('wallet-payouts.withdrawals.reject');
            Route::post('wallet-payouts/withdrawals/{id}/cancel', [WalletPayoutController::class, 'cancelWithdrawal'])->name('wallet-payouts.withdrawals.cancel');
            Route::post('wallet-payouts/delta', [WalletPayoutController::class, 'applyDelta'])->name('wallet-payouts.delta');
            Route::get('wallet-payouts/transactions', [WalletPayoutController::class, 'listTransactions'])->name('wallet-payouts.transactions.index');
            Route::post('wallet-payouts/transactions', [WalletPayoutController::class, 'createTransaction'])->name('wallet-payouts.transactions.store');
            Route::put('wallet-payouts/transactions/{id}', [WalletPayoutController::class, 'updateTransaction'])->name('wallet-payouts.transactions.update');
        });

        // Memberships & subscriptions
        Route::middleware('capability:view_subscriptions,manage_subscriptions')->group(function () {
            Route::get('subscriptions/dashboard', [ClassMembershipController::class, 'dashboard'])->name('subscriptions.dashboard');
            Route::post('subscriptions/dashboard', [ClassMembershipController::class, 'store'])->name('subscriptions.dashboard.store');
            Route::post('subscriptions/dashboard/bulk-cancel', [ClassMembershipController::class, 'bulkCancel'])->name('subscriptions.dashboard.bulk-cancel');
            Route::post('subscriptions/programs', [ClassMembershipController::class, 'storeProgram'])->name('subscriptions.programs.store');
            Route::put('subscriptions/programs/{id}', [ClassMembershipController::class, 'updateProgram'])->name('subscriptions.programs.update');
            Route::patch('subscriptions/programs/{id}/state', [ClassMembershipController::class, 'setProgramState'])->name('subscriptions.programs.state');
            Route::delete('subscriptions/programs/{id}', [ClassMembershipController::class, 'destroyProgram'])->name('subscriptions.programs.destroy');
            Route::get('subscriptions/dashboard/{id}', [ClassMembershipController::class, 'show'])->name('subscriptions.dashboard.show');
            Route::get('subscriptions/dashboard/{id}/payments', [ClassMembershipController::class, 'payments'])->name('subscriptions.dashboard.payments');
            // The customer's own saved cards, so staff pick a stored method
            // instead of typing a reference. No card number is ever collected
            // here and the Stripe token never reaches the browser.
            Route::get('subscriptions/dashboard/{id}/payment-methods', [ClassMembershipController::class, 'paymentMethods'])->name('subscriptions.dashboard.payment-methods');
            Route::post('subscriptions/dashboard/{id}/charge', [ClassMembershipController::class, 'charge'])->name('subscriptions.dashboard.charge');
            Route::post('subscriptions/dashboard/{id}/retry-charge', [ClassMembershipController::class, 'retryCharge'])->name('subscriptions.dashboard.retry-charge');
            Route::post('subscriptions/dashboard/{id}/cancel', [ClassMembershipController::class, 'cancel'])->name('subscriptions.dashboard.cancel');
            Route::post('subscriptions/dashboard/{id}/pause', [ClassMembershipController::class, 'pause'])->name('subscriptions.dashboard.pause');
            Route::post('subscriptions/dashboard/{id}/resume', [ClassMembershipController::class, 'resume'])->name('subscriptions.dashboard.resume');
            Route::post('subscriptions/dashboard/{id}/mark-past-due', [ClassMembershipController::class, 'markPastDue'])->name('subscriptions.dashboard.mark-past-due');
            Route::post('subscriptions/dashboard/{id}/retention/month-off', [ClassMembershipController::class, 'retentionMonthOff'])->name('subscriptions.dashboard.retention');
            Route::post('subscriptions/dashboard/{id}/winback', [ClassMembershipController::class, 'winback'])->name('subscriptions.dashboard.winback');
        });

        // Team & account administration. The dashboard hides these behind
        // CAP.MANAGE_TEAM (PAGE_CAP '/team'); without the same gate here the
        // check was cosmetic — any signed-in admin-area role could call the API
        // directly and mint or delete an administrator.
        Route::middleware('capability:manage_team')->group(function () {
            Route::get('registrations/requests', [UserController::class, 'listPendingUsers'])->name('users.pending');
            Route::post('registrations/{id}/approve', [UserController::class, 'approveUser'])->name('users.approve');
            Route::post('registrations/{id}/reject', [UserController::class, 'rejectUser'])->name('users.reject');

            // Before the resource: `users/{user}` is registered by apiResource and
            // would otherwise swallow "required-data" as an id and dispatch to a
            // show() that does not exist (a 500 for every caller).
            Route::get('users/required-data', [UserController::class, 'requiredDataForUser'])->name('users.required-data');
            Route::apiResource('users', UserController::class);
            Route::get('businesses', [BusinessController::class, 'adminIndex'])->name('businesses.index');
            // Registering an operator before anyone from it has a login, and the
            // capability probe the dashboard checks before offering that form.
            Route::get('businesses/capabilities', [BusinessController::class, 'capabilities'])->name('businesses.capabilities');
            Route::post('businesses', [BusinessController::class, 'adminStore'])->name('businesses.store');
            // Moving an account into a business is reviewed, not a single click:
            // the move strips the account's platform access, so an admin sees
            // exactly what goes before anything happens.
            // Literal path, declared before the {id} routes so "assignment-candidates"
            // is never read as a business id.
            Route::get('businesses/assignment-candidates', [BusinessController::class, 'assignmentCandidates'])->name('businesses.assignment-candidates');
            Route::get('businesses/{id}/assignment-options', [BusinessController::class, 'assignmentOptions'])->name('businesses.assignment-options');
            Route::post('businesses/{id}/assignment-preview', [BusinessController::class, 'assignmentPreview'])->name('businesses.assignment-preview');
            Route::post('businesses/{id}/assignments', [BusinessController::class, 'assignmentStore'])->name('businesses.assignments');
        });

        // A user editing their OWN profile needs no team rights.
        Route::post('users/update-profile', [UserController::class, 'updateProfile']);

        // Creating a customer inline from the booking drawer.
        Route::middleware('capability:manage_customers,manage_bookings')->group(function () {
            Route::post('customers/register', [CustomerController::class, 'createCustomer']);
        });

        // Reading settings stays open — the brand/theme loader needs it on every
        // page. WRITING them is the Brand page, gated as the dashboard gates it.
        Route::get('settings', [SettingController::class, 'index'])->name('settings.index');
        Route::middleware('capability:platform_admin,manage_platform_website')->group(function () {
            Route::post('settings/{key}', [SettingController::class, 'updateSingle'])->name('settings.updateSingle');
            Route::post('settings', [SettingController::class, 'update'])->name('settings.update');
        });

        // Marketplace-default message templates — the platform half of the
        // Notifications page. A provider's own templates are on
        // /business/notification-templates and keep their own gate.
        Route::middleware('capability:platform_admin,manage_platform_website')->group(function () {
            Route::get('notification-templates', [NotificationTemplateController::class, 'showPlatform'])->name('notification-templates.show');
            Route::put('notification-templates', [NotificationTemplateController::class, 'updatePlatform'])->name('notification-templates.update');
        });

        // Platform-wide sport catalogue — Bookboll's own taxonomy, not a provider's.
        Route::middleware('capability:manage_platform_catalog')->group(function () {
            Route::apiResource('sports-types', SportsTypeController::class)->only(['store', 'update', 'destroy']);
            Route::post('sports-types/sort', [SportsTypeController::class, 'sort'])->name('sports-types.sort');

            Route::post('sports-categories/sort', [SportsCategoryController::class, 'sort'])->name('sports-categories.sort');
            Route::apiResource('sports-categories', SportsCategoryController::class);
            Route::get('sports-categories/popular-emojis', [SportsCategoryController::class, 'popularEmojis'])->name('sports-categories.popular-emojis');
        });

        // Gift cards are bearer money instruments — same gate as platform promocodes.
        Route::middleware('capability:manage_promocodes_platform,manage_promocodes')->group(function () {
            Route::apiResource('gift-cards', GiftCardController::class);
        });

        // Bookable resources belong to the catalogue, same gate as listings/add-ons.
        Route::middleware('capability:manage_listings,manage_platform_catalog')->group(function () {
            Route::apiResource('resources', ResourceController::class);
        });

        // Notification bell feed (derived from recent domain activity)
        Route::get('notifications/feed', [NotificationFeedController::class, 'index'])->name('notifications.feed');
        Route::post('notifications/read-all', [NotificationFeedController::class, 'markAllRead'])->name('notifications.read-all');

        // The scheduled-reminder sweep the dashboard runs on open. The server decides
        // what is due (scoped to the caller) and owns the once-only log; the dashboard
        // still sends through the existing dispatcher.
        Route::get('notifications/sweep', [NotificationFeedController::class, 'sweepDue'])->name('notifications.sweep');
        Route::post('notifications/sweep/sent', [NotificationFeedController::class, 'sweepSent'])->name('notifications.sweep.sent');

        // Door check-in (admin app QR scanner)
        // Door check-in
        Route::middleware('capability:view_subscriptions,manage_subscriptions')->group(function () {
            Route::prefix('checkin')->name('checkin.')->group(function () {
                Route::get('lookup', [CheckInController::class, 'lookup'])->name('lookup');
                Route::get('recent', [CheckInController::class, 'recent'])->name('recent');
                Route::get('roster', [CheckInController::class, 'roster'])->name('roster');
                Route::post('attendance', [CheckInController::class, 'record'])->name('attendance');
                Route::post('mark-present', [CheckInController::class, 'markPresent'])->name('mark-present');
            });
        });

        // Audit logs
        Route::middleware('capability:platform_admin')->group(function () {
            Route::get('audit-logs', [AuditLogController::class, 'index']);
            Route::post('audit-logs', [AuditLogController::class, 'store']);
            Route::get('audit-logs/stats', [AuditLogController::class, 'stats']);
            Route::get('audit-logs/export', [AuditLogController::class, 'export']);
        });
        // Outbound message dispatch, fired by the booking and membership flows
        // (Bookings, Subscribers and the booking drawer/detail modal). Gated on
        // those same rights so a view-only role cannot send mail or WhatsApp
        // in the platform's name.
        Route::middleware('capability:manage_bookings,manage_subscriptions')->group(function () {
            Route::post('notifications/whatsapp', [WhatsappController::class, 'send']);
            Route::post('notifications/email', [NotificationEmailController::class, 'send'])->name('notifications.email');
            Route::get('notifications/owner-phone', [WhatsappController::class, 'ownerPhone']);
        });

        // Catalogue editing — the dashboard gates '/listings' on exactly these two.
        Route::middleware('capability:manage_listings,manage_platform_catalog')->group(function () {
            Route::post('add-ons/sort', [AddOnController::class, 'sort'])->name('add-ons.sort');
            Route::apiResource('add-ons', AddOnController::class);

            Route::post('listings/sort', [ListingController::class, 'sort'])->name('listings.sort');
            Route::post('listings/{id}/images', [ListingController::class, 'uploadImage'])->name('listings.uploadImage');
            Route::delete('listings/{id}/images', [ListingController::class, 'deleteImage'])->name('listings.deleteImage');
            Route::apiResource('listings', ListingController::class);
        });

        // Promotions
        Route::middleware('capability:manage_promocodes,manage_promocodes_platform')->group(function () {
            Route::get('promocodes/options', [PromocodeController::class, 'options'])->name('promocodes.options');
            Route::get('promocodes/venues/search', [PromocodeController::class, 'searchVenues'])->name('promocodes.venues.search');
            Route::get('promocodes/listings/search', [PromocodeController::class, 'searchListings'])->name('promocodes.listings.search');
            Route::post('promocodes/validate', [PromocodeController::class, 'validate'])->name('promocodes.validate');
            Route::get('promocodes/{id}/redemptions', [PromocodeController::class, 'redemptions'])->name('promocodes.redemptions');
            Route::post('promocodes/{id}/redemptions', [PromocodeController::class, 'storeRedemption'])->name('promocodes.redemptions.store');
            Route::post('promocodes/{promocode}/apply', [PromocodeController::class, 'apply'])->name('promocodes.apply');
            Route::post('promocodes/{booking}/remove', [PromocodeController::class, 'remove'])->name('promocodes.remove');
            Route::apiResource('promocodes', PromocodeController::class);
        });

        // ── Legacy helper dumps ──────────────────────────────────────────────
        // Pre-dating the dashboard rewrite and called by none of the three apps.
        // They stay routed for any external integration, but behind a gate:
        // `extra-customers` was handing every customer's name and email to any
        // role that could reach /api/admin, `guard` included.
        Route::middleware('capability:manage_platform_catalog,manage_listings')->group(function () {
            Route::get('extra-data', [HelperController::class, 'extraData'])->name('extra-data');
            Route::get('extra-resources', [HelperController::class, 'extraResources'])->name('extra-resources');
            Route::get('extra-add-ons', [HelperController::class, 'extraAddOns'])->name('extra-add-ons');
            Route::get('extra-sport-categories', [HelperController::class, 'extraSportCategories'])->name('extra-sport-categories');
            Route::get('extra-data-for-listing', [HelperController::class, 'extraDataForListing'])->name('extra-data-for-listing');
            Route::get('extra-special-days', [HelperController::class, 'extraSpecialDays'])->name('extra-special-days');
        });
        Route::middleware('capability:manage_customers')->group(function () {
            Route::get('extra-customers', [HelperController::class, 'extraCustomers'])->name('extra-customers');
        });
        Route::middleware('capability:manage_loyalty')->group(function () {
            Route::get('extra-loyalty-data', [LoyaltyPointController::class, 'extraDataForLoyalty'])->name('extra-loyalty-data');
        });

        Route::get('dashboard-data', [BookingController::class, 'dashboardData'])->name('dashboard-data');
        Route::get('dashboard/chart', [BookingController::class, 'dashboardChart'])->name('dashboard.chart');

        // Bookings & calendar
        Route::middleware('capability:view_bookings,manage_bookings,view_calendar')->group(function () {
            Route::get('bookings/dashboard', [BookingController::class, 'dashboard'])->name('bookings.dashboard');
            Route::get('bookings/occupancy', [BookingController::class, 'occupancy'])->name('bookings.occupancy');
            Route::get('bookings/special-days', [BookingController::class, 'dashboardSpecialDays'])->name('bookings.special-days');
            Route::post('bookings/dashboard', [BookingController::class, 'dashboardStore'])->name('bookings.dashboard.store');
            Route::post('bookings/dashboard-cancel', [BookingController::class, 'dashboardCancel'])->name('bookings.dashboard.cancel');
            Route::post('bookings/dashboard-payment-link', [BookingController::class, 'dashboardPaymentLink'])->name('bookings.dashboard.payment-link');
            Route::get('bookings/{id}/crm', [BookingController::class, 'crm'])->name('bookings.crm');
            Route::put('bookings/{id}/dashboard', [BookingController::class, 'dashboardUpdate'])->name('bookings.dashboard.update');
            Route::put('bookings/{id}/payments/{paymentId}/collect', [BookingController::class, 'dashboardCollect'])->name('bookings.dashboard.collect');
            Route::get('bookings/list', [BookingController::class, 'mobileBookingsList'])->name('bookings.list');
            Route::get('bookings/filter-options', [BookingController::class, 'mobileBookingsFilterOptions'])->name('bookings.filter-options');

            Route::post('bookings/completed', [BookingController::class, 'completed'])->name('bookings.completed');
            Route::post('bookings/confirm', [BookingController::class, 'confirm'])->name('bookings.confirm');
            Route::post('bookings/generate-invoice', [BookingController::class, 'generateInvoice'])->name('bookings.generate-invoice');
            Route::get('bookings/customer/{customerId}/history', [BookingController::class, 'customerHistory'])->name('bookings.customer.history');
            Route::get('bookings/form-data', [BookingController::class, 'formData'])->name('bookings.form-data');
            Route::get('bookings/payment-methods', [BookingController::class, 'getPaymentMethods'])->name('bookings.payment-methods');
            Route::get('bookings/calendar', [BookingController::class, 'bookingsForCalendar'])->name('bookings.calendar');
            Route::get('bookings/calendar-dashboard', [BookingController::class, 'calendarDashboard'])->name('bookings.calendar-dashboard');
            Route::post('bookings/cancel', [BookingController::class, 'cancel'])->name('bookings.cancel');
            Route::get('bookings/due/{customer_id?}', [BookingController::class, 'customerDueBookings'])->name('bookings.due');
            Route::get('bookings/export', [BookingController::class, 'export'])->name('bookings.export');
            Route::apiResource('bookings', BookingController::class);
        });

        // Payments
        Route::middleware('capability:accept_payments')->group(function () {
            Route::post('payments/confirm', [PaymentController::class, 'confirmPayment'])->name('payments.confirm');
            Route::post('payments/generate-link', [PaymentController::class, 'generatePaymentLink'])->name('payments.generate-link');
            Route::post('payments/mark-booking-paid', [PaymentController::class, 'markBookingAsPaid'])->name('payments.mark-booking-paid');
            Route::get('payments/dashboard', [PaymentController::class, 'dashboard'])->name('payments.dashboard');
            Route::get('payments/export', [PaymentController::class, 'export'])->name('payments.export');
            Route::post('payments/{id}/mark-received', [PaymentController::class, 'markPaymentAsReceived'])->name('payments.mark-received');
            Route::get('payments/{id}/invoice', [PaymentController::class, 'downloadInvoice'])->name('payments.invoice');
            Route::apiResource('payments', PaymentController::class);
        });

        // Special days
        Route::middleware('capability:manage_listings')->group(function () {
            Route::get('special-days/dashboard', [SpecialDayController::class, 'dashboard'])->name('special-days.dashboard');
            Route::post('special-days/dashboard', [SpecialDayController::class, 'dashboardStore'])->name('special-days.dashboard.store');
            Route::put('special-days/dashboard/{id}', [SpecialDayController::class, 'dashboardUpdate'])->name('special-days.dashboard.update');
            Route::delete('special-days/dashboard/{id}', [SpecialDayController::class, 'dashboardDestroy'])->name('special-days.dashboard.destroy');
            Route::post('special-days/dashboard-clear', [SpecialDayController::class, 'dashboardBulkClear'])->name('special-days.dashboard.clear');
            Route::post('special-days/dashboard-conflicts', [SpecialDayController::class, 'dashboardConflicts'])->name('special-days.dashboard.conflicts');
            Route::get('special-days/calendar/{venueId}', [SpecialDayController::class, 'calendar'])->name('special-days.calendar');
            Route::post('special-days/bulk', [SpecialDayController::class, 'bulkCreate'])->name('special-days.bulk');
            Route::apiResource('special-days', SpecialDayController::class);
        });

        // Customers / CRM
        Route::middleware('capability:manage_customers')->group(function () {
            Route::get('customers/dashboard', [CustomerController::class, 'dashboard'])->name('customers.dashboard');
            Route::get('customers/export', [CustomerController::class, 'export'])->name('customers.export');
            Route::get('customers/list', [CustomerController::class, 'listOptimized'])->name('customers.listOptimized');
            Route::get('customers/{id}/crm', [CustomerController::class, 'crm'])->name('customers.crm');
            Route::get('customers/{id}/profile', [CustomerController::class, 'adminProfile'])->name('customers.profile');
            Route::get('customers/{id}/bookings', [CustomerController::class, 'adminBookings'])->name('customers.bookings');
            Route::put('customers/{id}/tags', [CustomerController::class, 'updateTags'])->name('customers.updateTags');
            Route::put('customers/{id}/status', [CustomerController::class, 'updateStatus'])->name('customers.updateStatus');
            Route::put('customers/{id}/loyalty', [CustomerController::class, 'replaceLoyalty'])->name('customers.loyalty.replace');
            Route::post('customers/{id}/loyalty', [CustomerController::class, 'upsertLoyalty'])->name('customers.loyalty.upsert');
            Route::delete('customers/{id}/loyalty/{countryId}', [CustomerController::class, 'deleteLoyalty'])->name('customers.loyalty.delete');
            Route::apiResource('customers', CustomerController::class);
        });

    });

    Route::prefix('customer')->group(function () {

        Route::get('my-reviews', [ReviewController::class, 'myReviews'])->name('customer.my-reviews');
        Route::patch('reviews/{review}/helpful', [ReviewController::class, 'helpful'])->name('reviews.helpful');
        Route::apiResource('reviews', ReviewController::class)->except(['show', 'index']);

        Route::get('gift-cards', [GiftCardController::class, 'fetchGiftCards'])->name('customer.gift-cards.index');
        Route::post('gift-cards/redeem', [GiftCardController::class, 'redeemGiftCard'])->name('gift-cards.redeem');
        Route::get('gift-cards/balance', [GiftCardController::class, 'getBalance'])->name('gift-cards.balance');
        Route::get('gift-cards/promocodes', [GiftCardController::class, 'getGiftCardPromocodes'])->name('gift-cards.promocodes');

        Route::get('bookings/reviewable', [ReviewController::class, 'reviewableBookings'])->name('bookings.reviewable');
        Route::post('bookings/validate', [BookingController::class, 'validateBooking'])->name('bookings.validate');
        Route::post('bookings/confirm', [BookingController::class, 'confirmBooking'])->name('bookings.confirmBooking');
        Route::post('bookings/create', [BookingController::class, 'createCustomerBooking'])->name('customer.bookings.create');
        Route::get('bookings/upcoming', [BookingController::class, 'upcoming'])->name('bookings.upcoming');
        Route::get('bookings/recent', [BookingController::class, 'recent'])->name('bookings.recent');
        Route::get('bookings/by-ids', [BookingController::class, 'getBookingsByIds'])->name('bookings.byIds');
        Route::post('bookings/cancel', [BookingController::class, 'cancel'])->name('customer.bookings.cancel');
        Route::put('bookings/{id}/update', [BookingController::class, 'mobileUpdateBooking'])->name('bookings.mobileUpdate');
        Route::post('payments/create-payment-intent', [PaymentController::class, 'createPaymentIntent'])->name('payments.createPaymentIntent');
        Route::post('payments/create-checkout-session', [PaymentController::class, 'createCheckoutSession'])->name('payments.createCheckoutSession');
        // Mobile app: confirm or abandon a Stripe Checkout it opened in the browser.
        Route::get('payments/checkout-session/{sessionId}', [PaymentController::class, 'checkoutSessionState'])->name('payments.checkoutSessionState');
        Route::post('payments/checkout-session/{sessionId}/abandon', [PaymentController::class, 'abandonCheckoutSession'])->name('payments.abandonCheckoutSession');

        Route::get('promocodes', [PromocodeController::class, 'customerIndex'])->name('promocodes.customer.index');
    });

});

/*
 * Google sign-in for every tenant storefront.
 *
 * These two are opened in a popup by the storefront and are the ONLY URLs Google
 * knows about — see App\Services\OAuthBrokerService. Registering a redirect URI per
 * tenant domain is impossible (no wildcards, ~100 cap, domains created at runtime),
 * so the handshake happens here and the finished session is handed back to whichever
 * storefront opened the popup.
 */
Route::prefix('auth')->group(function () {
    Route::get('google/start', [OAuthBrokerController::class, 'start'])->name('oauth.google.start');
    Route::get('google/callback', [OAuthBrokerController::class, 'callback'])->name('oauth.google.callback');
});

Route::prefix('customer')->group(function () {

    Route::get('reviews', [ReviewController::class, 'index'])->name('customer.reviews.index');
    Route::get('reviews/my', [ReviewController::class, 'myReviews'])->name('customer.reviews.my');
    // Same reasoning as the public password routes above: IP-keyed limits that stop
    // brute force / reset-spam without locking out a shared-IP user. The social logins
    // verify a provider token rather than a password, so they are left untouched.
    Route::post('login', [UserController::class, 'customerLogin'])->middleware('throttle:10,1')->name('customer.login');
    Route::post('google-login', [UserController::class, 'googleLogin'])->name('customer.google-login');
    // Collects a session finished by the OAuth broker. google-login above stays for
    // the mobile apps, which get their id_token from the native Google SDK.
    Route::post('auth/exchange', [OAuthBrokerController::class, 'exchange'])->name('customer.auth.exchange');
    Route::post('apple-login', [UserController::class, 'appleLogin'])->name('customer.apple-login');
    Route::post('forgot-password', [UserController::class, 'customerForgotPassword'])->middleware('throttle:8,10')->name('customer.forgot-password');
    Route::post('reset-password', [UserController::class, 'customerResetPassword'])->middleware('throttle:10,10')->name('customer.reset-password');

    Route::middleware(['auth:sanctum'])->group(function () {
        Route::get('preferences', [UserController::class, 'customerPreferences'])->name('customer.preferences');
        Route::post('preferences', [UserController::class, 'updatePreferences'])->name('customer.updatePreferences');
        Route::get('profile', [UserController::class, 'getCustomerProfile'])->name('customer.profile');
        Route::get('loyalty', [LoyaltyPointController::class, 'customerLoyalty'])->name('customer.loyalty');
        // A priced offer, not a purchase: it debits nothing and reserves
        // nothing. Rate-limited because it re-prices an order on every call.
        Route::post('loyalty/checkout-quote', [LoyaltyPointController::class, 'checkoutQuote'])
            ->middleware('throttle:30,1')->name('customer.loyalty.checkout-quote');
        Route::post('update-profile', [UserController::class, 'updateCustomerProfile'])->name('customer.updateCustomerProfile');
        Route::post('update-profile-image', [UserController::class, 'updateProfileImage'])->name('customer.updateProfileImage');
        Route::get('payment-methods', [PaymentController::class, 'getPaymentMethods'])->name('customer.payment-methods');
        Route::post('delete-account', [UserController::class, 'deleteCustomerAccount'])->name('customer.delete-account');

        Route::post('social/follow/{uuid}', [CustomerSocialController::class, 'follow'])->name('customer.social.follow');
        Route::delete('social/follow/{uuid}', [CustomerSocialController::class, 'unfollow'])->name('customer.social.unfollow');
        Route::get('social/follows/{uuid}', [CustomerSocialController::class, 'followList'])->name('customer.social.follows');
        Route::post('social/follow-requests/{id}/respond', [CustomerSocialController::class, 'respondFollowRequest'])->name('customer.social.follow-respond');
        Route::post('social/posts', [CustomerSocialController::class, 'createPost'])->name('customer.social.posts.store');
        Route::delete('social/posts/{id}', [CustomerSocialController::class, 'deletePost'])->name('customer.social.posts.destroy');
        Route::post('social/street-cred', [CustomerCommunityController::class, 'giveStreetCred'])->name('customer.social.street-cred');
        Route::get('social/street-cred', [CustomerCommunityController::class, 'myPropsForGame'])->name('customer.social.street-cred.mine');
        Route::get('social/street-cred/counts', [CustomerCommunityController::class, 'streetCredCounts'])->name('customer.social.street-cred.counts');

        Route::get('communities/mine', [CustomerCommunityController::class, 'mine'])->name('customer.communities.mine');
        Route::get('communities/membership-check', [CustomerCommunityController::class, 'membershipCheck'])->name('customer.communities.membership-check');
        Route::get('communities/pending-members', [CustomerCommunityController::class, 'pendingMembers'])->name('customer.communities.pending-members');
        Route::post('communities', [CustomerCommunityController::class, 'store'])->name('customer.communities.store');
        Route::patch('communities/{uuid}', [CustomerCommunityController::class, 'update'])->name('customer.communities.update');
        Route::post('communities/{uuid}/join', [CustomerCommunityController::class, 'join'])->name('customer.communities.join');
        Route::delete('communities/{uuid}/leave', [CustomerCommunityController::class, 'leave'])->name('customer.communities.leave');
        Route::get('communities/{uuid}/members', [CustomerCommunityController::class, 'members'])->name('customer.communities.members');
        Route::post('communities/{uuid}/members/{memberUuid}/respond', [CustomerCommunityController::class, 'respondToMember'])->name('customer.communities.members.respond');
        Route::delete('communities/{uuid}/members/{memberUuid}', [CustomerCommunityController::class, 'removeMember'])->name('customer.communities.members.remove');
        Route::patch('communities/{uuid}/members/{memberUuid}/role', [CustomerCommunityController::class, 'setMemberRole'])->name('customer.communities.members.role');
        Route::post('community-members/{memberUuid}/respond', [CustomerCommunityController::class, 'respondToMemberById'])->name('customer.community-members.respond');
        Route::delete('community-members/{memberUuid}', [CustomerCommunityController::class, 'removeMemberById'])->name('customer.community-members.remove');
        Route::patch('community-members/{memberUuid}/role', [CustomerCommunityController::class, 'setMemberRoleById'])->name('customer.community-members.role');
        Route::get('communities/{uuid}/posts', [CustomerCommunityController::class, 'feed'])->name('customer.communities.posts.index');
        Route::post('communities/{uuid}/posts', [CustomerCommunityController::class, 'createPost'])->name('customer.communities.posts.store');
        Route::delete('communities/{uuid}/posts/{postUuid}', [CustomerCommunityController::class, 'deletePost'])->name('customer.communities.posts.destroy');
        Route::delete('community-posts/{postUuid}', [CustomerCommunityController::class, 'deletePostById'])->name('customer.community-posts.destroy');
        Route::post('communities/{uuid}/chat/read', [CustomerCommunityController::class, 'markChatRead'])->name('customer.communities.chat.read');
        Route::get('communities/{uuid}/chat/unread', [CustomerCommunityController::class, 'chatUnread'])->name('customer.communities.chat.unread');
        Route::get('messages/unread-count', [CustomerCommunityController::class, 'unreadMessages'])->name('customer.messages.unread');
        Route::get('notifications/feed', [CustomerGameController::class, 'notificationFeed'])->name('customer.notifications.feed');
        Route::get('messages/group', [CustomerCommunityController::class, 'loadGroupThread'])->name('customer.messages.group.index');
        Route::post('messages/group', [CustomerCommunityController::class, 'sendGroupMessage'])->name('customer.messages.group.store');
        Route::delete('messages/group/{id}', [CustomerCommunityController::class, 'deleteGroupMessage'])->name('customer.messages.group.destroy');

        // Direct messages (the inbox also lists community group chats)
        Route::get('messages/thread', [CustomerMessageController::class, 'thread'])->name('customer.messages.thread');
        Route::post('messages/read', [CustomerMessageController::class, 'markRead'])->name('customer.messages.read');
        Route::get('messages', [CustomerMessageController::class, 'index'])->name('customer.messages.index');
        Route::post('messages', [CustomerMessageController::class, 'store'])->name('customer.messages.store');

        Route::get('games/mine/due-matches', [CustomerGameController::class, 'dueMatches'])->name('customer.games.mine.due-matches');
        Route::get('games/mine/hosted', [CustomerGameController::class, 'mineHosted'])->name('customer.games.mine.hosted');
        Route::get('games/mine/joined', [CustomerGameController::class, 'mineJoined'])->name('customer.games.mine.joined');
        Route::get('stripe/payouts', [HostPayoutController::class, 'show'])->name('customer.stripe.payouts.show');
        Route::get('stripe/payouts/countries', [HostPayoutController::class, 'countries'])->name('customer.stripe.payouts.countries');
        Route::post('stripe/payouts/connect', [HostPayoutController::class, 'connect'])->name('customer.stripe.payouts.connect');
        Route::post('stripe/payouts/sync', [HostPayoutController::class, 'sync'])->name('customer.stripe.payouts.sync');
        Route::post('stripe/payouts/keys', [HostPayoutController::class, 'saveKeys'])->name('customer.stripe.payouts.keys');
        Route::delete('stripe/payouts', [HostPayoutController::class, 'destroy'])->name('customer.stripe.payouts.destroy');
        Route::post('games/quote', [CustomerGameController::class, 'quote'])->name('customer.games.quote');
        Route::post('games', [CustomerGameController::class, 'store'])->middleware('rewards.unsupported')->name('customer.games.store');
        Route::patch('games/{uuid}/community', [CustomerGameController::class, 'setCommunity'])->name('customer.games.community');
        Route::get('games/{uuid}/requests', [CustomerGameController::class, 'requests'])->name('customer.games.requests');
        Route::post('games/{uuid}/requests/accept-all', [CustomerGameController::class, 'acceptAllRequests'])->name('customer.games.requests.accept-all');
        Route::get('games/{uuid}/my-request', [CustomerGameController::class, 'myRequest'])->name('customer.games.my-request');
        Route::post('games/{uuid}/join', [CustomerGameController::class, 'join'])->name('customer.games.join');
        Route::patch('games/{uuid}', [CustomerGameController::class, 'update'])->name('customer.games.update');
        Route::post('games/{uuid}/cancel', [CustomerGameController::class, 'cancel'])->name('customer.games.cancel');
        Route::post('games/{uuid}/rounds', [CustomerGameController::class, 'saveRounds'])->name('customer.games.rounds');
        Route::patch('game-requests/{requestUuid}', [CustomerGameController::class, 'respondToRequest'])->name('customer.game-requests.respond');
        Route::delete('game-requests/{requestUuid}', [CustomerGameController::class, 'cancelRequest'])->name('customer.game-requests.cancel');
        Route::post('game-requests/{requestUuid}/attendance', [CustomerGameController::class, 'attendance'])->name('customer.game-requests.attendance');

        Route::post('tournaments', [CustomerGameController::class, 'storeTournament'])->middleware('rewards.unsupported')->name('customer.tournaments.store');
        Route::post('tournaments/{uuid}/matchups', [CustomerGameController::class, 'generateMatchups'])->name('customer.tournaments.matchups');
        Route::patch('tournament-matches/{matchUuid}/winner', [CustomerGameController::class, 'setMatchWinner'])->name('customer.tournament-matches.winner');
        Route::delete('tournament-matches/{matchUuid}/winner', [CustomerGameController::class, 'clearMatchWinner'])->name('customer.tournament-matches.winner.clear');

        Route::get('programs/mine', [CustomerProgramController::class, 'mine'])->name('customer.programs.mine');
        Route::get('programs/passes', [CustomerProgramController::class, 'passes'])->name('customer.programs.passes');
        Route::get('programs/offers/check', [CustomerProgramController::class, 'checkOffer'])->name('customer.programs.offers.check');
        Route::post('programs/offers/check', [CustomerProgramController::class, 'checkOffer'])->name('customer.programs.offers.check.post');
        Route::post('programs/{uuid}/enroll', [CustomerProgramController::class, 'enroll'])->middleware('rewards.unsupported')->name('customer.programs.enroll');
        Route::get('programs/checkout/{sessionId}', [CustomerProgramController::class, 'checkoutState'])->name('customer.programs.checkout-state');
        Route::post('programs/subscriptions/{uuid}/cancel', [CustomerProgramController::class, 'cancel'])->name('customer.programs.cancel');
        // Undo a cancellation that has not taken effect yet (cancel_pending).
        Route::post('programs/subscriptions/{uuid}/resume', [CustomerProgramController::class, 'resume'])->name('customer.programs.resume');
    });

});

Route::get('home-page-data', [HelperController::class, 'homePageData'])->name('home-page-data');
Route::get('blog-categories', [PostCategoryController::class, 'blogCategories'])->name('blog-categories');
Route::get('blog-articles', [PostController::class, 'blogArticles'])->name('blog-articles');
Route::get('blog-articles/{slug}', [PostController::class, 'blogPostDetails'])->name('blog-articles.show');
Route::get('sports-categories', [SportsCategoryController::class, 'sportsCategories'])->name('sports-categories.public');
Route::post('contact-us', [HelperController::class, 'contactUs'])->name('contact-us');
Route::get('fetch-venues', [VenueController::class, 'fetchVenues'])->name('customer.fetch-venues');
Route::get('fetch-listings', [ListingController::class, 'fetchListings'])->name('fetch-listings');

Route::prefix('marketplace')->name('marketplace.')->group(function () {
    Route::get('listings', [MarketplaceCatalogController::class, 'listings'])->name('listings');
    // One explicit category selection. Throttled per address because it is
    // public and unauthenticated; the service also dedupes and caps.
    Route::post('category-clicks', [MarketplaceCatalogController::class, 'categoryClick'])
        ->middleware('throttle:60,1')->name('category-clicks');
    Route::get('venues', [MarketplaceCatalogController::class, 'venues'])->name('venues');
    Route::get('venues/{id}', [MarketplaceCatalogController::class, 'venue'])->name('venues.show');
    Route::get('brand-locations', [MarketplaceCatalogController::class, 'brandLocations'])->name('brand-locations');
    Route::get('listings/{id}', [MarketplaceCatalogController::class, 'listing'])->name('listings.show');
    Route::get('platform-brand', [MarketplaceCatalogController::class, 'platformBrand'])->name('platform-brand');
    Route::get('brand', [MarketplaceCatalogController::class, 'brand'])->name('brand');
    Route::get('countries', [MarketplaceCatalogController::class, 'countries'])->name('countries');
    Route::get('cities', [MarketplaceCatalogController::class, 'cities'])->name('cities');
    Route::get('cities/{id}', [MarketplaceCatalogController::class, 'city'])->name('cities.show');
    Route::get('directory', [MarketplaceCatalogController::class, 'directory'])->name('directory');
    Route::get('default-city', [MarketplaceCatalogController::class, 'defaultCity'])->name('default-city');
    Route::get('served-regions', [MarketplaceCatalogController::class, 'servedRegions'])->name('served-regions');
    Route::get('loyalty-config', [MarketplaceCatalogController::class, 'loyaltyConfig'])->name('loyalty-config');
    // Build-time sitemap generator (apps/web scripts/gen-sitemap.mjs).
    Route::get('sitemap', [MarketplaceCatalogController::class, 'sitemap'])->name('sitemap');
    // People search. Throttled per address: public, unauthenticated and the
    // only endpoint here that reads across all customer rows.
    Route::get('users', [PublicProfileController::class, 'search'])
        ->middleware('throttle:30,1')->name('users');
    Route::get('profiles/{uuid}', [PublicProfileController::class, 'show'])->name('profiles.show');
    Route::get('communities/probe', [CustomerCommunityController::class, 'probe'])->name('communities.probe');
    Route::get('communities', [CustomerCommunityController::class, 'index'])->name('communities.index');
    Route::get('communities/{uuid}', [CustomerCommunityController::class, 'show'])->name('communities.show');
    Route::get('communities/{uuid}/members', [CustomerCommunityController::class, 'members'])->name('communities.members');
    Route::get('communities/{uuid}/posts', [CustomerCommunityController::class, 'feed'])->name('communities.posts');
    Route::get('communities/{uuid}/events', [CustomerGameController::class, 'communityEvents'])->name('communities.events');
    Route::get('games', [CustomerGameController::class, 'index'])->name('games.index');
    Route::get('games/config', [CustomerGameController::class, 'config'])->name('games.config');
    Route::get('tournaments/{uuid}', [CustomerGameController::class, 'showTournament'])->name('tournaments.show');
    Route::get('games/{uuid}', [CustomerGameController::class, 'show'])->name('games.show');
    Route::get('programs', [CustomerProgramController::class, 'index'])->name('programs.index');
    Route::get('programs/{uuid}', [CustomerProgramController::class, 'show'])->name('programs.show');
    Route::get('programs/{uuid}/hours', [CustomerProgramController::class, 'hours'])->name('programs.hours');
});
Route::get('fetch-suggested-listings', [ListingController::class, 'fetchSuggestedListings'])->name('fetch-suggested-listings');
Route::get('fetch-listing/{id}', [ListingController::class, 'fetchListing'])->name('fetch-listing');
Route::get('fetch-time-slots', [ListingController::class, 'fetchTimeSlots'])->name('fetch-time-slots');
Route::post('lock-slot', [ListingController::class, 'lockSlot'])->name('lock-slot');
Route::post('lock-slots', [ListingController::class, 'lockSlots'])->name('lock-slots');
Route::post('unlock-slot', [ListingController::class, 'unlockSlot'])->name('unlock-slot');
Route::post('unlock-all-slots', [ListingController::class, 'unlockAllSlots'])->name('unlock-all-slots');
Route::post('extend-slot-lock', [ListingController::class, 'extendSlotLock'])->name('extend-slot-lock');
Route::post('check-slot-availability', [ListingController::class, 'checkSlotAvailability'])->name('check-slot-availability');
Route::get('fetch-addons/{id}', [ListingController::class, 'fetchAddons'])->name('fetch-addons');
Route::get('fetch-recurring-booked-slots', [ListingController::class, 'fetchRecurringBookedSlots'])->name('fetch-recurring-booked-slots');
Route::get('listings', [ListingController::class, 'mobileListingsList'])->name('mobile.listings')->middleware('auth:sanctum');
Route::post('promocodes/apply', [PromocodeController::class, 'applyPromocode'])->name('promocodes.applyPromocode');

Route::post('newsletter/subscribe', [NewsletterController::class, 'subscribe'])->name('newsletter.subscribe');

// Desk "Pay by QR" — public; the booking uuid in the link is the credential.
Route::get('pay/{uuid}', [BookingPayLinkController::class, 'show'])->name('pay.show');
Route::post('pay/{uuid}/checkout', [BookingPayLinkController::class, 'checkout'])->name('pay.checkout');

/*
| WhatsApp inbound webhook — Meta calls this, so it is public by necessity. The
| GET is Meta's verification handshake; the POST carries inbound messages and
| delivery receipts, and is authenticated by its X-Hub-Signature-256 header
| rather than by a session.
*/
Route::get('whatsapp/webhook', [WhatsappWebhookController::class, 'verify'])->name('whatsapp.webhook.verify');
Route::post('whatsapp/webhook', [WhatsappWebhookController::class, 'handle'])->name('whatsapp.webhook.handle');

/*
| Image uploads. Any signed-in user (dashboard or storefront) may upload; the file
| lands on our own public disk rather than a third-party host, so the key never
| ships in the bundle and the files stay ours to serve, back up and delete.
*/
Route::post('uploads/image', [UploadController::class, 'image'])
    ->middleware('auth:sanctum')->name('uploads.image');

// One-click unsubscribe from a marketing email — no session, the token is the credential.
Route::post('marketing/unsubscribe', [MarketingController::class, 'unsubscribe'])->name('marketing.unsubscribe');
