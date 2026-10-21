<?php

use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

afterEach(function () {
    // End tenancy first so the tenant connection is purged; Windows cannot delete
    // an SQLite database file that is still open.
    tenancy()->end();

    // Drops the tenant databases created during the test.
    Tenant::withTrashed()->get()->each->forceDelete();
});

/**
 * Framework-owned plumbing, excluded because we do not control its column names
 * and it contains no money. `job_batches.total_jobs` would otherwise trip the
 * money-suffix rule below on the word "total".
 *
 * @return list<string>
 */
function frameworkTables(): array
{
    return [
        'migrations',
        'cache',
        'cache_locks',
        'jobs',
        'job_batches',
        'failed_jobs',
        'sessions',
        'password_reset_tokens',
    ];
}

/**
 * Collect every application-owned column on both the central and the tenant
 * connection, so these rules cannot be sidestepped by putting a bad column in
 * the half of the schema nobody checked.
 *
 * @return list<array{connection: string, table: string, column: string, type: string}>
 */
function everyApplicationColumn(): array
{
    $central = config('tenancy.database.central_connection');

    tenancy()->initialize(Tenant::create(['id' => 'schema-probe']));

    $columns = [];

    foreach ([$central, 'tenant'] as $connection) {
        // Scope to the connection's own schema. On MariaDB an unqualified
        // getTables() returns every database on the server, so without this the
        // central pass would also walk all three tenant databases.
        $schema = Schema::connection($connection)->getCurrentSchemaName();

        foreach (Schema::connection($connection)->getTables($schema) as $table) {
            $name = $table['name'];

            if (in_array($name, frameworkTables(), true) || str_starts_with($name, 'sqlite_')) {
                continue;
            }

            foreach (Schema::connection($connection)->getColumns($table['schema_qualified_name']) as $column) {
                $columns[] = [
                    'connection' => $connection,
                    'table' => $name,
                    'column' => $column['name'],
                    'type' => $column['type_name'],
                ];
            }
        }
    }

    return $columns;
}

test('no column is a float or a double', function () {
    // Defect 2: the legacy stored money as double(10,2). A payment of 30.01 was
    // audited as 30.009999999999998, and a customer notification shipped the
    // literal text 30.00999999999999801048033987. Banning the types outright is
    // what makes that class of corruption structurally unable to return.
    //
    // MariaDB reports a `float` column as `double`, so both names are needed to
    // cover SQLite, which reports them separately.
    $offenders = collect(everyApplicationColumn())
        ->filter(fn (array $column): bool => in_array($column['type'], ['float', 'double', 'real'], true))
        ->map(fn (array $column): string => "{$column['connection']}.{$column['table']}.{$column['column']} ({$column['type']})")
        ->values()
        ->all();

    expect($offenders)->toBe([]);
});

test('every money column is an integer with a _minor suffix', function () {
    // Money is stored in integer minor units. DECIMAL would fix the column but
    // not the language: PHP has no decimal type, Laravel's 'decimal:2' cast
    // returns a string, and the first arithmetic operation coerces it to float -
    // which is the exact layer where the legacy corruption happened.
    $integerTypes = ['integer', 'int', 'bigint', 'mediumint', 'smallint', 'tinyint'];

    $offenders = collect(everyApplicationColumn())
        ->filter(fn (array $column): bool => preg_match('/amount|price|total|subtotal|fee|discount/i', $column['column']) === 1)
        ->reject(fn (array $column): bool => str_ends_with($column['column'], '_minor')
            && in_array($column['type'], $integerTypes, true))
        ->map(fn (array $column): string => "{$column['connection']}.{$column['table']}.{$column['column']} ({$column['type']})")
        ->values()
        ->all();

    expect($offenders)->toBe([]);
});

test('every application table can be soft-deleted, as a deleted_on column', function () {
    // CLAUDE.md: soft deletes are required like created_on and updated_on, even
    // on a table nothing deletes from yet. Exempt are package plumbing, whose
    // columns we do not own, and inventory_allocations, which release must
    // hard-delete: a soft-deleted allocation keeps its unique slot and blocks
    // resale.
    $exempt = ['passkeys', 'personal_access_tokens', 'inventory_allocations'];

    $missing = collect(everyApplicationColumn())
        ->reject(fn (array $column): bool => in_array($column['table'], $exempt, true))
        ->groupBy(fn (array $column): string => "{$column['connection']}.{$column['table']}")
        ->reject(fn ($columns): bool => $columns->contains('column', 'deleted_on'))
        ->keys()
        ->all();

    expect($missing)->toBe([]);
});
