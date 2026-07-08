<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when a server-side URL download cannot be completed: the URL failed SSRF validation, the connection failed,
 * the server answered with an error status, or the transfer was aborted. The message is safe to show to the user.
 */
final class RemoteDownloadException extends RuntimeException {}
