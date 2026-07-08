<?php

declare(strict_types=1);

use App\Enums\NodeType;
use App\Exceptions\UploadOffsetMismatchException;
use App\Exceptions\UploadOverflowException;
use App\Exceptions\UploadWriteConflictException;
use App\Services\LocalShareStorage;
use App\Support\Entry;
use App\Support\PendingUpload;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function (): void {
    $this->root = sys_get_temp_dir().'/coffer-fs-'.uniqid();
    $disk = Storage::build(['driver' => 'local', 'root' => $this->root, 'throw' => true]);
    $this->storage = new LocalShareStorage($disk);
});

afterEach(function (): void {
    if (is_dir($this->root)) {
        exec('rm -rf '.escapeshellarg($this->root));
    }
});

function seedStorageFile(string $root, string $path, string $contents = 'data'): void
{
    $absolute = $root.'/'.$path;

    @mkdir(dirname($absolute), 0777, true);
    file_put_contents($absolute, $contents);
}

test('reads a stored file back as an entry and lists it', function (): void {
    seedStorageFile($this->root, 'report.txt', 'hello');

    $entry = $this->storage->entry('report.txt');

    expect($entry)->toBeInstanceOf(Entry::class)
        ->and($entry->name)->toBe('report.txt')
        ->and($entry->type)->toBe(NodeType::File)
        ->and($entry->size)->toBe(5);

    $entries = $this->storage->entries();

    expect($entries)->toHaveCount(1)
        ->and($entries->first()->path)->toBe('report.txt');
});

test('makeDirectory then list shows folders before files', function (): void {
    $this->storage->makeDirectory('Photos');
    seedStorageFile($this->root, 'z.txt');

    $entries = $this->storage->entries();

    expect($entries)->toHaveCount(2)
        ->and($entries[0]->type)->toBe(NodeType::Folder)
        ->and($entries[0]->name)->toBe('Photos')
        ->and($entries[1]->type)->toBe(NodeType::File);
});

test('move renames a file within a directory', function (): void {
    seedStorageFile($this->root, 'old.txt', 'x');

    $this->storage->move('old.txt', 'new.txt');

    expect($this->storage->exists('old.txt'))->toBeFalse()
        ->and($this->storage->exists('new.txt'))->toBeTrue();
});

test('move relocates a folder and its contents', function (): void {
    $this->storage->makeDirectory('src');
    seedStorageFile($this->root, 'src/file.txt', 'x');
    $this->storage->makeDirectory('dest');

    $this->storage->move('src', 'dest/src');

    expect($this->storage->exists('dest/src/file.txt'))->toBeTrue()
        ->and($this->storage->exists('src'))->toBeFalse();
});

test('delete removes a file and a folder subtree', function (): void {
    seedStorageFile($this->root, 'a.txt');
    $this->storage->makeDirectory('dir');
    seedStorageFile($this->root, 'dir/b.txt');

    $this->storage->delete('a.txt');
    $this->storage->delete('dir');

    expect($this->storage->exists('a.txt'))->toBeFalse()
        ->and($this->storage->exists('dir'))->toBeFalse();
});

test('search finds files and folders by name recursively', function (): void {
    $this->storage->makeDirectory('reports');
    seedStorageFile($this->root, 'reports/annual-report.txt');
    seedStorageFile($this->root, 'notes.txt');

    $results = $this->storage->search('report');

    expect($results->pluck('name')->all())
        ->toContain('reports')
        ->toContain('annual-report.txt')
        ->not->toContain('notes.txt');
});

test('usage and fileCount sum browsable files only', function (): void {
    seedStorageFile($this->root, 'a.txt', '12345');
    seedStorageFile($this->root, 'b.txt', '12');
    seedStorageFile($this->root, 'c.txt', 'gone');
    $this->storage->trash('c.txt', null);

    expect($this->storage->usage())->toBe(7)
        ->and($this->storage->fileCount())->toBe(2);
});

