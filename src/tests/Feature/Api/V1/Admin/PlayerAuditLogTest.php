<?php

namespace Tests\Feature\Api\V1\Admin;

use App\Http\Resources\Api\V1\Admin\AuditLogResource;
use App\Http\Resources\Api\V1\Admin\PlayerResource;
use App\Models\Admin;
use App\Models\AuditLog;
use App\Models\Platform;
use App\Models\User;
use App\Services\AuditLogService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PlayerAuditLogTest extends TestCase
{
    use DatabaseTransactions;

    private function authenticate(): Admin
    {
        $actor = Admin::factory()->create();
        $role = 'player_audit_' . $actor->id;
        DB::table('admin_roles')->insert(['admin_id' => $actor->id, 'role' => $role]);
        foreach (['players.manage', 'players.view', 'audit-logs.view'] as $permission) {
            DB::table('roles_permissions')->insert(['role' => $role, 'permission' => $permission]);
        }
        $this->withToken($actor->createToken('player-audit')->plainTextToken);

        return $actor;
    }

    public function test_events_snapshots_and_same_connection_transaction(): void
    {
        $actor = $this->authenticate();
        $platform = Platform::factory()->create(['is_active' => false]);
        $player = User::factory()->create(['platform_id' => $platform->id]);
        $connection = $player->getConnection();
        $level = $connection->transactionLevel();
        $seen = ['lock' => 0, 'update' => 0, 'audit' => 0];
        $connection->beforeExecuting(function (string $sql, array $bindings, $actual) use ($connection, $level, &$seen): void {
            $kind = match (true) {
                str_contains($sql, '`users`') && str_ends_with($sql, 'for update') => 'lock',
                str_starts_with($sql, 'update `users`') => 'update',
                str_starts_with($sql, 'insert into `audit_logs`') => 'audit',
                default => null,
            };
            if ($kind !== null) {
                $seen[$kind]++;
                $this->assertSame($connection, $actual);
                $this->assertSame($level + 1, $actual->transactionLevel());
            }
        });
        $this->withServerVariables(['REMOTE_ADDR' => '2001:db8::8']);
        foreach ([0, 1] as $status) {
            $this->patchJson('/api/v1/admin/players/' . $player->id . '/status', ['status' => $status])
                ->assertOk()->assertJsonPath('data.status', $status);
        }
        $this->assertSame(['lock' => 2, 'update' => 2, 'audit' => 2], $seen);
        $logs = AuditLog::where('target_type', 'player')->orderBy('id')->get();
        $this->assertCount(2, $logs);
        foreach ($logs as $index => $log) {
            $this->assertSame($index === 0 ? 'player.disabled' : 'player.enabled', $log->action);
            $this->assertSame(['status' => 1 - $index], $log->old_values);
            $this->assertSame(['status' => $index], $log->new_values);
            $this->assertSame($player->id, $log->target_id);
            $this->assertSame($actor->id, $log->admin_id);
            $this->assertSame('2001:db8::8', $log->ip_address);
        }
        $this->getJson('/api/v1/admin/audit-logs?target_type=player&action=player.enabled')
            ->assertOk()->assertJsonPath('data.0.new_values.status', 1);
        $this->getJson('/api/v1/admin/players')->assertOk();
        $this->getJson('/api/v1/admin/players/' . $player->id)->assertOk();
        $this->assertSame(2, AuditLog::count());
        $this->assertFalse($platform->fresh()->is_active);
        $this->assertSame(1, $actor->tokens()->count());
    }

    public function test_real_audit_sql_failure_rolls_back_player(): void
    {
        $this->authenticate();
        $player = User::factory()->create();
        $before = $player->refresh()->getAttributes();
        $dispatcher = AuditLog::getEventDispatcher();
        AuditLog::setEventDispatcher(clone $dispatcher);
        try {
            // Force a real NOT NULL violation in MySQL, without DDL or a mocked service.
            AuditLog::creating(function (AuditLog $log): void {
                $log->action = null;
            });
            $this->withoutExceptionHandling();
            try {
                $this->patchJson('/api/v1/admin/players/' . $player->id . '/status', ['status' => 0]);
                $this->fail('Expected a real audit SQL constraint failure.');
            } catch (QueryException $exception) {
                $this->assertSame(1048, (int) $exception->errorInfo[1]);
            }
            $this->assertSame($before, $player->fresh()->getAttributes());
            $this->assertSame(0, AuditLog::count());
        } finally {
            AuditLog::setEventDispatcher($dispatcher);
        }
    }

    public function test_unsigned_ids_remain_exact_in_resources_audit_and_lookup_bindings(): void
    {
        $this->authenticate();
        $id = '18446744073709551615';
        $player = User::factory()->create();
        $attributes = $player->getAttributes();
        $attributes['id'] = $id;
        $attributes['platform_id'] = $id;
        $hydrated = (new User())->newFromBuilder($attributes);
        $data = (new \App\Http\Resources\Api\V1\Admin\PlayerResource($hydrated))->resolve();
        $this->assertSame($id, $data['id']);
        $this->assertSame($id, $data['platform_id']);
        $log = app(AuditLogService::class)->record(null, 'player.enabled', 'player', $id, newValues: ['status' => 1]);
        $this->assertSame($id, (string) $log->fresh()->target_id);
        $this->getJson('/api/v1/admin/audit-logs?target_id=' . $id)->assertOk()->assertJsonPath('data.0.target_id', $id);
        $bindingsSeen = [];
        DB::connection()->beforeExecuting(function (string $sql, array $bindings) use (&$bindingsSeen): void {
            if (str_contains($sql, '`users`') && str_starts_with($sql, 'select')) {
                $bindingsSeen[] = $bindings;
            }
        });
        $this->getJson('/api/v1/admin/players/' . $id)->assertNotFound();
        $this->patchJson('/api/v1/admin/players/' . $id . '/status', ['status' => 0])->assertNotFound();
        $this->assertSame([[$id], [$id]], $bindingsSeen);
        $this->assertSame(1, $player->fresh()->status);
    }

    #[DataProvider('resourceIdBoundaries')]
    public function test_resource_json_id_boundaries_and_audit_target(string $id, int|string $expected): void
    {
        $player = User::factory()->create();
        $attributes = $player->getAttributes();
        $attributes['id'] = $id;
        $attributes['platform_id'] = $id;
        $hydrated = (new User())->newFromBuilder($attributes);
        $response = (new PlayerResource($hydrated))->response();
        $json = json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame($expected, $json['data']['id']);
        $this->assertSame($expected, $json['data']['platform_id']);
        $this->assertSame(is_int($expected) ? 'integer' : 'string', gettype($json['data']['id']));
        $this->assertSame(is_int($expected) ? 'integer' : 'string', gettype($json['data']['platform_id']));
        $this->assertSame(json_encode($expected, JSON_THROW_ON_ERROR), json_encode($json['data']['id'], JSON_THROW_ON_ERROR));

        $log = app(AuditLogService::class)->record(null, 'player.enabled', 'player', $id, newValues: ['status' => 1])->fresh();
        $this->assertSame($id, (string) $log->target_id);
        $this->assertSame(['status' => 1], $log->new_values);
        $this->assertSame($log->target_id, (new AuditLogResource($log))->resolve()['target_id']);
    }

    public static function resourceIdBoundaries(): array
    {
        // Decimal literals avoid floating-point arithmetic, including MAX + 1.
        [$below, $max, $above] = PHP_INT_SIZE === 8
            ? ['9223372036854775806', '9223372036854775807', '9223372036854775808']
            : ['2147483646', '2147483647', '2147483648'];

        return [
            'PHP_INT_MAX minus one' => [$below, (int) $below],
            'PHP_INT_MAX' => [$max, PHP_INT_MAX],
            'PHP_INT_MAX plus one' => [$above, $above],
            'unsigned BIGINT maximum' => ['18446744073709551615', '18446744073709551615'],
        ];
    }

    public function test_status_snapshot_filtering_in_service_and_resource(): void
    {
        foreach ([0, 1, null, true, false, '0', 1.0, 2, 255, ['token' => 'secret']] as $value) {
            $expected = is_int($value) && in_array($value, [0, 1], true) ? ['status' => $value] : [];
            $log = app(AuditLogService::class)->record(null, 'player.enabled', 'player', newValues: ['status' => $value, 'token' => 'secret']);
            $this->assertSame($expected, $log->fresh()->new_values);
            // Independently verify read-time filtering of historical JSON.
            $log->setRawAttributes(array_merge($log->getAttributes(), [
                'new_values' => json_encode(['status' => $value, 'token' => 'secret'], JSON_PRESERVE_ZERO_FRACTION),
            ]));
            $this->assertSame($expected, (new AuditLogResource($log))->resolve()['new_values']);
        }
    }
}
