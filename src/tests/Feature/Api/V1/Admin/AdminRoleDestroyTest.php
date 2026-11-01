<?php

namespace Tests\Feature\Api\V1\Admin;

use App\Models\Admin;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AdminRoleDestroyTest extends TestCase
{
    use DatabaseTransactions;

    private function authenticate(array $permissions = ['admins.roles.revoke', 'admins.read'], bool $active = true): Admin
    {
        $actor = Admin::factory()->create(['is_active' => $active]);
        $role = 'operator_' . $actor->id;
        $this->assign($actor, $role);
        foreach ($permissions as $permission) {
            DB::table('roles_permissions')->insert(['role' => $role, 'permission' => $permission]);
        }
        $this->withToken($actor->createToken('revoke-test')->plainTextToken);

        return $actor;
    }

    private function assign(Admin $admin, string $role = 'viewer'): void
    {
        DB::table('admin_roles')->insert(['admin_id' => $admin->id, 'role' => $role]);
    }

    private function configure(string $role = 'viewer', string $permission = 'admins.read'): void
    {
        DB::table('roles_permissions')->insert(['role' => $role, 'permission' => $permission]);
    }

    private function endpoint(int|string $id, string $role = 'viewer'): string
    {
        return '/api/v1/admin/admins/' . rawurlencode((string) $id) . '/roles/' . rawurlencode($role);
    }

    public function test_revoke_only_removes_target_assignment_and_preserves_definitions_and_tokens(): void
    {
        $this->authenticate();
        $target = Admin::factory()->create();
        $other = Admin::factory()->create();
        $this->assign($target);
        $this->assign($target, 'manager');
        $this->assign($other);
        $this->configure();
        $this->configure('manager');
        $token = $target->createToken('device');
        $before = DB::table('roles_permissions')->orderBy('role')->orderBy('permission')->get()->toArray();

        $this->deleteJson($this->endpoint($target->id))->assertNoContent()->assertContent('');
        $this->assertDatabaseMissing('admin_roles', ['admin_id' => $target->id, 'role' => 'viewer']);
        $this->assertDatabaseHas('admin_roles', ['admin_id' => $target->id, 'role' => 'manager']);
        $this->assertDatabaseHas('admin_roles', ['admin_id' => $other->id, 'role' => 'viewer']);
        $this->assertEquals($before, DB::table('roles_permissions')->orderBy('role')->orderBy('permission')->get()->toArray());
        $this->assertModelExists($token->accessToken);
    }

    public function test_manager_can_be_revoked_when_explicitly_allowed(): void
    {
        $this->authenticate();
        $target = Admin::factory()->create();
        $this->assign($target, 'manager');
        $this->configure('manager');
        $this->deleteJson($this->endpoint($target->id, 'manager'))->assertNoContent();
    }

    public function test_self_revocation_is_forbidden(): void
    {
        $actor = $this->authenticate();
        $this->assign($actor);
        $this->configure();
        $this->deleteJson($this->endpoint($actor->id))->assertForbidden()->assertExactJson(['message' => 'Forbidden.']);
        $this->assertDatabaseHas('admin_roles', ['admin_id' => $actor->id, 'role' => 'viewer']);
    }

    public function test_protected_role_cannot_be_revoked_even_if_added_to_revocable_list(): void
    {
        $this->authenticate();
        config(['rbac.revocable_roles' => ['security_admin']]);
        $target = Admin::factory()->create();
        $this->assign($target, 'security_admin');
        $this->configure('security_admin');
        $this->deleteJson($this->endpoint($target->id, 'security_admin'))->assertForbidden()
            ->assertExactJson(['message' => 'Forbidden.']);
        $this->assertDatabaseHas('admin_roles', ['admin_id' => $target->id, 'role' => 'security_admin']);
    }

    public function test_role_not_explicitly_revocable_is_forbidden(): void
    {
        $this->authenticate();
        config(['rbac.revocable_roles' => []]);
        $target = Admin::factory()->create();
        $this->assign($target);
        $this->configure();
        $this->deleteJson($this->endpoint($target->id))->assertForbidden();
        $this->assertDatabaseHas('admin_roles', ['admin_id' => $target->id, 'role' => 'viewer']);
    }

    public function test_role_with_no_permission_configuration_is_rejected(): void
    {
        $this->authenticate();
        $target = Admin::factory()->create();
        $this->assign($target);
        $this->deleteJson($this->endpoint($target->id))->assertForbidden()->assertExactJson(['message' => 'Forbidden.']);
        $this->assertDatabaseHas('admin_roles', ['admin_id' => $target->id, 'role' => 'viewer']);
    }

    public function test_actor_must_have_all_role_permissions(): void
    {
        $this->authenticate(['admins.roles.revoke']);
        $target = Admin::factory()->create();
        $this->assign($target);
        $this->configure();
        $this->deleteJson($this->endpoint($target->id))->assertForbidden()->assertExactJson(['message' => 'Forbidden.']);
        $this->assertDatabaseHas('admin_roles', ['admin_id' => $target->id, 'role' => 'viewer']);
    }

    #[DataProvider('unsafePermissions')]
    public function test_sensitive_permissions_cannot_be_revoked(string $permission): void
    {
        $this->authenticate(['admins.roles.revoke', 'admins.read', $permission]);
        $target = Admin::factory()->create();
        $this->assign($target);
        $this->configure();
        $this->configure('viewer', $permission);
        $this->deleteJson($this->endpoint($target->id))->assertForbidden()->assertExactJson(['message' => 'Forbidden.']);
        $this->assertDatabaseHas('admin_roles', ['admin_id' => $target->id, 'role' => 'viewer']);
    }

    public static function unsafePermissions(): array
    {
        return [['admins.roles.assign'], ['admins.roles.read'], ['admins.update'], ['admins.password.reset'], ['unknown.capability']];
    }

    #[DataProvider('invalidRoles')]
    public function test_unknown_or_invalid_role_returns_404(string $role): void
    {
        $this->authenticate();
        $target = Admin::factory()->create();
        $this->assign($target);
        $this->deleteJson($this->endpoint($target->id, $role))->assertNotFound()->assertExactJson(['message' => 'Role not found.']);
        $this->assertDatabaseHas('admin_roles', ['admin_id' => $target->id, 'role' => 'viewer']);
    }

    public static function invalidRoles(): array
    {
        return [['unknown'], ['Viewer'], ['viewer.role'], ['viewer role'], ['管理員'], [str_repeat('a', 51)]];
    }

    #[DataProvider('invalidIds')]
    public function test_unknown_or_invalid_admin_returns_safe_404(string $id): void
    {
        $this->authenticate();
        $this->deleteJson($this->endpoint($id))->assertNotFound()
            ->assertExactJson(['message' => 'Administrator not found.']);
    }

    public static function invalidIds(): array
    {
        return [['0'], ['-1'], ['abc'], ['01'], ['18446744073709551616'], ['18446744073709551615']];
    }

    public function test_unassigned_role_returns_404(): void
    {
        $this->authenticate();
        $target = Admin::factory()->create();
        $this->configure();
        $this->deleteJson($this->endpoint($target->id))->assertNotFound()
            ->assertExactJson(['message' => 'Role assignment not found.']);
    }

    public function test_request_body_is_rejected_without_changes(): void
    {
        $this->authenticate();
        $target = Admin::factory()->create();
        $this->assign($target);
        $this->configure();
        $this->deleteJson($this->endpoint($target->id), ['role' => 'manager'])->assertUnprocessable()->assertJsonValidationErrors('body');
        $this->assertDatabaseHas('admin_roles', ['admin_id' => $target->id, 'role' => 'viewer']);
    }

    public function test_missing_invalid_and_revoked_tokens_are_unauthorized(): void
    {
        $target = Admin::factory()->create();
        $this->assign($target);
        $this->deleteJson($this->endpoint($target->id))->assertUnauthorized()->assertExactJson(['message' => 'Unauthenticated.']);
        $actor = $this->authenticate();
        app('auth')->forgetGuards();
        $this->withToken('invalid')->deleteJson($this->endpoint($target->id))->assertUnauthorized();
        $token = $actor->createToken('revoked');
        $token->accessToken->delete();
        app('auth')->forgetGuards();
        $this->withToken($token->plainTextToken)->deleteJson($this->endpoint($target->id))->assertUnauthorized();
        $this->assertDatabaseHas('admin_roles', ['admin_id' => $target->id, 'role' => 'viewer']);
    }

    public function test_missing_permission_and_inactive_actor_cannot_revoke(): void
    {
        $actor = $this->authenticate(['admins.read']);
        $target = Admin::factory()->create();
        $this->assign($target);
        $this->configure();
        $this->deleteJson($this->endpoint($target->id))->assertForbidden()->assertExactJson(['message' => 'Forbidden.']);
        $actor->update(['is_active' => false]);
        app('auth')->forgetGuards();
        $this->deleteJson($this->endpoint($target->id))->assertForbidden()
            ->assertExactJson(['message' => 'Administrator account is disabled.']);
        $this->assertDatabaseHas('admin_roles', ['admin_id' => $target->id, 'role' => 'viewer']);
    }

    public function test_next_request_loses_permission_without_revoking_token(): void
    {
        $actor = $this->authenticate();
        $operatorToken = $actor->createToken('operator');
        $target = Admin::factory()->create();
        $this->assign($target);
        $this->configure();
        $targetToken = $target->createToken('existing');
        $this->withToken($targetToken->plainTextToken)->getJson('/api/v1/admin/admins')->assertOk();
        app('auth')->forgetGuards();
        $this->withToken($operatorToken->plainTextToken)->deleteJson($this->endpoint($target->id))->assertNoContent();
        app('auth')->forgetGuards();
        $this->withToken($targetToken->plainTextToken)->getJson('/api/v1/admin/admins')->assertForbidden()
            ->assertExactJson(['message' => 'Forbidden.']);
        $this->assertModelExists($targetToken->accessToken);
        app('auth')->forgetGuards();
        $this->withToken($targetToken->plainTextToken)->getJson('/api/v1/admin/me')->assertOk();
    }

    public function test_database_errors_are_not_hidden_and_assignment_is_preserved(): void
    {
        $this->authenticate();
        $target = Admin::factory()->create();
        $this->assign($target);
        $this->configure();
        DB::connection()->beforeExecuting(function (string $sql): void {
            if (str_starts_with($sql, 'delete from `admin_roles`')) {
                throw new QueryException('mysql', $sql, [], new \PDOException('delete failed'));
            }
        });
        $this->withoutExceptionHandling();
        try {
            $this->deleteJson($this->endpoint($target->id));
            $this->fail('Expected database error.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('delete failed', $exception->getMessage());
        }
        $this->assertDatabaseHas('admin_roles', ['admin_id' => $target->id, 'role' => 'viewer']);
    }
    #[DataProvider('invalidIds')]
    public function test_target_404_precedes_invalid_role_and_body(string $id): void
    {
        $this->authenticate();
        $this->deleteJson($this->endpoint($id, 'InvalidRole'), ['extra' => true])
            ->assertNotFound()->assertExactJson(['message' => 'Administrator not found.']);
    }

    public function test_existing_target_preserves_role_404_and_body_422(): void
    {
        $this->authenticate();
        $target = Admin::factory()->create();
        $this->assign($target);
        $this->configure();
        $this->deleteJson($this->endpoint($target->id, 'InvalidRole'))->assertNotFound()
            ->assertExactJson(['message' => 'Role not found.']);
        app('auth')->forgetGuards();
        $this->deleteJson($this->endpoint($target->id), ['extra' => true])->assertUnprocessable()
            ->assertJsonValidationErrors('body');
        $this->assertDatabaseHas('admin_roles', ['admin_id' => $target->id, 'role' => 'viewer']);
    }

    public function test_unauthorized_actor_cannot_probe_target_with_invalid_role(): void
    {
        $this->deleteJson($this->endpoint('abc', 'InvalidRole'))->assertUnauthorized()
            ->assertExactJson(['message' => 'Unauthenticated.']);
        $this->authenticate(['admins.read']);
        foreach (['abc', '18446744073709551615'] as $id) {
            app('auth')->forgetGuards();
            $this->deleteJson($this->endpoint($id, 'InvalidRole'), ['extra' => true])->assertForbidden()
                ->assertExactJson(['message' => 'Forbidden.']);
        }
    }
}
