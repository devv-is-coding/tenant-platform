<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Stripe Keys
    |--------------------------------------------------------------------------
    |
    | The platform's own Stripe account: it collects every agency's online
    | payments (StripeGateway). The publishable key is for Stripe.js, the
    | secret key for the API.
    |
    | Only what this app sets is here. Cashier merges its own defaults under
    | this file (currency, invoice renderer, logger, ...); none of them apply,
    | since no subscriptions or Cashier invoices run through Cashier here.
    |
    */

    'key' => env('STRIPE_KEY'),

    'secret' => env('STRIPE_SECRET'),

    /*
    |--------------------------------------------------------------------------
    | Stripe Webhooks
    |--------------------------------------------------------------------------
    |
    | The secret proves an event came from Stripe; without it every webhook is
    | refused (VerifyStripeWebhookSignature), where Cashier's own would accept
    | unsigned ones. The tolerance is how old, in seconds, a signed event may
    | be. The events are the ones StripeWebhookController handles, which
    | `php artisan cashier:webhook` subscribes the endpoint to.
    |
    */

    'webhook' => [
        'secret' => env('STRIPE_WEBHOOK_SECRET'),
        'tolerance' => 300,
        'events' => [
            'invoice.paid',
            'invoice.payment_failed',
            'refund.updated',
            'refund.failed',
        ],
    ],

];
