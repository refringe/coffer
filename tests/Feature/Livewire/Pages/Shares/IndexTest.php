<?php

declare(strict_types=1);

use App\Models\Share;
use App\Models\User;
use Illuminate\Support\Number;
use Livewire\Livewire;

test('the shares list loads for an authenticated user', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('shares.index'))->assertOk();
});

test('every signed-in user sees every share', function (): void {
    $user = User::factory()->create();
    Share::factory()->create(['name' => 'Alpha Share']);
    Share::factory()->create(['name' => 'Beta Share']);

    Livewire::actingAs($user)
        ->test('pages::shares.index')
        ->assertSee('Alpha Share')
        ->assertSee('Beta Share');
});

test('an administrator sees every share', function (): void {
    $admin = User::factory()->admin()->create();
    Share::factory()->create(['name' => 'Alpha Share']);
    Share::factory()->create(['name' => 'Beta Share']);

    Livewire::actingAs($admin)
        ->test('pages::shares.index')
        ->assertSee('Alpha Share')
        ->assertSee('Beta Share');
});

test('the shares index shows per-share storage usage, ignoring folders and trash', function (): void {
    [$user, $share] = shareWithMember('viewer');

    seedFile($share, 'small.bin', str_repeat('a', 2048));
    seedFolder($share, 'EmptyFolder');
    seedFile($share, 'big.bin', str_repeat('b', 999_999));
    storageFor($share)->trash('big.bin', null);

    Livewire::actingAs($user)
        ->test('pages::shares.index')
        ->assertSee(Number::fileSize(2048));
});
