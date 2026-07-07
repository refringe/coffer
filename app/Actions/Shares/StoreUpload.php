<?php

declare(strict_types=1);

namespace App\Actions\Shares;

use App\Contracts\ShareStorageResolver;
use App\Models\Share;
use App\Support\Entry;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Config;
use Throwable;

final readonly class StoreUpload
{
    public function __construct(private ShareStorageResolver $storage) {}

    /**
     * Stream an uploaded file into a directory of the share, resolving any name conflict with the requested strategy
     * (replace / keep_both / skip) and enforcing the configured maximum file size. A storage I/O failure (e.g. a full
     * or unwritable disk) is reported and surfaced as an `error` status rather than an unhandled 500.
     *
     * @return array{status: string, entry: Entry|null}
     */
    public function handle(Share $share, string $directory, UploadedFile $file, string $onConflict, ?int $userId = null): array
    {
        $maxFileSize = Config::integer('coffer.max_file_size');

        if ($maxFileSize > 0 && (int) $file->getSize() > $maxFileSize) {
            return ['status' => 'too_large', 'entry' => null];
        }

        try {
            $storage = $this->storage->for($share);
            $name = basename($file->getClientOriginalName());
            $target = $directory === '' ? $name : $directory.'/'.$name;

            if ($storage->exists($target)) {
                if ($onConflict === 'skip') {
                    return ['status' => 'skipped', 'entry' => null];
                }

                // A folder must never be destroyed by an uploaded file, so replacing one falls back to keeping both.
                if ($onConflict === 'replace' && ! $storage->isDirectory($target)) {
                    // Route the replaced file through the recycle bin so it stays recoverable, like every deletion.
                    $storage->trash($target, $userId);
                } else {
                    $name = $storage->uniqueName($directory, $name);
                }
            }

            return ['status' => 'completed', 'entry' => $storage->storeUpload($directory, $file, $name)];
        } catch (Throwable $throwable) {
            report($throwable);

            return ['status' => 'error', 'entry' => null];
        }
    }
}
