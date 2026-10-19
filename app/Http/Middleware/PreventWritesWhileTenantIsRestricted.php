<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Makes a suspended or paused agency read-only: its staff can still sign in
 * and look, but any request that could change something is refused.
 *
 * Part of the `tenant` middleware group, so every tenant route is covered
 * without having to remember it. Livewire update requests do not pass through
 * tenant routes, so policies must deny writes as well once tenant-side
 * components exist.
 */
class PreventWritesWhileTenantIsRestricted
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $tenant = tenant();

        if (! $request->isMethodSafe() && $tenant instanceof Tenant && $tenant->status->isReadOnly()) {
            abort(Response::HTTP_FORBIDDEN, __('This agency is :status, so its records are read-only.', [
                'status' => mb_strtolower($tenant->status->label()),
            ]));
        }

        return $next($request);
    }
}
