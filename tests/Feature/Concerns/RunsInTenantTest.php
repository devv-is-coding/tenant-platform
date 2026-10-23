<?php

use App\Concerns\RunsInTenant;
use App\Models\Tenant;

/*
 * Running in a single agency, including restoring the context when the
 * callback throws, is covered through withinTenant() in TestHelpersTest.
 */

test('running in each agency visits every live agency inside its own context, then returns to no tenancy', function () {
    $agency = Tenant::factory()->create();
    $otherAgency = Tenant::factory()->create();
    Tenant::factory()->create()->delete();
    $runner = new class
    {
        use RunsInTenant {
            runInEachTenant as public;
        }
    };
    $activeTenantPerVisit = [];

    $runner->runInEachTenant(function (Tenant $tenant) use (&$activeTenantPerVisit): void {
        $activeTenantPerVisit[$tenant->id] = tenant('id');
    });

    ksort($activeTenantPerVisit);
    $expected = [$agency->id => $agency->id, $otherAgency->id => $otherAgency->id];
    ksort($expected);

    expect($activeTenantPerVisit)->toBe($expected)
        ->and(tenancy()->initialized)->toBeFalse();
});
