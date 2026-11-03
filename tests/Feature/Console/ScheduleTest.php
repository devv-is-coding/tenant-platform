<?php

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;

/*
 * More than one scheduler may run (one per node), so every scheduled task must
 * take the shared lock, or it would run once per node: two invoices a day.
 */
test('every scheduled task runs on one server only, and never overlaps itself', function () {
    $tasks = collect(app(Schedule::class)->events());

    $unguarded = $tasks
        ->reject(fn (Event $task): bool => $task->onOneServer && $task->withoutOverlapping)
        ->map(fn (Event $task): string => (string) $task->command)
        ->values()
        ->all();

    expect($tasks)->not->toBeEmpty()
        ->and($unguarded)->toBe([]);
});
