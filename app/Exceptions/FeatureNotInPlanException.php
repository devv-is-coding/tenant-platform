<?php

namespace App\Exceptions;

use App\Enums\PlanFeature;
use App\Models\Tenant;
use App\Services\Plans\PlanFeatureMatrix;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * The agency's tier does not include a feature it asked for.
 *
 * An HTTP exception, so it is never reported as an error. It renders as a
 * stable `feature_not_in_plan` error for the API, which the app can match on,
 * and as an upsell page on the web rather than a bare 403 or a 404: the
 * feature exists, the agency's plan just does not include it.
 */
class FeatureNotInPlanException extends HttpException
{
    public function __construct(public readonly PlanFeature $feature, public readonly ?Tenant $tenant = null)
    {
        parent::__construct(Response::HTTP_FORBIDDEN, "The agency's plan does not include [{$feature->value}].");
    }

    /**
     * Render the exception as an HTTP response.
     */
    public function render(Request $request): Response
    {
        if ($request->is('api/*') || $request->expectsJson()) {
            return response()->json([
                'error' => 'feature_not_in_plan',
                'feature' => $this->feature->value,
            ], Response::HTTP_FORBIDDEN);
        }

        return response()->view('tenant.feature-not-in-plan', [
            'feature' => $this->feature,
            'requiredTier' => app(PlanFeatureMatrix::class)->minimumTierFor($this->feature),
            'currentTier' => $this->tenant?->currentTier(),
        ], Response::HTTP_FORBIDDEN);
    }
}
