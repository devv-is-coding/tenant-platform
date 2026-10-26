{{--
    Wraps a customer's personal details or a money figure on an agency
    screen, and hides it while the agency is Closed
    (TenantStatus::hidesCustomersAndMoney()). A booking's reference, dates and
    status are never wrapped, so staff can still honour travel already sold.

        <x-tenant.masked>{{ $booking->customer_email }}</x-tenant.masked>

    This hides what the page prints, not what a Livewire component sends: a
    customer's details or an amount held in a public property still reach the
    browser in the component's snapshot. Read them in computed properties or
    the view instead.
--}}
@if (tenant() instanceof \App\Models\Tenant && tenant()->status->hidesCustomersAndMoney())
    <span {{ $attributes->class('text-zinc-400 dark:text-zinc-500') }}>{{ __('Hidden') }}</span>
@else
    {{ $slot }}
@endif
