@props([
    'variant' => 'header',
    'showSettings' => true,
])

@php($user = auth()->user())

{{--
    The settings routes are registered on the central domain only. route() does
    not fail across hosts, so on a tenant domain the Settings link would quietly
    point at the central app - hence the gate rather than a trust in context.
--}}
<flux:dropdown position="bottom" align="end">
    @if ($variant === 'sidebar')
        <flux:sidebar.profile
            :name="$user->full_name"
            :initials="$user->initials()"
            icon:trailing="chevrons-up-down"
            data-test="sidebar-menu-button"
        />
    @else
        <flux:profile
            :name="$user->full_name"
            :initials="$user->initials()"
            icon:trailing="chevron-down"
            class="max-md:[&>span]:hidden"
            data-test="sidebar-menu-button"
        />
    @endif

    <flux:menu>
        <div class="flex items-center gap-2 px-1 py-1.5 text-start text-sm">
            <flux:avatar :name="$user->full_name" :initials="$user->initials()" />

            <div class="grid flex-1 text-start text-sm leading-tight">
                <flux:heading class="truncate">{{ $user->full_name }}</flux:heading>
                <flux:text class="truncate">{{ $user->email }}</flux:text>
            </div>
        </div>

        <flux:menu.separator />

        @if ($showSettings)
            <flux:menu.item :href="route('profile.edit')" icon="cog" wire:navigate>
                {{ __('Settings') }}
            </flux:menu.item>
        @endif

        <form method="POST" action="{{ route('logout') }}" class="w-full">
            @csrf
            <flux:menu.item
                as="button"
                type="submit"
                icon="arrow-right-start-on-rectangle"
                class="w-full cursor-pointer"
                data-test="logout-button"
            >
                {{ __('Log out') }}
            </flux:menu.item>
        </form>
    </flux:menu>
</flux:dropdown>
