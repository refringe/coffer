<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureTusVersion
{
    public const string VERSION = '1.0.0';

    /**
     * Reject resumable-upload requests that do not speak tus protocol version 1.0.0, and stamp the protocol version
     * header on every response passing through.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->header('Tus-Resumable') !== self::VERSION) {
            return response()->noContent(Response::HTTP_PRECONDITION_FAILED)->withHeaders([
                'Tus-Version' => self::VERSION,
                'Tus-Resumable' => self::VERSION,
            ]);
        }

        $response = $next($request);
        $response->headers->set('Tus-Resumable', self::VERSION);

        return $response;
    }
}
