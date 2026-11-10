<?php
namespace App\Http\Controllers;

use App\Exports\PaymentsExport;
use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\PaymentMethod as PaymentMethodModel;
use App\Services\BookingService;
use App\Services\PaymentService;
use App\Services\StripeService;
use App\Support\Capabilities;
use App\Support\MobileAppReturn;
use App\Support\TenantOrigins;
use Maatwebsite\Excel\Facades\Excel;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Stripe\PaymentIntent;
use Stripe\Stripe;

class PaymentController extends Controller
{
    public function __construct(
        protected PaymentService $paymentService,
        protected StripeService $stripeService,
        protected BookingService $bookingService,
    ) {}

    /**
     * Refuse a caller who may not handle payments, or null to continue.
     *
     * Every route below is already declared `capability:accept_payments`. These
     * methods used to RE-CHECK for super_admin/admin only, which silently overrode
     * that gate and refused the whole provider ladder — a business owner holding
     * accept_payments got 403 taking payment at their own venue, and the admin
     * mobile app surfaced it as "You are not authorized to accept payments".
     *
     * Checking the capability keeps the guard without contradicting the route.
     */
    protected function denyWithoutPaymentAccess()
    {
        $user = auth()->user();

        if ($user && $user->hasCapability(Capabilities::ACCEPT_PAYMENTS)) {
            return null;
        }

        return sendError('You do not have permission to manage payments.', [], 403);
    }

    public function index(Request $request)
    {
        return $this->paymentService->list($request);
    }

    public function dashboard(Request $request)
    {
        if ((int) $request->input('report_version') === 2) {
            return app(\App\Services\PaymentReportService::class)->dashboard($request);
        }
        return $this->paymentService->dashboard($request);
    }

    public function show($id)
    {
        return $this->paymentService->details($id);
    }

    public function totals()
    {
        return response()->json($this->paymentService->getDashboardTotals());
    }

    public function export(Request $request)
    {
        if ($deny = $this->denyWithoutPaymentAccess()) {
            return $deny;
        }

        $payments = $this->paymentService->getPaymentsForExport($request);
        $filename = 'payments-' . now()->format('Y-m-d') . '.xlsx';

        return Excel::download(new PaymentsExport($payments), $filename);
    }

    public function downloadInvoice($id)
    {
        if ($deny = $this->denyWithoutPaymentAccess()) {
            return $deny;
        }

        return $this->paymentService->downloadInvoice($id);
    }

