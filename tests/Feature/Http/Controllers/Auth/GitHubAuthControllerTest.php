<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;

beforeEach(function (): void {
    config([
        'services.github.client_id' => 'client-id',
        'services.github.client_secret' => 'client-secret',
        'services.github.organization' => 'acme',
    ]);
});

function githubUser(array $overrides = []): SocialiteUser
{
    return (new SocialiteUser)->map([
        'id' => '12345',
        'name' => 'Octo Cat',
        'nickname' => 'octocat',
        'email' => 'octo@example.com',
        'avatar' => 'https://avatars.githubusercontent.com/u/12345',
        ...$overrides,
    ])->setToken('gh-token');
}

function fakeMembership(string $state, ?string $role): void
{
    Http::fake([
        'api.github.com/user/memberships/orgs/acme' => $role === null && $state === 'none'
            ? Http::response([], 404)
            : Http::response(['state' => $state, 'role' => $role], 200),
    ]);
}

test('the redirect route sends the user to GitHub when configured', function (): void {
    Socialite::fake('github');

    $this->get(route('auth.github.redirect'))->assertRedirect();
});

test('the redirect route 404s when GitHub is not configured', function (): void {
    config(['services.github.client_id' => null]);

    $this->get(route('auth.github.redirect'))->assertNotFound();
});

test('an organization owner is provisioned as an administrator and signed in', function (): void {
    Socialite::fake('github', githubUser());
    fakeMembership('active', 'admin');

    $this->get(route('auth.github.callback'))->assertRedirect(route('shares.index'));

    $this->assertAuthenticated();
    $this->assertDatabaseHas('users', [
        'github_id' => '12345',
        'github_login' => 'octocat',
        'email' => 'octo@example.com',
        'is_admin' => true,
    ]);
});

test('an organization member is provisioned as a regular user', function (): void {
    Socialite::fake('github', githubUser());
    fakeMembership('active', 'member');

    $this->get(route('auth.github.callback'))->assertRedirect(route('shares.index'));

    expect(User::query()->where('github_id', '12345')->value('is_admin'))->toBeFalse();
});

test('a non-member is denied and not provisioned', function (): void {
    Socialite::fake('github', githubUser());
    fakeMembership('none', null);

    $this->get(route('auth.github.callback'))
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors('github');

    $this->assertGuest();
    expect(User::query()->where('github_id', '12345')->exists())->toBeFalse();
});

test('a pending invitation is treated as not a member', function (): void {
    Socialite::fake('github', githubUser());
    fakeMembership('pending', 'member');

    $this->get(route('auth.github.callback'))
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors('github');

    $this->assertGuest();
});

test('the administrator flag is re-synced from the GitHub role on each login', function (): void {
    User::factory()->create(['github_id' => '12345', 'is_admin' => false]);

    Socialite::fake('github', githubUser());
    fakeMembership('active', 'admin');

    $this->get(route('auth.github.callback'))->assertRedirect(route('shares.index'));

    expect(User::query()->where('github_id', '12345')->value('is_admin'))->toBeTrue();
});

test('a locally disabled account cannot sign in via GitHub', function (): void {
    User::factory()->create(['github_id' => '12345', 'disabled_at' => now()]);

    Socialite::fake('github', githubUser());
    fakeMembership('active', 'member');

    $this->get(route('auth.github.callback'))
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors('github');

    $this->assertGuest();
});

test('a github api connection failure degrades to the login page rather than a 500', function (): void {
    Socialite::fake('github', githubUser());
    Http::fake(fn () => throw new ConnectionException('GitHub is unreachable.'));

    $this->get(route('auth.github.callback'))
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors('github');

    $this->assertGuest();
});

test('a provisioning database failure degrades to the login page rather than a 500', function (): void {
    // An already-claimed account owns this email under a different GitHub id, so provisioning a new account collides
    // with the unique email index when it saves.
    User::factory()->create(['github_id' => '99999', 'email' => 'octo@example.com']);

    Socialite::fake('github', githubUser());
    fakeMembership('active', 'member');

    $this->get(route('auth.github.callback'))
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors('github');

    $this->assertGuest();
});

test('a member with no public email falls back to a noreply address', function (): void {
    Socialite::fake('github', githubUser(['email' => null]));
    fakeMembership('active', 'member');

    $this->get(route('auth.github.callback'))->assertRedirect(route('shares.index'));

    $this->assertDatabaseHas('users', [
        'github_id' => '12345',
        'email' => 'octocat@users.noreply.github.com',
    ]);
});
