# Task B1 Report — Tenant notifications table

## Status

Complete. The tenant migration creates Laravel's database-notification schema with UUID notifiables and the additional unread-count polling index required by the binding plan.

## Requirements reviewed

- Plan global constraints and Task B1.
- Binding design specification §6.1 and §14.
- Existing tenant migration conventions.

No requirement ambiguity or spec/plan conflict was found.

## Files

- Created `apps/api/database/migrations/tenant/2026_07_12_110000_create_notifications_table.php`.
- Updated `docs/handoff/treasury-phase3-progress.md`.
- Updated `.superpowers/sdd/progress.md`.
- Created `.superpowers/sdd/task-B1-report.md`.

No module, endpoint, treasury port, fiscal-perimeter, frontend, or interlocked file was touched.

## TDD task-boundary rationale

The binding B1 plan assigns the `$user->notify(...)` database-channel behavior smoke explicitly to the feature-test file created by Task B2. B1 is the declarative schema prerequisite for that behavior. Adding the smoke here would require creating B2-owned notification test/application scaffolding and would violate the one-task/no-scope-creep boundary. Accordingly, B1 used the plan-sanctioned migration verification boundary: establish RED by confirming the locked migration path was absent, then verify PHP syntax, Laravel's generated SQL, a real isolated SQLite migration, and the resulting columns/indexes. B2 remains responsible for the framework behavior smoke.

## Commands and evidence

1. RED boundary:

   `test ! -e apps/api/database/migrations/tenant/2026_07_12_110000_create_notifications_table.php`

   Exit 0 before implementation: the required migration did not exist.

2. PHP syntax:

   `php -l apps/api/database/migrations/tenant/2026_07_12_110000_create_notifications_table.php`

   Exit 0: `No syntax errors detected ...`.

3. Testing-environment SQLite pretend migration:

   `APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=<isolated-temp-db> CACHE_STORE=array SESSION_DRIVER=array QUEUE_CONNECTION=sync php artisan migrate --path=database/migrations/tenant/2026_07_12_110000_create_notifications_table.php --pretend --force`

   Exit 0. Laravel emitted the `notifications` table with UUID-backed `id`/`notifiable_id`, `type`, `data`, nullable `read_at`, `created_at`, `updated_at`, the standard morph index, and `notifications_notifiable_type_notifiable_id_read_at_index` in the required column order.

4. Real isolated SQLite migration:

   Same environment and path without `--pretend`; final gate exit 0: `2026_07_12_110000_create_notifications_table ... 2.58ms DONE`.

5. Direct SQLite schema inspection:

   `PRAGMA table_info(notifications)` confirmed eight columns, `id` as the primary key, `data` non-null, and `read_at` nullable. `PRAGMA index_list(notifications)` confirmed both the standard morph index and the secondary unread-poll index; the primary-key auto-index was also present.

6. Exact index order and rollback safety:

   `PRAGMA index_info(...)` returned `notifiable_type,notifiable_id` for the morph index and `notifiable_type,notifiable_id,read_at` for the polling index. `php artisan migrate:rollback --path=... --force` exited 0 in 1.58 ms, and a subsequent `sqlite_master` query confirmed the `notifications` table was absent.

7. Formatting and diff hygiene:

   `./vendor/bin/pint --dirty` returned `{"result":"pass"}`. `git diff --check` exited 0 with no output.

## Self-review

- `uuid('id')->primary()` is used rather than Laravel's default ULID/string notification ID.
- `uuidMorphs('notifiable')` creates the UUID notifiable key and framework-standard composite morph index.
- `jsonb('data')`, `timestampTz('read_at')->nullable()`, and `timestampsTz()` match the PostgreSQL tenant contract; SQLite's emitted `TEXT`/`datetime` affinities are expected portability behavior.
- The extra index is ordered exactly `(notifiable_type, notifiable_id, read_at)`.
- `down()` drops only the additive notifications table.

## Deviations

None.

## Concerns

None. Database-channel behavior remains intentionally assigned to B2.
