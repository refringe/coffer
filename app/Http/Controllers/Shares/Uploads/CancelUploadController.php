<?php

declare(strict_types=1);

namespace App\Http\Controllers\Shares\Uploads;

use App\Concerns\InteractsWithPendingUploads;
use App\Contracts\ShareStorageResolver;
use App\Http\Controllers\Controller;
use App\Models\Share;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class CancelUploadController extends Controller
{
    use InteractsWithPendingUploads;

    public function __construct(private readonly ShareStorageResolver $storage) {}

    /**
     * Abandon a pending upload, removing its partial file and sidecar manifest.
     */
    public function __invoke(Request $request, Share $share, string $upload): Response
    {
        $user = $request->user();

        abort_unless($user instanceof User, 403);
        abort_unless($user->can('modifyFiles', $share), 403);

        $storage = $this->storage->for($share);
        $pending = $this->resolveUpload($storage, $upload, $user);

        $storage->deleteUpload($pending->id);

        return response()->noContent();
    }
}
