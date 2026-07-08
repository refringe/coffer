<?php

declare(strict_types=1);

use App\Actions\Shares\MoveEntry;
use App\Actions\Shares\RenameEntry;
use App\Jobs\DownloadFileFromUrl;
use App\Models\Share;
use CraftCms\UrlValidator\UrlValidator;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

test('a guest is redirected to login when opening a share', function (): void {
    [, $share] = shareWithMember();

    $this->get(route('shares.show', $share))->assertRedirect(route('login'));
});

test('a member can open a share and see its root entries', function (): void {
    [$user, $share] = shareWithMember('viewer');

    seedFolder($share, 'Documents');
    seedFile($share, 'readme.txt');

    Livewire::actingAs($user)
        ->test('pages::shares.show', ['share' => $share])
        ->assertSee('Documents')
        ->assertSee('readme.txt');
});

test('a partial transfer file is hidden from the listing', function (): void {
    [$user, $share] = shareWithMember('viewer');

    seedFile($share, 'notes.txt');
    seedFile($share, 'archive.7z.partial');

    Livewire::actingAs($user)
        ->test('pages::shares.show', ['share' => $share])
        ->assertSee('notes.txt')
        ->assertDontSee('archive.7z.partial');
});

test('folders are listed before files', function (): void {
    [$user, $share] = shareWithMember('viewer');

    // A file that sorts alphabetically before the folder name.
    seedFile($share, 'aaa.txt');
    seedFolder($share, 'zzz-folder');

    Livewire::actingAs($user)
        ->test('pages::shares.show', ['share' => $share])
        ->assertSeeInOrder(['zzz-folder', 'aaa.txt']);
});

test('navigating into a folder lists its children and breadcrumbs back', function (): void {
    [$user, $share] = shareWithMember();

    seedFile($share, 'Sub/inside.txt');
    seedFile($share, 'root.txt');

    Livewire::actingAs($user)
        ->test('pages::shares.show', ['share' => $share])
        ->assertSee('root.txt')
        ->call('open', 'Sub')
        ->assertSee('inside.txt')
        ->assertDontSee('root.txt')
        ->call('openRoot')
        ->assertSee('root.txt');
});

test('navigating to a path that is not a folder is ignored', function (): void {
    [$user, $share] = shareWithMember('viewer');
    seedFile($share, 'root.txt');

    Livewire::actingAs($user)
        ->test('pages::shares.show', ['share' => $share])
        ->call('open', 'root.txt')
        ->assertSet('path', '')
        ->assertSee('root.txt');
});

test('a traversal path in the query string falls back to the share root', function (): void {
    [$user, $share] = shareWithMember('viewer');
    seedFile($share, 'root.txt');

    $this->actingAs($user)
        ->get(route('shares.show', $share).'?path='.urlencode('foo/../bar'))
        ->assertOk()
        ->assertSee('root.txt');
});

test('a reserved area path in the query string falls back to the share root', function (): void {
    [$user, $share] = shareWithMember('viewer');
    seedFile($share, 'root.txt');

    $this->actingAs($user)
        ->get(route('shares.show', $share).'?path=.trash')
        ->assertOk()
        ->assertSee('root.txt');
});

test('a path with an empty interior segment falls back to the share root', function (): void {
    [$user, $share] = shareWithMember('viewer');
    seedFile($share, 'root.txt');

    $this->actingAs($user)
        ->get(route('shares.show', $share).'?path='.urlencode('a//b'))
        ->assertOk()
        ->assertSee('root.txt');
});

test('a reserved area path cannot be sent to the recycle bin', function (): void {
    [$user, $share] = shareWithMember();
    seedFile($share, 'keep.txt');

    Livewire::actingAs($user)
        ->test('pages::shares.show', ['share' => $share])
        ->call('delete', '.trash/anything')
        ->assertOk();

    expect(storageFor($share)->exists('keep.txt'))->toBeTrue();
});

test('sorting by name toggles direction', function (): void {
    [$user, $share] = shareWithMember('viewer');

    seedFile($share, 'a-file.txt');
    seedFile($share, 'b-file.txt');

    Livewire::actingAs($user)
        ->test('pages::shares.show', ['share' => $share])
        ->assertSeeInOrder(['a-file.txt', 'b-file.txt'])
        ->call('sort', 'name')
        ->assertSeeInOrder(['b-file.txt', 'a-file.txt']);
});

