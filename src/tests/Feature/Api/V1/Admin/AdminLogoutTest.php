<?php

namespace Tests\Feature\Api\V1\Admin;

use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminLogoutTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_logout(): void
    {
        $admin = Admin::factory()->create();

        $token = $admin->createToken('admin-token')->plainTextToken;

        $response = $this->withToken($token)
            ->postJson('/api/v1/admin/logout');

        $response->assertOk();

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_unauthenticated_user_cannot_logout(): void
    {
        $response = $this->postJson('/api/v1/admin/logout');

        $response->assertUnauthorized();
    }


    public function test_revoked_token_cannot_access_profile(): void
    {
        $admin = Admin::factory()->create();

        $token = $admin->createToken('admin-token')->plainTextToken;

        // 第一次請求：登出並撤銷 Token
        $this->withToken($token)
            ->postJson('/api/v1/admin/logout')
            ->assertOk();

        // 確認 Token 確實從資料庫刪除
        $this->assertDatabaseCount('personal_access_tokens', 0);

        // 清除測試中已快取的認證狀態
        app('auth')->forgetGuards();

        // 第二次請求：使用已撤銷的 Token
        $this->withToken($token)
            ->getJson('/api/v1/admin/me')
            ->assertUnauthorized();
    }


    public function test_logout_only_revokes_current_token(): void
    {
        $admin = Admin::factory()->create();

        // 模擬兩台裝置登入
        $tokenA = $admin->createToken('device-A')->plainTextToken;
        $tokenB = $admin->createToken('device-B')->plainTextToken;

        // 確認資料庫有兩組 Token
        $this->assertDatabaseCount('personal_access_tokens', 2);

        // 裝置 A 登出
        $this->withToken($tokenA)
            ->postJson('/api/v1/admin/logout')
            ->assertOk();

        // 登出後應只剩裝置 B 的 Token
        $this->assertDatabaseCount('personal_access_tokens', 1);

        // 清除測試認證狀態
        app('auth')->forgetGuards();

        // 裝置 A 的 Token 應失效
        $this->withToken($tokenA)
            ->getJson('/api/v1/admin/me')
            ->assertUnauthorized();

        app('auth')->forgetGuards();

        // 裝置 B 的 Token 應仍然有效
        $this->withToken($tokenB)
            ->getJson('/api/v1/admin/me')
            ->assertOk();
    }

}
