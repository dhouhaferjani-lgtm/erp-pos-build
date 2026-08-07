# Consent-gated support impersonation handback

Date: 2026-08-07

Branch: `codex/tenant-impersonation`

Base: `46fd7decd`

Reviewed implementation tip: `63f14987a07c2198db3162f1e583b1b7f7d0dc60`

Reviewed CI/test-infrastructure tip: `c52d0f71faf66352a439e3d834813f96f43406be`

## Promotion status

Handback only. Do not merge yet and never push this branch to `origin/dev`.

The feature-scoped security suites, full PHPStan level 8, typecheck, focused web tests, and PostgreSQL end-to-end path are green. All three exact-commit adversarial re-reviews accept the implementation and reconfirm acceptance at the final test-infrastructure head. The repository-wide gates still expose pre-existing baseline failures listed below. The required CI-only full backend step ran all configured paths with `always()` semantics despite the earlier Unit-lane failure. Final promotion remains blocked wherever those repository gates are mandatory.

## Commits

- `d6ca07f34 fix(tests): repair POS and inventory baseline regressions` — the requested three POS and one inventory test-only repairs.
- `4f9f0f077 Phase 1.3.1: Build consent-gated support impersonation` — implementation replacing the former WIP tip.
- `753cc4518 Phase 1.3.2: Close impersonation security gate gaps` — generated types, clean hexagonal token port, DTO placement, and an unskippable manual whole-backend CI step.
- `5376af997 Phase 1.3.3: Complete impersonation security perimeter` — closes the consolidated enforcement-perimeter findings, adds authoritative grant lifecycle auditing and durable mirror reconciliation, and repairs authenticated export behavior.
- `27b0eb6dc Phase 1.3.4: Make impersonation lifecycle audit atomic` — makes grant/session/elevation state transitions atomic with their authoritative chain events, schedules natural expiry, makes mirror delivery concurrency-safe and idempotent, verifies persisted attribution/timestamps, audits corrupted token denials, and repairs the frontend query/form/banner contracts.
- `63f14987a Phase 1.3.5: Close final impersonation review gaps` — makes grant revocation atomic across every child session, schedules poison-row-safe mirror reconciliation, refuses evidence-destroying rollback, completes generated frontend contracts, and makes lifecycle-event translations exhaustive.
- `2f7799890 fix(tests): make support access CI gate complete` — points both backend CI lanes at the disposable central database and partitions the manual full-backend gate by configured top-level path so every path runs in a fresh PHP process and failures aggregate without a 2 GiB whole-suite OOM.
- `c52d0f71f fix(tests): guard skipped support access cleanup` — lets the PostgreSQL-only acceptance test skip cleanly in the SQLite full-path partition without querying an unmigrated central PostgreSQL schema; PostgreSQL setup failures still fail and the original cleanup remains active after migration succeeds.

## Database changes

Central migrations, in order:

1. `2026_08_06_230000_create_impersonation_access_tables.php`
   - `impersonation_grants`
   - `impersonation_sessions`
   - `impersonation_elevations`
   - `impersonation_session_permissions`
   - append-only, hash-chained `impersonation_session_events`
2. `2026_08_06_230100_add_support_access_fields_to_tenants_and_admin_audit_logs.php`
   - tenant sensitivity/support-window fields
   - impersonator, session, event, sequence, and independent impersonation-chain attribution on `admin_audit_logs`
3. `2026_08_07_010000_extend_impersonation_audit_outcomes.php`
   - adds the `observed` request-received outcome to the PostgreSQL constraint.
4. `2026_08_07_020000_add_authoritative_grant_audit_chain.php`
   - adds the authoritative grant-chain head and sequence to `impersonation_grants`
   - creates append-only `impersonation_grant_events`
   - creates durable, idempotent `impersonation_audit_deliveries` for dual-mirror reconciliation
   - permits a null `admin_audit_logs.super_admin_id` for tenant-originated grant lifecycle events while retaining explicit tenant actor attribution
   - extends PostgreSQL session-event constraints for `grant_expired` and `request_failed`
