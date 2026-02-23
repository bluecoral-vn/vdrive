<?php

namespace Database\Factories;

use App\Models\File;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<\App\Models\Share>
 */
class ShareFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'file_id' => File::factory(),
            'shared_by' => User::factory(),
            'shared_with' => User::factory(),
            'permission' => fake()->randomElement(['view', 'edit']),
            'expires_at' => null,
        ];
    }
}
