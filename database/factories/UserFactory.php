<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<User>
 */
final class UserFactory extends Factory
{
    /**
     * Define the model's default state: a regular, active GitHub-provisioned user.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'github_id' => (string) fake()->unique()->numberBetween(100_000, 9_999_999),
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'github_login' => fake()->unique()->userName(),
            'avatar_url' => fake()->imageUrl(),
            'email_verified_at' => now(),
            'is_admin' => false,
            'disabled_at' => null,
        ];
    }

    /**
     * Indicate that the user is a global administrator (a GitHub org owner).
     */
    public function admin(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_admin' => true,
        ]);
    }

    /**
     * Indicate that the user's account has been disabled.
     */
    public function disabled(): static
    {
        return $this->state(fn (array $attributes): array => [
            'disabled_at' => now(),
        ]);
    }
}
