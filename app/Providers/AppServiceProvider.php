<?php

namespace App\Providers;

use App\Models\Passkey;
use App\Models\PersonalAccessToken;
use App\Models\Subscription;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
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
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureCentralAuthModels();
        $this->configureMorphMap();
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
            'subscription' => Subscription::class,
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
