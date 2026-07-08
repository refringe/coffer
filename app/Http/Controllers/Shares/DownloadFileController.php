<?php

declare(strict_types=1);

namespace App\Http\Controllers\Shares;

use App\Concerns\ValidatesStoragePaths;
use App\Contracts\ShareStorageResolver;
use App\Http\Controllers\Controller;
use App\Models\Share;
use App\Models\User;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

final class DownloadFileController extends Controller
{
    use ValidatesStoragePaths;

    public function __construct(private readonly ShareStorageResolver $storage) {}

    /**
     * Deliver a file from the share's storage, either as an attachment download or inline (for in-browser preview).
     */
    public function __invoke(Request $request, Share $share): BinaryFileResponse
    {
        $user = $request->user();

        abort_unless($user instanceof User && $user->can('viewFiles', $share), 403);

        $request->validate([
            'path' => $this->storagePathRules(),
            'preview' => ['nullable', 'boolean'],
        ]);

        $path = mb_trim(str_replace('\\', '/', $request->string('path')->toString()), '/');
        $storage = $this->storage->for($share);

        abort_unless($storage->exists($path) && ! $storage->isDirectory($path), 404);

        $name = basename($path);

        return $request->boolean('preview')
            ? $storage->stream($path, $name)
            : $storage->download($path, $name);
    }
}
