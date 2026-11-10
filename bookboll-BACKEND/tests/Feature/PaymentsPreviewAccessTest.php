<?php
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
uses(RefreshDatabase::class);

it('rejects copied credentials and financial writes in preview mode', function () {
    config(['preview.enabled'=>true,'preview.admin_email'=>'preview-admin@bookboll.com']);
    $this->postJson('/api/login',['email'=>'copied-user@example.com','password'=>'anything'])->assertForbidden();
    $this->getJson('/api/admin/payments/dashboard?report_version=2')->assertUnauthorized();
    $copied=User::factory()->create();
    $this->actingAs($copied,'sanctum')->getJson('/api/admin/payments/dashboard?report_version=2')->assertUnauthorized();
    $preview=User::factory()->create(['email'=>'preview-admin@bookboll.com']);
    $this->actingAs($preview,'sanctum')->postJson('/api/admin/venues/1/transfers',[])->assertForbidden();
});
