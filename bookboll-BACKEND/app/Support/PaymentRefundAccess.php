<?php
namespace App\Support;

use App\Models\User;

/** Refund authority cannot be delegated through provider custom permissions. */
final class PaymentRefundAccess
{
    public static function allows(?User $actor): bool
    {
        return $actor && $actor->hasAnyRole(['super_admin', 'admin'])
            && !AccessScope::isProviderScoped($actor)
            && !$actor->owner_user_id && !AccessScope::businessId($actor);
    }
}
