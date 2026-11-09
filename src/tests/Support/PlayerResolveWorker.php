<?php

// Executable only as a separate testing subprocess. No migration or schema setup is permitted here.
use Illuminate\Database\Connection;
use Illuminate\Database\MySqlConnection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\Support\TestingDatabase;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

try {
    if (PHP_SAPI !== 'cli' || getenv('APP_ENV') !== 'testing') {
        throw new RuntimeException('Testing environment required before bootstrap.');
    }
    foreach (simplexml_load_file(dirname(__DIR__, 2) . '/phpunit.xml')->php->env as $entry) {
        $name = (string) $entry['name'];
        $value = (string) $entry['value'];
        if ((string) $entry['force'] === 'true' || getenv($name) === false) {
            putenv($name . '=' . $value);
            $_ENV[$name] = $_SERVER[$name] = $value;
        }
    }
    Connection::resolverFor('mysql', fn ($pdo, $database, $prefix, $config) => new class ($pdo, $database, $prefix, $config) extends MySqlConnection {
        public function getPdo()
        {
            TestingDatabase::verifyConfiguration($this->getConfig());
            return parent::getPdo();
        }

        protected function run($query, $bindings, Closure $callback)
        {
            TestingDatabase::verifyConfiguration($this->getConfig());
            if (preg_match('/\b(create|alter|drop|truncate|rename|grant|revoke|use)\b/i', $query)
                || preg_match('/`?(laravel|mysql|sys|performance_schema)`?\s*\./i', $query)) {
                throw new RuntimeException('Forbidden SQL in concurrent worker.');
            }
            return parent::run($query, $bindings, $callback);
        }
    });
    $app = require dirname(__DIR__, 2) . '/bootstrap/app.php';
    if ($app->configurationIsCached()) {
        throw new RuntimeException('Cached configuration is prohibited.');
    }
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    TestingDatabase::verifyResolve();
    DB::statement('SET SESSION innodb_lock_wait_timeout = 10');
    $input = json_decode(trim(fgets(STDIN)), true, flags: JSON_THROW_ON_ERROR);
    if (isset($input['rate_limit'])) {
        config(['platform_api.resolve_per_minute' => (int) $input['rate_limit']]);
    }
    $connectionId = (int) DB::selectOne('SELECT CONNECTION_ID() AS id')->id;
    $ready = false;
    DB::connection()->beforeExecuting(function (string $sql) use ($input, $connectionId, &$ready): void {
        if (! $ready && str_contains($sql, '`platforms`') && str_ends_with($sql, 'for update')) {
            $ready = true;
            echo 'READY ' . $connectionId . PHP_EOL;
            flush();
            if (trim(fgets(STDIN)) !== 'go') {
                throw new RuntimeException('Missing barrier release.');
            }
        }
        if ($input['hold'] && str_starts_with($sql, 'update `platform_api_idempotencies`')) {
            // Business INSERT has completed, but its transaction is deliberately held open.
            echo 'INSERTED' . PHP_EOL;
            flush();
            if (trim(fgets(STDIN)) !== 'commit') {
                throw new RuntimeException('Missing commit release.');
            }
        }
    });
    $request = Request::create(
        '/api/v1/platform/players/resolve',
        'POST',
        [],
        [],
        [],
        ['HTTP_AUTHORIZATION' => 'Bearer ' . $input['token'], 'HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json', 'HTTP_IDEMPOTENCY_KEY' => $input['idempotency_key']],
        json_encode($input['body'], JSON_THROW_ON_ERROR)
    );
    $kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
    $response = $kernel->handle($request);
    if (($input['drop_response'] ?? false) && $response->getStatusCode() === 201) {
        // Test control signal only: intentionally discard the committed HTTP response payload.
        echo 'DROPPED 201' . PHP_EOL;
    } else {
        echo 'RESULT ' . json_encode(['status' => $response->getStatusCode(), 'body' => $response->getContent()], JSON_THROW_ON_ERROR) . PHP_EOL;
    }
    $kernel->terminate($request, $response);
} catch (Throwable $exception) {
    // Do not print request input, bearer credentials, SQL bindings or exception traces.
    fwrite(STDERR, 'Concurrent worker failed: ' . get_class($exception) . PHP_EOL);
    exit(1);
}
