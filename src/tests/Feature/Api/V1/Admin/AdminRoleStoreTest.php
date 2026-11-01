<?php

namespace Tests\Feature\Api\V1\Admin;

use App\Models\Admin;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AdminRoleStoreTest extends TestCase
{
    use DatabaseTransactions;

    private function authenticate(array $permissions = ['admins.roles.assign', 'admins.read'], bool $active = true): Admin
    {
        $admin = Admin::factory()->create(['is_active' => $active]);
        $role = 'operator_' . $admin->id;
        DB::table('admin_roles')->insert(['admin_id' => $admin->id, 'role' => $role]);
        foreach ($permissions as $permission) {
            DB::table('roles_permissions')->insert(['role' => $role, 'permission' => $permission]);
        }
        $this->withToken($admin->createToken('assign-test')->plainTextToken);

        return $admin;
    }

    private function configureRole(string $role = 'viewer', string $permission = 'admins.read'): void
    {
        DB::table('roles_permissions')->insert(['role' => $role, 'permission' => $permission]);
    }

    private function endpoint(int|string $id): string
    {
        return '/api/v1/admin/admins/' . rawurlencode((string) $id) . '/roles';
    }

    public function test_assignment_and_duplicate_preserve_unique_row_and_permission_definitions(): void
    {
        $this->authenticate();
        $target = Admin::factory()->create();
        $this->configureRole();
        $before = DB::table('roles_permissions')->orderBy('role')->orderBy('permission')->get()->toArray();
        $this->postJson($this->endpoint($target->id), ['role' => 'viewer'])->assertCreated()
            ->assertExactJson(['data' => ['admin_id' => $target->id, 'role' => 'viewer']]);
        app('auth')->forgetGuards();
        $this->postJson($this->endpoint($target->id), ['role' => 'viewer'])->assertOk()
            ->assertExactJson(['data' => ['admin_id' => $target->id, 'role' => 'viewer']]);
        $this->assertSame(1, DB::table('admin_roles')->where('admin_id', $target->id)->count());
        $this->assertEquals($before, DB::table('roles_permissions')->orderBy('role')->orderBy('permission')->get()->toArray());
        $this->assertSame(0, $target->tokens()->count());
    }

    public function test_manager_is_assignable_only_with_safe_configuration(): void
    {
        $this->authenticate();
        $target = Admin::factory()->create();
        $this->configureRole('manager');
        $this->postJson($this->endpoint($target->id), ['role' => 'manager'])->assertCreated();
    }

    public function test_self_assignment_is_forbidden(): void
    {
        $actor = $this->authenticate();
        $this->configureRole();
        $this->postJson($this->endpoint($actor->id), ['role' => 'viewer'])->assertForbidden()
            ->assertExactJson(['message' => 'Forbidden.']);
        $this->assertDatabaseMissing('admin_roles', ['admin_id' => $actor->id, 'role' => 'viewer']);
    }

    public function test_unconfigured_role_is_rejected_without_creating_configuration(): void
    {
        $this->authenticate();
        $target = Admin::factory()->create();
        $this->postJson($this->endpoint($target->id), ['role' => 'viewer'])->assertUnprocessable()
            ->assertJsonValidationErrors('role');
        $this->assertDatabaseMissing('admin_roles', ['admin_id' => $target->id]);
        $this->assertDatabaseMissing('roles_permissions', ['role' => 'viewer']);
    }

    public function test_protected_role_is_forbidden(): void
    {
        $this->authenticate();
        $target = Admin::factory()->create();
        $this->configureRole('security_admin');
        $this->postJson($this->endpoint($target->id), ['role' => 'security_admin'])->assertForbidden()
            ->assertExactJson(['message' => 'Forbidden.']);
        $this->assertDatabaseMissing('admin_roles', ['admin_id' => $target->id]);
    }

    public function test_known_but_not_assignable_role_is_forbidden(): void
    {
        $this->authenticate();
        config(['rbac.assignable_roles' => []]);
        $target = Admin::factory()->create();
        $this->configureRole();
        $this->postJson($this->endpoint($target->id), ['role' => 'viewer'])->assertForbidden();
        $this->assertDatabaseMissing('admin_roles', ['admin_id' => $target->id]);
    }

    public function test_actor_must_hold_every_target_role_permission(): void
    {
        $this->authenticate(['admins.roles.assign']);
        $target = Admin::factory()->create();
        $this->configureRole();
        $this->postJson($this->endpoint($target->id), ['role' => 'viewer'])->assertForbidden()
            ->assertExactJson(['message' => 'Forbidden.']);
        $this->assertDatabaseMissing('admin_roles', ['admin_id' => $target->id]);
    }

    #[DataProvider('sensitivePermissions')]
    public function test_sensitive_or_unreviewed_permissions_cannot_be_delegated(string $permission): void
    {
        $this->authenticate(['admins.roles.assign', 'admins.read', $permission]);
        $target = Admin::factory()->create();
        $this->configureRole();
        $this->configureRole('viewer', $permission);
        $this->postJson($this->endpoint($target->id), ['role' => 'viewer'])->assertForbidden()
            ->assertExactJson(['message' => 'Forbidden.']);
        $this->assertDatabaseMissing('admin_roles', ['admin_id' => $target->id]);
    }

    public static function sensitivePermissions(): array
    {
        return [['admins.roles.revoke'], ['admins.roles.read'], ['admins.update'], ['admins.create'], ['admins.password.reset'], ['unknown.capability']];
    }

    #[DataProvider('invalidPayloads')]
    public function test_invalid_payload_is_rejected(array $payload, string $field): void
    {
        $this->authenticate();
        $target = Admin::factory()->create();
        $this->configureRole();
        $this->postJson($this->endpoint($target->id), $payload)->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->assertDatabaseMissing('admin_roles', ['admin_id' => $target->id]);
    }

    public static function invalidPayloads(): array
    {
        return [[[], 'role'], [['role' => 'Viewer'], 'role'], [['role' => 'nonexistent'], 'role'],
            [['role' => 'security-admin'], 'role'], [['role' => str_repeat('a', 51)], 'role'],
            [['role' => '管理員'], 'role'], [['role' => ['viewer']], 'role'],
            [['role' => 'viewer', 'permissions' => []], 'permissions'], [['role' => 'viewer', 'id' => null], 'id']];
    }

    #[DataProvider('invalidIds')]
    public function test_invalid_and_missing_targets_return_safe_404(string $id): void
    {
        $this->authenticate();
        $this->configureRole();
        $this->postJson($this->endpoint($id), ['role' => 'viewer'])->assertNotFound()
            ->assertExactJson(['message' => 'Administrator not found.']);
    }

    public static function invalidIds(): array
    {
        return [['0'], ['-1'], ['abc'], ['01'], ['18446744073709551616'], ['18446744073709551615']];
    }

    public function test_missing_invalid_and_revoked_tokens_are_unauthorized(): void
    {
        $target = Admin::factory()->create();
        $this->postJson($this->endpoint($target->id), ['role' => 'viewer'])->assertUnauthorized();
        $actor = $this->authenticate();
        app('auth')->forgetGuards();
        $this->withToken('invalid')->postJson($this->endpoint($target->id), ['role' => 'viewer'])->assertUnauthorized()
            ->assertExactJson(['message' => 'Unauthenticated.']);
        $token = $actor->createToken('revoked');
        $token->accessToken->delete();
        app('auth')->forgetGuards();
        $this->withToken($token->plainTextToken)->postJson($this->endpoint($target->id), ['role' => 'viewer'])->assertUnauthorized();
        $this->assertDatabaseMissing('admin_roles', ['admin_id' => $target->id]);
    }

    public function test_missing_assign_permission_and_disabled_actor_are_forbidden(): void
    {
        $actor = $this->authenticate(['admins.read']);
        $target = Admin::factory()->create();
        $this->configureRole();
        $this->postJson($this->endpoint($target->id), ['role' => 'viewer'])->assertForbidden()
            ->assertExactJson(['message' => 'Forbidden.']);
        $actor->update(['is_active' => false]);
        app('auth')->forgetGuards();
        $this->postJson($this->endpoint($target->id), ['role' => 'viewer'])->assertForbidden()
            ->assertExactJson(['message' => 'Administrator account is disabled.']);
        $this->assertDatabaseMissing('admin_roles', ['admin_id' => $target->id]);
    }

    public function test_assignment_takes_effect_on_next_request_with_existing_token(): void
    {
        $actor = $this->authenticate();
        $target = Admin::factory()->create();
        $targetToken = $target->createToken('existing');
        $this->configureRole();
        $this->withToken($targetToken->plainTextToken)->getJson('/api/v1/admin/admins')->assertForbidden();
        app('auth')->forgetGuards();
        $operatorToken = $actor->createToken('operator');
        $this->withToken($operatorToken->plainTextToken)->postJson($this->endpoint($target->id), ['role' => 'viewer'])->assertCreated();
        app('auth')->forgetGuards();
        $this->withToken($targetToken->plainTextToken)->getJson('/api/v1/admin/admins')->assertOk();
        $this->assertModelExists($targetToken->accessToken);
    }

    public function test_duplicate_key_race_returns_200(): void
    {
        $this->authenticate();
        $target = Admin::factory()->create();
        $this->configureRole();
        $injected = false;
        // Insert via PDO to simulate a winner between the existence check and INSERT.
        DB::connection()->beforeExecuting(function (string $sql, array $bindings, $connection) use (&$injected): void {
            if (!$injected && str_starts_with($sql, 'insert into `admin_roles`')) {
                $injected = true;
                $connection->getPdo()->prepare($sql)->execute($bindings);
            }
        });
        $this->postJson($this->endpoint($target->id), ['role' => 'viewer'])->assertOk()
            ->assertExactJson(['data' => ['admin_id' => $target->id, 'role' => 'viewer']]);
        $this->assertTrue($injected);
        $this->assertSame(1, DB::table('admin_roles')->where('admin_id', $target->id)->count());
    }

    public function test_other_database_errors_propagate_without_assignment(): void
    {
        $this->authenticate();
        $target = Admin::factory()->create();
        $this->configureRole();
        DB::connection()->beforeExecuting(function (string $sql): void {
            if (str_starts_with($sql, 'insert into `admin_roles`')) {
                throw new QueryException('mysql', $sql, [], new \PDOException('unrelated database failure'));
            }
        });
        $this->withoutExceptionHandling();
        try {
            $this->postJson($this->endpoint($target->id), ['role' => 'viewer']);
            $this->fail('Expected database error.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('unrelated database failure', $exception->getMessage());
        }
        $this->assertDatabaseMissing('admin_roles', ['admin_id' => $target->id]);
    }
    #[DataProvider('invalidIds')]
    public function test_target_404_precedes_invalid_body_for_authorized_actor(string $id): void
    {
        $this->authenticate();
        $this->postJson($this->endpoint($id), ['role' => 'Invalid Role', 'extra' => true])
            ->assertNotFound()->assertExactJson(['message' => 'Administrator not found.']);
    }

    public function test_existing_target_still_validates_body(): void
    {
        $this->authenticate();
        $target = Admin::factory()->create();
        $this->postJson($this->endpoint($target->id), ['role' => 'Invalid Role'])
            ->assertUnprocessable()->assertJsonValidationErrors('role');
        $this->assertDatabaseMissing('admin_roles', ['admin_id' => $target->id]);
    }

    public function test_unauthorized_actor_cannot_probe_target_with_invalid_body(): void
    {
        $this->postJson($this->endpoint('abc'), [])->assertUnauthorized()
            ->assertExactJson(['message' => 'Unauthenticated.']);
        $this->authenticate(['admins.read']);
        foreach (['abc', '18446744073709551615'] as $id) {
            app('auth')->forgetGuards();
            $this->postJson($this->endpoint($id), [])->assertForbidden()
                ->assertExactJson(['message' => 'Forbidden.']);
        }
    }
}
