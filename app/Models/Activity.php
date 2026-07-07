<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ActivityAction;
use Database\Factories\ActivityFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $share_id
 * @property int|null $user_id
 * @property ActivityAction $action
 * @property string|null $subject
 * @property string|null $path
 * @property array<string, mixed>|null $metadata
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Share $share
 * @property-read User|null $actor
 */
#[Fillable(['share_id', 'user_id', 'action', 'subject', 'path', 'metadata'])]
final class Activity extends Model
{
    /** @use HasFactory<ActivityFactory> */
    use HasFactory;

    /**
     * Record an activity entry for a share.
     *
     * @param  array<string, mixed>  $metadata
     */
    public static function record(
        Share $share,
        ?User $actor,
        ActivityAction $action,
        ?string $subject = null,
        ?string $path = null,
        array $metadata = [],
    ): self {
        return self::query()->create([
            'share_id' => $share->id,
            'user_id' => $actor?->id,
            'action' => $action,
            'subject' => $subject,
            'path' => $path,
            'metadata' => $metadata === [] ? null : $metadata,
        ]);
    }

    /**
     * Get the share the activity belongs to.
     *
     * @return BelongsTo<Share, $this>
     */
    public function share(): BelongsTo
    {
        return $this->belongsTo(Share::class);
    }

    /**
     * Get the user who performed the action, if known.
     *
     * @return BelongsTo<User, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'action' => ActivityAction::class,
            'metadata' => 'array',
        ];
    }
}
