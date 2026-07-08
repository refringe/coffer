<?php

declare(strict_types=1);

use App\Enums\RemoteDownloadState;
use App\Models\Share;
use App\Support\RemoteDownloadStatus;
use Illuminate\Support\Str;

test('a guest is redirected to login', function (): void {
    [, $share] = shareWithMember();

    $this->get(route('shares.remote-downloads.show', [$share, (string) Str::uuid()]))
        ->assertRedirect(route('login'));
});

test('a member can read a download status', function (): void {
    [$user, $share] = shareWithMember();

    $downloadId = (string) Str::uuid();

    RemoteDownloadStatus::put($share->id, $downloadId, RemoteDownloadState::Downloading, 25, 100);

    $this->actingAs($user)
        ->getJson(route('shares.remote-downloads.show', [$share, $downloadId]))
        ->assertSuccessful()
        ->assertExactJson([
            'status' => 'downloading',
            'received' => 25,
            'total' => 100,
            'error' => null,
        ]);
});

test('an unknown download id is not found', function (): void {
    [$user, $share] = shareWithMember();

    $this->actingAs($user)
        ->getJson(route('shares.remote-downloads.show', [$share, (string) Str::uuid()]))
        ->assertNotFound();
});

test('a failed download reports its error', function (): void {
    [$user, $share] = shareWithMember();

    $downloadId = (string) Str::uuid();

    RemoteDownloadStatus::put($share->id, $downloadId, RemoteDownloadState::Failed, error: 'The server responded with HTTP 404.');

    $this->actingAs($user)
        ->getJson(route('shares.remote-downloads.show', [$share, $downloadId]))
        ->assertSuccessful()
        ->assertJsonPath('status', 'failed')
        ->assertJsonPath('error', 'The server responded with HTTP 404.');
});

test('a status is scoped to its own share', function (): void {
    [$user, $share] = shareWithMember();
    $other = Share::factory()->create();

    $downloadId = (string) Str::uuid();

    RemoteDownloadStatus::put($share->id, $downloadId, RemoteDownloadState::Downloading);

    $this->actingAs($user)
        ->getJson(route('shares.remote-downloads.show', [$other, $downloadId]))
        ->assertNotFound();
});
