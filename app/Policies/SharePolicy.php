<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Share;
use App\Models\User;

final class SharePolicy
{
    /**
     * Grant administrators every ability before other checks run.
     */
    public function before(User $user): ?bool
    {
        return $user->isAdministrator() ? true : null;
    }

    /**
     * Anyone signed in may see the share list.
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * Access is flat: every signed-in (org-member) user may open every share.
     */
    public function view(User $user, Share $share): bool
    {
        return true;
    }

    /**
     * Creating shares is administrator-only (granted via before()).
     */
    public function create(User $user): bool
    {
        return false;
    }

    /**
     * Renaming/updating a share is administrator-only.
     */
    public function update(User $user, Share $share): bool
    {
        return false;
    }

    /**
     * Deleting a share is administrator-only.
     */
    public function delete(User $user, Share $share): bool
    {
        return false;
    }

    /**
     * Every signed-in user may view/download files in any share.
     */
    public function viewFiles(User $user, Share $share): bool
    {
        return true;
    }

    /**
     * Every signed-in user may modify files in any share (read + write).
     */
    public function modifyFiles(User $user, Share $share): bool
    {
        return true;
    }
}