test('a user can create a folder in the current directory', function (): void {
    [$user, $share] = shareWithMember();

    Livewire::actingAs($user)
        ->test('pages::shares.show', ['share' => $share])
        ->set('newFolderName', 'Reports')
        ->call('createFolder')
        ->assertHasNoErrors();

    expect(storageFor($share)->isDirectory('Reports'))->toBeTrue();
});

test('a folder is created inside the current folder', function (): void {
    [$user, $share] = shareWithMember();
    seedFolder($share, 'Parent');

    Livewire::actingAs($user)
        ->test('pages::shares.show', ['share' => $share])
        ->call('open', 'Parent')
        ->set('newFolderName', 'Child')
        ->call('createFolder')
        ->assertHasNoErrors();

    expect(storageFor($share)->isDirectory('Parent/Child'))->toBeTrue();
});

test('creating a folder with a duplicate name is rejected', function (): void {
    [$user, $share] = shareWithMember();
    seedFolder($share, 'Reports');

    Livewire::actingAs($user)
        ->test('pages::shares.show', ['share' => $share])
        ->set('newFolderName', 'Reports')
        ->call('createFolder')
        ->assertHasErrors('newFolderName');
});

test('creating a folder with disallowed characters is rejected', function (): void {
    [$user, $share] = shareWithMember();

    Livewire::actingAs($user)
        ->test('pages::shares.show', ['share' => $share])
        ->set('newFolderName', 'a/b')
        ->call('createFolder')
        ->assertHasErrors('newFolderName');

    expect(storageFor($share)->entries())->toHaveCount(0);
});

test('renaming an entry to a disallowed name is rejected', function (): void {
    [$user, $share] = shareWithMember();
    seedFile($share, 'ok.txt');

    Livewire::actingAs($user)
        ->test('pages::shares.show', ['share' => $share])
        ->call('startRename', 'ok.txt')
        ->set('renameName', '../evil')
        ->call('rename')
        ->assertHasErrors('renameName');

    expect(storageFor($share)->exists('ok.txt'))->toBeTrue();
});

test('creating a folder with a reserved name is rejected', function (): void {
    [$user, $share] = shareWithMember();

    Livewire::actingAs($user)
        ->test('pages::shares.show', ['share' => $share])
        ->set('newFolderName', '.trash')
        ->call('createFolder')
        ->assertHasErrors('newFolderName');

    expect(storageFor($share)->exists('.trash'))->toBeFalse();
});

test('renaming an entry to a reserved name is rejected', function (): void {
    [$user, $share] = shareWithMember();
    seedFile($share, 'ok.txt');

    Livewire::actingAs($user)
        ->test('pages::shares.show', ['share' => $share])
        ->call('startRename', 'ok.txt')
        ->set('renameName', '.tmp')
        ->call('rename')
        ->assertHasErrors('renameName');

    expect(storageFor($share)->exists('ok.txt'))->toBeTrue();
});

test('renaming an entry to a partial-suffixed name is rejected', function (): void {
    [$user, $share] = shareWithMember();
    seedFile($share, 'ok.txt');

    Livewire::actingAs($user)
        ->test('pages::shares.show', ['share' => $share])
        ->call('startRename', 'ok.txt')
        ->set('renameName', 'ok.txt.partial')
        ->call('rename')
        ->assertHasErrors('renameName');

    expect(storageFor($share)->exists('ok.txt'))->toBeTrue();
});

test('a case-only rename is not blocked as a self-collision', function (): void {
    [$user, $share] = shareWithMember();
    seedFile($share, 'Photo.jpg');

    Livewire::actingAs($user)
        ->test('pages::shares.show', ['share' => $share])
        ->call('startRename', 'Photo.jpg')
        ->set('renameName', 'photo.jpg')
        ->call('rename')
        ->assertHasNoErrors();

    expect(storageFor($share)->exists('photo.jpg'))->toBeTrue();
});

