<?php

declare(strict_types=1);

use App\Models\User;
use Livewire\Livewire;

test('non-administrators cannot access user management', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('admin.users.index'))->assertForbidden();
});

test('administrators can view user management', function (): void {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->get(route('admin.users.index'))->assertOk();
});

test('an administrator can disable and re-enable another user', function (): void {
    $admin = User::factory()->admin()->create();
    $target = User::factory()->create();

    $component = Livewire::actingAs($admin)->test('pages::admin.users');

    $component->call('toggleDisabled', $target->id);

    expect($target->refresh()->isDisabled())->toBeTrue();

    $component->call('toggleDisabled', $target->id);
    expect($target->refresh()->isDisabled())->toBeFalse();
});

test('an administrator can delete another user', function (): void {
    $admin = User::factory()->admin()->create();
    $target = User::factory()->create();

    Livewire::actingAs($admin)
        ->test('pages::admin.users')
        ->call('deleteUser', $target->id);

    expect(User::query()->whereKey($target->id)->exists())->toBeFalse();
});

test('an administrator cannot disable or delete their own account', function (): void {
    $admin = User::factory()->admin()->create();

    $component = Livewire::actingAs($admin)->test('pages::admin.users');

    $component->call('toggleDisabled', $admin->id);
    $component->call('deleteUser', $admin->id);

    $admin->refresh();

    expect($admin->isDisabled())->toBeFalse()
        ->and(User::query()->whereKey($admin->id)->exists())->toBeTrue();
});

test('the last administrator cannot be disabled', function (): void {
    // Self-protection covers the only reachable path: an admin disabling themselves while they are the sole
    // administrator is refused (no-op).
    $admin = User::factory()->admin()->create();

    Livewire::actingAs($admin)
        ->test('pages::admin.users')
        ->call('toggleDisabled', $admin->id);

    expect($admin->refresh()->isDisabled())->toBeFalse();
});
