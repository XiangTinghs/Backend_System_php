<?php

namespace Tests\Feature\Api\V1\Admin;

use App\Models\Admin;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AdminShowTest extends TestCase
{
    use DatabaseTransactions;

    private const URL = '/api/v1/admin/admins/';

    private function authenticate(bool $permission = true, bool $active = true): Admin
    {
        $admin = Admin::factory()->create(['is_active' => $active]);

        if ($permission) {
            $role = 'detail_reader_' . $admin->id;
            DB::table('admin_roles')->insert(['admin_id' => $admin->id, 'role' => $role]);
            DB::table('roles_permissions')->insert(['role' => $role, 'permission' => 'admins.read']);
        }

        $this->withToken($admin->createToken('detail-test')->plainTextToken);

        return $admin;
    }

    public function test_detail_returns_only_public_fields(): void
    {
        $this->authenticate();
        $target = Admin::factory()->create();
        $target->createToken('sensitive-token');

        $this->getJson(self::URL . $target->id)->assertOk()->assertExactJson([
            'data' => [
                'id' => $target->id,
                'username' => $target->username,
                'name' => $target->name,
                'is_active' => true,
                'created_at' => $target->created_at->toISOString(),
                'updated_at' => $target->updated_at->toISOString(),
            ],
        ])->assertJsonMissingPath('data.password')
            ->assertJsonMissingPath('data.token')->assertJsonMissingPath('data.tokens');
    }

    public function test_active_reader_can_view_disabled_target(): void
    {
        $this->authenticate();
        $target = Admin::factory()->create(['is_active' => false]);

        $this->getJson(self::URL . $target->id)->assertOk()
            ->assertJsonPath('data.id', $target->id)->assertJsonPath('data.is_active', false);
    }

    public function test_missing_admin_returns_safe_not_found_response(): void
    {
        $this->authenticate();
        $target = Admin::factory()->create();
        $id = $target->id;
        $target->delete();

        $this->getJson(self::URL . $id)->assertNotFound()
            ->assertExactJson(['message' => 'Administrator not found.']);
    }

    #[DataProvider('invalidIds')]
    public function test_invalid_ids_return_safe_not_found_response(string $id): void
    {
        $this->authenticate();

        $this->getJson(self::URL . rawurlencode($id))->assertNotFound()
            ->assertExactJson(['message' => 'Administrator not found.']);
    }

    public static function invalidIds(): array
    {
        return [
            ['0'], ['-1'], ['1.5'], ['abc'], ['1e2'], ['+1'], ['01'],
            ['18446744073709551616'], [str_repeat('9', 100)],
        ];
    }

    public function test_missing_token_is_unauthorized(): void
    {
        $this->getJson(self::URL . '1')->assertUnauthorized()
            ->assertExactJson(['message' => 'Unauthenticated.']);
    }

    public function test_invalid_and_revoked_tokens_are_unauthorized(): void
    {
        $admin = $this->authenticate();
        $this->withToken('invalid')->getJson(self::URL . $admin->id)->assertUnauthorized()
            ->assertExactJson(['message' => 'Unauthenticated.']);

        $token = $admin->createToken('revoked');
        $token->accessToken->delete();
        app('auth')->forgetGuards();

        $this->withToken($token->plainTextToken)->getJson(self::URL . $admin->id)
            ->assertUnauthorized()->assertExactJson(['message' => 'Unauthenticated.']);
    }

    public function test_reader_without_permission_is_forbidden(): void
    {
        $admin = $this->authenticate(false);

        $this->getJson(self::URL . $admin->id)->assertForbidden()
            ->assertExactJson(['message' => 'Forbidden.']);
    }

    public function test_disabled_reader_is_forbidden_without_token_revocation(): void
    {
        $admin = $this->authenticate(true, false);
        $before = $admin->tokens()->count();

        $this->getJson(self::URL . $admin->id)->assertForbidden()
            ->assertExactJson(['message' => 'Administrator account is disabled.']);
        $this->assertSame($before, $admin->tokens()->count());
    }

    public function test_detail_route_does_not_change_paginated_list(): void
    {
        $admin = $this->authenticate();
        $this->getJson(self::URL . $admin->id)->assertOk();
        app('auth')->forgetGuards();

        $this->getJson(rtrim(self::URL, '/') . '?sort=id&direction=desc&per_page=1')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('meta.per_page', 1)
            ->assertJsonStructure(['data', 'links', 'meta']);
    }
}
