<?php

declare(strict_types=1);

use App\Models\Share;

test('it permanently removes trash past the retention window and keeps fresh items', function (): void {
    config(['coffer.trash_days' => 30]);

    $share = Share::factory()->create();
    seedFile($share, 'old.txt', 'stale');
    seedFile($share, 'fresh.txt', 'recent');

    // The sidecar records the deletion time, so age one item past the window.
    $this->travelTo(now()->subDays(31));
    storageFor($share)->trash('old.txt', null);
    $this->travelBack();

    $freshId = storageFor($share)->trash('fresh.txt', null)->id;

    $this->artisan('shares:purge-trash')->assertSuccessful();

    $remaining = storageFor($share)->trashed();

    expect($remaining)->toHaveCount(1)
        ->and($remaining->first()->id)->toBe($freshId);
});

test('a retention of zero disables the purge and keeps every item', function (): void {
    config(['coffer.trash_days' => 0]);

    $share = Share::factory()->create();
    seedFile($share, 'old.txt', 'stale');

    $this->travelTo(now()->subDays(999));
    storageFor($share)->trash('old.txt', null);
    $this->travelBack();

    $this->artisan('shares:purge-trash')->assertSuccessful();

    expect(storageFor($share)->trashed())->toHaveCount(1);
});

test('it sweeps the recycle bins of soft-deleted shares too', function (): void {
    config(['coffer.trash_days' => 30]);

    $share = Share::factory()->create();
    seedFile($share, 'old.txt');

    $this->travelTo(now()->subDays(31));
    storageFor($share)->trash('old.txt', null);
    $this->travelBack();

    $share->delete();

    $this->artisan('shares:purge-trash')->assertSuccessful();

    expect(storageFor($share->fresh())->trashed())->toHaveCount(0);
});
