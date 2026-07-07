<?php

declare(strict_types=1);

use App\Models\Share;
use App\Models\User;

test('the recycle bin lists trashed items in the browser', function (): void {
    $user = User::factory()->create();
    $share = Share::factory()->create(['name' => 'Restore Share']);

    seedFile($share, 'lost.txt');
    storageFor($share)->trash('lost.txt', $user->id);

    $this->actingAs($user);

    visit(route('shares.trash', $share))
        ->assertSee('Deleted items are kept here')
        ->assertSee('lost.txt');
});
