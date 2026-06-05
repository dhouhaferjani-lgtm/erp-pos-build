# POS Audit Pipeline Backend Review

Diff reviewed: `git diff 665d76ffb..2b5e540bd -- apps/api`

Spec reviewed: `docs/superpowers/specs/2026-06-04-pos-mt-login-C-audit-pipeline-design.md` §Backend.

Required verification:

- `./vendor/bin/phpunit --filter AuditEvent 2>&1 | tail -8`: passed, `Tests: 10, Assertions: 31, PHPUnit Deprecations: 443, Skipped: 1`.
- `./vendor/bin/phpstan analyse app/Modules/POS/Presentation/Controllers/AuditEventSyncController.php app/Modules/Compliance/Domain/AuditEvent.php 2>&1 | tail -5`: did not reach analysis in this sandbox; output ended with `Failed to listen on "tcp://127.0.0.1:0": Operation not permitted (EPERM)`.

## Findings

### MAJOR: Route enforces only the Spatie permission, not the dedicated Sanctum token ability

Problem: `pos.audit_sync` is registered as a Spatie permission and checked through `Gate::authorize()`, but the endpoint never checks the authenticated token's abilities. Real POS tokens are minted with `['tenant:<uuid>', 'pos:*']`, not `pos.audit_sync`, and the feature test uses `Sanctum::actingAs()` rather than a real bearer token, so it does not prove that a token lacking `pos.audit_sync` is rejected. A bearer token without the dedicated ingest ability can still pass if the token's user has the Spatie permission.

Evidence:

- `apps/api/app/Modules/POS/Presentation/Controllers/AuditEventSyncController.php:43` calls `Gate::authorize('pos.audit_sync')`.
- `apps/api/app/Modules/POS/routes.php:33-42` mounts the route under `auth:sanctum` but no Sanctum `abilities`/`ability` middleware.
- `apps/api/app/Modules/Identity/Presentation/Controllers/AuthController.php:170-172` mints POS-client tokens as `['pos:*']`, not `pos.audit_sync`.
- `apps/api/tests/Feature/POS/AuditEventSyncTest.php:45-49` grants the user Spatie permission and uses `Sanctum::actingAs($this->user)`, which does not exercise real token ability contents.
- `apps/api/tests/Feature/POS/AuditEventSyncTest.php:158-166` only revokes the Spatie permission; it does not send a real token missing `pos.audit_sync`.

Concrete fix: Enforce both layers. Either add route middleware / explicit checks for `tokenCan('pos.audit_sync')` or define a documented `pos:*` wildcard policy and test it explicitly. If `pos.audit_sync` is meant to be a dedicated token ability, mint it for POS clients that should sync audit events and add a feature test using `$user->createToken(...)->plainTextToken` with and without `pos.audit_sync`.

### MAJOR: Client-supplied `company_id` and `operator_id` are not scoped to the authenticated tenant

Problem: The only per-event tenant guard compares `events.*.tenant_id` to the authenticated user's tenant. `company_id` and `operator_id` are accepted as nullable UUIDs without existence or tenant-scope validation, then persisted as-is. That lets a tenant A token ingest a tenant A row whose `company_id` or `user_id` points at tenant B, poisoning company-scoped audit/fraud/NF525 queries that filter by `company_id`.

Evidence:

- `apps/api/app/Modules/POS/Presentation/Controllers/AuditEventSyncController.php:52-53` validates `company_id` and `operator_id` only as nullable UUIDs.
- `apps/api/app/Modules/POS/Presentation/Controllers/AuditEventSyncController.php:67-72` rejects only `tenant_id` mismatch.
- `apps/api/app/Modules/Compliance/Domain/AuditEvent.php:170-179` persists the supplied `tenant_id`, `company_id`, and `operator_id` directly.
- `apps/api/database/migrations/tenant/2025_11_30_140000_create_audit_events_table.php:15-23` and `apps/api/database/migrations/tenant/2025_11_30_140001_add_company_id_to_audit_events.php:18-26` define IDs/indexes but no FK enforcing company/user tenancy for audit events.
- `apps/api/app/Modules/POS/Application/Services/Nf525DataProvider.php:289-299` consumes audit events by `company_id` without a tenant predicate.

Concrete fix: Validate `company_id` with `ScopedExists::tenant('companies', $tenantId)` or `ScopedExists::tenantAndCompany(...)` where appropriate, validate `operator_id` with `ScopedExists::tenant('users', $tenantId)`, and reject mismatches before the write loop. Add feature tests for cross-tenant `company_id` and cross-tenant `operator_id` with zero writes.

