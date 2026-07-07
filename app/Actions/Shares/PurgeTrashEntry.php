<?php

declare(strict_types=1);

namespace App\Actions\Shares;

use App\Contracts\ShareStorageResolver;
use App\Models\Share;

final readonly class PurgeTrashEntry
{
    public function __construct(private ShareStorageResolver $storage) {}

    /**
     * Permanently remove a single item from the share's recycle bin.
     */
    public function handle(Share $share, string $id): void
    {
        $this->storage->for($share)->purge($id);
    }
}
