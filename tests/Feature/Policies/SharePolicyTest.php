<?php

declare(strict_types=1);

use App\Models\Share;
use App\Models\User;

test('an administrator can do everything on any share', function (): void {
    $admin = User::factory()->admin()->create();
    $share = Share::factory()->create();

    expect($admin->can('viewFiles', $share))->toBeTrue()
        ->and($admin->can('modifyFiles', $share))->toBeTrue()
        ->and($admin->can('view', $share))->toBeTrue()
        ->and($admin->can('create', Share::class))->toBeTrue()
        ->and($admin->can('update', $share))->toBeTrue()
        ->and($admin->can('delete', $share))->toBeTrue();
});

test('any signed-in user can view and modify files on every share', function (): void {
    $user = User::factory()->create();
    $share = Share::factory()->create();

    expect($user->can('viewFiles', $share))->toBeTrue()
        ->and($user->can('modifyFiles', $share))->toBeTrue()
        ->and($user->can('view', $share))->toBeTrue();
});

test('share lifecycle (create/update/delete) is denied to non-administrators', function (): void {
    $user = User::factory()->create();
    $share = Share::factory()->create();

    expect($user->can('create', Share::class))->toBeFalse()
        ->and($user->can('update', $share))->toBeFalse()
        ->and($user->can('delete', $share))->toBeFalse();
});
