<?php

declare(strict_types=1);

use App\Enums\NodeType;
use App\Support\Entry;
use App\Support\PendingUpload;
use App\Support\TrashedEntry;
use Mockery\MockInterface;

/**
 * Interaction (mockist) tests: the storage backend is replaced with a Mockery mock so we can assert *which* storage
 * methods the orchestration layer calls (or must not call), and inject failures, without touching the real disk.
 * Behavior/round-trip correctness lives in the real-filesystem suites.
 */
function fakeEntry(string $path = 'report.txt'): Entry
{
    return new Entry(basename($path), NodeType::File, $path, 5, 'text/plain', 0);
}

function fakeTrashedEntry(string $path = 'report.txt'): TrashedEntry
{
    return new TrashedEntry('id', basename($path), NodeType::File, $path, 5, 0, null);
}

/**
 * Expect the pending-upload session bookkeeping every mocked tus upload performs: the sidecar write at creation (and
 * again at completion), the sidecar read-back on later requests, and the activity probe of the expiry checks.
 */
function expectUploadSession(MockInterface $storage): void
{
    $created = null;

    $storage->shouldReceive('putPendingUpload')->andReturnUsing(function (PendingUpload $upload) use (&$created): void {
        $created ??= $upload;
    });
    $storage->shouldReceive('pendingUpload')->andReturnUsing(function (string $id) use (&$created): ?PendingUpload {
        return $created;
    });
    $storage->shouldReceive('uploadLastActivity')->andReturn(now()->getTimestamp());
}

test('a successful upload stores the file once and records the activity', function (): void {
    [$user, $share] = shareWithMember();
    $storage = fakeShareStorage();

    expectUploadSession($storage);
    $storage->shouldReceive('appendUpload')->once()->andReturn(5);
    $storage->shouldReceive('exists')->andReturn(false);
    $storage->shouldReceive('move')->once();
    $storage->shouldReceive('entry')->andReturn(fakeEntry());

    $this->actingAs($user);

    tusUpload($share, 'report.txt', 'hello')->assertNoContent();

    $this->assertDatabaseHas('activities', [
        'share_id' => $share->id,
        'action' => 'file.uploaded',
        'path' => 'report.txt',
    ]);
});

test('a replace-conflict upload trashes the existing file before promotion', function (): void {
    [$user, $share] = shareWithMember();
    $storage = fakeShareStorage();

    expectUploadSession($storage);
    $storage->shouldReceive('appendUpload')->once()->andReturn(5);
    $storage->shouldReceive('exists')->andReturn(true);
    $storage->shouldReceive('isDirectory')->once()->with('report.txt')->andReturn(false);
    $storage->shouldReceive('trash')->once()->with('report.txt', $user->id)->andReturn(fakeTrashedEntry());
    $storage->shouldReceive('move')->once();
    $storage->shouldReceive('entry')->andReturn(fakeEntry());

    $this->actingAs($user);

    tusUpload($share, 'report.txt', 'hello', onConflict: 'replace')->assertNoContent();
});

test('a replace-conflict upload never destroys a folder of the same name', function (): void {
    [$user, $share] = shareWithMember();
    $storage = fakeShareStorage();

    expectUploadSession($storage);
    $storage->shouldReceive('appendUpload')->once()->andReturn(5);
    $storage->shouldReceive('exists')->andReturn(true);
    $storage->shouldReceive('isDirectory')->once()->with('report.txt')->andReturn(true);
    $storage->shouldNotReceive('trash');
    $storage->shouldNotReceive('delete');
    $storage->shouldReceive('uniqueName')->once()->with('', 'report.txt')->andReturn('report (1).txt');
    $storage->shouldReceive('move')->once();
    $storage->shouldReceive('entry')->andReturn(fakeEntry('report (1).txt'));

    $this->actingAs($user);

    tusUpload($share, 'report.txt', 'hello', onConflict: 'replace')->assertNoContent();
});

test('an oversize upload is rejected at creation before any bytes or state exist', function (): void {
    config(['coffer.max_file_size' => 1000]);

    [$user, $share] = shareWithMember();
    $storage = fakeShareStorage();

    $storage->shouldNotReceive('putPendingUpload');
    $storage->shouldNotReceive('appendUpload');

    $this->actingAs($user);

    tusCreate($share, 'big.bin', 2048)
        ->assertStatus(413)
        ->assertHeader('Tus-Max-Size', '1000');

    $this->assertDatabaseMissing('activities', ['share_id' => $share->id, 'action' => 'file.uploaded']);
});

test('a storage failure during upload surfaces a server error and records no activity', function (): void {
    [$user, $share] = shareWithMember();
    $storage = fakeShareStorage();

    expectUploadSession($storage);
    $storage->shouldReceive('appendUpload')->andThrow(new RuntimeException('disk full'));

    $this->actingAs($user);

    tusUpload($share, 'report.txt', 'hello')->assertServerError();

    $this->assertDatabaseMissing('activities', ['share_id' => $share->id, 'action' => 'file.uploaded']);
});

test('downloading a missing file checks existence but never streams', function (): void {
    [$user, $share] = shareWithMember();
    $storage = fakeShareStorage();

    $storage->shouldReceive('exists')->once()->andReturn(false);
    $storage->shouldNotReceive('download');

    $this->actingAs($user)
        ->get(route('shares.download', ['share' => $share, 'path' => 'gone.txt']))
        ->assertNotFound();
});
