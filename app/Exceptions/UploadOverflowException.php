<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when a chunk append carries more bytes than the upload's declared total length allows; the partial file is
 * truncated back to its pre-append offset before this is raised.
 */
final class UploadOverflowException extends RuntimeException {}
