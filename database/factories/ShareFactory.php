<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Share;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * @extends Factory<Share>
 */
final class ShareFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->company();

        return [
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::random(6),
            'path' => mb_rtrim(Config::string('coffer.storage_path'), '/').'/'.Str::uuid()->toString(),
        ];
    }

    /**
     * Ensure each share's storage directory exists once it is created.
     */
    public function configure(): static
    {
        return $this->afterCreating(function (Share $share): void {
            File::ensureDirectoryExists($share->path);
        });
    }

    /**
     * Indicate that the share has been deleted.
     */
    public function trashed(): static
    {
        return $this->state(fn (array $attributes): array => [
            'deleted_at' => now(),
        ]);
    }

    /**
     * Root the share's storage at a specific local directory.
     */
    public function withPath(string $path): static
    {
        return $this->state(fn (array $attributes): array => [
            'path' => $path,
        ]);
    }
}
