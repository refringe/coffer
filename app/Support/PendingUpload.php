<?php

declare(strict_types=1);

namespace App\Support;

/**
 * An immutable read-model for one in-progress resumable upload, reconstructed from its on-disk sidecar manifest under
 * the share's `.tmp/uploads` directory. The partial file's size on disk (not this manifest) is the byte offset's source
 * of truth.
 */
final readonly class PendingUpload
{
    public function __construct(
        public string $id,
        public int $userId,
        public string $name,
        public string $directory,
        public int $length,
        public string $onConflict,
        public int $createdAt,
        public ?int $completedAt,
    ) {}

    /**
     * Whether the upload has been promoted into the browsable tree.
     */
    public function isCompleted(): bool
    {
        return $this->completedAt !== null;
    }

    /**
     * A copy of this upload marked as completed at the given timestamp.
     */
    public function completed(int $timestamp): self
    {
        return new self(
            id: $this->id,
            userId: $this->userId,
            name: $this->name,
            directory: $this->directory,
            length: $this->length,
            onConflict: $this->onConflict,
            createdAt: $this->createdAt,
            completedAt: $timestamp,
        );
    }
}
