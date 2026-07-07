<?php

declare(strict_types=1);

namespace App\Actions\Shares;

use App\Contracts\ShareStorageResolver;
use App\Models\Share;

final readonly class MoveEntry
{
    public function __construct(private ShareStorageResolver $storage) {}

    /**
     * Move an entry into another folder within the share (the share root when the destination is empty), returning its
     * new relative path. Returns null when the move is invalid: the destination is not a folder, or moving a folder
     * into itself or one of its own descendants.
     */
    public function handle(Share $share, string $path, string $destination): ?string
    {
        $storage = $this->storage->for($share);

        $name = basename($path);
        $target = $destination === '' ? $name : $destination.'/'.$name;

        if ($target === $path) {
            return $path;
        }

        if ($destination !== '' && ! $storage->isDirectory($destination)) {
            return null;
        }

        if ($destination === $path || str_starts_with($destination, $path.'/')) {
            return null;
        }

        if ($storage->exists($target)) {
            $name = $storage->uniqueName($destination, $name);
            $target = $destination === '' ? $name : $destination.'/'.$name;
        }

        $storage->move($path, $target);

        return $target;
    }
}
