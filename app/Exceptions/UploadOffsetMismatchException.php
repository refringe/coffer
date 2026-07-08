<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when a chunk append's declared byte offset does not match the partial file's current size on disk.
 */
final class UploadOffsetMismatchException extends RuntimeException {}
