<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Laravel\Cashier\Http\Middleware\VerifyWebhookSignature;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Let a webhook through only when Stripe signed it with the platform's
 * secret (`STRIPE_WEBHOOK_SECRET`), using Cashier's check.
 *
 * Cashier's own webhook skips the check entirely when no secret is set, so a
 * forged event would credit a booking. Here no secret means nothing gets in.
 */
class VerifyStripeWebhookSignature extends VerifyWebhookSignature
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     *
     * @throws AccessDeniedHttpException
     */
    public function handle($request, Closure $next): Response
    {
        if (blank(config('cashier.webhook.secret'))) {
            throw new AccessDeniedHttpException('Stripe webhooks are refused until STRIPE_WEBHOOK_SECRET is set.');
        }

        return parent::handle($request, $next);
    }
}
