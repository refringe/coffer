<?php

declare(strict_types=1);

use App\Models\Share;
use App\Models\User;

test('an authenticated user lands on their shares without errors', function (): void {
    $user = User::factory()->create();
    Share::factory()->create(['name' => 'Visible Share']);

    $this->actingAs($user);

    visit(route('shares.index'))
        ->assertNoJavaScriptErrors()
        ->assertSee('Visible Share');
});
