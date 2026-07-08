<?php

declare(strict_types=1);

use App\Exceptions\UploadWriteConflictException;
use App\Models\Share;
use App\Models\User;
use App\Support\PendingUpload;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * Open an upload session and return its URL.
 */
function tusCreateUrl(Share $share, string $name, int $length, string $directory = '', string $onConflict = 'keep_both'): string
{
    $location = tusCreate($share, $name, $length, $directory, $onConflict)->assertCreated()->headers->get('Location');

    assert(is_string($location));

    return $location;
}

test('a chunk appends at the declared offset and reports the new one', function (): void {
    [$user, $share] = shareWithMember();

    $this->actingAs($user);

    $url = tusCreateUrl($share, 'report.txt', 10);

    tusPatch($url, 0, 'hello')
        ->assertNoContent()
        ->assertHeader('Upload-Offset', '5');

    $id = basename($url);

    expect(File::get($share->path.'/.tmp/uploads/'.$id))->toBe('hello');
});

test('the final chunk promotes the upload into the share and records the activity', function (): void {
    [$user, $share] = shareWithMember();

    $this->actingAs($user);

    $url = tusCreateUrl($share, 'report.txt', 10, directory: '');
    $id = basename($url);

    tusPatch($url, 0, 'hello')->assertNoContent();
    tusPatch($url, 5, 'world')
        ->assertNoContent()
        ->assertHeader('Upload-Offset', '10');

    expect(File::get($share->path.'/report.txt'))->toBe('helloworld')
        ->and(File::exists($share->path.'/.tmp/uploads/'.$id))->toBeFalse()
        ->and(storageFor($share)->pendingUpload($id)?->isCompleted())->toBeTrue();

    $this->assertDatabaseHas('activities', [
        'share_id' => $share->id,
        'action' => 'file.uploaded',
        'path' => 'report.txt',
    ]);
});

test('an upload into a subdirectory lands there', function (): void {
    [$user, $share] = shareWithMember();
    seedFolder($share, 'Docs');

    $this->actingAs($user);

    tusUpload($share, 'a.txt', 'abc', directory: 'Docs')->assertNoContent();

    expect(File::get($share->path.'/Docs/a.txt'))->toBe('abc');
});

test('a mismatched offset is refused and the partial is untouched', function (): void {
    [$user, $share] = shareWithMember();

    $this->actingAs($user);

    $url = tusCreateUrl($share, 'report.txt', 10);

    tusPatch($url, 0, 'hello')->assertNoContent();
    tusPatch($url, 2, 'zzz')->assertConflict();

    expect(File::get($share->path.'/.tmp/uploads/'.basename($url)))->toBe('hello');
});

test('a chunk without the tus content type is refused', function (): void {
    [$user, $share] = shareWithMember();

    $this->actingAs($user);

    $url = tusCreateUrl($share, 'report.txt', 5);

    $this->call('PATCH', $url, [], [], [], [
        'HTTP_TUS_RESUMABLE' => '1.0.0',
        'HTTP_UPLOAD_OFFSET' => '0',
        'CONTENT_TYPE' => 'application/octet-stream',
    ], 'hello')->assertStatus(415);
});

test('a chunk without a valid offset header is refused', function (): void {
    [$user, $share] = shareWithMember();

    $this->actingAs($user);

    $url = tusCreateUrl($share, 'report.txt', 5);

    $this->call('PATCH', $url, [], [], [], [
        'HTTP_TUS_RESUMABLE' => '1.0.0',
        'CONTENT_TYPE' => 'application/offset+octet-stream',
    ], 'hello')->assertBadRequest();
});

test('bytes past the declared length are refused and the partial rolls back', function (): void {
    [$user, $share] = shareWithMember();

    $this->actingAs($user);

    $url = tusCreateUrl($share, 'report.txt', 4);

    tusPatch($url, 0, 'toolong')->assertBadRequest();

    expect(File::size($share->path.'/.tmp/uploads/'.basename($url)))->toBe(0);
});

test('an unknown upload id is not found', function (): void {
    [$user, $share] = shareWithMember();

    $this->actingAs($user);

    tusPatch(route('shares.uploads.append', [$share, Str::uuid()->toString()]), 0, 'hi')->assertNotFound();
});

test('a non-uuid upload segment misses the route entirely', function (): void {
    [$user, $share] = shareWithMember();

    $this->actingAs($user);

    tusPatch(url('shares/'.$share->slug.'/uploads/not-a-uuid'), 0, 'hi')->assertNotFound();
});

