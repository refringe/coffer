<?php

declare(strict_types=1);

namespace App\Concerns;

use App\Models\User;
use Illuminate\Support\Facades\Auth;

trait InteractsWithAuthenticatedUser
{
    /**
     * Get the currently authenticated user.
     */
    protected function authenticatedUser(): User
    {
        $user = Auth::user();

        abort_unless($user instanceof User, 403);

        return $user;
    }
}
