<?php

namespace App\Services;

use App\Models\Payment;
use App\Models\Venue;
use App\Support\AccessScope;
use App\Support\PaymentReportCache;
use App\Support\PaymentReportRules;
use App\Support\PaymentReportTotals;
use App\Support\PaymentReportPage;
use App\Support\PaymentReportGroups;
use App\Support\StripeDocumentLink;
use Illuminate\Http\Request;

/** Recipient-based report over recorded amounts; does not mutate old payments. */
final class PaymentReportService
{
    /** Shared ledger scope for list, connected transactions and document reads. */
    public function authorizedQuery($actor)
    {
        $platform = AccessScope::isPlatform($actor);
        $businessId = $platform ? null : AccessScope::businessId($actor);
        $venueIds = AccessScope::allowedVenueIds($actor);
        $query = Payment::query();
        if ($venueIds !== null) {
            $programIds = AccessScope::scopedProgramIds($actor, $venueIds);
            $historicalOwner = !$platform && $businessId && $actor->hasRole('provider_admin');
            $query->where(function ($q) use ($venueIds, $programIds, $historicalOwner, $businessId) {
                $q->whereHas('booking', fn ($b) => $b->whereIn('venue_id', $venueIds))
                    ->orWhereHas('subscription', fn ($s) => $s->whereIn('program_id', $programIds));
                if ($historicalOwner) $q->orWhere('financial_context->business_id', $businessId);
            });
        }
        if (!$platform && \Illuminate\Support\Facades\Schema::hasColumn('payments', 'financial_context')) {
            $query->where(fn ($q) => $q->whereNull('financial_context')
                ->orWhere('financial_context->business_id', $businessId ?? -1));
        }
        return $query;
    }

