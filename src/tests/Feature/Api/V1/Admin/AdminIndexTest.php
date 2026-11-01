<?php

namespace Tests\Feature\Api\V1\Admin;

use App\Models\Admin;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AdminIndexTest extends TestCase
{
    use DatabaseTransactions;

    private const URL = '/api/v1/admin/admins';

    private function authenticate(bool $permission = true, bool $active = true): Admin
    {
        $admin = Admin::factory()->create(['is_active' => $active]);
        if ($permission) {
            $role = 'reader_' . $admin->id;
            DB::table('admin_roles')->insert(['admin_id' => $admin->id, 'role' => $role]);
            DB::table('roles_permissions')->insert(['role' => $role, 'permission' => 'admins.read']);
        }
        $this->withToken($admin->createToken('index-test')->plainTextToken);

        return $admin;
    }

    public function test_list_is_paginated_and_only_exposes_public_fields(): void
    {
        $this->authenticate();
        Admin::factory()->count(22)->create();
        $response = $this->getJson(self::URL)->assertOk()
            ->assertJsonCount(20, 'data')->assertJsonPath('meta.per_page', 20)
            ->assertJsonStructure(['data', 'links' => ['first', 'last', 'prev', 'next'], 'meta']);
        foreach ($response->json('data') as $row) {
            $this->assertEqualsCanonicalizing(
                ['id', 'username', 'name', 'is_active', 'created_at', 'updated_at'],
                array_keys($row)
            );
            $this->assertIsBool($row['is_active']);
        }
        $ids = array_column($response->json('data'), 'id');
        $sorted = $ids;
        rsort($sorted);
        $this->assertSame($sorted, $ids);
    }

    public function test_custom_page_and_page_size(): void
    {
        $this->authenticate();
        Admin::factory()->count(5)->create();
        $first = $this->getJson(self::URL . '?per_page=2&page=1')->assertOk()->assertJsonCount(2, 'data');
        app('auth')->forgetGuards();
        $second = $this->getJson(self::URL . '?per_page=2&page=2')->assertOk()
            ->assertJsonCount(2, 'data')->assertJsonPath('meta.current_page', 2)
            ->assertJsonPath('meta.per_page', 2);
        $this->assertSame([], array_intersect(array_column($first->json('data'), 'id'), array_column($second->json('data'), 'id')));
    }

    public function test_maximum_page_size_is_accepted(): void
    {
        $this->authenticate();
        $this->getJson(self::URL . '?per_page=100')->assertOk()->assertJsonPath('meta.per_page', 100);
    }

    public function test_search_matches_username_and_name(): void
    {
        $this->authenticate();
        $a = Admin::factory()->create(['username' => 'needle_account', 'name' => 'First']);
        $b = Admin::factory()->create(['username' => 'other_account', 'name' => 'needle Name']);
        $response = $this->getJson(self::URL . '?search=needle&sort=id&direction=asc')->assertOk();
        $this->assertSame([$a->id, $b->id], array_column($response->json('data'), 'id'));
    }

    #[DataProvider('literalSearches')]
    public function test_like_special_characters_are_literal(string $search): void
    {
        $this->authenticate();
        $match = Admin::factory()->create(['name' => 'literal' . $search . 'value']);
        Admin::factory()->create(['name' => 'literalXvalue']);
        $response = $this->getJson(self::URL . '?' . http_build_query(['search' => $search]))->assertOk();
        $this->assertSame([$match->id], array_column($response->json('data'), 'id'));
    }

    public static function literalSearches(): array
    {
        return [['%'], ['_'], ['!'], ['!%_']];
    }

    public function test_search_and_active_filter_are_grouped(): void
    {
        $this->authenticate();
        $match = Admin::factory()->create(['username' => 'filtermatch_active', 'is_active' => true]);
        $inactive = Admin::factory()->create(['name' => 'filtermatch', 'is_active' => false]);
        $response = $this->getJson(self::URL . '?search=filtermatch&is_active=1')->assertOk();
        $this->assertSame([$match->id], array_column($response->json('data'), 'id'));
        app('auth')->forgetGuards();
        $response = $this->getJson(self::URL . '?search=filtermatch&is_active=0')->assertOk();
        $this->assertSame([$inactive->id], array_column($response->json('data'), 'id'));
    }

    public function test_non_unique_sort_uses_id_as_tie_breaker(): void
    {
        $this->authenticate();
        $a = Admin::factory()->create(['name' => 'sortmatch']);
        $b = Admin::factory()->create(['name' => 'sortmatch']);
        foreach (['asc' => [$a->id, $b->id], 'desc' => [$b->id, $a->id]] as $direction => $expected) {
            app('auth')->forgetGuards();
            $response = $this->getJson(self::URL . '?search=sortmatch&sort=name&direction=' . $direction)->assertOk();
            $this->assertSame($expected, array_column($response->json('data'), 'id'));
        }
    }

    public function test_no_search_matches_returns_empty_paginated_collection(): void
    {
        $this->authenticate();
        $this->getJson(self::URL . '?search=nonexistent_unique_search')->assertOk()
            ->assertJsonCount(0, 'data')->assertJsonPath('meta.total', 0);
    }

    public function test_missing_token_is_unauthorized(): void
    {
        $this->getJson(self::URL)->assertUnauthorized()->assertExactJson(['message' => 'Unauthenticated.']);
    }

    public function test_invalid_and_revoked_tokens_are_unauthorized(): void
    {
        $admin = $this->authenticate();
        $this->withToken('invalid')->getJson(self::URL)->assertUnauthorized()
            ->assertExactJson(['message' => 'Unauthenticated.']);
        $token = $admin->createToken('revoked');
        $token->accessToken->delete();
        app('auth')->forgetGuards();
        $this->withToken($token->plainTextToken)->getJson(self::URL)->assertUnauthorized()
            ->assertExactJson(['message' => 'Unauthenticated.']);
    }

    public function test_no_permission_is_forbidden(): void
    {
        $this->authenticate(false);
        $this->getJson(self::URL)->assertForbidden()->assertExactJson(['message' => 'Forbidden.']);
    }

    public function test_disabled_admin_is_forbidden(): void
    {
        $this->authenticate(true, false);
        $this->getJson(self::URL)->assertForbidden()
            ->assertExactJson(['message' => 'Administrator account is disabled.']);
    }

    #[DataProvider('invalidParameters')]
    public function test_invalid_query_parameters_return_validation_errors(string $field, mixed $value): void
    {
        $this->authenticate();
        $this->getJson(self::URL . '?' . http_build_query([$field => $value]))
            ->assertUnprocessable()->assertJsonValidationErrors($field);
    }

    public static function invalidParameters(): array
    {
        return [
            ['page', 0], ['page', 'abc'], ['per_page', 0], ['per_page', 101],
            ['sort', 'password'], ['sort', ['id']], ['direction', 'invalid'],
            ['search', str_repeat('a', 101)], ['search', ['value']], ['is_active', 'true'],
        ];
    }
}
