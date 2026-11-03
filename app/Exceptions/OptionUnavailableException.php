<?php

namespace App\Exceptions;

use App\Models\Option;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Fewer units of an option are left than a booking asked for.
 *
 * An HTTP exception (409), so it is never reported as an error: running out is
 * an everyday outcome, and the losing side of a race on the last unit must get
 * a clean answer, not a 500. The API renders a stable `option_unavailable`
 * error the app can match on.
 */
class OptionUnavailableException extends HttpException
{
    public function __construct(public readonly Option $option, public readonly int $requested, public readonly int $available)
    {
        parent::__construct(Response::HTTP_CONFLICT, "Only {$available} of [{$option->name}] left; {$requested} asked for.");
    }

    /**
     * Render the exception for the API; the web gets Laravel's own 409 page.
     */
    public function render(Request $request): ?JsonResponse
    {
        if (! $request->is('api/*') && ! $request->expectsJson()) {
            return null;
        }

        return response()->json([
            'error' => 'option_unavailable',
            'requested' => $this->requested,
            'available' => $this->available,
        ], Response::HTTP_CONFLICT);
    }
}
