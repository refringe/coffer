<?php

declare(strict_types=1);

namespace App\Concerns;

use Illuminate\Support\Str;

trait GeneratesUniqueShareSlugs
{
    /**
     * Generate a unique slug for the share.
     */
    protected static function generateUniqueShareSlug(string $name, ?int $excludeId = null): string
    {
        $slug = Str::slug($name);

        // Falls back to a placeholder when Str::slug() reduces a symbol-only or non-transliterable name to an empty
        // string.
        $defaultSlug = $slug === '' ? 'share' : $slug;

        $query = static::withTrashed()
            ->where(function ($query) use ($defaultSlug): void {
                $query->where('slug', $defaultSlug)
                    ->orWhere('slug', 'like', $defaultSlug.'-%');
            });

        if ($excludeId) {
            $query->where('id', '!=', $excludeId);
        }

        $existingSlugs = $query->pluck('slug');

        $maxSuffix = $existingSlugs
            ->map(function (mixed $slug) use ($defaultSlug): ?int {
                if (! is_string($slug)) {
                    return null;
                }

                if ($slug === $defaultSlug) {
                    return 0;
                }

                if (preg_match('/^'.preg_quote($defaultSlug, '/').'-(\d+)$/', $slug, $matches)) {
                    return (int) $matches[1];
                }

                return null;
            })
            ->filter(fn (?int $suffix): bool => $suffix !== null)
            ->max() ?? 0;

        // Prefer the bare base slug whenever it is unused; only fall back to a numbered suffix when it is taken, so a
        // sibling like "photos-1" does not needlessly push a free "photos" to "photos-2".
        return $existingSlugs->contains($defaultSlug)
            ? $defaultSlug.'-'.($maxSuffix + 1)
            : $defaultSlug;
    }
}
