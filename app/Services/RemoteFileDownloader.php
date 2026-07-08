<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\RemoteDownloadException;
use CraftCms\UrlValidator\UrlValidationException;
use CraftCms\UrlValidator\UrlValidator;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use Illuminate\Support\Number;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/**
 * Streams a remote HTTP(S) resource to a local file with SSRF protection. Every URL, including each redirect hop, is
 * validated against private, reserved, loopback, and cloud-metadata addresses, and each connection is pinned to the
 * IP addresses that passed validation so a re-resolving DNS record cannot swap in an internal target mid-transfer.
 */
final readonly class RemoteFileDownloader
{
    private const int MAX_REDIRECTS = 5;

    private const int CONNECT_TIMEOUT_SECONDS = 10;

    /**
     * Transfers slower than this many bytes per second, sustained for the whole stall window, are aborted.
     */
    private const int STALL_BYTES_PER_SECOND = 1024;

    private const int STALL_WINDOW_SECONDS = 60;

    public function __construct(
        private UrlValidator $validator,
        private ClientInterface $client,
    ) {}

    /**
     * Download a remote file to the destination path, following at most five redirects, and return its size in bytes.
     * A $maxBytes of zero disables the size cap. Progress is reported as (bytes received, total bytes), where the total
     * is zero when the server did not declare a length. The destination file is removed on failure.
     *
     * @param  (callable(int, int): void)|null  $onProgress
     *
     * @throws RemoteDownloadException
     */
    public function download(string $url, string $destination, int $maxBytes = 0, ?callable $onProgress = null): int
    {
        $cap = $maxBytes > 0 ? $maxBytes : PHP_INT_MAX;

        try {
            return $this->transfer($url, $destination, $cap, $onProgress);
        } catch (Throwable $throwable) {
            @unlink($destination);

            throw $throwable;
        }
    }

    /**
     * @param  (callable(int, int): void)|null  $onProgress
     */
    private function transfer(string $url, string $destination, int $cap, ?callable $onProgress): int
    {
        $current = $url;

        for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
            $ips = $this->validateUrl($current);
            $response = $this->request($current, $ips, $destination, $cap, $onProgress);
            $status = $response->getStatusCode();

            if (in_array($status, [301, 302, 303, 307, 308], true)) {
                $location = $response->getHeaderLine('Location');

                throw_if($location === '', RemoteDownloadException::class, 'The server redirected without a destination.');

                $current = (string) UriResolver::resolve(new Uri($current), new Uri($location));

                continue;
            }

            throw_unless($status >= 200 && $status < 300, RemoteDownloadException::class, sprintf('The server responded with HTTP %s.', $status));

            return $this->verifiedSize($destination, $cap);
        }

        throw new RemoteDownloadException('The URL redirected too many times.');
    }

    /**
     * Validate one hop's URL and return the resolved IP addresses the connection must be pinned to.
     *
     * @return string[]
     */
    private function validateUrl(string $url): array
    {
        $parts = parse_url($url);

        throw_if(
            ! is_array($parts) || isset($parts['user']) || isset($parts['pass']),
            RemoteDownloadException::class,
            'The URL must not contain embedded credentials.'
        );

        try {
            return $this->validator->validate($url);
        } catch (UrlValidationException $urlValidationException) {
            throw new RemoteDownloadException($urlValidationException->getMessage(), $urlValidationException->getCode(), previous: $urlValidationException);
        }
    }

    /**
     * Perform one pinned GET request, streaming the body to the destination. Redirects are never followed here; the
     * response is returned to the caller so each hop is re-validated before the next connection is opened.
     *
     * @param  string[]  $ips
     * @param  (callable(int, int): void)|null  $onProgress
     */
    private function request(string $url, array $ips, string $destination, int $cap, ?callable $onProgress): ResponseInterface
    {
        try {
            return $this->client->request('GET', $url, [
                'sink' => $destination,
                'allow_redirects' => false,
                'http_errors' => false,
                'decode_content' => false,
                'connect_timeout' => self::CONNECT_TIMEOUT_SECONDS,
                'timeout' => 0,
                'on_headers' => function (ResponseInterface $response) use ($cap): void {
                    throw_if(
                        (int) $response->getHeaderLine('Content-Length') > $cap,
                        RemoteDownloadException::class,
                        $this->tooLargeMessage($cap)
                    );
                },
                'progress' => function (int $total, int $received) use ($cap, $onProgress): void {
                    throw_if($received > $cap, RemoteDownloadException::class, $this->tooLargeMessage($cap));

                    if ($onProgress !== null && $received > 0) {
                        $onProgress($received, $total);
                    }
                },
                'curl' => $this->curlOptions($url, $ips, $cap),
            ]);
        } catch (Throwable $throwable) {
            throw $this->normalize($throwable);
        }
    }

    /**
     * Per-request curl options: pin the connection to the validated IP addresses, restrict protocols to HTTP(S), abort
     * stalled transfers, and cap the response size natively when a cap is set.
     *
     * @param  string[]  $ips
     * @return array<int, mixed>
     */
    private function curlOptions(string $url, array $ips, int $cap): array
    {
        $parts = parse_url($url);
        $scheme = mb_strtolower((string) (is_array($parts) ? ($parts['scheme'] ?? 'https') : 'https'));
        $host = (string) (is_array($parts) ? ($parts['host'] ?? '') : '');
        $port = (int) (is_array($parts) ? ($parts['port'] ?? ($scheme === 'http' ? 80 : 443)) : 443);

        $options = [
            CURLOPT_RESOLVE => [sprintf('%s:%d:%s', $host, $port, implode(',', $ips))],
            CURLOPT_LOW_SPEED_LIMIT => self::STALL_BYTES_PER_SECOND,
            CURLOPT_LOW_SPEED_TIME => self::STALL_WINDOW_SECONDS,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        ];

        if ($cap < PHP_INT_MAX) {
            $options[CURLOPT_MAXFILESIZE_LARGE] = $cap;
        }

        return $options;
    }

    /**
     * The downloaded file's size on disk, verified against the cap. Guards the paths curl's own size checks cannot
     * cover, such as a mocked transport in tests or a handler that ignores the progress option.
     */
    private function verifiedSize(string $destination, int $cap): int
    {
        clearstatcache(true, $destination);
        $size = @filesize($destination);

        throw_unless(is_int($size), RemoteDownloadException::class, 'The downloaded file could not be read back.');
        throw_if($size > $cap, RemoteDownloadException::class, $this->tooLargeMessage($cap));

        return $size;
    }

    /**
     * Surface the RemoteDownloadException buried in a transport exception chain (thrown from inside a response
     * callback), or wrap the transport failure in one.
     */
    private function normalize(Throwable $exception): RemoteDownloadException
    {
        for ($current = $exception; $current instanceof Throwable; $current = $current->getPrevious()) {
            if ($current instanceof RemoteDownloadException) {
                return $current;
            }
        }

        return new RemoteDownloadException('The download failed: '.$exception->getMessage(), previous: $exception);
    }

    private function tooLargeMessage(int $cap): string
    {
        return 'The file is larger than the '.Number::fileSize($cap).' maximum.';
    }
}
