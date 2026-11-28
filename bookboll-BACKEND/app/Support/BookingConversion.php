<?php

namespace App\Support;

use App\Models\StripeSession;
use Illuminate\Support\Collection;

/** Advertising conversions describe the Stripe payment, never the booking's GMV. */
class BookingConversion
{
    /** A confirmed booking is not necessarily a payment (cash/free/covered deposit). */
    public static function schedule(Collection $bookings, int $customerId): ?array
    {
        if ($bookings->isEmpty()) return null;
        $first = $bookings->first();
        $surface = $first->financial_context['channel'] ?? '';
        $businessId = (int)($first->financial_context['business_id'] ?? 0);
        if (!in_array($surface, ['marketplace', 'site'], true)
            || !$bookings->every(fn($b) => $b->id && $b->listing_id && (int)$b->user_id === $customerId
                && in_array($b->status, ['confirmed', 'completed'], true)
                && ($b->financial_context['channel'] ?? '') === $surface
                && (int)($b->financial_context['business_id'] ?? 0) === $businessId)) return null;
        $contents = $bookings->groupBy('listing_id')->map(fn($group, $id) => ['id' => (string)$id, 'quantity' => $group->count()])->values()->all();
        return ['event_id' => 'bb_schedule_'.hash('sha256', $bookings->pluck('id')->sort()->implode(',')),
            'surface' => $surface, 'business_id' => $businessId, 'content_ids' => array_column($contents, 'id'),
            'contents' => $contents, 'num_items' => $bookings->count()];
    }

    public static function checkout(string $id, array $metadata, int $minor, string $currency): ?array
    {
        $currency = strtoupper($currency);
        if ($minor <= 0 || !preg_match('/^[A-Z]{3}$/', $currency)
            || ($metadata['conversion_version'] ?? '') !== '1'
            || ($metadata['source'] ?? '') !== 'customer'
            || !in_array($metadata['conversion_surface'] ?? '', ['marketplace', 'site'], true)
            || !ctype_digit((string)($metadata['listing_id'] ?? '')) || (int)$metadata['listing_id'] < 1
            || !ctype_digit((string)($metadata['quantity'] ?? '')) || (int)$metadata['quantity'] < 1) return null;

        return [
            'event_id' => 'bb_checkout_'.hash('sha256', $id),
            // Stripe represents UGX in hundredths, despite its zero-decimal display.
            'value' => $minor / ($currency === 'UGX' ? 100 : Money::factor($currency)),
            'currency' => $currency,
            'surface' => $metadata['conversion_surface'],
            'business_id' => (int)($metadata['conversion_business_id'] ?? 0),
            'content_ids' => [(string)$metadata['listing_id']],
            'contents' => [['id' => (string)$metadata['listing_id'], 'quantity' => (int)$metadata['quantity']]],
            'num_items' => (int)$metadata['quantity'],
        ];
    }

    public static function purchase(array $state, Collection $bookings, int $customerId): ?array
    {
        $session = $state['session'] ?? null;
        $meta = $session?->metadata?->toArray() ?? [];
        $ids = array_unique(array_map('intval', $state['booking_ids'] ?? []));
        // Old returns, administrative pay links, unpaid sessions and partial fulfilment
        // cannot manufacture conversions by opening a thank-you URL.
        if (($state['status'] ?? '') !== 'completed' || ($session->payment_status ?? '') !== 'paid'
            || ($session->status ?? '') !== 'complete' || !is_string($session->payment_intent ?? null)
            || !str_starts_with($session->payment_intent, 'pi_')
            || !$ids || $bookings->count() !== count($ids)
            || (int)($meta['user_id'] ?? 0) !== $customerId
            || !$bookings->every(fn($b) => (int)$b->user_id === $customerId
                && in_array($b->financial_context['channel'] ?? '', ['marketplace', 'site'], true)
                && !$b->refunded_at && (float)$b->refunded_amount <= 0
                && in_array($b->payment_status, ['deposit_paid', 'fully_paid'], true))
            || !StripeSession::where('session_id', $session->id)->where('processed', true)->exists()) return null;

        $payload = self::checkout($session->id, $meta, (int)($session->amount_total ?? 0), (string)($session->currency ?? ''));
        if (!$payload) return null;
        $payload['event_id'] = 'bb_purchase_'.hash('sha256', $session->payment_intent);
        return $payload;
    }
}
