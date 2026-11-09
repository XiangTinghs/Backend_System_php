<?php

namespace Tests\Feature\Console;

use App\Models\PlatformApiIdempotency;
use App\Models\User;
use App\Services\IdempotencyPruner;
use App\Services\PlayerResolveService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\Support\PlatformJwtFixture;
use Tests\TestCase;

class PrunePlatformIdempotenciesTest extends TestCase
{
    use DatabaseTransactions;

    private array $fixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixture = PlatformJwtFixture::create();
    }

    private function record(int $seconds, string $state = 'completed'): PlatformApiIdempotency
    {
        return PlatformApiIdempotency::create(['platform_id' => $this->fixture['platform']->id,
            'endpoint' => PlayerResolveService::ENDPOINT, 'idempotency_key' => (string) Str::uuid(),
            'request_hash' => str_repeat('a', 64), 'state' => $state, 'expires_at' => now('UTC')->addSeconds($seconds)]);
    }

    public function test_default_dry_run_has_no_writes(): void
    {
        $record = $this->record(-1);
        $before = $record->refresh()->getAttributes();
        $this->artisan('platform:prune-idempotencies')->expectsOutput('Dry run. No records deleted.')->assertSuccessful();
        $this->assertSame($before, $record->refresh()->getAttributes());
    }

    public function test_apply_deletes_only_completed_expired_records_and_preserves_players(): void
    {
        $expired = $this->record(-60);
        $atCutoff = $this->record(-1);
        $live = $this->record(3600);
        $processing = $this->record(-3600, 'processing');
        $player = User::factory()->create(['platform_id' => $this->fixture['platform']->id]);
        $expired->player_id = $player->id;
        $expired->save();
        $this->artisan('platform:prune-idempotencies', ['--apply' => true])
            ->expectsQuestion('Type exactly: DELETE EXPIRED laravel_testing', 'DELETE EXPIRED laravel_testing')->assertSuccessful();
        $this->assertModelMissing($expired);
        $this->assertModelMissing($atCutoff);
        $this->assertModelExists($live);
        $this->assertModelExists($processing);
        $this->assertModelExists($player);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_batch_limits_are_bounded_and_each_delete_is_in_a_transaction(): void
    {
        for ($i = 0; $i < 7; $i++) {
            $this->record(-60);
        }
        $connection = app(IdempotencyPruner::class)->connection();
        $level = $connection->transactionLevel();
        $counts = [];
        $connection->beforeExecuting(function (string $sql, array $bindings, $actual) use ($connection, $level, &$counts): void {
            if (str_starts_with($sql, 'delete from `platform_api_idempotencies`')) {
                $this->assertSame($connection, $actual);
                $this->assertSame($level + 1, $actual->transactionLevel());
                $this->assertStringContainsString('`expires_at` <= ?', $sql);
                $counts[] = count($bindings);
            }
        });
        $this->artisan('platform:prune-idempotencies', ['--apply' => true, '--batch-size' => '2', '--max-batches' => '2'])
            ->expectsQuestion('Type exactly: DELETE EXPIRED laravel_testing', 'DELETE EXPIRED laravel_testing')->assertSuccessful();
        $this->assertCount(2, $counts);
        $this->assertDatabaseCount('platform_api_idempotencies', 3);
    }

    public function test_confirmation_and_scheduled_opt_in_are_required(): void
    {
        $this->record(-1);
        $this->artisan('platform:prune-idempotencies', ['--apply' => true])
            ->expectsQuestion('Type exactly: DELETE EXPIRED laravel_testing', 'no')->assertFailed();
        $this->artisan('platform:prune-idempotencies', ['--apply' => true, '--no-interaction' => true])->assertFailed();
        $this->artisan('platform:prune-idempotencies', ['--apply' => true, '--scheduled' => true, '--no-interaction' => true])->assertFailed();
        $this->assertDatabaseCount('platform_api_idempotencies', 1);
        config(['platform_api.prune_scheduled' => true]);
        $this->artisan('platform:prune-idempotencies', ['--apply' => true, '--scheduled' => true, '--no-interaction' => true])->assertSuccessful();
        $this->assertDatabaseCount('platform_api_idempotencies', 0);
    }

    public function test_invalid_batch_options_have_no_writes(): void
    {
        $this->record(-1);
        foreach ([['--batch-size' => '0'], ['--batch-size' => '1001'], ['--max-batches' => '-1'], ['--max-batches' => 'abc']] as $options) {
            $this->artisan('platform:prune-idempotencies', $options)->assertFailed();
        }
        $this->assertDatabaseCount('platform_api_idempotencies', 1);
    }

    public function test_refreshed_record_is_rechecked_after_preview(): void
    {
        $record = $this->record(-60);
        $service = app(IdempotencyPruner::class);
        $cutoff = $service->cutoff();
        $this->assertSame(1, $service->preview($cutoff)['count']);
        $record->expires_at = now('UTC')->addHours(48);
        $record->save();
        $this->assertSame(0, $service->batch($cutoff, 100));
        $this->assertModelExists($record);
    }

    public function test_scheduler_is_disabled_by_default_and_opt_in_is_hourly(): void
    {
        $schedule = app(\Illuminate\Console\Scheduling\Schedule::class);
        $events = fn () => array_values(array_filter($schedule->events(), fn ($event) => str_contains($event->command ?? '', 'platform:prune-idempotencies')));
        $this->assertSame([], $events());
        config(['platform_api.prune_scheduled' => true]);
        require base_path('routes/console.php');
        $this->assertCount(1, $events());
        $this->assertSame('0 * * * *', $events()[0]->expression);
        $this->assertStringContainsString('--scheduled', $events()[0]->command);
    }

    public function test_delete_failure_rolls_back_and_preserves_records(): void
    {
        $record = $this->record(-60);
        $service = app(IdempotencyPruner::class);
        $connection = $service->connection();
        $level = $connection->transactionLevel();
        $connection->beforeExecuting(function (string $sql): void {
            if (str_starts_with($sql, 'delete from `platform_api_idempotencies`')) {
                throw new \Illuminate\Database\QueryException('mysql', $sql, [], new \PDOException('Test prune delete failure'));
            }
        });
        try {
            $service->batch($service->cutoff(), 100);
            $this->fail('Expected delete failure.');
        } catch (\Illuminate\Database\QueryException $exception) {
            $this->assertStringContainsString('Test prune delete failure', $exception->getMessage());
        }
        $this->assertSame($level, $connection->transactionLevel());
        $this->assertModelExists($record);
    }

    public function test_future_cutoff_is_refused_and_expiry_boundary_is_inclusive(): void
    {
        $service = app(IdempotencyPruner::class);
        $cutoff = $service->cutoff();
        $record = $this->record(10);
        $record->expires_at = $cutoff;
        $record->save();
        try {
            $service->batch($cutoff->addDay(), 100);
            $this->fail('Expected future cutoff refusal.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Invalid prune size or future cutoff.', $e->getMessage());
        }
        $this->assertSame(1, $service->batch($cutoff, 100));
    }
}
