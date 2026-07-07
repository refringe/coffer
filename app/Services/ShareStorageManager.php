<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\ShareStorage;
use App\Contracts\ShareStorageResolver;
use App\Models\Share;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;

final class ShareStorageManager implements ShareStorageResolver
{
    /**
     * Resolved storage instances, memoized by share root path so repeated resolutions (and cross-share purge commands)
     * never rebuild a disk per call.
     *
     * @var array<string, ShareStorage>
     */
    private array $resolved = [];

    /**
     * Resolve the storage backend rooted at the share's own directory.
     */
    public function for(Share $share): ShareStorage
    {
        return $this->resolved[$share->path] ??= new LocalShareStorage($this->disk($share->path));
    }

    /**
     * Build the on-demand local filesystem disk rooted at the given directory.
     */
    private function disk(string $root): FilesystemAdapter
    {
        /** @var FilesystemAdapter $disk */
        $disk = Storage::build([
            'driver' => 'local',
            'root' => $root,
            'throw' => true,
        ]);

        return $disk;
    }
}
