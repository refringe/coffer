<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ActivityAction;
use App\Models\Activity;
use App\Models\Share;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Activity>
 */
final class ActivityFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'share_id' => Share::factory(),
            'user_id' => User::factory(),
            'action' => fake()->randomElement(ActivityAction::cases()),
            'subject' => fake()->word(),
            'path' => null,
            'metadata' => null,
        ];
    }
}
