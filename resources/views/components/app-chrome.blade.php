@props(['trailing' => null])

@php
    $homeRoute = auth()->check() && ! tenancy()->initialized && auth()->user()->isSuperAdmin()
        ? 'admin.dashboard'
        : (tenancy()->initialized ? 'tenant.dashboard' : (auth()->check() ? 'dashboard' : 'home'));

    // The security band's caption: whose document this page is.
    $band = match (true) {
        tenancy()->initialized => tenant('name') ?? tenant('id'),
        $homeRoute === 'admin.dashboard' => __('Platform console'),
        default => config('app.name'),
    };
@endphp

{{--
    The header bar, shared by the app shell, the tenant dashboard and the
    landing page so all three carry the same navigation.

    It is the passport cover: maroon, with the security band printed under it
    (data-passport-bar styles its Flux parts in app.css).

    $trailing replaces the user menu, which is what the landing page uses to
    show sign-in calls to action instead.
--}}
<flux:header
    sticky
    container
    data-passport-bar
    :data-band="$band"
    class="bg-passport"
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
        <flux:separator vertical class="me-4 my-3 bg-white/25! max-md:hidden" />

        <flux:text class="me-4 truncate font-semibold text-white! max-md:hidden">
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
