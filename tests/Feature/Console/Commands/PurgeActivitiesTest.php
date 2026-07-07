<?php

declare(strict_types=1);

use App\Models\Activity;
use App\Models\Share;

test('it deletes activity past the retention window and keeps recent records', function (): void {
    config(['coffer.activity_days' => 90]);

    $share = Share::factory()->create();
    $old = Activity::factory()->create(['share_id' => $share->id, 'created_at' => now()->subDays(91)]);
    $recent = Activity::factory()->create(['share_id' => $share->id, 'created_at' => now()->subDays(10)]);

    $this->artisan('shares:purge-activity')->assertSuccessful();

    expect(Activity::query()->find($old->id))->toBeNull()
        ->and(Activity::query()->find($recent->id))->not->toBeNull();
});

test('retention disabled keeps activity indefinitely', function (): void {
    config(['coffer.activity_days' => 0]);

    $share = Share::factory()->create();
    $old = Activity::factory()->create(['share_id' => $share->id, 'created_at' => now()->subYears(5)]);

    $this->artisan('shares:purge-activity')->assertSuccessful();

    expect(Activity::query()->find($old->id))->not->toBeNull();
});
