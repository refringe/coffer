<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;

/**
 * Resolves a user's membership of the configured GitHub organization, which is the gate for access. Only active members
 * may sign in, organization owners (`role: admin`) become administrators, and everyone else becomes a regular user.
 */
final class GitHubOrganization
{
    /**
     * Whether GitHub authentication is fully configured.
     */
    public function configured(): bool
    {
        return $this->name() !== ''
            && filled(Config::get('services.github.client_id'))
            && filled(Config::get('services.github.client_secret'));
    }

    /**
     * The configured organization login (slug), or an empty string when unset.
     */
    public function name(): string
    {
        $name = Config::get('services.github.organization');

        return is_string($name) ? mb_trim($name) : '';
    }

    /**
     * Resolve the authenticated GitHub user's role within the configured organization using their OAuth token: 'admin'
     * (owner) or 'member' for an active member, or null when they are not an active member (or on error).
     *
     * @return 'admin'|'member'|null
     */
    public function membershipRole(string $token): ?string
    {
        $organization = $this->name();

        if ($organization === '') {
            return null;
        }

        $response = Http::withToken($token)
            ->withHeaders([
                'Accept' => 'application/vnd.github+json',
                'X-GitHub-Api-Version' => '2022-11-28',
            ])
            ->get('https://api.github.com/user/memberships/orgs/'.$organization);

        if (! $response->successful() || $response->json('state') !== 'active') {
            return null;
        }

        $role = $response->json('role');

        return in_array($role, ['admin', 'member'], true) ? $role : null;
    }
}
