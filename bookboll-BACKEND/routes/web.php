<?php

use App\Http\Controllers\Admin\BackupController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\PasswordController;
use App\Http\Controllers\NewsletterController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\StripeWebhookController;
use App\Livewire\Admin\Activities\Index as ActivitiesIndex;
use App\Livewire\Admin\Activities\Show as ActivitiesShow;
use App\Livewire\Admin\Backups\Index as BackupsIndex;
use App\Livewire\Admin\Dashboard;
use App\Livewire\Admin\DeliveryTests\Index as DeliveryTestsIndex;
use App\Livewire\Admin\Profile\Edit as ProfileEdit;
use App\Livewire\Admin\Sessions\Index as SessionsIndex;
use App\Livewire\Admin\Settings\Index as SettingsIndex;
use App\Livewire\Admin\SlotLocks\Index as SlotLocksIndex;
use Illuminate\Support\Facades\Route;

Route::get('newsletter/unsubscribe/{email}', [NewsletterController::class, 'unsubscribe'])->name('newsletter.unsubscribe');

Route::post('/stripe/bookboll-webhook', [StripeWebhookController::class, 'handle'])->name('stripe.payment.webhook');

Route::get('/payment/success/{session_id}', [PaymentController::class, 'paymentSuccess'])->name('payment.success');
Route::get('/payment/status/{session_id}', [PaymentController::class, 'paymentStatus'])->name('payment.status');
Route::get('/payment/cancel', [PaymentController::class, 'cancelPayment'])->name('payment.cancel');
// Generic "reopen the mobile app here" hop for flows whose Stripe return does not
// pass through paymentSuccess (memberships) — see PaymentController::appRedirect.
Route::get('/payment/app-redirect', [PaymentController::class, 'appRedirect'])->name('payment.app-redirect');

Route::prefix('admin')->middleware(['auth', 'checkRoles:super_admin'])->group(function () {
    Route::get('/dashboard-000009', Dashboard::class)->name('dashboard');

    Route::get('/profile-000009', ProfileEdit::class)->name('profile.edit');

    Route::get('activities-000009', ActivitiesIndex::class)->name('activities.index');
    Route::get('activities-000009/{activity}', ActivitiesShow::class)->name('activities.show');

    // NOT 'settings.index' — routes/api.php already owns that name, and the
    // later registration wins, so this link would resolve to the JSON endpoint.
    Route::get('settings-000009', SettingsIndex::class)->name('platform-settings.index');

    Route::get('slot-locks-000009', SlotLocksIndex::class)->name('slot-locks.index');

    Route::get('sessions-000009', SessionsIndex::class)->name('sessions.index');

    Route::get('delivery-tests-000009', DeliveryTestsIndex::class)->name('delivery-tests.index');

    Route::get('backups-000009', BackupsIndex::class)->name('backups.index');
    Route::get('backups-000009/{filename}/download', [BackupController::class, 'download'])->name('backups.download')->where('filename', '[\w\-\.]+');

    Route::post('logout-000009', [AuthenticatedSessionController::class, 'destroy'])->name('logout');
    Route::put('password-000009', [PasswordController::class, 'update'])->name('password.update');
});

Route::middleware('guest')->group(function () {
    Route::get('login-000009', [AuthenticatedSessionController::class, 'create'])->name('login');

    Route::post('login-000009', [AuthenticatedSessionController::class, 'store'])->name('login.store');
});
