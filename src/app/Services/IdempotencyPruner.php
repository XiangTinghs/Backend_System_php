<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class IdempotencyPruner
{
    public function connection(): Connection
    {
        $connection = DB::connection();
        if ($connection->getDriverName() !== 'mysql') {
            throw new RuntimeException('Idempotency pruning requires MySQL with SKIP LOCKED.');
        }
        return $connection;
    }

    public function cutoff(): CarbonImmutable
    {
        return CarbonImmutable::parse($this->connection()->selectOne('SELECT UTC_TIMESTAMP() AS cutoff')->cutoff, 'UTC');
    }

    public function preview(CarbonImmutable $cutoff): array
    {
        $query = $this->connection()->table('platform_api_idempotencies')
            ->where('state', 'completed')->where('expires_at', '<=', $cutoff);
        return ['count' => (clone $query)->count(),
            'sample' => $query->orderBy('id')->limit(10)->get(['id', 'platform_id', 'expires_at'])->map(fn ($row) => (array) $row)->all()];
    }

    public function batch(CarbonImmutable $cutoff, int $size): int
    {
        if ($size < 1 || $size > 1000 || $cutoff > $this->cutoff()) {
            throw new RuntimeException('Invalid prune size or future cutoff.');
        }
        $connection = $this->connection();
        return $connection->transaction(function () use ($connection, $cutoff, $size): int {
            // Same platform-first order as Resolve. Busy platforms are skipped, never waited on.
            $platform = $connection->table('platforms')->whereExists(function ($query) use ($cutoff): void {
                $query->selectRaw('1')->from('platform_api_idempotencies')
                    ->whereColumn('platform_api_idempotencies.platform_id', 'platforms.id')
                    ->where('state', 'completed')->where('expires_at', '<=', $cutoff);
            })->orderBy('platforms.id')->lock('for update skip locked')->first(['platforms.id']);
            if ($platform === null) {
                return 0;
            }
            $ids = $connection->table('platform_api_idempotencies')->where('platform_id', $platform->id)
                ->where('state', 'completed')->where('expires_at', '<=', $cutoff)
                ->orderBy('id')->limit($size)->lock('for update skip locked')->pluck('id')->all();
            if ($ids === []) {
                return 0;
            }
            $deleted = $connection->table('platform_api_idempotencies')->where('platform_id', $platform->id)
                ->whereIn('id', $ids)->where('state', 'completed')->where('expires_at', '<=', $cutoff)->delete();
            if ($deleted !== count($ids)) {
                throw new RuntimeException('Prune affected an unexpected number of records.');
            }
            return $deleted;
        }, 3);
    }
}
