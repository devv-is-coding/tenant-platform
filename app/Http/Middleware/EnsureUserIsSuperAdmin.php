<?php

namespace App\Http\Middleware;

use App\Enums\LoginPortal;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Only super admins may use the platform console.
 *
 * Checked on every request rather than trusted from the login portal, so the
 * boundary holds however the session was established.
 */
class EnsureUserIsSuperAdmin
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(LoginPortal::SuperAdmin->allows($request->user()), Response::HTTP_FORBIDDEN);

        return $next($request);
    }
}
