<?php

namespace App\Concerns;

use App\Models\Tenant;
use Closure;

/**
 * Runs code inside an agency's own database from somewhere tenancy is not
 * initialized, such as a seeder or a console command.
 *
 * Unlike stancl's `$tenant->run()`, the context that was active before (another
 * agency, or none) comes back even when the callback throws, so a failure in
 * one agency can never leave later code writing to the wrong database.
 */
trait RunsInTenant
{
    /**
     * Run the callback inside each agency in turn. Deleted agencies are skipped.
     *
     * @param  Closure(Tenant): mixed  $callback
     */
    protected function runInEachTenant(Closure $callback): void
    {
        // Iterated on the builder: the stancl base model's TenantCollection is
        // not generic, so a fetched collection would lose the Tenant type.
        Tenant::query()->each(function (Tenant $tenant) use ($callback): void {
            $this->runInTenant($tenant, $callback);
        });
    }

    /**
     * Run the callback inside the agency, then restore the previous context.
     *
     * @template TReturn
     *
     * @param  Closure(Tenant): TReturn  $callback
     * @return TReturn
     */
    protected function runInTenant(Tenant $tenant, Closure $callback): mixed
    {
        $previousTenant = tenant();

        tenancy()->initialize($tenant);

        try {
            return $callback($tenant);
        } finally {
            if ($previousTenant instanceof Tenant) {
                tenancy()->initialize($previousTenant);
            } else {
                tenancy()->end();
            }
        }
    }
}
