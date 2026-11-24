<?php

use App\Enums\AuditAction;
use App\Enums\Role;
use App\Models\PlanChangeRequest;
use App\Models\RestoreRequest;
use App\Models\Subscription;
use App\Models\SubscriptionInvoice;
use App\Models\SubscriptionPayment;
use App\Models\SubscriptionPlan;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Audit\AuditRecorder;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/*
| Query budgets (TA-35): each heavy screen runs as many queries for a long
| list as for a short one. A screen that loads a relation once per row (an
| N+1) runs more queries the more rows it shows, and fails here, whether or
| not the lazy-loading guard (AppServiceProvider) catches that relation.
|
| Each screen is rendered once to warm up (what a request reads once and
| reuses, such as the plan-feature matrix, is not counted), then measured
| with a few rows, then again after more are added; both stay on one page
| of results.
*/

/**
 * Count the queries the callback runs, on every connection.
 */
function queriesRunBy(Closure $callback): int
{
    $count = 0;
    $counting = true;
    DB::listen(function () use (&$count, &$counting): void {
        if ($counting) {
            $count++;
        }
    });

    $callback();
    $counting = false;

    return $count;
}

/**
 * An agency on a plan, with an admin and an invoice it has partly paid.
 */
function agencyWithBilling(): Tenant
{
    $tenant = Tenant::factory()->create();
    User::factory()->role(Role::Admin)->for($tenant)->create();
    billSubscription(Subscription::factory()->for($tenant)->create());

    return $tenant;
}

/**
 * One invoice on the subscription, partly paid by one payment.
 */
function billSubscription(Subscription $subscription): void
{
    $invoice = SubscriptionInvoice::factory()->for($subscription)->partiallyPaid()->create([
        'period_start_on' => now()->subMonths(Subscription::query()->count() + SubscriptionInvoice::query()->count())->startOfMonth(),
    ]);
    SubscriptionPayment::factory()->for($invoice, 'invoice')->create();
}

test('a console screen runs as many queries for many rows as for a few', function (string $page, Closure $addRows) {
    $superAdmin = User::factory()->superAdmin()->create();
    $render = fn () => Livewire::actingAs($superAdmin)->test($page)->assertOk();

    $addRows(2);
    $render();
    $few = queriesRunBy($render);
    $addRows(6);
    $many = queriesRunBy($render);

    expect($many)->toBe($few);
})->with([
    'the dashboard' => ['pages::admin.dashboard', fn (int $rows) => collect()->times($rows, fn () => agencyWithBilling())],
    'the agency directory' => ['pages::admin.tenants.index', fn (int $rows) => collect()->times($rows, fn () => agencyWithBilling())],
    'the overdue invoices' => ['pages::admin.invoices.overdue', fn (int $rows) => SubscriptionInvoice::factory()->count($rows)->overdue()->create()],
    'the platform audit log' => ['pages::admin.audit.index', function (int $rows): void {
        $actor = User::factory()->superAdmin()->create();
        collect()->times($rows, fn () => app(AuditRecorder::class)->recordPlatformEvent(AuditAction::TenantCreated, $actor, Tenant::factory()->create()));
    }],
    'the restore requests' => ['pages::admin.restore-requests.index', fn (int $rows) => RestoreRequest::factory()->count($rows)->create()],
    'the plan requests' => ['pages::admin.plan-requests.index', fn (int $rows) => PlanChangeRequest::factory()->count($rows)->create()],
    'the price list' => ['pages::admin.plans.index', fn (int $rows) => collect()->times($rows, fn () => Subscription::factory()->create(['subscription_plan_id' => SubscriptionPlan::factory()->create()->id]))],
]);

test('an agency\'s page in the console runs as many queries for many rows as for a few', function () {
    $tenant = agencyWithBilling();
    $superAdmin = User::factory()->superAdmin()->create();
    $render = fn () => Livewire::actingAs($superAdmin)->test('pages::admin.tenants.show', ['tenant' => $tenant])->assertOk();
    $addRows = function (int $rows) use ($tenant): void {
        User::factory()->count($rows)->role(Role::Staff)->for($tenant)->create();
        collect()->times($rows, fn () => billSubscription(Subscription::factory()->for($tenant)->cancelled()->create()));
    };

    $addRows(2);
    $render();
    $few = queriesRunBy($render);
    $addRows(3);
    $many = queriesRunBy($render);

    expect($many)->toBe($few);
});

test('an agency screen runs as many queries for many rows as for a few', function (string $page, Closure $addRows) {
    $tenant = tenantOnDomain();
    $admin = User::factory()->role(Role::Admin)->for($tenant)->create();
    $subscription = Subscription::factory()->for($tenant)->create();
    tenancy()->initialize($tenant);
    $render = fn () => Livewire::actingAs($admin)->test($page)->assertOk();
    $agency = ['tenant' => $tenant, 'admin' => $admin, 'subscription' => $subscription];

    $addRows(2, $agency);
    $render();
    $few = queriesRunBy($render);
    $addRows(6, $agency);
    $many = queriesRunBy($render);

    expect($many)->toBe($few);
})->with([
    'its dashboard' => ['pages::tenant.dashboard', fn (int $rows, array $agency) => User::factory()->count($rows)->role(Role::Staff)->for($agency['tenant'])->create()],
    'its team' => ['pages::tenant.team', fn (int $rows, array $agency) => User::factory()->count($rows)->role(Role::Staff)->for($agency['tenant'])->create()],
    'its plan and billing' => ['pages::tenant.billing', fn (int $rows, array $agency) => collect()->times($rows, fn () => billSubscription($agency['subscription']))],
    'its audit log' => ['pages::tenant.audit', fn (int $rows, array $agency) => collect()->times($rows, fn () => app(AuditRecorder::class)->recordTenantEvent(AuditAction::SettingsUpdated, $agency['admin']))],
]);
