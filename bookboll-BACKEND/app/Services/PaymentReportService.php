<?php

namespace App\Services;

use App\Models\Payment;
use App\Models\Venue;
use App\Support\AccessScope;
use App\Support\PaymentReportCache;
use App\Support\PaymentReportRules;
use App\Support\PaymentReportTotals;
use App\Support\PaymentReportPage;
use App\Support\StripeDocumentLink;
use Illuminate\Http\Request;

/** Recipient-based report over recorded amounts; does not mutate old payments. */
final class PaymentReportService
{
    public function dashboard(Request $request)
    {
        $request->validate([
            'from_date' => 'nullable|date_format:Y-m-d',
            'to_date' => 'nullable|date_format:Y-m-d'.($request->filled('from_date') ? '|after_or_equal:from_date' : ''),
            'amount_min' => 'nullable|numeric|min:0', 'amount_max' => 'nullable|numeric|min:0'.($request->filled('amount_min') ? '|gte:amount_min' : ''),
            'page' => 'nullable|integer|min:1', 'records_per_page' => 'nullable|integer|min:1|max:250',
            'search' => 'nullable|string|max:150',
            'sort' => 'nullable|in:date,amount,status,method', 'sort_dir' => 'nullable|in:asc,desc',
            'scope' => 'nullable|in:all,bookboll,provider,review,fee_split',
        ]);
        $actor = $request->user();
        $platform = AccessScope::isPlatform($actor);
        $businessId = $platform ? null : AccessScope::businessId($actor);
        $query = Payment::query();
        $venueIds = AccessScope::allowedVenueIds($actor);
        // Owners retain their own frozen ledger after a venue changes hands.
        // Staff remain narrowed to their currently accessible venues.
        $historicalOwner = !$platform && $businessId && $actor->hasRole('provider_admin');
        if ($venueIds !== null) {
            $programIds = AccessScope::scopedProgramIds($actor, $venueIds);
            $query->where(function ($q) use ($venueIds, $programIds, $historicalOwner, $businessId) {
                $q->whereHas('booking', fn ($b) => $b->whereIn('venue_id', $venueIds))
                    ->orWhereHas('subscription', fn ($s) => $s->whereIn('program_id', $programIds));
                if ($historicalOwner) $q->orWhere('financial_context->business_id', $businessId);
            });
        }
        // A transferred venue must not expose the old business's frozen ledger.
        if (!$platform && \Illuminate\Support\Facades\Schema::hasColumn('payments', 'financial_context')) {
            $query->where(fn ($q) => $q->whereNull('financial_context')
                ->orWhere('financial_context->business_id', $businessId ?? -1));
        }
        $query->with(['booking.venue', 'booking.listing', 'booking.user', 'subscription.program.venue', 'user']);
        $hasAllocations = \Illuminate\Support\Facades\Schema::hasTable('payment_allocations');
        if ($hasAllocations) $query->with('ownedAllocations');
        $sort = $request->input('sort', 'date');
        $dir = $request->input('sort_dir', 'desc');
        $perPage = (int) $request->input('records_per_page', 25);
        $page = (int) $request->input('page', 1);
        $start = ($page - 1) * $perPage;
        $pageRows = new PaymentReportPage($start, $perPage, $sort, $dir);
        $matched = 0; $totals = new PaymentReportTotals;
        $currencies = []; $venues = []; $businesses = [];

        // Process bounded batches. Keep the requested sorted window and totals;
        // allocations may inherit a receipt date older than their creation date.
        // Siblings are loaded BEFORE any payment filter so a filtered-out receipt
        // cannot make its fee/share rows look like additional receipts.
        // Scope before caching. A permission or ownership change selects a new key.
        $scope = hash('sha256', json_encode([$actor->id, $platform, $businessId, $venueIds, $programIds ?? [], $query->toSql(), $query->getBindings(), config('preview.workspace')]));
        $rows = PaymentReportCache::remember($scope, 'rows', function () use ($query, $hasAllocations) {
            $rows = [];
            $query->chunkById(1000, function ($batch) use (&$rows, $hasAllocations) {
                $bookingIds = $batch->pluck('booking_id')->filter()->unique();
                $siblings = $bookingIds->isEmpty() ? collect() : Payment::whereIn('booking_id', $bookingIds)->when($hasAllocations, fn ($q) => $q->with('ownedAllocations'))->get()->groupBy('booking_id');
                $subscriptionIds = $batch->whereNull('booking_id')->pluck('subscription_id')->filter()->unique();
                $subSiblings = $subscriptionIds->isEmpty() ? collect() : Payment::whereIn('subscription_id', $subscriptionIds)->get()->groupBy('subscription_id');
                $cache = [];
                foreach ($batch as $payment) {
                    $booking = $payment->booking;
                    $groupKey = $payment->booking_id ? 'b'.$payment->booking_id : ($payment->subscription_id ? 's'.$payment->subscription_id : 'p'.$payment->id);
                    if (!isset($cache[$groupKey])) {
                        $group = $payment->booking_id ? ($siblings[$payment->booking_id] ?? collect([$payment]))
                            : ($payment->subscription_id ? ($subSiblings[$payment->subscription_id] ?? collect([$payment])) : collect([$payment]));
                        $data = $group->map(fn ($p) => $this->raw($p))->all();
                        $b = $booking ? $booking->getAttributes() + [] : [];
                        $b['financial_context'] = $booking?->financial_context ?? [];
                        $b['creation_context'] = $booking?->creation_context ?? [];
                        $cache[$groupKey] = collect(PaymentReportRules::rows($data, $b))->groupBy(fn ($row) => (string) ($row['source_payment_id'] ?? $row['id']));
                    }
                    foreach ($cache[$groupKey][(string) $payment->id] as $row) {
                    if ($row['row_kind'] === 'receipt') continue;
                    $row = $this->describe($row, $payment);
                    $rows[] = $row;
                    }
                }
            });
            return $rows;
        }, $request->boolean('refresh'));
        foreach ($rows as $row) {
            $row['can_view_booking'] = $venueIds === null || in_array((int)$row['venue_id'], $venueIds, true);
            $row['can_collect'] = $row['can_collect'] && $row['can_view_booking'];
            if ($row['currency']) $currencies[$row['currency']] = true;
            if ($row['venue_id']) $venues[$row['venue_id']] = ['value' => (string) $row['venue_id'], 'label' => $row['venue_name']];
            if ($row['business_id']) $businesses[$row['business_id']] = ['value' => (string) $row['business_id'], 'label' => $row['business_name'] ?: $row['recipient_label']];
            if (!\App\Support\PaymentReportFilter::matches($row, $request->query())) continue;
            $totals->add($row);
            $pageRows->add($row);
            $matched++;
        }
        ksort($currencies);
        uasort($venues, fn ($a, $b) => strcasecmp($a['label'], $b['label']));
        return sendSuccess('Payment allocations fetched', [
            'report_version' => 2, 'perspective' => $platform ? 'platform' : 'provider',
            'payments' => ['data' => $pageRows->rows(), 'total' => $matched, 'current_page' => $page, 'per_page' => $perPage],
            'summary' => $totals->result(), 'review_count' => $totals->reviewCount,
            'fee_split_count' => $totals->feeSplitCount, 'fee_split_statuses' => $totals->feeSplitStatuses,
            'filter_options' => ['currencies' => array_keys($currencies), 'venues' => array_values($venues), 'businesses' => array_values($businesses)],
        ]);
    }

