<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\NodeType;

/**
 * An immutable read-model for one item in a share's recycle bin, reconstructed from its on-disk sidecar manifest under
 * the share's `.trash` directory.
 */
final readonly class TrashedEntry
{
    public function __construct(
        public string $id,
        public string $name,
        public NodeType $type,
        public string $originalPath,
        public ?int $size,
        public int $deletedAt,
        public ?int $deletedBy,
    ) {}

    /**
     * Whether the trashed item is a folder.
     */
    public function isFolder(): bool
    {
        return $this->type === NodeType::Folder;
    }
}
