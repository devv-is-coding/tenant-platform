@php($title = __('Welcome'))
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-white antialiased dark:bg-linear-to-b dark:from-neutral-950 dark:to-neutral-900">
        <a
            href="#main-content"
            class="sr-only focus:not-sr-only focus:absolute focus:start-4 focus:top-4 focus:z-50 focus:rounded-lg focus:bg-white focus:px-4 focus:py-2 focus:text-sm focus:font-medium focus:text-zinc-800 focus:shadow-lg dark:focus:bg-zinc-900 dark:focus:text-white"
        >
            {{ __('Skip to content') }}
        </a>

        <x-app-chrome>
            <x-slot:trailing>
                @guest
                    <flux:button :href="route('login')" variant="primary" size="sm" icon:trailing="arrow-right" wire:navigate>
                        {{ __('Sign in') }}
                    </flux:button>
                @else
                    <x-desktop-user-menu />
                @endguest
            </x-slot:trailing>
        </x-app-chrome>

        <main id="main-content" class="mx-auto w-full max-w-5xl p-gutter lg:p-gutter-lg">
            <section class="flex flex-col items-start gap-6">
                <div class="flex size-16 items-center justify-center rounded-2xl bg-accent-content text-accent-foreground">
                    <x-app-logo-icon class="size-9 fill-current" />
                </div>

                <div class="flex flex-col gap-4">
                    <flux:heading size="xl" class="max-w-2xl text-4xl! leading-tight! lg:text-5xl!">
                        {{ __('One platform, every agency its own.') }}
                    </flux:heading>

                    <flux:text class="max-w-xl text-base!">
                        {{ __(':name gives each travel agency an isolated database, its own domain and its own staff, while the platform keeps oversight of them all.', ['name' => config('app.name')]) }}
                    </flux:text>
                </div>

                <div class="mt-2 flex flex-wrap items-center gap-3">
                    @guest
                        <flux:button :href="route('login')" variant="primary" icon:trailing="arrow-right" wire:navigate>
                            {{ __('Sign in') }}
                        </flux:button>

                        <flux:button :href="route('admin.login')" variant="ghost" icon="shield-check" wire:navigate>
                            {{ __('Platform console') }}
                        </flux:button>
                    @else
                        @if (auth()->user()->isSuperAdmin())
                            <flux:button :href="route('admin.dashboard')" variant="primary" icon="shield-check" wire:navigate>
                                {{ __('Open the console') }}
                            </flux:button>
                        @elseif (auth()->user()->role->isTenantRole())
                            {{--
                                tenant.dashboard carries no domain constraint, so from the
                                central host route() would return the central dashboard URL.
                                Staff are sent to their agency's own login instead.
                            --}}
                            <flux:button :href="route('login')" variant="primary" icon:trailing="arrow-right" wire:navigate>
                                {{ __('Go to your agency portal') }}
                            </flux:button>
                        @else
                            <flux:button :href="route('dashboard')" variant="primary" icon="home" wire:navigate>
                                {{ __('Open your dashboard') }}
                            </flux:button>
                        @endif
                    @endguest
                </div>
            </section>

            <section class="mt-20 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ([
                    ['icon' => 'building-office-2', 'heading' => __('A database per agency'), 'text' => __('Every agency is provisioned with its own database and domain, so one agency can never read another\'s bookings.')],
                    ['icon' => 'users', 'heading' => __('Separate portals per role'), 'text' => __('Platform staff, agency staff and customers each sign in through their own portal, enforced on every request.')],
                    ['icon' => 'finger-print', 'heading' => __('Passkeys and two-factor'), 'text' => __('Passwords, passkeys, two-factor and password confirmation are all built in from the start.')],
                ] as $feature)
                    <flux:card class="flex flex-col gap-3">
                        <flux:icon :icon="$feature['icon']" class="size-6 text-zinc-500 dark:text-white/70" />

                        <flux:heading size="lg">{{ $feature['heading'] }}</flux:heading>
                        <flux:text>{{ $feature['text'] }}</flux:text>
                    </flux:card>
                @endforeach
            </section>
        </main>

        <x-app-footer class="mt-20" />

        @fluxScripts
    </body>
</html>
