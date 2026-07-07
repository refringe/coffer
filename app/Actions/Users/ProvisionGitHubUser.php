<?php

declare(strict_types=1);

namespace App\Actions\Users;

use App\Models\User;
use Laravel\Socialite\Contracts\User as SocialiteUser;

final class ProvisionGitHubUser
{
    /**
     * Create or update the local account for an authenticated GitHub user, keyed by their stable GitHub id. The
     * administrator flag is re-derived from their organization role on every login, so promotions/demotions in GitHub
     * take effect on next sign-in. A local disable is preserved (never cleared here) so it remains an immediate
     * revocation.
     *
     * @param  'admin'|'member'  $role
     */
    public function handle(SocialiteUser $githubUser, string $role): User
    {
        $githubId = (string) $githubUser->getId();
        $login = (string) $githubUser->getNickname();
        $name = $githubUser->getName();
        $email = $githubUser->getEmail() ?: $login.'@users.noreply.github.com';

        // Match the stable GitHub id first; otherwise adopt an unclaimed local account holding the same email (e.g. a
        // seeded or pre-provisioned row) so it is linked rather than colliding with the unique email index.
        $user = User::query()->where('github_id', $githubId)->first()
            ?? User::query()->whereNull('github_id')->where('email', $email)->first()
            ?? new User();

        $user->forceFill([
            'github_id' => $githubId,
            'name' => filled($name) ? $name : $login,
            'email' => $email,
            'github_login' => $login,
            'avatar_url' => $githubUser->getAvatar(),
            'is_admin' => $role === 'admin',
            // GitHub-verified identities are considered verified; preserve an existing timestamp.
            'email_verified_at' => $user->email_verified_at ?? now(),
        ]);

        $user->save();

        return $user;
    }
}
