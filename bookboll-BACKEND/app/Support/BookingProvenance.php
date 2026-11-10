<?php
namespace App\Support;

use App\Models\Booking;
use App\Models\User;

/** Booking author is distinct from its customer and from whoever later collects payment. */
final class BookingProvenance
{
    public static function legacy(Booking $booking): array
    {
        $creator = $booking->creator;
        $history = $booking->creationHistory;
        $description = (string) ($history?->description ?? '');
        $different = $booking->created_by && (string) $booking->created_by !== (string) $booking->user_id;
        $staff = $creator && $creator->roles->contains(fn ($r) => $r->name !== 'customer');
        $namedAuthor = $creator && str_starts_with($description, 'Booking created by '.$creator->name.' for ');
        $imported = str_contains(strtolower($description), 'imported from excel');
        $explicitAdmin = str_contains(strtolower($description), 'created in admin dashboard');
        $channel = self::originChannel($booking);
        $basis = 'recorded_origin';
        if ($channel === 'auto') {
            $creator = null; $basis = 'membership_schedule';
        } elseif ($booking->origin !== 'customer_game' &&
            ($channel === 'admin' || $explicitAdmin || ($different && ($staff || $namedAuthor || $imported)))) {
            $channel = 'admin';
            $basis = $explicitAdmin ? 'creation_log_admin' : ($different ? 'recorded_staff_creator' : 'explicit_manual_origin');
        } elseif ($different) {
            // A different actor without supporting evidence is not a customer checkout.
            $channel = 'unknown'; $basis = 'creator_requires_review';
        } elseif (!$creator) {
            // Legacy history.user_id was often the CUSTOMER, so use it only when
            // the creation description explicitly identifies a customer booking.
            if ($history && str_contains(strtolower($description), 'created by customer')
                && (string) $history->user_id === (string) $booking->user_id) {
                $creator = $booking->user; $basis = 'explicit_customer_creation_log';
            } else {
                $channel = 'unknown'; $basis = 'creator_not_recorded';
            }
        }
        return self::context($booking, $channel, $creator, $basis) + [
            'history_id'=>$history?->id, 'history_description'=>$description ?: null,
            'legacy_origin'=>$booking->origin, 'legacy_created_by'=>$booking->created_by,
        ];
    }

    public static function forNew(Booking $booking): array
    {
        $channel = self::originChannel($booking);
        $actor = auth()->user();
        $adminRequest = request()->is('api/admin/*') && request()->isMethod('post') && !request()->is('*import*');
        // Route is server-owned. A staff member using customer checkout for
        // themselves still makes a customer booking, regardless of their role.
        if ($adminRequest && !in_array($booking->origin, ['class_program','customer_game'], true)) $channel = 'admin';
        if (!$actor || ($booking->created_by && (string) $booking->created_by !== (string) $actor->id)) {
            $actor = $booking->created_by ? User::find($booking->created_by) : null;
        }
        return self::context($booking, $channel, $channel === 'auto' ? null : $actor,
            $adminRequest ? 'authenticated_admin_endpoint' : 'recorded_creation_endpoint');
    }

    private static function context(Booking $b, string $channel, ?User $actor, string $basis): array
    {
        return ['version'=>1, 'channel'=>$channel, 'actor_id'=>$actor?->id, 'actor_name'=>$actor?->name,
            'actor_kind'=>match($channel) { 'admin'=>'staff', 'auto'=>'system', 'unknown'=>'unknown', default=>'customer' },
            'basis'=>$basis, 'recorded_at'=>now()->toIso8601String()];
    }

    public static function originChannel(Booking $b): string
    {
        return match ($b->origin) {
            'manual_admin','manual'=>'admin', 'class_program'=>'auto',
            'provider_site','whitelabel','white_label'=>'site', 'customer_game','bookboll_app'=>'marketplace',
            'bookboll_web'=> $b->commission_channel === 'site' ? 'site' : 'marketplace',
            default=>in_array($b->commission_channel, ['site','marketplace'], true) ? $b->commission_channel : 'unknown',
        };
    }
}
