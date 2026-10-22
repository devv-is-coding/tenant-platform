<?php

namespace App\Models;

use Illuminate\Database\Eloquent\SoftDeletes;
use Stancl\Tenancy\Database\Models\Domain as BaseDomain;

/**
 * Exists to rename the timestamp columns and make domains soft-deletable;
 * registered as tenancy.domain_model. A soft-deleted domain no longer
 * identifies its agency, so the agency's address stops resolving.
 */
class Domain extends BaseDomain
{
    use SoftDeletes;
}
