<?php

namespace Tests\Feature\Api\V1\Admin;

use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

        $token = $admin->createToken('profile')->plainTextToken;

        $response = $this->withToken($token)->getJson('/api/v1/admin/me');

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
        $admin = Admin::factory()->create();
        $token = $admin->createToken('profile');

        $this->withToken($token->plainTextToken)->getJson('/api/v1/admin/me')->assertOk();

        $admin->update(['is_active' => false]);
        app('auth')->forgetGuards();

        $this->withToken($token->plainTextToken)->getJson('/api/v1/admin/me')
            ->assertForbidden()
            ->assertExactJson(['message' => 'Administrator account is disabled.']);

        $this->assertModelExists($token->accessToken);
        $this->assertDatabaseCount('personal_access_tokens', 1);
    }

    public function test_invalid_token_cannot_get_profile(): void
    {
        $token = Admin::factory()->create()->createToken('profile');

        $this->withToken($token->accessToken->getKey() . '|invalid-secret')
            ->getJson('/api/v1/admin/me')
            ->assertUnauthorized()
            ->assertExactJson(['message' => 'Unauthenticated.']);

        $this->assertModelExists($token->accessToken);
    }

    public function test_admin_session_cannot_replace_bearer_authentication(): void
    {
        // Include admin in Sanctum's session sources to exercise its TransientToken branch.
        config(['sanctum.guard' => ['admin']]);
        $admin = Admin::factory()->create();
        $this->actingAs($admin, 'admin');
        $this->assertAuthenticatedAs($admin, 'admin');

        $this->getJson('/api/v1/admin/me')
            ->assertUnauthorized()
            ->assertExactJson(['message' => 'Unauthenticated.']);
    }

    public function test_admin_session_with_invalid_bearer_token_is_rejected(): void
    {
        config(['sanctum.guard' => ['admin']]);
        $admin = Admin::factory()->create();
        $token = $admin->createToken('profile');
        $this->actingAs($admin, 'admin');
        $this->assertAuthenticatedAs($admin, 'admin');

        $this->withToken($token->accessToken->getKey() . '|invalid-secret')
            ->getJson('/api/v1/admin/me')
            ->assertUnauthorized()
            ->assertExactJson(['message' => 'Unauthenticated.']);

        $this->assertModelExists($token->accessToken);
    }
}
