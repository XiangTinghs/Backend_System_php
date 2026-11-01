<?php

namespace Tests\Feature\Api\V1\Admin;

use App\Models\Admin;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AdminUpdateTest extends TestCase
{
    use DatabaseTransactions;

    private const URL = '/api/v1/admin/admins/';

    private function authenticate(bool $permission = true, bool $active = true): Admin
    {
        $admin = Admin::factory()->create(['is_active' => $active]);
        if ($permission) {
            $role = 'updater_' . $admin->id;
            DB::table('admin_roles')->insert(['admin_id' => $admin->id, 'role' => $role]);
            DB::table('roles_permissions')->insert(['role' => $role, 'permission' => 'admins.update']);
        }
        $this->withToken($admin->createToken('update-test')->plainTextToken);

        return $admin;
    }

    private function payload(Admin $target): array
    {
        return ['username' => $target->username, 'name' => '  Updated Name  ', 'is_active' => true];
    }

    public function test_update_preserves_password_and_exposes_only_public_fields(): void
    {
        $this->authenticate();
        $target = Admin::factory()->create();
        $old = $target->password;
        $response = $this->putJson(self::URL . $target->id, $this->payload($target))->assertOk();
        $target->refresh();
        $response->assertExactJson(['data' => [
            'id' => $target->id, 'username' => $target->username, 'name' => 'Updated Name',
            'is_active' => true, 'created_at' => $target->created_at->toISOString(),
            'updated_at' => $target->updated_at->toISOString(),
        ]]);
        $this->assertSame($old, $target->password);
        $this->assertSame(0, $target->tokens()->count());
        $this->assertDatabaseMissing('admin_roles', ['admin_id' => $target->id]);
    }

    public function test_password_change_uses_model_hashing(): void
    {
        $this->authenticate();
        $target = Admin::factory()->create();
        $payload = $this->payload($target) + ['password' => str_repeat('密', 24), 'password_confirmation' => str_repeat('密', 24)];
        $this->putJson(self::URL . $target->id, $payload)->assertOk();
        $this->assertTrue(Hash::check($payload['password'], $target->fresh()->password));
    }

    public function test_duplicate_username_does_not_change_target(): void
    {
        $actor = $this->authenticate();
        $target = Admin::factory()->create();
        $before = $target->fresh()->getAttributes();
        $payload = $this->payload($target);
        $payload['username'] = $actor->username;
        $this->putJson(self::URL . $target->id, $payload)->assertUnprocessable()->assertJsonValidationErrors('username');
        $this->assertSame($before, $target->fresh()->getAttributes());
    }

    #[DataProvider('invalidInputs')]
    public function test_invalid_input_preserves_database(string $field, mixed $value, string $error): void
    {
        $this->authenticate();
        $target = Admin::factory()->create();
        $before = $target->fresh()->getAttributes();
        $payload = $this->payload($target);
        $payload[$field] = $value;
        $this->putJson(self::URL . $target->id, $payload)->assertUnprocessable()->assertJsonValidationErrors($error);
        $this->assertSame($before, $target->fresh()->getAttributes());
    }

    public static function invalidInputs(): array
    {
        return [
            ['username', null, 'username'], ['username', str_repeat('a', 51), 'username'],
            ['username', '非ASCII', 'username'], ['username', 'bad space', 'username'],
            ['name', '  ', 'name'], ['name', str_repeat('a', 101), 'name'],
            ['is_active', null, 'is_active'], ['is_active', 'yes', 'is_active'],
            ['password', null, 'password'], ['password', '', 'password'],
            ['password', 'short', 'password'], ['password', str_repeat('a', 73), 'password'],
            ['password', str_repeat('密', 25), 'password'], ['password', "password1234\0", 'password'],
            ['password', 'missing-confirmation', 'password'],
            ['password_confirmation', 'orphan-confirmation', 'password_confirmation'],
            ['id', 99, 'id'], ['admin_id', 1, 'admin_id'], ['role', 'root', 'role'],
            ['roles', [], 'roles'], ['permissions', null, 'permissions'], ['other', 'x', 'other'],
        ];
    }

    public function test_put_requires_all_basic_fields(): void
    {
        $this->authenticate();
        $target = Admin::factory()->create();
        $before = $target->fresh()->getAttributes();
        $this->putJson(self::URL . $target->id, [])->assertUnprocessable()
            ->assertJsonValidationErrors(['username', 'name', 'is_active']);
        $this->assertSame($before, $target->fresh()->getAttributes());
    }

    public function test_password_confirmation_must_match(): void
    {
        $this->authenticate();
        $target = Admin::factory()->create();
        $before = $target->password;
        $this->putJson(self::URL . $target->id, $this->payload($target) + [
            'password' => 'secure-password123', 'password_confirmation' => 'different-password',
        ])->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->assertSame($before, $target->fresh()->password);
    }

    public function test_self_update_is_forbidden_even_with_permission(): void
    {
        $actor = $this->authenticate();
        $before = $actor->fresh()->getAttributes();
        $this->putJson(self::URL . $actor->id, $this->payload($actor))->assertForbidden()
            ->assertExactJson(['message' => 'Forbidden.']);
        $this->assertSame($before, $actor->fresh()->getAttributes());
    }

    #[DataProvider('invalidIds')]
    public function test_invalid_or_missing_id_returns_safe_404(string $id): void
    {
        $this->authenticate();
        $this->putJson(self::URL . rawurlencode($id), [])->assertNotFound()
            ->assertExactJson(['message' => 'Administrator not found.']);
    }

    public static function invalidIds(): array
    {
        return [['0'], ['-1'], ['1.5'], ['abc'], ['01'], ['18446744073709551616'], ['18446744073709551615']];
    }

    public function test_unauthenticated_and_invalid_tokens_cannot_change_data(): void
    {
        $target = Admin::factory()->create();
        $before = $target->fresh()->getAttributes();
        $this->putJson(self::URL . $target->id, $this->payload($target))->assertUnauthorized();
        app('auth')->forgetGuards();
        $this->withToken('invalid')->putJson(self::URL . $target->id, $this->payload($target))->assertUnauthorized()
            ->assertExactJson(['message' => 'Unauthenticated.']);
        $this->assertSame($before, $target->fresh()->getAttributes());
    }

    public function test_missing_permission_and_inactive_actor_are_forbidden(): void
    {
        $actor = $this->authenticate(false);
        $target = Admin::factory()->create();
        $before = $target->fresh()->getAttributes();
        $this->putJson(self::URL . $target->id, $this->payload($target))->assertForbidden()
            ->assertExactJson(['message' => 'Forbidden.']);
        $actor->update(['is_active' => false]);
        app('auth')->forgetGuards();
        $this->putJson(self::URL . $target->id, $this->payload($target))->assertForbidden()
            ->assertExactJson(['message' => 'Administrator account is disabled.']);
        $this->assertSame($before, $target->fresh()->getAttributes());
    }

    public function test_disabled_target_keeps_tokens_but_can_only_logout(): void
    {
        $this->authenticate();
        $target = Admin::factory()->create();
        $tokenA = $target->createToken('device-a');
        $tokenB = $target->createToken('device-b');
        $payload = $this->payload($target);
        $payload['is_active'] = false;
        $this->putJson(self::URL . $target->id, $payload)->assertOk()->assertJsonPath('data.is_active', false);
        $this->assertModelExists($tokenA->accessToken);
        $this->assertModelExists($tokenB->accessToken);
        app('auth')->forgetGuards();
        $this->withToken($tokenA->plainTextToken)->getJson('/api/v1/admin/me')->assertForbidden();
        app('auth')->forgetGuards();
        $this->withToken($tokenA->plainTextToken)->postJson('/api/v1/admin/logout')->assertOk()
            ->assertExactJson(['message' => 'Logout successful.']);
        $this->assertModelMissing($tokenA->accessToken);
        $this->assertModelExists($tokenB->accessToken);
        app('auth')->forgetGuards();
        $this->withToken($tokenA->plainTextToken)->getJson('/api/v1/admin/me')->assertUnauthorized();
    }

    public function test_unique_race_returns_422_without_changing_target(): void
    {
        $this->authenticate();
        $target = Admin::factory()->create();
        $before = $target->fresh()->getAttributes();
        $dispatcher = Admin::getEventDispatcher();
        Admin::setEventDispatcher(clone $dispatcher);
        try {
            Admin::updating(function (Admin $admin): void {
                DB::table('admins')->insert([
                    'username' => $admin->username, 'name' => 'Concurrent winner',
                    'password' => $admin->password, 'is_active' => true,
                ]);
            });
            $payload = $this->payload($target);
            $payload['username'] = 'race_new_username';
            $this->putJson(self::URL . $target->id, $payload)->assertUnprocessable()->assertJsonValidationErrors('username');
            $this->assertSame($before, $target->fresh()->getAttributes());
        } finally {
            Admin::setEventDispatcher($dispatcher);
        }
    }

    public function test_other_database_errors_are_not_swallowed(): void
    {
        $this->authenticate();
        $target = Admin::factory()->create();
        $before = $target->fresh()->getAttributes();
        $dispatcher = Admin::getEventDispatcher();
        Admin::setEventDispatcher(clone $dispatcher);
        try {
            Admin::updating(function (Admin $admin): void {
                $admin->name = null;
            });
            $this->withoutExceptionHandling();
            try {
                $this->putJson(self::URL . $target->id, $this->payload($target));
                $this->fail('Expected database error.');
            } catch (QueryException $exception) {
                $this->assertSame(1048, (int) $exception->errorInfo[1]);
            }
            $this->assertSame($before, $target->fresh()->getAttributes());
        } finally {
            Admin::setEventDispatcher($dispatcher);
        }
    }
}
