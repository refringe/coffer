<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
use Symfony\Component\HttpFoundation\Response;

final class EnsureActiveUser
{
    /**
     * Force-log-out an authenticated user whose account has been disabled, so a local disable takes effect immediately
     * even while an existing GitHub session is active.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User && $user->isDisabled()) {
            Auth::guard('web')->logout();

            Session::invalidate();
            Session::regenerateToken();

            // A JSON/XHR caller (e.g. the through-app uploader) must receive an explicit failure status rather than a
            // redirect it would silently follow to a 200 login page and mistake for success.
            if ($request->expectsJson()) {
                return response()->json(['message' => __('This account has been disabled.')], Response::HTTP_FORBIDDEN);
            }

            return to_route('login')->withErrors([
                'github' => __('This account has been disabled.'),
            ]);
        }

        return $next($request);
    }
}