5. `2026_08_07_020100_enforce_impersonation_mirror_uniqueness.php`
   - adds a partial unique index for support-access mirrors in `admin_audit_logs`
   - keeps non-support audit attribution rows outside that uniqueness constraint

Tenant migrations:

6. `tenant/2026_08_06_230200_add_impersonation_fields_to_audit_events.php`
   - adds the same attribution and independent impersonation-chain mirror columns to every tenant's `audit_events` table.
7. `tenant/2026_08_07_230300_enforce_impersonation_mirror_uniqueness.php`
   - adds a partial unique index for `support_access.%` tenant mirrors so reconciliation cannot duplicate a delivered event.

Deploy central migrations before enabling routes, then run the tenant migration for every tenant database. A session must not be enabled for a tenant whose audit mirror columns are absent.

## Permissions and operator roles

`RolesAndPermissionsSeeder` adds:

- `support-access.view`
- `support-access.manage`

Run the permission seeder in every tenant context and then run:

```bash
php artisan permission:cache-reset
```

The central operator roles are deliberately distinct:

- `super_admin` requests and operates support access.
- `support_approver` is the configured business-partner four-eyes account.

Configure the partner account through the `SUPPORT_ACCESS_PARTNER_*` environment values and run `SuperAdminSeeder`. The seeder does not rotate the existing platform super-admin password; it creates or updates the separately configured partner approver.

## Queues and Horizon

No new queue, job, or Horizon supervisor was introduced. `horizon.php` needs no coverage change for this feature. Tenant-manager grant notifications use the existing notification path synchronously. Failed audit-mirror deliveries are repaired idempotently with `php artisan support-access:audit-reconcile`; they are not delegated to an uncovered queue. The scheduler runs this command every minute with overlap protection, isolates and logs poison rows so later deliveries continue, returns non-zero when a row fails, and warns while a backlog remains.

The scheduler also runs `php artisan support-access:expire` every minute with overlap protection. It atomically transitions elapsed grants and sessions with their authoritative chain events, revokes their scoped tokens, and delivers both mirrors. Deployments must keep the Laravel scheduler running; both additions are scheduled commands, not Horizon queues.

## Fiscal and audit compatibility

- Fiscal write routes are enumerated and hard-blocked even during write elevation.
- Tenant deletion, password reset, and all support-access self-management paths are also hard-blocked.
- The pre-existing `audit_events.event_hash` calculation is byte-identical. Impersonation linkage is stored in separate `impersonation_*` columns and does not alter legacy hash input.
- The support-session event chain is append-only and independently verified; the tamper regression test fails verification as required.
- Grant lifecycle events are authoritative at transition time, append-only, independently chained, and mirrored to both audit stores even when no session exists. Session and grant chains are verified with `php artisan support-access:audit-verify --session=<uuid>` and `--grant=<uuid>`.
- Both mirror destinations are described by a durable delivery ledger before dispatch. A terminal mirror failure fails the request closed and can be reconciled without duplicating already-completed mirrors.
- Grant revocation commits the grant, every live child session, their authoritative events, and all delivery rows in one central transaction before attempting any mirror delivery.
- Mirror delivery locks its central ledger row and uses database uniqueness as the final idempotency guard. PostgreSQL JSONB payload normalization is performed before the unchanged legacy `AuditEvent::recomputeHash()` so a persisted mirror hash is reproducible after reload.
- The authoritative grant-audit migration is explicitly irreversible after evidence exists; rollback refuses instead of deleting retained audit records.
- Impersonation mirror rows have no fiscal company and remain outside the NF525 chain. Any future proposal to include them in fiscal canonical bytes requires a versioned fiscal-hash migration and a separate compliance review.

## Tunisia legal restriction

Impersonation **must not be used on a real Tunisian tenant** until the E-4 business-partner validation for Tunisia Law 2004-63 / INPDP has been completed and recorded. This is an operational prohibition, not a soft recommendation.

Break-glass access remains out of scope.

## Verification evidence

