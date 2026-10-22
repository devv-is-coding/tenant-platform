<?php

use App\Models\Passkey;
use App\Models\PersonalAccessToken;
use Illuminate\Database\Eloquent\SoftDeletes;

/*
 * CLAUDE.md: soft deletes are required on every model. The two exempt
 * models are thin subclasses of package
 * plumbing whose tables we do not own. SchemaTest checks the matching
 * deleted_at column on every table.
 */
arch('every model can be soft-deleted')
    ->expect('App\Models')
    ->classes()
    ->toUseTrait(SoftDeletes::class)
    ->ignoring([Passkey::class, PersonalAccessToken::class]);
