<?php

namespace Database\Factories;

use App\Models\Platform;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Platform> */
class PlatformFactory extends Factory
{
    public function definition(): array
    {
        return [
            'code' => fake()->unique()->regexify('[a-z]{20}'),
            'name' => fake()->company(),
            'is_active' => true,
        ];
    }
}
