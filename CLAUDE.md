<laravel-boost-guidelines>
=== foundation rules ===

# Laravel Boost Guidelines

## Foundational Context

This application is a Laravel application running on PHP 8.4. Always use the APIs that match the installed major version of each package — do not assume a version.

Before relying on a package's API, confirm its installed version:

- PHP packages: run `composer show --direct` to list direct dependencies with versions, or `composer show <vendor/package>` for a single package.
- JS packages: check `package.json` for the installed versions.

## Skills Activation

This project has domain-specific skills available in `**/skills/**`. You MUST activate the relevant skill whenever you work in that domain—don't wait until you're stuck.

## Conventions

- You must follow all existing code conventions used in this application. When creating or editing a file, check sibling files for the correct structure, approach, and naming.
- Use descriptive names for variables and methods. For example, `isRegisteredForDiscounts`, not `discount()`.
- Check for existing components to reuse before writing a new one.

## Verification Scripts

- Do not create verification scripts or tinker when tests cover that functionality and prove they work. Unit and feature tests are more important.

## Application Structure & Architecture

- Stick to existing directory structure; don't create new base folders without approval.
- Do not change the application's dependencies without approval.

## Frontend Bundling

- If a frontend change doesn't show in the UI or you get a "Unable to locate file in Vite manifest" error, run `npm run build` or ask the user to run `npm run dev` or `composer run dev`.

## Documentation Files

- You must only create documentation files if explicitly requested by the user.

=== boost rules ===

# Laravel Boost

## Tools

- Laravel Boost is an MCP server with tools designed specifically for this application. Prefer Boost tools over manual alternatives like shell commands or file reads.
- Use `database-query` to run read-only queries against the database instead of writing raw SQL in tinker.
- Use `database-schema` to inspect table structure before writing migrations or models.
- Use `get-absolute-url` to resolve the correct scheme, domain, and port for project URLs. Always use this before sharing a URL with the user.
- Use `browser-logs` to read browser logs, errors, and exceptions. Only recent logs are useful, ignore old entries.

## Searching Documentation (IMPORTANT)

- Use `search-docs` before changes that depend on Laravel ecosystem APIs, behavior, configuration, or version-specific syntax. Skip it for copy-only edits and other changes where package documentation is irrelevant. Reuse sufficient results already in context instead of searching again.
- Pass a `packages` array to scope results when you know which packages are relevant.
- Use multiple broad, topic-based queries: `['rate limiting', 'routing rate limiting', 'routing']`. Expect the most relevant results first.
- Do not add package names to queries because package info is already shared. Use `test resource table`, not `filament 4 test resource table`.

### Search Syntax

1. Use words for auto-stemmed AND logic: `rate limit` matches both "rate" AND "limit".
2. Use `"quoted phrases"` for exact position matching: `"infinite scroll"` requires adjacent words in order.
3. Combine words and phrases for mixed queries: `middleware "rate limit"`.
4. Use multiple queries for OR logic: `queries=["authentication", "middleware"]`.

## Project Rules

- This project contains committed, area-grouped rules in `.ai/rules` when that directory exists, including path-scoped framework guidelines under `.ai/rules/boost`. Before you enter plan mode or create/edit any file, you MUST first: open @.ai/rules/index.md (it maps file globs to rule files), read every rule file whose globs cover the path(s) in scope, and run `grep -rin 'keyword' .ai/rules` to catch what a path match alone misses. Do not write code until you have read and are following every matching rule. If `.ai/rules` does not exist, continue without it.

## Artisan

- Run Artisan commands directly via the command line (e.g., `php artisan route:list`). Use `php artisan list` to discover available commands and `php artisan [command] --help` to check parameters.
- Inspect routes with `php artisan route:list`. Filter with: `--method=GET`, `--name=users`, `--path=api`, `--except-vendor`, `--only-vendor`.
- Read configuration values using dot notation: `php artisan config:show app.name`, `php artisan config:show database.default`. Or read config files directly from the `config/` directory.

## Tinker

- Execute PHP in app context for debugging and testing code. Do not create models without user approval, prefer tests with factories instead. Prefer existing Artisan commands over custom tinker code.
- Always use single quotes to prevent shell expansion: `php artisan tinker --execute 'Your::code();'`
    - Double quotes for PHP strings inside: `php artisan tinker --execute 'User::where("active", true)->count();'`

=== php rules ===

