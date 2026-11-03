<?php

/*
 * One racer in BookingRaceTest: a PHP process of its own, so the race is real.
 * It boots the app, waits for the shared start time, books one unit, and
 * prints the outcome: "booked", "unavailable", or the error.
 *
 * Usage: php book-last-unit.php <tenant id> <option id> <customer id> <start at, unix seconds>
 */

use App\Exceptions\OptionUnavailableException;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Booking\BookingDraft;
use App\Services\Booking\BookingDraftLine;
use App\Services\Booking\BookingService;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../../../vendor/autoload.php';

$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[, $tenantId, $optionId, $customerId, $startAt] = $argv;

tenancy()->initialize(Tenant::query()->findOrFail($tenantId));
$customer = User::query()->whereKey((int) $customerId)->firstOrFail();

if (microtime(true) < (float) $startAt) {
    time_sleep_until((float) $startAt);
}

try {
    app(BookingService::class)->create(new BookingDraft($customer, [new BookingDraftLine((int) $optionId)]));
    echo 'booked';
} catch (OptionUnavailableException) {
    echo 'unavailable';
} catch (Throwable $error) {
    echo 'error: '.$error::class.': '.$error->getMessage();
}
