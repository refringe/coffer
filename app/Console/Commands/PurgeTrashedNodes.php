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

#[Description('Permanently delete recycle-bin items past the retention window')]
#[Signature('shares:purge-trash')]
final class PurgeTrashedNodes extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(ShareStorageResolver $storage): int
    {
        $days = Config::integer('coffer.trash_days');

        if ($days <= 0) {
            $this->components->info('Recycle-bin retention is disabled; nothing to purge.');

            return self::SUCCESS;
        }

        $cutoff = now()->subDays($days);
        $purged = 0;

        // Files live in each share's own directory (which persists even when the share is soft-deleted), so every
        // share's recycle bin must be swept.
        Share::withTrashed()->chunkById(100, function (Collection $shares) use ($storage, $cutoff, &$purged): void {
            /** @var Collection<int, Share> $shares */
            foreach ($shares as $share) {
                $purged += $storage->for($share)->purgeTrashedBefore($cutoff);
            }
        });

        $this->components->info(sprintf('Purged %d recycle-bin item(s).', $purged));

        return self::SUCCESS;
    }
}
