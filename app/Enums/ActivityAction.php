<?php

declare(strict_types=1);

namespace App\Enums;

enum ActivityAction: string
{
    case FolderCreated = 'folder.created';
    case FileUploaded = 'file.uploaded';
    case NodeRenamed = 'node.renamed';
    case NodeMoved = 'node.moved';
    case NodeDeleted = 'node.deleted';
    case NodeRestored = 'node.restored';
    case NodePurged = 'node.purged';

    /**
     * A human-readable, past-tense description of the action for activity feeds.
     */
    public function description(): string
    {
        return match ($this) {
            self::FolderCreated => __('created folder'),
            self::FileUploaded => __('uploaded'),
            self::NodeRenamed => __('renamed'),
            self::NodeMoved => __('moved'),
            self::NodeDeleted => __('deleted'),
            self::NodeRestored => __('restored'),
            self::NodePurged => __('permanently deleted'),
        };
    }
}
