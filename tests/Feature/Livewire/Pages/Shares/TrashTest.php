<?php

declare(strict_types=1);

use App\Actions\Shares\PurgeTrashEntry;
use App\Actions\Shares\RestoreEntry;
use App\Models\Share;
use Livewire\Livewire;

test('a trashed item appears in the recycle bin but not the browser', function (): void {
    [$user, $share] = shareWithMember();
    seedFile($share, 'gone.txt');

    storageFor($share)->trash('gone.txt', $user->id);

    expect(storageFor($share)->entries())->toHaveCount(0);

    $bin = Livewire::actingAs($user)->test('pages::shares.trash', ['share' => $share]);

    expect($bin->instance()->trashed->pluck('name')->all())->toContain('gone.txt');
});

test('restoring a file brings it back from the recycle bin', function (): void {
    $share = Share::factory()->create();
    seedFile($share, 'doc.txt');
    $id = storageFor($share)->trash('doc.txt', null)->id;

    $restored = resolve(RestoreEntry::class)->handle($share, $id);

    expect($restored?->path)->toBe('doc.txt')
        ->and(storageFor($share)->exists('doc.txt'))->toBeTrue()
        ->and(storageFor($share)->trashed())->toHaveCount(0);
});

test('restoring a folder also restores its descendants', function (): void {
    $share = Share::factory()->create();
    seedFile($share, 'A/x.txt');
    $id = storageFor($share)->trash('A', null)->id;

    resolve(RestoreEntry::class)->handle($share, $id);

    expect(storageFor($share)->exists('A/x.txt'))->toBeTrue();
});

test('restoring resolves a name clash with a live item', function (): void {
    $share = Share::factory()->create();
    seedFile($share, 'doc.txt', 'original');
    $id = storageFor($share)->trash('doc.txt', null)->id;
    seedFile($share, 'doc.txt', 'replacement');

    $restored = resolve(RestoreEntry::class)->handle($share, $id);

    expect($restored?->path)->toBe('doc (1).txt')
        ->and(storageFor($share)->exists('doc.txt'))->toBeTrue()
        ->and(storageFor($share)->exists('doc (1).txt'))->toBeTrue();
});

test('restoring an item whose parent is gone returns it to the share root', function (): void {
    $share = Share::factory()->create();
    seedFile($share, 'P/c.txt');
    $id = storageFor($share)->trash('P/c.txt', null)->id;

    // The original parent folder is removed before the child is restored.
    storageFor($share)->delete('P');

    $restored = resolve(RestoreEntry::class)->handle($share, $id);

    expect($restored?->path)->toBe('c.txt')
        ->and(storageFor($share)->exists('c.txt'))->toBeTrue();
});

test('permanently deleting an item removes it from the recycle bin', function (): void {
    $share = Share::factory()->create();
    seedFile($share, 'A/x.txt');
    $id = storageFor($share)->trash('A', null)->id;

    resolve(PurgeTrashEntry::class)->handle($share, $id);

    expect(storageFor($share)->trashed())->toHaveCount(0)
        ->and(resolve(RestoreEntry::class)->handle($share, $id))->toBeNull();
});

test('a user can restore an item through the recycle bin', function (): void {
    [$user, $share] = shareWithMember();
    seedFile($share, 'doc.txt');
    $id = storageFor($share)->trash('doc.txt', $user->id)->id;

    Livewire::actingAs($user)
        ->test('pages::shares.trash', ['share' => $share])
        ->call('restore', $id);

    expect(storageFor($share)->exists('doc.txt'))->toBeTrue();
});

test('a user can permanently delete an item through the recycle bin', function (): void {
    [$user, $share] = shareWithMember();
    seedFile($share, 'doc.txt');
    $id = storageFor($share)->trash('doc.txt', $user->id)->id;

    Livewire::actingAs($user)
        ->test('pages::shares.trash', ['share' => $share])
        ->call('purge', $id);

    expect(storageFor($share)->trashed())->toHaveCount(0);
});

test('permanently deleting an item records an attributable activity', function (): void {
    [$user, $share] = shareWithMember();
    seedFile($share, 'doc.txt');
    $id = storageFor($share)->trash('doc.txt', $user->id)->id;

    Livewire::actingAs($user)
        ->test('pages::shares.trash', ['share' => $share])
        ->call('purge', $id);

    $this->assertDatabaseHas('activities', [
        'share_id' => $share->id,
        'user_id' => $user->id,
        'action' => 'node.purged',
        'subject' => 'doc.txt',
    ]);
});
