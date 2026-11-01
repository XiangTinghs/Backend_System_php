<?php

namespace Tests\Feature\Api\V1\Admin;

use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminLoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_login_with_valid_credentials(): void
    {
        Admin::factory()->create([
            'username' => 'admin001',
            'password' => 'password123',
            'is_active' => true,
        ]);

        $response = $this->postJson('/api/v1/admin/login', [
            'username' => 'admin001',
            'password' => 'password123',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.admin.username', 'admin001')
            ->assertJsonStructure([
                'data' => [
                    'token',
                    'admin' => [
                        'id',
                        'username',
                        'name',
                    ],
                ],
            ]);
    }

    public function test_admin_cannot_login_with_wrong_password(): void
    {
        Admin::factory()->create([
            'username' => 'admin001',
            'password' => 'password123',
        ]);

        $response = $this->postJson('/api/v1/admin/login', [
            'username' => 'admin001',
            'password' => 'wrongpassword',
        ]);

        $response->assertUnauthorized();
    }

    public function test_admin_cannot_login_with_unknown_username(): void
    {
        $response = $this->postJson('/api/v1/admin/login', [
            'username' => 'not_exist',
            'password' => 'password123',
        ]);

        $response->assertUnauthorized();
    }

    public function test_inactive_admin_cannot_login(): void
    {
        Admin::factory()->create([
            'username' => 'admin001',
            'password' => 'password123',
            'is_active' => false,
        ]);

        $response = $this->postJson('/api/v1/admin/login', [
            'username' => 'admin001',
            'password' => 'password123',
        ]);

        $response->assertUnauthorized();
    }

    public function test_login_requires_username_and_password(): void
    {
        $response = $this->postJson('/api/v1/admin/login', []);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors([
                'username',
                'password',
            ]);
    }
    public function test_repeated_login_creates_independent_usable_tokens(): void
    {
        $admin = Admin::factory()->create(['password' => 'password123']);
        $credentials = ['username' => $admin->username, 'password' => 'password123'];

        $first = $this->postJson('/api/v1/admin/login', $credentials)->assertOk();
        app('auth')->forgetGuards();
        $second = $this->postJson('/api/v1/admin/login', $credentials)->assertOk();

        $tokenA = $first->json('data.token');
        $tokenB = $second->json('data.token');
        $this->assertIsString($tokenA);
        $this->assertIsString($tokenB);
        $this->assertNotSame($tokenA, $tokenB);
        $this->assertDatabaseCount('personal_access_tokens', 2);

        foreach ([$tokenA, $tokenB] as $token) {
            app('auth')->forgetGuards();
            $this->withToken($token)->getJson('/api/v1/admin/me')
                ->assertOk()
                ->assertJsonPath('data.id', $admin->id)
                ->assertJsonMissingPath('data.password');
        }
    }
}
