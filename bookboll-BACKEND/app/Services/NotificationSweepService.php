<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\ClassProgram;
use App\Models\ClassSubscription;
use App\Models\NotificationLog;
use App\Models\User;
use App\Models\Venue;
use App\Support\AccessScope;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * The scheduled reminders that have no single "moment":
 *   booking_reminder            — N days before a booking
 *   review_request              — the day after a booking
 *   trial_ending                — N days before a trial converts
 *   membership_renewal_reminder — N days before a recurring charge
 *
 * The dashboard runs the sweep when a provider opens it, so this decides WHAT is due
 * and records what has been sent; the dashboard still does the sending through the
 * existing notification dispatcher. notification_log makes it idempotent — opening
 * the dashboard ten times sends each reminder once.
 *
 * The scope comes from the caller, so a provider can only ever sweep their own
 * bookings and members.
 */
class NotificationSweepService
{
    /** Everything due for this provider that has not been sent yet. */
    public function due(Request $request)
    {
        $ownerId = AccessScope::ownerUserId($request->user());
        if ($ownerId === null) {
            return sendSuccess('Nothing due', ['bookings' => [], 'subscriptions' => []]);
        }

        $data = $request->validate([
            'remind_before_days'    => 'nullable|integer|min:0|max:60',
            'trial_ending_days'     => 'nullable|integer|min:0|max:60',
            'renewal_reminder_days' => 'nullable|integer|min:0|max:60',
        ]);
        $remindDays  = (int) ($data['remind_before_days'] ?? 1);
        $trialDays   = (int) ($data['trial_ending_days'] ?? 3);
        $renewalDays = (int) ($data['renewal_reminder_days'] ?? 3);

        $today     = Carbon::today()->toDateString();
        $yesterday = Carbon::today()->subDay()->toDateString();

        return sendSuccess('Sweep', [
            'bookings'      => $this->dueBookings($ownerId, $today, $yesterday, $remindDays),
            'subscriptions' => $this->dueSubscriptions($ownerId, $today, $trialDays, $renewalDays),
        ]);
    }

    /** @return array<int, array<string, mixed>> */
    protected function dueBookings(int $ownerId, string $today, string $yesterday, int $remindDays): array
    {
        $venues = Venue::where('owner_user_id', $ownerId)->get(['id', 'name', 'currency_override'])->keyBy('id');
        if ($venues->isEmpty()) {
            return [];
        }

        $until = Carbon::parse($today)->addDays($remindDays)->toDateString();

        $bookings = Booking::query()
            ->whereIn('venue_id', $venues->keys())
            ->whereDate('date', '>=', $yesterday)
            ->whereDate('date', '<=', $until)
            ->where('status', 'confirmed')
            ->whereNotNull('user_id')
            ->with(['listing:id,name'])
            ->get(['id', 'user_id', 'venue_id', 'listing_id', 'date', 'start_time', 'end_time', 'total_price']);

        if ($bookings->isEmpty()) {
            return [];
        }

        $customers = User::whereIn('id', $bookings->pluck('user_id')->unique())
            ->get(['id', 'name', 'email', 'phone'])->keyBy('id');

        $out = [];
        foreach ($bookings as $b) {
            $customer = $customers->get($b->user_id);
            if (! $customer) {
                continue;
            }
            $date  = Carbon::parse($b->date)->toDateString();
            $venue = $venues->get($b->venue_id);

            $payload = [
                'customer' => [
                    'id'    => $customer->id,
                    'name'  => $customer->name,
                    'email' => $customer->email,
                    'phone' => $customer->phone,
                ],
                'booking' => [
                    'listing'       => $b->listing?->name,
                    'venue'         => $venue?->name,
                    'business_name' => $venue?->name,
                    'date'          => $date,
                    'start_time'    => $b->start_time,
                    'end_time'      => $b->end_time,
                    'code'          => strtoupper(substr((string) $b->id, 0, 8)),
                    'currency'      => $venue?->currency_override ?: '',
                    'amount'        => $b->total_price,
                ],
            ];

            if ($date >= $today && $date <= $until) {
                $out[] = ['event_key' => 'booking_reminder', 'subject_id' => (string) $b->id, 'cycle_key' => $date] + $payload;
            }
            if ($date === $yesterday) {
                $out[] = ['event_key' => 'review_request', 'subject_id' => (string) $b->id, 'cycle_key' => $date] + $payload;
            }
        }

        return $this->unsent($out, 'booking');
    }

    /** @return array<int, array<string, mixed>> */
    protected function dueSubscriptions(int $ownerId, string $today, int $trialDays, int $renewalDays): array
    {
        // Providers manage academy members offline; no recurring customer billing reminders.
        return [];
    }

    /**
     * Drop anything already recorded as sent. One query for the whole batch, keyed
     * exactly as the unique index is.
     *
     * @param  array<int, array<string, mixed>>  $items
     * @return array<int, array<string, mixed>>
     */
    protected function unsent(array $items, string $subjectType): array
    {
        if (! $items) {
            return [];
        }

        $sent = NotificationLog::query()
            ->where('subject_type', $subjectType)
            ->whereIn('subject_id', array_column($items, 'subject_id'))
            ->get(['event_key', 'subject_id', 'cycle_key'])
            ->map(fn ($r) => $r->event_key.'|'.$r->subject_id.'|'.$r->cycle_key)
            ->flip();

        return array_values(array_filter(
            $items,
            fn ($i) => ! $sent->has($i['event_key'].'|'.$i['subject_id'].'|'.$i['cycle_key'])
        ));
    }

    /** Record one reminder as sent. Idempotent — a repeat is a no-op, not an error. */
    public function markSent(Request $request)
    {
        $data = $request->validate([
            'event_key'    => 'required|string|max:64',
            'subject_type' => 'required|string|in:booking,subscription',
            'subject_id'   => 'required|string|max:64',
            'cycle_key'    => 'required|string|max:32',
        ]);

        NotificationLog::firstOrCreate($data);

        return sendSuccess('Recorded', ['ok' => true]);
    }

    /** @return array<string, mixed> */
    protected function mapSub(ClassSubscription $s): array
    {
        return [
            'id'              => $s->id,
            'uuid'            => $s->uuid,
            'program_id'      => $s->program_id,
            'customer_name'   => $s->customer_name,
            'customer_email'  => $s->customer_email,
            'monthly_amount'  => $s->monthly_amount,
            'trial_ends_at'   => optional($s->trial_ends_at)?->toDateString(),
            'next_billing_at' => optional($s->next_billing_at)?->toDateString(),
            'status'          => $s->status,
            'trial_status'    => $s->trial_status,
        ];
    }

    /** @return array<string, mixed> */
    protected function mapProgram(ClassProgram $p): array
    {
        return [
            'id'       => $p->id,
            'uuid'     => $p->uuid,
            'name'     => $p->name,
            'currency' => $p->currency ?: '',
        ];
    }
}
