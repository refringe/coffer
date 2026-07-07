<?php

declare(strict_types=1);

use App\Models\User;

test('guests are redirected to the login page', function (): void {
    User::factory()->create();

    $response = $this->get(route('shares.index'));

    $response->assertRedirect(route('login'));
});

test('authenticated users land on the shares list', function (): void {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->get(route('shares.index'));

    $response->assertOk();
});
