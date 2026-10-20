# Tenant Platform

**A multi-tenant travel & tour agency SaaS**, built with Laravel 13 and Livewire 4.

Tenant Platform takes **360 Degrees** — a single-company capstone project selling tours, visas and
hotel rooms — and reworks it into a platform where independent travel agencies each run their own
isolated database, and each agency's customers book through **that agency's own branded Flutter
app**, backed by a REST API served from the agency's domain.

- **Laravel web (Livewire):** the platform console for the super admin, and each agency's console
  for its admins, managers and staff.
- **Flutter app per agency:** customers only, over `/api/v1`. *(The customer API is designed and
  on the roadmap; the Flutter apps live in a separate repository.)*

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
subscriptions/billing, customer transfers between agencies, API tokens, and the platform-level
audit log.

**Tenant databases** — everything one agency owns: catalog, inventory, bookings, payments,
refunds, reviews, wishlists, in-app notifications, check-ins, app branding, and that agency's own
audit trail.

### One agency, one app, one API

Each agency ships its own Flutter app, configured with nothing but its agency's URL. Everything
else — name, logo, colours, contact details, currency — comes from a public
`GET /api/v1/app-config`, so a single Flutter codebase reskins itself per agency.

The API is served from **the agency's own domain**, where tenancy is resolved exactly as it is for
the agency's staff. An app is therefore physically unable to address another agency's data, and
every catalog, price and availability read comes straight from the agency's own database — there
is no cross-agency copy to go stale.

Customers authenticate with **Sanctum bearer tokens**, each bound to the agency that issued it.

### Customers belong to one agency at a time

A customer has one platform-wide account (one email, one password) but belongs to **one agency
at a time** — the one whose app they signed up in. To use another agency's app they request a
**transfer**, and the *old* agency must release them, which is refused while any trip, balance or
refund is still open there. Their profile moves; their history stays in the old agency's database,
untouched, and reappears if they ever move back.

A cross-agency marketplace was designed (a central read projection kept in sync by domain events)
and deliberately dropped: each agency owns its customers and its app, so there was nothing to
aggregate.

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
clean "just sold out" (`409`) instead of a 500 error.

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
| Operator web | Livewire 4 (single-file components), Flux UI 2, Tailwind CSS 4 |
| Customer API | Versioned REST (`/api/v1`) on each agency's domain, Eloquent API Resources, for per-agency Flutter apps |
| Multi-tenancy | [stancl/tenancy](https://tenancyforlaravel.com/) 3.10, database-per-tenant |
| Auth | Laravel Fortify (web: 2FA, passkeys), Laravel Sanctum (API bearer tokens) |
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

# Prints an app key: paste it into APP_KEY in .env. The container reads .env at startup.
docker compose run --rm --no-deps --entrypoint php app artisan key:generate --show

npm install && npm run build      # assets are built on the host (or `npm run dev` for HMR)
docker compose up --build --watch
```

In a second terminal:

```bash
docker compose exec app php artisan migrate --seed
```

The app is served at `http://localhost:8000`.

The source is not bind-mounted. On Windows a bind mount of the repo runs about 15× slower per
file, which made every page take around 3 seconds. Instead, `--watch` syncs your edits into the
container within a second or two. Sync is one-way, so run `php artisan make:*` on the host.

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
phase by phase — platform-level tenant and subscription governance, the per-agency customer API
and customer transfers, catalog management, inventory and date-ranged availability, the booking
and payment lifecycle, refunds, reviews, QR check-in, and Stripe integration. The move to
per-agency customer apps reshapes part of the schema (the central `listings` projection goes;
wishlists and notifications move into tenant databases) in an upcoming rebuild.

**Design priority, in order:** correctness, security, maintainability, architecture, automated
testing, UX, performance, documentation, deployment readiness. Speed of delivery is explicitly
last — this project is optimized for depth, not for feature count.

---

## License

This is a personal portfolio project. Source is available for review; no license is granted for
reuse.
