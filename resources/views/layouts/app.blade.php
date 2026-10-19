<x-layouts::app.navbar :title="$title ?? null">
    <flux:main id="main-content">
        {{ $slot }}
    </flux:main>
</x-layouts::app.navbar>
