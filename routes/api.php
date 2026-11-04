<?php

use App\Http\Controllers\StripeWebhookController;
use App\Http\Middleware\VerifyStripeWebhookSignature;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

/*
| Stripe's webhook, on the platform's domain only: the platform collects for
| every agency, and each event names its agency in its metadata. Here rather
| than in web.php, so no session starts and no CSRF token is asked for.
| Cashier's own routes are off (AppServiceProvider); the name is kept so
| `php artisan cashier:webhook` finds this endpoint.
*/
Route::domain((string) parse_url((string) config('app.url'), PHP_URL_HOST))
    ->post('stripe/webhook', StripeWebhookController::class)
    ->middleware(VerifyStripeWebhookSignature::class)
    ->name('cashier.webhook');
