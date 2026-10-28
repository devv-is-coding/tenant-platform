<?php

use App\Enums\AvailabilityMode;

test('only capacity and per-night options consume inventory; a visa tracks none', function (AvailabilityMode $mode, bool $consumes) {
    expect($mode->consumesInventory())->toBe($consumes);
})->with([
    'capacity' => [AvailabilityMode::Capacity, true],
    'per night' => [AvailabilityMode::Nightly, true],
    'unlimited' => [AvailabilityMode::Unlimited, false],
]);

test('only a per-night option lets the guest choose the dates, and only it leaves the last day unconsumed', function (AvailabilityMode $mode, bool $perNight) {
    expect($mode->isGuestDated())->toBe($perNight)
        ->and($mode->usesHalfOpenRange())->toBe($perNight);
})->with([
    'capacity' => [AvailabilityMode::Capacity, false],
    'per night' => [AvailabilityMode::Nightly, true],
    'unlimited' => [AvailabilityMode::Unlimited, false],
]);
