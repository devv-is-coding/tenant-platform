<?php

namespace App\Providers;

use App\Enums\PlanFeature;
use App\Models\Passkey;
use App\Models\PersonalAccessToken;
use App\Models\PlanFeatureSetting;
use App\Models\RestoreRequest;
use App\Models\Subscription;
use App\Models\SubscriptionInvoice;
use App\Models\SubscriptionPayment;
use App\Models\SubscriptionPlan;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Plans\PlanFeatureMatrix;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Laravel\Passkeys\Passkeys;
use Laravel\Sanctum\Sanctum;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Scoped, so the feature overrides are read once per request or job.
        $this->app->scoped(PlanFeatureMatrix::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureCentralAuthModels();
        $this->configureMorphMap();
        $this->configurePlanFeatureGates();
    }

    /**
     * Register the ways code asks whether the current agency's tier includes a
     * feature, besides the `feature` route middleware. All of them defer to
     * Tenant::hasFeature(), so the answer is the same everywhere.
     *
     * The ability is for policies: `$user->can('use-feature', PlanFeature::Reviews)`.
     * The directive is for views: `@feature(PlanFeature::Reviews) … @endfeature`.
     * Outside an agency there is no tier, so both say no.
     */
    protected function configurePlanFeatureGates(): void
    {
        // The user is not consulted: a feature belongs to the agency's plan,
        // so everyone acting within the agency has it or nobody does.
        Gate::define('use-feature', fn (?User $_user, PlanFeature $feature): bool => $this->currentTenantHas($feature));

        Blade::if('feature', fn (PlanFeature $feature): bool => $this->currentTenantHas($feature));
    }

    /**
     * Determine if the agency of the current request has the feature.
     */
    private function currentTenantHas(PlanFeature $feature): bool
    {
        $tenant = tenant();

        return $tenant instanceof Tenant && $tenant->hasFeature($feature);
    }

    /**
     * Store polymorphic types, such as an audit event's subject, under short
     * aliases rather than class names, so renaming a class never strands rows.
     *
     * Not enforced yet: models without an alias still fall back to their class
     * name until the full map is in place.
     */
    protected function configureMorphMap(): void
    {
        Relation::morphMap([
            'plan_feature' => PlanFeatureSetting::class,
            'restore_request' => RestoreRequest::class,
            'subscription' => Subscription::class,
            'subscription_invoice' => SubscriptionInvoice::class,
            'subscription_payment' => SubscriptionPayment::class,
            'subscription_plan' => SubscriptionPlan::class,
            'user' => User::class,
        ]);
    }

    /**
     * Point the auth-related models at app-level subclasses that are pinned to the
     * central connection. Without this they would resolve against whichever tenant
     * database is active, where these tables do not exist.
     */
    protected function configureCentralAuthModels(): void
    {
        Passkeys::usePasskeyModel(Passkey::class);
        Sanctum::usePersonalAccessTokenModel(PersonalAccessToken::class);
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
