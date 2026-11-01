<?php

namespace Tests\Feature\Api\V1\Admin;

use App\Models\Admin;
use App\Models\AuditLog;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AdminRoleAuditLogTest extends TestCase
{
    use DatabaseTransactions;

    private function authenticate(array $permissions = ['admins.roles.assign', 'admins.roles.revoke', 'admins.read']): Admin
    {
        $actor = Admin::factory()->create();
        $role = 'audit_operator_' . $actor->id;
        DB::table('admin_roles')->insert(['admin_id' => $actor->id, 'role' => $role]);
        foreach ($permissions as $permission) {
            DB::table('roles_permissions')->insert(['role' => $role, 'permission' => $permission]);
        }
        $this->withToken($actor->createToken('role-audit')->plainTextToken);

        return $actor;
    }

    private function endpoint(int|string $id, ?string $role = null): string
    {
        return '/api/v1/admin/admins/' . $id . '/roles' . ($role === null ? '' : '/' . $role);
    }

    private function assign(Admin $target, string $role): void
    {
        DB::table('admin_roles')->insert(['admin_id' => $target->id, 'role' => $role]);
    }

    private function configure(string $role, string $permission = 'admins.read'): void
    {
        DB::table('roles_permissions')->insert(['role' => $role, 'permission' => $permission]);
    }

    public function test_assignment_records_sorted_database_snapshots_and_actor(): void
    {
        $actor = $this->authenticate();
        $target = Admin::factory()->create();
        $this->assign($target, 'viewer');
        $this->assign($target, 'alpha');
        $this->configure('manager');
        $targetToken = $target->createToken('private');
        $this->postJson($this->endpoint($target->id), ['role' => 'manager'])->assertCreated()
            ->assertExactJson(['data' => ['admin_id' => $target->id, 'role' => 'manager']]);
        $log = AuditLog::sole();
        $this->assertSame('admin.role_assigned', $log->action);
        $this->assertSame($actor->id, $log->admin_id);
        $this->assertSame('admin', $log->target_type);
        $this->assertSame($target->id, $log->target_id);
        $this->assertSame('127.0.0.1', $log->ip_address);
        $this->assertSame(['roles' => ['alpha', 'viewer']], $log->old_values);
        $this->assertSame(['roles' => ['alpha', 'manager', 'viewer']], $log->new_values);
        $this->assertSame(DB::table('admin_roles')->where('admin_id', $target->id)->orderBy('role')->pluck('role')->all(), $log->new_values['roles']);
        $this->assertStringNotContainsString($targetToken->plainTextToken, $log->toJson());
        $this->assertStringNotContainsString($target->password, $log->toJson());
    }

    public function test_revocation_records_snapshots_and_preserves_other_data(): void
    {
        $actor = $this->authenticate();
        $target = Admin::factory()->create();
        $other = Admin::factory()->create();
        foreach (['viewer', 'manager', 'alpha'] as $role) {
            $this->assign($target, $role);
        }
        $this->assign($other, 'viewer');
        $this->configure('viewer');
        $token = $target->createToken('device');
        $definitions = DB::table('roles_permissions')->orderBy('role')->orderBy('permission')->get()->toArray();
        $this->deleteJson($this->endpoint($target->id, 'viewer'))->assertNoContent()->assertContent('');
        $log = AuditLog::sole();
        $this->assertSame('admin.role_revoked', $log->action);
        $this->assertSame($actor->id, $log->admin_id);
        $this->assertSame($target->id, $log->target_id);
        $this->assertSame('admin', $log->target_type);
        $this->assertSame('127.0.0.1', $log->ip_address);
        $this->assertSame(['roles' => ['alpha', 'manager', 'viewer']], $log->old_values);
        $this->assertSame(['roles' => ['alpha', 'manager']], $log->new_values);
        $this->assertDatabaseHas('admin_roles', ['admin_id' => $other->id, 'role' => 'viewer']);
        $this->assertEquals($definitions, DB::table('roles_permissions')->orderBy('role')->orderBy('permission')->get()->toArray());
        $this->assertModelExists($token->accessToken);
    }

    public function test_empty_before_and_after_snapshots_are_arrays(): void
    {
        $this->authenticate();
        $target = Admin::factory()->create();
        $this->configure('viewer');
        $this->postJson($this->endpoint($target->id), ['role' => 'viewer'])->assertCreated();
        $this->assertSame(['roles' => []], AuditLog::sole()->old_values);
        app('auth')->forgetGuards();
        $this->deleteJson($this->endpoint($target->id, 'viewer'))->assertNoContent();
        $this->assertSame(['roles' => []], AuditLog::latest('id')->firstOrFail()->new_values);
    }

    public function test_duplicate_assignment_and_missing_assignment_do_not_log(): void
    {
        $this->authenticate();
        $target = Admin::factory()->create();
        $this->assign($target, 'viewer');
        $this->configure('viewer');
        $this->postJson($this->endpoint($target->id), ['role' => 'viewer'])->assertOk()
            ->assertExactJson(['data' => ['admin_id' => $target->id, 'role' => 'viewer']]);
        app('auth')->forgetGuards();
        $this->deleteJson($this->endpoint($target->id, 'manager'))->assertNotFound();
        $this->assertSame(0, AuditLog::count());
    }

    public function test_unique_constraint_conflict_does_not_log_success(): void
    {
        $this->authenticate();
        $target = Admin::factory()->create();
        $this->configure('viewer');
        $inserted = false;
        // Same-connection simulation: real unique constraint, not a two-connection test.
        DB::connection()->beforeExecuting(function (string $sql, array $bindings, $connection) use (&$inserted): void {
            if (!$inserted && str_starts_with($sql, 'insert into `admin_roles`')) {
                $inserted = true;
                $connection->getPdo()->prepare($sql)->execute($bindings);
            }
        });
        $this->postJson($this->endpoint($target->id), ['role' => 'viewer'])->assertOk();
        $this->assertTrue($inserted);
        $this->assertSame(0, AuditLog::count());
    }

    public function test_rejected_requests_leave_no_audit_logs(): void
    {
        $target = Admin::factory()->create();
        $this->postJson($this->endpoint($target->id), [])->assertUnauthorized();
        $this->deleteJson($this->endpoint($target->id, 'viewer'))->assertUnauthorized();
        $this->authenticate(['admins.read']);
        $this->postJson($this->endpoint('abc'), [])->assertForbidden();
        $this->deleteJson($this->endpoint('abc', 'viewer'))->assertForbidden();
        app('auth')->forgetGuards();
        $this->authenticate();
        $this->postJson($this->endpoint('abc'), [])->assertNotFound();
        $this->deleteJson($this->endpoint('abc', 'invalid'))->assertNotFound();
        $this->postJson($this->endpoint($target->id), [])->assertUnprocessable();
        $this->deleteJson($this->endpoint($target->id, 'viewer'), ['extra' => true])->assertUnprocessable();
        $this->assertSame(0, AuditLog::count());
    }

    public function test_self_protected_and_sensitive_operations_do_not_log(): void
    {
        $actor = $this->authenticate();
        $target = Admin::factory()->create();
        $this->configure('viewer');
        $this->postJson($this->endpoint($actor->id), ['role' => 'viewer'])->assertForbidden();
        $this->deleteJson($this->endpoint($actor->id, 'viewer'))->assertForbidden();
        $this->postJson($this->endpoint($target->id), ['role' => 'security_admin'])->assertForbidden();
        $this->deleteJson($this->endpoint($target->id, 'security_admin'))->assertForbidden();
        $this->configure('manager', 'admins.update');
        $this->assign($target, 'manager');
        $this->postJson($this->endpoint($target->id), ['role' => 'manager'])->assertForbidden();
        $this->deleteJson($this->endpoint($target->id, 'manager'))->assertForbidden();
        $this->assertSame(0, AuditLog::count());
    }

    #[DataProvider('mutationMethods')]
    public function test_audit_failure_rolls_back_role_change(string $method): void
    {
        $this->authenticate();
        $target = Admin::factory()->create();
        $other = Admin::factory()->create();
        $this->assign($target, 'alpha');
        $this->assign($other, 'viewer');
        $this->configure('viewer');
        if ($method === 'delete') {
            $this->assign($target, 'viewer');
        }
        $token = $target->createToken('device');
        $before = DB::table('admin_roles')->orderBy('admin_id')->orderBy('role')->get()->toArray();
        $definitions = DB::table('roles_permissions')->orderBy('role')->orderBy('permission')->get()->toArray();
        $level = DB::connection()->transactionLevel();
        $seen = false;
        DB::connection()->beforeExecuting(function (string $sql, array $bindings, $connection) use (&$seen, $method, $target, $level): void {
            if (str_starts_with($sql, 'insert into `audit_logs`')) {
                $seen = true;
                $this->assertSame(DB::connection(), $connection);
                $this->assertSame($level + 1, $connection->transactionLevel());
                $exists = $connection->table('admin_roles')->where('admin_id', $target->id)->where('role', 'viewer')->exists();
                $this->assertSame($method === 'post', $exists);
                // Real failing MySQL statement, issued after verifying the business write occurred.
                $connection->getPdo()->exec('INSERT INTO audit_logs (action, target_type) VALUES (NULL, NULL)');
            }
        });
        $this->withoutExceptionHandling();
        try {
            if ($method === 'post') {
                $this->postJson($this->endpoint($target->id), ['role' => 'viewer']);
            } else {
                $this->deleteJson($this->endpoint($target->id, 'viewer'));
            }
            $this->fail('Expected real MySQL failure.');
        } catch (\PDOException $exception) {
            $this->assertSame(1048, (int) $exception->errorInfo[1]);
        }
        $this->assertTrue($seen);
        $this->assertEquals($before, DB::table('admin_roles')->orderBy('admin_id')->orderBy('role')->get()->toArray());
        $this->assertEquals($definitions, DB::table('roles_permissions')->orderBy('role')->orderBy('permission')->get()->toArray());
        $this->assertSame(0, AuditLog::count());
        $this->assertSame($level, DB::connection()->transactionLevel());
        $this->assertModelExists($token->accessToken);
    }

    public static function mutationMethods(): array
    {
        return [['post'], ['delete']];
    }
}