test('a folder named "0" is excluded from its own move targets', function (): void {
    [$user, $share] = shareWithMember();
    seedFolder($share, '0/inner');
    seedFolder($share, 'Other');

    $component = Livewire::actingAs($user)
        ->test('pages::shares.show', ['share' => $share])
        ->call('startMove', '0');

    $targets = $component->instance()->moveTargets()->pluck('path')->all();

    expect($targets)->toContain('Other')
        ->not->toContain('0')
        ->not->toContain('0/inner');
});

test('the client cannot overwrite the locked rename path', function (): void {
    [$user, $share] = shareWithMember();

    Livewire::actingAs($user)
        ->test('pages::shares.show', ['share' => $share])
        ->set('renamePath', '../escape');
})->throws(CannotUpdateLockedPropertyException::class);

test('renaming a folder moves all of its descendants', function (): void {
    $share = Share::factory()->create();
    seedFile($share, 'A/x.txt');
    seedFile($share, 'A/sub/y.txt');

    $newPath = resolve(RenameEntry::class)->handle($share, 'A', 'B');

    $storage = storageFor($share);

    expect($newPath)->toBe('B')
        ->and($storage->exists('A'))->toBeFalse()
        ->and($storage->exists('B/x.txt'))->toBeTrue()
        ->and($storage->exists('B/sub/y.txt'))->toBeTrue();
});

test('moving a folder relocates its descendants', function (): void {
    $share = Share::factory()->create();
    seedFile($share, 'A/x.txt');
    seedFolder($share, 'C');

    $newPath = resolve(MoveEntry::class)->handle($share, 'A', 'C');

    expect($newPath)->toBe('C/A')
        ->and(storageFor($share)->exists('C/A/x.txt'))->toBeTrue()
        ->and(storageFor($share)->exists('A'))->toBeFalse();
});

test('a folder cannot be moved into its own descendant', function (): void {
    $share = Share::factory()->create();
    seedFolder($share, 'A/sub');

    $result = resolve(MoveEntry::class)->handle($share, 'A', 'A/sub');

    expect($result)->toBeNull()
        ->and(storageFor($share)->isDirectory('A'))->toBeTrue();
});

test('a user can move a file into a folder through the browser', function (): void {
    [$user, $share] = shareWithMember();
    seedFile($share, 'doc.txt');
    seedFolder($share, 'Folder');

    Livewire::actingAs($user)
        ->test('pages::shares.show', ['share' => $share])
        ->call('startMove', 'doc.txt')
        ->call('moveTo', 'Folder');

    expect(storageFor($share)->exists('Folder/doc.txt'))->toBeTrue()
        ->and(storageFor($share)->exists('doc.txt'))->toBeFalse();
});

test('moving an entry into its current folder records no activity and reports no move', function (): void {
    [$user, $share] = shareWithMember();
    seedFile($share, 'doc.txt');

    Livewire::actingAs($user)
        ->test('pages::shares.show', ['share' => $share])
        ->call('startMove', 'doc.txt')
        ->call('moveTo', '');

    expect(storageFor($share)->exists('doc.txt'))->toBeTrue();
    $this->assertDatabaseMissing('activities', ['share_id' => $share->id, 'action' => 'node.moved']);
});

test('a user can delete an entry through the browser', function (): void {
    [$user, $share] = shareWithMember();
    seedFile($share, 'doc.txt');

    Livewire::actingAs($user)
        ->test('pages::shares.show', ['share' => $share])
        ->call('delete', 'doc.txt');

    $storage = storageFor($share);

    expect($storage->exists('doc.txt'))->toBeFalse()
        ->and($storage->trashed())->toHaveCount(1);
});

test('the uploader intake renders the conflict prompt and completion listener', function (): void {
    [$user, $share] = shareWithMember();

    Livewire::actingAs($user)
        ->test('pages::shares.show', ['share' => $share])
        ->assertSeeHtml('data-test="upload-conflict"')
        ->assertSeeHtml('coffer:upload-finished');
});

