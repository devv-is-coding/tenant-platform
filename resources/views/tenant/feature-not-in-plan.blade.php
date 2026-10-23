@php($title = $feature->label())

{{--
    Shown, with a 403, when the agency's tier does not include a feature it
    opened. The feature exists, so this says which tier has it rather than
    pretending the page is missing. Rendered by FeatureNotInPlanException.
--}}
<x-layouts::app :title="$title">
    <div class="mx-auto flex max-w-xl flex-col items-center gap-4 py-12 text-center">
        <div class="flex size-12 items-center justify-center rounded-full bg-zinc-100 dark:bg-zinc-800">
            <flux:icon.sparkles class="text-zinc-500 dark:text-zinc-400" />
        </div>

        <flux:badge :color="$requiredTier->color()">{{ __('Available on :tier', ['tier' => $requiredTier->label()]) }}</flux:badge>

        <flux:heading size="xl" level="1">{{ $feature->label() }}</flux:heading>

        <flux:text>{{ $feature->description() }}</flux:text>

        <flux:text>
            @if ($currentTier)
                {{ __('Your agency is on :current, which does not include it. Ask the platform to move you to :tier.', ['current' => $currentTier->label(), 'tier' => $requiredTier->label()]) }}
            @else
                {{ __('Your agency has no live plan. Ask the platform to put you on :tier.', ['tier' => $requiredTier->label()]) }}
            @endif
        </flux:text>
    </div>
</x-layouts::app>
