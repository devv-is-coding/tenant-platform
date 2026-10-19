<x-layouts::app.navbar :title="$title ?? null">
    {{--
        container matches the header, so page content sits in the same centred
        max-w-7xl column as the brand and navigation instead of running to the
        far edge on wide screens.

        The class overrides flux:main's own p-6 lg:p-8 so the gutter comes from
        the theme token.
    --}}
    <flux:main container id="main-content" class="max-w-layout p-gutter! lg:p-gutter-lg!">
        {{ $slot }}
    </flux:main>
</x-layouts::app.navbar>
