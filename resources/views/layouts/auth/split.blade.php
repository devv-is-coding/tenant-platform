@php($homeUrl = tenancy()->initialized ? route('tenant.homepage') : route('home'))

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-white antialiased dark:bg-linear-to-b dark:from-neutral-950 dark:to-neutral-900">
        <div class="relative grid h-dvh grid-cols-1 flex-col items-center justify-center px-8 sm:px-0 lg:max-w-none lg:grid-cols-2 lg:px-0">
            <div class="bg-muted relative hidden h-full flex-col p-10 text-white lg:flex dark:border-e dark:border-neutral-800">
                <div class="absolute inset-0 bg-neutral-900"></div>
                <a href="{{ $homeUrl }}" class="relative z-20 flex items-center text-lg font-medium" wire:navigate>
                    <span class="flex h-10 w-10 items-center justify-center rounded-md">
                        <x-app-logo-icon class="me-2 h-7 fill-current text-white" />
                    </span>
                    {{ config('app.name', 'Laravel') }}
                </a>

                <div class="relative z-20 mt-auto space-y-4">
                    <flux:heading size="lg" class="text-white">
                        {{ $panelTitle ?? (tenancy()->initialized ? tenant('name') ?? tenant('id') : config('app.name', 'Laravel')) }}
                    </flux:heading>

                    <p class="max-w-sm text-sm leading-relaxed text-neutral-300">
                        {{ $panelDescription ?? (tenancy()->initialized
                            ? __('Staff portal for administrators, managers and staff of this agency.')
                            : __('Sign in to your customer account.')) }}
                    </p>

                    @if ($panelNotice ?? null)
                        <div class="flex items-center gap-2 pt-2 text-xs text-neutral-400">
                            <flux:icon.shield-check variant="micro" />
                            <span>{{ $panelNotice }}</span>
                        </div>
                    @endif
                </div>
            </div>
            <div class="w-full lg:p-8">
                <div class="mx-auto flex w-full flex-col justify-center space-y-6 sm:w-[350px]">
                    <a href="{{ $homeUrl }}" class="z-20 flex flex-col items-center gap-2 font-medium lg:hidden" wire:navigate>
                        <span class="flex h-9 w-9 items-center justify-center rounded-md">
                            <x-app-logo-icon class="size-9 fill-current text-black dark:text-white" />
                        </span>

                        <span class="sr-only">{{ config('app.name', 'Laravel') }}</span>
                    </a>
                    {{ $slot }}
                </div>
            </div>
        </div>

        @persist('toast')
            <flux:toast.group>
                <flux:toast />
            </flux:toast.group>
        @endpersist

        @fluxScripts
    </body>
</html>
