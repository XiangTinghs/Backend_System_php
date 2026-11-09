<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\IndexPlayerRequest;
use App\Http\Requests\Api\V1\Admin\UpdatePlayerStatusRequest;
use App\Http\Resources\Api\V1\Admin\PlayerResource;
use App\Models\User;
use App\Services\AuditLogService;
use App\Support\UnsignedBigInt;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PlayerController extends Controller
{
    private const FIELDS = ['id', 'platform_id', 'external_user_id', 'name', 'status', 'last_login_at', 'created_at', 'updated_at'];

    public function __construct(private readonly AuditLogService $auditLogs)
    {
    }

    public function index(IndexPlayerRequest $request): AnonymousResourceCollection
    {
        $input = $request->validated();
        $query = User::query()->withCasts(['id' => 'string'])->select(self::FIELDS);
        foreach (['platform_id', 'external_user_id', 'status'] as $field) {
            if (array_key_exists($field, $input)) {
                $query->where($field, $input[$field]);
            }
        }
        if (isset($input['search']) && $input['search'] !== '') {
            $pattern = '%' . strtr($input['search'], ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
            $query->where(function (Builder $query) use ($pattern): void {
                $query->whereRaw("external_user_id LIKE ? ESCAPE '!'", [$pattern])
                    ->orWhereRaw("name LIKE ? ESCAPE '!'", [$pattern]);
            });
        }
        $sort = $input['sort'] ?? 'id';
        $direction = $input['direction'] ?? 'desc';
        $query->orderBy($sort, $direction);
        if ($sort !== 'id') {
            $query->orderBy('id', $direction);
        }

        return PlayerResource::collection(
            $query->paginate((int) ($input['per_page'] ?? 20), ['*'], 'page', (int) ($input['page'] ?? 1))
                ->appends(array_diff_key($input, ['page' => true]))
        );
    }

    public function show(string $id): PlayerResource|JsonResponse
    {
        $player = UnsignedBigInt::valid($id)
            ? User::query()->withCasts(['id' => 'string'])->select(self::FIELDS)->find($id) : null;

        return $player === null ? response()->json(['message' => 'Player not found.'], 404) : new PlayerResource($player);
    }

    public function status(UpdatePlayerStatusRequest $request): PlayerResource|JsonResponse
    {
        $target = $request->targetPlayer();
        $connection = $target->getConnection();

        return $connection->transaction(function () use ($request, $target, $connection): PlayerResource|JsonResponse {
            $player = $target->newQuery()->withCasts(['id' => 'string'])->whereKey($target->getKey())->lockForUpdate()->first();
            if ($player === null) {
                return response()->json(['message' => 'Player not found.'], 404);
            }
            $before = $player->status;
            if (! in_array($before, [0, 1], true)) {
                return response()->json(['message' => 'Player status is not supported.'], 409);
            }
            $status = $request->validated('status');
            if ($before !== $status) {
                $player->status = $status;
                $player->save();
                $this->auditLogs->record(
                    $request->user('sanctum')->id,
                    $status === 1 ? 'player.enabled' : 'player.disabled',
                    'player',
                    $player->getKey(),
                    ['status' => $before],
                    ['status' => $status],
                    $request->ip(),
                    $connection->getName()
                );
            }

            return new PlayerResource($player);
        });
    }
}
