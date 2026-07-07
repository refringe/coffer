<?php

declare(strict_types=1);

namespace App\Actions\Shares;

use App\Models\Share;
use Illuminate\Support\Facades\File;

final class UpdateSharePath
{
    /**
     * Point a share at a different local storage directory, ensuring it exists. This is purely a configuration change:
     * existing files are never moved.
     */
    public function handle(Share $share, string $path): void
    {
        File::ensureDirectoryExists($path);

        $share->update(['path' => $path]);
    }
}
