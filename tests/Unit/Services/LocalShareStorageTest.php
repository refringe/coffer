<?php

declare(strict_types=1);

use App\Enums\NodeType;
use App\Services\LocalShareStorage;
use App\Support\Entry;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

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

function upload(string $name, string $contents = 'data'): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, $contents);
}

test('stores an upload and lists it', function (): void {
    $entry = $this->storage->storeUpload('', upload('report.txt', 'hello'));

    expect($entry)->toBeInstanceOf(Entry::class)
        ->and($entry->name)->toBe('report.txt')
        ->and($entry->type)->toBe(NodeType::File)
        ->and($entry->size)->toBe(5);

    $entries = $this->storage->entries();

    expect($entries)->toHaveCount(1)
        ->and($entries->first()->path)->toBe('report.txt');
});

test('storeUpload uses the supplied name', function (): void {
    $entry = $this->storage->storeUpload('docs', upload('a.txt', 'x'), 'renamed.txt');

    expect($entry->path)->toBe('docs/renamed.txt')
        ->and($this->storage->exists('docs/renamed.txt'))->toBeTrue();
});

test('makeDirectory then list shows folders before files', function (): void {
    $this->storage->makeDirectory('Photos');
    $this->storage->storeUpload('', upload('z.txt'));

    $entries = $this->storage->entries();

    expect($entries)->toHaveCount(2)
        ->and($entries[0]->type)->toBe(NodeType::Folder)
        ->and($entries[0]->name)->toBe('Photos')
        ->and($entries[1]->type)->toBe(NodeType::File);
});

test('move renames a file within a directory', function (): void {
    $this->storage->storeUpload('', upload('old.txt', 'x'));

    $this->storage->move('old.txt', 'new.txt');

    expect($this->storage->exists('old.txt'))->toBeFalse()
        ->and($this->storage->exists('new.txt'))->toBeTrue();
});

test('move relocates a folder and its contents', function (): void {
    $this->storage->makeDirectory('src');
    $this->storage->storeUpload('src', upload('file.txt', 'x'));
    $this->storage->makeDirectory('dest');

    $this->storage->move('src', 'dest/src');

    expect($this->storage->exists('dest/src/file.txt'))->toBeTrue()
        ->and($this->storage->exists('src'))->toBeFalse();
});

test('delete removes a file and a folder subtree', function (): void {
    $this->storage->storeUpload('', upload('a.txt'));
    $this->storage->makeDirectory('dir');
    $this->storage->storeUpload('dir', upload('b.txt'));

    $this->storage->delete('a.txt');
    $this->storage->delete('dir');

    expect($this->storage->exists('a.txt'))->toBeFalse()
        ->and($this->storage->exists('dir'))->toBeFalse();
});

test('search finds files and folders by name recursively', function (): void {
    $this->storage->makeDirectory('reports');
    $this->storage->storeUpload('reports', upload('annual-report.txt'));
    $this->storage->storeUpload('', upload('notes.txt'));

    $results = $this->storage->search('report');

    expect($results->pluck('name')->all())
        ->toContain('reports')
        ->toContain('annual-report.txt')
        ->not->toContain('notes.txt');
});

test('usage and fileCount sum browsable files only', function (): void {
    $this->storage->storeUpload('', upload('a.txt', '12345'));
    $this->storage->storeUpload('', upload('b.txt', '12'));
    $this->storage->trash($this->storage->storeUpload('', upload('c.txt', 'gone'))->path, null);

    expect($this->storage->usage())->toBe(7)
        ->and($this->storage->fileCount())->toBe(2);
});

test('usageStats reports file count and total bytes in one walk', function (): void {
    $this->storage->storeUpload('', upload('a.txt', '12345'));
    $this->storage->storeUpload('', upload('b.txt', '12'));

    expect($this->storage->usageStats())->toBe(['files' => 2, 'bytes' => 7]);
});

test('readStream returns null for a missing file instead of throwing', function (): void {
    expect($this->storage->readStream('does-not-exist.txt'))->toBeNull();
});

test('uniqueName disambiguates collisions', function (): void {
    $this->storage->storeUpload('', upload('file.txt'));

    expect($this->storage->uniqueName('', 'file.txt'))->toBe('file (1).txt')
        ->and($this->storage->uniqueName('', 'fresh.txt'))->toBe('fresh.txt');
});

test('uniqueName keeps an extension-less dotfile name intact on collision', function (): void {
    $this->storage->storeUpload('', upload('.gitignore', 'x'));

    expect($this->storage->uniqueName('', '.gitignore'))->toBe('.gitignore (1)');
});

test('move refuses to overwrite a different existing destination', function (): void {
    $this->storage->storeUpload('', upload('a.txt', 'aaa'));
    $this->storage->storeUpload('', upload('b.txt', 'bbb'));

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
    $this->storage->storeUpload('', upload('visible.txt'));
    $this->storage->trash('visible.txt', 7);

    expect($this->storage->entries())->toHaveCount(0)
        ->and($this->storage->trashed())->toHaveCount(1);
});

test('trash then restore returns the file to its original location', function (): void {
    $this->storage->makeDirectory('docs');
    $this->storage->storeUpload('docs', upload('keep.txt', 'x'));

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
    $this->storage->storeUpload('gone', upload('orphan.txt', 'x'));

    $trashed = $this->storage->trash('gone/orphan.txt', null);
    $this->storage->delete('gone');

    $restored = $this->storage->restore($trashed->id);

    expect($restored?->path)->toBe('orphan.txt');
});

test('purge permanently removes a trashed item', function (): void {
    $this->storage->storeUpload('', upload('temp.txt'));
    $trashed = $this->storage->trash('temp.txt', null);

    $this->storage->purge($trashed->id);

    expect($this->storage->trashed())->toHaveCount(0)
        ->and($this->storage->restore($trashed->id))->toBeNull();
});

test('purgeTrashedBefore removes only items older than the cutoff', function (): void {
    $this->storage->storeUpload('', upload('old.txt'));
    $trashed = $this->storage->trash('old.txt', null);

    expect($this->storage->purgeTrashedBefore(now()->subDay()))->toBe(0)
        ->and($this->storage->trashed())->toHaveCount(1);

    expect($this->storage->purgeTrashedBefore(now()->addDay()))->toBe(1)
        ->and($this->storage->trashed())->toHaveCount(0);
});

test('rejects directory traversal in paths', function (): void {
    $this->storage->exists('../escape.txt');
})->throws(InvalidArgumentException::class);
