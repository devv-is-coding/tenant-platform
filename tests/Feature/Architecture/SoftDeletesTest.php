<?php

use App\Models\InventoryAllocation;
use App\Models\Passkey;
use App\Models\PersonalAccessToken;
use Illuminate\Database\Eloquent\SoftDeletes;

/*
 * CLAUDE.md: soft deletes are required on every model. Exempt are two thin
 * subclasses of package plumbing whose tables we do not own, and
 * InventoryAllocation, which release must hard-delete: a soft-deleted
 * allocation keeps its unique slot and blocks resale. SchemaTest checks the
 * matching deleted_at column on every table.
 */
arch('every model can be soft-deleted')
    ->expect('App\Models')
    ->classes()
    ->toUseTrait(SoftDeletes::class)
    ->ignoring([Passkey::class, PersonalAccessToken::class, InventoryAllocation::class]);
