<?php

namespace App\Livewire\Admin\Concerns;

use Illuminate\Support\Facades\Auth;

/**
 * Child components are not reached through the admin route group: after the
 * first page load every interaction goes through Livewire's own update
 * endpoint, so the route's `checkRoles:super_admin` never runs again. A
 * component that sends mail or pushes on request re-checks for itself.
 */
trait RequiresSuperAdmin
{
    public function bootRequiresSuperAdmin(): void
    {
        $user = Auth::user();

        abort_unless($user && $user->hasRole('super_admin'), 403);
    }
}
