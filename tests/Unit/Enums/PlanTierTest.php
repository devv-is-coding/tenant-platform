<?php

use App\Enums\PlanTier;

test('a tier includes itself and every tier below it, never one above', function (PlanTier $tier, PlanTier $other, bool $included) {
    expect($tier->includes($other))->toBe($included);
})->with([
    'Basic includes Basic' => [PlanTier::Basic, PlanTier::Basic, true],
    'Basic lacks Advanced' => [PlanTier::Basic, PlanTier::Advanced, false],
    'Basic lacks Pro' => [PlanTier::Basic, PlanTier::Pro, false],
    'Advanced includes Basic' => [PlanTier::Advanced, PlanTier::Basic, true],
    'Advanced includes Advanced' => [PlanTier::Advanced, PlanTier::Advanced, true],
    'Advanced lacks Pro' => [PlanTier::Advanced, PlanTier::Pro, false],
    'Pro includes Basic' => [PlanTier::Pro, PlanTier::Basic, true],
    'Pro includes Advanced' => [PlanTier::Pro, PlanTier::Advanced, true],
    'Pro includes Pro' => [PlanTier::Pro, PlanTier::Pro, true],
]);
