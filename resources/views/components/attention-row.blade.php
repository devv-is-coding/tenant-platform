@props([
    'title',
    'href',
    'action',
    'heading' => null,
    'detail' => null,
    'primary' => false,
])

{{--
    One item waiting on a decision (DESIGN.md): what it is, why, and a button
    to where it is decided. A heading opens the first row of each kind.
--}}
<div {{ $attributes->class('flex flex-col gap-1 px-5 py-3') }}>
    @if ($heading)
        <flux:text size="sm" class="font-semibold uppercase tracking-wide">{{ $heading }}</flux:text>
    @endif

    <div class="flex items-center justify-between gap-4">
        <div class="min-w-0">
            <div class="truncate font-semibold text-zinc-800 dark:text-white">{{ $title }}</div>

            @if ($detail)
                <flux:text size="sm" class="truncate">{{ $detail }}</flux:text>
            @endif
        </div>

        <flux:button
            size="sm"
            :variant="$primary ? 'primary' : 'outline'"
            :href="$href"
            :aria-label="$action.' · '.$title"
            class="shrink-0"
            wire:navigate
        >
            {{ $action }}
        </flux:button>
    </div>
</div>
