<?php

declare(strict_types=1);

namespace App\Actions\Shares\Uploads;

use App\Contracts\ShareStorage;
use App\Contracts\ShareStorageResolver;
use App\Enums\ActivityAction;
use App\Models\Activity;
use App\Models\Share;
use App\Models\User;
use App\Support\Entry;
use App\Support\PendingUpload;
use RuntimeException;

final readonly class FinalizeUpload
{
    public function __construct(private ShareStorageResolver $storage) {}

    /**
     * Promote a fully received pending upload into its target directory, resolving any name conflict with the upload's
     * requested strategy (replace / keep_both), recording the activity, and marking the sidecar completed. The sidecar
     * is kept (swept later by the stalled-upload purge) so a retried final chunk finds the upload already completed
     * instead of promoting a duplicate.
     */
    public function handle(Share $share, PendingUpload $upload, User $user): Entry
    {
        $storage = $this->storage->for($share);

        $name = $upload->name;
        $directory = $upload->directory;
        $target = $directory === '' ? $name : $directory.'/'.$name;

        if ($storage->exists($target)) {
            // A folder must never be destroyed by an uploaded file, so replacing one falls back to keeping both.
            if ($upload->onConflict === 'replace' && ! $storage->isDirectory($target)) {
                // Route the replaced file through the recycle bin so it stays recoverable, like every deletion.
                $storage->trash($target, $user->id);
            } else {
                $name = $storage->uniqueName($directory, $name);
            }
        }

        $destination = $directory === '' ? $name : $directory.'/'.$name;

        $storage->move(ShareStorage::UPLOADS.'/'.$upload->id, $destination);

        $entry = $storage->entry($destination);

        throw_unless($entry instanceof Entry, RuntimeException::class, 'The promoted upload could not be read back.');

        Activity::record($share, $user, ActivityAction::FileUploaded, $entry->name, $entry->path);

        $storage->putPendingUpload($upload->completed(now()->getTimestamp()));

        return $entry;
    }
}
