<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\ShareStorage;
use App\Enums\NodeType;
use App\Exceptions\UploadOffsetMismatchException;
use App\Exceptions\UploadOverflowException;
use App\Exceptions\UploadWriteConflictException;
use App\Support\Entry;
use App\Support\PendingUpload;
use App\Support\TrashedEntry;
use Carbon\CarbonInterface;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use InvalidArgumentException;
use League\Flysystem\UnableToReadFile;
use League\Flysystem\UnableToRetrieveMetadata;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\HeaderUtils;

/**
 * A share's storage backed by a real local directory tree. Every browser-facing path is relative to the share's own
 * root; the reserved `.trash` (recycle bin) and `.tmp` (built zip archives and in-progress uploads) directories live
 * alongside the browsable tree and are hidden from every listing, search, and usage walk.
 */
final readonly class LocalShareStorage implements ShareStorage
{
    private const string TRASH = '.trash';

    private const string ZIP_DIR = '.tmp/zips';

    public function __construct(private FilesystemAdapter $disk) {}

    /**
     * {@inheritDoc}
     */
    public function entries(string $directory = ''): Collection
    {
        $directory = $this->guard($directory);

        $folders = $this->strings($this->disk->directories($directory))
            ->reject(fn (string $path): bool => $this->isReserved($path))
            ->map(fn (string $path): Entry => $this->entryFor($path, true));

        $files = $this->strings($this->disk->files($directory))
            ->reject(fn (string $path): bool => $this->isReserved($path))
            ->map(fn (string $path): Entry => $this->entryFor($path, false));

        return $folders->concat($files)->values();
    }

    /**
     * {@inheritDoc}
     */
    public function search(string $term, int $limit = 100): Collection
    {
        if ($term === '') {
            return new Collection();
        }

        $matches = new Collection();

        foreach ($this->disk->getDriver()->listContents('', true) as $attributes) {
            $path = $attributes->path();
            if ($this->isReserved($path)) {
                continue;
            }

            if (mb_stripos(basename($path), $term) === false) {
                continue;
            }

            $matches->push($this->entryFor($path, $attributes->isDir()));
        }

        return $matches
            ->sortBy(fn (Entry $entry): int => $entry->isFolder() ? 0 : 1)
            ->take($limit)
            ->values();
    }

    /**
     * {@inheritDoc}
     */
    public function filesUnder(string $directory = ''): Collection
    {
        return $this->strings($this->disk->allFiles($this->guard($directory)))
            ->reject(fn (string $path): bool => $this->isReserved($path))
            ->values();
    }

    /**
     * {@inheritDoc}
     */
    public function folders(): Collection
    {
        return $this->strings($this->disk->allDirectories())
            ->reject(fn (string $path): bool => $this->isReserved($path))
            ->map(fn (string $path): Entry => $this->entryFor($path, true))
            ->values();
    }

    /**
     * {@inheritDoc}
     */
    public function usage(): int
    {
        return $this->filesUnder()->sum(fn (string $path): int => @filesize($this->disk->path($path)) ?: 0);
    }

    /**
     * {@inheritDoc}
     */
    public function fileCount(): int
    {
        return $this->filesUnder()->count();
    }

    /**
     * {@inheritDoc}
     */
    public function usageStats(): array
    {
        $files = $this->filesUnder();

        return [
            'files' => $files->count(),
            'bytes' => (int) $files->sum(fn (string $path): int => @filesize($this->disk->path($path)) ?: 0),
        ];
    }

    public function exists(string $path): bool
    {
        return file_exists($this->disk->path($this->guard($path)));
    }

    public function isDirectory(string $path): bool
    {
        return is_dir($this->disk->path($this->guard($path)));
    }

    public function entry(string $path): ?Entry
    {
        $path = $this->guard($path);
        $absolute = $this->disk->path($path);

        if (! file_exists($absolute)) {
            return null;
        }

        return $this->entryFor($path, is_dir($absolute));
    }

    public function uniqueName(string $directory, string $name): string
    {
        $directory = $this->guard($directory);

        if (! $this->exists($this->join($directory, $name))) {
            return $name;
        }

        $info = pathinfo($name);
        $base = $info['filename'];
        $extension = isset($info['extension']) ? '.'.$info['extension'] : '';

        // pathinfo() treats a leading-dot name like ".gitignore" as all-extension with an empty base; fall back to the
        // whole name so the variant reads ".gitignore (1)" rather than " (1).gitignore".
        if ($base === '') {
            $base = $name;
            $extension = '';
        }

        $counter = 1;

        do {
            $candidate = $base.' ('.$counter.')'.$extension;
            $counter++;
        } while ($this->exists($this->join($directory, $candidate)));

        return $candidate;
    }

    public function makeDirectory(string $path): void
    {
        $this->disk->makeDirectory($this->guard($path));
    }

    public function move(string $from, string $to): void
    {
        $from = $this->guard($from);
        $to = $this->guard($to);

        $fromPath = $this->disk->path($from);
        $toPath = $this->disk->path($to);

        $this->ensureParent($to);

        // @rename silently overwrites an existing destination, so a different entry already at the target is refused to
        // avoid destroying it. A case-only rename on a case-insensitive filesystem resolves to the same file (equal
        // inode) and is allowed through.
        if (file_exists($toPath) && ! $this->isSameFile($fromPath, $toPath)) {
            throw new RuntimeException(sprintf('Could not move [%s] to [%s]: the destination already exists.', $from, $to));
        }

        if (! @rename($fromPath, $toPath)) {
            throw new RuntimeException(sprintf('Could not move [%s] to [%s].', $from, $to));
        }
    }

    public function delete(string $path): void
    {
        $path = $this->guard($path);

        if ($this->isDirectory($path)) {
            $this->disk->deleteDirectory($path);

            return;
        }

        $this->disk->delete($path);
    }

    /**
     * {@inheritDoc}
     */
    public function readStream(string $path): mixed
    {
        $path = $this->guard($path);

        try {
            return $this->disk->readStream($path);
        } catch (UnableToReadFile) {
            return null;
        }
    }

    /**
     * {@inheritDoc}
     */
    public function writeStream(string $path, mixed $resource): void
    {
        $path = $this->guard($path);

        $this->ensureParent($path);
        $this->disk->writeStream($path, $resource);
    }

    public function download(string $path, ?string $name = null): BinaryFileResponse
    {
        return $this->fileResponse($path, $name, HeaderUtils::DISPOSITION_ATTACHMENT);
    }

    public function stream(string $path, ?string $name = null): BinaryFileResponse
    {
        // Inline preview serves the file under the application's own origin. The sandbox policy stops an uploaded HTML
        // or SVG file from executing scripts when opened directly, and nosniff stops the browser from re-typing it.
        $headers = [
            'Content-Security-Policy' => 'sandbox',
            'X-Content-Type-Options' => 'nosniff',
        ];

        return $this->fileResponse($path, $name, HeaderUtils::DISPOSITION_INLINE, $headers);
    }

    public function trash(string $path, ?int $userId): TrashedEntry
    {
        $path = $this->guard($path);
        $name = basename($path);
        $isDirectory = $this->isDirectory($path);

        $id = Str::uuid()->toString();
        $size = ($isDirectory ? $this->folderSize($path) : @filesize($this->disk->path($path))) ?: 0;
        $deletedAt = now()->getTimestamp();

        $this->makeDirectory(self::TRASH.'/'.$id);
        $this->move($path, self::TRASH.'/'.$id.'/'.$name);

        $entry = new TrashedEntry(
            id: $id,
            name: $name,
            type: $isDirectory ? NodeType::Folder : NodeType::File,
            originalPath: $path,
            size: $size,
            deletedAt: $deletedAt,
            deletedBy: $userId,
        );

        $this->disk->put(self::TRASH.'/'.$id.'.json', (string) json_encode([
            'id' => $id,
            'name' => $name,
            'type' => $entry->type->value,
            'original_path' => $path,
            'size' => $size,
            'deleted_at' => $deletedAt,
            'deleted_by' => $userId,
        ]));

        return $entry;
    }

    /**
     * {@inheritDoc}
     */
    public function trashed(): Collection
    {
        return $this->strings($this->disk->files(self::TRASH))
            ->filter(fn (string $path): bool => str_ends_with($path, '.json'))
            ->map(fn (string $path): ?TrashedEntry => $this->readSidecar($path))
            ->filter()
            ->sortByDesc(fn (TrashedEntry $entry): int => $entry->deletedAt)
            ->values();
    }

    public function restore(string $id): ?Entry
    {
        $sidecar = self::TRASH.'/'.$id.'.json';
        $meta = $this->readSidecar($sidecar);

        if (! $meta instanceof TrashedEntry) {
            return null;
        }

        $payload = self::TRASH.'/'.$id.'/'.$meta->name;

        if (! $this->exists($payload)) {
            $this->purge($id);

            return null;
        }

        $parent = $this->parentOf($meta->originalPath);
        $targetDirectory = ($parent !== '' && $this->isDirectory($parent)) ? $parent : '';
        $name = $this->uniqueName($targetDirectory, $meta->name);
        $destination = $this->join($targetDirectory, $name);

        $this->move($payload, $destination);
        $this->purge($id);

        return $this->entry($destination);
    }

    public function purge(string $id): void
    {
        $this->disk->deleteDirectory(self::TRASH.'/'.$id);
        $this->disk->delete(self::TRASH.'/'.$id.'.json');
    }

    public function purgeTrashedBefore(CarbonInterface $cutoff): int
    {
        $purged = 0;

        foreach ($this->trashed() as $entry) {
            if ($entry->deletedAt < $cutoff->getTimestamp()) {
                $this->purge($entry->id);
                $purged++;
            }
        }

        return $purged;
    }

    public function purgeTempBefore(CarbonInterface $cutoff): int
    {
        $purged = 0;

        foreach ($this->strings($this->disk->files(self::ZIP_DIR)) as $path) {
            if (@filemtime($this->disk->path($path)) < $cutoff->getTimestamp()) {
                $this->disk->delete($path);
                $purged++;
            }
        }

        return $purged;
    }

    public function putPendingUpload(PendingUpload $upload): void
    {
        $this->disk->makeDirectory(self::UPLOADS);

        // A completed upload's partial has already been promoted out of the temporary area; only a still-pending
        // upload gets its (empty) partial created here.
        $partial = $this->disk->path(self::UPLOADS.'/'.$upload->id);

        if ($upload->completedAt === null && ! file_exists($partial)) {
            touch($partial);
        }

        $this->disk->put(self::UPLOADS.'/'.$upload->id.'.json', (string) json_encode([
            'id' => $upload->id,
            'user_id' => $upload->userId,
            'name' => $upload->name,
            'directory' => $upload->directory,
            'length' => $upload->length,
            'on_conflict' => $upload->onConflict,
            'created_at' => $upload->createdAt,
            'completed_at' => $upload->completedAt,
        ]));
    }

    public function pendingUpload(string $id): ?PendingUpload
    {
        $path = self::UPLOADS.'/'.$id.'.json';

        if (! $this->disk->exists($path)) {
            return null;
        }

        $data = json_decode((string) $this->disk->get($path), true);

        if (! is_array($data)
            || ! is_string($data['id'] ?? null)
            || ! is_string($data['name'] ?? null)
            || ! is_numeric($data['user_id'] ?? null)
            || ! is_numeric($data['length'] ?? null)) {
            return null;
        }

        return new PendingUpload(
            id: $data['id'],
            userId: (int) $data['user_id'],
            name: $data['name'],
            directory: is_string($data['directory'] ?? null) ? $data['directory'] : '',
            length: (int) $data['length'],
            onConflict: is_string($data['on_conflict'] ?? null) ? $data['on_conflict'] : 'keep_both',
            createdAt: is_numeric($data['created_at'] ?? null) ? (int) $data['created_at'] : 0,
            completedAt: is_numeric($data['completed_at'] ?? null) ? (int) $data['completed_at'] : null,
        );
    }

    public function uploadOffset(string $id): ?int
    {
        $absolute = $this->disk->path(self::UPLOADS.'/'.$id);

        clearstatcache(true, $absolute);
        $size = @filesize($absolute);

        return $size === false ? null : $size;
    }

    public function uploadLastActivity(string $id): ?int
    {
        $partial = @filemtime($this->disk->path(self::UPLOADS.'/'.$id));

        if ($partial !== false) {
            return $partial;
        }

        $sidecar = @filemtime($this->disk->path(self::UPLOADS.'/'.$id.'.json'));

        return $sidecar === false ? null : $sidecar;
    }

    public function appendUpload(string $id, mixed $stream, int $offset, int $maxBytes): int
    {
        throw_unless(is_resource($stream), RuntimeException::class, 'The upload body stream is not readable.');

        throw_if($offset < 0, UploadOffsetMismatchException::class, 'The declared offset does not match the partial file.');

        $absolute = $this->disk->path(self::UPLOADS.'/'.$id);

        // 'c' opens for writing without truncating, so a retried chunk never wipes bytes already on disk.
        $handle = @fopen($absolute, 'cb');
        throw_unless(is_resource($handle), RuntimeException::class, 'The upload partial could not be opened.');

        try {
            // The lock is advisory and released automatically when the handle closes, so a crashed request can never
            // leave the upload permanently locked.
            throw_unless(flock($handle, LOCK_EX | LOCK_NB), UploadWriteConflictException::class, 'Another request is already writing to this upload.');

            clearstatcache(true, $absolute);
            $stats = fstat($handle);
            $size = is_array($stats) ? (int) $stats['size'] : 0;

            throw_unless($size === $offset, UploadOffsetMismatchException::class, 'The declared offset does not match the partial file.');

            fseek($handle, 0, SEEK_END);

            $written = 0;
            $remaining = $maxBytes;

            while ($remaining > 0 && ! feof($stream)) {
                $chunk = fread($stream, min(1_048_576, $remaining));

                if ($chunk === false || $chunk === '') {
                    break;
                }

                // Chunks are raw bytes, so lengths are measured in bytes ('8bit'), never in multibyte characters.
                $bytes = mb_strlen($chunk, '8bit');

                throw_unless(fwrite($handle, $chunk) === $bytes, RuntimeException::class, 'The upload chunk could not be written.');

                $written += $bytes;
                $remaining -= $bytes;
            }

            // Bytes remaining past the declared total length are refused and the partial rolled back to its pre-append
            // offset, so an overrunning client cannot grow an upload beyond the size it declared.
            $extra = ($remaining <= 0 && ! feof($stream)) ? fread($stream, 1) : '';

            if (is_string($extra) && $extra !== '') {
                fflush($handle);
                ftruncate($handle, $offset);

                throw new UploadOverflowException('The chunk carries more bytes than the upload declared.');
            }

            fflush($handle);

            return $offset + $written;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    public function deleteUpload(string $id): void
    {
        $this->disk->delete(self::UPLOADS.'/'.$id);
        $this->disk->delete(self::UPLOADS.'/'.$id.'.json');
    }

    public function purgeUploadsBefore(CarbonInterface $cutoff): int
    {
        $purged = 0;

        // A partial and its sidecar share an id, so stripping the sidecar suffix folds both into one upload; orphan
        // partials with no sidecar (and completed sidecars with no partial) are swept by the same walk.
        $ids = $this->strings($this->disk->files(self::UPLOADS))
            ->map(fn (string $path): string => basename($path, '.json'))
            ->unique()
            ->values();

        foreach ($ids as $id) {
            $activity = $this->uploadLastActivity($id);

            if ($activity !== null && $activity < $cutoff->getTimestamp()) {
                $this->deleteUpload($id);
                $purged++;
            }
        }

        return $purged;
    }

    /**
     * Build a file delivery response. The response streams the file from disk in small chunks and honors HTTP range
     * requests; when the request carries the X-Sendfile-Type and X-Accel-Mapping headers (set by the production web
     * server), it instead sends no body and names the file in an X-Accel-Redirect header for the web server to serve
     * directly.
     *
     * @param  array<string, string>  $headers
     */
    private function fileResponse(string $path, ?string $name, string $disposition, array $headers = []): BinaryFileResponse
    {
        $path = $this->guard($path);
        $name ??= basename($path);

        $response = new BinaryFileResponse($this->disk->path($path), 200, $headers, false);
        $response->setContentDisposition($disposition, $name, str_replace('%', '', Str::ascii($name)));

        return $response;
    }

    /**
     * Build an entry read-model for a relative path already known to exist.
     */
    private function entryFor(string $path, bool $isDirectory): Entry
    {
        $absolute = $this->disk->path($path);
        $modifiedAt = @filemtime($absolute) ?: 0;

        if ($isDirectory) {
            return new Entry(basename($path), NodeType::Folder, $path, null, null, $modifiedAt);
        }

        try {
            $mime = $this->disk->mimeType($path);
        } catch (UnableToRetrieveMetadata) {
            $mime = null;
        }

        return new Entry(
            name: basename($path),
            type: NodeType::File,
            path: $path,
            size: @filesize($absolute) ?: 0,
            mimeType: is_string($mime) ? $mime : null,
            modifiedAt: $modifiedAt,
        );
    }

    /**
     * Decode a recycle-bin sidecar manifest into a read-model, or null when it is missing or unreadable.
     */
    private function readSidecar(string $path): ?TrashedEntry
    {
        if (! $this->disk->exists($path)) {
            return null;
        }

        $data = json_decode((string) $this->disk->get($path), true);

        if (! is_array($data) || ! is_string($data['id'] ?? null) || ! is_string($data['name'] ?? null)) {
            return null;
        }

        $type = is_string($data['type'] ?? null) ? NodeType::tryFrom($data['type']) : null;
        $originalPath = is_string($data['original_path'] ?? null) ? $data['original_path'] : $data['name'];
        $size = is_numeric($data['size'] ?? null) ? (int) $data['size'] : null;
        $deletedAt = is_numeric($data['deleted_at'] ?? null) ? (int) $data['deleted_at'] : 0;
        $deletedBy = is_numeric($data['deleted_by'] ?? null) ? (int) $data['deleted_by'] : null;

        return new TrashedEntry(
            id: $data['id'],
            name: $data['name'],
            type: $type ?? NodeType::File,
            originalPath: $originalPath,
            size: $size,
            deletedAt: $deletedAt,
            deletedBy: $deletedBy,
        );
    }

    /**
     * Total size, in bytes, of every file beneath a folder.
     */
    private function folderSize(string $path): int
    {
        return (int) $this->strings($this->disk->allFiles($path))
            ->sum(fn (string $file): int => @filesize($this->disk->path($file)) ?: 0);
    }

    /**
     * Reduce a raw disk path array to a values-only collection of strings, so the adapter's loosely-typed array results
     * can be mapped/filtered with confidence.
     *
     * @param  array<mixed>  $items
     * @return Collection<int, string>
     */
    private function strings(array $items): Collection
    {
        return collect(array_values(array_filter($items, is_string(...))));
    }

    /**
     * Ensure the parent directory of a relative path exists.
     */
    private function ensureParent(string $path): void
    {
        $parent = $this->parentOf($path);

        if ($parent !== '') {
            $this->disk->makeDirectory($parent);
        }
    }

    /**
     * The parent directory of a relative path, or '' when it sits at the root.
     */
    private function parentOf(string $path): string
    {
        return str_contains($path, '/') ? Str::beforeLast($path, '/') : '';
    }

    /**
     * Join a directory and a name into a relative path.
     */
    private function join(string $directory, string $name): string
    {
        return $directory === '' ? $name : $directory.'/'.$name;
    }

    /**
     * Whether two absolute paths resolve to the same underlying file, so a case-only rename on a case-insensitive
     * filesystem is not mistaken for a collision.
     */
    private function isSameFile(string $a, string $b): bool
    {
        $inodeA = @fileinode($a);
        $inodeB = @fileinode($b);

        return $inodeA !== false && $inodeA === $inodeB;
    }

    /**
     * Whether a relative path lies within one of the reserved areas.
     */
    private function isReserved(string $path): bool
    {
        return array_any(self::RESERVED, fn (string $reserved): bool => $path === $reserved || str_starts_with($path, $reserved.'/'));
    }

    /**
     * Normalize a browser-supplied relative path and reject any traversal. The filesystem adapter confines operations
     * to the share root as a backstop, but rejecting `..`/empty segments here keeps paths well-formed.
     */
    private function guard(string $path): string
    {
        $path = mb_trim(str_replace('\\', '/', $path), '/');

        if ($path === '') {
            return '';
        }

        foreach (explode('/', $path) as $segment) {
            throw_if(
                in_array($segment, ['', '.', '..'], true) || preg_match('/[\x00-\x1F]/', $segment) === 1,
                InvalidArgumentException::class,
                'Invalid storage path.',
            );
        }

        return $path;
    }
}
