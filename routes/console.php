<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// onOneServer(): with more than one scheduler running, only the first to take
// the lock in the shared cache runs the command.
Schedule::command('subscriptions:issue-invoices')->daily()->withoutOverlapping()->onOneServer();
Schedule::command('audit:prune')->daily()->withoutOverlapping()->onOneServer();
// A hold is counted in minutes (Settings::booking_hold_minutes), so it is
// released within a minute of running out.
Schedule::command('bookings:release-expired')->everyMinute()->withoutOverlapping()->onOneServer();
