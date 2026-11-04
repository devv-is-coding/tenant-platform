<?php

namespace App\Services\Session;

use Illuminate\Contracts\Container\Container;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use Illuminate\Session\DatabaseSessionHandler;

/**
 * The database session driver, choosing its connection at each read and
 * write instead of once when it is built.
 *
 * Laravel's handler keeps the connection that was the default when it was
 * created, and it can be created before tenancy runs: building a controller
 * whose constructor takes the auth guard (Fortify's login controller does)
 * creates the session there, while the router gathers middleware. An agency's
 * sign-in would then be stored in the central database while every other
 * agency page looks for it in the agency's own, and the user would appear
 * signed out right after signing in.
 *
 * Resolved at use, the connection is whichever is the default by then: the
 * agency's own database on its domain (tenancy runs before StartSession), the
 * central one on the platform's. `session.connection`, when set, still wins.
 */
class DefaultConnectionSessionHandler extends DatabaseSessionHandler
{
    public function __construct(
        private DatabaseManager $databases,
        private ?string $connectionName,
        string $table,
        int $minutes,
        ?Container $container = null,
    ) {
        parent::__construct($databases->connection($connectionName), $table, $minutes, $container);
    }

    /**
     * Get a fresh query builder on the connection that is current now.
     */
    protected function getQuery(): Builder
    {
        return $this->databases->connection($this->connectionName)->table($this->table)->useWritePdo();
    }
}
