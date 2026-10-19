<?php

use App\Actions\Tenants\OnboardTenantAction;
use App\Models\Tenant;
use Flux\Flux;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Onboard an agency')] class extends Component {
    /**
     * The fields each step of the wizard asks for, in order.
     *
     * @var array<int, list<string>>
     */
    private const STEPS = [
        1 => ['name', 'subdomain'],
        2 => ['locality', 'currency'],
        3 => ['first_name', 'last_name', 'username', 'email', 'password'],
    ];

    public int $step = 1;

    public string $name = '';
    public string $subdomain = '';
    public string $locality = '';
    public string $currency = '';
    public string $first_name = '';
    public string $last_name = '';
    public string $username = '';
    public string $email = '';
    public string $password = '';
    public string $password_confirmation = '';

    /**
     * Mount the component.
     */
    public function mount(): void
    {
        $this->authorize('create', Tenant::class);

        $this->currency = $this->currencies[0];
    }

    /**
     * Validate the current step and move to the next, onboarding the agency
     * once the last step is done.
     */
    public function nextStep(OnboardTenantAction $onboardTenant): void
    {
        $this->validate(Arr::only($onboardTenant->rules(), self::STEPS[$this->step]), $onboardTenant->messages());

        if ($this->step < count(self::STEPS)) {
            $this->step++;

            return;
        }

        $this->onboard($onboardTenant);
    }

    /**
     * Return to the previous step.
     */
    public function previousStep(): void
    {
        $this->resetErrorBag();

        $this->step = max(1, $this->step - 1);
    }

    /**
     * Onboard the agency and its first admin.
     *
     * Authorized again here because Livewire's update requests do not re-run
     * the console's route middleware. Every field is validated again too, so
     * a step skipped client-side cannot slip through; if an earlier step's
     * field fails (say, its subdomain was taken meanwhile), the wizard returns
     * to that step so the error is visible.
     */
    public function onboard(OnboardTenantAction $onboardTenant): void
    {
        $this->authorize('create', Tenant::class);

        try {
            $tenant = $onboardTenant->handle(Auth::user(), $this->only([
                ...array_merge(...self::STEPS),
                'password_confirmation',
            ]));
        } catch (ValidationException $exception) {
            $this->step = collect(self::STEPS)
                ->filter(fn (array $fields): bool => Arr::hasAny($exception->errors(), $fields))
                ->keys()
                ->first() ?? $this->step;

            throw $exception;
        }

        $this->resetExcept('currency');

        Flux::toast(variant: 'success', text: __(':name is onboarded and pending activation.', ['name' => $tenant->name]));
    }

    /**
     * @return list<string>
     */
    #[Computed]
    public function currencies(): array
    {
        return config('tenancy.currencies');
    }

    #[Computed]
    public function centralHost(): string
    {
        return (string) parse_url((string) config('app.url'), PHP_URL_HOST);
    }

    /**
     * @return array{icon: string, heading: string, subheading: string}
     */
    #[Computed]
    public function stepIntro(): array
    {
        return match ($this->step) {
            1 => ['icon' => 'building-office-2', 'heading' => __('Which agency are you onboarding?'), 'subheading' => __('Its name, and the subdomain it will trade on.')],
            2 => ['icon' => 'map-pin', 'heading' => __('Where does it trade?'), 'subheading' => __('Its home locality and the currency its prices are in.')],
            default => ['icon' => 'user-circle', 'heading' => __('Who is its first admin?'), 'subheading' => __('They can add the rest of the agency\'s team.')],
        };
    }

    /**
     * Short names for the steps, listed beside the form on wide screens.
     *
     * @return array<int, string>
     */
    #[Computed]
    public function stepLabels(): array
    {
        return [
            1 => __('Agency'),
            2 => __('Locality & currency'),
            3 => __('First admin'),
        ];
    }
}; ?>

