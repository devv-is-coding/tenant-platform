@props([
    'sidebar' => false,
])

{{-- The mark sits on a paper tile, so it reads on the maroon top bar and on any ground. --}}
@if($sidebar)
    <flux:sidebar.brand :name="config('app.name', 'Laravel')" {{ $attributes }}>
        <x-slot name="logo" class="flex aspect-square size-8 items-center justify-center rounded-md bg-paper text-seal ring-1 ring-black/10">
            <x-app-logo-icon class="size-6" />
        </x-slot>
    </flux:sidebar.brand>
@else
    <flux:brand :name="config('app.name', 'Laravel')" {{ $attributes }}>
        <x-slot name="logo" class="flex aspect-square size-8 items-center justify-center rounded-md bg-paper text-seal ring-1 ring-black/10">
            <x-app-logo-icon class="size-6" />
        </x-slot>
    </flux:brand>
@endif