    public function createPaymentIntent(Request $request)
    {
        $request->validate([
            'amount'                  => 'required|integer|min:100',
            'currency'                => 'required|string',
            'listing_id'              => 'required|integer',
            'promocode'               => 'nullable|string',
            'payment_method'          => 'required|string',
            'venue_id'                => 'required|integer',
            'saved_payment_method_id' => 'nullable|numeric|exists:payment_methods,id',
        ]);

        Stripe::setApiKey(config('services.stripe.secret'));

        try {
            $user = auth()->user();

            $user->createOrGetStripeCustomer();

            if (! $user->stripe_id) {
                Log::error('No Stripe Customer ID for user', ['user_id' => $user->id]);
                return sendError('Customer setup failed', [], 500);
            }

            $metadata = [
                'user_id'        => $user->id,
                'venue_id'       => $request->venue_id,
                'promocode'      => $request->promocode,
                'listing_id'     => $request->listing_id,
                'payment_method' => $request->payment_method,
            ];

            $paymentIntentData = [
                'payment_method_types' => ['card'],
                'metadata'             => $metadata,
                'setup_future_usage'   => 'off_session',
                'amount'               => $request->amount,
                'customer'             => $user->stripe_id,
                'currency'             => $request->currency,
                'description'          => 'Bookboll Booking payment intent for user #' . $user->email,
            ];

            if ($request->saved_payment_method_id) {
                $savedPaymentMethod = PaymentMethodModel::where('id', $request->saved_payment_method_id)
                    ->where('user_id', $user->id)->first();

                if (! $savedPaymentMethod) {
                    return sendError('Payment method not found or does not belong to you');
                }

                if ($savedPaymentMethod->stripe_payment_method_id) {
                    try {
                        $stripePm = $user->findPaymentMethod($savedPaymentMethod->stripe_payment_method_id);
                        if ($stripePm) {
                            $paymentIntentData['payment_method'] = $savedPaymentMethod->stripe_payment_method_id;
                        } else {
                            Log::warning('Stripe payment method not found for saved payment method', [
                                'saved_payment_method_id'  => $request->saved_payment_method_id,
                                'stripe_payment_method_id' => $savedPaymentMethod->stripe_payment_method_id,
                                'user_id'                  => $user->id,
                            ]);
                            return sendError('Saved payment method is no longer available in Stripe. Please use a different payment method.', [], 404);
                        }
                    } catch (Exception $e) {
                        Log::error('Error retrieving Stripe payment method', [
                            'saved_payment_method_id'  => $request->saved_payment_method_id,
                            'stripe_payment_method_id' => $savedPaymentMethod->stripe_payment_method_id,
                            'user_id'                  => $user->id,
                            'error'                    => $e->getMessage(),
                        ]);
                        return sendError('Error retrieving saved payment method. Please use a different payment method.', [], 500);
                    }
                } else {

                    $stripePaymentMethods = $user->paymentMethods();

                    $matchingStripePaymentMethod = null;
                    foreach ($stripePaymentMethods as $stripePm) {
                        if ($stripePm->type === 'card' && $stripePm->card) {
                            $card = $stripePm->card;
                            if (
                                $card->last4 === $savedPaymentMethod->last_four &&
                                strtolower($card->brand) === strtolower($savedPaymentMethod->brand) &&
                                $card->exp_month === $savedPaymentMethod->exp_month &&
                                $card->exp_year === $savedPaymentMethod->exp_year
                            ) {
                                $matchingStripePaymentMethod = $stripePm;
                                $savedPaymentMethod->update(['stripe_payment_method_id' => $stripePm->id]);
                                break;
                            }
                        }
                    }

                    if ($matchingStripePaymentMethod) {
                        $paymentIntentData['payment_method'] = $matchingStripePaymentMethod->id;
                    } else {
                        Log::warning('Saved payment method found but no matching Stripe payment method', [
                            'saved_payment_method_id' => $request->saved_payment_method_id,
                            'user_id'                 => $user->id,
                        ]);
                        return sendError('Saved payment method not found in Stripe. Please use a different payment method.', [], 404);
                    }
                }
            }

            $payment = PaymentIntent::create($paymentIntentData);

            return sendSuccess("Client secret created successfully", [
                'client_secret'     => $payment->client_secret,
                'payment_intent_id' => $payment->id,
            ]);

        } catch (Exception $e) {
            Log::error('Stripe Payment Intent Error: ' . $e->getMessage(), [
                'user_id'      => auth()->id(),
                'request_data' => $request->all(),
                'customer_id'  => $user->stripe_id ?? 'null',
            ]);
            return sendError("Something went wrong: " . $e->getMessage());
        }
    }

    public function generatePaymentLink(Request $request)
    {
        if ($deny = $this->denyWithoutPaymentAccess()) {
            return $deny;
        }

        return $this->paymentService->generatePaymentLink($request);
    }

    public function confirmPayment(Request $request)
    {
        if ($deny = $this->denyWithoutPaymentAccess()) {
            return $deny;
        }

        return $this->paymentService->confirmPayment($request);
    }

    public function paymentSuccess($session_id)
    {
        try {
            $state = $this->stripeService->getSessionReturnState($session_id);
        } catch (Exception $e) {
            Log::error('Stripe success return error: ' . $e->getMessage(), ['session_id' => $session_id]);

            return $this->renderPaymentReturnFailure('customer');
        }

        if ($state['status'] === 'completed') {
            return $this->renderPaymentReturnSuccess($state);
        }

        if ($state['status'] === 'processing') {
            return view('payment-processing', ['session_id' => $session_id]);
        }

        return $this->renderPaymentReturnCancelled($state);
    }