- SupportAccess PHPUnit by path: 85 passed, 594 assertions, with one separately executed PostgreSQL-only test skipped in the SQLite run.
- Live local PostgreSQL E2E: 1 passed, 132 assertions; grant → approve → impersonate → malformed request-ID normalization → elevate → write → revoke → next-request refusal → both mirrors → persisted legacy-hash recomputation → session and grant chain verification.
- PHPStan level 8 across all 2,796 configured files: zero errors.
- Pint on branch-dirty PHP files: pass.
- Root TypeScript typecheck: pass.
- Root lint: pass with existing warnings and no new SupportAccess/design/query-key violations.
- Focused web App/SupportAccess: 7 files, 16 tests, pass.
- Focused POS test repairs: 3 files, 14 tests, pass.
- React Doctor on the feature diff using explicit base `46fd7decd`: 90/100, no findings.
- Implementation-head CI: [run 31173960530](https://github.com/otospexsolutions/erp/actions/runs/31173960530) for `63f14987a`. The mandatory full-backend step did start despite the Unit failure and reached 8,968 of 11,473 tests before the single PHP process exhausted its 2 GiB memory limit. Its PostgreSQL invariant lane completed the SupportAccess acceptance path's 132 assertions, then failed during teardown because `DB_CENTRAL_DATABASE` still named a non-disposable database.
- CI/test-infrastructure diagnostic CI: [run 31187640701](https://github.com/otospexsolutions/erp/actions/runs/31187640701) for `2f7799890`. The PostgreSQL invariant lane confirms that `SupportAccessPostgresEndToEndTest` and its teardown pass; the lane reports 889 passed, 3 skipped, 1 failed, and 3,669 assertions, with only the pre-existing fiscal chokepoint inventory call-site failure. The full-backend step executes all 75 configured paths through `tests/Architecture` in fresh processes with no memory exhaustion: 11,474 tests, 52,949 assertions, 88 errors, 103 failures, 385 skipped, 3 incomplete, and 1 risky. It exposed one SupportAccess test-lifecycle defect: the SQLite partition's intended PostgreSQL-only skip still entered central cleanup against an unmigrated database. `c52d0f71f` fixes that test-only teardown path.
- Final CI/test-infrastructure-head CI: [run 31202946159](https://github.com/otospexsolutions/erp/actions/runs/31202946159) for `c52d0f71f`: **failure / DO NOT MERGE**. PHPStan, TypeScript, POS Vitest, and generated-type drift pass. The PostgreSQL lane confirms the live SupportAccess test and teardown pass, reporting 889 passed, 3 skipped, 1 failed, and 3,669 assertions; its sole failure is the pre-existing fiscal chokepoint inventory call site. The manual full-backend step executes all 75 configured paths through `tests/Architecture` without memory exhaustion: 11,473 tests, 52,972 assertions, 87 errors, 103 failures, 385 skipped, 3 incomplete, and no risky tests. Its SupportAccess partition is clean at 82 tests, 571 assertions, and 1 intended PostgreSQL-only skip. Earlier runs are diagnostic evidence, not promotion evidence.

## Discovered but not fixed in this lane

These failures reproduce outside the impersonation change-set and were not expanded into unrelated production/test cleanup:

- Full Pint reports 24 pre-existing style issues in Inventory, Company, Product, Fiscal, POS, Treasury, and their tests; no SupportAccess file is among them.
- Deptrac remains 4 above its checked-in baseline due pre-existing `SharedContracts on ModuleDomain` references; the SupportAccess module itself contributes zero violations after `5376af997`.
- The PostgreSQL chokepoint gates find the pre-existing unreconciled `InventoryCountingController::$countingService->finalize()` call site.
- `PendingSealMigrationTest` expects terminal fiscal schema version `2` while the current migration default is `3`.
- The Unit CI lane has 34 pre-existing failures (including stale constructor arities and POS shift fixture uniqueness).
- Repository-wide Vitest has unrelated failures/timeouts. The latest recursive run failed in unrelated expenses tenant-scope, replenishment, stock-by-location, treasury tenant-scope, and POS cart-mutator lint-guard tests; the feature-focused frontend tests pass.

All of the above remain promotion blockers wherever the owning gate is mandatory. No waiver is asserted by this handback.
