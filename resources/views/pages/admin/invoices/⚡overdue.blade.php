<?php

use App\Models\SubscriptionInvoice;
use App\ValueObjects\Money;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

/*
| Every agency invoice that is still owed after its due date. Overdue is
| derived from the status and the due date on each read; no flag is stored.
*/
new #[Title('Overdue invoices')] class extends Component {
    use WithPagination;

    /**
     * Mount the component.
     */
    public function mount(): void
    {
        $this->authorize('viewAny', SubscriptionInvoice::class);
    }

    /**
     * Get one page of overdue invoices, longest overdue first.
     *
     * Authorized again here because page changes are Livewire update
     * requests, which do not re-run the console's route middleware.
     *
     * @return LengthAwarePaginator<int, SubscriptionInvoice>
     */
    #[Computed]
    public function invoices(): LengthAwarePaginator
    {
        $this->authorize('viewAny', SubscriptionInvoice::class);

        return SubscriptionInvoice::query()
            ->with('tenant')
            ->overdue(today())
            ->orderBy('due_on')
            ->orderBy('id')
            ->paginate(15);
    }
}; ?>

<div class="flex flex-col gap-6">
    <div>
        <flux:heading size="xl" level="1">{{ __('Overdue invoices') }}</flux:heading>
        <flux:text class="mt-1">{{ __('Invoices still owed after their due date, longest overdue first. Being overdue never suspends an agency on its own.') }}</flux:text>
    </div>

    <flux:table :paginate="$this->invoices">
        <flux:table.columns>
            <flux:table.column>{{ __('Agency') }}</flux:table.column>
            <flux:table.column class="max-md:hidden">{{ __('Reference') }}</flux:table.column>
            <flux:table.column class="max-lg:hidden">{{ __('Period') }}</flux:table.column>
            <flux:table.column>{{ __('Due') }}</flux:table.column>
            <flux:table.column align="end">{{ __('Outstanding') }}</flux:table.column>
            <flux:table.column class="max-sm:hidden">{{ __('Status') }}</flux:table.column>
        </flux:table.columns>

        <flux:table.rows>
            @forelse ($this->invoices as $invoice)
                <flux:table.row :key="$invoice->id">
                    <flux:table.cell>
                        <flux:link :href="route('admin.tenants.show', $invoice->tenant_id)" variant="ghost" class="font-medium" wire:navigate>
                            {{ $invoice->tenant?->name ?? $invoice->tenant_id }}
                        </flux:link>

                        <flux:text size="sm" class="md:hidden">{{ $invoice->reference }}</flux:text>
                    </flux:table.cell>

                    <flux:table.cell class="max-md:hidden">{{ $invoice->reference }}</flux:table.cell>

                    <flux:table.cell class="max-lg:hidden">
                        {{ $invoice->period_start_on->toFormattedDateString() }} – {{ $invoice->period_end_on->toFormattedDateString() }}
                    </flux:table.cell>

                    <flux:table.cell>
                        {{ $invoice->due_on->toFormattedDateString() }}
                        <flux:text size="sm">{{ trans_choice(':count day overdue|:count days overdue', (int) $invoice->due_on->diffInDays(today())) }}</flux:text>
                    </flux:table.cell>

                    <flux:table.cell align="end" variant="strong">
                        {{ Money::ofMinor($invoice->outstandingMinor(), $invoice->currency)->format() }}
                    </flux:table.cell>

                    <flux:table.cell class="max-sm:hidden">
                        <flux:badge :color="$invoice->status->color()" size="sm" inset="top bottom">{{ $invoice->status->label() }}</flux:badge>
                    </flux:table.cell>
                </flux:table.row>
            @empty
                <flux:table.row>
                    <flux:table.cell colspan="6" class="py-12 text-center">{{ __('No invoices are overdue.') }}</flux:table.cell>
                </flux:table.row>
            @endforelse
        </flux:table.rows>
    </flux:table>
</div>
