<?php

declare(strict_types=1);

use App\Enums\RemoteDownloadState;
use App\Jobs\DownloadFileFromUrl;
use App\Services\RemoteFileDownloader;
use App\Support\RemoteDownloadStatus;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * Run the job synchronously against a mocked HTTP transport.
 *
 * @param  array<int, mixed>  $responses
 * @param  array<string, string[]>  $hosts
 */
function runUrlDownloadJob(DownloadFileFromUrl $job, array $responses, array $hosts = []): void
{
    app()->instance(RemoteFileDownloader::class, fakeRemoteDownloader($responses, $hosts));

    app()->call($job->handle(...));
}

test('downloads a remote file into the share and records the activity', function (): void {
    [$user, $share] = shareWithMember();

    $downloadId = (string) Str::uuid();

    runUrlDownloadJob(
        new DownloadFileFromUrl($share->id, $user->id, $downloadId, 'https://files.example/report.pdf', 'report.pdf', ''),
        [new Response(200, ['Content-Length' => '12'], 'file-content')],
    );

    expect(File::get($share->path.'/report.pdf'))->toBe('file-content')
        ->and(File::exists($share->path.'/.tmp/uploads/'.$downloadId))->toBeFalse()
        ->and(RemoteDownloadStatus::get($share->id, $downloadId))->toBe([
            'status' => RemoteDownloadState::Completed->value,
            'received' => 12,
            'total' => 12,
            'error' => null,
        ]);

    $this->assertDatabaseHas('activities', [
        'share_id' => $share->id,
        'user_id' => $user->id,
        'action' => 'file.uploaded',
        'path' => 'report.pdf',
    ]);
});

test('downloads into the requested directory', function (): void {
    [$user, $share] = shareWithMember();

    seedFolder($share, 'Docs');

    runUrlDownloadJob(
        new DownloadFileFromUrl($share->id, $user->id, (string) Str::uuid(), 'https://files.example/report.pdf', 'report.pdf', 'Docs'),
        [new Response(200, [], 'nested')],
    );

    expect(File::get($share->path.'/Docs/report.pdf'))->toBe('nested');
});

test('keeps both files when the name is already taken', function (): void {
    [$user, $share] = shareWithMember();

    seedFile($share, 'report.pdf', 'original');

    runUrlDownloadJob(
        new DownloadFileFromUrl($share->id, $user->id, (string) Str::uuid(), 'https://files.example/report.pdf', 'report.pdf', ''),
        [new Response(200, [], 'downloaded')],
    );

    expect(File::get($share->path.'/report.pdf'))->toBe('original')
        ->and(File::get($share->path.'/report (1).pdf'))->toBe('downloaded');
});

test('marks the download failed when the URL resolves to a private address', function (): void {
    [$user, $share] = shareWithMember();

    $downloadId = (string) Str::uuid();

    runUrlDownloadJob(
        new DownloadFileFromUrl($share->id, $user->id, $downloadId, 'http://internal.example/secret', 'secret.bin', ''),
        [],
        ['internal.example' => ['10.0.0.5']],
    );

    $status = RemoteDownloadStatus::get($share->id, $downloadId);

    expect($status)->not->toBeNull()
        ->and($status['status'])->toBe(RemoteDownloadState::Failed->value)
        ->and($status['error'])->toContain('resolves to an invalid IP address')
        ->and(File::exists($share->path.'/secret.bin'))->toBeFalse()
        ->and(File::exists($share->path.'/.tmp/uploads/'.$downloadId))->toBeFalse()
        ->and(File::exists($share->path.'/.tmp/uploads/'.$downloadId.'.json'))->toBeFalse();
});

test('marks the download failed on an HTTP error response', function (): void {
    [$user, $share] = shareWithMember();

    $downloadId = (string) Str::uuid();

    runUrlDownloadJob(
        new DownloadFileFromUrl($share->id, $user->id, $downloadId, 'https://files.example/gone.bin', 'gone.bin', ''),
        [new Response(404, [], 'missing')],
    );

    expect(RemoteDownloadStatus::get($share->id, $downloadId)['status'] ?? null)->toBe(RemoteDownloadState::Failed->value)
        ->and(File::exists($share->path.'/gone.bin'))->toBeFalse();
});

test('enforces the configured maximum file size', function (): void {
    config(['coffer.max_file_size' => 5]);

    [$user, $share] = shareWithMember();

    $downloadId = (string) Str::uuid();

    runUrlDownloadJob(
        new DownloadFileFromUrl($share->id, $user->id, $downloadId, 'https://files.example/big.bin', 'big.bin', ''),
        [new Response(200, [], 'more-than-five-bytes')],
    );

    $status = RemoteDownloadStatus::get($share->id, $downloadId);

    expect($status['status'] ?? null)->toBe(RemoteDownloadState::Failed->value)
        ->and($status['error'] ?? null)->toContain('larger than')
        ->and(File::exists($share->path.'/big.bin'))->toBeFalse();
});

test('failed marks the status and removes the partial', function (): void {
    [$user, $share] = shareWithMember();

    $downloadId = (string) Str::uuid();
    $job = new DownloadFileFromUrl($share->id, $user->id, $downloadId, 'https://files.example/file.bin', 'file.bin', '');

    seedFile($share, '.tmp/uploads/'.$downloadId, 'partial-bytes');

    $job->failed(new RuntimeException('worker died'));

    expect(RemoteDownloadStatus::get($share->id, $downloadId)['status'] ?? null)->toBe(RemoteDownloadState::Failed->value)
        ->and(File::exists($share->path.'/.tmp/uploads/'.$downloadId))->toBeFalse();
});
