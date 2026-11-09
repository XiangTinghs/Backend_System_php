<?php

namespace App\Services;

use App\Models\Admin;
use App\Support\UnsignedBigInt;
use DomainException;
use Illuminate\Support\Facades\Hash;

class PlatformKeyPermissionService
{
    public const PERMISSIONS = ['platforms.keys.view', 'platforms.keys.register', 'platforms.keys.manage', 'platforms.keys.revoke'];

    public function __construct(private readonly AuditLogService $auditLogs)
    {
    }

    public function preview(string $actorId, string $role, string $permission): array
    {
        if (! UnsignedBigInt::valid($actorId) || ! in_array($permission, self::PERMISSIONS, true)
            || preg_match('/\A[a-z][a-z0-9_]{0,49}\z/', $role) !== 1
            || ! in_array($role, config('rbac.protected_roles', []), true)
            || ! in_array('security_admin', config('rbac.protected_roles', []), true)) {
            throw new DomainException('Specify a protected role, a valid actor ID and one platform key permission.');
        }
        $actor = Admin::find($actorId);
        $connection = (new Admin())->getConnection();
        if ($actor === null || ! $actor->is_active || ! $connection->table('admin_roles')
            ->where('admin_id', $actorId)->where('role', 'security_admin')->exists()) {
            throw new DomainException('The operator must be an active security_admin.');
        }
        $members = $connection->table('admin_roles')->join('admins', 'admins.id', '=', 'admin_roles.admin_id')
            ->where('admin_roles.role', $role)->orderBy('admins.id')->get(['admins.id', 'admins.is_active'])
            ->map(fn ($admin) => ['id' => (string) $admin->id, 'is_active' => (bool) $admin->is_active])->all();
        if ($members === []) {
            throw new DomainException('The protected role must already have explicitly provisioned administrators.');
        }

        return ['role' => $role, 'permission' => $permission, 'members' => $members,
            'granted' => $connection->table('roles_permissions')->where('role', $role)->where('permission', $permission)->exists()];
    }

    public function apply(
        string $action,
        string $actorId,
        string $role,
        string $permission,
        string $ticket,
        #[\SensitiveParameter] string $password,
        array $expected,
    ): bool {
        if (! in_array($action, ['grant', 'revoke'], true)
            || preg_match('/\A[A-Z][A-Z0-9]{1,15}-[1-9][0-9]{0,9}\z/', $ticket) !== 1) {
            throw new DomainException('Specify grant/revoke and a change ticket such as SEC-123.');
        }
        $this->preview($actorId, $role, $permission);
        $connection = (new Admin())->getConnection();

        return $connection->transaction(function () use ($action, $actorId, $role, $permission, $ticket, $password, $expected, $connection): bool {
            $ids = array_column($expected['members'] ?? [], 'id');
            $ids[] = $actorId;
            // Compatible with administrator mutations: lock administrator rows in ascending ID order first.
            Admin::on($connection->getName())->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();
            $connection->table('admin_roles')->whereIn('role', array_unique(['security_admin', $role]))
                ->orderBy('admin_id')->orderBy('role')->lockForUpdate()->get();
            $connection->table('roles_permissions')->where('role', $role)->orderBy('permission')->lockForUpdate()->get();
            $current = $this->preview($actorId, $role, $permission);
            $actor = Admin::on($connection->getName())->find($actorId);
            if ($current !== $expected) {
                throw new DomainException('Role membership or permission state changed; review a fresh preview.');
            }
            if (! Hash::check($password, $actor->password)) {
                throw new DomainException('Operator authentication failed.');
            }
            $grant = $action === 'grant';
            if ($current['granted'] === $grant) {
                return false;
            }
            $query = $connection->table('roles_permissions')->where('role', $role)->where('permission', $permission);
            if ($grant) {
                $connection->table('roles_permissions')->insert(['role' => $role, 'permission' => $permission]);
            } elseif ($query->delete() !== 1) {
                throw new DomainException('Unexpected permission mapping count.');
            }
            $base = ['role' => $role, 'permission' => $permission, 'ticket' => $ticket];
            $this->auditLogs->record(
                (int) $actor->id,
                $grant ? 'rbac.platform_key_permission_granted' : 'rbac.platform_key_permission_revoked',
                'role',
                null,
                $base + ['is_active' => ! $grant],
                $base + ['is_active' => $grant],
                null,
                $connection->getName()
            );

            return true;
        });
    }
}