test('usageStats reports file count and total bytes in one walk', function (): void {
    seedStorageFile($this->root, 'a.txt', '12345');
    seedStorageFile($this->root, 'b.txt', '12');

    expect($this->storage->usageStats())->toBe(['files' => 2, 'bytes' => 7]);
});

test('readStream returns null for a missing file instead of throwing', function (): void {
    expect($this->storage->readStream('does-not-exist.txt'))->toBeNull();
});

test('uniqueName disambiguates collisions', function (): void {
    seedStorageFile($this->root, 'file.txt');

    expect($this->storage->uniqueName('', 'file.txt'))->toBe('file (1).txt')
        ->and($this->storage->uniqueName('', 'fresh.txt'))->toBe('fresh.txt');
});

test('uniqueName keeps an extension-less dotfile name intact on collision', function (): void {
    seedStorageFile($this->root, '.gitignore', 'x');

    expect($this->storage->uniqueName('', '.gitignore'))->toBe('.gitignore (1)');
});

test('move refuses to overwrite a different existing destination', function (): void {
    seedStorageFile($this->root, 'a.txt', 'aaa');
    seedStorageFile($this->root, 'b.txt', 'bbb');

    expect(fn (): mixed => $this->storage->move('a.txt', 'b.txt'))
        ->toThrow(RuntimeException::class);

    // Neither file was destroyed by the aborted move.
    expect($this->storage->exists('a.txt'))->toBeTrue()
        ->and(file_get_contents($this->root.'/b.txt'))->toBe('bbb');
});

test('rejects control characters in paths', function (): void {
    $this->storage->exists("foo\x00bar");
})->throws(InvalidArgumentException::class);

test('reserved trash and tmp areas are hidden from listings', function (): void {
    seedStorageFile($this->root, 'visible.txt');
    $this->storage->trash('visible.txt', 7);

    expect($this->storage->entries())->toHaveCount(0)
        ->and($this->storage->trashed())->toHaveCount(1);
});

test('trash then restore returns the file to its original location', function (): void {
    $this->storage->makeDirectory('docs');
    seedStorageFile($this->root, 'docs/keep.txt', 'x');

    $trashed = $this->storage->trash('docs/keep.txt', 42);

    expect($trashed->originalPath)->toBe('docs/keep.txt')
        ->and($trashed->deletedBy)->toBe(42)
        ->and($this->storage->exists('docs/keep.txt'))->toBeFalse();

    $restored = $this->storage->restore($trashed->id);

    expect($restored?->path)->toBe('docs/keep.txt')
        ->and($this->storage->exists('docs/keep.txt'))->toBeTrue()
        ->and($this->storage->trashed())->toHaveCount(0);
});

test('restore falls back to root when the original parent is gone', function (): void {
    $this->storage->makeDirectory('gone');
    seedStorageFile($this->root, 'gone/orphan.txt', 'x');

    $trashed = $this->storage->trash('gone/orphan.txt', null);
    $this->storage->delete('gone');

    $restored = $this->storage->restore($trashed->id);

    expect($restored?->path)->toBe('orphan.txt');
});

test('purge permanently removes a trashed item', function (): void {
    seedStorageFile($this->root, 'temp.txt');
    $trashed = $this->storage->trash('temp.txt', null);

    $this->storage->purge($trashed->id);

    expect($this->storage->trashed())->toHaveCount(0)
        ->and($this->storage->restore($trashed->id))->toBeNull();
});

test('purgeTrashedBefore removes only items older than the cutoff', function (): void {
    seedStorageFile($this->root, 'old.txt');
    $trashed = $this->storage->trash('old.txt', null);

    expect($this->storage->purgeTrashedBefore(now()->subDay()))->toBe(0)
        ->and($this->storage->trashed())->toHaveCount(1);

    expect($this->storage->purgeTrashedBefore(now()->addDay()))->toBe(1)
        ->and($this->storage->trashed())->toHaveCount(0);
});

