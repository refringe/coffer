<?php

declare(strict_types=1);

namespace App\Actions\Shares;

use App\Contracts\ShareStorageResolver;
use App\Models\Share;
use Illuminate\Support\Str;

final readonly class RenameEntry
{
    public function __construct(private ShareStorageResolver $storage) {}

    /**
     * Rename a file or folder within its current directory, returning its new relative path.
     */
    public function handle(Share $share, string $path, string $name): string
    {
        $directory = str_contains($path, '/') ? Str::beforeLast($path, '/') : '';
        $destination = $directory === '' ? $name : $directory.'/'.$name;

        $this->storage->for($share)->move($path, $destination);

        return $destination;
    }
}
