<?php

declare(strict_types=1);

use App\Actions\Shares\Uploads\FinalizeUpload;
use App\Models\Share;
use App\Models\User;
use App\Support\PendingUpload;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * Seed a fully received pending upload (sidecar plus a partial holding every declared byte) ready for promotion.
 */
function seedPendingUpload(Share $share, User $user, string $name, string $contents, string $directory = '', string $onConflict = 'keep_both'): PendingUpload
{
    $upload = new PendingUpload(
        id: Str::uuid()->toString(),
        userId: $user->id,
        name: $name,
        directory: $directory,
        length: mb_strlen($contents, '8bit'),
        onConflict: $onConflict,
        createdAt: now()->getTimestamp(),
        completedAt: null,
    );

    storageFor($share)->putPendingUpload($upload);
    File::put($share->path.'/.tmp/uploads/'.$upload->id, $contents);

    return $upload;
}

test('promotion moves the partial into place, records activity, and keeps a completed sidecar', function (): void {
    [$user, $share] = shareWithMember();
    $upload = seedPendingUpload($share, $user, 'report.txt', 'hello');

    $entry = resolve(FinalizeUpload::class)->handle($share, $upload, $user);

    expect($entry->path)->toBe('report.txt')
        ->and(File::get($share->path.'/report.txt'))->toBe('hello')
        ->and(File::exists($share->path.'/.tmp/uploads/'.$upload->id))->toBeFalse()
        ->and(storageFor($share)->pendingUpload($upload->id)?->isCompleted())->toBeTrue();

    $this->assertDatabaseHas('activities', [
        'share_id' => $share->id,
        'action' => 'file.uploaded',
        'path' => 'report.txt',
    ]);
});

test('promotion recreates a target directory deleted mid-upload', function (): void {
    [$user, $share] = shareWithMember();
    $upload = seedPendingUpload($share, $user, 'report.txt', 'hello', directory: 'Docs');

    $entry = resolve(FinalizeUpload::class)->handle($share, $upload, $user);

    expect($entry->path)->toBe('Docs/report.txt')
        ->and(File::get($share->path.'/Docs/report.txt'))->toBe('hello');
});

test('a replace conflict routes the original through the recycle bin with the uploaders id', function (): void {
    [$user, $share] = shareWithMember();
    seedFile($share, 'report.txt', 'original');
    $upload = seedPendingUpload($share, $user, 'report.txt', 'fresh', onConflict: 'replace');

    resolve(FinalizeUpload::class)->handle($share, $upload, $user);

    $trashed = storageFor($share)->trashed();

    expect(File::get($share->path.'/report.txt'))->toBe('fresh')
        ->and($trashed)->toHaveCount(1)
        ->and($trashed->first()->deletedBy)->toBe($user->id);
});

test('a keep-both conflict promotes under a unique name', function (): void {
    [$user, $share] = shareWithMember();
    seedFile($share, 'report.txt', 'original');
    $upload = seedPendingUpload($share, $user, 'report.txt', 'fresh', onConflict: 'keep_both');

    $entry = resolve(FinalizeUpload::class)->handle($share, $upload, $user);

    expect($entry->path)->toBe('report (1).txt')
        ->and(File::get($share->path.'/report.txt'))->toBe('original')
        ->and(File::get($share->path.'/report (1).txt'))->toBe('fresh');
});

test('a replace conflict against a folder falls back to keeping both', function (): void {
    [$user, $share] = shareWithMember();
    seedFolder($share, 'report.txt');
    $upload = seedPendingUpload($share, $user, 'report.txt', 'fresh', onConflict: 'replace');

    $entry = resolve(FinalizeUpload::class)->handle($share, $upload, $user);

    expect($entry->path)->toBe('report (1).txt')
        ->and(File::isDirectory($share->path.'/report.txt'))->toBeTrue();
});
