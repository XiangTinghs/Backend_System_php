<?php

namespace Tests\Feature\Services;

use App\Models\Admin;
use App\Models\AuditLog;
use App\Services\AuditLogService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AuditLogServiceTest extends TestCase
{
    use DatabaseTransactions;

    public function test_creates_log_with_array_casts_and_no_updated_at(): void
    {
        $admin = Admin::factory()->create();
        $old = ['username' => 'before', 'name' => '舊名稱', 'is_active' => true, 'roles' => ['viewer']];
        $new = ['username' => 'after', 'name' => '新名稱', 'is_active' => false, 'roles' => ['viewer', 'manager']];
        $log = app(AuditLogService::class)->record(
            $admin->id,
            'admin.updated',
            'admin',
            $admin->id,
            $old,
            $new,
            '2001:db8::1'
        )->fresh();

        // MySQL JSON objects do not preserve key order; retain strict value/type assertions.
        $storedOld = $log->old_values;
        $storedNew = $log->new_values;
        ksort($old);
        ksort($new);
        ksort($storedOld);
        ksort($storedNew);
        $this->assertSame($old, $storedOld);
        $this->assertSame($new, $storedNew);
        $this->assertSame($admin->id, $log->admin_id);
        $this->assertSame('admin.updated', $log->action);
        $this->assertSame('admin', $log->target_type);
        $this->assertSame($admin->id, $log->target_id);
        $this->assertSame('2001:db8::1', $log->ip_address);
        $this->assertNotNull($log->created_at);
        $this->assertArrayNotHasKey('updated_at', $log->getAttributes());
        $stored = DB::table('audit_logs')->where('id', $log->id)->first();
        $this->assertEquals($old, json_decode($stored->old_values, true));
        $this->assertEquals($new, json_decode($stored->new_values, true));
    }

    public function test_platform_code_snapshot_is_string_or_null_only(): void
    {
        $service = app(AuditLogService::class);
        foreach (['PlatformA', null] as $value) {
            $log = $service->record(null, 'platform.created', 'platform', newValues: ['code' => $value])->fresh();
            $this->assertSame(['code' => $value], $log->new_values);
        }
        foreach ([['secret' => 'hidden'], (object) ['secret' => 'hidden'], 123, true] as $value) {
            $log = $service->record(null, 'platform.created', 'platform', newValues: ['code' => $value])->fresh();
            $this->assertSame([], $log->new_values);
        }
    }

    public function test_nullable_fields_and_ipv4_are_supported(): void
    {
        $service = app(AuditLogService::class);
        $log = $service->record(null, 'system.event', 'system')->fresh();
        foreach (['admin_id', 'target_id', 'old_values', 'new_values', 'ip_address'] as $field) {
            $this->assertNull($log->{$field});
        }
        $log = $service->record(null, 'system.event', 'system', ipAddress: '127.0.0.1')->fresh();
        $this->assertSame('127.0.0.1', $log->ip_address);
    }

    public function test_sensitive_and_unknown_fields_are_never_persisted(): void
    {
        $values = [
            'name' => 'Safe', 'password' => 'sensitive', 'password_confirmation' => 'sensitive',
            'token' => 'sensitive', 'access_token' => 'sensitive', 'secret' => 'sensitive',
            'authorization' => 'sensitive', 'Authorization' => 'sensitive',
            'unexpected' => 'sensitive', 'metadata' => ['password' => 'sensitive'],
            'id' => 999, 'created_at' => 'sensitive',
        ];
        $log = app(AuditLogService::class)->record(null, 'admin.updated', 'admin', oldValues: $values, newValues: $values)->fresh();
        $this->assertSame(['name' => 'Safe'], $log->old_values);
        $this->assertSame(['name' => 'Safe'], $log->new_values);
        $stored = DB::table('audit_logs')->where('id', $log->id)->first();
        $this->assertStringNotContainsString('sensitive', $stored->old_values);
        $this->assertStringNotContainsString('sensitive', $stored->new_values);
    }

    public function test_nested_payloads_cannot_hide_under_allowed_keys(): void
    {
        $values = ['name' => ['password' => 'secret'], 'username' => (object) ['token' => 'secret'],
            'role' => ['authorization' => 'secret'], 'roles' => [['secret' => 'secret']], 'is_active' => 'secret'];
        $log = app(AuditLogService::class)->record(null, 'admin.updated', 'admin', newValues: $values)->fresh();
        $this->assertSame([], $log->new_values);
    }

    public function test_model_fillable_excludes_identity_and_timestamp_overrides(): void
    {
        $log = new AuditLog();
        $this->assertFalse($log->isFillable('id'));
        $this->assertFalse($log->isFillable('created_at'));
        $this->assertFalse($log->isFillable('updated_at'));
    }

    public function test_caller_rollback_removes_business_change_and_audit_log(): void
    {
        $admin = Admin::factory()->create(['name' => 'Before']);
        $connection = DB::connection();
        $level = $connection->transactionLevel();
        $logId = null;
        try {
            $connection->transaction(function () use ($admin, $connection, &$logId): void {
                $admin->update(['name' => 'After']);
                $level = $connection->transactionLevel();
                $log = app(AuditLogService::class)->record(
                    $admin->id,
                    'admin.updated',
                    'admin',
                    $admin->id,
                    ['name' => 'Before'],
                    ['name' => 'After'],
                    connection: $connection->getName()
                );
                $logId = $log->id;
                $this->assertSame($level, $connection->transactionLevel());
                $this->assertModelExists($log);
                throw new \RuntimeException('Caller rollback');
            });
            $this->fail('Expected caller exception.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Caller rollback', $exception->getMessage());
        }
        $this->assertNotNull($logId);
        $this->assertSame($level, $connection->transactionLevel());
        $this->assertDatabaseMissing('audit_logs', ['id' => $logId]);
        $this->assertSame('Before', $admin->fresh()->name);
    }

    public function test_database_failure_propagates_and_caller_transaction_rolls_back(): void
    {
        $admin = Admin::factory()->create(['name' => 'Before']);
        $before = AuditLog::count();
        DB::connection()->beforeExecuting(function (string $sql): void {
            if (str_starts_with($sql, 'insert into `audit_logs`')) {
                throw new QueryException('mysql', $sql, [], new \PDOException('Audit insert failed'));
            }
        });
        try {
            DB::transaction(function () use ($admin): void {
                $admin->update(['name' => 'After']);
                app(AuditLogService::class)->record($admin->id, 'admin.updated', 'admin', $admin->id);
            });
            $this->fail('Expected database exception.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('Audit insert failed', $exception->getMessage());
        }
        $this->assertSame($before, AuditLog::count());
        $this->assertSame('Before', $admin->fresh()->name);
    }
}
