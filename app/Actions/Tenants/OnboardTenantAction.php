<?php

namespace App\Actions\Tenants;

use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Enums\AuditAction;
use App\Enums\Role;
use App\Models\Domain;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Audit\AuditRecorder;
use Closure;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Throwable;

class OnboardTenantAction
{
    use PasswordValidationRules, ProfileValidationRules;

    public function __construct(private AuditRecorder $auditRecorder) {}

    /**
     * Validate and onboard a new agency together with its first admin.
     *
     * Creating the tenant fires TenantCreated, which provisions its database
     * (create, migrate, seed the settings row). The tenant starts Pending:
     * going live is a separate decision, not a side effect of onboarding.
     *
     * No transaction can roll back a created database, so a failure in a
     * later step is undone by deleting the tenant instead - which drops its
     * database and, through the cascading foreign keys, its domain and users.
     *
     * @param  array<string, string>  $input
     */
    public function handle(User $actor, array $input): Tenant
    {
        $validated = Validator::make($input, $this->rules(), $this->messages())->validate();

        $tenant = Tenant::create([
            'id' => $validated['subdomain'],
            'name' => $validated['name'],
            'locality' => $validated['locality'],
            'currency' => $validated['currency'],
        ]);

        try {
            $tenant->domains()->create(['domain' => $this->domainFor($validated['subdomain'])]);

            // The role is always explicit: the column's old default was super admin.
            User::create([
                'username' => $validated['username'],
                'email' => $validated['email'],
                'password' => $validated['password'],
                'first_name' => $validated['first_name'],
                'last_name' => $validated['last_name'],
                'role' => Role::Admin,
                'tenant_id' => $tenant->id,
                'is_active' => true,
            ]);

            $this->auditRecorder->recordPlatformEvent(AuditAction::TenantCreated, $actor, $tenant);
        } catch (Throwable $exception) {
            // Undoing a half-created agency that never went live: a force
            // delete, so its database is dropped rather than left behind.
            $tenant->forceDelete();

            throw $exception;
        }

        return $tenant;
    }

    /**
     * Get the onboarding validation rules. Public so a multi-step form can
     * check each step's fields before moving on; handle() still validates
     * everything, so a skipped step cannot slip through.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            // Doubles as the tenant id, so it also names the tenant database.
            'subdomain' => [
                'required',
                'string',
                'max:40',
                'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                // admin/tenants/create is a route, so a tenant with that id
                // could never reach its admin/tenants/{tenant} page.
                Rule::notIn(['create']),
                Rule::unique(Tenant::class, 'id'),
                $this->domainIsAvailable(...),
            ],
            'locality' => ['required', 'string', 'max:255'],
            'currency' => ['required', 'string', Rule::in(config('tenancy.currencies'))],
            'first_name' => $this->givenNameRules(),
            'last_name' => $this->givenNameRules(),
            'username' => $this->usernameRules(),
            'email' => $this->emailRules(),
            'password' => $this->passwordRules(),
        ];
    }

    /**
     * Get the custom validation messages.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'subdomain.regex' => __('The subdomain may only contain lowercase letters, numbers and single dashes.'),
            'subdomain.not_in' => __('That subdomain is reserved.'),
        ];
    }

    /**
     * Fail validation when the subdomain's full domain already belongs to a
     * tenant, which may be one whose id differs from the subdomain.
     */
    private function domainIsAvailable(string $attribute, mixed $value, Closure $fail): void
    {
        if (is_string($value) && Domain::where('domain', $this->domainFor($value))->exists()) {
            $fail(__('The :domain domain is already taken.', ['domain' => $this->domainFor($value)]));
        }
    }

    /**
     * Tenant domains are subdomains of the central host.
     */
    private function domainFor(string $subdomain): string
    {
        return $subdomain.'.'.parse_url((string) config('app.url'), PHP_URL_HOST);
    }
}
