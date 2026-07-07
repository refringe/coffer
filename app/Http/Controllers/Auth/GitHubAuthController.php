<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Actions\Users\ProvisionGitHubUser;
use App\Http\Controllers\Controller;
use App\Services\GitHubOrganization;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\AbstractProvider;
use Laravel\Socialite\Two\User as GitHubUser;
use Symfony\Component\HttpFoundation\RedirectResponse as SymfonyRedirectResponse;
use Throwable;

final class GitHubAuthController extends Controller
{
    public function __construct(private readonly GitHubOrganization $organization) {}

    /**
     * Send the user to GitHub to authorize. `read:org` is required so we can read their organization role.
     */
    public function redirect(): SymfonyRedirectResponse
    {
        abort_unless($this->organization->configured(), 404);

        $driver = Socialite::driver('github');

        if ($driver instanceof AbstractProvider) {
            $driver->scopes(['read:org', 'user:email']);
        }

        return $driver->redirect();
    }

    /**
     * Handle the OAuth callback. Verify active membership of the configured organization, provision/sync the local
     * account, and sign the user in. Non-members and disabled accounts are rejected.
     */
    public function callback(ProvisionGitHubUser $provision): RedirectResponse
    {
        abort_unless($this->organization->configured(), 404);

        try {
            $githubUser = Socialite::driver('github')->user();

            $token = $githubUser instanceof GitHubUser ? $githubUser->token : '';

            $role = $this->organization->membershipRole($token);

            if ($role === null) {
                return $this->failed(__('Your GitHub account is not an active member of the required organization.'));
            }

            // Provisioning persists the account, so a database error (e.g. a colliding email) is caught here rather
            // than surfacing as an uncaught 500.
            $user = $provision->handle($githubUser, $role);
        } catch (Throwable $throwable) {
            report($throwable);

            return $this->failed(__('GitHub sign-in could not be completed. Please try again.'));
        }

        if ($user->isDisabled()) {
            return $this->failed(__('This account has been disabled.'));
        }

        Auth::login($user, remember: true);

        return redirect()->intended(route('shares.index'));
    }

    /**
     * Bounce back to the login page with an error message.
     */
    private function failed(string $message): RedirectResponse
    {
        return to_route('login')->withErrors(['github' => $message]);
    }
}