    public function dashboard(Request $request, bool $selectionOnly = false, bool $analyticsOnly = false)
    {
        if ($selectionOnly) abort_unless(\App\Support\PaymentBulkRules::allows($request->user()),403);
        $request->validate([
            'from_date' => 'nullable|date_format:Y-m-d',
            'to_date' => 'nullable|date_format:Y-m-d'.($request->filled('from_date') ? '|after_or_equal:from_date' : ''),
            'amount_min' => 'nullable|numeric|min:0', 'amount_max' => 'nullable|numeric|min:0'.($request->filled('amount_min') ? '|gte:amount_min' : ''),
            'page' => 'nullable|integer|min:1', 'records_per_page' => 'nullable|integer|min:1|max:250',
            'search' => 'nullable|string|max:150',
            'sort' => 'nullable|in:date,amount,status,method', 'sort_dir' => 'nullable|in:asc,desc',
            'scope' => 'nullable|in:all,bookboll,provider,review,fee_split',
            'group_by' => 'nullable|in:stripe,allocation',
        ]);
        $actor = $request->user();
        $platform = AccessScope::isPlatform($actor);
        $businessId = $platform ? null : AccessScope::businessId($actor);
        $query = $this->authorizedQuery($actor);
        $venueIds = AccessScope::allowedVenueIds($actor);
        $query->with(['booking.venue', 'booking.listing', 'booking.user', 'subscription.program.venue', 'user', 'paymentRefund']);
        $hasAllocations = \Illuminate\Support\Facades\Schema::hasTable('payment_allocations');
        if ($hasAllocations) $query->with('ownedAllocations');
        $sort = $request->input('sort', 'date');
        $dir = $request->input('sort_dir', 'desc');
        $perPage = (int) $request->input('records_per_page', 25);
        $page = (int) $request->input('page', 1);
        $start = ($page - 1) * $perPage;
        $pageRows = new PaymentReportPage($start, $perPage, $sort, $dir);
        $matched = 0; $totals = new PaymentReportTotals;
        $currencies = []; $venues = []; $listings = []; $businesses = [];

        // Process bounded batches. Keep the requested sorted window and totals;
        // allocations may inherit a receipt date older than their creation date.
        // Siblings are loaded BEFORE any payment filter so a filtered-out receipt
        // cannot make its fee/share rows look like additional receipts.
        // Scope before caching. A permission or ownership change selects a new key.
        $scope = hash('sha256', json_encode([$actor->id, $platform, $businessId, $venueIds, $query->toSql(), $query->getBindings(), config('preview.workspace')]));
        $rows = PaymentReportCache::remember($scope, 'rows', function () use ($query, $hasAllocations, $actor) {
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
            return $this->withProcessorIdentity($actor, $rows);
        }, $request->boolean('refresh'));
        $mayRefund = \App\Support\PaymentRefundAccess::allows($actor);
        $grouped = !$analyticsOnly && $request->input('group_by') === 'stripe';
        $groups = new PaymentReportGroups($rows);
        $matching = []; $selection = [];
        $analytics = $analyticsOnly ? new \App\Support\PaymentReportAnalytics($rows, $request->query()) : null;
        foreach ($rows as &$row) {
            $row = $groups->decorate($row);
            $row['can_request_refund'] = $mayRefund
                && $row['method'] === 'online' && $row['status'] === 'paid'
                && empty($row['refund_status'])
                && (!empty($row['charge_id']) || !empty($row['payment_intent_id']) || !empty($row['source_receipt_ids']));
            $row['can_view_booking'] = $venueIds === null || in_array((int)$row['venue_id'], $venueIds, true);
            $row['can_collect'] = $row['can_collect'] && $row['can_view_booking'];
            $row['can_review'] = $mayRefund && $row['category'] === 'unclassified';
            if ($row['currency']) $currencies[$row['currency']] = true;
            if ($row['venue_id']) $venues[$row['venue_id']] = ['value' => (string) $row['venue_id'], 'label' => $row['venue_name']];
            if ($row['listing_id']) $listings[$row['listing_id']] = [
                'value' => (string) $row['listing_id'], 'label' => $row['listing_name'] ?: 'Listing #'.$row['listing_id'],
                'venue_id' => $row['venue_id'], 'venue_name' => $row['venue_name'],
            ];
            if ($row['business_id'] && $row['operator'] !== 'bookboll') $businesses[$row['business_id']] = ['value' => (string) $row['business_id'], 'label' => $row['business_name'] ?: $row['recipient_label']];
            if (!\App\Support\PaymentReportFilter::matches($row, $request->query())) continue;
            if ($selectionOnly) {
                $selection[] = ['id'=>(string)$row['id'], 'booking_id'=>$row['booking_id'], 'subscription_id'=>$row['subscription_id'] ?? null];
                continue;
            }
            $totals->add($row);
            if ($analytics) $analytics->add($row);
            elseif ($grouped) $matching[] = $row;
            else $pageRows->add($row);
            $matched++;
        }
        unset($row);
        // Exact matching allocation IDs across every page, never hidden siblings.
        if ($selectionOnly) return sendSuccess('All filtered allocations selected', ['rows'=>$selection, 'allocation_count'=>count($selection)]);
        $entryCount = $matched;
        if ($grouped) {
            $entries = $groups->entries($matching, $platform);
            $entryCount = count($entries);
            foreach ($entries as $entry) $pageRows->add($entry);
        }
        $visible = $pageRows->rows();
        $documentRows = [];
        foreach ($visible as $entry) {
            if (!isset($entry['bookings'])) $documentRows[] = $entry;
            else foreach ($entry['bookings'] as $booking) foreach ($booking['allocations'] as $child) $documentRows[] = $child;
        }
        $documented = collect($this->withDocuments($actor, $documentRows))->keyBy('id');
        foreach ($visible as &$entry) {
            if (!isset($entry['bookings'])) {
                $entry = $documented[$entry['id']];
                continue;
            }
            $entry['anchor'] = $documented[$entry['anchor']['id']];
            foreach ($entry['bookings'] as &$booking) {
                $booking['allocations'] = array_map(fn ($r) => $documented[$r['id']], $booking['allocations']);
            }
            unset($booking);
        }
        unset($entry);
        ksort($currencies);
        uasort($venues, fn ($a, $b) => strcasecmp($a['label'], $b['label']));
        uasort($listings, fn ($a, $b) => strcasecmp($a['label'], $b['label']));
        uasort($businesses, fn ($a, $b) => strcasecmp($a['label'], $b['label']));
        $businessOptions = array_values($businesses);
        if ($platform) array_unshift($businessOptions, ['value' => 'bookboll', 'label' => 'Bookboll · own venues']);
        return sendSuccess('Payment allocations fetched', [
            'report_version' => 2, 'perspective' => $platform ? 'platform' : 'provider',
            'can_bulk_edit' => \App\Support\PaymentBulkRules::allows($actor),
            'group_by' => $grouped ? 'stripe' : 'allocation',
            'payments' => ['data' => $visible, 'total' => $entryCount, 'allocation_total'=>$matched, 'current_page' => $page, 'per_page' => $perPage],
            'summary' => $totals->result(), 'review_count' => $totals->reviewCount,
            ...($analytics ? ['analytics'=>$analytics->result()] : []),
            'fee_split_count' => $totals->feeSplitCount, 'fee_split_statuses' => $totals->feeSplitStatuses,
            'filter_options' => ['currencies' => array_keys($currencies), 'venues' => array_values($venues), 'listings' => array_values($listings), 'businesses' => $businessOptions],
        ]);
    }

    /** Inherit processor identity only from one explicit, authorized receipt. */
    public function withProcessorIdentity($actor, array $rows): array
    {
        $ids = collect($rows)->flatMap(fn ($r) => count($r['source_receipt_ids'] ?? []) === 1 ? $r['source_receipt_ids'] : [])->unique();
        if ($ids->isEmpty()) return $rows;
        $sources = $this->authorizedQuery($actor)->whereIn('id', $ids)->get(['id','charge_id','payment_intent_id'])->keyBy('id');
        foreach ($rows as &$row) {
            $links = $row['source_receipt_ids'] ?? [];
            $source = count($links) === 1 ? $sources->get((int)$links[0]) : null;
            if (!$source || ($row['method'] ?? '') !== 'online') continue;
            $row['charge_id'] ??= $source->charge_id;
            $row['payment_intent_id'] ??= $source->payment_intent_id;
        }
        return $rows;
    }

