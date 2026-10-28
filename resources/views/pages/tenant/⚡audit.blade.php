<?php

use App\Enums\AuditAction;
use App\Models\AuditEvent;
use App\Models\Tenant;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/*
| The agency's own audit trail: every row in its `audit_events` table, newest
| first. That is everything its people did, its team changes included, and
| the platform's lifecycle notices about it (a suspension, a reactivation),
| so the Admin sees what the platform did and why. It never reads another
| agency's log or the platform's.
|
| The agency's Admin's alone, on every tier and in every status: the log is
| the agency's own record, which a lapsed agency keeps.
|
| Every filter lives in the query string, so a filtered view can be linked to.
| A value that does not parse, such as a hand-edited date, is ignored rather
| than refused.
*/
new #[Title('Audit log')] class extends Component {
    use WithPagination;

    /**
     * The actor filter's value for actions nobody signed in performed.
     */
    public const SYSTEM_ACTOR = 'system';

    /**
     * Filter properties that narrow the log, so changing one returns to the
     * first page.
     */
    private const FILTERS = ['search', 'actor', 'action', 'from', 'to'];

    /**
     * Free text matched against the description, the actor and the IP
     * address, as recorded.
     */
    #[Url(except: '')]
    public string $search = '';

    /**
     * A user id, SYSTEM_ACTOR, or empty for everyone. Kept as a string
     * because that is what the select and the query string hand back.
     */
    #[Url(except: '')]
    public string $actor = '';

    /**
     * An AuditAction value, or empty for every action.
     */
    #[Url(except: '')]
    public string $action = '';

    /**
     * The first day to include, as Y-m-d.
     */
    #[Url(except: '')]
    public string $from = '';

    /**
     * The last day to include, as Y-m-d.
     */
    #[Url(except: '')]
    public string $to = '';

    /**
     * Mount the component.
     */
    public function mount(): void
    {
        $this->authorize('viewAny', AuditEvent::class);
    }

    /**
     * Return to the first page when a filter changes, since the current page
     * may not exist in the narrower result set.
     */
    public function updated(string $property): void
    {
        if (in_array($property, self::FILTERS, true)) {
            $this->resetPage();
        }
    }

    /**
     * Clear every filter.
     */
    public function clearFilters(): void
    {
        $this->reset(self::FILTERS);
        $this->resetPage();
    }

    /**
     * Get the agency the log is for.
     */
    #[Computed]
    public function agency(): Tenant
    {
        $agency = tenant();

        abort_unless($agency instanceof Tenant, 404);

        return $agency;
    }

    /**
     * Get one page of the log, newest first.
     *
     * Authorized again here because every filter and page change is a
     * Livewire update request, which does not re-run the route middleware's
     * role checks on its own.
     *
     * @return LengthAwarePaginator<int, AuditEvent>
     */
    #[Computed]
    public function events(): LengthAwarePaginator
    {
        $this->authorize('viewAny', AuditEvent::class);

        $search = trim($this->search);
        $from = $this->parseDate($this->from);
        $to = $this->parseDate($this->to);

        return AuditEvent::query()
            ->when($search !== '', fn (Builder $query) => $query->where(fn (Builder $query) => $query
                ->where('description', 'like', "%{$search}%")
                ->orWhere('actor_label', 'like', "%{$search}%")
                ->orWhere('ip_address', 'like', "%{$search}%")))
            ->when($this->actor === self::SYSTEM_ACTOR, fn (Builder $query) => $query->whereNull('actor_id'))
            ->when(ctype_digit($this->actor), fn (Builder $query) => $query->where('actor_id', (int) $this->actor))
            ->when(AuditAction::tryFrom($this->action), fn (Builder $query, AuditAction $action) => $query->where('action', $action))
            ->when($from, fn (Builder $query, string $from) => $query->whereDate('created_at', '>=', $from))
            ->when($to, fn (Builder $query, string $to) => $query->whereDate('created_at', '<=', $to))
            ->latest('created_at')
            ->latest('id')
            ->paginate(25);
    }

    /**
     * Get everyone who appears in the log as an actor, keyed by user id and
     * labelled as of their most recent action, for the actor filter.
     *
     * Read from the log rather than the users table, so someone who has since
     * left can still be filtered on.
     *
     * @return Collection<int, string>
     */
    #[Computed]
    public function actors(): Collection
    {
        $this->authorize('viewAny', AuditEvent::class);

        return AuditEvent::query()
            ->whereIn('id', AuditEvent::query()->selectRaw('MAX(id)')->whereNotNull('actor_id')->groupBy('actor_id'))
            ->orderBy('actor_label')
            ->pluck('actor_label', 'actor_id')
            ->toBase();
    }

    /**
     * Get the actions that appear in the log, for the action filter.
     *
     * @return list<AuditAction>
     */
    #[Computed]
    public function actions(): array
    {
        $this->authorize('viewAny', AuditEvent::class);

        return AuditEvent::query()
            ->distinct()
            ->orderBy('action')
            ->pluck('action')
            ->all();
    }

    /**
     * Determine if any filter is narrowing the log.
     */
    #[Computed]
    public function isFiltered(): bool
    {
        return collect(self::FILTERS)->contains(fn (string $filter): bool => $this->{$filter} !== '');
    }

    /**
     * Read a Y-m-d date from a filter, or null if it is not a real date.
     */
    private function parseDate(string $value): ?string
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date !== false && $date->format('Y-m-d') === $value ? $value : null;
    }
}; ?>

