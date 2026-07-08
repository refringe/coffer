<?php

declare(strict_types=1);

namespace App\Http\Controllers\Shares;

use App\Concerns\ValidatesStoragePaths;
use App\Contracts\ShareStorage;
use App\Contracts\ShareStorageResolver;
use App\Http\Controllers\Controller;
use App\Jobs\BuildShareZip;
use App\Models\Share;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

final class DownloadZipController extends Controller
{
    use ValidatesStoragePaths;

    public function __construct(private readonly ShareStorageResolver $storage) {}

    /**
     * Deliver a zip of the requested paths. Archive building is handed to the queue so a large selection never blocks
     * the request. The first hit enqueues the job and returns a lightweight "preparing" page that refreshes itself,
     * and a later hit streams the archive once it is ready. A single file skips the archive entirely and a synchronous
     * queue completes on the first hit.
     */
    public function __invoke(Request $request, Share $share): BinaryFileResponse|View|Response
    {
        $user = $request->user();

        abort_unless($user instanceof User && $user->can('viewFiles', $share), 403);

        $storage = $this->storage->for($share);

        $request->validate([
            'paths' => ['required', 'array', 'min:1'],
            'paths.*' => $this->storagePathRules(),
        ]);

        $paths = $request->collect('paths')
            ->map(fn (mixed $path): string => is_string($path) ? mb_trim(str_replace('\\', '/', $path), '/') : '')
            ->filter(fn (string $path): bool => $path !== '' && $storage->exists($path))
            ->unique()
            ->sort()
            ->values()
            ->all();

        abort_if($paths === [], 404);

        // A single file needs no archive, so stream it directly.
        if (count($paths) === 1 && ! $storage->isDirectory($paths[0])) {
            return $storage->download($paths[0], basename($paths[0]));
        }

        $token = self::tokenFor($share->id, $paths);

        // Already built (and still present)? Stream it straight away.
        if (($key = $this->readyKey($storage, $token)) !== null) {
            return $storage->download($key, $share->slug.'.zip');
        }

        // Enqueue at most once per build window so the polling refresh does not pile up jobs; the lock expires so a
        // failed build is eventually retried.
        if (Cache::add($token.':queued', true, now()->addMinutes(5))) {
            dispatch(new BuildShareZip($share->id, $paths, $token));
        }

        // A synchronous queue (tests) will have finished during dispatch.
        if (($key = $this->readyKey($storage, $token)) !== null) {
            return $storage->download($key, $share->slug.'.zip');
        }

        return response()->view('shares.preparing-zip', [
            'share' => $share,
            'url' => $request->fullUrl(),
        ], Response::HTTP_ACCEPTED);
    }

    /**
     * The cache token a built archive is published under for a given selection.
     *
     * @param  array<int, string>  $paths  Normalized (unique, sorted) paths.
     */
    public static function tokenFor(int $shareId, array $paths): string
    {
        return 'zip:'.sha1($shareId.'|'.implode("\n", $paths));
    }

    /**
     * The relative path of a built archive that still exists in storage, or null. Reads the cache and storage, so its
     * result must not be remembered between calls (the archive may become ready after the job runs).
     *
     * @phpstan-impure
     */
    private function readyKey(ShareStorage $storage, string $token): ?string
    {
        $key = Cache::get($token);

        if (is_string($key) && $storage->exists($key)) {
            return $key;
        }

        return null;
    }
}
