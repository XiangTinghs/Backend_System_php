<?php

namespace Database\Factories;

use App\Models\Platform;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<User> */
class UserFactory extends Factory
{
    public function definition(): array
    {
        return [
            'platform_id' => Platform::factory(),
            'external_user_id' => fake()->uuid(),
            'name' => null,
            'status' => 1,
            'last_login_at' => null,
        ];
    }
}
