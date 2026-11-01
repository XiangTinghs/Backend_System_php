<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\DestroyAdminRoleRequest;
use App\Http\Requests\Api\V1\Admin\StoreAdminRoleRequest;
use App\Models\Admin;
use App\Services\AuditLogService;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class AdminRoleController extends Controller
{
    public function __construct(private readonly AuditLogService $auditLogs)
    {
    }

    public function destroy(Request $request, string $id, string $role): Response
    {
        if (
            preg_match('/\A[1-9][0-9]*\z/', $id) !== 1
            || strlen($id) > 20
            || (strlen($id) === 20 && strcmp($id, '18446744073709551615') > 0)
        ) {
            return response()->json(['message' => 'Administrator not found.'], 404);
        }

        $connection = DB::connection();

        return $connection->transaction(function () use ($request, $id, $role, $connection): Response {
            $actor = $request->user('sanctum');
            // Use the same administrator lock order as assignment.
            $admins = Admin::on($connection->getName())->whereIn('id', [$actor->id, $id])->orderBy('id')->lockForUpdate()->get();
            $target = $admins->first(fn (Admin $admin): bool => (string) $admin->id === $id);
            $actor = $admins->firstWhere('id', $actor->id);

            if ($target === null) {
                return response()->json(['message' => 'Administrator not found.'], 404);
            }

            // Resolve validation only after the authorized target lookup, without a second query.
            app(DestroyAdminRoleRequest::class);

            if (
                $actor === null || ! $actor->is_active || $target->is($actor)
                || ! $actor->hasPermission('admins.roles.revoke')
                || in_array($role, config('rbac.protected_roles', []), true)
                || ! in_array($role, config('rbac.revocable_roles', []), true)
            ) {
                return response()->json(['message' => 'Forbidden.'], 403);
            }

            $assignment = ['admin_id' => $target->id, 'role' => $role];
            if (! $connection->table('admin_roles')->where($assignment)->lockForUpdate()->exists()) {
                return response()->json(['message' => 'Role assignment not found.'], 404);
            }

            $permissions = $connection->table('roles_permissions')->where('role', $role)->sharedLock()->pluck('permission');
            // Empty or unreviewed configurations cannot be safely authorized.
            if ($permissions->isEmpty()) {
                return response()->json(['message' => 'Forbidden.'], 403);
            }

            foreach ($permissions as $permission) {
                if (
                    ! in_array($permission, config('rbac.delegable_permissions', []), true)
                    || ! $actor->hasPermission($permission)
                ) {
                    return response()->json(['message' => 'Forbidden.'], 403);
                }
            }

            $before = $connection->table('admin_roles')->where('admin_id', $target->id)
                ->orderBy('role')->lockForUpdate()->pluck('role')->all();

            if ($connection->table('admin_roles')->where($assignment)->delete() !== 1) {
                throw new \RuntimeException('Unexpected role assignment deletion count.');
            }

            $after = $connection->table('admin_roles')->where('admin_id', $target->id)
                ->orderBy('role')->lockForUpdate()->pluck('role')->all();
            $this->auditLogs->record(
                $actor->id,
                'admin.role_revoked',
                'admin',
                $target->id,
                ['roles' => $before],
                ['roles' => $after],
                $request->ip(),
                $connection->getName()
            );

            return response()->noContent();
        });
    }

    public function store(Request $request, string $id): JsonResponse
    {
        if (
            preg_match('/\A[1-9][0-9]*\z/', $id) !== 1
            || strlen($id) > 20
            || (strlen($id) === 20 && strcmp($id, '18446744073709551615') > 0)
        ) {
            return response()->json(['message' => 'Administrator not found.'], 404);
        }

        $connection = DB::connection();

        return $connection->transaction(function () use ($request, $id, $connection): JsonResponse {
            $actor = $request->user('sanctum');
            // Serialize assignments involving the same administrators and recheck authorization.
            $admins = Admin::on($connection->getName())->whereIn('id', [$actor->id, $id])->orderBy('id')->lockForUpdate()->get();
            $target = $admins->first(fn (Admin $admin): bool => (string) $admin->id === $id);
            $actor = $admins->firstWhere('id', $actor->id);

            if ($target === null) {
                return response()->json(['message' => 'Administrator not found.'], 404);
            }

            // Container resolution preserves Laravel's Form Request lifecycle after target lookup.
            $role = app(StoreAdminRoleRequest::class)->validated('role');
            if (
                $actor === null || ! $actor->is_active || $target->is($actor)
                || ! $actor->hasPermission('admins.roles.assign')
                || in_array($role, config('rbac.protected_roles', []), true)
                || ! in_array($role, config('rbac.assignable_roles', []), true)
            ) {
                return response()->json(['message' => 'Forbidden.'], 403);
            }

            $permissions = $connection->table('roles_permissions')->where('role', $role)->sharedLock()->pluck('permission');
            if ($permissions->isEmpty()) {
                throw ValidationException::withMessages(['role' => 'The role has no configured permissions.']);
            }

            foreach ($permissions as $permission) {
                if (
                    ! in_array($permission, config('rbac.delegable_permissions', []), true)
                    || ! $actor->hasPermission($permission)
                ) {
                    return response()->json(['message' => 'Forbidden.'], 403);
                }
            }

            $assignment = ['admin_id' => $target->id, 'role' => $role];
            $before = $connection->table('admin_roles')->where('admin_id', $target->id)
                ->orderBy('role')->lockForUpdate()->pluck('role')->all();
            $created = false;
            if (! $connection->table('admin_roles')->where($assignment)->exists()) {
                try {
                    $connection->table('admin_roles')->insert($assignment);
                    $created = true;
                } catch (QueryException $exception) {
                    if (
                        (int) ($exception->errorInfo[1] ?? 0) !== 1062
                        || preg_match("/for key ['`](?:[^'`]+\\.)?admin_roles_admin_id_role_unique['`]/", $exception->errorInfo[2] ?? '') !== 1
                        || ! $connection->table('admin_roles')->where($assignment)->lockForUpdate()->exists()
                    ) {
                        throw $exception;
                    }
                }
            }

            if ($created) {
                $after = $connection->table('admin_roles')->where('admin_id', $target->id)
                    ->orderBy('role')->lockForUpdate()->pluck('role')->all();
                $this->auditLogs->record(
                    $actor->id,
                    'admin.role_assigned',
                    'admin',
                    $target->id,
                    ['roles' => $before],
                    ['roles' => $after],
                    $request->ip(),
                    $connection->getName()
                );
            }

            return response()->json(['data' => $assignment], $created ? 201 : 200);
        });
    }

    public function index(string $id): JsonResponse
    {
        if (
            preg_match('/\A[1-9][0-9]*\z/', $id) !== 1
            || strlen($id) > 20
            || (strlen($id) === 20 && strcmp($id, '18446744073709551615') > 0)
        ) {
            return response()->json(['message' => 'Administrator not found.'], 404);
        }

        $admin = DB::table('admins')->select('id')->where('id', $id)->first();

        if ($admin === null) {
            return response()->json(['message' => 'Administrator not found.'], 404);
        }

        $roles = DB::table('admin_roles')->where('admin_id', $admin->id)->orderBy('role')->pluck('role');

        return response()->json(['data' => ['admin_id' => $admin->id, 'roles' => $roles]]);
    }
}
