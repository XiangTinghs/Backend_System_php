<?php

namespace Tests\Feature\Models;

use App\Models\Platform;
use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class UserModelTest extends TestCase
{
    use DatabaseTransactions;

    public function test_defaults_nullable_fields_and_platform_relationship(): void
    {
        $platform = Platform::factory()->create();
        $player = User::create(['platform_id' => $platform->id, 'external_user_id' => 'player-1'])->refresh();

        $this->assertSame(1, $player->status);
        $this->assertNull($player->name);
        $this->assertNull($player->last_login_at);
        $this->assertTrue($player->platform->is($platform));
        $this->assertNotNull($player->created_at);
        $this->assertNotNull($player->updated_at);
        $this->assertNotInstanceOf(Authenticatable::class, $player);
    }

    public function test_display_name_disabled_status_and_login_timestamp(): void
    {
        $player = User::factory()->create([
            'name' => 'Display name', 'status' => 0, 'last_login_at' => '2026-11-02 12:34:56',
        ])->refresh();

        $this->assertSame('Display name', $player->name);
        $this->assertSame(0, $player->status);
        $this->assertInstanceOf(Carbon::class, $player->last_login_at);
        $this->assertSame('2026-11-02 12:34:56', $player->last_login_at->format('Y-m-d H:i:s'));
    }

    public function test_same_external_id_is_allowed_on_different_platforms(): void
    {
        $first = User::factory()->create(['external_user_id' => 'shared-player']);
        $second = User::factory()->create(['external_user_id' => 'shared-player']);
        $this->assertNotSame($first->platform_id, $second->platform_id);
    }

    public function test_external_id_is_case_sensitive_within_a_platform(): void
    {
        $platform = Platform::factory()->create();
        User::factory()->for($platform)->create(['external_user_id' => 'PlayerA']);
        User::factory()->for($platform)->create(['external_user_id' => 'playera']);
        $this->assertSame(1, $platform->users()->where('external_user_id', 'PlayerA')->count());
        $this->assertSame(1, $platform->users()->where('external_user_id', 'playera')->count());
    }

    public function test_duplicate_external_id_within_a_platform_is_rejected(): void
    {
        $player = User::factory()->create();
        $this->expectException(QueryException::class);
        User::factory()->create(['platform_id' => $player->platform_id, 'external_user_id' => $player->external_user_id]);
    }

    public function test_nonexistent_platform_is_rejected(): void
    {
        $missingId = ((int) Platform::max('id')) + 1;
        $this->expectException(QueryException::class);
        User::factory()->create(['platform_id' => $missingId]);
    }

    #[DataProvider('invalidPlayers')]
    public function test_required_fields_and_column_limits_are_enforced(array $attributes): void
    {
        $this->expectException(QueryException::class);
        User::factory()->create($attributes);
    }

    public static function invalidPlayers(): array
    {
        return [
            'missing platform' => [['platform_id' => null]],
            'missing external ID' => [['external_user_id' => null]],
            'external ID too long' => [['external_user_id' => str_repeat('x', 101)]],
            'display name too long' => [['name' => str_repeat('x', 101)]],
            'negative status' => [['status' => -1]],
            'status outside unsigned tinyint' => [['status' => 256]],
        ];
    }

    public function test_player_schema_has_only_intended_fields(): void
    {
        $this->assertEqualsCanonicalizing([
            'id', 'platform_id', 'external_user_id', 'name', 'status', 'last_login_at', 'created_at', 'updated_at',
        ], Schema::getColumnListing('users'));
        $this->assertSame('utf8mb4_0900_as_cs', collect(Schema::getColumns('users'))->firstWhere('name', 'external_user_id')['collation']);
        $this->assertSame('utf8mb4_0900_as_cs', collect(Schema::getColumns('platforms'))->firstWhere('name', 'code')['collation']);
    }

    public function test_conversion_and_rollback_refuse_existing_rows_without_modifying_them(): void
    {
        $migration = require database_path('migrations/2026_11_02_000002_convert_users_to_platform_players.php');
        $connection = DB::connection($migration->getConnection());

        // Verify the actual migration connection before creating data or invoking either method.
        if (
            ! app()->environment('testing')
            || $connection->getDriverName() !== 'mysql'
            || $connection->getDatabaseName() !== 'laravel_testing'
            || $connection->selectOne('SELECT DATABASE() AS database_name')->database_name !== 'laravel_testing'
        ) {
            throw new RuntimeException('Migration safety tests must use the laravel_testing MySQL database.');
        }

        $player = User::factory()->create();
        foreach (['up', 'down'] as $method) {
            try {
                $migration->$method();
                $this->fail('Expected migration to refuse non-empty users.');
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('non-empty', $exception->getMessage());
            }
            $this->assertModelExists($player);
            $this->assertTrue(Schema::hasColumn('users', 'platform_id'));
        }
    }
}
