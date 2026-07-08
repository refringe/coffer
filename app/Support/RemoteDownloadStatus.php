<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\RemoteDownloadState;
use Illuminate\Support\Facades\Cache;

/**
 * Cache-backed progress record for one server-side URL download, keyed per share and download id, so the browser can
 * poll a download's state while the queued job streams it in the background.
 */
final class RemoteDownloadStatus
{
    private const int TTL_HOURS = 24;

    public static function put(
        int $shareId,
        string $downloadId,
        RemoteDownloadState $state,
        int $received = 0,
        int $total = 0,
        ?string $error = null,
    ): void {
        Cache::put(self::key($shareId, $downloadId), [
            'status' => $state->value,
            'received' => $received,
            'total' => $total,
            'error' => $error,
        ], now()->addHours(self::TTL_HOURS));
    }

    /**
     * @return array{status: string, received: int, total: int, error: string|null}|null
     */
    public static function get(int $shareId, string $downloadId): ?array
    {
        $status = Cache::get(self::key($shareId, $downloadId));

        if (! is_array($status)) {
            return null;
        }

        return [
            'status' => is_string($status['status'] ?? null) ? $status['status'] : RemoteDownloadState::Failed->value,
            'received' => is_numeric($status['received'] ?? null) ? (int) $status['received'] : 0,
            'total' => is_numeric($status['total'] ?? null) ? (int) $status['total'] : 0,
            'error' => is_string($status['error'] ?? null) ? $status['error'] : null,
        ];
    }

    private static function key(int $shareId, string $downloadId): string
    {
        return sprintf('remote-download:%d:%s', $shareId, $downloadId);
    }
}