test('rejects directory traversal in paths', function (): void {
    $this->storage->exists('../escape.txt');
})->throws(InvalidArgumentException::class);

function pendingUploadFor(string $id, int $length = 10, string $onConflict = 'keep_both', string $directory = '', ?int $completedAt = null, int $userId = 7): PendingUpload
{
    return new PendingUpload(
        id: $id,
        userId: $userId,
        name: 'report.txt',
        directory: $directory,
        length: $length,
        onConflict: $onConflict,
        createdAt: now()->getTimestamp(),
        completedAt: $completedAt,
    );
}

/**
 * @return resource
 */
function uploadBodyStream(string $contents): mixed
{
    $stream = fopen('php://temp', 'r+b');
    fwrite($stream, $contents);
    rewind($stream);

    return $stream;
}

test('putPendingUpload writes a sidecar and an empty partial', function (): void {
    $id = Str::uuid()->toString();

    $this->storage->putPendingUpload(pendingUploadFor($id, length: 5));

    expect(file_exists($this->root.'/.tmp/uploads/'.$id))->toBeTrue()
        ->and($this->storage->uploadOffset($id))->toBe(0);

    $pending = $this->storage->pendingUpload($id);

    expect($pending)->not->toBeNull()
        ->and($pending->name)->toBe('report.txt')
        ->and($pending->length)->toBe(5)
        ->and($pending->userId)->toBe(7)
        ->and($pending->onConflict)->toBe('keep_both')
        ->and($pending->isCompleted())->toBeFalse();
});

test('pendingUpload returns null for a missing or corrupt sidecar', function (): void {
    expect($this->storage->pendingUpload(Str::uuid()->toString()))->toBeNull();

    $id = Str::uuid()->toString();
    mkdir($this->root.'/.tmp/uploads', 0777, true);
    file_put_contents($this->root.'/.tmp/uploads/'.$id.'.json', 'not-json');

    expect($this->storage->pendingUpload($id))->toBeNull();
});

test('putPendingUpload does not recreate the partial for a completed upload', function (): void {
    $id = Str::uuid()->toString();

    $this->storage->putPendingUpload(pendingUploadFor($id, length: 5, completedAt: now()->getTimestamp()));

    expect(file_exists($this->root.'/.tmp/uploads/'.$id))->toBeFalse()
        ->and($this->storage->pendingUpload($id)?->isCompleted())->toBeTrue();
});

test('uploadOffset is null without a partial and grows with appends', function (): void {
    $id = Str::uuid()->toString();

    expect($this->storage->uploadOffset($id))->toBeNull();

    $this->storage->putPendingUpload(pendingUploadFor($id, length: 10));
    $this->storage->appendUpload($id, uploadBodyStream('hello'), 0, 10);

    expect($this->storage->uploadOffset($id))->toBe(5);
});

test('appendUpload appends chunks and returns the new offset', function (): void {
    $id = Str::uuid()->toString();
    $this->storage->putPendingUpload(pendingUploadFor($id, length: 10));

    expect($this->storage->appendUpload($id, uploadBodyStream('hello'), 0, 10))->toBe(5)
        ->and($this->storage->appendUpload($id, uploadBodyStream('world'), 5, 5))->toBe(10)
        ->and(file_get_contents($this->root.'/.tmp/uploads/'.$id))->toBe('helloworld');
});

test('appendUpload rejects a mismatched offset and leaves the partial untouched', function (): void {
    $id = Str::uuid()->toString();
    $this->storage->putPendingUpload(pendingUploadFor($id, length: 10));
    $this->storage->appendUpload($id, uploadBodyStream('hi'), 0, 10);

    expect(fn (): int => $this->storage->appendUpload($id, uploadBodyStream('more'), 5, 5))
        ->toThrow(UploadOffsetMismatchException::class)
        ->and(file_get_contents($this->root.'/.tmp/uploads/'.$id))->toBe('hi');
});

