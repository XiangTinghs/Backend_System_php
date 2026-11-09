<?php

namespace Tests\Feature\Api\V1\Platform;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\PlatformJwtFixture;
use Tests\Support\TestingDatabase;
use Tests\TestCase;

class PlayerResolveConcurrencyTest extends TestCase
{
    // Independent HTTP workers require committed synthetic fixtures, all precisely removed in finally.
    #[DataProvider('retryCases')]
    public function test_concurrent_resolves_are_atomic_and_idempotent(string $mode, int $secondStatus, int $records): void
    {
        TestingDatabase::verifyResolve();
        $this->assertSame(0, DB::connection()->transactionLevel());
        $fixture = DB::transaction(fn () => PlatformJwtFixture::create());
        $workers = [];
        try {
            $token = PlatformJwtFixture::token($fixture);
            $key = (string) Str::uuid();
            $body = ['external_user_id' => 'ConcurrentPlayer', 'name' => 'First name'];
            $workers[] = $this->worker(['token' => $token, 'body' => $body, 'idempotency_key' => $key, 'hold' => true, 'drop_response' => $mode === 'lost response', 'rate_limit' => $mode === 'rate exceeded' ? 1 : 120]);
            $workers[] = $this->worker(['token' => $token,
                'body' => $mode === 'conflicting body' ? ['external_user_id' => 'OtherPlayer'] : $body,
                'idempotency_key' => $mode === 'different keys' ? (string) Str::uuid() : $key, 'hold' => false, 'rate_limit' => $mode === 'rate exceeded' ? 1 : 120]);
            $firstReady = $this->workerLine($workers[0]);
            $secondReady = $this->workerLine($workers[1]);
            $this->assertMatchesRegularExpression('/\AREADY [0-9]+\z/', $firstReady);
            $this->assertMatchesRegularExpression('/\AREADY [0-9]+\z/', $secondReady);
            fwrite($workers[0]['pipes'][0], "go\n");
            $this->assertSame('INSERTED', $this->workerLine($workers[0]));
            fwrite($workers[1]['pipes'][0], "go\n");
            $waitingId = (int) substr($secondReady, 6);
            $observed = false;
            for ($attempt = 0; $attempt < 300; $attempt++) {
                $process = collect(DB::select('SHOW FULL PROCESSLIST'))->first(fn ($process) => (int) $process->Id === $waitingId);
                $sql = $process->Info ?? '';
                if (str_contains($sql, '`platforms`') && str_ends_with($sql, 'for update')) {
                    $observed = true;
                    break;
                }
                usleep(10000);
            }
            $this->assertTrue($observed, 'Second independent MySQL connection must reach the locked platform.');
            $read = [$workers[1]['pipes'][1]];
            $write = $except = [];
            $this->assertSame(0, stream_select($read, $write, $except, 0, 100000));
            fwrite($workers[0]['pipes'][0], "commit\n");
            if ($mode === 'lost response') {
                $this->assertSame('DROPPED 201', $this->workerLine($workers[0]));
                $stored = DB::table('platform_api_idempotencies')->where('platform_id', $fixture['platform']->id)->sole();
                $first = ['status' => (int) $stored->response_status, 'body' => $stored->response_body];
            } else {
                $first = $this->workerResult($workers[0]);
            }
            $second = $this->workerResult($workers[1]);
            $this->assertSame(201, $first['status']);
            $this->assertSame($secondStatus, $second['status']);
            if ($mode === 'rate exceeded') {
                $this->assertSame(['message' => 'Too many requests.'], json_decode($second['body'], true));
                $limiter = new \Illuminate\Cache\RateLimiter(\Illuminate\Support\Facades\Cache::store(\App\Services\PlatformResolveRateLimiter::STORE));
                $this->assertSame(1, $limiter->attempts(\App\Services\PlatformResolveRateLimiter::key((string) $fixture['platform']->id)));
            } elseif ($mode === 'conflicting body') {
                $this->assertSame(['message' => 'The Idempotency-Key was already used with different request content.'], json_decode($second['body'], true));
            } else {
                $this->assertSame($first['body'], $second['body']);
            }
            $this->assertSame(1, DB::table('users')->where('platform_id', $fixture['platform']->id)->count());
            $this->assertSame($records, DB::table('platform_api_idempotencies')->where('platform_id', $fixture['platform']->id)->count());
            $this->assertSame(0, DB::table('platform_api_idempotencies')->where('platform_id', $fixture['platform']->id)->where('state', '!=', 'completed')->count());
            $this->assertSame('First name', DB::table('users')->where('platform_id', $fixture['platform']->id)->value('name'));
        } finally {
            foreach ($workers as $worker) {
                if (proc_get_status($worker['process'])['running']) {
                    proc_terminate($worker['process']);
                }
                foreach ($worker['pipes'] as $pipe) {
                    fclose($pipe);
                }
                proc_close($worker['process']);
            }
            TestingDatabase::verifyResolve();
            $id = $fixture['platform']->id;
            TestingDatabase::clearResolveRateLimit((string) $id);
            DB::transaction(function () use ($id): void {
                DB::table('platform_api_idempotencies')->where('platform_id', $id)->delete();
                DB::table('users')->where('platform_id', $id)->delete();
                DB::table('platform_jwt_keys')->where('platform_id', $id)->delete();
                DB::table('platform_jwt_issuers')->where('platform_id', $id)->delete();
                DB::table('platforms')->where('id', $id)->delete();
            });
        }
    }

    private function worker(array $input): array
    {
        $process = proc_open(
            [PHP_BINARY, base_path('tests/Support/PlayerResolveWorker.php')],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            base_path()
        );
        $this->assertIsResource($process);
        stream_set_timeout($pipes[1], 15);
        fwrite($pipes[0], json_encode($input, JSON_THROW_ON_ERROR) . "\n");
        return ['process' => $process, 'pipes' => $pipes];
    }

    private function workerLine(array $worker): string
    {
        $line = fgets($worker['pipes'][1]);
        $detail = $line === false && ! proc_get_status($worker['process'])['running']
            ? stream_get_contents($worker['pipes'][2]) : '';
        $this->assertNotFalse($line, 'Concurrent resolve worker did not respond before timeout. ' . $detail);
        return trim($line);
    }

    private function workerResult(array $worker): array
    {
        $line = $this->workerLine($worker);
        $this->assertStringStartsWith('RESULT ', $line);
        return json_decode(substr($line, 7), true, flags: JSON_THROW_ON_ERROR);
    }

    public static function retryCases(): array
    {
        return [['different keys', 200, 2], ['same key', 201, 1], ['conflicting body', 409, 1], ['lost response', 201, 1], ['rate exceeded', 429, 1]];
    }
}
