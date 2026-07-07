<?php

declare(strict_types=1);

use App\Actions\Users\ProvisionGitHubUser;
use App\Models\User;
use Laravel\Socialite\Two\User as SocialiteUser;

/**
 * Build a Socialite GitHub user with the given overrides.
 */
function socialiteGitHubUser(array $overrides = []): SocialiteUser
{
    return (new SocialiteUser)->map([
        'id' => '999',
        'name' => 'Real Name',
        'nickname' => 'real-login',
        'email' => 'real@example.com',
        'avatar' => 'https://avatars.githubusercontent.com/u/999',
        ...$overrides,
    ]);
}

test('a display name of zero is preserved rather than falling back to the login', function (): void {
    $user = resolve(ProvisionGitHubUser::class)->handle(
        socialiteGitHubUser(['name' => '0', 'nickname' => 'zero-login']),
        'member',
    );

    expect($user->name)->toBe('0');
});

test('a missing display name falls back to the login', function (): void {
    $user = resolve(ProvisionGitHubUser::class)->handle(
        socialiteGitHubUser(['name' => null, 'nickname' => 'no-name']),
        'member',
    );

    expect($user->name)->toBe('no-name');
});

test('an unclaimed local account with the same email is linked instead of colliding', function (): void {
    $existing = User::factory()->create(['email' => 'real@example.com', 'github_id' => null]);

    $user = resolve(ProvisionGitHubUser::class)->handle(socialiteGitHubUser(), 'member');

    expect($user->id)->toBe($existing->id)
        ->and($user->github_id)->toBe('999')
        ->and(User::query()->count())->toBe(1);
});
