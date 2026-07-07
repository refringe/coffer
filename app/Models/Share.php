<?php

declare(strict_types=1);

namespace App\Models;

use App\Concerns\GeneratesUniqueShareSlugs;
use Database\Factories\ShareFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property string $path
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property int $files_count On-disk file count, attached by the share listings.
 * @property int $usage_bytes On-disk usage in bytes, attached by the share listings.
 * @property-read Collection<int, Activity> $activities
 */
#[Fillable(['name', 'slug', 'path'])]
final class Share extends Model
{
    use GeneratesUniqueShareSlugs;

    /** @use HasFactory<ShareFactory> */
    use HasFactory;

    use SoftDeletes;

    /**
     * Get the activity log entries for this share.
     *
     * @return HasMany<Activity, $this>
     */
    public function activities(): HasMany
    {
        return $this->hasMany(Activity::class);
    }

    /**
     * Get the route key for the model.
     */
    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /**
     * Bootstrap the model and its traits.
     */
    protected static function boot(): void
    {
        parent::boot();

        self::creating(function (Share $share): void {
            if (blank($share->slug)) {
                $share->slug = self::generateUniqueShareSlug($share->name);
            }
        });

        self::updating(function (Share $share): void {
            if ($share->isDirty('name')) {
                $share->slug = self::generateUniqueShareSlug($share->name, $share->id);
            }
        });
    }
}
