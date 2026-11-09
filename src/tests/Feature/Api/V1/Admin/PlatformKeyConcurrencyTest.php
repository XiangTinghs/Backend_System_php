<?php

namespace Tests\Feature\Api\V1\Admin;

use App\Models\Admin;
use App\Models\Platform;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\TestingDatabase;
use Tests\TestCase;

class PlatformKeyConcurrencyTest extends TestCase
{
    // Deliberately no DatabaseTransactions: independent HTTP workers need committed synthetic fixtures.
    // Every created row is precisely scoped and removed in finally; the outer runner checks persistent fingerprints.
    #[DataProvider('platformScopes')]
    public function test_two_http_processes_compete_for_one_global_public_key(bool $samePlatform): void
    {
        TestingDatabase::verify();
        $this->assertSame(0, DB::connection()->transactionLevel());
        $role = 'key_race_' . bin2hex(random_bytes(8));
        [$actor, $first, $second, $token] = DB::transaction(function () use ($role): array {
            $actor = Admin::factory()->create();
            $first = Platform::factory()->create();
            $second = Platform::factory()->create();
            DB::table('admin_roles')->insert(['admin_id' => $actor->id, 'role' => $role]);
            DB::table('roles_permissions')->insert(['role' => $role, 'permission' => 'platforms.keys.register']);
            return [$actor, $first, $second, $actor->createToken('concurrency-fixture')->plainTextToken];
        });
        $workers = [];
        try {
            $pair = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
            $pem = openssl_pkey_get_details($pair)['key'];
            $issuer = 'race-' . bin2hex(random_bytes(8));
            $body = ['issuer' => $issuer, 'kid' => 'race-key-a', 'public_key' => $pem,
                'valid_from' => now('UTC')->subMinute()->format('Y-m-d\TH:i:s\Z'),
                'valid_until' => now('UTC')->addDay()->format('Y-m-d\TH:i:s\Z')];
            $workers[] = $this->worker(['platform_id' => $first->id, 'token' => $token, 'body' => $body, 'hold' => true]);
            $otherBody = array_replace($body, ['issuer' => $samePlatform ? $issuer : $issuer . '-b', 'kid' => 'race-key-b']);
            $workers[] = $this->worker(['platform_id' => $samePlatform ? $first->id : $second->id,
                'token' => $token, 'body' => $otherBody, 'hold' => false]);
            $firstReady = $this->line($workers[0]);
            $secondReady = $this->line($workers[1]);
            $this->assertMatchesRegularExpression('/\AREADY [0-9]+\z/', $firstReady);
            $this->assertMatchesRegularExpression('/\AREADY [0-9]+\z/', $secondReady);
            fwrite($workers[0]['pipes'][0], "go\n");
            $this->assertSame('INSERTED', $this->line($workers[0]));
            fwrite($workers[1]['pipes'][0], "go\n");
            $waitingId = (int) substr($secondReady, 6);
            $observed = false;
            for ($attempt = 0; $attempt < 100; $attempt++) {
                $process = collect(DB::select('SHOW FULL PROCESSLIST'))->first(fn ($process) => (int) $process->Id === $waitingId);
                $sql = $process->Info ?? '';
                if ($samePlatform ? str_contains($sql, '`platforms`') && str_ends_with($sql, 'for update')
                    : str_starts_with($sql, 'insert into `platform_jwt_keys`')) {
                    $observed = true;
                    break;
                }
                usleep(10000);
            }
            $this->assertTrue($observed, 'Second independent connection must reach the competing SQL while the first transaction is held.');
            $read = [$workers[1]['pipes'][1]];
            $write = $except = [];
            $this->assertSame(0, stream_select($read, $write, $except, 0, 100000), 'Competing request must remain blocked before the first commit.');
            fwrite($workers[0]['pipes'][0], "commit\n");
            $winner = $this->workerResult($workers[0]);
            $loser = $this->workerResult($workers[1]);
            $this->assertSame(201, $winner['status']);
            $this->assertSame(['status' => 422, 'message' => 'The public key has already been registered.',
                'errors' => ['public_key' => ['The public key has already been registered.']]], $loser);
            $this->assertSame(1, DB::table('platform_jwt_keys')->whereIn('platform_id', [$first->id, $second->id])->count());
            $this->assertSame(1, DB::table('platform_jwt_issuers')->whereIn('platform_id', [$first->id, $second->id])->count());
            $this->assertSame(1, DB::table('audit_logs')->where('admin_id', $actor->id)->where('action', 'platform.key_registered')->count());
        } finally {
            foreach ($workers as $worker) {
                $status = proc_get_status($worker['process']);
                if ($status['running']) {
                    proc_terminate($worker['process']);
                }
                foreach ($worker['pipes'] as $pipe) {
                    fclose($pipe);
                }
                proc_close($worker['process']);
            }
            TestingDatabase::verify();
            // No broad resets/deletes: only this test's exact synthetic IDs and random role are removed.
            DB::transaction(function () use ($actor, $first, $second, $role): void {
                DB::table('audit_logs')->where('admin_id', $actor->id)->delete();
                DB::table('platform_jwt_keys')->whereIn('platform_id', [$first->id, $second->id])->delete();
                DB::table('platform_jwt_issuers')->whereIn('platform_id', [$first->id, $second->id])->delete();
                DB::table('platforms')->whereIn('id', [$first->id, $second->id])->delete();
                $actor->tokens()->delete();
                DB::table('admin_roles')->where('admin_id', $actor->id)->where('role', $role)->delete();
                DB::table('roles_permissions')->where('role', $role)->where('permission', 'platforms.keys.register')->delete();
                DB::table('admins')->where('id', $actor->id)->delete();
            });
        }
    }

    private function worker(array $input): array
    {
        $process = proc_open(
            [PHP_BINARY, base_path('tests/Support/PlatformKeyRegistrationWorker.php')],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            base_path()
        );
        $this->assertIsResource($process);
        stream_set_timeout($pipes[1], 15);
        fwrite($pipes[0], json_encode($input, JSON_THROW_ON_ERROR) . "\n");
        return ['process' => $process, 'pipes' => $pipes];
    }

    private function line(array $worker): string
    {
        $line = fgets($worker['pipes'][1]);
        $this->assertNotFalse($line, 'Concurrent worker did not respond before timeout.');
        return trim($line);
    }

    private function workerResult(array $worker): array
    {
        $line = $this->line($worker);
        $this->assertStringStartsWith('RESULT ', $line);
        return json_decode(substr($line, 7), true, flags: JSON_THROW_ON_ERROR);
    }

    public static function platformScopes(): array
    {
        return ['same platform' => [true], 'different platforms' => [false]];
    }
}
