<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when a chunk append finds another request already holding the partial file's exclusive write lock.
 */
final class UploadWriteConflictException extends RuntimeException {}
