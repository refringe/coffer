<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Contracts\ShareStorageResolver;
use App\Models\Share;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Config;

#[Description('Delete resumable uploads whose last activity is older than the retention window')]
#[Signature('shares:purge-uploads')]
final class PurgeStalledUploads extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(ShareStorageResolver $storage): int
    {
        $hours = Config::integer('coffer.upload_ttl_hours');

        if ($hours <= 0) {
            $this->components->info('Upload retention is disabled; nothing to purge.');

            return self::SUCCESS;
        }

        $cutoff = now()->subHours($hours);
        $purged = 0;

        Share::withTrashed()->chunkById(100, function (Collection $shares) use ($storage, $cutoff, &$purged): void {
            /** @var Collection<int, Share> $shares */
            foreach ($shares as $share) {
                $purged += $storage->for($share)->purgeUploadsBefore($cutoff);
            }
        });

        $this->components->info(sprintf('Purged %d stalled upload(s).', $purged));

        return self::SUCCESS;
    }
}
