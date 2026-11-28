<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use Closure;
use Illuminate\Http\Request;
use Stancl\Tenancy\Contracts\TenantCouldNotBeIdentifiedException;
use Stancl\Tenancy\Middleware\InitializeTenancyByDomain;
use Symfony\Component\HttpFoundation\Response;

/**
 * stancl's domain identification, except that an agency whose database is
 * still being set up (TenantStatus::Provisioning) answers "being set up"
 * instead of being entered: there is no database yet to hold its sessions,
 * and in the local environment stancl itself refuses to enter it.
 *
 * Bound in the container in place of InitializeTenancyByDomain
 * (TenancyServiceProvider::register()), so every way into an agency gets it:
 * the `tenant` route group, and InitializeTenancyUnlessCentral for Fortify and
 * Livewire. The middleware lists keep naming stancl's class, which keeps the
 * priority list in bootstrap/app.php, which runs it before the session, whole.
 *
 * The agency is resolved once, as stancl does, then checked before tenancy is
 * initialized, so the refusal costs no extra query.
 */
class InitializeTenancyByDomainOnceProvisioned extends InitializeTenancyByDomain
{
    /**
     * Identify the agency by the request's domain and enter it, unless it is
     * still being set up.
     *
     * @param  Request  $request
     * @param  Closure(Request): Response  $next
     * @param  mixed  ...$resolverArguments
     */
    public function initializeTenancy($request, $next, ...$resolverArguments): mixed
    {
        try {
            $tenant = $this->resolver->resolve(...$resolverArguments);
        } catch (TenantCouldNotBeIdentifiedException $exception) {
            $onFail = static::$onFail ?? function (TenantCouldNotBeIdentifiedException $exception): never {
                throw $exception;
            };

            return $onFail($exception, $request, $next);
        }

        if ($tenant instanceof Tenant && ! $tenant->status->isProvisioned()) {
            abort(Response::HTTP_SERVICE_UNAVAILABLE, __('This agency is still being set up. Try again in a few minutes.'));
        }

        $this->tenancy->initialize($tenant);

        return $next($request);
    }
}
