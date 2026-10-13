# Tenant Platform

**A multi-tenant travel & tour agency SaaS marketplace**, built with Laravel 13 and Livewire 4.

Tenant Platform takes **360 Degrees** — a single-company capstone project selling tours, visas and
hotel rooms — and reworks it into a platform where independent travel agencies each run their own
isolated database, while customers browse and book across all of them from one central
storefront.

The original system's business logic is ported deliberately. Its structural defects are fixed
deliberately, each with a named regression test. Both halves are the point of this project.

[![Tests](https://github.com/devv-is-coding/tenant-platform/actions/workflows/tests.yml/badge.svg)](https://github.com/devv-is-coding/tenant-platform/actions/workflows/tests.yml)
![PHP](https://img.shields.io/badge/PHP-8.4-777BB4?logo=php&logoColor=white)
![Laravel](https://img.shields.io/badge/Laravel-13-FF2D20?logo=laravel&logoColor=white)
![Livewire](https://img.shields.io/badge/Livewire-4-4E56A6?logo=livewire&logoColor=white)
![PHPStan](https://img.shields.io/badge/PHPStan-level%207-brightgreen)

---

## Why this project exists

Most portfolio CRUD apps demonstrate that you can build *a* feature. This one is built to
demonstrate something narrower and harder: that a working system can be taken apart, understood,
and rebuilt correctly — including naming what was wrong with the original and proving, with a
test, that it no longer is.

The legacy system is a real MariaDB schema from a completed capstone project. It is not a toy.
It has real defects, found by reading its schema and its data rather than assumed:

| # | Defect | What the data actually showed |
|---|---|---|
| 1 | No date-ranged availability | Booking a hotel room consumed its slot **permanently** — the dataset contains exactly one hotel booking, never approved |
| 2 | Money stored as `double(10,2)` | A ₱30.01 payment was audited as `30.009999999999998`; a customer-facing notification shipped the literal text `30.00999999999999801048033987` |
| 3 | No date validation | An itinerary row's date range ends *before* it starts; another has the end year `0026` |
| 4 | One option per booking | `UNIQUE(invoice_id, option_id)` made "two rooms" or "a tour plus a visa" literally unrepresentable |
| 5 | Two redundant audit tables | `logs` and `histories` differ only in payload; `logs.user_id = 0` for every failed login |
| 6 | Untyped EAV attributes | A hotel's check-in time was stored as the *string* `"2:00 PM"`; one attribute row holds malformed, invalid JSON |
| 7 | No concurrency control | Nothing stopped two customers both booking the last unit of inventory |
| 8 | Dangling foreign references | `option_batches.added_by = 1` points at a user id that does not exist |
| 9 | Refunds modelled as columns | Eight refund columns on one row permit exactly one refund per booking, ever |
| 10 | Timestamps stored as unix integers | `payments.paid_on` is an `int(11)`, not a real timestamp |
| 11 | Coordinates modelled, never used | `latitude`/`longitude` columns exist on every itinerary row; every single value is `NULL` |

Every one of these gets a schema-level fix and a regression test named after the defect, so the
test suite itself documents the improvement.

---

## Architecture

### Multi-database, multi-tenant

Each agency ("tenant") gets its own **physically separate MariaDB database**, provisioned
automatically when the tenant is created. This is not a shared-schema, `tenant_id`-on-every-row
design — it's full database isolation via [stancl/tenancy](https://tenancyforlaravel.com/), which
means:

- One agency's data cannot leak into another's queries by forgetting a `WHERE` clause.
- Tenant-local autoincrement IDs collide across agencies by design (booking `#1` exists in every
  tenant), which the isolation tests treat as the normal case, not an edge case.
- Deleting a tenant's database doesn't touch anyone else's.

**Central database** — platform-wide concerns: user accounts, tenant registry, domains,
subscriptions/billing, the cross-tenant discovery projection, wishlist, in-app notifications,
and platform-level audit log.

**Tenant databases** — everything one agency owns: catalog, inventory, bookings, payments,
refunds, reviews, check-ins, and that agency's own audit trail.

### The hard part: cross-tenant discovery without cross-tenant queries

Customers need to search and browse listings from *every* agency at once, but each agency's
catalog lives in its own database. Querying all tenant databases on every search request doesn't
scale. The solution is a **central read projection** (`listings`): a denormalized, searchable
copy of each published item, kept in sync by queued domain events and reconciled nightly.

The invariant this design depends on, and the one thing that must never be violated:

> The projection is for **discovery only**. It is never the source of truth for price or
> availability. The booking flow always re-reads both, authoritatively, from the tenant database.
> Staleness can only ever produce "search said ₱499, the detail page says ₱549" — never a
> financial error.

### Two orthogonal booking axes

A booking tracks **approval** (did a manager approve it?) and **payment** (has it been paid?) as
two independent values — exactly as the legacy system did, which turned out to be a deliberate
and correct design, not a bug. A rejected booking keeps its payment status unchanged, because
rejecting a booking and refunding it are different events that can happen in either order or not
at all.

### Concurrency without relying on row locks

The test suite runs on SQLite; production runs on MariaDB. `lockForUpdate()` silently compiles to
a no-op on SQLite, which means **a test asserting a row lock works would pass against broken
code**. So the actual concurrency guarantee is a database-level `UNIQUE` constraint on inventory
allocations (`option_id`, `occupied_on`, `unit_index`) — a guarantee that holds identically on
both drivers, with the row lock kept only as a UX optimization that turns a losing race into a
clean "just sold out" instead of a 500 error.

### Money as integers, always

Every monetary column is an integer count of minor units (`price_minor`, `amount_minor`), never
`DECIMAL` and never `float`/`double`. This is enforced by an **architecture test** that scans
every table on both the central and tenant connections and fails the build if a `double`/`float`
column exists anywhere, or if a column matching `/amount|price|total|fee|discount/` doesn't end in
`_minor`.

---

## Tech stack

| Layer | Choice |
|---|---|
| Language / Framework | PHP 8.4, Laravel 13 |
| Frontend | Livewire 4 (single-file components), Flux UI 2, Tailwind CSS 4 |
| Multi-tenancy | [stancl/tenancy](https://tenancyforlaravel.com/) 3.10, database-per-tenant |
| Auth | Laravel Fortify (2FA, passkeys), Laravel Sanctum |
| Database | MariaDB 11.4 (all environments), SQLite in-memory (test suite only) |
| Testing | Pest 5, PHPUnit, a dedicated architecture-test suite |
| Static analysis | Larastan / PHPStan — **level 7** |
| Code style | Laravel Pint |
| Containerization | Docker Compose (app + MariaDB), Laravel Sail-compatible |
| CI | GitHub Actions — lint, static analysis, full test suite on every push and PR |

---

## Getting started

### Requirements

- Docker and Docker Compose
- *(or, for a bare-metal setup)* PHP 8.4, Composer, Node 22, a MariaDB 11+ instance

### With Docker (recommended)

```bash
git clone https://github.com/devv-is-coding/tenant-platform.git
cd tenant-platform
cp .env.example .env

docker compose up -d
docker compose exec app composer install
docker compose exec app php artisan key:generate
docker compose exec app php artisan migrate --seed
docker compose exec app npm install
docker compose exec app npm run build
```

The app is served at `http://localhost:8000`.

### Without Docker

```bash
composer setup   # copies .env, generates a key, migrates, installs and builds frontend assets
```

### Running the test suite

```bash
composer test          # config:clear, Pint (check mode), PHPStan, full Pest suite
php artisan test        # suite only
vendor/bin/phpstan analyse --memory-limit=2G
vendor/bin/pint
```

CI runs the same checks (`composer ci:check`) on every push and pull request against `main`.

---

## Project status

This is an active, from-scratch rebuild, developed in phases against a detailed internal
roadmap. **The database schema is complete**: 24 tables across the central and tenant
connections, 19 domain enums, full multi-tenant provisioning verified end-to-end with
`migrate:fresh --seed`, and an architecture test guarding the money-as-integers invariant
permanently.

Application logic (models, services, policies, UI) is being layered on top of this schema
phase by phase — catalog management, inventory and date-ranged availability, the booking and
payment lifecycle, refunds, reviews, QR check-in, Stripe integration, and platform-level tenant
and subscription governance.

**Design priority, in order:** correctness, security, maintainability, architecture, automated
testing, UX, performance, documentation, deployment readiness. Speed of delivery is explicitly
last — this project is optimized for depth, not for feature count.

---

## License

This is a personal portfolio project. Source is available for review; no license is granted for
reuse.
