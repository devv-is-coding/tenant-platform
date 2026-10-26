<?php

use App\Enums\PlanTier;
use App\Enums\TenantStatus;
use App\Models\Tenant;
use Illuminate\Support\Facades\Blade;

/**
 * Render a Blade snippet inside the agency, as its screens are.
 */
function renderInAgency(Tenant $tenant, string $blade): string
{
    return withinTenant($tenant, fn (): string => Blade::render($blade));
}

test('a closed agency\'s screens hide customers\' details and money figures', function () {
    $html = renderInAgency(tenantOnDomain(TenantStatus::Closed), '<x-tenant.masked>maria@example.com</x-tenant.masked>');

    expect($html)->toContain('Hidden')
        ->not->toContain('maria@example.com');
});

test('an agency that is not closed shows them, read-only or not', function (TenantStatus $status) {
    $html = renderInAgency(tenantOnDomain($status), '<x-tenant.masked>maria@example.com</x-tenant.masked>');

    expect($html)->toContain('maria@example.com')
        ->not->toContain('Hidden');
})->with([
    'active' => TenantStatus::Active,
    'suspended' => TenantStatus::Suspended,
    'paused' => TenantStatus::Paused,
]);

test('a screen whose feature the agency lost says which tier has it', function () {
    $html = renderInAgency(tenantOnDomain(tier: PlanTier::Basic), '<x-tenant.feature-locked :feature="App\Enums\PlanFeature::Reviews" />');

    expect($html)->toContain('You can view these records, but not add or change them.')
        ->toContain('Available on Advanced')
        ->toContain('Your agency is on Basic, which does not include it. Ask the platform to move you to Advanced.');
});

test('the note tells an agency with no live plan which tier to ask for', function () {
    $html = renderInAgency(tenantOnDomain(), '<x-tenant.feature-locked :feature="App\Enums\PlanFeature::Reviews" />');

    expect($html)->toContain('Your agency has no live plan. Ask the platform to put you on Advanced.');
});
