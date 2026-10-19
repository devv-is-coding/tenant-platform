@props(['link'])

{{--
    One row inside a dropdown panel: icon tile, label, and a description line.

    flux:menu.item carries no current-page styling of its own - it renders
    through button-or-link-pure, which skips Flux's current detection - so
    data-current is set here by hand, and aria-current with it, because Flux
    only ever marks the current page visually.
--}}
<flux:menu.item
    :href="$link['href']"
    wire:navigate
    wire:current.ignore
    :data-current="$link['current'] ?: null"
    :aria-current="$link['current'] ? 'page' : null"
    class="group/item items-start! gap-3 px-2! py-2! data-current:bg-zinc-100 dark:data-current:bg-white/10"
>
    <span
        class="mt-px flex size-8 shrink-0 items-center justify-center rounded-md bg-zinc-100 text-zinc-500 transition-colors group-data-current/item:bg-accent-content group-data-current/item:text-accent-foreground dark:bg-white/10 dark:text-white/70"
    >
        <flux:icon :icon="$link['icon']" variant="micro" />
    </span>

    <span class="flex min-w-0 flex-col gap-0.5">
        <span class="text-sm font-medium text-zinc-800 dark:text-white">{{ $link['label'] }}</span>
        <span class="text-xs leading-snug font-normal text-zinc-500 dark:text-white/60">{{ $link['description'] }}</span>
    </span>
</flux:menu.item>
