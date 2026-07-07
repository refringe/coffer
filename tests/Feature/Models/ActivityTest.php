<?php

declare(strict_types=1);

use App\Enums\ActivityAction;
use App\Models\Activity;
use App\Models\Share;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;

test('creating a folder records a single activity entry', function (): void {
    [$user, $share] = shareWithMember();

    Livewire::actingAs($user)
        ->test('pages::shares.show', ['share' => $share])
        ->set('newFolderName', 'Reports')
        ->call('createFolder')
        ->assertHasNoErrors();

    $activity = Activity::query()->where('share_id', $share->id)->sole();

    expect($activity->action)->toBe(ActivityAction::FolderCreated)
        ->and($activity->user_id)->toBe($user->id)
        ->and($activity->subject)->toBe('Reports')
        ->and($activity->path)->toBe('Reports');
});

test('renaming an entry records activity', function (): void {
    [$user, $share] = shareWithMember();
    seedFile($share, 'old.txt');

    Livewire::actingAs($user)
        ->test('pages::shares.show', ['share' => $share])
        ->call('startRename', 'old.txt')
        ->set('renameName', 'new.txt')
        ->call('rename');

    expect(Activity::query()->where('share_id', $share->id)->where('action', ActivityAction::NodeRenamed)->where('subject', 'new.txt')->exists())->toBeTrue();
});

test('moving an entry records activity', function (): void {
    [$user, $share] = shareWithMember();
    seedFile($share, 'doc.txt');
    seedFolder($share, 'Folder');

    Livewire::actingAs($user)
        ->test('pages::shares.show', ['share' => $share])
        ->call('startMove', 'doc.txt')
        ->call('moveTo', 'Folder');

    expect(Activity::query()->where('share_id', $share->id)->where('action', ActivityAction::NodeMoved)->where('subject', 'doc.txt')->exists())->toBeTrue();
});

test('deleting an entry records activity', function (): void {
    [$user, $share] = shareWithMember();
    seedFile($share, 'doc.txt');

    Livewire::actingAs($user)
        ->test('pages::shares.show', ['share' => $share])
        ->call('delete', 'doc.txt');

    expect(Activity::query()->where('share_id', $share->id)->where('action', ActivityAction::NodeDeleted)->where('subject', 'doc.txt')->exists())->toBeTrue();
});

test('restoring an entry records activity', function (): void {
    [$user, $share] = shareWithMember();
    seedFile($share, 'doc.txt');
    $trashed = storageFor($share)->trash('doc.txt', $user->id);

    Livewire::actingAs($user)
        ->test('pages::shares.trash', ['share' => $share])
        ->call('restore', $trashed->id);

    expect(Activity::query()->where('share_id', $share->id)->where('action', ActivityAction::NodeRestored)->where('subject', 'doc.txt')->exists())->toBeTrue();
});

test('uploading a file records activity', function (): void {
    [$user, $share] = shareWithMember();

    $this->actingAs($user)
        ->post(route('shares.upload', $share), [
            'file' => UploadedFile::fake()->createWithContent('upload.txt', 'uploaded'),
            'directory' => '',
            'on_conflict' => 'keep_both',
        ])
        ->assertCreated();

    expect(Activity::query()->where('share_id', $share->id)->where('action', ActivityAction::FileUploaded)->where('subject', 'upload.txt')->exists())->toBeTrue();
});

test('a download does not record activity', function (): void {
    [$user, $share] = shareWithMember('viewer');
    seedFile($share, 'doc.txt');

    $this->actingAs($user)
        ->get(route('shares.download', ['share' => $share, 'path' => 'doc.txt']))
        ->assertOk();

    expect(Activity::query()->where('share_id', $share->id)->count())->toBe(0);
});

test('the per-share feed only shows that shares activity', function (): void {
    $admin = User::factory()->admin()->create();
    $shareA = Share::factory()->create();
    $shareB = Share::factory()->create();
    Activity::factory()->create(['share_id' => $shareA->id, 'subject' => 'alpha-entry']);
    Activity::factory()->create(['share_id' => $shareB->id, 'subject' => 'beta-entry']);

    Livewire::actingAs($admin)
        ->test('pages::shares.show', ['share' => $shareA])
        ->set('showActivity', true)
        ->assertSee('alpha-entry')
        ->assertDontSee('beta-entry');
});

test('an administrator sees activity across every share', function (): void {
    $admin = User::factory()->admin()->create();
    Activity::factory()->create(['subject' => 'alpha-global']);
    Activity::factory()->create(['subject' => 'beta-global']);

    $this->actingAs($admin)
        ->get(route('admin.activity.index'))
        ->assertOk()
        ->assertSee('alpha-global')
        ->assertSee('beta-global');
});

test('a non-administrator cannot view the global activity log', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('admin.activity.index'))
        ->assertForbidden();
});
