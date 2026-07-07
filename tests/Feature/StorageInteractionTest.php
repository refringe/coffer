<?php

declare(strict_types=1);

use App\Enums\NodeType;
use App\Support\Entry;
use App\Support\TrashedEntry;
use Illuminate\Http\UploadedFile;

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

test('a successful upload stores the file once and records the activity', function (): void {
    [$user, $share] = shareWithMember();
    $storage = fakeShareStorage();

    $storage->shouldReceive('exists')->andReturn(false);
    $storage->shouldReceive('storeUpload')->once()->andReturn(fakeEntry());

    $this->actingAs($user)
        ->post(route('shares.upload', $share), [
            'file' => UploadedFile::fake()->createWithContent('report.txt', 'hello'),
            'directory' => '',
            'on_conflict' => 'keep_both',
        ])
        ->assertCreated()
        ->assertJson(['status' => 'completed']);

    $this->assertDatabaseHas('activities', [
        'share_id' => $share->id,
        'action' => 'file.uploaded',
        'path' => 'report.txt',
    ]);
});

test('a skip-conflict upload never writes to storage', function (): void {
    [$user, $share] = shareWithMember();
    $storage = fakeShareStorage();

    $storage->shouldReceive('exists')->once()->andReturn(true);
    $storage->shouldNotReceive('storeUpload');

    $this->actingAs($user)
        ->post(route('shares.upload', $share), [
            'file' => UploadedFile::fake()->createWithContent('report.txt', 'hello'),
            'directory' => '',
            'on_conflict' => 'skip',
        ])
        ->assertOk()
        ->assertJson(['status' => 'skipped']);

    $this->assertDatabaseMissing('activities', ['share_id' => $share->id, 'action' => 'file.uploaded']);
});

test('a replace-conflict upload trashes the existing file before storing', function (): void {
    [$user, $share] = shareWithMember();
    $storage = fakeShareStorage();

    $storage->shouldReceive('exists')->andReturn(true);
    $storage->shouldReceive('isDirectory')->once()->with('report.txt')->andReturn(false);
    $storage->shouldReceive('trash')->once()->with('report.txt', $user->id)->andReturn(fakeTrashedEntry());
    $storage->shouldReceive('storeUpload')->once()->andReturn(fakeEntry());

    $this->actingAs($user)
        ->post(route('shares.upload', $share), [
            'file' => UploadedFile::fake()->createWithContent('report.txt', 'hello'),
            'directory' => '',
            'on_conflict' => 'replace',
        ])
        ->assertCreated()
        ->assertJson(['status' => 'completed']);
});

test('a replace-conflict upload never destroys a folder of the same name', function (): void {
    [$user, $share] = shareWithMember();
    $storage = fakeShareStorage();

    $storage->shouldReceive('exists')->andReturn(true);
    $storage->shouldReceive('isDirectory')->once()->with('report.txt')->andReturn(true);
    $storage->shouldNotReceive('trash');
    $storage->shouldNotReceive('delete');
    $storage->shouldReceive('uniqueName')->once()->with('', 'report.txt')->andReturn('report (1).txt');
    $storage->shouldReceive('storeUpload')->once()->andReturn(fakeEntry('report (1).txt'));

    $this->actingAs($user)
        ->post(route('shares.upload', $share), [
            'file' => UploadedFile::fake()->createWithContent('report.txt', 'hello'),
            'directory' => '',
            'on_conflict' => 'replace',
        ])
        ->assertCreated()
        ->assertJson(['status' => 'completed']);
});

test('an oversize upload is rejected before storage is ever resolved', function (): void {
    config(['coffer.max_file_size' => 1000]);

    [$user, $share] = shareWithMember();
    $storage = fakeShareStorage();

    $storage->shouldNotReceive('exists');
    $storage->shouldNotReceive('storeUpload');

    $this->actingAs($user)
        ->post(route('shares.upload', $share), [
            'file' => UploadedFile::fake()->create('big.bin', 2), // 2 KB > 1000 bytes
            'directory' => '',
            'on_conflict' => 'keep_both',
        ])
        ->assertStatus(422)
        ->assertJson(['status' => 'too_large']);

    $this->assertDatabaseMissing('activities', ['share_id' => $share->id, 'action' => 'file.uploaded']);
});

test('a storage failure during upload surfaces an error and records no activity', function (): void {
    [$user, $share] = shareWithMember();
    $storage = fakeShareStorage();

    $storage->shouldReceive('exists')->andReturn(false);
    $storage->shouldReceive('storeUpload')->andThrow(new RuntimeException('disk full'));

    $this->actingAs($user)
        ->post(route('shares.upload', $share), [
            'file' => UploadedFile::fake()->createWithContent('report.txt', 'hello'),
            'directory' => '',
            'on_conflict' => 'keep_both',
        ])
        ->assertStatus(422)
        ->assertJson(['status' => 'error']);

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
