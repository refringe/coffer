<?php

declare(strict_types=1);

namespace App\Http\Controllers\Shares\Uploads;

use App\Actions\Shares\Uploads\FinalizeUpload;
use App\Concerns\InteractsWithPendingUploads;
use App\Contracts\ShareStorageResolver;
use App\Http\Controllers\Controller;
use App\Models\Share;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class UploadStatusController extends Controller
{
    use InteractsWithPendingUploads;

    public function __construct(
        private readonly ShareStorageResolver $storage,
        private readonly FinalizeUpload $finalizeUpload,
    ) {}

    /**
     * Report a pending upload's current byte offset so an interrupted client can resume where it left off. A partial
     * that already holds every declared byte but was never promoted (the promotion failed after the final chunk) is
     * promoted here before responding, so a resuming client never mistakes an unpromoted upload for a finished one.
     */
    public function __invoke(Request $request, Share $share, string $upload): Response
    {
        $user = $request->user();

        abort_unless($user instanceof User, 403);
        abort_unless($user->can('modifyFiles', $share), 403);

        $storage = $this->storage->for($share);
        $pending = $this->resolveUpload($storage, $upload, $user);

        if (! $pending->isCompleted()) {
            $offset = $storage->uploadOffset($pending->id);

            if ($offset === $pending->length) {
                $pending = $pending->completed(now()->getTimestamp());
                $this->finalizeUpload->handle($share, $pending, $user);
            } elseif ($offset === null) {
                // The partial is gone but the sidecar still reads pending: promotion crashed between the move and the
                // sidecar rewrite, so the upload is in fact complete and the manifest is healed to match.
                $pending = $pending->completed(now()->getTimestamp());
                $storage->putPendingUpload($pending);
            }
        }

        $offset = $pending->isCompleted() ? $pending->length : (int) $storage->uploadOffset($pending->id);

        return response()->noContent(Response::HTTP_OK)->withHeaders([
            'Upload-Offset' => (string) $offset,
            'Upload-Length' => (string) $pending->length,
            'Cache-Control' => 'no-store',
            ...$this->uploadExpiresHeader($storage, $pending->id),
        ]);
    }
}