test('the persistent upload panel renders through the app layout', function (): void {
    [$user, $share] = shareWithMember();

    $this->actingAs($user)
        ->get(route('shares.show', $share))
        ->assertSee('data-test="upload-progress"', false)
        ->assertSee('data-test="dismiss-upload"', false)
        ->assertSee(':style="`width: ${item.progress}%`"', false);
});

test('deleting an entry removes it from the current selection', function (): void {
    [$user, $share] = shareWithMember();
    seedFile($share, 'a.txt');
    seedFile($share, 'b.txt');

    Livewire::actingAs($user)
        ->test('pages::shares.show', ['share' => $share])
        ->set('selected', ['a.txt', 'b.txt'])
        ->call('delete', 'a.txt')
        ->assertSet('selected', ['b.txt']);
});

test('deleting the selection trashes every selected entry and clears it', function (): void {
    [$user, $share] = shareWithMember();
    seedFile($share, 'a.txt');
    seedFile($share, 'b.txt');

    Livewire::actingAs($user)
        ->test('pages::shares.show', ['share' => $share])
        ->set('selected', ['a.txt', 'b.txt'])
        ->call('deleteSelected')
        ->assertSet('selected', []);

    expect(storageFor($share)->trashed())->toHaveCount(2);
});

test('moving the selection relocates every selected entry and clears it', function (): void {
    [$user, $share] = shareWithMember();
    seedFolder($share, 'Dest');
    seedFile($share, 'a.txt');
    seedFile($share, 'b.txt');

    Livewire::actingAs($user)
        ->test('pages::shares.show', ['share' => $share])
        ->set('selected', ['a.txt', 'b.txt'])
        ->call('startBulkMove')
        ->assertSet('moveBulk', true)
        ->call('moveTo', 'Dest')
        ->assertSet('selected', [])
        ->assertSet('showMove', false);

    $storage = storageFor($share);

    expect($storage->exists('Dest/a.txt'))->toBeTrue()
        ->and($storage->exists('Dest/b.txt'))->toBeTrue();
});

test('search matches files and folders by name across every folder', function (): void {
    [$user, $share] = shareWithMember('viewer');
    seedFile($share, 'Reports/budget.txt');
    seedFile($share, 'notes.txt');

    Livewire::actingAs($user)
        ->test('pages::shares.show', ['share' => $share])
        ->set('search', 'budget')
        ->assertSee('budget.txt')
        ->assertSee('Reports/budget.txt')
        ->assertDontSee('notes.txt');
});

test('search is case-insensitive', function (): void {
    [$user, $share] = shareWithMember('viewer');
    seedFile($share, 'Proposal.txt');

    Livewire::actingAs($user)
        ->test('pages::shares.show', ['share' => $share])
        ->set('search', 'proposal')
        ->assertSee('Proposal.txt');
});

test('search excludes items in the recycle bin', function (): void {
    [$user, $share] = shareWithMember('viewer');
    seedFile($share, 'deleted-budget.txt');
    storageFor($share)->trash('deleted-budget.txt', null);

    Livewire::actingAs($user)
        ->test('pages::shares.show', ['share' => $share])
        ->set('search', 'budget')
        ->assertDontSee('deleted-budget.txt');
});

test('search excludes partial transfer files', function (): void {
    [$user, $share] = shareWithMember('viewer');
    seedFile($share, 'Reports/budget.xlsx.partial');

    Livewire::actingAs($user)
        ->test('pages::shares.show', ['share' => $share])
        ->set('search', 'budget')
        ->assertDontSee('budget.xlsx.partial');
});

test('search is scoped to the current share', function (): void {
    [$user, $share] = shareWithMember('viewer');
    $otherShare = Share::factory()->create();
    seedFile($otherShare, 'budget-elsewhere.txt');

    Livewire::actingAs($user)
        ->test('pages::shares.show', ['share' => $share])
        ->set('search', 'budget')
        ->assertDontSee('budget-elsewhere.txt');
});