    public function paymentStatus($session_id)
    {
        try {
            $state = $this->stripeService->getSessionReturnState($session_id);

            return response()->json([
                'status'      => $state['status'],
                'source'      => $state['source'],
                'booking_ids' => $state['booking_ids'],
                'message'     => $state['message'],
            ]);
        } catch (Exception $e) {
            Log::error('Stripe payment status error: ' . $e->getMessage(), ['session_id' => $session_id]);

            return response()->json([
                'status'  => 'error',
                'message' => 'Unable to verify payment status.',
            ], 500);
        }
    }

    public function createCheckoutSession(Request $request)
    {
        $user = auth()->user();

        if (!$user) {
            return sendError('Unauthorized', [], 401);
        }

        // `slots` and `blocks` are required_without each other, so an empty
        // selection fails BOTH rules and Laravel answers with
        // "The slots field is required when blocks is not present. The blocks
        // field is required when slots is not present." That is what a customer
        // whose hold had merely lapsed saw on the mobile app. The cash route has
        // always checked this first; this one never did.
        if (! $this->bookingService->hasSlotSelection($request)) {
            return sendError('Please pick at least one time slot.', [], 422);
        }

        $validated = $request->validate(
            $this->bookingService->customerBookingValidationRules([
                'online_payment',
                'deposit_remaining_on_arrival',
            ])
        );

        try {
            return $this->paymentService->createCheckoutSession($validated, $user);
        } catch (Exception $e) {
            Log::error('Create checkout session error: ' . $e->getMessage(), [
                'user_id' => $user->id,
                'request' => $request->all(),
            ]);
            return sendError('Failed to create checkout session: ' . $e->getMessage());
        }
    }

    public function cancelPayment()
    {
        $sessionId = request()->get('session_id');
        $source = 'customer';
        $paymentLinkUrl = null;
        $state = null;

        if ($sessionId) {
            try {
                $state = $this->stripeService->getSessionReturnState($sessionId);

                if ($state['status'] === 'completed') {
                    return $this->renderPaymentReturnSuccess($state);
                }

                if ($state['status'] === 'processing') {
                    return view('payment-processing', ['session_id' => $sessionId]);
                }

                // Customer left Stripe checkout — expire session and cancel unpaid bookings
                // so browser "back" cannot complete payment on a dead session.
                $state = $this->stripeService->abandonCheckoutSession($sessionId);

                $source = $state['source'] ?? 'customer';

                if ($source === 'admin' && isset($state['session'])) {
                    $paymentLinkUrl = $state['session']->url ?? null;
                }
            } catch (Exception $e) {
                Log::error('Payment cancel return error: ' . $e->getMessage(), [
                    'session_id' => $sessionId,
                ]);
            }
        }

        if ($source === 'admin') {
            return view('payment-cancel', [
                'isAdminPayment'  => true,
                'paymentLinkUrl' => $paymentLinkUrl,
            ]);
        }

        if (MobileAppReturn::wantsApp($state['session'] ?? null)) {
            return $this->renderAppReturn($state, 'cancelled');
        }

        return redirect($this->storefrontUrl($state, '/payment/cancel'));
    }

    /**
     * Where to send a customer after Stripe: the storefront they actually started the
     * checkout on, recorded in the session metadata when it was created.
     *
     * Without this everyone lands on FRONTEND_URL — the marketplace — so a customer who
     * booked on a merchant's white-label site (alamgir.bookboll.com, or their own
     * domain) is dropped off that merchant's brand the moment they pay or cancel.
     *
     * The recorded origin is re-validated on the way back rather than trusted: metadata
     * travels through Stripe, and a custom domain may have been disconnected while the
     * customer was on the checkout page. An unknown origin falls back to the marketplace,
     * which is also what old sessions created before this existed will do.
     */
    protected function storefrontUrl(?array $state, string $path): string
    {
        $recorded = $state['session']->metadata['return_origin'] ?? null;
        $base = TenantOrigins::storefrontOrigin($recorded)
            ?? config('app.frontend_url', 'https://bookboll.com');

        return rtrim($base, '/') . $path;
    }

