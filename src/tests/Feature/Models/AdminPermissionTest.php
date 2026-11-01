<?php

namespace Tests\Feature\Models;

use App\Models\Admin;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AdminPermissionTest extends TestCase
{
    use DatabaseTransactions;

    public function test_single_role_grants_permission(): void
    {
        $admin = Admin::factory()->create();
        $this->assignRole($admin, 'viewer');
        $this->grantPermission('viewer', 'admins.read');

        $this->assertTrue($admin->hasPermission('admins.read'));
    }

    public function test_multiple_roles_grant_the_union_of_permissions(): void
    {
        $admin = Admin::factory()->create();
        $this->assignRole($admin, 'viewer');
        $this->assignRole($admin, 'editor');
        $this->grantPermission('viewer', 'admins.read');
        $this->grantPermission('editor', 'admins.update');
        $this->grantPermission('editor', 'admins.read');

        $this->assertTrue($admin->hasPermission('admins.read'));
        $this->assertTrue($admin->hasPermission('admins.update'));
        $this->assertFalse($admin->hasPermission('admins.delete'));
    }

    public function test_admin_without_roles_has_no_permission(): void
    {
        $other = Admin::factory()->create();
        $this->assignRole($other, 'viewer');
        $this->grantPermission('viewer', 'admins.read');

        $this->assertFalse(Admin::factory()->create()->hasPermission('admins.read'));
    }

    public function test_role_without_permissions_grants_nothing(): void
    {
        $admin = Admin::factory()->create();
        $this->assignRole($admin, 'empty');
        $this->grantPermission('unassigned', 'admins.read');

        $this->assertFalse($admin->hasPermission('admins.read'));
    }

    public function test_missing_and_unknown_permissions_are_denied(): void
    {
        $admin = Admin::factory()->create();
        $this->assignRole($admin, 'viewer');
        $this->grantPermission('viewer', 'admins.read');
        $this->grantPermission('editor', 'admins.update');

        $this->assertFalse($admin->hasPermission('admins.update'));
        $this->assertFalse($admin->hasPermission('unknown.permission'));
        $this->assertFalse($admin->hasPermission(''));
        $this->assertFalse($admin->hasPermission('Admins.Read'));
    }

    public function test_permission_and_role_changes_take_effect_immediately(): void
    {
        $admin = Admin::factory()->create();
        $this->assignRole($admin, 'viewer');
        $this->assertFalse($admin->hasPermission('admins.read'));

        $this->grantPermission('viewer', 'admins.read');
        $this->assertTrue($admin->hasPermission('admins.read'));

        DB::table('roles_permissions')->where('role', 'viewer')
            ->where('permission', 'admins.read')->delete();
        $this->assertFalse($admin->hasPermission('admins.read'));

        $this->grantPermission('viewer', 'admins.read');
        $this->assertTrue($admin->hasPermission('admins.read'));

        DB::table('admin_roles')->where('admin_id', $admin->id)->where('role', 'viewer')->delete();
        $this->assertFalse($admin->hasPermission('admins.read'));
    }

    public function test_unsaved_admin_has_no_permission(): void
    {
        $this->assertFalse((new Admin())->hasPermission('admins.read'));
    }

    private function assignRole(Admin $admin, string $role): void
    {
        DB::table('admin_roles')->insert(['admin_id' => $admin->id, 'role' => $role]);
    }

    private function grantPermission(string $role, string $permission): void
    {
        DB::table('roles_permissions')->insert(['role' => $role, 'permission' => $permission]);
    }
}
