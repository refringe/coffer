<?php

declare(strict_types=1);

use App\Models\Share;
use Illuminate\Support\Facades\File;

test('it purges uploads idle past the retention window and keeps active ones', function (): void {
    config(['coffer.upload_ttl_hours' => 48]);

    $share = Share::factory()->create();
    seedFile($share, '.tmp/uploads/11111111-1111-1111-1111-111111111111', 'stale');
    seedFile($share, '.tmp/uploads/11111111-1111-1111-1111-111111111111.json', '{}');
    seedFile($share, '.tmp/uploads/22222222-2222-2222-2222-222222222222', 'active');
    seedFile($share, '.tmp/uploads/22222222-2222-2222-2222-222222222222.json', '{}');

    touch($share->path.'/.tmp/uploads/11111111-1111-1111-1111-111111111111', now()->subDays(3)->getTimestamp());
    touch($share->path.'/.tmp/uploads/11111111-1111-1111-1111-111111111111.json', now()->subDays(3)->getTimestamp());

    $this->artisan('shares:purge-uploads')->assertSuccessful();

    expect(File::exists($share->path.'/.tmp/uploads/11111111-1111-1111-1111-111111111111'))->toBeFalse()
        ->and(File::exists($share->path.'/.tmp/uploads/11111111-1111-1111-1111-111111111111.json'))->toBeFalse()
        ->and(File::exists($share->path.'/.tmp/uploads/22222222-2222-2222-2222-222222222222'))->toBeTrue()
        ->and(File::exists($share->path.'/.tmp/uploads/22222222-2222-2222-2222-222222222222.json'))->toBeTrue();
});

test('a retention of zero disables the sweep and keeps every upload', function (): void {
    config(['coffer.upload_ttl_hours' => 0]);

    $share = Share::factory()->create();
    seedFile($share, '.tmp/uploads/11111111-1111-1111-1111-111111111111', 'stale');

    touch($share->path.'/.tmp/uploads/11111111-1111-1111-1111-111111111111', now()->subDays(999)->getTimestamp());

    $this->artisan('shares:purge-uploads')->assertSuccessful();

    expect(File::exists($share->path.'/.tmp/uploads/11111111-1111-1111-1111-111111111111'))->toBeTrue();
});
