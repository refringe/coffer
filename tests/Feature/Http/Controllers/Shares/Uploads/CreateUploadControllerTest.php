<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;

test('creating an upload records a sidecar and an empty partial and returns the upload url', function (): void {
    [$user, $share] = shareWithMember();

    $this->actingAs($user);

    $response = tusCreate($share, 'report.txt', 1024, directory: '', onConflict: 'keep_both')
        ->assertCreated()
        ->assertHeader('Tus-Resumable', '1.0.0')
        ->assertHeaderMissing('Upload-Offset');

    $location = $response->headers->get('Location');
    $id = basename((string) $location);

    expect($location)->toBe(route('shares.uploads.show', [$share, $id]))
        ->and(File::exists($share->path.'/.tmp/uploads/'.$id))->toBeTrue()
        ->and(File::size($share->path.'/.tmp/uploads/'.$id))->toBe(0)
        ->and(File::exists($share->path.'/.tmp/uploads/'.$id.'.json'))->toBeTrue()
        ->and($response->headers->get('Upload-Expires'))->not->toBeNull();
});

test('a zero-length upload is promoted at creation', function (): void {
    [$user, $share] = shareWithMember();

    $this->actingAs($user);

    tusCreate($share, 'empty.txt', 0)->assertCreated();

    expect(File::exists($share->path.'/empty.txt'))->toBeTrue()
        ->and(File::size($share->path.'/empty.txt'))->toBe(0);

    $this->assertDatabaseHas('activities', [
        'share_id' => $share->id,
        'action' => 'file.uploaded',
        'path' => 'empty.txt',
    ]);
});

test('a missing upload length is refused', function (): void {
    [$user, $share] = shareWithMember();

    $this->actingAs($user)
        ->withHeaders([
            'Tus-Resumable' => '1.0.0',
            'Upload-Metadata' => tusMetadata(['filename' => 'report.txt']),
        ])
        ->post(route('shares.uploads.store', $share))
        ->assertBadRequest();
});

test('a deferred upload length is refused', function (): void {
    [$user, $share] = shareWithMember();

    $this->actingAs($user)
        ->withHeaders([
            'Tus-Resumable' => '1.0.0',
            'Upload-Length' => '5',
            'Upload-Defer-Length' => '1',
            'Upload-Metadata' => tusMetadata(['filename' => 'report.txt']),
        ])
        ->post(route('shares.uploads.store', $share))
        ->assertBadRequest();
});

test('an upload larger than the size limit is refused at creation with the advertised maximum', function (): void {
    config(['coffer.max_file_size' => 5]);

    [$user, $share] = shareWithMember();

    $this->actingAs($user);

    tusCreate($share, 'big.bin', 10)
        ->assertStatus(413)
        ->assertHeader('Tus-Max-Size', '5');

    expect(File::exists($share->path.'/.tmp/uploads'))->toBeFalse();
});

test('a traversal directory in the metadata is refused', function (): void {
    [$user, $share] = shareWithMember();

    $this->actingAs($user);

    tusCreate($share, 'report.txt', 5, directory: '../escape')->assertUnprocessable();
});

test('a reserved directory in the metadata is refused', function (): void {
    [$user, $share] = shareWithMember();

    $this->actingAs($user);

    tusCreate($share, 'report.txt', 5, directory: '.trash/injected')->assertUnprocessable();
});

test('a reserved or path-corrupting filename is refused', function (): void {
    [$user, $share] = shareWithMember();

    $this->actingAs($user);

    tusCreate($share, '.trash', 5)->assertUnprocessable();
    tusCreate($share, 'nested/name.txt', 5)->assertUnprocessable();
});

test('metadata whose value is not valid base64 is refused', function (): void {
    [$user, $share] = shareWithMember();

    $this->actingAs($user)
        ->withHeaders([
            'Tus-Resumable' => '1.0.0',
            'Upload-Length' => '5',
            'Upload-Metadata' => 'filename !!!not-base64!!!',
            'Accept' => 'application/json',
        ])
        ->post(route('shares.uploads.store', $share))
        ->assertUnprocessable();
});

test('a guest is redirected to login', function (): void {
    [, $share] = shareWithMember();

    $this->post(route('shares.uploads.store', $share))->assertRedirect(route('login'));
});
