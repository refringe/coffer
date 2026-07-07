<?php

declare(strict_types=1);

use App\Models\Share;
use Illuminate\Support\Facades\File;

test('it purges zip archives older than the retention window and keeps fresh ones', function (): void {
    config(['coffer.zip_ttl_hours' => 24]);

    $share = Share::factory()->create();
    seedFile($share, '.tmp/zips/old.zip', 'stale');
    seedFile($share, '.tmp/zips/fresh.zip', 'recent');

    touch($share->path.'/.tmp/zips/old.zip', now()->subDays(2)->getTimestamp());

    $this->artisan('shares:purge-zips')->assertSuccessful();

    expect(File::exists($share->path.'/.tmp/zips/old.zip'))->toBeFalse()
        ->and(File::exists($share->path.'/.tmp/zips/fresh.zip'))->toBeTrue();
});

test('a retention of zero disables the sweep and keeps every archive', function (): void {
    config(['coffer.zip_ttl_hours' => 0]);

    $share = Share::factory()->create();
    seedFile($share, '.tmp/zips/old.zip', 'stale');

    touch($share->path.'/.tmp/zips/old.zip', now()->subDays(999)->getTimestamp());

    $this->artisan('shares:purge-zips')->assertSuccessful();

    expect(File::exists($share->path.'/.tmp/zips/old.zip'))->toBeTrue();
});
