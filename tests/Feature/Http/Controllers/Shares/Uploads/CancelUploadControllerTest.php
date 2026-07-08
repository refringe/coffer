<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

test('cancelling an upload removes its partial and sidecar', function (): void {
    [$user, $share] = shareWithMember();

    $this->actingAs($user);

    $url = tusCreate($share, 'report.txt', 10)->assertCreated()->headers->get('Location');
    $id = basename((string) $url);

    tusPatch((string) $url, 0, 'hello')->assertNoContent();

    $this->withHeaders(['Tus-Resumable' => '1.0.0'])
        ->delete((string) $url)
        ->assertNoContent();

    expect(File::exists($share->path.'/.tmp/uploads/'.$id))->toBeFalse()
        ->and(File::exists($share->path.'/.tmp/uploads/'.$id.'.json'))->toBeFalse();
});

test('an unknown upload id is not found', function (): void {
    [$user, $share] = shareWithMember();

    $this->actingAs($user)
        ->withHeaders(['Tus-Resumable' => '1.0.0'])
        ->delete(route('shares.uploads.destroy', [$share, Str::uuid()->toString()]))
        ->assertNotFound();
});

test('another users upload is not found rather than forbidden', function (): void {
    [$user, $share] = shareWithMember();

    $this->actingAs($user);

    $url = tusCreate($share, 'report.txt', 5)->assertCreated()->headers->get('Location');

    $this->actingAs(User::factory()->create())
        ->withHeaders(['Tus-Resumable' => '1.0.0'])
        ->delete((string) $url)
        ->assertNotFound();
});

test('a guest is redirected to login', function (): void {
    [, $share] = shareWithMember();

    $this->delete(route('shares.uploads.destroy', [$share, Str::uuid()->toString()]))
        ->assertRedirect(route('login'));
});
