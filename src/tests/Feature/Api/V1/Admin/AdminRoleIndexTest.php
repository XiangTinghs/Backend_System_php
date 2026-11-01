<?php

namespace Tests\Feature\Api\V1\Admin;

use App\Models\Admin;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AdminRoleIndexTest extends TestCase
{
    use DatabaseTransactions;

    private function authenticate(?string $permission = 'admins.roles.read', bool $active = true): Admin
    {
        $admin = Admin::factory()->create(['is_active' => $active]);
        if ($permission !== null) {
            $role = 'reader_' . $admin->id;
            DB::table('admin_roles')->insert(['admin_id' => $admin->id, 'role' => $role]);
            DB::table('roles_permissions')->insert(['role' => $role, 'permission' => $permission]);
        }
        $this->withToken($admin->createToken('roles-test')->plainTextToken);

        return $admin;
    }

    private function endpoint(int|string $id): string
    {
        return '/api/v1/admin/admins/' . rawurlencode((string) $id) . '/roles';
    }

    public function test_other_admin_roles_are_sorted_and_sensitive_fields_are_excluded(): void
    {
        $this->authenticate();
        $target = Admin::factory()->create();
        $target->createToken('private');
        DB::table('admin_roles')->insert([
            ['admin_id' => $target->id, 'role' => 'viewer'],
            ['admin_id' => $target->id, 'role' => 'manager'],
        ]);

        $this->getJson($this->endpoint($target->id))->assertOk()->assertExactJson([
            'data' => ['admin_id' => $target->id, 'roles' => ['manager', 'viewer']],
        ]);
    }

    public function test_admin_without_roles_returns_empty_array(): void
    {
        $this->authenticate();
        $target = Admin::factory()->create(['is_active' => false]);

        $this->getJson($this->endpoint($target->id))->assertOk()->assertExactJson([
            'data' => ['admin_id' => $target->id, 'roles' => []],
        ]);
    }

    public function test_own_roles_require_and_accept_read_permission(): void
    {
        $actor = $this->authenticate();

        $this->getJson($this->endpoint($actor->id))->assertOk()->assertExactJson([
            'data' => ['admin_id' => $actor->id, 'roles' => ['reader_' . $actor->id]],
        ]);
    }

    public function test_own_roles_have_no_permission_exemption(): void
    {
        $actor = $this->authenticate(null);

        $this->getJson($this->endpoint($actor->id))->assertForbidden()
            ->assertExactJson(['message' => 'Forbidden.']);
    }

    public function test_general_admin_read_permission_does_not_grant_role_read(): void
    {
        $this->authenticate('admins.read');
        $target = Admin::factory()->create();

        $this->getJson($this->endpoint($target->id))->assertForbidden()
            ->assertExactJson(['message' => 'Forbidden.']);
    }

    public function test_disabled_reader_is_rejected_and_token_is_preserved(): void
    {
        $actor = $this->authenticate('admins.roles.read', false);
        $this->getJson($this->endpoint($actor->id))->assertForbidden()
            ->assertExactJson(['message' => 'Administrator account is disabled.']);
        $this->assertSame(1, $actor->tokens()->count());
    }

    #[DataProvider('invalidIds')]
    public function test_invalid_or_nonexistent_id_returns_safe_404(string $id): void
    {
        $this->authenticate();
        $this->getJson($this->endpoint($id))->assertNotFound()
            ->assertExactJson(['message' => 'Administrator not found.']);
    }

    public static function invalidIds(): array
    {
        return [['0'], ['-1'], ['abc'], ['1.5'], ['01'], ['+1'],
            ['18446744073709551616'], ['18446744073709551615'], [str_repeat('9', 100)]];
    }

    public function test_missing_token_is_unauthorized(): void
    {
        $this->getJson($this->endpoint(1))->assertUnauthorized()
            ->assertExactJson(['message' => 'Unauthenticated.']);
    }

    public function test_invalid_and_revoked_tokens_are_rejected(): void
    {
        $actor = $this->authenticate();
        $this->withToken('invalid')->getJson($this->endpoint($actor->id))->assertUnauthorized()
            ->assertExactJson(['message' => 'Unauthenticated.']);
        $token = $actor->createToken('revoked');
        $token->accessToken->delete();
        app('auth')->forgetGuards();
        $this->withToken($token->plainTextToken)->getJson($this->endpoint($actor->id))->assertUnauthorized()
            ->assertExactJson(['message' => 'Unauthenticated.']);
    }

    public function test_session_does_not_replace_token_authentication(): void
    {
        $actor = $this->authenticate();
        app('auth')->forgetGuards();
        config(['sanctum.guard' => ['admin']]);
        $this->actingAs($actor, 'admin');
        $this->withToken('invalid')->getJson($this->endpoint($actor->id))->assertUnauthorized()
            ->assertExactJson(['message' => 'Unauthenticated.']);
    }
}
