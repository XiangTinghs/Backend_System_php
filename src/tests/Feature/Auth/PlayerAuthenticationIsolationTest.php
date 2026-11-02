<?php

namespace Tests\Feature\Auth;

use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class PlayerAuthenticationIsolationTest extends TestCase
{
    public function test_player_authentication_and_default_seed_are_not_provisioned(): void
    {
        $this->assertNull(config('auth.providers.users'));
        $this->assertNull(config('auth.guards.web'));
        $this->assertSame([], array_filter(config('auth.passwords')));
        $this->assertNull(config('auth.defaults.passwords'));
        $this->assertSame([], config('sanctum.guard'));
        $this->assertFalse(collect(Route::getRoutes())->contains(fn ($route) => $route->uri() === 'api/user'));
        $this->getJson('/api/user')->assertNotFound();

        // A default seed must not recreate local password/email users or fake platforms.
        $this->expectsDatabaseQueryCount(0);
        app(DatabaseSeeder::class)->run();
    }
}
