<?php

declare(strict_types=1);

namespace App\Enums;

enum NodeType: string
{
    case File = 'file';
    case Folder = 'folder';

    /**
     * Get the display label for the node type.
     */
    public function label(): string
    {
        return ucfirst($this->value);
    }

    /**
     * Determine if the node is a file.
     */
    public function isFile(): bool
    {
        return $this === self::File;
    }

    /**
     * Determine if the node is a folder.
     */
    public function isFolder(): bool
    {
        return $this === self::Folder;
    }
}
