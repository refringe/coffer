<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\NodeType;

/**
 * An immutable read-model for a single file or folder within a share, read straight from disk. The filesystem (not the
 * database) is the source of truth for the file tree.
 */
final readonly class Entry
{
    public function __construct(
        public string $name,
        public NodeType $type,
        public string $path,
        public ?int $size,
        public ?string $mimeType,
        public int $modifiedAt,
    ) {}

    /**
     * Whether this entry is a folder.
     */
    public function isFolder(): bool
    {
        return $this->type === NodeType::Folder;
    }

    /**
     * Whether this entry is a file.
     */
    public function isFile(): bool
    {
        return $this->type === NodeType::File;
    }
}
