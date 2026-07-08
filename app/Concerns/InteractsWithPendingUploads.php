<?php

declare(strict_types=1);

namespace App\Concerns;

use App\Contracts\ShareStorage;
use App\Models\User;
use App\Support\PendingUpload;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Config;

trait InteractsWithPendingUploads
{
    /**
     * Resolve a pending upload for the authenticated user, aborting with 404 for an unknown or foreign upload (a 403
     * would confirm the id exists) and 410 (removing its artifacts) for one whose inactivity window has lapsed.
     */
    private function resolveUpload(ShareStorage $storage, string $id, User $user): PendingUpload
    {
        $upload = $storage->pendingUpload($id);

        abort_unless($upload instanceof PendingUpload && $upload->userId === $user->id, 404);

        $ttlHours = Config::integer('coffer.upload_ttl_hours');
        $activity = $storage->uploadLastActivity($id);

        if ($ttlHours > 0 && ! $upload->isCompleted() && is_int($activity) && $activity + $ttlHours * 3600 < now()->getTimestamp()) {
            $storage->deleteUpload($id);

            abort(410);
        }

        return $upload;
    }

    /**
     * The Upload-Expires header advertising when the upload's inactivity window lapses, or no header when expiry is
     * disabled or the upload no longer has artifacts to date it by.
     *
     * @return array<string, string>
     */
    private function uploadExpiresHeader(ShareStorage $storage, string $id): array
    {
        $ttlHours = Config::integer('coffer.upload_ttl_hours');
        $activity = $storage->uploadLastActivity($id);

        if ($ttlHours <= 0 || $activity === null) {
            return [];
        }

        return ['Upload-Expires' => CarbonImmutable::createFromTimestamp($activity + $ttlHours * 3600)->toRfc7231String()];
    }
}
