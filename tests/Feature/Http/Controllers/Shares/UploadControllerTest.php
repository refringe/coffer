<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;

/**
 * Build a fake uploaded file with the given name and contents.
 */
function uploadedFile(string $name = 'doc.txt', string $contents = 'hello'): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, $contents);
}

test('an editor can upload a file to the share root', function (): void {
    [$user, $share] = shareWithMember();

    $this->actingAs($user)
        ->post(route('shares.upload', $share), [
            'file' => uploadedFile('report.txt', 'the body'),
            'directory' => '',
            'on_conflict' => 'keep_both',
        ])
        ->assertCreated()
        ->assertJson(['status' => 'completed']);

    $storage = storageFor($share);

    expect($storage->exists('report.txt'))->toBeTrue();
    $this->assertDatabaseHas('activities', ['share_id' => $share->id, 'action' => 'file.uploaded', 'path' => 'report.txt']);
});

test('a file can be uploaded into a subdirectory', function (): void {
    [$user, $share] = shareWithMember();
    seedFolder($share, 'Docs');

    $this->actingAs($user)
        ->post(route('shares.upload', $share), [
            'file' => uploadedFile('a.txt'),
            'directory' => 'Docs',
        ])
        ->assertCreated();

    expect(storageFor($share)->exists('Docs/a.txt'))->toBeTrue();
});

test('a conflicting upload can be skipped', function (): void {
    [$user, $share] = shareWithMember();
    seedFile($share, 'doc.txt', 'original');

    $this->actingAs($user)
        ->post(route('shares.upload', $share), [
            'file' => uploadedFile('doc.txt', 'replacement'),
            'on_conflict' => 'skip',
        ])
        ->assertOk()
        ->assertJson(['status' => 'skipped']);

    expect(File::get($share->path.'/doc.txt'))->toBe('original');
});

test('a conflicting upload can replace the existing file', function (): void {
    [$user, $share] = shareWithMember();
    seedFile($share, 'doc.txt', 'original');

    $this->actingAs($user)
        ->post(route('shares.upload', $share), [
            'file' => uploadedFile('doc.txt', 'replacement'),
            'on_conflict' => 'replace',
        ])
        ->assertCreated();

    expect(File::get($share->path.'/doc.txt'))->toBe('replacement');
});

test('a conflicting upload can keep both copies', function (): void {
    [$user, $share] = shareWithMember();
    seedFile($share, 'doc.txt', 'original');

    $this->actingAs($user)
        ->post(route('shares.upload', $share), [
            'file' => uploadedFile('doc.txt', 'second'),
            'on_conflict' => 'keep_both',
        ])
        ->assertCreated();

    $storage = storageFor($share);

    expect($storage->exists('doc.txt'))->toBeTrue()
        ->and($storage->exists('doc (1).txt'))->toBeTrue();
});

test('an upload over the size limit is rejected', function (): void {
    config(['coffer.max_file_size' => 5]);

    [$user, $share] = shareWithMember();

    $this->actingAs($user)
        ->post(route('shares.upload', $share), [
            'file' => uploadedFile('big.txt', 'way too long'),
        ])
        ->assertStatus(422)
        ->assertJson(['status' => 'too_large']);

    expect(storageFor($share)->exists('big.txt'))->toBeFalse();
});

test('a traversal directory is rejected', function (): void {
    [$user, $share] = shareWithMember();

    $this->actingAs($user)
        ->post(route('shares.upload', $share), [
            'file' => uploadedFile(),
            'directory' => '../escape',
        ])
        ->assertInvalid('directory');
});

test('an upload targeting a reserved area is rejected', function (): void {
    [$user, $share] = shareWithMember();

    $this->actingAs($user)
        ->post(route('shares.upload', $share), [
            'file' => uploadedFile(),
            'directory' => '.trash/injected',
        ])
        ->assertInvalid('directory');

    expect(storageFor($share)->exists('.trash/injected'))->toBeFalse();
});

test('an upload whose own name is reserved is rejected', function (): void {
    [$user, $share] = shareWithMember();

    $this->actingAs($user)
        ->post(route('shares.upload', $share), [
            'file' => uploadedFile('.trash', 'x'),
            'directory' => '',
        ])
        ->assertInvalid('file');

    expect(storageFor($share)->exists('.trash'))->toBeFalse();
});
