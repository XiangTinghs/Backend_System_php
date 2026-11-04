<?php

namespace Tests\Feature\Api\V1\Admin;

use App\Models\Admin;
use App\Models\AuditLog;
use App\Models\Platform;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PlatformAuditLogTest extends TestCase
{
    use DatabaseTransactions;

    private const URL = '/api/v1/admin/platforms';

    private function authenticate(): Admin
    {
        $actor = Admin::factory()->create();
        $role = 'platform_audit_' . $actor->id;
        DB::table('admin_roles')->insert(['admin_id' => $actor->id, 'role' => $role]);
        foreach (['platforms.manage', 'platforms.view', 'audit-logs.view'] as $permission) {
            DB::table('roles_permissions')->insert(['role' => $role, 'permission' => $permission]);
        }
        $this->withToken($actor->createToken('platform-audit-test')->plainTextToken);

        return $actor;
    }

    public function test_snapshots_actor_ip_and_audit_read_contract(): void
    {
        $actor = $this->authenticate();
        $this->withServerVariables(['REMOTE_ADDR' => '2001:db8::7']);
        $response = $this->postJson(self::URL, ['code' => 'PlatformA', 'name' => 'Original'])->assertCreated();
        $id = $response->json('data.id');
        $this->putJson(self::URL . '/' . $id, ['name' => 'Renamed'])->assertOk();
        $this->patchJson(self::URL . '/' . $id . '/status', ['is_active' => false])->assertOk();
        $this->patchJson(self::URL . '/' . $id . '/status', ['is_active' => true])->assertOk();
        $logs = AuditLog::orderBy('id')->get();
        $this->assertSame(['platform.created', 'platform.updated', 'platform.disabled', 'platform.enabled'], $logs->pluck('action')->all());
        $expected = [
            [null, ['code' => 'PlatformA', 'name' => 'Original', 'is_active' => true]],
            [['name' => 'Original'], ['name' => 'Renamed']],
            [['is_active' => true], ['is_active' => false]],
            [['is_active' => false], ['is_active' => true]],
        ];
        foreach ($logs as $index => $log) {
            $this->assertSame($actor->id, $log->admin_id);
            $this->assertSame($id, $log->target_id);
            $this->assertSame('platform', $log->target_type);
            $this->assertSame('2001:db8::7', $log->ip_address);
            $this->assertEquals($expected[$index][0], $log->old_values);
            $this->assertEquals($expected[$index][1], $log->new_values);
        }
        $response = $this->getJson('/api/v1/admin/audit-logs?target_type=platform&action=platform.created')->assertOk();
        $this->assertSame([
            'id' => $logs[0]->id, 'admin_id' => $actor->id, 'action' => 'platform.created',
            'target_type' => 'platform', 'target_id' => $id, 'old_values' => null,
            'new_values' => ['name' => 'Original', 'is_active' => true, 'code' => 'PlatformA'],
            'ip_address' => '2001:db8::7', 'created_at' => $logs[0]->created_at->utc()->toISOString(),
        ], $response->json('data.0'));
        $this->getJson(self::URL)->assertOk();
        $this->getJson(self::URL . '/' . $id)->assertOk();
        $this->assertSame(4, AuditLog::count());
    }

    #[DataProvider('mutations')]
    public function test_audit_error_rolls_back_business_write_and_is_not_misclassified(string $method): void
    {
        $this->authenticate();
        $platform = Platform::factory()->create();
        $before = $platform->refresh()->getAttributes();
        $count = Platform::count();
        DB::connection()->beforeExecuting(function (string $sql): void {
            if (str_starts_with($sql, 'insert into `audit_logs`')) {
                $previous = new PDOException('Audit failure');
                $previous->errorInfo = ['23000', 1062, "Duplicate entry for key 'platforms_code_unique'"];
                throw new QueryException('mysql', $sql, [], $previous);
            }
        });
        $this->withoutExceptionHandling();
        try {
            $this->mutate($method, $platform);
            $this->fail('Expected audit failure.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('Audit failure', $exception->getMessage());
        }
        $this->assertSame($before, $platform->refresh()->getAttributes());
        $this->assertSame($count, Platform::count());
        $this->assertSame(0, AuditLog::count());
    }

    #[DataProvider('mutations')]
    public function test_business_and_audit_use_same_connection_and_transaction(string $method): void
    {
        $this->authenticate();
        $platform = Platform::factory()->create();
        $connection = $platform->getConnection();
        $level = $connection->transactionLevel();
        $seenAudit = false;
        $seenLock = false;
        $connection->beforeExecuting(function (string $sql, array $bindings, $actual) use ($connection, $level, &$seenAudit, &$seenLock): void {
            if (str_ends_with($sql, 'for update') && str_contains($sql, '`platforms`')) {
                $seenLock = true;
                $this->assertSame($connection, $actual);
                $this->assertSame($level + 1, $actual->transactionLevel());
            }
            if (str_starts_with($sql, 'insert into `audit_logs`') || str_starts_with($sql, 'insert into `platforms`') || str_starts_with($sql, 'update `platforms`')) {
                $this->assertSame($connection, $actual);
                $this->assertSame($level + 1, $actual->transactionLevel());
                $seenAudit = $seenAudit || str_starts_with($sql, 'insert into `audit_logs`');
            }
        });
        $this->mutate($method, $platform)->assertSuccessful();
        $this->assertTrue($seenAudit);
        $this->assertSame($method !== 'POST', $seenLock);
    }

    public static function mutations(): array
    {
        return [['POST'], ['PUT'], ['PATCH']];
    }

    private function mutate(string $method, Platform $platform): \Illuminate\Testing\TestResponse
    {
        return match ($method) {
            'POST' => $this->postJson(self::URL, ['code' => 'AuditNew', 'name' => 'New']),
            'PUT' => $this->putJson(self::URL . '/' . $platform->id, ['name' => 'Changed']),
            'PATCH' => $this->patchJson(self::URL . '/' . $platform->id . '/status', ['is_active' => false]),
        };
    }

    public function test_real_unique_key_conflict_after_validation_rolls_back_without_audit(): void
    {
        $this->authenticate();
        $dispatcher = Platform::getEventDispatcher();
        Platform::setEventDispatcher(clone $dispatcher);
        $calls = 0;
        try {
            // A real MySQL conflict on one connection; this is not concurrent transaction coverage.
            Platform::creating(function (Platform $platform) use (&$calls): void {
                $calls++;
                DB::table('platforms')->insert(['code' => $platform->code, 'name' => 'Competing insert', 'is_active' => true]);
            });
            $this->postJson(self::URL, ['code' => 'RaceCode', 'name' => 'New'])->assertUnprocessable()
                ->assertExactJson(['message' => 'The code has already been taken.', 'errors' => ['code' => ['The code has already been taken.']]]);
            $this->assertSame(1, $calls);
            $this->assertDatabaseMissing('platforms', ['code' => 'RaceCode']);
            $this->assertSame(0, AuditLog::count());
        } finally {
            Platform::setEventDispatcher($dispatcher);
        }
    }

    #[DataProvider('unrelatedSqlErrors')]
    public function test_other_sql_errors_propagate(int $error, string $detail): void
    {
        $this->authenticate();
        $this->withoutExceptionHandling();
        DB::connection()->beforeExecuting(function (string $sql) use ($error, $detail): void {
            if (str_starts_with($sql, 'insert into `platforms`')) {
                $previous = new PDOException('Unrelated business failure');
                $previous->errorInfo = ['23000', $error, $detail];
                throw new QueryException('mysql', $sql, [], $previous);
            }
        });
        try {
            $this->postJson(self::URL, ['code' => 'ErrorCode', 'name' => 'New']);
            $this->fail('Expected unrelated SQL failure.');
        } catch (QueryException $exception) {
            $this->assertSame($error, $exception->errorInfo[1]);
        }
        $this->assertSame(0, Platform::count());
        $this->assertSame(0, AuditLog::count());
    }

    public static function unrelatedSqlErrors(): array
    {
        return [[1048, 'Column name cannot be null'], [1062, "Duplicate entry for key 'some_other_unique'"]];
    }
}
