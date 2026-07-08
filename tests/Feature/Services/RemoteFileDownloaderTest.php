<?php

declare(strict_types=1);

use App\Exceptions\RemoteDownloadException;
use App\Services\RemoteFileDownloader;
use CraftCms\UrlValidator\UrlValidator;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * A unique destination path for one downloaded file under the test-only storage area.
 */
function downloadSinkPath(): string
{
    $directory = storage_path('framework/testing/downloads');

    File::ensureDirectoryExists($directory);

    return $directory.'/'.Str::uuid()->toString();
}

test('downloads a remote file to the destination and returns its size', function (): void {
    $destination = downloadSinkPath();

    $downloader = fakeRemoteDownloader([new Response(200, ['Content-Length' => '12'], 'file-content')]);

    $size = $downloader->download('https://files.example/report.pdf', $destination);

    expect($size)->toBe(12)
        ->and(File::get($destination))->toBe('file-content');
});

test('follows redirects and downloads the final target', function (): void {
    $destination = downloadSinkPath();

    $downloader = fakeRemoteDownloader([
        new Response(302, ['Location' => 'https://cdn.example/real-file.bin']),
        new Response(200, [], 'redirected-content'),
    ]);

    $size = $downloader->download('https://files.example/file.bin', $destination);

    expect($size)->toBe(18)
        ->and(File::get($destination))->toBe('redirected-content');
});

test('resolves a relative redirect against the current URL', function (): void {
    $destination = downloadSinkPath();

    $mock = new MockHandler([
        new Response(301, ['Location' => '/mirror/file.bin']),
        new Response(200, [], 'data'),
    ]);

    $downloader = new RemoteFileDownloader(
        new UrlValidator(fn (): array => ['93.184.215.14']),
        new Client(['handler' => HandlerStack::create($mock)]),
    );

    $downloader->download('https://files.example/file.bin', $destination);

    expect((string) $mock->getLastRequest()?->getUri())->toBe('https://files.example/mirror/file.bin');
});

test('rejects a redirect that lands on a private address', function (): void {
    $destination = downloadSinkPath();

    $downloader = fakeRemoteDownloader(
        [new Response(302, ['Location' => 'http://internal.example/secret'])],
        ['files.example' => ['93.184.215.14'], 'internal.example' => ['10.0.0.5']],
    );

    expect(fn (): int => $downloader->download('https://files.example/file.bin', $destination))
        ->toThrow(RemoteDownloadException::class, 'resolves to an invalid IP address')
        ->and(file_exists($destination))->toBeFalse();
});

test('rejects a URL that resolves to a loopback address', function (): void {
    $downloader = fakeRemoteDownloader([], ['sneaky.example' => ['127.0.0.1']]);

    expect(fn (): int => $downloader->download('https://sneaky.example/file.bin', downloadSinkPath()))
        ->toThrow(RemoteDownloadException::class, 'resolves to an invalid IP address');
});

test('rejects a non-HTTP scheme', function (): void {
    $downloader = fakeRemoteDownloader([]);

    expect(fn (): int => $downloader->download('ftp://files.example/file.bin', downloadSinkPath()))
        ->toThrow(RemoteDownloadException::class, 'invalid scheme');
});

test('rejects a raw IP literal hostname', function (): void {
    $downloader = fakeRemoteDownloader([]);

    expect(fn (): int => $downloader->download('http://169.254.169.254/latest/meta-data', downloadSinkPath()))
        ->toThrow(RemoteDownloadException::class, 'invalid hostname');
});

test('rejects a URL with embedded credentials', function (): void {
    $downloader = fakeRemoteDownloader([]);

    expect(fn (): int => $downloader->download('https://user:secret@files.example/file.bin', downloadSinkPath()))
        ->toThrow(RemoteDownloadException::class, 'embedded credentials');
});

test('gives up after too many redirects', function (): void {
    $responses = array_fill(0, 6, new Response(302, ['Location' => 'https://files.example/again']));

    $downloader = fakeRemoteDownloader($responses);

    expect(fn (): int => $downloader->download('https://files.example/file.bin', downloadSinkPath()))
        ->toThrow(RemoteDownloadException::class, 'redirected too many times');
});

test('rejects a redirect without a destination', function (): void {
    $downloader = fakeRemoteDownloader([new Response(301)]);

    expect(fn (): int => $downloader->download('https://files.example/file.bin', downloadSinkPath()))
        ->toThrow(RemoteDownloadException::class, 'redirected without a destination');
});

test('fails on an unsuccessful response status', function (): void {
    $destination = downloadSinkPath();

    $downloader = fakeRemoteDownloader([new Response(404, [], 'not here')]);

    expect(fn (): int => $downloader->download('https://files.example/file.bin', $destination))
        ->toThrow(RemoteDownloadException::class, 'HTTP 404')
        ->and(file_exists($destination))->toBeFalse();
});

test('rejects a declared length above the cap before the body is read', function (): void {
    $downloader = fakeRemoteDownloader([new Response(200, ['Content-Length' => '1000'], 'irrelevant')]);

    expect(fn (): int => $downloader->download('https://files.example/file.bin', downloadSinkPath(), maxBytes: 10))
        ->toThrow(RemoteDownloadException::class, 'larger than the');
});

test('rejects an undeclared body that exceeds the cap and removes the partial', function (): void {
    $destination = downloadSinkPath();

    $downloader = fakeRemoteDownloader([new Response(200, [], 'twenty-byte-content!')]);

    expect(fn (): int => $downloader->download('https://files.example/file.bin', $destination, maxBytes: 10))
        ->toThrow(RemoteDownloadException::class, 'larger than the')
        ->and(file_exists($destination))->toBeFalse();
});

test('a zero max size disables the cap', function (): void {
    $destination = downloadSinkPath();

    $downloader = fakeRemoteDownloader([new Response(200, [], 'twenty-byte-content!')]);

    expect($downloader->download('https://files.example/file.bin', $destination, maxBytes: 0))->toBe(20);
});
