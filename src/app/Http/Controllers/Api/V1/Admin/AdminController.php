<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\IndexAdminRequest;
use App\Http\Requests\Api\V1\Admin\StoreAdminRequest;
use App\Http\Requests\Api\V1\Admin\UpdateAdminRequest;
use App\Http\Resources\Api\V1\Admin\AdminResource;
use App\Models\Admin;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;

class AdminController extends Controller
{
    public function update(UpdateAdminRequest $request): AdminResource
    {
        $admin = $request->targetAdmin();

        try {
            $admin->update($request->safe()->only(['username', 'name', 'is_active', 'password']));
        } catch (QueryException $exception) {
            if (
                (int) ($exception->errorInfo[1] ?? 0) !== 1062
                || preg_match("/for key ['`](?:[^'`]+\\.)?admins_username_unique['`]/", $exception->errorInfo[2] ?? '') !== 1
            ) {
                throw $exception;
            }

            throw ValidationException::withMessages(['username' => 'The username has already been taken.']);
        }

        return new AdminResource($admin);
    }

    public function store(StoreAdminRequest $request): JsonResponse
    {
        try {
            $admin = Admin::create($request->safe()->only(['username', 'name', 'password', 'is_active']));
        } catch (QueryException $exception) {
            // Match only MySQL duplicate-key errors for the existing username unique index.
            if (
                (int) ($exception->errorInfo[1] ?? 0) !== 1062
                || preg_match("/for key ['`](?:[^'`]+\\.)?admins_username_unique['`]/", $exception->errorInfo[2] ?? '') !== 1
            ) {
                throw $exception;
            }

            throw ValidationException::withMessages(['username' => 'The username has already been taken.']);
        }

        return (new AdminResource($admin))->response()->setStatusCode(201)
            ->header('Location', url('/api/v1/admin/admins/' . $admin->id));
    }

    public function show(string $id): AdminResource|JsonResponse
    {
        // Validate as a string to avoid integer overflow before querying an unsigned BIGINT.
        if (
            preg_match('/\A[1-9][0-9]*\z/', $id) !== 1
            || strlen($id) > 20
            || (strlen($id) === 20 && strcmp($id, '18446744073709551615') > 0)
        ) {
            return response()->json(['message' => 'Administrator not found.'], 404);
        }

        $admin = Admin::query()
            ->select(['id', 'username', 'name', 'is_active', 'created_at', 'updated_at'])
            ->find($id);

        if ($admin === null) {
            return response()->json(['message' => 'Administrator not found.'], 404);
        }

        return new AdminResource($admin);
    }

    public function index(IndexAdminRequest $request): AnonymousResourceCollection
    {
        $input = $request->validated();
        $query = Admin::query()->select(['id', 'username', 'name', 'is_active', 'created_at', 'updated_at']);

        if (isset($input['search']) && $input['search'] !== '') {
            // Use an explicit escape character, independent of MySQL backslash escaping modes.
            $pattern = '%' . strtr($input['search'], ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
            $query->where(function (Builder $query) use ($pattern): void {
                $query->whereRaw("username LIKE ? ESCAPE '!'", [$pattern])
                    ->orWhereRaw("name LIKE ? ESCAPE '!'", [$pattern]);
            });
        }

        if (array_key_exists('is_active', $input)) {
            $query->where('is_active', $input['is_active']);
        }

        $sort = $input['sort'] ?? 'id';
        $direction = $input['direction'] ?? 'desc';
        $query->orderBy($sort, $direction);

        if (! in_array($sort, ['id', 'username'], true)) {
            $query->orderBy('id', $direction);
        }

        return AdminResource::collection(
            $query->paginate((int) ($input['per_page'] ?? 20), ['*'], 'page', (int) ($input['page'] ?? 1))
                ->appends($request->only(['per_page', 'sort', 'direction', 'search', 'is_active']))
        );
    }
}
