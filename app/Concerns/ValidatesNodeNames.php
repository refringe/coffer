<?php

declare(strict_types=1);

namespace App\Concerns;

use App\Contracts\ShareStorage;
use Closure;

trait ValidatesNodeNames
{
    /**
     * Validation rules for a user-supplied file or folder name. Disallows path separators, control characters,
     * directory-traversal names, reserved names, and the hidden `.partial` transfer suffix so the materialized node
     * path can never be corrupted or hidden.
     *
     * @return array<int, mixed>
     */
    protected function nodeNameRules(): array
    {
        return ['required', 'string', 'max:255', $this->nodeNameRule()];
    }

    /**
     * A closure rule rejecting names that would corrupt the logical path.
     */
    protected function nodeNameRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $value = is_string($value) ? $value : '';

            if (preg_match('#[/\\\\]#', $value) === 1
                || preg_match('/[\x00-\x1F]/', $value) === 1
                || in_array(mb_trim($value), ['', '.', '..'], true)) {
                $fail(__('That name contains characters that are not allowed.'));

                return;
            }

            if (in_array($value, ShareStorage::RESERVED, true) || str_ends_with($value, ShareStorage::PARTIAL_SUFFIX)) {
                $fail(__('That name is reserved.'));
            }
        };
    }
}