# PHP

- Always use curly braces for control structures, even for single-line bodies.
- Use PHP 8 constructor property promotion: `public function __construct(public GitHub $github) { }`. Do not leave empty zero-parameter `__construct()` methods unless the constructor is private.
- Use explicit return type declarations and type hints for all method parameters: `function isAccessible(User $user, ?string $path = null): bool`
- Use TitleCase for Enum keys: `FavoritePerson`, `BestLake`, `Monthly`.
- Prefer PHPDoc blocks over inline comments. Only add inline comments for exceptionally complex logic.
- Use array shape type definitions in PHPDoc blocks.

=== deployments rules ===

# Deployment

- Laravel can be deployed using [Laravel Cloud](https://cloud.laravel.com/), which is the fastest way to deploy and scale production Laravel applications.

=== tests rules ===

# Test Enforcement

- Add or update tests for behavior and logic changes when a test provides meaningful regression coverage.
- Pure copy, styling, and layout-only changes do not require new or updated tests.
- When test coverage applies, run the affected tests and ensure they pass.
- Test the changed behavior and its important failure modes, but do not add tests beyond them.
- Read the `testing-best-practices` skill before writing tests.

=== laravel/core rules ===

# Do Things the Laravel Way

- Use `php artisan make:` commands to create new files (i.e. migrations, controllers, models, etc.). You can list available Artisan commands using `php artisan list` and check their parameters with `php artisan [command] --help`.
- If you're creating a generic PHP class, use `php artisan make:class`.
- Pass `--no-interaction` to all Artisan commands to ensure they work without user input. You should also pass the correct `--options` to ensure correct behavior.

### Model Creation

- When creating new models, create useful factories and seeders for them too. Ask the user if they need any other things, using `php artisan make:model --help` to check the available options.

## APIs & Eloquent Resources

- For APIs, default to using Eloquent API Resources and API versioning unless existing API routes do not, then you should follow existing application convention.

## URL Generation

- When generating links to other pages, prefer named routes and the `route()` function.

## Testing

- When creating models for tests, use the factories for the models. Check if the factory has custom states that can be used before manually setting up the model.
- Faker: Use methods such as `$this->faker->word()` or `fake()->randomDigit()`. Follow existing conventions whether to use `$this->faker` or `fake()`.
- When creating tests, make use of `php artisan make:test [options] {name}` to create a feature test, and pass `--unit` to create a unit test. Most tests should be feature tests.

=== livewire/core rules ===

# Livewire

- Livewire allows you to build dynamic, reactive interfaces in PHP without writing JavaScript.
- You can use Alpine.js for client-side interactions instead of JavaScript frameworks.
- Keep state server-side so the UI reflects it. Validate and authorize in actions as you would in HTTP requests.

=== pint/core rules ===

# Laravel Pint Code Formatter

- If you have modified any PHP files, you must run `vendor/bin/pint --dirty --format agent` before finalizing changes to ensure your code matches the project's expected style.
- Do not run `vendor/bin/pint --test --format agent`, simply run `vendor/bin/pint --format agent` to fix any formatting issues.

=== pest/core rules ===

# Pest

- This project uses Pest. Create tests with `php artisan make:test --pest {name}`.
- Do not include the test suite directory in `{name}`. Use `SomeFeatureTest`, not `Feature/SomeFeatureTest`.
- Read the `testing-best-practices` skill for guidance on coverage, naming, structure, dependency isolation, and review.
- Do not delete tests or test files without approval. They are part of the application.

## Running Tests

- Run the narrowest set of tests that covers the change. Pass a file path or `--filter=testName` to `php artisan test --compact`.
- Rerun a test after each change to it.
- Run `vendor/bin/pest` to call the test runner directly. It accepts the same file path and `--filter=testName` arguments.
- After the feature tests pass, ask the user to run the complete suite with `php artisan test --compact`.

</laravel-boost-guidelines>

## Git Commit Message Convention

Use Conventional Commits:

`<type>(<scope>): <short description>`

### Types

- `feat:` — New feature or functionality
- `fix:` — Bug fix
- `refactor:` — Code restructuring without changing behavior
- `style:` — Formatting, styling, or UI-only changes
- `docs:` — Documentation changes
- `test:` — Adding or modifying tests
- `perf:` — Performance improvements
- `build:` — Build system, dependencies, Docker, or build configuration
- `ci:` — CI/CD changes
- `chore:` — General maintenance
- `revert:` — Reverting a previous commit

### Examples

- `feat(auth): add Google authentication`
- `fix(booking): resolve payment status issue`
- `refactor(api): simplify response handling`
- `style(button): improve hover animation`
- `docs(api): update API documentation`
- `test(auth): add login endpoint tests`
- `chore(deps): update dependencies`

### Rules

1. Use lowercase for the commit type, scope, and description.
2. Keep commit messages concise and preferably under 72 characters.
3. Use imperative wording such as `add`, `fix`, `update`, `remove`, or `refactor`.
4. Choose the commit type based on the actual changes made.
5. Do not use vague messages such as `update`, `changes`, `fixed stuff`, or `misc`.
6. Use a scope when it makes the change clearer, but do not force a scope when it is unnecessary.
7. Use `!` for breaking changes, for example `feat(api)!: change response format`.
8. When creating commits, inspect the actual changes first and select the appropriate commit type.
9. Do not create commits unless the user explicitly asks you to commit or the current task explicitly requires a commit.
10. Follow the project's existing Git workflow and branching rules.

## Git Branch Naming Conventions

Use this format:

`<type>/<short-description>`

When the work tracks a GitHub Issue, include its number:

`<type>/<issue-number>-<short-description>`

### Branch Types

| Prefix | Purpose | Example |
|---|---|---|
| `main` | Stable, production-ready code | `main` |
| `develop` | Integration branch, only when the project uses this workflow | `develop` |
| `feature/` | New functionality | `feature/tenant-management` |
| `fix/` | Regular bug fixes | `fix/login-validation` |
| `hotfix/` | Urgent production fixes | `hotfix/payment-failure` |
| `refactor/` | Code restructuring without intended behavior changes | `refactor/auth-service` |
| `docs/` | Documentation changes | `docs/api-setup-guide` |
| `test/` | Test-related changes | `test/booking-service` |
| `chore/` | Maintenance, dependencies, and tooling | `chore/update-dependencies` |
| `style/` | Formatting or non-functional code style changes | `style/format-python-code` |
| `perf/` | Performance improvements | `perf/optimize-booking-query` |

### Naming Rules

1. Use lowercase letters only.
2. Separate words with hyphens (`-`), and put a forward slash (`/`) between the type and the description.
3. Keep names concise, descriptive, and specific to the task, for example `feature/42-tenant-directory`.
4. Avoid spaces, vague names, unnecessary dates, and inconsistent naming patterns.

### Branch Workflow

1. Do not create a branch for every small action. Create one when the task, its risk, or the existing project workflow warrants it.
2. Before creating a branch, inspect the current branch, the working tree, and the repository's instructions.
3. Never switch branches if doing so could overwrite, discard, or interfere with uncommitted user changes.
4. Do not create, rename, delete, or switch branches without considering the user's current workflow and the repository's existing conventions.
5. Follow the project's branching strategy when one is documented. Do not introduce `develop` or another permanent branch unnecessarily.
6. Prefer feature branches and pull requests for changes that need isolation or review.
7. Do not commit directly to `main` unless the user or the established project workflow explicitly permits it.
8. Do not rename or delete existing branches just to enforce this convention.
9. Never discard user changes or run destructive Git operations without explicit authorization.

### Branch Names vs. Commit Messages

Branch naming and commit messages are separate conventions; commits still follow the Git Commit Message Convention above. The prefixes differ, for example:

- Branch `feature/tenant-management`, commit `feat: implement tenant management`
- Branch `fix/booking-validation`, commit `fix: validate booking dates`

## Before Starting Work

- Before writing code, confirm the setup is actually working: the Docker stack is up and healthy (`docker compose ps`), migrations are current (`php artisan migrate:status`), and there's no unexplained failure already present (e.g. a broken container, a stale schema, a red test). Fix or flag that first rather than building on top of it.

## Before Pushing

- Before any `git push`, run `composer ci:check` — the same command CI runs (Pint style check, PHPStan, and the full Pest suite).
- Push only if it passes. If it fails, fix the cause and re-run; do not push a failing branch.
- Never bypass hooks or checks (`--no-verify`, skipping tests) to get a push through.

## Migrations

- Do not create a new migration to add or change columns on an existing table. Edit the project's existing migration for that table instead (e.g. `users` columns go in `2026_08_07_101622_user_added_attributes.php`, `tenants` columns go in `2019_09_15_000010_create_tenants_table.php`).
- Never edit Laravel's default migrations (`0001_01_01_*`). Change their tables through the project's own migration instead.
- Editing an existing migration requires a fresh migration (`migrate:fresh --seed`) to apply; tell the user.
- `migrate:fresh` drops only the central database, not the `tenant*` databases. Drop those first, or reseeding fails with `TenantDatabaseAlreadyExistsException`.
- A new real column on `tenants` must also be added to `Tenant::getCustomColumns()`. Otherwise stancl silently stores it in the `data` JSON column.

## Docker (Local Development)

- The source is not bind-mounted: a bind mount from the Windows drive made every request take ~3s. Start the stack with `docker compose up --watch`, which syncs host edits into the container.
- Sync is one-way, host to container. Run `php artisan make:*` and other file generators on the host, never through `docker compose exec`.
- `.env` is read only when the container is created. After changing it, run `docker compose up -d --force-recreate app`.
- Recreating or restarting the `app` container resets its source to the image's copy and detaches the running watch, so every uncommitted edit since the last image build disappears *inside the container* — routes 404, new pages vanish. Nothing is lost on the host. Always follow a recreate with `docker compose up --watch` (its `initial_sync` re-copies the host source), then confirm the work is back before reporting anything as broken.
- Changes to `composer.json` or `composer.lock` rebuild the image automatically while watch is running.
- Before ending any task that changed files, update the running container. Make sure `docker compose up --watch` is running (start it in the background if it is not), then confirm the container has the work: a changed file's content matches inside `docker compose exec -T app`, and any new route appears in `php artisan route:list` there. If the change touched Blade, CSS or JS, also run `npm run build` on the host: `public/` is bind-mounted, and Tailwind emits only the classes it found at build time, so a new class silently does nothing until then. Tests pass on the host without the container, so they do not prove the app the user sees is current.

## Timestamp Columns

- Use `_on`, not `_at`, for timestamp columns: `created_on`, `updated_on`, `deleted_on`.
- This applies to Eloquent's timestamps and to domain timestamps (`approved_on`, `paid_on`, `requested_on`).
- Models declare `CREATED_AT`, `UPDATED_AT` and `DELETED_AT` accordingly — see `app/Models/User.php` for the pattern.
- Declare soft deletes as `$table->softDeletes('deleted_on')`.

## Onboarding Forms

- Build onboarding flows as a step-by-step wizard modeled on `resources/views/pages/admin/tenants/⚡create.blade.php`; copy its structure rather than starting from a plain form.
- One card, one question per step: an icon, the question as the heading, a one-line subheading, then only that step's fields. A footer bar holds `Go back` (hidden on step 1) and `Continue`, which reads as the final action on the last step.
- Responsive shape: a portrait single-column card on mobile; from `lg` a landscape card centred in the viewport, with the icon, question and a numbered step list in a tinted left panel and the fields on the right.
- `Continue` validates only the current step's fields, from the same rules the final action uses (expose them as a public `rules()` on the action). The final submit revalidates everything and, on failure, jumps back to the step holding the first error.

## IDE Diagnostics

- Before ending a task, always check the IDE diagnostics (`getDiagnostics`) for every file you created or modified, and resolve every error and warning you introduced.
- Check every language the change touched, not only PHP/Intelephense: Blade, CSS, JS, JSON and config files all report separately.
- Also re-check files you did not edit whose diagnostics the change could affect.
- When a warning is a false positive from tooling that does not understand valid syntax (for example VS Code's CSS service not knowing Tailwind v4's `@theme`, `@source` or `@apply`), silence it at its source — e.g. `.vscode/css-custom-data.json` — rather than leaving it or rewriting correct code to satisfy it.

## CLAUDE.md Modification Policy

**Do not modify `CLAUDE.md` casually.**

Only modify `CLAUDE.md` when:

- The user explicitly asks you to modify or update it, or
- A change is genuinely necessary to keep its instructions accurate and consistent with the project.

Do not add new rules, notes, preferences, documentation, or instructions to `CLAUDE.md` simply because they may be useful for the current task.

If a task can be completed without modifying `CLAUDE.md`, **do not modify it**.

When a modification is necessary, make the **smallest possible change** and preserve all existing instructions, structure, priorities, and wording. Do not rewrite or reorganize unrelated sections.
