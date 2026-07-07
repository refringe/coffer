<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Models\Share;

interface ShareStorageResolver
{
    /**
     * Resolve the storage backend rooted at a share's own directory.
     */
    public function for(Share $share): ShareStorage;
}