    private function raw(Payment $p): array
    {
        return array_merge($p->getAttributes(), ['financial_context' => $p->financial_context ?? [], 'currency' => $p->currency ?: '',
            'owned_allocations'=>$p->relationLoaded('ownedAllocations') ? $p->ownedAllocations->toArray() : []]);
    }

    private function describe(array $row, Payment $payment): array
    {
        $b = $payment->booking;
        $sub = $payment->subscription;
        $venue = $b?->venue ?? $sub?->program?->venue;
        $context = array_merge($b?->financial_context ?? [], $payment->financial_context ?? []);
        $customer = $b?->user ?? $payment->user;
        return array_merge($row, [
            'booking_id' => $payment->booking_id ? (string) $payment->booking_id : null,
            'booking_ref' => $b?->booking_id ?: ($payment->booking_id ? '#'.$payment->booking_id : ''),
            'created_by_name' => $b?->creation_context['actor_name'] ?? null,
            'created_by_kind' => $b?->creation_context['actor_kind'] ?? 'unknown',
            'creation_basis' => $b?->creation_context['basis'] ?? null,
            'customer_name' => $sub?->customer_name ?: ($customer?->name ?: '—'), 'customer_email' => $sub?->customer_email ?: ($customer?->email ?: ''),
            'venue_id' => $venue ? (string) $venue->id : null,
            'venue_name' => $venue?->name ?: '—', 'listing_name' => $b?->listing?->name ?: ($sub?->program?->name ?: ''),
            'business_name' => $context['business_name'] ?? null,
            'country_id' => $context['country_id'] ?? $b?->venue?->country_id,
            'city_id' => $context['city_id'] ?? $b?->venue?->city_id,
            'source' => $payment->source_type === 'subscription' ? 'subscription'
                : (($b?->origin === 'customer_game' || $payment->source_type === 'hosted_game') ? 'event' : 'venue'),
            'stripe_receipt_url' => StripeDocumentLink::sanitize($payment->stripe_receipt_url),
            'stripe_invoice_url' => StripeDocumentLink::sanitize($payment->stripe_invoice_url),
            'can_collect' => (!config('preview.enabled') || config('preview.workspace')) && $row['status'] === 'pending' && $row['category'] !== 'unclassified'
                && $payment->booking_id && $payment->method !== 'online',
        ]);
    }

}
