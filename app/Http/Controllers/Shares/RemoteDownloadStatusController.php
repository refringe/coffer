<?php

declare(strict_types=1);

namespace App\Http\Controllers\Shares;

use App\Http\Controllers\Controller;
use App\Models\Share;
use App\Models\User;
use App\Support\RemoteDownloadStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class RemoteDownloadStatusController extends Controller
{
    /**
     * Report a server-side URL download's cached progress so the browser's upload panel can poll it.
     */
    public function __invoke(Request $request, Share $share, string $download): JsonResponse
    {
        $user = $request->user();

        abort_unless($user instanceof User, 403);
        abort_unless($user->can('viewFiles', $share), 403);

        $status = RemoteDownloadStatus::get($share->id, $download);

        abort_if($status === null, 404);

        return response()->json($status)->header('Cache-Control', 'no-store');
    }
}
