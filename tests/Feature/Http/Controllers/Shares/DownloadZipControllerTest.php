<?php

declare(strict_types=1);

use App\Contracts\ShareStorageResolver;
use App\Http\Controllers\Shares\DownloadZipController;
use App\Jobs\BuildShareZip;
use App\Models\Share;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

beforeEach(function (): void {
    // The fresh in-memory database resets ids each test, but the array cache does not; clear it so a stale archive
    // token cannot leak between tests.
    Cache::flush();
});

/**
 * The relative paths of any built zip archives in a share's temporary area.
 *
 * @return array<int, string>
 */
function builtZips(Share $share): array
{
    $directory = $share->path.'/.tmp/zips';

    return File::isDirectory($directory) ? File::files($directory) : [];
}

test("the job zips a folder's descendant files preserving relative paths", function (): void {
    $share = Share::factory()->create();
    seedFile($share, 'Docs/a.txt', 'alpha');
    seedFile($share, 'Docs/b.txt', 'bravo');

    $key = new BuildShareZip($share->id, ['Docs'])->handle(resolve(ShareStorageResolver::class));

    expect(str_starts_with($key, '.tmp/zips/'))->toBeTrue();

    $local = $share->path.'/'.$key;

    expect(File::exists($local))->toBeTrue();

    $zip = new ZipArchive();
    $zip->open($local);

    $entries = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $entries[] = $zip->getNameIndex($i);
    }

    $zip->close();

    expect($entries)->toContain('Docs/a.txt')->toContain('Docs/b.txt');
});

test('a partial transfer file is excluded from a zipped folder', function (): void {
    $share = Share::factory()->create();
    seedFile($share, 'Docs/a.txt', 'alpha');
    seedFile($share, 'Docs/a.txt.partial', 'in-flight');

    $key = new BuildShareZip($share->id, ['Docs'])->handle(resolve(ShareStorageResolver::class));

    $zip = new ZipArchive();
    $zip->open($share->path.'/'.$key);

    $entries = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $entries[] = $zip->getNameIndex($i);
    }

    $zip->close();

    expect($entries)->toContain('Docs/a.txt')->not->toContain('Docs/a.txt.partial');
});

test('zipping an empty folder produces a valid, non-empty archive', function (): void {
    $share = Share::factory()->create();
    seedFolder($share, 'Empty');

    $key = new BuildShareZip($share->id, ['Empty'])->handle(resolve(ShareStorageResolver::class));

    $local = $share->path.'/'.$key;

    expect(File::exists($local))->toBeTrue()
        ->and(filesize($local))->toBeGreaterThan(0);

    $zip = new ZipArchive();

    expect($zip->open($local))->toBeTrue()
        ->and($zip->numFiles)->toBe(0);

    $zip->close();
});

test('a single selected file short-circuits to a direct download with no archive', function (): void {
    [$user, $share] = shareWithMember('viewer');
    seedFile($share, 'solo.txt', 'just me');

    $this->actingAs($user)
        ->get(route('shares.zip', ['share' => $share, 'paths' => ['solo.txt']]))
        ->assertOk()
        ->assertDownload('solo.txt');

    expect(builtZips($share))->toBeEmpty();
});

test('a folder selection builds and streams a zip', function (): void {
    [$user, $share] = shareWithMember('viewer');
    seedFile($share, 'Docs/a.txt', 'alpha');

    $this->actingAs($user)
        ->get(route('shares.zip', ['share' => $share, 'paths' => ['Docs']]))
        ->assertOk()
        ->assertDownload($share->slug.'.zip');

    expect(builtZips($share))->not->toBeEmpty();
});

test('a guest is redirected to login', function (): void {
    $share = Share::factory()->create();
    seedFile($share, 'a.txt', 'alpha');

    $this->get(route('shares.zip', ['share' => $share, 'paths' => ['a.txt']]))
        ->assertRedirect(route('login'));
});

test('a selection with no existing paths is rejected', function (): void {
    [$user, $share] = shareWithMember('viewer');

    $this->actingAs($user)
        ->get(route('shares.zip', ['share' => $share, 'paths' => ['ghost.txt']]))
        ->assertNotFound();
});

test('the job publishes the archive key to the cache when given a token', function (): void {
    $share = Share::factory()->create();
    seedFile($share, 'a.txt', 'alpha');
    seedFile($share, 'b.txt', 'bravo');

    $paths = ['a.txt', 'b.txt'];
    $token = DownloadZipController::tokenFor($share->id, $paths);

    $key = new BuildShareZip($share->id, $paths, $token)->handle(resolve(ShareStorageResolver::class));

    expect(Cache::get($token))->toBe($key);
});

test('the job aborts instead of silently omitting an unreadable file', function (): void {
    $share = Share::factory()->create();

    $storage = fakeShareStorage();
    $storage->shouldReceive('isDirectory')->andReturnFalse();
    $storage->shouldReceive('exists')->andReturnTrue();
    $storage->shouldReceive('readStream')->andReturnFalse();

    expect(fn (): string => new BuildShareZip($share->id, ['a.txt'])->handle(resolve(ShareStorageResolver::class)))
        ->toThrow(RuntimeException::class);
});

test('a failed build publishes no cache token', function (): void {
    $share = Share::factory()->create();
    $token = DownloadZipController::tokenFor($share->id, ['a.txt']);

    $storage = fakeShareStorage();
    $storage->shouldReceive('isDirectory')->andReturnFalse();
    $storage->shouldReceive('exists')->andReturnTrue();
    $storage->shouldReceive('readStream')->andReturnFalse();

    expect(fn (): string => new BuildShareZip($share->id, ['a.txt'], $token)->handle(resolve(ShareStorageResolver::class)))
        ->toThrow(RuntimeException::class);

    // The waiting request must never receive a token pointing at an archive that was never written.
    expect(Cache::get($token))->toBeNull();
});

test('a multi-file selection enqueues a build and shows the preparing page', function (): void {
    Queue::fake();

    [$user, $share] = shareWithMember('viewer');
    seedFile($share, 'a.txt', 'alpha');
    seedFile($share, 'b.txt', 'bravo');

    $this->actingAs($user)
        ->get(route('shares.zip', ['share' => $share, 'paths' => ['a.txt', 'b.txt']]))
        ->assertStatus(202)
        ->assertSee('Preparing');

    Queue::assertPushed(BuildShareZip::class);
});

test('an already built archive streams straight to the download', function (): void {
    [$user, $share] = shareWithMember('viewer');
    seedFile($share, 'a.txt', 'alpha');
    seedFile($share, 'b.txt', 'bravo');

    $paths = ['a.txt', 'b.txt'];
    $key = '.tmp/zips/'.Str::uuid()->toString().'.zip';
    seedFile($share, $key, 'PK');
    Cache::put(DownloadZipController::tokenFor($share->id, $paths), $key);

    $this->actingAs($user)
        ->get(route('shares.zip', ['share' => $share, 'paths' => ['a.txt', 'b.txt']]))
        ->assertOk()
        ->assertDownload($share->slug.'.zip');
});
