<?php

declare(strict_types=1);

namespace App\Actions\Shares;

use App\Contracts\ShareStorageResolver;
use App\Models\Share;
use App\Support\Entry;

final readonly class RestoreEntry
{
    public function __construct(private ShareStorageResolver $storage) {}

    /**
     * Restore a recycle-bin item to its original location (falling back to the share root, with a unique name on
     * collision). Returns the restored entry, or null when the trashed item no longer exists.
     */
    public function handle(Share $share, string $id): ?Entry
    {
        return $this->storage->for($share)->restore($id);
    }
}
