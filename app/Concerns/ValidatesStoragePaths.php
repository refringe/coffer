<?php

declare(strict_types=1);

namespace App\Concerns;

use App\Contracts\ShareStorage;
use Closure;

trait ValidatesStoragePaths
{
    /**
     * Validation rules for a browser-supplied relative storage path (a file or directory within a share). An empty
     * value means the share root.
     *
     * @return array<int, mixed>
     */
    protected function storagePathRules(bool $required = true): array
    {
        return [$required ? 'required' : 'nullable', 'string', 'max:4096', $this->storagePathRule()];
    }

    /**
     * A closure rule rejecting paths that contain traversal, separators that would escape the share root, control
     * characters, or a reserved internal area (`.trash`, `.tmp`).
     */
    protected function storagePathRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $value = is_string($value) ? str_replace('\\', '/', $value) : '';

            if ($value === '') {
                return;
            }

            $segments = explode('/', $value);

            foreach ($segments as $segment) {
                if (in_array(mb_trim($segment), ['', '.', '..'], true)
                    || preg_match('/[\x00-\x1F]/', $segment) === 1) {
                    $fail(__('That path is not allowed.'));

                    return;
                }
            }

            if (in_array($segments[0], ShareStorage::RESERVED, true)) {
                $fail(__('That path is not allowed.'));
            }
        };
    }
}