### MAJOR: The DB-per-tenant write test is skipped locally and would still be vacuous with `Sanctum::actingAs()`

Problem: The spec requires proof that the write lands in the active tenant DB. The only test for that path is skipped on non-PostgreSQL, and its setup does not provide the pre-auth resolver with a real tenant-bearing bearer token. `ResolveTenancy` resolves tenant context from a signed tenant param, bearer token ability, or session; `Sanctum::actingAs()` bypasses that route-time bearer-token path. The assertion then queries `AuditEvent::query()` on whatever connection is active, so it does not prove central-vs-tenant placement.

Evidence:

- `apps/api/tests/Feature/POS/AuditEventSyncTest.php:49` uses `Sanctum::actingAs($this->user)`.
- `apps/api/tests/Feature/POS/AuditEventSyncTest.php:169-175` skips the DB-per-tenant test unless the current driver is PostgreSQL, then only flips config.
- `apps/api/tests/Feature/POS/AuditEventSyncTest.php:179-181` posts without an `Authorization: Bearer ...` token containing `tenant:<uuid>`.
- `apps/api/app/Modules/Identity/Presentation/Middleware/ResolveTenancy.php:71-99` resolves tenant id only from signed link input, bearer token, or session.
- `apps/api/tests/Feature/POS/AuditEventSyncTest.php:186-188` asserts with an unqualified `AuditEvent::query()` instead of independently checking tenant and central connections.

Concrete fix: Build the DB-per-tenant test like the existing Stancl flip tests: provision/migrate a tenant DB, create a real token with `tenant:<uuid>` and the required sync ability, send it as a bearer token, assert the row exists on the tenant connection, and assert the central/default central audit table has no row. Keep a SQLite compat test, but do not treat it as coverage for DB-per-tenant routing.

### MINOR: `client_clock_skew_ms` sign is inverted relative to the spec

Problem: The spec defines `client_clock_skew_ms` as `server_now - client occurred_at`. The controller calls `now()->diffInMilliseconds(clientTime, false)`, which returns a negative value when the server clock is later than the client timestamp. That stores `client - server`, not `server - client`.

Evidence:

- `docs/superpowers/specs/2026-06-04-pos-mt-login-C-audit-pipeline-design.md:140-141` specifies `server_now - client occurred_at`.
- `apps/api/app/Modules/POS/Presentation/Controllers/AuditEventSyncController.php:90-94` computes `now()->diffInMilliseconds(Carbon::parse(...), false)`.
- `apps/api/tests/Feature/POS/AuditEventSyncTest.php:90-91` only asserts that the key exists, not the sign/value.

Concrete fix: Capture `$serverNow = now()` once, store `ingested_at` from it, and compute skew as `Carbon::parse((string) $event['occurred_at'])->diffInMilliseconds($serverNow, false)` or as an explicit millisecond timestamp subtraction. Add a deterministic test with a frozen server clock and a client timestamp 10 seconds behind.

## Notes on probed vectors

- Idempotency/race: Laravel 12 wraps PostgreSQL SQLSTATE `23505` and SQLite `UNIQUE constraint failed` as `Illuminate\Database\UniqueConstraintViolationException` (`vendor/laravel/framework/src/Illuminate/Database/Connection.php:834-845`, `PostgresConnection.php:53-55`, `SQLiteConnection.php:60-62`). The duplicate path is also exercised by `apps/api/tests/Feature/POS/AuditEventSyncTest.php:95-107`.
- Factory behavior: `AuditEvent::fromClientEnvelope()` avoids the stamping constructor branch by calling `new self` with no args (`AuditEvent.php:142-150`), sets `occurredAt` from the client timestamp (`AuditEvent.php:157-168`), force-fills `occurred_at` from that Carbon (`AuditEvent.php:170-180`), and recomputes the hash last (`AuditEvent.php:182-195`). I found no source evidence that `HasUuids` overwrites the preset key.
- Hash determinism: The hash includes the client timestamp through `occurredAt` (`AuditEvent.php:223-235`) and the unit test recomputes the expected hash over that client time (`AuditEventFromClientEnvelopeTest.php:69-87`).
- Batch validation before writes: the controller validates tenant/byte caps over the full batch before the write loop (`AuditEventSyncController.php:65-81`), so no batch transaction is consistent with the spec's duplicate semantics.

## Verdict

REQUEST-CHANGES

Confidence: high. The factory/idempotency mechanics look sound, but the authorization/token-scope gap, cross-tenant reference validation gap, and DB-per-tenant test gap are material for an audit ingest surface.
