<?php

namespace Tests\Feature\Api\V1\Admin;

use App\Models\Admin;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AdminPermissionMiddlewareTest extends TestCase
{
    use DatabaseTransactions;

    private const ENDPOINT = '/api/testing/admin-permission';

    protected function setUp(): void
    {
        parent::setUp();

        $this->registerPermissionRoute(self::ENDPOINT, 'admin.permission:admins.read');
    }

    public function test_admin_with_permission_can_access(): void
    {
        $admin = Admin::factory()->create();
        $this->grant($admin, 'viewer', 'admins.read');

        $this->withToken($admin->createToken('test')->plainTextToken)
            ->getJson(self::ENDPOINT)->assertOk()->assertExactJson(['data' => ['allowed' => true]]);
    }

    public function test_admin_without_role_is_forbidden(): void
    {
        $other = Admin::factory()->create();
        $this->grant($other, 'viewer', 'admins.read');
        $admin = Admin::factory()->create();

        $this->withToken($admin->createToken('test')->plainTextToken)
            ->getJson(self::ENDPOINT)->assertForbidden()->assertExactJson(['message' => 'Forbidden.']);
    }

    public function test_admin_without_required_permission_is_forbidden(): void
    {
        $admin = Admin::factory()->create();
        $this->grant($admin, 'editor', 'admins.update');

        $this->withToken($admin->createToken('test')->plainTextToken)
            ->getJson(self::ENDPOINT)->assertForbidden()->assertExactJson(['message' => 'Forbidden.']);
    }

    public function test_unknown_permission_is_forbidden(): void
    {
        $admin = Admin::factory()->create();
        $this->grant($admin, 'viewer', 'admins.read');
        $this->registerPermissionRoute('/api/testing/unknown', 'admin.permission:unknown.permission');

        $this->withToken($admin->createToken('test')->plainTextToken)
            ->getJson('/api/testing/unknown')->assertForbidden()->assertExactJson(['message' => 'Forbidden.']);
    }

    public function test_missing_token_is_unauthorized(): void
    {
        $this->getJson(self::ENDPOINT)->assertUnauthorized()->assertExactJson(['message' => 'Unauthenticated.']);
    }

    public function test_invalid_token_is_unauthorized(): void
    {
        $admin = Admin::factory()->create();
        $this->grant($admin, 'viewer', 'admins.read');
        $token = $admin->createToken('test');

        $this->withToken($token->accessToken->getKey() . '|invalid-secret')
            ->getJson(self::ENDPOINT)->assertUnauthorized()->assertExactJson(['message' => 'Unauthenticated.']);
        $this->assertModelExists($token->accessToken);
    }

    public function test_revoked_token_is_unauthorized(): void
    {
        $admin = Admin::factory()->create();
        $this->grant($admin, 'viewer', 'admins.read');
        $token = $admin->createToken('test');
        $this->withToken($token->plainTextToken)->getJson(self::ENDPOINT)->assertOk();

        $token->accessToken->delete();
        app('auth')->forgetGuards();

        $this->withToken($token->plainTextToken)->getJson(self::ENDPOINT)
            ->assertUnauthorized()->assertExactJson(['message' => 'Unauthenticated.']);
        $this->assertModelMissing($token->accessToken);
    }

    public function test_disabled_admin_with_permission_is_forbidden_without_revocation(): void
    {
        $admin = Admin::factory()->create();
        $this->grant($admin, 'viewer', 'admins.read');
        $token = $admin->createToken('test');
        $this->withToken($token->plainTextToken)->getJson(self::ENDPOINT)->assertOk();

        $admin->update(['is_active' => false]);
        app('auth')->forgetGuards();

        $this->withToken($token->plainTextToken)->getJson(self::ENDPOINT)
            ->assertForbidden()->assertExactJson(['message' => 'Administrator account is disabled.']);
        $this->assertModelExists($token->accessToken);
    }

    public function test_permissions_are_unioned_across_roles(): void
    {
        $admin = Admin::factory()->create();
        $this->grant($admin, 'editor', 'admins.update');
        $this->grant($admin, 'viewer', 'admins.read');
        $token = $admin->createToken('test')->plainTextToken;
        $this->registerPermissionRoute('/api/testing/update', 'admin.permission:admins.update');

        $this->withToken($token)->getJson(self::ENDPOINT)->assertOk()
            ->assertExactJson(['data' => ['allowed' => true]]);
        app('auth')->forgetGuards();
        $this->withToken($token)->getJson('/api/testing/update')->assertOk()
            ->assertExactJson(['data' => ['allowed' => true]]);
    }

    public function test_permission_changes_apply_on_the_next_request(): void
    {
        $admin = Admin::factory()->create();
        DB::table('admin_roles')->insert(['admin_id' => $admin->id, 'role' => 'viewer']);
        $token = $admin->createToken('test')->plainTextToken;
        $this->withToken($token)->getJson(self::ENDPOINT)
            ->assertForbidden()->assertExactJson(['message' => 'Forbidden.']);

        DB::table('roles_permissions')->insert(['role' => 'viewer', 'permission' => 'admins.read']);
        app('auth')->forgetGuards();
        $this->withToken($token)->getJson(self::ENDPOINT)->assertOk()
            ->assertExactJson(['data' => ['allowed' => true]]);

        DB::table('roles_permissions')->where('role', 'viewer')->where('permission', 'admins.read')->delete();
        app('auth')->forgetGuards();
        $this->withToken($token)->getJson(self::ENDPOINT)
            ->assertForbidden()->assertExactJson(['message' => 'Forbidden.']);
    }

    #[DataProvider('invalidPermissionParameters')]
    public function test_invalid_permission_parameters_are_forbidden(string $middleware): void
    {
        $admin = Admin::factory()->create();
        $this->grant($admin, 'viewer', 'admins.read');
        $this->registerPermissionRoute('/api/testing/invalid', $middleware);

        $this->withToken($admin->createToken('test')->plainTextToken)->getJson('/api/testing/invalid')
            ->assertForbidden()->assertExactJson(['message' => 'Forbidden.']);
    }

    public static function invalidPermissionParameters(): array
    {
        return [
            'missing' => ['admin.permission'],
            'empty' => ['admin.permission:'],
            'multiple' => ['admin.permission:admins.read,admins.update'],
            'uppercase' => ['admin.permission:Admins.Read'],
            'whitespace' => ['admin.permission:admins. read'],
            'wildcard' => ['admin.permission:admins.*'],
            'empty segment' => ['admin.permission:admins..read'],
            'too long' => ['admin.permission:' . str_repeat('a', 101)],
        ];
    }

    #[DataProvider('sessionBearerHeaders')]
    public function test_session_cannot_replace_bearer_authentication(?string $bearer): void
    {
        config(['sanctum.guard' => ['admin']]);
        $admin = Admin::factory()->create();
        $this->grant($admin, 'viewer', 'admins.read');
        $token = $admin->createToken('test');
        $this->actingAs($admin, 'admin');
        $this->assertAuthenticatedAs($admin, 'admin');

        if ($bearer !== null) {
            $this->withToken($bearer);
        }

        $this->getJson(self::ENDPOINT)->assertUnauthorized()->assertExactJson(['message' => 'Unauthenticated.']);
        $this->assertModelExists($token->accessToken);
    }

    public static function sessionBearerHeaders(): array
    {
        return ['no bearer' => [null], 'invalid bearer' => ['invalid-token']];
    }

    private function registerPermissionRoute(string $uri, string $permissionMiddleware): void
    {
        Route::get($uri, fn () => response()->json(['data' => ['allowed' => true]]))
            ->middleware(['auth:sanctum', 'admin.token', 'admin.active', $permissionMiddleware]);
    }

    private function grant(Admin $admin, string $role, string $permission): void
    {
        DB::table('admin_roles')->insert(['admin_id' => $admin->id, 'role' => $role]);
        DB::table('roles_permissions')->insert(['role' => $role, 'permission' => $permission]);
    }
}
