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

        <x-tenant.feature-tier-hint :required-tier="$requiredTier" :current-tier="$currentTier" />
    </div>
</x-layouts::app>
