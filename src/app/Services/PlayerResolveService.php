<?php

namespace App\Services;

use App\Auth\PlatformContext;
use App\Http\Resources\Api\V1\Platform\PlayerResource;
use App\Models\Platform;
use App\Models\PlatformApiIdempotency;
use App\Models\PlatformJwtKey;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Http\Exceptions\HttpResponseException;

class PlayerResolveService
{
    public const ENDPOINT = 'v1.platform.players.resolve';
    public const RETENTION_HOURS = 48;

    /** @return array{status: int, body: string} */
    public function resolve(PlatformContext $context, array $input, string $idempotencyKey): array
    {
        $connection = (new User())->getConnection();
        $name = $connection->getName();
        $canonical = ['external_user_id' => $input['external_user_id']];
        if (array_key_exists('name', $input)) {
            $canonical['name'] = $input['name'];
        }
        $hash = hash('sha256', json_encode($canonical, JSON_THROW_ON_ERROR));

        return $connection->transaction(function () use ($context, $input, $idempotencyKey, $hash, $name): array {
            // Platform -> key -> idempotency -> player. This deliberately serializes resolves per platform.
            // Platform status/key writers use the same platform-first order.
            $platform = Platform::on($name)->whereKey($context->platformId)->lockForUpdate()->first();
            if ($platform === null) {
                $this->fail(401, 'Unauthenticated.');
            }
            $key = PlatformJwtKey::on($name)->where('platform_id', $context->platformId)->whereKey($context->keyId)->sharedLock()->first();
            $now = now('UTC');
            $this->requireCurrentCredential($context, $key);
            if (! $platform->is_active) {
                $this->fail(403, 'Platform is disabled.');
            }
            $scope = ['platform_id' => $context->platformId, 'endpoint' => self::ENDPOINT, 'idempotency_key' => $idempotencyKey];
            $record = PlatformApiIdempotency::on($name)->where($scope)->lockForUpdate()->first();
            if ($record !== null && $record->state !== 'completed') {
                $this->fail(409, 'The request is not complete. Retry later.');
            }
            if ($record !== null && $record->expires_at > $now) {
                if (! hash_equals($record->request_hash, $hash)) {
                    $this->fail(409, 'The Idempotency-Key was already used with different request content.');
                }
                $player = User::on($name)->withCasts(['id' => 'string'])->where('platform_id', $context->platformId)
                    ->where('external_user_id', $input['external_user_id'])->whereKey($record->player_id)->lockForUpdate()->first();
                if ($player === null) {
                    $this->fail(409, 'The stored resolve result is no longer available.');
                }
                $this->requireCurrentCredential($context, $key);
                $this->requireActive($player);
                if (! in_array($record->response_status, [200, 201], true) || ! is_string($record->response_body)) {
                    $this->fail(409, 'The stored resolve result is no longer available.');
                }
                return ['status' => $record->response_status, 'body' => $record->response_body];
            }
            if ($record === null) {
                $record = PlatformApiIdempotency::on($name)->create($scope + ['request_hash' => $hash,
                    'state' => 'processing', 'expires_at' => $now->copy()->addHours(self::RETENTION_HOURS)]);
                $record = PlatformApiIdempotency::on($name)->where($scope)->lockForUpdate()->firstOrFail();
            } else {
                // Reclaim only an expired completed record, in place and under its lock; no deletes/pruning race.
                $record->fill(['request_hash' => $hash, 'state' => 'processing', 'player_id' => null,
                    'response_status' => null, 'response_body' => null]);
            }
            $identity = ['platform_id' => $context->platformId, 'external_user_id' => $input['external_user_id']];
            $player = User::on($name)->withCasts(['id' => 'string'])->where($identity)->first();
            $created = false;
            if ($player === null) {
                try {
                    User::on($name)->create($identity + ['name' => $input['name'] ?? null, 'status' => 1, 'last_login_at' => null]);
                    $created = true;
                } catch (QueryException $exception) {
                    if (! $this->isPlayerConflict($exception)) {
                        throw $exception;
                    }
                }
            }
            // A locking current read sees a competing committed INSERT even with MySQL REPEATABLE READ.
            // Reread by identity also avoids integer truncation of insertGetId() for unsigned BIGINT IDs.
            $player = User::on($name)->withCasts(['id' => 'string'])->where($identity)->lockForUpdate()->first();
            if ($player === null) {
                $this->fail(409, 'The player changed during resolution. Retry the request.');
            }
            $this->requireCurrentCredential($context, $key);
            $this->requireActive($player);
            $status = $created ? 201 : 200;
            $body = json_encode(
                ['data' => (new PlayerResource($player))->resolve()],
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            );
            $record->fill(['state' => 'completed', 'player_id' => $player->getKey(), 'response_status' => $status,
                'response_body' => $body, 'expires_at' => now('UTC')->addHours(self::RETENTION_HOURS)]);
            $record->save();

            return ['status' => $status, 'body' => $body];
        }, 3);
    }

    private function requireCurrentCredential(PlatformContext $context, ?PlatformJwtKey $key): void
    {
        $now = now('UTC');
        if ($context->expiresAt <= $now->getTimestamp() || $key === null || ! $key->is_active
            || $key->kid !== $context->kid || $key->revoked_at !== null || $key->valid_from > $now || $key->valid_until <= $now) {
            $this->fail(401, 'Unauthenticated.');
        }
    }

    private function requireActive(User $player): void
    {
        if ($player->status !== 1) {
            $this->fail(403, 'Player is disabled.');
        }
    }

    private function isPlayerConflict(QueryException $exception): bool
    {
        return (int) ($exception->errorInfo[1] ?? 0) === 1062
            && preg_match("/for key ['`](?:[^'`]+\\.)?users_platform_id_external_user_id_unique['`]/", $exception->errorInfo[2] ?? '') === 1;
    }

    private function fail(int $status, string $message): never
    {
        throw new HttpResponseException(response()->json(['message' => $message], $status));
    }
}