    protected function renderPaymentReturnSuccess(array $state)
    {
        $bookingIds = $state['booking_ids'] ?? [];
        $source     = $state['source'] ?? 'customer';

        // Started in the mobile app: reopen the app rather than the website. Checked
        // before the hosted-game branch because a host on the phone belongs back in
        // the app's hosting screen, not on bookboll.com/host-game.
        if (MobileAppReturn::wantsApp($state['session'] ?? null)) {
            return $this->renderAppReturn($state, 'success');
        }

        // A host who just paid for their courts belongs back on the hosting page,
        // where the share link for the event they published is waiting.
        if ($source === \App\Services\CustomerGameService::CHECKOUT_SOURCE) {
            return redirect($this->hostReturnUrl($state, [
                'published' => $state['session']->metadata['hosted_game_uuid'] ?? '',
                'type'      => $state['session']->metadata['game_type'] ?? 'game',
            ]));
        }

        if ($source === 'admin') {
            $bookings = Booking::with(['venue:id,name', 'listing:id,name'])
                ->whereIn('id', $bookingIds)
                ->get();

            return view('payment-success', compact('bookings'));
        }

        return redirect($this->storefrontUrl($state, '/thank-you?booking_ids=' . implode(',', $bookingIds)));
    }

    protected function renderPaymentReturnCancelled(array $state)
    {
        $source  = $state['source'] ?? 'customer';
        $message = $state['message'] ?? 'Payment was not completed.';

        if (MobileAppReturn::wantsApp($state['session'] ?? null)) {
            return $this->renderAppReturn($state, 'cancelled');
        }

        // The event was discarded with the payment, so send the host back to the
        // form rather than to a booking page that no longer means anything.
        if ($source === \App\Services\CustomerGameService::CHECKOUT_SOURCE) {
            return redirect($this->hostReturnUrl($state, ['payment' => 'cancelled']));
        }

        if ($source === 'admin') {
            return view('payment-cancel', ['error' => $message]);
        }

        return redirect($this->storefrontUrl($state, '/payment/cancel'));
    }

    /** Where a host lands after Stripe, on the hosting page of the storefront. */
    protected function hostReturnUrl(array $state, array $query): string
    {
        return $this->storefrontUrl($state, '/host-game?'.http_build_query(array_filter($query)));
    }

    protected function renderPaymentReturnFailure(string $source = 'customer')
    {
        if ($source === 'admin') {
            return view('payment-cancel', [
                'error' => 'Payment verification failed. Please contact support if this issue persists.',
            ]);
        }

        // No session could be read, so the originating storefront is unknown — the
        // marketplace is the only address left.
        $frontendUrl = config('app.frontend_url', 'https://bookboll.com');

        return redirect(rtrim($frontendUrl, '/') . '/payment/cancel');
    }

    /**
     * Hand a customer who paid (or bailed out) in the mobile app back to the app.
     *
     * Stripe will only redirect to http(s), so the phone's browser lands here, and
     * this page bounces it on to `bookboll://payment/return?...`. It is a page and
     * not a 302 on purpose: Safari and Chrome both treat a custom-scheme navigation
     * that no tap started with suspicion, so the automatic jump is paired with a
     * button that always works, and with the website as the last-resort link. The
     * app also polls the session itself when it regains focus, so even a customer
     * who simply closes this tab ends up on the right screen.
     */
    protected function renderAppReturn(array $state, string $status)
    {
        $session = $state['session'] ?? null;
        $source  = $state['source'] ?? 'customer';
        $meta    = $session->metadata ?? null;
        $isGame  = $source === \App\Services\CustomerGameService::CHECKOUT_SOURCE;

        $query = [
            'status'      => $status,
            'flow'        => $isGame ? 'game' : 'booking',
            'session_id'  => $session->id ?? null,
            'booking_ids' => implode(',', array_map('intval', $state['booking_ids'] ?? [])),
            'listing_id'  => $meta['listing_id'] ?? null,
            'venue_id'    => $meta['venue_id'] ?? null,
            'published'   => $isGame ? ($meta['hosted_game_uuid'] ?? null) : null,
            'type'        => $isGame ? ($meta['game_type'] ?? null) : null,
            'message'     => $status === 'cancelled' ? ($state['message'] ?? null) : null,
        ];

        // Where this return would have gone on the web — offered as a fallback link.
        $websiteUrl = $isGame
            ? $this->hostReturnUrl($state, $status === 'success'
                ? ['published' => $query['published'] ?? '', 'type' => $query['type'] ?? 'game']
                : ['payment' => 'cancelled'])
            : $this->storefrontUrl($state, $status === 'success'
                ? '/thank-you?booking_ids='.$query['booking_ids']
                : '/payment/cancel');

        return response()->view('payment-app-return', [
            'status'     => $status,
            'appUrl'     => MobileAppReturn::url($query),
            'intentUrl'  => MobileAppReturn::androidIntentUrl($query, $websiteUrl),
            'websiteUrl' => $websiteUrl,
        ]);
    }

