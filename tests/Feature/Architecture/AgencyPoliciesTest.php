<?php

use App\Models\Domain;
use App\Models\Tenant;
use App\Policies\AgencyPolicy;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/*
 * TA-7: every policy over an agency's own records extends AgencyPolicy, so a
 * read-only agency, or one whose tier lacks the feature, cannot write through
 * Livewire, which skips the tenant route group's guard. An agency's records
 * are the models without CentralConnection; Tenant and Domain are the tenancy
 * package's own central models.
 */
test('every policy over an agency\'s own records extends AgencyPolicy', function () {
    $offenders = collect(glob(app_path('Models/*.php')))
        ->map(fn (string $path): string => sprintf('App\Models\%s', basename($path, '.php')))
        ->reject(fn (string $model): bool => in_array($model, [Tenant::class, Domain::class], true)
            || in_array(CentralConnection::class, class_uses_recursive($model), true))
        ->map(fn (string $model): string => sprintf('App\Policies\%sPolicy', class_basename($model)))
        ->filter(fn (string $policy): bool => class_exists($policy) && ! is_subclass_of($policy, AgencyPolicy::class))
        ->values()
        ->all();

    expect($offenders)->toBe([]);
});
