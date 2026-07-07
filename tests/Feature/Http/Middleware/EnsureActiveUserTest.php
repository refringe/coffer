<?php

declare(strict_types=1);

use App\Models\User;

test('an active user passes through untouched', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('shares.index'))
        ->assertOk();

    $this->assertAuthenticated();
});

test('a disabled user is force-logged-out with the reason flashed under the github error key', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('shares.index'))->assertOk();

    $user->forceFill(['disabled_at' => now()])->save();

    // Re-fetch a full row so the session guard's logout (which reads remember_token) behaves as it does in production.
    $this->actingAs($user->fresh())
        ->get(route('shares.index'))
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors(['github' => 'This account has been disabled.']);

    $this->assertGuest();
});

test('a disabled user making a json request receives a 403 rather than a redirect', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('shares.index'))->assertOk();

    $user->forceFill(['disabled_at' => now()])->save();

    // An XHR caller (e.g. the uploader) must not be 302-redirected to a 200 login page it would mistake for success.
    $this->actingAs($user->fresh())
        ->getJson(route('shares.index'))
        ->assertForbidden();

    $this->assertGuest();
});