    /**
     * GET /payment/app-redirect?to=memberships/{uuid}&checkout={session|cancelled}
     *
     * Reopens the mobile app at an app screen after a Stripe Checkout whose return
     * does not go through paymentSuccess() — membership subscriptions, whose
     * success_url is normally the storefront program page. Only app paths on the
     * allow-list below are accepted, so this can never be used to bounce a browser
     * to an arbitrary scheme or path.
     */
    public function appRedirect(Request $request)
    {
        $to       = trim((string) $request->query('to', ''));
        $checkout = trim((string) $request->query('checkout', ''));

        $allowed = [
            '#^memberships/[A-Za-z0-9\-]{1,64}$#',
        ];
        $ok = false;
        foreach ($allowed as $pattern) {
            if (preg_match($pattern, $to)) {
                $ok = true;
                break;
            }
        }
        if (! $ok || ! preg_match('#^(cancelled|cs_[A-Za-z0-9_]{1,120})$#', $checkout)) {
            return redirect(rtrim((string) config('app.frontend_url', 'https://bookboll.com'), '/'));
        }

        $status = $checkout === 'cancelled' ? 'cancelled' : 'success';
        $query  = ['checkout' => $checkout];

        // The same destination on the website, offered as the fallback link.
        $websitePath = str_starts_with($to, 'memberships/')
            ? '/programs/'.substr($to, strlen('memberships/')).'?checkout='.$checkout
            : '/';
        $websiteUrl = rtrim((string) config('app.frontend_url', 'https://bookboll.com'), '/').$websitePath;

        return response()->view('payment-app-return', [
            'status'     => $status,
            'appUrl'     => MobileAppReturn::url($query, $to),
            'intentUrl'  => MobileAppReturn::androidIntentUrl($query, $websiteUrl, $to),
            'websiteUrl' => $websiteUrl,
        ]);
    }

    /**
     * GET /api/customer/payments/checkout-session/{sessionId}
     *
     * The mobile app's view of a Stripe Checkout it started: has it been paid, is
     * it still open, or is it gone. Reading the state also drives fulfilment (see
     * StripeService::getSessionReturnState), so the app does not have to wait on
     * the webhook. When the payment has landed the bookings come back too, in the
     * same shape as /customer/bookings/by-ids, so the thank-you screen is one call.
     */
    public function checkoutSessionState(string $sessionId)
    {
        $user = auth()->user();
        if (! $user) {
            return sendError('Unauthorized', [], 401);
        }

        try {
            $state = $this->stripeService->getSessionReturnState($sessionId);
        } catch (Exception $e) {
            Log::error('Checkout session state error: '.$e->getMessage(), [
                'session_id' => $sessionId,
                'user_id'    => $user->id,
            ]);

            return sendError('Unable to verify payment status.', [], 500);
        }

        if (! $this->checkoutSessionBelongsTo($state, $user)) {
            return sendError('Checkout session not found.', [], 404);
        }

        return sendSuccess('Checkout session state', $this->checkoutSessionPayload($state, $user));
    }