    /** Resolve documents from explicit receipt links, only for this authorized page. */
    public function withDocuments($actor, array $rows): array
    {
        $ids = collect($rows)->flatMap(fn ($r) => count($r['source_receipt_ids'] ?? []) === 1 ? $r['source_receipt_ids'] : [])->unique();
        if ($ids->isEmpty()) return $rows;
        $sources = $this->authorizedQuery($actor)->whereIn('id', $ids)->with('paymentRefund')->get()->keyBy('id');
        foreach ($rows as &$row) {
            $links = $row['source_receipt_ids'] ?? [];
            $source = count($links) === 1 ? $sources->get((int)$links[0]) : null;
            if (!$source) continue;
            $row['stripe_receipt_url'] ??= StripeDocumentLink::sanitize($source->stripe_receipt_url);
            $row['stripe_invoice_url'] ??= StripeDocumentLink::sanitize($source->stripe_invoice_url);
            $row['stripe_refund_receipt_url'] ??= StripeDocumentLink::sanitize($source->paymentRefund?->receipt_url);
            $row['refund_status'] ??= $source->paymentRefund?->status;
            if ($row['refund_status']) $row['can_request_refund'] = false;
        }
        return $rows;
    }

    public function raw(Payment $p): array
    {
        return array_merge($p->getAttributes(), ['financial_context' => $p->financial_context ?? [], 'currency' => $p->currency ?: '',
            'owned_allocations'=>$p->relationLoaded('ownedAllocations') ? $p->ownedAllocations->toArray() : []]);
    }

    public function describe(array $row, Payment $payment, bool $details = false): array
    {
        $b = $payment->booking;
        $sub = $payment->subscription;
        $venue = $b?->venue ?? $sub?->program?->venue;
        $context = array_merge($b?->financial_context ?? [], $payment->financial_context ?? []);
        $customer = $b?->user ?? $payment->user;
        // Cache/public rows contain report fields only. Keeping every source
        // attribute, financial audit snapshot and both nested allocations on
        // every expanded row can exhaust memory when serializing full history.
        // The classifier and refund preflight still use complete source facts.
        $row = array_intersect_key($row, array_flip([
            'id','payment_id','source_payment_id','amount','currency','method','status','type',
            'charge_id','payment_intent_id','operator','channel','business_id','category',
            'recipient_kind','recipient_label','fee_rate','row_kind','source_receipt_ids',
            'review_reason','review_decision_id','bulk_correction_id','snapshot_recorded','payment_date','allocation_id','receipt_amount','allocation_basis',
        ]));
        if ($details) $row['notes'] = $payment->notes;
        return array_merge($row, [
            'booking_id' => $payment->booking_id ? (string) $payment->booking_id : null,
            'subscription_id' => $payment->subscription_id ? (string) $payment->subscription_id : null,
            'checkout_reference' => $b?->checkout_reference,
            'customer_id' => $customer?->id,
            'booking_ref' => $b?->booking_id ?: ($payment->booking_id ? '#'.$payment->booking_id : ''),
            'created_by_name' => $b?->creation_context['actor_name'] ?? null,
            'created_by_kind' => $b?->creation_context['actor_kind'] ?? 'unknown',
            'creation_basis' => $b?->creation_context['basis'] ?? null,
            'customer_name' => $sub?->customer_name ?: ($customer?->name ?: '—'), 'customer_email' => $sub?->customer_email ?: ($customer?->email ?: ''),
            'venue_id' => $venue ? (string) $venue->id : null,
            'listing_id' => $b?->listing_id ? (string) $b->listing_id : null,
            'venue_name' => $venue?->name ?: '—', 'listing_name' => $b?->listing?->name ?: ($sub?->program?->name ?: ''),
            'business_name' => $context['business_name'] ?? null,
            'country_id' => $context['country_id'] ?? $b?->venue?->country_id,
            'city_id' => $context['city_id'] ?? $b?->venue?->city_id,
            'source' => $payment->source_type === 'subscription' ? 'subscription'
                : (($b?->origin === 'customer_game' || $payment->source_type === 'hosted_game') ? 'event' : 'venue'),
            'stripe_receipt_url' => StripeDocumentLink::sanitize($payment->stripe_receipt_url),
            'stripe_invoice_url' => StripeDocumentLink::sanitize($payment->stripe_invoice_url),
            'refund_status' => $payment->paymentRefund?->status,
            'stripe_refund_receipt_url' => StripeDocumentLink::sanitize($payment->paymentRefund?->receipt_url),
            'can_collect' => (!config('preview.enabled') || config('preview.workspace')) && $row['status'] === 'pending' && $row['category'] !== 'unclassified'
                && $payment->booking_id && $payment->method !== 'online',
        ]);
    }

}