<div class="flex flex-col gap-6">
    <div>
        <flux:heading size="xl" level="1">{{ __('Audit log') }}</flux:heading>
        <flux:text class="mt-1">{{ __('Everything done at :tenant, newest first, with what the platform did and why.', ['tenant' => $this->agency->name ?? $this->agency->id]) }}</flux:text>
    </div>

    <div class="grid gap-3 xl:grid-cols-3">
        <flux:input
            wire:model.live.debounce.300ms="search"
            :label="__('Search')"
            icon="magnifying-glass"
            :placeholder="__('Description, person or IP')"
            clearable
        />

        <div class="grid gap-3 sm:grid-cols-2 xl:col-span-2 xl:grid-cols-4">
            <flux:select wire:model.live="actor" :label="__('By')">
                <flux:select.option value="">{{ __('Anyone') }}</flux:select.option>
                <flux:select.option :value="$this::SYSTEM_ACTOR">{{ __('System') }}</flux:select.option>

                @foreach ($this->actors as $actorId => $actorLabel)
                    <flux:select.option :value="$actorId">{{ $actorLabel ?? __('User #:id', ['id' => $actorId]) }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:select wire:model.live="action" :label="__('Action')">
                <flux:select.option value="">{{ __('All actions') }}</flux:select.option>

                @foreach ($this->actions as $actionOption)
                    <flux:select.option :value="$actionOption->value">{{ $actionOption->label() }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:input type="date" wire:model.live="from" :label="__('From')" :max="today()->toDateString()" />

            <flux:input type="date" wire:model.live="to" :label="__('To')" :max="today()->toDateString()" />
        </div>
    </div>

    @if ($this->isFiltered)
        <div>
            <flux:button size="sm" variant="ghost" icon="x-mark" wire:click="clearFilters">{{ __('Clear filters') }}</flux:button>
        </div>
    @endif

    <flux:table :paginate="$this->events">
        <flux:table.columns>
            <flux:table.column>{{ __('When') }}</flux:table.column>
            <flux:table.column>{{ __('Action') }}</flux:table.column>
            <flux:table.column class="max-sm:hidden">{{ __('By') }}</flux:table.column>
        </flux:table.columns>

        <flux:table.rows>
            @forelse ($this->events as $event)
                <flux:table.row :key="$event->id">
                    <flux:table.cell class="whitespace-nowrap align-top">
                        <time datetime="{{ $event->created_at?->toIso8601String() }}">{{ $event->created_at?->toDayDateTimeString() }}</time>
                        <flux:text size="sm">{{ $event->created_at?->diffForHumans() }}</flux:text>
                    </flux:table.cell>

                    <flux:table.cell class="align-top">
                        <span class="flex flex-wrap items-center gap-2">
                            <span class="font-medium text-zinc-800 dark:text-white">{{ $event->action->label() }}</span>

                            {{-- Lifecycle notices are the platform's, written into this log. --}}
                            @if ($event->action->domain() === 'tenant')
                                <flux:badge size="sm" color="sky" inset="top bottom">{{ __('Platform') }}</flux:badge>
                            @endif
                        </span>

                        @if ($event->subject_type && $event->subject_id)
                            <flux:text size="sm">{{ Str::headline($event->subject_type) }} #{{ $event->subject_id }}</flux:text>
                        @endif

                        @if ($event->description)
                            <flux:text size="sm" class="mt-1 max-w-md whitespace-normal">{{ $event->description }}</flux:text>
                        @endif

                        @foreach ($event->changeSummary() as $change)
                            <flux:text size="sm" class="font-mono">{{ $change }}</flux:text>
                        @endforeach

                        <flux:text size="sm" class="sm:hidden">{{ $event->actor_label ?? __('System') }}</flux:text>
                    </flux:table.cell>

                    <flux:table.cell class="align-top max-sm:hidden">
                        {{ $event->actor_label ?? __('System') }}

                        @if ($event->ip_address)
                            <flux:text size="sm">{{ $event->ip_address }}</flux:text>
                        @endif
                    </flux:table.cell>
                </flux:table.row>
            @empty
                <flux:table.row>
                    <flux:table.cell colspan="3" class="py-12 text-center">
                        {{ $this->isFiltered ? __('No activity matches these filters.') : __('No activity has been recorded yet.') }}
                    </flux:table.cell>
                </flux:table.row>
            @endforelse
        </flux:table.rows>
    </flux:table>
</div>