test('a member can start a URL download', function (): void {
    [$user, $share] = shareWithMember();

    Queue::fake();
    app()->instance(UrlValidator::class, new UrlValidator(fn (): array => ['93.184.215.14']));

    Livewire::actingAs($user)
        ->test('pages::shares.show', ['share' => $share])
        ->set('showUrlDownload', true)
        ->set('downloadUrl', 'https://files.example/report.pdf')
        ->set('downloadName', 'report.pdf')
        ->call('startUrlDownload')
        ->assertHasNoErrors()
        ->assertSet('showUrlDownload', false)
        ->assertDispatched('remote-download-started');

    Queue::assertPushed(
        DownloadFileFromUrl::class,
        fn (DownloadFileFromUrl $job): bool => $job->shareId === $share->id
            && $job->userId === $user->id
            && $job->url === 'https://files.example/report.pdf'
            && $job->name === 'report.pdf'
            && $job->directory === '',
    );
});

test('the file name is derived from the URL when left blank', function (): void {
    [$user, $share] = shareWithMember();

    Queue::fake();
    app()->instance(UrlValidator::class, new UrlValidator(fn (): array => ['93.184.215.14']));

    Livewire::actingAs($user)
        ->test('pages::shares.show', ['share' => $share])
        ->set('downloadUrl', 'https://files.example/photos/My%20Photo.jpg')
        ->assertSet('downloadName', 'My Photo.jpg')
        ->call('startUrlDownload')
        ->assertHasNoErrors();

    Queue::assertPushed(DownloadFileFromUrl::class, fn (DownloadFileFromUrl $job): bool => $job->name === 'My Photo.jpg');
});

test('a URL download targets the currently open folder', function (): void {
    [$user, $share] = shareWithMember();
    seedFolder($share, 'Docs');

    Queue::fake();
    app()->instance(UrlValidator::class, new UrlValidator(fn (): array => ['93.184.215.14']));

    Livewire::actingAs($user)
        ->test('pages::shares.show', ['share' => $share])
        ->call('open', 'Docs')
        ->set('downloadUrl', 'https://files.example/report.pdf')
        ->call('startUrlDownload')
        ->assertHasNoErrors();

    Queue::assertPushed(DownloadFileFromUrl::class, fn (DownloadFileFromUrl $job): bool => $job->directory === 'Docs');
});

test('a URL download rejects a non-HTTP scheme', function (): void {
    [$user, $share] = shareWithMember();

    Queue::fake();

    Livewire::actingAs($user)
        ->test('pages::shares.show', ['share' => $share])
        ->set('downloadUrl', 'ftp://files.example/report.pdf')
        ->set('downloadName', 'report.pdf')
        ->call('startUrlDownload')
        ->assertHasErrors(['downloadUrl']);

    Queue::assertNothingPushed();
});

test('a URL download rejects embedded credentials', function (): void {
    [$user, $share] = shareWithMember();

    Queue::fake();

    Livewire::actingAs($user)
        ->test('pages::shares.show', ['share' => $share])
        ->set('downloadUrl', 'https://user:secret@files.example/report.pdf')
        ->set('downloadName', 'report.pdf')
        ->call('startUrlDownload')
        ->assertHasErrors(['downloadUrl']);

    Queue::assertNothingPushed();
});

test('a URL download rejects a target on a private network', function (): void {
    [$user, $share] = shareWithMember();

    Queue::fake();
    app()->instance(UrlValidator::class, new UrlValidator(fn (): array => ['10.0.0.5']));

    Livewire::actingAs($user)
        ->test('pages::shares.show', ['share' => $share])
        ->set('downloadUrl', 'http://internal.example/secret')
        ->set('downloadName', 'secret.bin')
        ->call('startUrlDownload')
        ->assertHasErrors(['downloadUrl']);

    Queue::assertNothingPushed();
});

test('URL downloads are rate limited per user', function (): void {
    [$user, $share] = shareWithMember();

    Queue::fake();
    app()->instance(UrlValidator::class, new UrlValidator(fn (): array => ['93.184.215.14']));

    foreach (range(1, 20) as $i) {
        RateLimiter::hit('url-downloads:'.$user->id, 3600);
    }

    Livewire::actingAs($user)
        ->test('pages::shares.show', ['share' => $share])
        ->set('downloadUrl', 'https://files.example/report.pdf')
        ->set('downloadName', 'report.pdf')
        ->call('startUrlDownload')
        ->assertHasErrors(['downloadUrl']);

    Queue::assertNothingPushed();
});
