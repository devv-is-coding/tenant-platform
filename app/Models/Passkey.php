<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Laravel\Passkeys\Passkey as BasePasskey;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/**
 * Passkeys belong to central users, so they must resolve against the central
 * connection even while tenancy has swapped the default connection to a tenant.
 *
 * Registered via Passkeys::usePasskeyModel() in AppServiceProvider.
 *
 * @property CarbonInterface|null $last_used_at
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
class Passkey extends BasePasskey
{
    use CentralConnection;
}
