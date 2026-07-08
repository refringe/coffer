<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Exceptions\UploadOffsetMismatchException;
use App\Exceptions\UploadOverflowException;
use App\Exceptions\UploadWriteConflictException;
use App\Support\Entry;
use App\Support\PendingUpload;
use App\Support\TrashedEntry;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * A share's storage, backed by a real local directory tree of which the share is the sole writer. Every path is
 * relative to the share's own root; the reserved `.trash` and `.tmp` directories are excluded from all browsing,
 * search, and usage operations.
 */
interface ShareStorage
{
    /**
     * Top-level directory names that hold internal state and are never browsable, searchable, or addressable from a
     * browser-supplied path.
     *
     * @var list<string>
     */
    public const array RESERVED = ['.trash', '.tmp'];

    /**
     * Directory beneath the reserved temporary area where in-progress resumable uploads (partial files and their
     * sidecar manifests) are kept.
     */
    public const string UPLOADS = '.tmp/uploads';

    /**
     * List the immediate folder and file entries within a directory (the share root when empty), excluding the reserved
     * areas.
     *
     * @return Collection<int, Entry>
     */
    public function entries(string $directory = ''): Collection;

    /**
     * Recursively find folders and files whose name contains the term.
     *
     * @return Collection<int, Entry>
     */
    public function search(string $term, int $limit = 100): Collection;

    /**
     * Relative paths of every browsable file beneath a directory, recursively.
     *
     * @return Collection<int, string>
     */
    public function filesUnder(string $directory = ''): Collection;

    /**
     * Every browsable folder in the share, recursively (e.g. for move targets).
     *
     * @return Collection<int, Entry>
     */
    public function folders(): Collection;

    /**
     * Total size, in bytes, of every browsable file in the share.
     */
    public function usage(): int;

    /**
     * Number of browsable files in the share.
     */
    public function fileCount(): int;

    /**
     * The number of browsable files in the share and their total size in bytes, computed in a single walk.
     *
     * @return array{files: int, bytes: int}
     */
    public function usageStats(): array;

    /**
     * Determine if a file or folder exists at the path.
     */
    public function exists(string $path): bool;

    /**
     * Determine if the path is a directory.
     */
    public function isDirectory(string $path): bool;

    /**
     * Read a single entry's metadata, or null when it does not exist.
     */
    public function entry(string $path): ?Entry;

    /**
     * Return the given name, or a "name (n)" variant, that does not collide with an existing entry in the target
     * directory.
     */
    public function uniqueName(string $directory, string $name): string;

    /**
     * Create a directory (and any missing parents).
     */
    public function makeDirectory(string $path): void;

    /**
     * Move (or rename) a file or folder to a new relative path.
     */
    public function move(string $from, string $to): void;

    /**
     * Permanently delete a file or folder (recursively for a folder).
     */
    public function delete(string $path): void;

    /**
     * Open a read stream for a file, or null when it does not exist.
     *
     * @return resource|null
     */
    public function readStream(string $path): mixed;

    /**
     * Write a file from a stream resource.
     *
     * @param  resource  $resource
     */
    public function writeStream(string $path, mixed $resource): void;

    /**
     * Build an attachment download response for a file.
     */
    public function download(string $path, ?string $name = null): BinaryFileResponse;

    /**
     * Build an inline (preview) response for a file.
     */
    public function stream(string $path, ?string $name = null): BinaryFileResponse;

    /**
     * Send a file or folder to the recycle bin, recording who deleted it.
     */
    public function trash(string $path, ?int $userId): TrashedEntry;

    /**
     * List the items currently in the recycle bin, most recently deleted first.
     *
     * @return Collection<int, TrashedEntry>
     */
    public function trashed(): Collection;

    /**
     * Restore a trashed item to its original location (falling back to the share root, with a unique name on
     * collision). Returns the restored entry, or null when the trashed item no longer exists.
     */
    public function restore(string $id): ?Entry;

    /**
     * Permanently remove a single item from the recycle bin.
     */
    public function purge(string $id): void;

    /**
     * Permanently remove every recycle-bin item deleted before the cutoff, returning the number purged.
     */
    public function purgeTrashedBefore(CarbonInterface $cutoff): int;

    /**
     * Remove built zip archives under the temporary area older than the cutoff, returning the number removed.
     */
    public function purgeTempBefore(CarbonInterface $cutoff): int;

    /**
     * Record (create or update) a pending upload's sidecar manifest, creating its empty partial file when the upload is
     * not yet completed.
     */
    public function putPendingUpload(PendingUpload $upload): void;

    /**
     * Read a pending upload's sidecar manifest, or null when it is missing or unreadable.
     */
    public function pendingUpload(string $id): ?PendingUpload;

    /**
     * The byte offset (current size) of a pending upload's partial file, or null when the partial does not exist.
     */
    public function uploadOffset(string $id): ?int;

    /**
     * The absolute filesystem path of a pending upload's partial file.
     */
    public function uploadPath(string $id): string;

    /**
     * The Unix timestamp of a pending upload's last write activity (the partial's mtime, falling back to the sidecar's
     * mtime), or null when neither file exists.
     */
    public function uploadLastActivity(string $id): ?int;

    /**
     * Append at most $maxBytes from the stream to a pending upload's partial file under an exclusive lock, verifying
     * that the partial currently sits at $offset. Returns the new offset.
     *
     * @param  resource  $stream
     *
     * @throws UploadWriteConflictException
     * @throws UploadOffsetMismatchException
     * @throws UploadOverflowException
     */
    public function appendUpload(string $id, mixed $stream, int $offset, int $maxBytes): int;

    /**
     * Remove a pending upload's partial file and sidecar manifest.
     */
    public function deleteUpload(string $id): void;

    /**
     * Remove pending uploads whose last write activity predates the cutoff, returning the number purged.
     */
    public function purgeUploadsBefore(CarbonInterface $cutoff): int;
}
