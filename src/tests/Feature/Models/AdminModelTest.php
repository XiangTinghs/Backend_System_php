<?php

namespace Tests\Feature\Models;

use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Database\QueryException;
use Tests\TestCase;

class AdminModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_create_admin(): void
    {
        $admin = Admin::factory()->create([
            'username' => 'admin001',
            'name' => '測試管理員',
        ]);

        $this->assertDatabaseHas('admins', [
            'id' => $admin->id,
            'username' => 'admin001',
            'name' => '測試管理員',
        ]);
    }

    public function test_password_is_hashed(): void
    {
        $admin = Admin::factory()->create([
            'password' => 'password123',
        ]);

        $this->assertNotEquals(
            'password123',
            $admin->password
        );

        $this->assertTrue(
            Hash::check('password123', $admin->password)
        );
    }

    public function test_username_must_be_unique(): void
    {
        Admin::factory()->create([
            'username' => 'admin001',
        ]);

        $this->expectException(QueryException::class);

        Admin::factory()->create([
            'username' => 'admin001',
        ]);
    }

    public function test_admin_can_be_inactive(): void
    {
        $admin = Admin::factory()->create([
            'is_active' => false,
        ]);

        $this->assertFalse($admin->is_active);
    }
}
