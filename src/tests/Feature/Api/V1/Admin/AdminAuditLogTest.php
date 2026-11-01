<?php

namespace Tests\Feature\Api\V1\Admin;

use App\Models\Admin;
use App\Models\AuditLog;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AdminAuditLogTest extends TestCase
{
    use DatabaseTransactions;

    private const URL = '/api/v1/admin/admins';

    private function authenticate(bool $permissions = true): Admin
    {
        $admin = Admin::factory()->create();
        if ($permissions) {
            $role = 'audit_operator_' . $admin->id;
            DB::table('admin_roles')->insert(['admin_id' => $admin->id, 'role' => $role]);
            foreach (['admins.create', 'admins.update'] as $permission) {
                DB::table('roles_permissions')->insert(['role' => $role, 'permission' => $permission]);
            }
        }
        $this->withToken($admin->createToken('audit-test')->plainTextToken);

        return $admin;
    }

    private function payload(?Admin $target = null): array
    {
        return ['username' => $target?->username ?? 'audit_new_admin', 'name' => $target?->name ?? 'New Admin',
            'is_active' => $target?->is_active ?? true];
    }

    private function password(): array
    {
        return ['password' => 'new-password1234', 'password_confirmation' => 'new-password1234'];
    }

    public function test_creation_records_actor_target_ip_and_safe_values(): void
    {
        $actor = $this->authenticate();
        $response = $this->postJson(self::URL, $this->payload() + $this->password())->assertCreated();
        $id = $response->json('data.id');
        $log = AuditLog::where('target_id', $id)->sole();
        $this->assertSame('admin.created', $log->action);
        $this->assertSame($actor->id, $log->admin_id);
        $this->assertSame('admin', $log->target_type);
        $this->assertSame($id, $log->target_id);
        $this->assertNull($log->old_values);
        $this->assertEquals($this->payload(), $log->new_values);
        $this->assertSame('127.0.0.1', $log->ip_address);
        $response->assertHeader('Location', url(self::URL . '/' . $id))
            ->assertJsonMissingPath('data.password')->assertJsonMissingPath('data.token');
        $this->assertSame(0, Admin::findOrFail($id)->tokens()->count());
        $this->assertDatabaseMissing('admin_roles', ['admin_id' => $id]);
    }

    public function test_update_logs_only_changed_public_fields(): void
    {
        $this->authenticate();
        $target = Admin::factory()->create(['name' => 'Before']);
        $oldPassword = $target->password;
        $payload = $this->payload($target);
        $payload['name'] = 'After';
        $this->putJson(self::URL . '/' . $target->id, $payload)->assertOk();
        $log = AuditLog::where('target_id', $target->id)->sole();
        $this->assertSame('admin.updated', $log->action);
        $this->assertSame(['name' => 'Before'], $log->old_values);
        $this->assertSame(['name' => 'After'], $log->new_values);
        $this->assertSame($oldPassword, $target->fresh()->password);
    }

    public function test_username_and_name_changes_are_both_recorded(): void
    {
        $this->authenticate();
        $target = Admin::factory()->create();
        $payload = $this->payload($target);
        $payload['username'] = 'audit_renamed';
        $payload['name'] = 'Renamed';
        $this->putJson(self::URL . '/' . $target->id, $payload)->assertOk();
        $log = AuditLog::where('target_id', $target->id)->sole();
        $this->assertEquals(['username' => $target->username, 'name' => $target->name], $log->old_values);
        $this->assertEquals(['username' => 'audit_renamed', 'name' => 'Renamed'], $log->new_values);
    }

    #[DataProvider('statusChanges')]
    public function test_status_changes_have_dedicated_events(bool $before, bool $after, string $action): void
    {
        $this->authenticate();
        $target = Admin::factory()->create(['is_active' => $before]);
        $payload = $this->payload($target);
        $payload['is_active'] = $after;
        $this->putJson(self::URL . '/' . $target->id, $payload)->assertOk();
        $log = AuditLog::where('target_id', $target->id)->sole();
        $this->assertSame($action, $log->action);
        $this->assertSame(['is_active' => $before], $log->old_values);
        $this->assertSame(['is_active' => $after], $log->new_values);
    }

    public static function statusChanges(): array
    {
        return [[false, true, 'admin.enabled'], [true, false, 'admin.disabled']];
    }

    public function test_password_event_contains_no_password_metadata(): void
    {
        $this->authenticate();
        $target = Admin::factory()->create();
        $this->putJson(self::URL . '/' . $target->id, $this->payload($target) + $this->password())->assertOk();
        $log = AuditLog::where('target_id', $target->id)->sole();
        $this->assertSame('admin.password_changed', $log->action);
        $this->assertNull($log->old_values);
        $this->assertNull($log->new_values);
        $this->assertTrue(Hash::check($this->password()['password'], $target->fresh()->password));
    }

    public function test_multiple_change_types_are_logged_in_same_transaction(): void
    {
        $this->authenticate();
        $target = Admin::factory()->create();
        $payload = $this->payload($target) + $this->password();
        $payload['name'] = 'After';
        $payload['is_active'] = false;
        $this->putJson(self::URL . '/' . $target->id, $payload)->assertOk();
        $this->assertSame(
            ['admin.updated', 'admin.disabled', 'admin.password_changed'],
            AuditLog::where('target_id', $target->id)->orderBy('id')->pluck('action')->all()
        );
        foreach (AuditLog::where('target_id', $target->id)->get() as $log) {
            $this->assertStringNotContainsString($this->password()['password'], $log->toJson());
            $this->assertStringNotContainsString($target->fresh()->password, $log->toJson());
        }
    }

    public function test_unchanged_update_does_not_log(): void
    {
        $this->authenticate();
        $target = Admin::factory()->create();
        $this->putJson(self::URL . '/' . $target->id, $this->payload($target))->assertOk();
        $this->assertSame(0, AuditLog::count());
    }

    public function test_rejected_requests_do_not_log_success(): void
    {
        $target = Admin::factory()->create();
        $this->postJson(self::URL, [])->assertUnauthorized();
        $actor = $this->authenticate(false);
        $this->postJson(self::URL, [])->assertForbidden();
        app('auth')->forgetGuards();
        $actor = $this->authenticate();
        $this->putJson(self::URL . '/abc', [])->assertNotFound();
        app('auth')->forgetGuards();
        $this->putJson(self::URL . '/' . $actor->id, $this->payload($actor))->assertForbidden();
        app('auth')->forgetGuards();
        $this->postJson(self::URL, [])->assertUnprocessable();
        app('auth')->forgetGuards();
        $payload = $this->payload($target);
        $payload['username'] = $actor->username;
        $this->putJson(self::URL . '/' . $target->id, $payload)->assertUnprocessable()->assertJsonValidationErrors('username');
        $this->assertSame(0, AuditLog::count());
    }

    private function failAuditInsert(int $failOn = 1): void
    {
        $count = 0;
        DB::connection()->beforeExecuting(function (string $sql) use (&$count, $failOn): void {
            if (str_starts_with($sql, 'insert into `audit_logs`') && ++$count === $failOn) {
                // Resemble a username unique error: it still must not become a 422.
                $previous = new \PDOException('Audit database failure');
                $previous->errorInfo = ['23000', 1062, "Duplicate entry for key 'admins_username_unique'"];
                throw new QueryException('mysql', $sql, [], $previous);
            }
        });
    }

    public function test_audit_failure_rolls_back_creation_and_propagates(): void
    {
        $this->authenticate();
        $before = Admin::count();
        $this->failAuditInsert();
        $this->withoutExceptionHandling();
        try {
            $this->postJson(self::URL, $this->payload() + $this->password());
            $this->fail('Expected audit database failure.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('Audit database failure', $exception->getMessage());
        }
        $this->assertSame($before, Admin::count());
        $this->assertDatabaseMissing('admins', ['username' => 'audit_new_admin']);
        $this->assertSame(0, AuditLog::count());
    }

    #[DataProvider('auditFailurePositions')]
    public function test_audit_failure_rolls_back_entire_update(int $failOn): void
    {
        $this->authenticate();
        $target = Admin::factory()->create();
        $before = $target->fresh()->getAttributes();
        $this->failAuditInsert($failOn);
        $this->withoutExceptionHandling();
        $payload = $this->payload($target) + $this->password();
        $payload['name'] = 'After';
        $payload['is_active'] = false;
        try {
            $this->putJson(self::URL . '/' . $target->id, $payload);
            $this->fail('Expected audit database failure.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('Audit database failure', $exception->getMessage());
        }
        $this->assertSame($before, $target->fresh()->getAttributes());
        $this->assertSame(0, AuditLog::count());
    }

    public static function auditFailurePositions(): array
    {
        return [[1], [2], [3]];
    }

    public function test_unique_key_race_does_not_leave_audit_log(): void
    {
        $this->authenticate();
        $target = Admin::factory()->create();
        $dispatcher = Admin::getEventDispatcher();
        Admin::setEventDispatcher(clone $dispatcher);
        try {
            Admin::updating(function (Admin $admin): void {
                DB::table('admins')->insert(['username' => $admin->username, 'name' => 'Winner',
                    'password' => $admin->password, 'is_active' => true]);
            });
            $payload = $this->payload($target);
            $payload['username'] = 'audit_race_username';
            $this->putJson(self::URL . '/' . $target->id, $payload)->assertUnprocessable()->assertJsonValidationErrors('username');
            $this->assertSame(0, AuditLog::count());
            $this->assertSame($target->username, $target->fresh()->username);
        } finally {
            Admin::setEventDispatcher($dispatcher);
        }
    }

    public function test_connection_and_transaction_are_shared_with_audit_insert(): void
    {
        $this->authenticate();
        $level = DB::connection()->transactionLevel();
        $seen = false;
        DB::connection()->beforeExecuting(function (string $sql, array $bindings, $connection) use (&$seen, $level): void {
            if (str_starts_with($sql, 'insert into `audit_logs`')) {
                $seen = true;
                $this->assertSame(DB::connection(), $connection);
                $this->assertSame($level + 1, $connection->transactionLevel());
            }
        });
        $this->postJson(self::URL, $this->payload() + $this->password())->assertCreated();
        $this->assertTrue($seen);
    }
}