test('appendUpload rolls back and refuses bytes past the declared length', function (): void {
    $id = Str::uuid()->toString();
    $this->storage->putPendingUpload(pendingUploadFor($id, length: 4));
    $this->storage->appendUpload($id, uploadBodyStream('ab'), 0, 4);

    expect(fn (): int => $this->storage->appendUpload($id, uploadBodyStream('cdEXTRA'), 2, 2))
        ->toThrow(UploadOverflowException::class)
        ->and(file_get_contents($this->root.'/.tmp/uploads/'.$id))->toBe('ab');
});

test('appendUpload refuses to write while another handle holds the lock', function (): void {
    $id = Str::uuid()->toString();
    $this->storage->putPendingUpload(pendingUploadFor($id, length: 10));

    $competing = fopen($this->root.'/.tmp/uploads/'.$id, 'rb');
    flock($competing, LOCK_EX);

    try {
        expect(fn (): int => $this->storage->appendUpload($id, uploadBodyStream('hi'), 0, 10))
            ->toThrow(UploadWriteConflictException::class);
    } finally {
        flock($competing, LOCK_UN);
        fclose($competing);
    }
});

test('uploadLastActivity tracks the partial and falls back to the sidecar', function (): void {
    $id = Str::uuid()->toString();
    $this->storage->putPendingUpload(pendingUploadFor($id, length: 5));

    touch($this->root.'/.tmp/uploads/'.$id, now()->subHours(3)->getTimestamp());

    expect($this->storage->uploadLastActivity($id))->toBe(now()->subHours(3)->getTimestamp());

    unlink($this->root.'/.tmp/uploads/'.$id);
    touch($this->root.'/.tmp/uploads/'.$id.'.json', now()->subHours(9)->getTimestamp());

    expect($this->storage->uploadLastActivity($id))->toBe(now()->subHours(9)->getTimestamp());

    $this->storage->deleteUpload($id);

    expect($this->storage->uploadLastActivity($id))->toBeNull();
});

test('deleteUpload removes the partial and the sidecar', function (): void {
    $id = Str::uuid()->toString();
    $this->storage->putPendingUpload(pendingUploadFor($id, length: 5));

    $this->storage->deleteUpload($id);

    expect(file_exists($this->root.'/.tmp/uploads/'.$id))->toBeFalse()
        ->and($this->storage->pendingUpload($id))->toBeNull();
});

test('purgeUploadsBefore sweeps stale uploads, orphan partials, and completed sidecars', function (): void {
    $stale = Str::uuid()->toString();
    $this->storage->putPendingUpload(pendingUploadFor($stale, length: 5));
    touch($this->root.'/.tmp/uploads/'.$stale, now()->subDays(3)->getTimestamp());
    touch($this->root.'/.tmp/uploads/'.$stale.'.json', now()->subDays(3)->getTimestamp());

    $fresh = Str::uuid()->toString();
    $this->storage->putPendingUpload(pendingUploadFor($fresh, length: 5));

    $orphan = Str::uuid()->toString();
    file_put_contents($this->root.'/.tmp/uploads/'.$orphan, 'bytes');
    touch($this->root.'/.tmp/uploads/'.$orphan, now()->subDays(3)->getTimestamp());

    $completed = Str::uuid()->toString();
    $this->storage->putPendingUpload(pendingUploadFor($completed, length: 5, completedAt: now()->subDays(3)->getTimestamp()));
    touch($this->root.'/.tmp/uploads/'.$completed.'.json', now()->subDays(3)->getTimestamp());

    expect($this->storage->purgeUploadsBefore(now()->subDay()))->toBe(3)
        ->and($this->storage->pendingUpload($stale))->toBeNull()
        ->and($this->storage->pendingUpload($fresh))->not->toBeNull()
        ->and(file_exists($this->root.'/.tmp/uploads/'.$orphan))->toBeFalse()
        ->and($this->storage->pendingUpload($completed))->toBeNull();
});
