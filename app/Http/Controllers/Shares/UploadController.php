<?php

declare(strict_types=1);

namespace App\Http\Controllers\Shares;

use App\Actions\Shares\StoreUpload;
use App\Enums\ActivityAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Shares\UploadRequest;
use App\Models\Activity;
use App\Models\Share;
use App\Models\User;
use App\Support\Entry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\UploadedFile;

final class UploadController extends Controller
{
    public function __construct(private readonly StoreUpload $storeUpload) {}

    /**
     * Stream an uploaded file to the share's storage directory, resolving any name conflict, and record the activity.
     */
    public function __invoke(UploadRequest $request, Share $share): JsonResponse
    {
        $user = $request->user();

        abort_unless($user instanceof User, 403);

        $file = $request->file('file');

        abort_unless($file instanceof UploadedFile, 422);

        $result = $this->storeUpload->handle($share, $request->directory(), $file, $request->conflictStrategy(), $user->id);

        if ($result['status'] === 'completed' && $result['entry'] instanceof Entry) {
            Activity::record($share, $user, ActivityAction::FileUploaded, $result['entry']->name, $result['entry']->path);

            return response()->json(['status' => 'completed', 'entry' => $result['entry']], 201);
        }

        return match ($result['status']) {
            'skipped' => response()->json(['status' => 'skipped'], 200),
            'too_large' => response()->json(['status' => 'too_large', 'message' => __('The file exceeds the maximum allowed size.')], 422),
            default => response()->json(['status' => 'error', 'message' => __('The file could not be stored.')], 422),
        };
    }
}
