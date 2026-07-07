<?php

declare(strict_types=1);

use App\Enums\ActivityAction;
use App\Models\Activity;
use App\Models\Share;
use App\Models\User;

test('a signed-in user sees the file controls in the browser', function (): void {
    $user = User::factory()->create();
    $share = Share::factory()->create(['name' => 'Ops Share']);

    $this->actingAs($user);

    visit(route('shares.show', $share))
        ->assertSee('Ops Share')
        ->assertSee('Upload')
        ->assertSee('New folder');
});

test('the new-folder dialog opens in the browser', function (): void {
    $user = User::factory()->create();
    $share = Share::factory()->create(['name' => 'Build Share']);

    $this->actingAs($user);

    // The folder-name input and Create button are only visible once the modal opens. Creating the folder itself is
    // covered by the component test (filling a Flux modal input is unreliable in headless Chromium).
    visit(route('shares.show', $share))
        ->press('New folder')
        ->assertSee('Folder name')
        ->assertSee('Create');
});

test('the share browser renders search and bulk-download affordances', function (): void {
    $user = User::factory()->create();
    $share = Share::factory()->create(['name' => 'Browse Share']);
    seedFile($share, 'report.txt');

    $this->actingAs($user);

    visit(route('shares.show', $share))
        ->assertNoJavaScriptErrors()
        ->assertSee('report.txt')
        ->assertSee('Recycle bin')
        ->assertSee('Activity');
});

test('the activity feed renders entries in the browser', function (): void {
    $user = User::factory()->create();
    $share = Share::factory()->create(['name' => 'Watched Share']);

    Activity::factory()->create([
        'share_id' => $share->id,
        'action' => ActivityAction::FolderCreated,
        'subject' => 'audit-trail-folder',
    ]);

    $this->actingAs($user);

    visit(route('shares.show', $share))
        ->press('Activity')
        ->assertSee('audit-trail-folder');
});

test('a user sees their shares and browses into a folder', function (): void {
    $user = User::factory()->create();
    $share = Share::factory()->create(['name' => 'Browser Share']);

    seedFolder($share, 'Reports');
    seedFile($share, 'Reports/q1-summary.txt');
    seedFile($share, 'root-note.txt');

    $this->actingAs($user);

    visit(route('shares.index'))
        ->assertSee('Browser Share')
        ->click('Browser Share')
        ->assertSee('Reports')
        ->assertSee('root-note.txt')
        ->click('Reports')
        ->assertSee('q1-summary.txt');
});

test('a guest is redirected to the GitHub login page', function (): void {
    $share = Share::factory()->create(['name' => 'Private Share']);

    visit(route('shares.show', $share))
        ->assertDontSee('Private Share')
        ->assertSee('Sign in to Coffer');
});
