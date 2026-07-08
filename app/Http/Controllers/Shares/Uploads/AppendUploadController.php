<?php

declare(strict_types=1);

namespace App\Http\Controllers\Shares\Uploads;

use App\Actions\Shares\Uploads\FinalizeUpload;
use App\Concerns\InteractsWithPendingUploads;
use App\Contracts\ShareStorageResolver;
use App\Exceptions\UploadOffsetMismatchException;
use App\Exceptions\UploadOverflowException;
use App\Exceptions\UploadWriteConflictException;
use App\Http\Controllers\Controller;
use App\Models\Share;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class AppendUploadController extends Controller
{
    use InteractsWithPendingUploads;

    public function __construct(
        private readonly ShareStorageResolver $storage,
        private readonly FinalizeUpload $finalizeUpload,
    ) {}

    /**
     * Append one chunk to a pending upload's partial file at the declared offset, promoting the upload into the share
     * once the final byte lands. A mismatched offset yields 409 and a concurrently locked upload 423; the client
     * resynchronizes through a status request and retries. A chunk cut off mid-body needs no cleanup: the bytes that
     * arrived stay in the partial and the next status request reports the offset they carried it to.
     */
    public function __invoke(Request $request, Share $share, string $upload): Response
    {
        $user = $request->user();

        abort_unless($user instanceof User, 403);
        abort_unless($user->can('modifyFiles', $share), 403);
        abort_unless($request->header('Content-Type') === 'application/offset+octet-stream', 415);

        $offsetHeader = $request->header('Upload-Offset');

        abort_unless(is_string($offsetHeader) && preg_match('/^\d+$/', $offsetHeader) === 1, 400);

        $offset = (int) $offsetHeader;

        $storage = $this->storage->for($share);
        $pending = $this->resolveUpload($storage, $upload, $user);

        // A retried final chunk whose successful response was lost finds the upload already promoted; agreeing on the
        // final offset is an idempotent success, anything else a conflict.
        if ($pending->isCompleted()) {
            abort_unless($offset === $pending->length, 409);

            return response()->noContent()->withHeaders(['Upload-Offset' => (string) $pending->length]);
        }

        try {
            $newOffset = $storage->appendUpload($pending->id, $request->getContent(true), $offset, $pending->length - $offset);
        } catch (UploadOffsetMismatchException) {
            abort(409);
        } catch (UploadWriteConflictException) {
            abort(423);
        } catch (UploadOverflowException) {
            abort(400);
        }

        if ($newOffset === $pending->length) {
            $this->finalizeUpload->handle($share, $pending, $user);
        }

        return response()->noContent()->withHeaders([
            'Upload-Offset' => (string) $newOffset,
            ...$this->uploadExpiresHeader($storage, $pending->id),
        ]);
    }
}
