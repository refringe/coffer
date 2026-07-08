<?php

declare(strict_types=1);

use App\Contracts\ShareStorage;
use App\Contracts\ShareStorageResolver;
use App\Models\Share;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
*/

pest()->extend(TestCase::class)
    ->use(LazilyRefreshDatabase::class)
    ->beforeEach(function (): void {
        Str::createRandomStringsNormally();
        Str::createUuidsNormally();
        Http::preventStrayRequests();
        Process::preventStrayProcesses();
        Sleep::fake();

        $this->freezeTime();

        // Root all share storage under a test-only directory
        config(['coffer.storage_path' => storage_path('framework/testing/shares')]);
    })
    ->afterEach(function (): void {
        cleanUpShareStorage();
    })
    ->in('Browser', 'Feature', 'Unit');

// Tag every test under tests/Browser with the "browser" group so the suite can be filtered locally.
pest()->group('browser')->in('Browser');

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
*/

/**
 * Remove only this test's share directories from disk. Under Pest's --parallel, each worker has its own database, so
 * deleting the paths recorded there never races with directories another worker is actively using, and the shared
 * storage/app/shares parent is never removed out from under another worker.
 */
function cleanUpShareStorage(): void
{
    rescue(function (): void {
        Share::withTrashed()->pluck('path')->each(
            fn (mixed $path): bool => is_string($path) && File::deleteDirectory($path),
        );
    }, report: false);
}

/**
 * Resolve the storage backend rooted at a share's own directory.
 */
function storageFor(Share $share): ShareStorage
{
    return resolve(ShareStorageResolver::class)->for($share);
}

/**
 * Replace every share's storage with a single Mockery mock and return it, so a test can set interaction expectations
 * (which methods are/aren't called, what they return or throw) without touching the real filesystem. Use this for
 * orchestration, authorization-gating, and failure-injection tests; use the real disk (the default) for
 * behavior/round-trip tests.
 */
function fakeShareStorage(): ShareStorage
{
    $storage = Mockery::mock(ShareStorage::class);

    app()->instance(ShareStorageResolver::class, new readonly class($storage) implements ShareStorageResolver
    {
        public function __construct(private ShareStorage $storage) {}

        public function for(Share $share): ShareStorage
        {
            return $this->storage;
        }
    });

    return $storage;
}

/**
 * A unique, test-only absolute storage path under the configured (test) base.
 */
function testSharePath(): string
{
    return mb_rtrim((string) config('coffer.storage_path'), '/').'/'.Str::uuid()->toString();
}

/**
 * Create an active (non-admin) user and a share. Access is flat (every signed-in user has read + write on every share),
 * so the level argument is accepted for backwards compatibility but no longer affects what the user may do.
 *
 * @return array{0: User, 1: Share}
 */
function shareWithMember(string $level = 'editor'): array
{
    return [User::factory()->create(), Share::factory()->create()];
}

/**
 * Seed a file directly into a share's storage directory (creating any parent folders), so a test can set up filesystem
 * state without going through an upload.
 */
function seedFile(Share $share, string $path, string $contents = 'data'): void
{
    $absolute = $share->path.'/'.mb_ltrim($path, '/');

    File::ensureDirectoryExists(dirname($absolute));
    File::put($absolute, $contents);
}

/**
 * Seed an (empty) folder directly into a share's storage directory.
 */
function seedFolder(Share $share, string $path): void
{
    File::ensureDirectoryExists($share->path.'/'.mb_ltrim($path, '/'));
}

/**
 * Encode key/value pairs into a tus Upload-Metadata header ("key base64value" pairs, comma-separated).
 *
 * @param  array<string, string>  $pairs
 */
function tusMetadata(array $pairs): string
{
    return collect($pairs)
        ->map(fn (string $value, string $key): string => $key.' '.base64_encode($value))
        ->implode(',');
}

/**
 * Open a tus upload session on a share as the currently acting user, returning the raw creation response (its
 * Location header carries the upload URL).
 */
function tusCreate(Share $share, string $name, int $length, string $directory = '', string $onConflict = 'keep_both'): TestResponse
{
    return test()->withHeaders([
        'Tus-Resumable' => '1.0.0',
        'Upload-Length' => (string) $length,
        'Upload-Metadata' => tusMetadata(['filename' => $name, 'directory' => $directory, 'on_conflict' => $onConflict]),
        'Accept' => 'application/json',
    ])->post(route('shares.uploads.store', $share));
}

/**
 * Probe a tus upload URL for its current offset (the protocol's HEAD request) as the currently acting user.
 */
function tusHead(string $url): TestResponse
{
    return test()->call('HEAD', $url, [], [], [], ['HTTP_TUS_RESUMABLE' => '1.0.0']);
}

/**
 * Send one tus chunk to an upload URL as the currently acting user.
 */
function tusPatch(string $url, int $offset, string $body): TestResponse
{
    return test()->call('PATCH', $url, [], [], [], [
        'HTTP_TUS_RESUMABLE' => '1.0.0',
        'HTTP_UPLOAD_OFFSET' => (string) $offset,
        'CONTENT_TYPE' => 'application/offset+octet-stream',
    ], $body);
}

/**
 * Run a complete tus upload (creation plus a single chunk) on a share as the currently acting user, returning the
 * final chunk's response.
 */
function tusUpload(Share $share, string $name, string $contents, string $directory = '', string $onConflict = 'keep_both'): TestResponse
{
    $location = tusCreate($share, $name, mb_strlen($contents, '8bit'), $directory, $onConflict)
        ->assertCreated()
        ->headers->get('Location');

    assert(is_string($location));

    return tusPatch($location, 0, $contents);
}
