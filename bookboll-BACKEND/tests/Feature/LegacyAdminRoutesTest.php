<?php

/**
 * Two admin routes answered 500 to everyone: `users/required-data` was
 * registered after the users resource, so `users/{user}` swallowed it and
 * dispatched to a show() that does not exist; and the `role:` middleware, on a
 * refusal without an Accept header, called logout() on the Sanctum guard, which
 * has none.
 */

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    foreach (['super_admin', 'facility_admin'] as $r) {
        Role::findOrCreate($r, 'web');
    }
});

function larUser(string $role): User
{
    $u = User::factory()->create(['status' => User::ACTIVE]);
    $u->assignRole($role);

    return $u->fresh();
}

it('serves users/required-data instead of routing it to a missing show action', function () {
    $this->actingAs(larUser('super_admin'))->getJson('/api/admin/users/required-data')->assertOk();
});

it('refuses a super-admin-only route to a token caller with a 403, never a 500', function () {
    // No Accept header: the branch that used to try a session logout.
    $this->actingAs(larUser('facility_admin'))->get('/api/admin/roles')
        ->assertStatus(403)
        ->assertJson(['message' => 'Forbidden. You do not have the required role.']);
});

it('still serves the super-admin-only routes to a super admin', function () {
    $admin = larUser('super_admin');
    $this->actingAs($admin)->getJson('/api/admin/roles')->assertOk();
    $this->actingAs($admin)->getJson('/api/admin/location-management/countries')->assertOk();
});
