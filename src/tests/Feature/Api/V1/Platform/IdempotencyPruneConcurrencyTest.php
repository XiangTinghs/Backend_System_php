<?php

namespace Tests\Feature\Api\V1\Platform;

use App\Models\PlatformApiIdempotency;
use App\Services\IdempotencyPruner;
use App\Services\PlayerResolveService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\PlatformJwtFixture;
use Tests\Support\TestingDatabase;
use Tests\TestCase;

class IdempotencyPruneConcurrencyTest extends TestCase
{
    public function test_prune_skips_running_resolve_and_preserves_its_reclaimed_record(): void
    {
        TestingDatabase::verifyResolve();
        $this->assertSame(0, DB::connection()->transactionLevel());
        [$busy, $idle, $key] = DB::transaction(function (): array {
            $busy = PlatformJwtFixture::create();
            $idle = PlatformJwtFixture::create(1);
            $key = (string) Str::uuid();
            foreach ([$busy, $idle] as $fixture) {
                PlatformApiIdempotency::create(['platform_id' => $fixture['platform']->id,
                    'endpoint' => PlayerResolveService::ENDPOINT, 'idempotency_key' => $key,
                    'request_hash' => str_repeat('a', 64), 'state' => 'completed', 'expires_at' => now('UTC')->subHour()]);
            }
            return [$busy, $idle, $key];
        });
        $process = null;
        $pipes = [];
        try {
            $cutoff = app(IdempotencyPruner::class)->cutoff();
            $token = PlatformJwtFixture::token($busy);
            $process = proc_open(
                [PHP_BINARY, base_path('tests/Support/PlayerResolveWorker.php')],
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
                base_path()
            );
            $this->assertIsResource($process);
            stream_set_timeout($pipes[1], 15);
            fwrite($pipes[0], json_encode(['token' => $token, 'body' => ['external_user_id' => 'BusyPlayer'],
                'idempotency_key' => $key, 'hold' => true], JSON_THROW_ON_ERROR) . "\n");
            $this->assertMatchesRegularExpression('/\AREADY [0-9]+\z/', trim(fgets($pipes[1])));
            fwrite($pipes[0], "go\n");
            $this->assertSame('INSERTED', trim(fgets($pipes[1])));
            // Busy Resolve holds the platform and expired record while creating its new result.
            $this->assertSame(1, app(IdempotencyPruner::class)->batch($cutoff, 100));
            $this->assertSame(0, DB::table('platform_api_idempotencies')->where('platform_id', $idle['platform']->id)->count());
            $this->assertSame(1, DB::table('platform_api_idempotencies')->where('platform_id', $busy['platform']->id)->count());
            $this->assertSame(0, app(IdempotencyPruner::class)->batch($cutoff, 100));
            fwrite($pipes[0], "commit\n");
            $line = trim(fgets($pipes[1]));
            $this->assertStringStartsWith('RESULT ', $line);
            $result = json_decode(substr($line, 7), true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame(201, $result['status']);
            $record = PlatformApiIdempotency::where('platform_id', $busy['platform']->id)->sole();
            $this->assertSame('completed', $record->state);
            $this->assertTrue($record->expires_at->greaterThan($cutoff));
            $this->assertSame(0, app(IdempotencyPruner::class)->batch($cutoff, 100));
            $this->withToken($token)->withHeader('Idempotency-Key', $key)
                ->postJson('/api/v1/platform/players/resolve', ['external_user_id' => 'BusyPlayer'])
                ->assertCreated()->assertContent($result['body']);
            $this->assertSame(1, DB::table('users')->where('platform_id', $busy['platform']->id)->count());
        } finally {
            if (is_resource($process)) {
                if (proc_get_status($process)['running']) {
                    proc_terminate($process);
                }
                foreach ($pipes as $pipe) {
                    fclose($pipe);
                }
                proc_close($process);
            }
            TestingDatabase::verifyResolve();
            $ids = [$busy['platform']->id, $idle['platform']->id];
            foreach ($ids as $id) {
                TestingDatabase::clearResolveRateLimit((string) $id);
            }
            DB::transaction(function () use ($ids): void {
                DB::table('platform_api_idempotencies')->whereIn('platform_id', $ids)->delete();
                DB::table('users')->whereIn('platform_id', $ids)->delete();
                DB::table('platform_jwt_keys')->whereIn('platform_id', $ids)->delete();
                DB::table('platform_jwt_issuers')->whereIn('platform_id', $ids)->delete();
                DB::table('platforms')->whereIn('id', $ids)->delete();
            });
        }
    }
}
