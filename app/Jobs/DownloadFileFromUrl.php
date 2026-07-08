<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\Shares\Uploads\FinalizeUpload;
use App\Contracts\ShareStorageResolver;
use App\Enums\RemoteDownloadState;
use App\Exceptions\RemoteDownloadException;
use App\Models\Share;
use App\Models\User;
use App\Services\RemoteFileDownloader;
use App\Support\PendingUpload;
use App\Support\RemoteDownloadStatus;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Support\Facades\Config;
use Throwable;

/**
 * Downloads a remote URL directly into a share on the server, streaming the body into the pending-upload area and
 * promoting it through the same finalization path as a browser upload.
 */
#[Timeout(7200)]
#[Tries(1)]
final class DownloadFileFromUrl implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public int $shareId,
        public int $userId,
        public string $downloadId,
        public string $url,
        public string $name,
        public string $directory,
    ) {}

    public function handle(
        ShareStorageResolver $manager,
        RemoteFileDownloader $downloader,
        FinalizeUpload $finalizeUpload,
    ): void {
        $share = Share::query()->findOrFail($this->shareId);
        $user = User::query()->findOrFail($this->userId);

        $storage = $manager->for($share);

        // The sidecar manifest is written before any bytes arrive so the stalled-upload purge can sweep the partial
        // if this job dies. The declared length stays zero until the transfer completes and the real size is known.
        $storage->putPendingUpload($this->pendingUpload(0));

        RemoteDownloadStatus::put($share->id, $this->downloadId, RemoteDownloadState::Downloading);

        $lastFlush = 0.0;

        try {
            $size = $downloader->download(
                $this->url,
                $storage->uploadPath($this->downloadId),
                Config::integer('coffer.max_file_size'),
                function (int $received, int $total) use ($share, &$lastFlush): void {
                    // Progress lands in the cache at most once every two seconds.
                    if (microtime(true) - $lastFlush < 2.0) {
                        return;
                    }

                    $lastFlush = microtime(true);

                    RemoteDownloadStatus::put($share->id, $this->downloadId, RemoteDownloadState::Downloading, $received, $total);
                },
            );
        } catch (RemoteDownloadException $remoteDownloadException) {
            $storage->deleteUpload($this->downloadId);

            RemoteDownloadStatus::put($share->id, $this->downloadId, RemoteDownloadState::Failed, error: $remoteDownloadException->getMessage());

            return;
        }

        $finalizeUpload->handle($share, $this->pendingUpload($size), $user);

        RemoteDownloadStatus::put($share->id, $this->downloadId, RemoteDownloadState::Completed, $size, $size);
    }

    /**
     * Mark the download failed and remove its partial when the job dies outside the transfer itself (worker timeout,
     * missing share or user, promotion failure).
     */
    public function failed(?Throwable $exception): void
    {
        RemoteDownloadStatus::put($this->shareId, $this->downloadId, RemoteDownloadState::Failed, error: 'The download failed unexpectedly.');

        $share = Share::query()->find($this->shareId);

        if ($share instanceof Share) {
            resolve(ShareStorageResolver::class)->for($share)->deleteUpload($this->downloadId);
        }
    }

    private function pendingUpload(int $length): PendingUpload
    {
        return new PendingUpload(
            id: $this->downloadId,
            userId: $this->userId,
            name: $this->name,
            directory: $this->directory,
            length: $length,
            onConflict: 'keep_both',
            createdAt: now()->getTimestamp(),
            completedAt: null,
        );
    }
}
