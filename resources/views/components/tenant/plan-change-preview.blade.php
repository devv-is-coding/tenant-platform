@props(['adds', 'removes'])

{{--
    What moving to a chosen plan would change for the agency: the features it
    would gain, and the ones it would lose. Shown by both of the billing
    page's plan forms, asking the platform and changing plan by card.

        <x-tenant.plan-change-preview :adds="$change['adds']" :removes="$change['removes']" />
--}}
<div {{ $attributes->class('flex flex-col gap-3') }} data-test="plan-change-preview">
    @if ($adds !== [])
        <div>
            <flux:text size="sm" class="mb-1">{{ __('You would gain') }}</flux:text>
            <ul class="flex flex-wrap gap-1.5">
                @foreach ($adds as $feature)
                    <li wire:key="adds-{{ $feature->value }}"><flux:badge size="sm" color="green">{{ $feature->label() }}</flux:badge></li>
                @endforeach
            </ul>
        </div>
    @endif

    @if ($removes !== [])
        <flux:callout variant="warning" icon="exclamation-triangle" :heading="__('You would lose')">
            <flux:callout.text>{{ implode(', ', array_map(fn ($feature) => $feature->label(), $removes)) }}</flux:callout.text>
            <flux:callout.text>{{ __('What you made with them stays, read-only or hidden.') }}</flux:callout.text>
        </flux:callout>
    @endif

    @if ($adds === [] && $removes === [])
        <flux:text size="sm">{{ __('The same features as now, at this plan\'s price and billing.') }}</flux:text>
    @endif
</div>
