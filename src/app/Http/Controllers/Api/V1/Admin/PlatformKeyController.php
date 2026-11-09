<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\PlatformKeyRequest;
use App\Http\Requests\Api\V1\Admin\IndexPlatformKeyRequest;
use App\Http\Requests\Api\V1\Admin\StorePlatformKeyRequest;
use App\Http\Requests\Api\V1\Admin\UpdatePlatformKeyStatusRequest;
use App\Http\Resources\Api\V1\Admin\PlatformKeyResource;
use App\Models\Platform;
use App\Models\PlatformJwtIssuer;
use App\Models\PlatformJwtKey;
use App\Services\AuditLogService;
use App\Support\RsaPublicKey;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;

class PlatformKeyController extends Controller
{
    public function __construct(private readonly AuditLogService $auditLogs)
    {
    }

    public function index(IndexPlatformKeyRequest $request): AnonymousResourceCollection
    {
        return PlatformKeyResource::collection(PlatformJwtKey::where('platform_id', $request->route('id'))
            ->with('issuer')->orderBy('id')->paginate((int) $request->validated('per_page', 20), ['*'], 'page', (int) $request->validated('page', 1))->appends($request->validated()));
    }

    public function show(PlatformKeyRequest $request): PlatformKeyResource
    {
        $key = PlatformJwtKey::where('platform_id', $request->route('id'))
            ->whereKey($request->route('keyId'))->with('issuer')->first();
        abort_if($key === null, 404, 'Platform key not found.');

        return new PlatformKeyResource($key);
    }

    public function store(StorePlatformKeyRequest $request): JsonResponse
    {
        $connection = (new PlatformJwtKey())->getConnection();
        $key = $connection->transaction(function () use ($request, $connection): PlatformJwtKey {
            // All key writers serialize on the platform, including first issuer registration.
            $platform = Platform::on($connection->getName())->whereKey($request->route('id'))->lockForUpdate()->first();
            abort_if($platform === null, 404, 'Platform not found.');
            $input = $request->validated();
            $issuer = PlatformJwtIssuer::on($connection->getName())->whereKey($platform->id)->first();
            if ($issuer !== null && $issuer->issuer !== $input['issuer']) {
                throw ValidationException::withMessages(['issuer' => 'The platform issuer cannot be changed.']);
            }
            if ($issuer === null) {
                try {
                    PlatformJwtIssuer::on($connection->getName())->create(['platform_id' => $platform->id, 'issuer' => $input['issuer']]);
                } catch (QueryException $exception) {
                    $this->translateConflict($exception, 'platform_jwt_issuers_issuer_unique', 'issuer');
                }
            }
            $publicKey = RsaPublicKey::fromPem($input['public_key']);
            try {
                $key = PlatformJwtKey::on($connection->getName())->create([
                    'platform_id' => $platform->id, 'kid' => $input['kid'], 'public_key' => $publicKey->pem,
                    'fingerprint' => $publicKey->fingerprint, 'is_active' => (bool) ($input['is_active'] ?? true),
                    'valid_from' => $input['valid_from'], 'valid_until' => $input['valid_until'],
                ]);
            } catch (QueryException $exception) {
                if ($this->isConflict($exception, 'platform_jwt_keys_fingerprint_unique')) {
                    throw ValidationException::withMessages(['public_key' => 'The public key has already been registered.']);
                }
                $this->translateConflict($exception, 'platform_jwt_keys_platform_id_kid_unique', 'kid');
            }
            $this->auditLogs->record(
                $request->user('sanctum')->id,
                'platform.key_registered',
                'platform_key',
                (string) $key->id,
                null,
                ['issuer' => $input['issuer'], 'kid' => $key->kid, 'fingerprint' => $key->fingerprint,
                    'is_active' => $key->is_active, 'valid_from' => $key->valid_from->utc()->toISOString(),
                    'valid_until' => $key->valid_until->utc()->toISOString()],
                $request->ip(),
                $connection->getName()
            );

            return $key->load('issuer');
        });

        return (new PlatformKeyResource($key))->response()->setStatusCode(201)
            ->header('Location', url('/api/v1/admin/platforms/' . $key->platform_id . '/keys/' . $key->id));
    }

    public function status(UpdatePlatformKeyStatusRequest $request): PlatformKeyResource|JsonResponse
    {
        return $this->mutate($request, false);
    }

    public function revoke(PlatformKeyRequest $request): PlatformKeyResource|JsonResponse
    {
        return $this->mutate($request, true);
    }

    private function mutate(PlatformKeyRequest $request, bool $revoke): PlatformKeyResource|JsonResponse
    {
        $connection = (new PlatformJwtKey())->getConnection();

        return $connection->transaction(function () use ($request, $connection, $revoke): PlatformKeyResource|JsonResponse {
            $platform = Platform::on($connection->getName())->whereKey($request->route('id'))->lockForUpdate()->first();
            abort_if($platform === null, 404, 'Platform not found.');
            $key = PlatformJwtKey::on($connection->getName())->where('platform_id', $platform->id)
                ->whereKey($request->route('keyId'))->lockForUpdate()->first();
            abort_if($key === null, 404, 'Platform key not found.');
            if ($key->revoked_at !== null) {
                return $revoke ? new PlatformKeyResource($key->load('issuer'))
                    : response()->json(['message' => 'Platform key has been revoked.'], 409);
            }
            $old = ['is_active' => $key->is_active];
            $key->is_active = $revoke ? false : (bool) $request->validated('is_active');
            if ($revoke) {
                $old['revoked_at'] = null;
                $key->revoked_at = now('UTC');
            }
            if ($key->isDirty()) {
                $key->save();
                $new = ['is_active' => $key->is_active];
                if ($revoke) {
                    $new['revoked_at'] = $key->revoked_at->utc()->toISOString();
                }
                $action = $revoke ? 'platform.key_revoked' : ($key->is_active ? 'platform.key_enabled' : 'platform.key_disabled');
                $this->auditLogs->record(
                    $request->user('sanctum')->id,
                    $action,
                    'platform_key',
                    (string) $key->id,
                    $old,
                    $new,
                    $request->ip(),
                    $connection->getName()
                );
            }

            return new PlatformKeyResource($key->load('issuer'));
        });
    }

    private function translateConflict(QueryException $exception, string $index, string $field): never
    {
        if ($this->isConflict($exception, $index)) {
            throw ValidationException::withMessages([$field => 'The ' . $field . ' has already been taken.']);
        }
        throw $exception;
    }

    private function isConflict(QueryException $exception, string $index): bool
    {
        return (int) ($exception->errorInfo[1] ?? 0) === 1062
            && preg_match("/for key ['`](?:[^'`]+\\.)?" . preg_quote($index, '/') . "['`]/", $exception->errorInfo[2] ?? '') === 1;
    }

}
