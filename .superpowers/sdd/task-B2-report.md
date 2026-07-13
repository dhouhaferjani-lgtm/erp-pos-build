# Task B2 Report — Slim Notification module inbox read API

## Status

Complete. The platform now exposes the authenticated tenant user's Laravel database-notification inbox through the four ownership-scoped endpoints required by specification §6.2.

## Requirements reviewed

- Plan Global Constraints and Task B2.
- Binding design specification §6.1, §6.2, and §14.
- B1 migration report and database-channel smoke hand-off.
- Cart provider and Treasury route/middleware exemplars.

No requirement ambiguity or plan/spec conflict was found.

## Files

- Created `apps/api/app/Modules/Notification/Providers/NotificationServiceProvider.php`.
- Created `apps/api/app/Modules/Notification/Presentation/routes.php`.
- Created `apps/api/app/Modules/Notification/Presentation/Controllers/NotificationController.php`.
- Registered the provider in `apps/api/bootstrap/providers.php`.
- Created `apps/api/tests/Feature/Notification/NotificationEndpointsTest.php`.
- Updated `.superpowers/sdd/progress.md` and `docs/handoff/treasury-phase3-progress.md`.

No Treasury movement port, fiscal-perimeter, frontend/interlock, permission-seeder, or unrelated module file was touched.

## TDD evidence

1. RED before provider registration:

   `cd apps/api && ./vendor/bin/phpunit tests/Feature/Notification/NotificationEndpointsTest.php`

   Exit 1: `FAILURES! Tests: 6, Assertions: 7, Failures: 4.` Every desired endpoint expectation reached the required missing-module 404. The B1 `$user->notify(...)` database-channel smoke passed, proving the migration/framework behavior independently. The malformed-ID check also passed because an absent route is necessarily 404; its GREEN value comes from the registered route's explicit UUID constraint.

2. Initial GREEN after minimal production registration and implementation:

   Same command, exit 0: `OK (6 tests, 43 assertions)`.

3. Post-format focused GREEN:

   Same command, exit 0: `OK (6 tests, 43 assertions)`.

## Contract coverage

- Database-channel smoke: `$user->notify(...)` persists a standard tenant `notifications` row.
- Inbox: caller-only records, ordered newest first, exact page/per-page behavior, and `{data,meta}` with `current_page`, `per_page`, `total`, and `last_page`.
- Filters: `filter=unread` excludes read records; `filter=all` includes both states.
- Count: unread count excludes another user's unread rows.
- Single read: a foreign UUID returns 404; an owned row is marked read; a second call remains 200 and preserves the first `read_at`.
- Malformed UUID: `POST /api/v1/notifications/not-a-uuid/read` returns 404 through `whereUuid('id')`, never reaching the UUID-backed query.
- Read all: only the caller's unread relationship is updated; a foreign unread row remains unread.
- Serialization: every item contains `id`, `type`, `data`, `read_at`, and `created_at`.

All reads and writes begin from the authenticated caller's `notifications()` or `unreadNotifications()` relationship. There is deliberately no `can:` gate: ownership is the authorization boundary.

## Route and type verification

- `php artisan route:list --path=api/v1/notifications -v` listed exactly four routes. Every route carries the locked middleware stack in order: `api`, `auth:sanctum`, `SetPermissionsTeam`, `EnforceTokenTenantClaim`. The item-read declaration contains `whereUuid('id')`.
- The first `./vendor/bin/phpstan --memory-limit=1G` run found one legitimate `method.nonObject` diagnostic because framework metadata types `created_at` as nullable. The serializer was made null-safe without suppression, a baseline, or an inferred-type override.
- The rerun exited 0 with `[OK] No errors` across 2506 files.
- `./vendor/bin/pint --dirty` exited 0 and formatted the endpoint test.
- `git diff --check` exited 0 with no output.

## Self-review

- Provider registration and route loading are both present; the RED proves registration is material.
- Route ordering places the static `read-all` route before `{id}/read` and the UUID constraint prevents malformed primary-key queries.
- `per_page` is clamped to 1–50 and defaults to 15.
- The controller uses strict types, concrete request/response/user/notification types, and explicit authentication narrowing; it has no `mixed` signatures.
- The implementation is Presentation-only and relies on Laravel's native notification domain, as specified.

## Deviations

None.

## Concerns

None.
