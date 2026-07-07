<?php

declare(strict_types=1);

namespace App\Actions\Shares;

use App\Contracts\ShareStorageResolver;
use App\Models\Share;
use App\Support\TrashedEntry;

final readonly class TrashEntry
{
    public function __construct(private ShareStorageResolver $storage) {}

    /**
     * Send a file or folder to the share's recycle bin, recording who deleted it.
     */
    public function handle(Share $share, string $path, ?int $userId): TrashedEntry
    {
        return $this->storage->for($share)->trash($path, $userId);
    }
}
