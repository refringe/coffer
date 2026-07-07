<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Contracts\ShareStorage;
use App\Contracts\ShareStorageResolver;
use App\Models\Share;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;
use RuntimeException;
use ZipArchive;

final class BuildShareZip implements ShouldQueue
{
    use Queueable;

    /**
     * @param  array<int, string>  $paths  Relative paths of the selected files/folders.
     * @param  string|null  $cacheToken  When set (queued path), the resulting object key is published to the cache so the waiting request can pick it up.
     */
    public function __construct(
        public int $shareId,
        public array $paths,
        public ?string $cacheToken = null,
    ) {}

    /**
     * Build a zip of the requested files, store it under the temporary area, and return its relative path. When a cache
     * token is present the path is also published to the cache.
     */
    public function handle(ShareStorageResolver $manager): string
    {
        $storage = $manager->for(Share::withTrashed()->findOrFail($this->shareId));

        $zipPath = (string) tempnam(sys_get_temp_dir(), 'fczip');
        $tempSources = [];

        $zip = new ZipArchive();

        throw_if($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true, RuntimeException::class, 'Unable to create the zip archive.');

        try {
            foreach ($this->resolveFiles($storage) as $relativePath) {
                $source = $storage->readStream($relativePath);

                // A selected file that cannot be read or faithfully staged aborts the build, so the user never
                // receives an archive that silently omits or truncates files they expected.
                throw_unless(is_resource($source), RuntimeException::class, sprintf('Unable to read [%s] for the zip archive.', $relativePath));

                $sourcePath = (string) tempnam(sys_get_temp_dir(), 'fcsrc');
                $tempSources[] = $sourcePath;

                $destination = fopen($sourcePath, 'w');
                throw_unless(is_resource($destination), RuntimeException::class, sprintf('Unable to stage [%s] for the zip archive.', $relativePath));

                $copied = stream_copy_to_stream($source, $destination);

                fclose($destination);
                fclose($source);

                throw_if($copied === false, RuntimeException::class, sprintf('Unable to copy [%s] into the zip archive.', $relativePath));

                $zip->addFile($sourcePath, $relativePath);
            }

            if ($zip->count() === 0) {
                $zip->close();

                // Writes a valid empty archive (just the end-of-central-directory record) when no entries were added,
                // so the download is a well-formed zip rather than a 0-byte file.
                file_put_contents($zipPath, "PK\x05\x06".str_repeat("\x00", 18));
            } else {
                throw_if(! $zip->close(), RuntimeException::class, 'Unable to finalize the zip archive.');
            }

            $key = '.tmp/zips/'.Str::uuid()->toString().'.zip';
            $handle = fopen($zipPath, 'r');

            // Publishing a cache token for an archive that was never written would strand the waiting request forever,
            // so a read failure here aborts before any token is published.
            throw_unless(is_resource($handle), RuntimeException::class, 'Unable to read the built zip archive.');

            $storage->writeStream($key, $handle);
            fclose($handle);
        } finally {
            foreach ([...$tempSources, $zipPath] as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }
        }

        if ($this->cacheToken !== null) {
            $hours = Config::integer('coffer.zip_ttl_hours');

            // A retention of 0 (or less) disables expiry and keeps the archive indefinitely; otherwise the published
            // key lives exactly as long as the archive is retained on disk.
            Cache::put($this->cacheToken, $key, $hours > 0 ? now()->addHours($hours) : null);
        }

        return $key;
    }

    /**
     * Resolve the requested paths to a set of file paths, expanding directories to all of its descendant files.
     *
     * @return Collection<int, string>
     */
    private function resolveFiles(ShareStorage $storage): Collection
    {
        $files = new Collection();

        foreach ($this->paths as $path) {
            if ($storage->isDirectory($path)) {
                $files = $files->merge($storage->filesUnder($path));
            } elseif ($storage->exists($path)) {
                $files->push($path);
            }
        }

        return $files->unique()->values();
    }
}