    /**
     * POST /api/customer/payments/checkout-session/{sessionId}/abandon
     *
     * The customer closed the payment page without paying and chose not to
     * continue. Expire the Stripe session and release the pending bookings now,
     * instead of leaving them to the auto-cancel sweep. Idempotent, and never
     * destructive to a session that has in fact been paid: the state is read first
     * and a paid session is reported as completed instead.
     */
    public function abandonCheckoutSession(string $sessionId)
    {
        $user = auth()->user();
        if (! $user) {
            return sendError('Unauthorized', [], 401);
        }

        try {
            $state = $this->stripeService->getSessionReturnState($sessionId);

            if (! $this->checkoutSessionBelongsTo($state, $user)) {
                return sendError('Checkout session not found.', [], 404);
            }

            if ($state['status'] !== 'completed' && $state['status'] !== 'processing') {
                $state = $this->stripeService->abandonCheckoutSession($sessionId);
            }
        } catch (Exception $e) {
            Log::error('Abandon checkout session error: '.$e->getMessage(), [
                'session_id' => $sessionId,
                'user_id'    => $user->id,
            ]);

            return sendError('Could not cancel the payment session.', [], 500);
        }

        return sendSuccess('Checkout session updated', $this->checkoutSessionPayload($state, $user));
    }

    /** Only the customer who opened a checkout may read or abandon it. */
    protected function checkoutSessionBelongsTo(array $state, $user): bool
    {
        $meta   = $state['session']->metadata ?? null;
        $userId = (int) ($meta['user_id'] ?? 0);

        if ($userId > 0) {
            return $userId === (int) $user->id;
        }

        $bookingIds = array_filter(array_map('intval', $state['booking_ids'] ?? []));
        if (empty($bookingIds)) {
            return false;
        }

        return Booking::whereIn('id', $bookingIds)->where('user_id', $user->id)->exists();
    }

    /** @return array<string, mixed> */
    protected function checkoutSessionPayload(array $state, $user): array
    {
        $session    = $state['session'] ?? null;
        $meta       = $session->metadata ?? null;
        $bookingIds = array_values(array_filter(array_map('intval', $state['booking_ids'] ?? [])));
        $bookings   = collect();

        if ($state['status'] === 'completed' && ! empty($bookingIds)) {
            $bookings = Booking::with([
                'venue:id,name,address,maps_url',
                'listing:id,name',
                'payments',
                'addOns',
                'latestInvoice',
            ])
                ->whereIn('id', $bookingIds)
                ->where('user_id', $user->id)
                ->orderBy('date')
                ->orderBy('start_time')
                ->get();
        }

        // Stripe's own lifecycle, separate from our fulfilment status: an `open`
        // session can still be paid, so the app may offer "continue payment" with
        // the same URL rather than making the customer start over.
        $checkoutStatus = $session->status ?? null;

        return [
            'status'          => $state['status'],
            'checkout_status' => $checkoutStatus,
            'source'          => $state['source'] ?? 'customer',
            'message'         => $state['message'] ?? null,
            'booking_ids'     => $bookingIds,
            'listing_id'      => isset($meta['listing_id']) && $meta['listing_id'] !== '' ? (int) $meta['listing_id'] : null,
            'venue_id'        => isset($meta['venue_id']) && $meta['venue_id'] !== '' ? (int) $meta['venue_id'] : null,
            'payment_type'    => $meta['payment_type'] ?? null,
            'url'             => $checkoutStatus === 'open' && $state['status'] !== 'completed' ? ($session->url ?? null) : null,
            'bookings'        => $bookings,
        ];
    }

    public function getPaymentMethods()
    {
        return $this->paymentService->customerPaymentMethods();
    }

    public function markBookingAsPaid(Request $request)
    {
        if ($deny = $this->denyWithoutPaymentAccess()) {
            return $deny;
        }

        return $this->paymentService->markBookingAsPaid($request);
    }

    public function markPaymentAsReceived(Request $request, $id)
    {
        if ($deny = $this->denyWithoutPaymentAccess()) {
            return $deny;
        }

        return $this->paymentService->markPaymentAsReceived($request, $id);
    }
}
