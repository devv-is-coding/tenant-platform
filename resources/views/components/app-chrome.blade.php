@props(['trailing' => null])

@php
    $homeRoute = auth()->check() && ! tenancy()->initialized && auth()->user()->isSuperAdmin()
        ? 'admin.dashboard'
        : (tenancy()->initialized ? 'tenant.dashboard' : (auth()->check() ? 'dashboard' : 'home'));
@endphp

{{--
    The header bar, shared by the app shell, the tenant dashboard and the
    landing page so all three carry the same navigation.

    $trailing replaces the user menu, which is what the landing page uses to
    show sign-in calls to action instead.
--}}
<flux:header
    sticky
    container
    class="border-b border-zinc-200 bg-zinc-50/80 backdrop-blur-sm dark:border-zinc-700 dark:bg-zinc-900/80"
>
    @auth
        <flux:sidebar.toggle class="me-2 lg:hidden" icon="bars-2" inset="left" />
    @endauth

    {{-- flux:brand renders the wordmark as the last child div; hide it on narrow screens. --}}
    <x-app-logo :href="route($homeRoute)" class="max-md:[&>div:last-child]:hidden" wire:navigate />

    {{--
        Which agency a staff member is signed in to, carried over from the
        tenant dashboard's own header so that context is not lost.
    --}}
    @if (tenancy()->initialized)
        <flux:separator vertical class="me-4 my-3 max-md:hidden" />

        <flux:text class="me-4 truncate font-medium text-zinc-800 max-md:hidden dark:text-white">
            {{ tenant('name') ?? tenant('id') }}
        </flux:text>
    @endif

    <x-app-nav />

    <flux:spacer />

    @if ($trailing)
        {{ $trailing }}
    @else
        <x-desktop-user-menu :show-settings="! tenancy()->initialized" />
    @endif
</flux:header>

@auth
    <x-app-nav.mobile :$homeRoute />
@endauth
