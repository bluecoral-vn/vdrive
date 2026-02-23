<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected $model = User::class;

    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'password' => 'password',
            'status' => 'active',
            'token_version' => 0,
        ];
    }

    /**
     * Create a disabled user.
     */
    public function disabled(string $reason = 'Test disabled'): static
    {
        return $this->state(fn () => [
            'status' => 'disabled',
            'disabled_at' => now(),
            'disabled_reason' => $reason,
        ]);
    }
}
