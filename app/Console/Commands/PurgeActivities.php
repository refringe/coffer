<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Activity;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;

#[Description('Delete share activity records past the retention window')]
#[Signature('shares:purge-activity')]
final class PurgeActivities extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $days = Config::integer('coffer.activity_days');

        if ($days <= 0) {
            $this->components->info('Activity retention is disabled; nothing to purge.');

            return self::SUCCESS;
        }

        $cutoff = now()->subDays($days);

        $purged = Activity::query()->where('created_at', '<', $cutoff)->count();

        Activity::query()->where('created_at', '<', $cutoff)->delete();

        $this->components->info(sprintf('Purged %d activity record(s).', $purged));

        return self::SUCCESS;
    }
}
