<?php

declare(strict_types=1);

namespace App\Actions\Shares;

use App\Models\Share;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\File;
use Throwable;

final class CreateShare
{
    /**
     * Create a new share rooted at its own local storage directory, ensuring the directory exists. The insert is
     * retried on a unique-constraint violation so a concurrent same-name create regenerates a distinct slug instead of
     * surfacing a 500.
     */
    public function handle(string $name, string $path): Share
    {
        File::ensureDirectoryExists($path);

        // SQLSTATE 23000 is the unique-violation code on MySQL/SQLite; 23505 is its PostgreSQL equivalent.
        return retry(3, fn (): Share => Share::query()->create([
            'name' => $name,
            'path' => $path,
        ]), 0, fn (Throwable $e): bool => $e instanceof QueryException && in_array($e->getCode(), ['23000', '23505'], true));
    }
}