test('another users upload is not found rather than forbidden', function (): void {
    [$user, $share] = shareWithMember();

    $this->actingAs($user);

    $url = tusCreateUrl($share, 'report.txt', 5);

    $this->actingAs(User::factory()->create());

    tusPatch($url, 0, 'hello')->assertNotFound();
});

test('an upload idle past the retention window is gone and its artifacts are removed', function (): void {
    config(['coffer.upload_ttl_hours' => 48]);

    [$user, $share] = shareWithMember();

    $this->actingAs($user);

    $url = tusCreateUrl($share, 'report.txt', 5);
    $id = basename($url);

    touch($share->path.'/.tmp/uploads/'.$id, now()->subDays(3)->getTimestamp());
    touch($share->path.'/.tmp/uploads/'.$id.'.json', now()->subDays(3)->getTimestamp());

    tusPatch($url, 0, 'hello')->assertGone();

    expect(File::exists($share->path.'/.tmp/uploads/'.$id))->toBeFalse()
        ->and(File::exists($share->path.'/.tmp/uploads/'.$id.'.json'))->toBeFalse();
});

test('a retried final chunk after promotion is an idempotent success', function (): void {
    [$user, $share] = shareWithMember();

    $this->actingAs($user);

    $url = tusCreateUrl($share, 'report.txt', 5);

    tusPatch($url, 0, 'hello')->assertNoContent();

    tusPatch($url, 5, '')
        ->assertNoContent()
        ->assertHeader('Upload-Offset', '5');

    expect(File::get($share->path.'/report.txt'))->toBe('hello')
        ->and(File::exists($share->path.'/report (1).txt'))->toBeFalse();
});

test('a retried chunk at the wrong offset after promotion is a conflict', function (): void {
    [$user, $share] = shareWithMember();

    $this->actingAs($user);

    $url = tusCreateUrl($share, 'report.txt', 5);

    tusPatch($url, 0, 'hello')->assertNoContent();
    tusPatch($url, 0, 'hello')->assertConflict();
});

test('a keep-both conflict promotes under a unique name', function (): void {
    [$user, $share] = shareWithMember();
    seedFile($share, 'doc.txt', 'original');

    $this->actingAs($user);

    tusUpload($share, 'doc.txt', 'fresh', onConflict: 'keep_both')->assertNoContent();

    expect(File::get($share->path.'/doc.txt'))->toBe('original')
        ->and(File::get($share->path.'/doc (1).txt'))->toBe('fresh');
});

test('a replace conflict trashes the original and promotes under its name', function (): void {
    [$user, $share] = shareWithMember();
    seedFile($share, 'doc.txt', 'original');

    $this->actingAs($user);

    tusUpload($share, 'doc.txt', 'fresh', onConflict: 'replace')->assertNoContent();

    expect(File::get($share->path.'/doc.txt'))->toBe('fresh')
        ->and(storageFor($share)->trashed())->toHaveCount(1);
});

test('a replace conflict never destroys a folder of the same name', function (): void {
    [$user, $share] = shareWithMember();
    seedFolder($share, 'doc');
    seedFile($share, 'doc/inside.txt', 'kept');

    $this->actingAs($user);

    tusUpload($share, 'doc', 'fresh', onConflict: 'replace')->assertNoContent();

    expect(File::get($share->path.'/doc/inside.txt'))->toBe('kept')
        ->and(File::get($share->path.'/doc (1)'))->toBe('fresh');
});

test('a concurrently locked upload is refused with 423', function (): void {
    [$user, $share] = shareWithMember();
    $storage = fakeShareStorage();

    $id = Str::uuid()->toString();

    $storage->shouldReceive('pendingUpload')->andReturn(new PendingUpload(
        id: $id,
        userId: $user->id,
        name: 'report.txt',
        directory: '',
        length: 10,
        onConflict: 'keep_both',
        createdAt: now()->getTimestamp(),
        completedAt: null,
    ));
    $storage->shouldReceive('uploadLastActivity')->andReturn(now()->getTimestamp());
    $storage->shouldReceive('appendUpload')->andThrow(new UploadWriteConflictException());

    $this->actingAs($user);

    tusPatch(route('shares.uploads.append', [$share, $id]), 0, 'hello')->assertStatus(423);
});
