<?php

namespace Tests\Feature\Api\V1\Admin;

use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_admin_can_get_profile(): void
    {
        $admin = Admin::factory()->create([
            'username' => 'admin001',
            'name' => 'Test Admin',
        ]);

        Sanctum::actingAs($admin, ['*']);

        $response = $this->getJson('/api/v1/admin/me');

        $response->assertOk()
            ->assertJsonPath('data.username', 'admin001')
            ->assertJsonPath('data.name', 'Test Admin')
            ->assertJsonMissingPath('data.password');
    }

    public function test_unauthenticated_user_cannot_get_profile(): void
    {
        $response = $this->getJson('/api/v1/admin/me');

        $response->assertUnauthorized();
    }

    public function test_inactive_admin_cannot_get_profile(): void
    {
        $admin = Admin::factory()->create([
            'is_active' => false,
        ]);

        Sanctum::actingAs($admin, ['*']);

        $response = $this->getJson('/api/v1/admin/me');

        $response->assertForbidden();
    }
}
