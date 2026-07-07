<?php

declare(strict_types=1);

use App\Models\Share;
use App\Services\ShareStorageManager;
use Illuminate\Support\Facades\File;

test("for builds a disk rooted at the share's own directory", function (): void {
    $share = Share::factory()->create();

    $storage = resolve(ShareStorageManager::class)->for($share);

    $resource = tmpfile();
    fwrite($resource, 'data');
    rewind($resource);
    $storage->writeStream('obj.txt', $resource);
    fclose($resource);

    expect($storage->exists('obj.txt'))->toBeTrue()
        ->and(File::exists($share->path.'/obj.txt'))->toBeTrue();
});

test('storage instances are memoized per share path', function (): void {
    $manager = resolve(ShareStorageManager::class);

    $path = testSharePath();
    $a = Share::factory()->withPath($path)->create();
    $sameAsA = Share::factory()->makeOne(['path' => $path]);
    $b = Share::factory()->create();

    expect($manager->for($a))->toBe($manager->for($sameAsA))
        ->and($manager->for($a))->not->toBe($manager->for($b));
});

test('different shares resolve to storage rooted at their own paths', function (): void {
    $manager = resolve(ShareStorageManager::class);

    $a = Share::factory()->create();
    $b = Share::factory()->create();

    $manager->for($a)->makeDirectory('only-a');

    expect(File::isDirectory($a->path.'/only-a'))->toBeTrue()
        ->and(File::isDirectory($b->path.'/only-a'))->toBeFalse();
});
