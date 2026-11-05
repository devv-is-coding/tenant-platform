---
paths:
  - 'database/migrations/**'
  - 'app/Models/**'
---

# Migrations

## Edit the table's existing migration, never add one
- Do not create a new migration to add or change columns on an existing table. Edit the project's existing migration for that table instead (e.g. `users` columns go in `2026_08_07_101622_user_added_attributes.php`, `tenants` columns go in `2019_09_15_000010_create_tenants_table.php`).
- Never edit Laravel's default migrations (`0001_01_01_*`). Change their tables through the project's own migration instead.
- Editing an existing migration requires a fresh migration (`migrate:fresh --seed`) to apply; tell the user.
- `migrate:fresh` drops only the central database, not the `tenant*` databases. Drop those first, or reseeding fails with `TenantDatabaseAlreadyExistsException`.
- A new real column on `tenants` must also be added to `Tenant::getCustomColumns()`. Otherwise stancl silently stores it in the `data` JSON column.

## Every table gets soft deletes
- Soft deletes are required: every table the project creates gets `$table->softDeletes()`, even when nothing deletes from it yet, and its model uses `SoftDeletes`. Deleting is always a soft delete; `forceDelete()` is only for tests and deliberate data repair.
- Exempt: framework and package plumbing (`sessions`, `cache`, `jobs`, `password_reset_tokens`, `passkeys`, `personal_access_tokens`), and `inventory_allocations`, which must be hard-deleted on release — a soft-deleted allocation still holds its unique slot and would block resale (see that migration).
- A soft-deleted row still occupies its unique keys, so an existence check or a "count + 1" reference number guarding a unique column must use `withTrashed()`.

## Name timestamp columns `_at`, plain dates `_on`
- Use `_at` for date-and-time columns (`paid_at`, `approved_at`) and `_on` only where it fits the grammar: plain `date` columns (`starts_on`, `due_on`).
