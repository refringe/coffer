<?php

declare(strict_types=1);

namespace App\Actions\Shares;

use App\Contracts\ShareStorageResolver;
use App\Enums\NodeType;
use App\Models\Share;
use App\Support\Entry;

final readonly class CreateFolder
{
    public function __construct(private ShareStorageResolver $storage) {}

    /**
     * Create a new folder within the share, inside the given directory (the share root when empty). Returns the created
     * folder entry.
     */
    public function handle(Share $share, string $directory, string $name): Entry
    {
        $storage = $this->storage->for($share);

        $path = $directory === '' ? $name : $directory.'/'.$name;

        $storage->makeDirectory($path);

        return $storage->entry($path)
            ?? new Entry($name, NodeType::Folder, $path, null, null, now()->getTimestamp());
    }
}
