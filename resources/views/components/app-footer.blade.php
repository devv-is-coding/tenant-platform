{{--
    Shared by the app shell and the landing page.

    flux:footer sits in the grid's footer row, below the page body. Its own
    p-6 lg:p-8 is cleared so the inner row can take the same max-w-layout column
    and gutter the header uses, putting the footer text on the same left edge as
    the brand and the navigation. The border spans the full width, mirroring the
    header's bottom border.
--}}
<flux:footer {{ $attributes->class('border-t border-zinc-200 p-0! dark:border-zinc-700') }}>
    <div class="mx-auto flex w-full max-w-layout flex-wrap items-center justify-between gap-2 px-gutter py-4 lg:px-gutter-lg">
        <flux:text size="sm">
            &copy; {{ now()->year }} {{ config('app.name') }}
        </flux:text>

        @if (tenancy()->initialized)
            <flux:text size="sm">
                {{ tenant('name') ?? tenant('id') }}
            </flux:text>
        @endif
    </div>
</flux:footer>
