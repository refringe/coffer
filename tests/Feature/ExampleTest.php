<?php

declare(strict_types=1);

test('the homepage redirects to the login page', function (): void {
    $response = $this->get(route('home'));

    $response->assertRedirect(route('login'));
});
