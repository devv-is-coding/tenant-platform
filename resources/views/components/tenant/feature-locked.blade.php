@props(['feature'])

@php
    $requiredTier = app(\App\Services\Plans\PlanFeatureMatrix::class)->minimumTierFor($feature);
    $currentTier = tenant() instanceof \App\Models\Tenant ? tenant()->currentTier() : null;
@endphp

{{--
    Shown on a screen whose plan feature the agency does not have (or lost
    on a downgrade): its records stay readable, and this says why they cannot
    be changed. Use it in place of the screen's write controls:

        @feature(PlanFeature::Reviews) …buttons… @else <x-tenant.feature-locked :feature="PlanFeature::Reviews" /> @endfeature

    The policy refuses the writes regardless (AgencyPolicy); this only explains.
--}}
<flux:callout icon="sparkles" {{ $attributes }}>
    <flux:callout.heading class="flex flex-wrap items-center gap-2">
        {{ __('You can view these records, but not add or change them.') }}
        <flux:badge size="sm" :color="$requiredTier->color()">{{ __('Available on :tier', ['tier' => $requiredTier->label()]) }}</flux:badge>
    </flux:callout.heading>

    <x-tenant.feature-tier-hint :required-tier="$requiredTier" :current-tier="$currentTier" />
</flux:callout>
