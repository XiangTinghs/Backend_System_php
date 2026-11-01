<?php

namespace Tests\Feature\Api\V1\Admin;

use App\Models\Admin;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AdminStoreTest extends TestCase
{
    use DatabaseTransactions;

    private const URL = '/api/v1/admin/admins';

    private function authenticate(bool $permission = true, bool $active = true): Admin
    {
        $admin = Admin::factory()->create(['is_active' => $active]);
        if ($permission) {
            $role = 'creator_' . $admin->id;
            DB::table('admin_roles')->insert(['admin_id' => $admin->id, 'role' => $role]);
            DB::table('roles_permissions')->insert(['role' => $role, 'permission' => 'admins.create']);
        }
        $this->withToken($admin->createToken('store-test')->plainTextToken);

        return $admin;
    }

    private function payload(): array
    {
        return [
            'username' => 'new.admin_01-test', 'name' => '  New Admin  ',
            'password' => 'secure-password123', 'password_confirmation' => 'secure-password123',
            'is_active' => true,
        ];
    }

    public function test_create_hashes_password_and_returns_only_public_data_without_roles_or_tokens(): void
    {
        $this->authenticate();
        $response = $this->postJson(self::URL, $this->payload())->assertCreated();
        $admin = Admin::findOrFail($response->json('data.id'));
        $response->assertHeader('Location', url(self::URL . '/' . $admin->id))
            ->assertExactJson(['data' => [
                'id' => $admin->id, 'username' => $admin->username, 'name' => 'New Admin',
                'is_active' => true, 'created_at' => $admin->created_at->toISOString(),
                'updated_at' => $admin->updated_at->toISOString(),
            ]]);
        $this->assertNotSame($this->payload()['password'], $admin->password);
        $this->assertTrue(Hash::check($this->payload()['password'], $admin->password));
        $this->assertDatabaseMissing('admin_roles', ['admin_id' => $admin->id]);
        $this->assertSame(0, $admin->tokens()->count());
    }

    public function test_duplicate_username_is_rejected_without_creating_data(): void
    {
        $this->authenticate();
        Admin::factory()->create(['username' => $this->payload()['username']]);
        $before = Admin::count();
        $this->postJson(self::URL, $this->payload())->assertUnprocessable()->assertJsonValidationErrors('username');
        $this->assertSame($before, Admin::count());
    }

    #[DataProvider('invalidInputs')]
    public function test_invalid_inputs_create_no_data(string $field, mixed $value): void
    {
        $this->authenticate();
        $payload = $this->payload();
        $payload[$field] = $value;
        $before = Admin::count();
        $this->postJson(self::URL, $payload)->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->assertSame($before, Admin::count());
    }

    public static function invalidInputs(): array
    {
        return [
            ['username', null], ['username', str_repeat('a', 51)], ['username', '管理員'],
            ['username', 'bad account'], ['username', ['admin']], ['name', '   '],
            ['name', str_repeat('a', 101)], ['password', 'short'], ['password', null],
            ['password', str_repeat('a', 73)], ['password', str_repeat('密', 25)],
            ['password', "secure-password\0test"],
            ['password_confirmation', null], ['is_active', null], ['is_active', 'yes'],
        ];
    }

    #[DataProvider('forbiddenFields')]
    public function test_unknown_fields_are_rejected_even_when_empty(string $field, mixed $value): void
    {
        $this->authenticate();
        $before = Admin::count();
        $this->postJson(self::URL, array_merge($this->payload(), [$field => $value]))
            ->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->assertSame($before, Admin::count());
    }

    public static function forbiddenFields(): array
    {
        return [['id', 1], ['role', 'root'], ['roles', []], ['permissions', null], ['admin_id', 1], ['unexpected', 'x']];
    }

    public function test_required_fields_are_validated(): void
    {
        $this->authenticate();
        $before = Admin::count();
        $this->postJson(self::URL, [])->assertUnprocessable()
            ->assertJsonValidationErrors(['username', 'name', 'password', 'password_confirmation', 'is_active']);
        $this->assertSame($before, Admin::count());
    }

    public function test_bcrypt_byte_limit_and_inactive_creation_are_supported(): void
    {
        $this->authenticate();
        $payload = $this->payload();
        $payload['password'] = $payload['password_confirmation'] = str_repeat('密', 24);
        $payload['is_active'] = false;
        $response = $this->postJson(self::URL, $payload)->assertCreated()->assertJsonPath('data.is_active', false);
        $this->assertTrue(Hash::check($payload['password'], Admin::findOrFail($response->json('data.id'))->password));
    }

    public function test_password_confirmation_failure_is_reported_on_password(): void
    {
        $this->authenticate();
        $payload = $this->payload();
        $payload['password_confirmation'] = 'different-password';
        $this->postJson(self::URL, $payload)->assertUnprocessable()->assertJsonValidationErrors('password');
    }

    public function test_missing_and_invalid_tokens_create_no_data(): void
    {
        $before = Admin::count();
        $this->postJson(self::URL, $this->payload())->assertUnauthorized()->assertExactJson(['message' => 'Unauthenticated.']);
        app('auth')->forgetGuards();
        $this->withToken('invalid')->postJson(self::URL, $this->payload())->assertUnauthorized()
            ->assertExactJson(['message' => 'Unauthenticated.']);
        $this->assertSame($before, Admin::count());
    }

    public function test_missing_permission_creates_no_data(): void
    {
        $this->authenticate(false);
        $before = Admin::count();
        $this->postJson(self::URL, $this->payload())->assertForbidden()->assertExactJson(['message' => 'Forbidden.']);
        $this->assertSame($before, Admin::count());
    }

    public function test_disabled_creator_creates_no_data(): void
    {
        $this->authenticate(true, false);
        $before = Admin::count();
        $this->postJson(self::URL, $this->payload())->assertForbidden()
            ->assertExactJson(['message' => 'Administrator account is disabled.']);
        $this->assertSame($before, Admin::count());
    }

    public function test_username_unique_race_returns_validation_error(): void
    {
        $this->authenticate();
        $dispatcher = Admin::getEventDispatcher();
        Admin::setEventDispatcher(clone $dispatcher);
        try {
            Admin::creating(function (Admin $admin): void {
                DB::table('admins')->insert([
                    'username' => $admin->username, 'name' => 'Concurrent winner',
                    'password' => $admin->password, 'is_active' => true,
                ]);
            });
            $before = Admin::count();
            $this->postJson(self::URL, $this->payload())->assertUnprocessable()->assertJsonValidationErrors('username');
            $this->assertSame($before + 1, Admin::count());
        } finally {
            Admin::setEventDispatcher($dispatcher);
        }
    }

    public function test_unrelated_database_errors_are_not_converted_to_validation_errors(): void
    {
        $this->authenticate();
        $dispatcher = Admin::getEventDispatcher();
        Admin::setEventDispatcher(clone $dispatcher);
        $before = Admin::count();
        try {
            Admin::creating(function (Admin $admin): void {
                $admin->name = null;
            });
            $this->withoutExceptionHandling();
            try {
                $this->postJson(self::URL, $this->payload());
                $this->fail('Expected the database error to propagate.');
            } catch (QueryException $exception) {
                $this->assertSame(1048, (int) $exception->errorInfo[1]);
            }
            $this->assertSame($before, Admin::count());
        } finally {
            Admin::setEventDispatcher($dispatcher);
        }
    }
}
