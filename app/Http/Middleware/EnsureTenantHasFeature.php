<?php

namespace App\Http\Middleware;

use App\Enums\PlanFeature;
use App\Exceptions\FeatureNotInPlanException;
use App\Models\Tenant;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Refuses a route the current agency's tier does not include, for the web and
 * `/api/v1` alike. Registered as `feature`, so a route reads
 * `->middleware(EnsureTenantHasFeature::using(PlanFeature::Reviews))`.
 *
 * It must run after tenancy is initialized: with no agency there is no tier,
 * so every gated route is refused.
 */
class EnsureTenantHasFeature
{
    /**
     * Get the middleware string that gates a route on the feature.
     */
    public static function using(PlanFeature $feature): string
    {
        return 'feature:'.$feature->value;
    }

    /**
     * Handle an incoming request.
     *
     * An unknown feature name is a typo in a route file, so it fails loudly
     * rather than letting the route through.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, string $feature): Response
    {
        $planFeature = PlanFeature::from($feature);
        $tenant = tenant();
        $tenant = $tenant instanceof Tenant ? $tenant : null;

        if ($tenant === null || ! $tenant->hasFeature($planFeature)) {
            throw new FeatureNotInPlanException($planFeature, $tenant);
        }

        return $next($request);
    }
}
