@props(['section'])

@php($focusRing = 'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent-content')

@if ($section['groups'] === [])
    <flux:navbar.item
        :icon="$section['icon']"
        :href="$section['href']"
        :current="$section['current']"
        :aria-current="$section['current'] ? 'page' : null"
        :class="$focusRing"
        wire:navigate
    >
        {{ $section['label'] }}
    </flux:navbar.item>
@else
    {{--
        ui-dropdown resolves its parts structurally:
            trigger = querySelector('button')              -> the FIRST button in this subtree
            overlay = lastElementChild.matches('[popover]') -> the panel must stay LAST

        So the trigger carries no href (an href renders an <a>, which cannot
        trigger), and nothing may be inserted before it or after the panel.
        Keep this body to exactly these two tags.
    --}}
    <flux:dropdown position="bottom" align="start" gap="0" hover>
        <flux:navbar.item
            :icon="$section['icon']"
            icon:trailing="chevron-down"
            :current="$section['current']"
            :class="$focusRing.' [&_svg:last-of-type]:transition-transform [&_svg:last-of-type]:duration-200 [&[data-open]_svg:last-of-type]:rotate-180'"
        >
            {{ $section['label'] }}
        </flux:navbar.item>

        <flux:menu
            data-app-nav-panel
            :aria-label="$section['label']"
            class="w-max max-w-[calc(100vw-2rem)] origin-top-left p-2!"
        >
            <div @class(['grid gap-x-2 gap-y-1', 'sm:grid-cols-2' => count($section['groups']) > 1])>
                @foreach ($section['groups'] as $group)
                    <div role="group" @if ($group['heading']) aria-label="{{ $group['heading'] }}" @endif>
                        @if ($group['heading'])
                            <flux:menu.heading>{{ $group['heading'] }}</flux:menu.heading>
                        @endif

                        @foreach ($group['links'] as $link)
                            <x-app-nav.panel-item :$link />
                        @endforeach
                    </div>
                @endforeach
            </div>
        </flux:menu>
    </flux:dropdown>
@endif
