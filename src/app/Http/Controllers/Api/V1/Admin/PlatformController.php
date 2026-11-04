<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\IndexPlatformRequest;
use App\Http\Requests\Api\V1\Admin\StorePlatformRequest;
use App\Http\Requests\Api\V1\Admin\UpdatePlatformRequest;
use App\Http\Requests\Api\V1\Admin\UpdatePlatformStatusRequest;
use App\Http\Resources\Api\V1\Admin\PlatformResource;
use App\Models\Platform;
use App\Services\AuditLogService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;

class PlatformController extends Controller
{
    public function __construct(private readonly AuditLogService $auditLogs)
    {
    }

    public function store(StorePlatformRequest $request): JsonResponse
    {
        $model = new Platform();
        $connection = $model->getConnection();
        $platform = $connection->transaction(function () use ($request, $model, $connection): Platform {
            $input = $request->safe()->only(['code', 'name', 'is_active']);
            $input['is_active'] = (bool) ($input['is_active'] ?? true);
            try {
                $platform = $model->newQuery()->create($input);
            } catch (QueryException $exception) {
                // Only the expected business index conflict becomes a validation error.
                if (
                    (int) ($exception->errorInfo[1] ?? 0) !== 1062
                    || preg_match("/for key ['`](?:[^'`]+\\.)?platforms_code_unique['`]/", $exception->errorInfo[2] ?? '') !== 1
                ) {
                    throw $exception;
                }
                throw ValidationException::withMessages(['code' => 'The code has already been taken.']);
            }

            $this->auditLogs->record(
                $request->user('sanctum')->id,
                'platform.created',
                'platform',
                $platform->id,
                null,
                ['code' => $platform->code, 'name' => $platform->name, 'is_active' => $platform->is_active],
                $request->ip(),
                $connection->getName()
            );

            return $platform;
        });

        return (new PlatformResource($platform))->response()->setStatusCode(201)
            ->header('Location', url('/api/v1/admin/platforms/' . $platform->id));
    }

    public function update(UpdatePlatformRequest $request): PlatformResource|JsonResponse
    {
        return $this->updateField($request, 'name');
    }

    public function status(UpdatePlatformStatusRequest $request): PlatformResource|JsonResponse
    {
        return $this->updateField($request, 'is_active');
    }

    private function updateField(UpdatePlatformRequest $request, string $field): PlatformResource|JsonResponse
    {
        $target = $request->targetPlatform();
        $connection = $target->getConnection();

        return $connection->transaction(function () use ($request, $target, $connection, $field): PlatformResource|JsonResponse {
            $platform = $target->newQuery()->whereKey($target->getKey())->lockForUpdate()->first();
            if ($platform === null) {
                return response()->json(['message' => 'Platform not found.'], 404);
            }

            $before = $platform->{$field};
            // The field comes only from the two controller methods, never from request keys.
            $value = $request->validated($field);
            $platform->{$field} = $field === 'is_active' ? (bool) $value : $value;
            if ($platform->isDirty($field)) {
                $platform->save();
                $action = $field === 'name' ? 'platform.updated'
                    : ($platform->is_active ? 'platform.enabled' : 'platform.disabled');
                $this->auditLogs->record(
                    $request->user('sanctum')->id,
                    $action,
                    'platform',
                    $platform->id,
                    [$field => $before],
                    [$field => $platform->{$field}],
                    $request->ip(),
                    $connection->getName()
                );
            }

            return new PlatformResource($platform);
        });
    }

    public function show(string $id): PlatformResource|JsonResponse
    {
        // Validate as a string to avoid integer overflow before querying an unsigned BIGINT.
        if (
            preg_match('/\A[1-9][0-9]*\z/', $id) !== 1
            || strlen($id) > 20
            || (strlen($id) === 20 && strcmp($id, '18446744073709551615') > 0)
        ) {
            return response()->json(['message' => 'Platform not found.'], 404);
        }

        $platform = Platform::query()
            ->select(['id', 'code', 'name', 'is_active', 'created_at', 'updated_at'])
            ->find($id);

        if ($platform === null) {
            return response()->json(['message' => 'Platform not found.'], 404);
        }

        return new PlatformResource($platform);
    }

    public function index(IndexPlatformRequest $request): AnonymousResourceCollection
    {
        $input = $request->validated();
        $query = Platform::query()->select(['id', 'code', 'name', 'is_active', 'created_at', 'updated_at']);

        if (isset($input['search']) && $input['search'] !== '') {
            // Use an explicit escape character, independent of MySQL backslash escaping modes.
            $pattern = '%' . strtr($input['search'], ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
            $query->where(function (Builder $query) use ($pattern): void {
                $query->whereRaw("code LIKE ? ESCAPE '!'", [$pattern])
                    ->orWhereRaw("name LIKE ? ESCAPE '!'", [$pattern]);
            });
        }

        if (array_key_exists('is_active', $input)) {
            $query->where('is_active', $input['is_active']);
        }

        $sort = $input['sort'] ?? 'id';
        $direction = $input['direction'] ?? 'desc';
        $query->orderBy($sort, $direction);

        if (! in_array($sort, ['id', 'code'], true)) {
            $query->orderBy('id', $direction);
        }

        return PlatformResource::collection(
            $query->paginate((int) ($input['per_page'] ?? 20), ['*'], 'page', (int) ($input['page'] ?? 1))
                ->appends($request->only(['per_page', 'sort', 'direction', 'search', 'is_active']))
        );
    }
}
