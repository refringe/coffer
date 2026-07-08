<?php

declare(strict_types=1);

namespace App\Http\Controllers\Shares\Uploads;

use App\Actions\Shares\Uploads\FinalizeUpload;
use App\Concerns\InteractsWithPendingUploads;
use App\Contracts\ShareStorageResolver;
use App\Http\Controllers\Controller;
use App\Http\Requests\Shares\CreateUploadRequest;
use App\Models\Share;
use App\Models\User;
use App\Support\PendingUpload;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;

final class CreateUploadController extends Controller
{
    use InteractsWithPendingUploads;

    public function __construct(
        private readonly ShareStorageResolver $storage,
        private readonly FinalizeUpload $finalizeUpload,
    ) {}

    /**
     * Open a resumable upload: record its sidecar manifest and empty partial file, then hand back the URL its chunks
     * are sent to. The filename, target directory, and conflict strategy arrive base64-encoded in the Upload-Metadata
     * header (the request body is empty); invalid metadata yields a 422 through the app's standard form-request
     * validation rather than the tus specification's 400.
     */
    public function __invoke(CreateUploadRequest $request, Share $share): Response
    {
        $user = $request->user();

        abort_unless($user instanceof User, 403);

        $length = $request->uploadLength();

        // A browser File always knows its size, so a deferred-length upload has no caller and is refused outright.
        abort_if($length === null || $request->hasHeader('Upload-Defer-Length'), 400);

        // The size limit is enforced against the declared length here, before a single byte is transferred.
        $maxFileSize = Config::integer('coffer.max_file_size');

        if ($maxFileSize > 0 && $length > $maxFileSize) {
            return response()->noContent(Response::HTTP_REQUEST_ENTITY_TOO_LARGE)
                ->withHeaders(['Tus-Max-Size' => (string) $maxFileSize]);
        }

        $storage = $this->storage->for($share);

        $upload = new PendingUpload(
            id: Str::uuid()->toString(),
            userId: $user->id,
            name: $request->filename(),
            directory: $request->directory(),
            length: $length,
            onConflict: $request->conflictStrategy(),
            createdAt: now()->getTimestamp(),
            completedAt: null,
        );

        $storage->putPendingUpload($upload);

        // The client never sends a chunk for an empty file, so a zero-length upload is promoted immediately.
        if ($length === 0) {
            $this->finalizeUpload->handle($share, $upload, $user);
        }

        return response()->noContent(Response::HTTP_CREATED)->withHeaders([
            'Location' => route('shares.uploads.show', [$share, $upload->id]),
            ...$this->uploadExpiresHeader($storage, $upload->id),
        ]);
    }
}
