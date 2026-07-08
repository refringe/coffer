<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

test('the status probe reports the current offset and total length', function (): void {
    [$user, $share] = shareWithMember();

    $this->actingAs($user);

    $url = tusCreate($share, 'report.txt', 10)->assertCreated()->headers->get('Location');

    tusPatch((string) $url, 0, 'hello')->assertNoContent();

    $response = tusHead((string) $url)
        ->assertOk()
        ->assertHeader('Upload-Offset', '5')
        ->assertHeader('Upload-Length', '10');

    expect($response->headers->get('Cache-Control'))->toContain('no-store');
});

test('an unknown upload id is not found', function (): void {
    [$user, $share] = shareWithMember();

    $this->actingAs($user);

    tusHead(route('shares.uploads.show', [$share, Str::uuid()->toString()]))->assertNotFound();
});

test('another users upload is not found rather than forbidden', function (): void {
    [$user, $share] = shareWithMember();

    $this->actingAs($user);

    $url = tusCreate($share, 'report.txt', 5)->assertCreated()->headers->get('Location');

    $this->actingAs(User::factory()->create());

    tusHead((string) $url)->assertNotFound();
});

test('an upload idle past the retention window is gone', function (): void {
    config(['coffer.upload_ttl_hours' => 48]);

    [$user, $share] = shareWithMember();

    $this->actingAs($user);

    $url = tusCreate($share, 'report.txt', 5)->assertCreated()->headers->get('Location');
    $id = basename((string) $url);

    touch($share->path.'/.tmp/uploads/'.$id, now()->subDays(3)->getTimestamp());
    touch($share->path.'/.tmp/uploads/'.$id.'.json', now()->subDays(3)->getTimestamp());

    tusHead((string) $url)->assertGone();
});

test('a fully received but unpromoted upload is promoted by the status probe', function (): void {
    [$user, $share] = shareWithMember();

    $this->actingAs($user);

    $url = tusCreate($share, 'report.txt', 5)->assertCreated()->headers->get('Location');
    $id = basename((string) $url);

    // The final chunk landed but the promotion never ran (e.g. it crashed): the partial holds every declared byte
    // while the sidecar still reads pending.
    File::put($share->path.'/.tmp/uploads/'.$id, 'hello');

    tusHead((string) $url)
        ->assertOk()
        ->assertHeader('Upload-Offset', '5')
        ->assertHeader('Upload-Length', '5');

    expect(File::get($share->path.'/report.txt'))->toBe('hello')
        ->and(storageFor($share)->pendingUpload($id)?->isCompleted())->toBeTrue();

    $this->assertDatabaseHas('activities', [
        'share_id' => $share->id,
        'action' => 'file.uploaded',
        'path' => 'report.txt',
    ]);
});

test('a pending sidecar whose partial vanished after promotion is healed to completed', function (): void {
    [$user, $share] = shareWithMember();

    $this->actingAs($user);

    $url = tusCreate($share, 'report.txt', 5)->assertCreated()->headers->get('Location');
    $id = basename((string) $url);

    // The promotion moved the partial but crashed before rewriting the sidecar.
    File::move($share->path.'/.tmp/uploads/'.$id, $share->path.'/report.txt');

    tusHead((string) $url)
        ->assertOk()
        ->assertHeader('Upload-Offset', '5');

    expect(storageFor($share)->pendingUpload($id)?->isCompleted())->toBeTrue();
});

test('a completed upload reports its full length as the offset', function (): void {
    [$user, $share] = shareWithMember();

    $this->actingAs($user);

    $url = tusCreate($share, 'report.txt', 5)->assertCreated()->headers->get('Location');

    tusPatch((string) $url, 0, 'hello')->assertNoContent();

    tusHead((string) $url)
        ->assertOk()
        ->assertHeader('Upload-Offset', '5')
        ->assertHeader('Upload-Length', '5');
});
