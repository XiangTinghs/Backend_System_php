<?php

namespace Tests\Feature\Console;

use App\Models\Admin;
use App\Models\AuditLog;
use App\Models\Platform;
use App\Services\AuditLogService;
use App\Services\PlatformKeyPermissionService;
use DomainException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class PlatformKeyPermissionCommandTest extends TestCase
{
    use DatabaseTransactions;

    private const PASSWORD = 'test-only-operator-password';
    private Admin $operator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->operator = Admin::factory()->create(['password' => self::PASSWORD]);
        DB::table('admin_roles')->insert(['admin_id' => $this->operator->id, 'role' => 'security_admin']);
    }

    private function arguments(array $overrides = []): array
    {
        return array_replace(['action' => 'grant', 'role' => 'security_admin', 'permission' => 'platforms.keys.view',
            '--actor' => (string) $this->operator->id, '--ticket' => 'SEC-123'], $overrides);
    }

    private function apply(string $action, string $permission = 'platforms.keys.view', string $password = self::PASSWORD, ?string $confirmation = null): \Illuminate\Testing\PendingCommand
    {
        return $this->artisan('platform-keys:permission', $this->arguments(['action' => $action, 'permission' => $permission, '--apply' => true]))
            ->expectsQuestion('Operator password', $password)
            ->expectsQuestion(
                'Type exactly: ' . strtoupper($action) . ' ' . $permission . ' security_admin SEC-123',
                $confirmation ?? strtoupper($action) . ' ' . $permission . ' security_admin SEC-123'
            );
    }

    public function test_default_is_preview_and_has_no_writes(): void
    {
        $this->artisan('platform-keys:permission', $this->arguments())
            ->expectsOutput('Preview only. No permissions or audit records were changed.')->assertSuccessful();
        $this->assertDatabaseCount('roles_permissions', 0);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    #[DataProvider('permissions')]
    public function test_explicit_grant_revoke_and_noops_are_audited_without_tokens_or_delegation_changes(string $permission): void
    {
        $other = Admin::factory()->create();
        DB::table('admin_roles')->insert(['admin_id' => $other->id, 'role' => 'security_admin']);
        $token = $this->operator->createToken('existing')->plainTextToken;
        $this->apply('grant', $permission)->expectsOutput('Permission mapping changed and audited.')->assertSuccessful();
        $this->assertTrue($this->operator->hasPermission($permission));
        $this->assertTrue($other->hasPermission($permission));
        $this->apply('grant', $permission)->expectsOutput('Already in the requested state. No change or audit event.')->assertSuccessful();
        $this->assertDatabaseCount('audit_logs', 1);
        $this->apply('revoke', $permission)->assertSuccessful();
        $this->apply('revoke', $permission)->assertSuccessful();
        $this->assertFalse($this->operator->hasPermission($permission));
        $this->assertFalse($other->hasPermission($permission));
        $logs = AuditLog::orderBy('id')->get();
        $this->assertSame(['rbac.platform_key_permission_granted', 'rbac.platform_key_permission_revoked'], $logs->pluck('action')->all());
        foreach ($logs as $index => $log) {
            $base = ['role' => 'security_admin', 'permission' => $permission, 'ticket' => 'SEC-123'];
            $this->assertEquals($base + ['is_active' => $index === 1], $log->old_values);
            $this->assertEquals($base + ['is_active' => $index === 0], $log->new_values);
            $this->assertSame($this->operator->id, $log->admin_id);
            $this->assertSame('role', $log->target_type);
            $this->assertNull($log->target_id);
            $this->assertNull($log->ip_address);
        }
        $this->assertSame(['admins.read'], config('rbac.delegable_permissions'));
        $this->assertSame(1, $this->operator->tokens()->count());
        $this->assertStringNotContainsString(self::PASSWORD, $logs->toJson());
        $this->assertStringNotContainsString($token, $logs->toJson());
    }

    public static function permissions(): array
    {
        return array_map(fn ($permission) => [$permission], PlatformKeyPermissionService::PERMISSIONS);
    }

    public function test_existing_bearer_sees_grant_and_revocation_on_next_request(): void
    {
        $platform = Platform::factory()->create();
        $url = '/api/v1/admin/platforms/' . $platform->id . '/keys';
        $this->withToken($this->operator->createToken('existing')->plainTextToken)->getJson($url)->assertForbidden();
        $this->apply('grant')->assertSuccessful();
        $this->app['auth']->forgetGuards();
        $this->getJson($url)->assertOk();
        $this->apply('revoke')->assertSuccessful();
        $this->app['auth']->forgetGuards();
        $this->getJson($url)->assertForbidden();
    }

    #[DataProvider('invalidArguments')]
    public function test_invalid_or_noninteractive_operations_have_no_writes(array $arguments): void
    {
        $this->artisan('platform-keys:permission', $this->arguments($arguments))->assertFailed();
        $this->assertDatabaseCount('roles_permissions', 0);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public static function invalidArguments(): array
    {
        return [[['action' => 'enable']], [['role' => 'viewer']], [['role' => 'manager']], [['role' => 'unknown']],
            [['permission' => 'admins.roles.assign']], [['--actor' => '']], [['--actor' => '0']],
            [['--ticket' => '']], [['--ticket' => 'sensitive free text']], [['--apply' => true, '--no-interaction' => true]]];
    }

    public function test_disabled_or_unprivileged_operator_is_rejected(): void
    {
        $this->operator->update(['is_active' => false]);
        $this->artisan('platform-keys:permission', $this->arguments())->assertFailed();
        $this->operator->update(['is_active' => true]);
        DB::table('admin_roles')->where('admin_id', $this->operator->id)->delete();
        $this->artisan('platform-keys:permission', $this->arguments())->assertFailed();
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_wrong_password_or_confirmation_cannot_grant(): void
    {
        $this->apply('grant', password: 'incorrect')->expectsOutput('Operator authentication failed.')->assertFailed();
        $this->apply('grant', confirmation: 'yes')->expectsOutput('Confirmation did not match. Nothing changed.')->assertFailed();
        $this->assertDatabaseCount('roles_permissions', 0);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_stale_membership_preview_is_rejected(): void
    {
        $service = app(PlatformKeyPermissionService::class);
        $preview = $service->preview((string) $this->operator->id, 'security_admin', 'platforms.keys.view');
        $other = Admin::factory()->create();
        DB::table('admin_roles')->insert(['admin_id' => $other->id, 'role' => 'security_admin']);
        try {
            $service->apply('grant', (string) $this->operator->id, 'security_admin', 'platforms.keys.view', 'SEC-123', self::PASSWORD, $preview);
            $this->fail('Expected stale preview rejection.');
        } catch (DomainException $e) {
            $this->assertSame('Role membership or permission state changed; review a fresh preview.', $e->getMessage());
        }
        $this->assertDatabaseCount('roles_permissions', 0);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_stale_permission_preview_is_rejected(): void
    {
        $service = app(PlatformKeyPermissionService::class);
        $preview = $service->preview((string) $this->operator->id, 'security_admin', 'platforms.keys.view');
        DB::table('roles_permissions')->insert(['role' => 'security_admin', 'permission' => 'platforms.keys.view']);
        try {
            $service->apply('revoke', (string) $this->operator->id, 'security_admin', 'platforms.keys.view', 'SEC-123', self::PASSWORD, $preview);
            $this->fail('Expected stale preview rejection.');
        } catch (DomainException $e) {
            $this->assertSame('Role membership or permission state changed; review a fresh preview.', $e->getMessage());
        }
        $this->assertTrue($this->operator->hasPermission('platforms.keys.view'));
        $this->assertDatabaseCount('audit_logs', 0);
    }

    #[DataProvider('actions')]
    public function test_audit_failure_rolls_back_permission_mapping(string $action): void
    {
        if ($action === 'revoke') {
            DB::table('roles_permissions')->insert(['role' => 'security_admin', 'permission' => 'platforms.keys.view']);
        }
        $this->mock(AuditLogService::class)->shouldReceive('record')->once()->andThrow(new RuntimeException('Test audit failure'));
        $service = app(PlatformKeyPermissionService::class);
        $preview = $service->preview((string) $this->operator->id, 'security_admin', 'platforms.keys.view');
        try {
            $service->apply($action, (string) $this->operator->id, 'security_admin', 'platforms.keys.view', 'SEC-123', self::PASSWORD, $preview);
            $this->fail('Expected audit failure.');
        } catch (RuntimeException $e) {
            $this->assertSame('Test audit failure', $e->getMessage());
        }
        $this->assertSame($action === 'revoke', $this->operator->hasPermission('platforms.keys.view'));
        $this->assertDatabaseCount('audit_logs', 0);
    }

    #[DataProvider('actions')]
    public function test_permission_write_and_audit_share_connection_and_transaction(string $action): void
    {
        if ($action === 'revoke') {
            DB::table('roles_permissions')->insert(['role' => 'security_admin', 'permission' => 'platforms.keys.view']);
        }
        $connection = DB::connection();
        $level = $connection->transactionLevel();
        $seen = 0;
        $connection->beforeExecuting(function (string $sql, array $bindings, $actual) use ($connection, $level, &$seen): void {
            if (str_starts_with($sql, 'insert into `audit_logs`') || str_starts_with($sql, 'insert into `roles_permissions`') || str_starts_with($sql, 'delete from `roles_permissions`')) {
                $this->assertSame($connection, $actual);
                $this->assertSame($level + 1, $actual->transactionLevel());
                $seen++;
            }
        });
        $this->apply($action)->assertSuccessful();
        $this->assertSame(2, $seen);
    }

    public static function actions(): array
    {
        return [['grant'], ['revoke']];
    }

    public function test_another_role_can_still_grant_permission_after_revoking_target_mapping(): void
    {
        DB::table('admin_roles')->insert(['admin_id' => $this->operator->id, 'role' => 'other_role']);
        DB::table('roles_permissions')->insert(['role' => 'other_role', 'permission' => 'platforms.keys.view']);
        $this->apply('grant')->assertSuccessful();
        $this->apply('revoke')->assertSuccessful();
        $this->assertTrue($this->operator->hasPermission('platforms.keys.view'));
        $this->assertDatabaseHas('roles_permissions', ['role' => 'other_role', 'permission' => 'platforms.keys.view']);
    }
}