{{-- A tall single-column card on phones; on wide screens a landscape card, centred in the
     viewport (100dvh less flux:main's lg:p-8), with the question on the left. --}}
<div class="flex w-full justify-center lg:min-h-[calc(100dvh-4rem)] lg:items-center">
    <form
        wire:submit="nextStep"
        class="flex min-h-[36rem] w-full max-w-lg flex-col overflow-hidden rounded-2xl border border-zinc-200 bg-white shadow-xs lg:min-h-[38rem] lg:max-w-6xl dark:border-zinc-700 dark:bg-zinc-900"
    >
        <div class="flex flex-1 flex-col lg:flex-row">
            <div class="flex flex-col px-6 pt-8 sm:px-8 lg:w-2/5 lg:border-e lg:border-zinc-200 lg:bg-zinc-50 lg:px-12 lg:py-12 lg:dark:border-zinc-700 lg:dark:bg-zinc-800/40">
                <div class="flex items-center justify-between">
                    <div class="flex size-12 items-center justify-center rounded-full lg:size-16 bg-zinc-100 ring-1 ring-zinc-200 dark:bg-zinc-800 dark:ring-zinc-700">
                        <flux:icon :name="$this->stepIntro['icon']" class="size-6 text-zinc-700 lg:size-8 dark:text-zinc-200" />
                    </div>

                    <div class="flex items-center gap-3 lg:hidden">
                        <flux:text size="sm">{{ __('Step :step of :total', ['step' => $step, 'total' => count($this->stepLabels)]) }}</flux:text>

                        <div class="flex gap-1">
                            @foreach ($this->stepLabels as $position => $label)
                                <div @class([
                                    'h-1.5 w-6 rounded-full',
                                    'bg-zinc-800 dark:bg-white' => $position <= $step,
                                    'bg-zinc-200 dark:bg-zinc-700' => $position > $step,
                                ])></div>
                            @endforeach
                        </div>
                    </div>
                </div>

                <flux:heading size="xl" class="mt-6 lg:mt-8 lg:text-3xl!">{{ $this->stepIntro['heading'] }}</flux:heading>
                <flux:text class="mt-1 lg:mt-3 lg:text-base!">{{ $this->stepIntro['subheading'] }}</flux:text>

                <ol class="mt-auto hidden space-y-4 pt-12 lg:block">
                    @foreach ($this->stepLabels as $position => $label)
                        <li class="flex items-center gap-3">
                            <span @class([
                                'flex size-8 shrink-0 items-center justify-center rounded-full text-sm font-medium',
                                'bg-zinc-800 text-white dark:bg-white dark:text-zinc-800' => $position <= $step,
                                'bg-zinc-200 text-zinc-500 dark:bg-zinc-700 dark:text-zinc-400' => $position > $step,
                            ])>
                                @if ($position < $step)
                                    <flux:icon.check variant="micro" />
                                @else
                                    {{ $position }}
                                @endif
                            </span>

                            <flux:text @class(['text-base!', 'font-medium text-zinc-800 dark:text-white' => $position === $step])>{{ $label }}</flux:text>
                        </li>
                    @endforeach
                </ol>
            </div>

            <div class="flex-1 space-y-6 px-6 py-8 sm:px-8 lg:space-y-8 lg:px-12 lg:py-12" wire:key="step-{{ $step }}">
                @if ($step === 1)
                    <flux:input wire:model="name" :label="__('Agency name')" type="text" required autofocus :placeholder="__('Dumaguete City Travel & Tours')" />

                    <flux:field>
                        <flux:label>{{ __('Subdomain') }}</flux:label>
                        <flux:description>{{ __('Also the agency\'s permanent id. Lowercase letters, numbers and dashes.') }}</flux:description>

                        <flux:input.group>
                            <flux:input wire:model="subdomain" required :placeholder="__('dumaguete')" />
                            <flux:input.group.suffix>.{{ $this->centralHost }}</flux:input.group.suffix>
                        </flux:input.group>

                        <flux:error name="subdomain" />
                    </flux:field>
                @elseif ($step === 2)
                    <flux:input wire:model="locality" :label="__('Locality')" type="text" required autofocus :placeholder="__('Dumaguete City')" />

                    <flux:radio.group wire:model="currency" :label="__('Currency')" variant="pills">
                        @foreach ($this->currencies as $code)
                            <flux:radio :value="$code" :label="$code" />
                        @endforeach
                    </flux:radio.group>
                @else
                    <div class="grid gap-6 sm:grid-cols-2">
                        <flux:input wire:model="first_name" :label="__('First name')" type="text" required autofocus autocomplete="off" />
                        <flux:input wire:model="last_name" :label="__('Last name')" type="text" required autocomplete="off" />
                    </div>

                    <flux:input wire:model="username" :label="__('Username')" type="text" required autocomplete="off" />

                    <flux:input wire:model="email" :label="__('Email')" type="email" required autocomplete="off" :placeholder="__('admin@agency.com')" />

                    <div class="grid gap-6 lg:grid-cols-2">
                        <flux:input wire:model="password" :label="__('Password')" type="password" required autocomplete="new-password" viewable />
                        <flux:input wire:model="password_confirmation" :label="__('Confirm password')" type="password" required autocomplete="new-password" viewable />
                    </div>
                @endif
            </div>
        </div>

        <div class="flex items-center justify-between border-t border-zinc-200 px-6 py-4 sm:px-8 lg:px-12 lg:py-6 dark:border-zinc-700">
            @if ($step > 1)
                <flux:button icon="chevron-left" wire:click="previousStep">{{ __('Go back') }}</flux:button>
            @else
                <div></div>
            @endif

            <flux:button variant="primary" type="submit">
                {{ $step < count($this->stepLabels) ? __('Continue') : __('Onboard agency') }}
            </flux:button>
        </div>
    </form>
</div>
