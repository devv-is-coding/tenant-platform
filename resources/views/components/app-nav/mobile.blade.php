@php
    $sections = app(\App\Services\Navigation\NavigationMenu::class)
        ->for(auth()->user(), tenancy()->initialized);
@endphp

{{--
    Below lg the navbar is hidden and this off-canvas drawer takes over. It is
    a flux:sidebar used purely as a mobile panel - collapsible="mobile" plus
    lg:hidden means it has no desktop presence at all - which buys the backdrop,
    the slide transition and the no-flash cloak for free.

    It sits after the header in the document so Flux's grid keeps the header
    full width rather than putting a column beside it.

    Panel groups become accordions here, through the project's own expandable
    navlist.group override, so the structure survives rather than flattening.
--}}
<flux:sidebar
    collapsible="mobile"
    class="border-e border-zinc-200 bg-zinc-50 lg:hidden dark:border-zinc-700 dark:bg-zinc-900"
>
    <flux:sidebar.header>
        <x-app-logo :sidebar="true" :href="$homeRoute ?? route('home')" wire:navigate />
        <flux:sidebar.collapse />
    </flux:sidebar.header>

    <flux:sidebar.nav>
        <flux:navlist :aria-label="__('Main')">
            @foreach ($sections as $section)
                @if ($section['groups'] === [])
                    <flux:navlist.item
                        :icon="$section['icon']"
                        :href="$section['href']"
                        :current="$section['current']"
                        :aria-current="$section['current'] ? 'page' : null"
                        wire:navigate
                    >
                        {{ $section['label'] }}
                    </flux:navlist.item>
                @else
                    <flux:navlist.group expandable :heading="$section['label']" :expanded="$section['current']">
                        @foreach ($section['groups'] as $group)
                            @foreach ($group['links'] as $link)
                                <flux:navlist.item
                                    :icon="$link['icon']"
                                    :href="$link['href']"
                                    :current="$link['current']"
                                    :aria-current="$link['current'] ? 'page' : null"
                                    wire:navigate
                                >
                                    {{ $link['label'] }}
                                </flux:navlist.item>
                            @endforeach
                        @endforeach
                    </flux:navlist.group>
                @endif
            @endforeach
        </flux:navlist>
    </flux:sidebar.nav>
</flux:sidebar>
