@php
    $sections = app(\App\Services\Navigation\NavigationMenu::class)
        ->for(auth()->user(), tenancy()->initialized);
@endphp

{{--
    The horizontal navigation. ui-menu already owns ArrowUp/Down, Escape, focus
    return and focus containment inside an open panel, but Flux has nothing for
    moving along the row itself and no Home/End anywhere, so this adds both.

    The hooks are attributes Flux already emits on every item, and panel events
    reach this handler because a popover panel, though drawn in the top layer,
    is still a DOM descendant of ui-dropdown.

    -mb-px pulls the row onto the header's bottom border, where navbar.item's
    active underline (an ::after at -bottom-3) is positioned to land.
--}}
<flux:navbar
    scrollable
    class="-mb-px max-lg:hidden"
    :aria-label="__('Main')"
    x-data
    x-on:keydown="
        if (! ['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes($event.key)) return;

        const panel = $event.target.closest('[data-app-nav-panel]');

        // Inside a panel ui-menu handles the arrows; only Home/End are missing.
        if (panel && ! ['Home', 'End'].includes($event.key)) return;

        const selector = panel ? '[data-flux-menu-item]' : '[data-flux-navbar-items]';
        const items = [...(panel ?? $el).querySelectorAll(selector)].filter(item => ! item.disabled);
        const from = items.indexOf($event.target.closest(selector));

        if (from === -1) return;

        const to = $event.key === 'ArrowLeft' ? (from - 1 + items.length) % items.length
            : $event.key === 'ArrowRight' ? (from + 1) % items.length
            : $event.key === 'Home' ? 0
            : items.length - 1;

        items[to].focus();
        $event.preventDefault();
        $event.stopPropagation();
    "
>
    @foreach ($sections as $section)
        <x-app-nav.section :$section />
    @endforeach
</flux:navbar>
