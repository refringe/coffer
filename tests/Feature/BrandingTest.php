<?php

declare(strict_types=1);

use App\Models\User;

test('the login page shows Coffer branding', function (): void {
    $response = $this->get(route('login'));

    $response->assertOk();
    $response->assertSee('Coffer');
});

test('the login page has no Laravel starter kit branding', function (): void {
    $response = $this->get(route('login'));

    $response->assertDontSee('laravel.com', false);
    $response->assertDontSee('laracasts.com', false);
    $response->assertDontSee('livewire-starter-kit', false);
    $response->assertDontSee('Laravel Starter Kit');
});

test('the authenticated layout links to the Coffer repository and wiki', function (): void {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->get(route('shares.index'));

    $response->assertOk();
    $response->assertSee('Coffer');
    $response->assertSee('https://github.com/refringe/coffer', false);
    $response->assertSee('https://github.com/refringe/coffer/wiki', false);
    $response->assertDontSee('livewire-starter-kit', false);
    $response->assertDontSee('laravel.com/docs', false);
});
