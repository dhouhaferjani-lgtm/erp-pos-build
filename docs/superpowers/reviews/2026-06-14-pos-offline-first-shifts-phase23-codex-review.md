# Adversarial Code Review - POS Offline-First Shifts Phase 2 + Phase 3

**Branch:** `feat/offline-first-shifts`  
**Diff reviewed:** `git diff 0ef99f609..b3fd1f71d` (`d99ed0fe2` Phase 2, `b3fd1f71d` Phase 3)  
**Date:** 2026-06-14  
**Reviewer:** Codex adversarial review pass  
**Spec:** `docs/superpowers/specs/2026-06-13-pos-offline-first-device-authoritative-shifts-design.md` §7, §12  
**Prior review context:** `docs/superpowers/reviews/2026-06-14-pos-offline-first-shifts-phase01-codex-review.md`

## Executive Summary

Verdict: **REQUEST-CHANGES**. The intended model is sound, but the implementation makes `pos_shifts` a non-atomic side effect after `pos_z_session_events`, while the existing idempotency guard skips the whole projector once the mirror row exists. That turns common PG-only failures, out-of-order close delivery, or FK failures into permanently missing or unclosed `pos_shifts` rows while the projection row can still become `applied`. The 409 REST guards are mutation-safe, and the `(terminal_id, shift_number)` migration shape appears correct, but the projector needs transaction/retry hardening before this lands.

## Findings Table

| Severity | Area | One-line summary | File:line |
|---|---|---|---|
| BLOCKER | Projection idempotency | `ZSessionEvent` is written before `pos_shifts`; a later failure makes retry skip the shift projection permanently | `apps/api/app/Modules/POS/Application/Projections/ZSessionLifecycleProjection.php:57` |
| BLOCKER | Projection ordering | Out-of-order `SESSION_CLOSE` is marked successful and never retried after `SESSION_OPEN` creates the row | `apps/api/app/Modules/POS/Application/Projections/ZSessionLifecycleProjection.php:189` |
| BLOCKER | PG CHECK constraints | Balanced cash counts write `variance_severity = balanced`, which violates the real PG check | `apps/api/app/Modules/POS/Application/Projections/ZSessionLifecycleProjection.php:231` |
| HIGH | Training isolation | `training_z_session` events project indistinguishable production `pos_shifts` rows | `apps/api/app/Modules/POS/Application/Projections/ZSessionLifecycleProjection.php:53` |
| MED | FK integrity | Projector writes device `operator_id` / supervisor ids directly into user FKs without existence checks | `apps/api/app/Modules/POS/Application/Projections/ZSessionLifecycleProjection.php:163` |
| MED | POS close path | Public v3 `terminalStore.closeShift()` clears state without proving `SESSION_CLOSE` was authored | `apps/pos/src/stores/terminalStore.ts:884` |
| LOW | Test quality | New projection tests call the projector directly on SQLite, hiding queue retry and PG-only failures | `apps/api/tests/Feature/POS/PosShiftProjectionTest.php:69` |

## Detailed Findings

### BLOCKER - `ZSessionEvent` idempotency can permanently skip `pos_shifts`

**Evidence**

- `apps/api/app/Modules/POS/Application/Projections/ZSessionLifecycleProjection.php:57` returns immediately when a `pos_z_session_events` mirror row already exists.
- `apps/api/app/Modules/POS/Application/Projections/ZSessionLifecycleProjection.php:79` creates that mirror row before the new `SESSION_OPEN` / `SESSION_CLOSE` shift side effects at lines 100-105.
- `apps/api/app/Modules/Fiscal/Application/Jobs/ApplyFiscalEventProjectionJob.php:220` documents that the projector owns its own transaction, and this projector does not open one.
- `apps/api/app/Modules/Fiscal/Application/Jobs/ApplyFiscalEventProjectionJob.php:393` calls `apply()`, then line 408 marks the projection row `applied` when `apply()` returns cleanly.

**Problem**

If `Shift::save()` fails after the mirror insert, the mirror row remains committed. On retry, line 57 sees the mirror row and returns before either `projectPosShiftOpen()` or `projectPosShiftClose()` runs. The job then marks the fiscal projection row `applied`, even though the `pos_shifts` projection never happened.

This is not theoretical: the diff introduces several post-mirror failure paths, including PG check failures and user FK failures. SQLite direct-projector tests do not exercise the queue retry path that makes this permanent.

**Spec reference**

Spec §7 Phase 2 requires `SESSION_OPEN -> INSERT pos_shifts ... ON CONFLICT (id) DO NOTHING`; Phase 3 requires `SESSION_CLOSE -> close pos_shifts`. Spec §12 resolves prior review F-12 by requiring idempotent close, not a successful no-op after a partial write.

**Recommended fix**

Make `ZSessionLifecycleProjection::apply()` atomic for the mirror row and `pos_shifts` side effects, or split the idempotency guards so an existing mirror row does not suppress a missing/incomplete `pos_shifts` projection. Add a queue-level regression test where the first attempt fails after `pos_z_session_events` is inserted, then a retry must still create/close `pos_shifts`.

### BLOCKER - Missing-row `SESSION_CLOSE` loses the close on out-of-order projection

**Evidence**

- `apps/api/app/Modules/POS/Application/Projections/ZSessionLifecycleProjection.php:189` loads the shift by `shift_id`.
- `apps/api/app/Modules/POS/Application/Projections/ZSessionLifecycleProjection.php:190` returns when the row is missing.
- `apps/api/app/Modules/Fiscal/Application/Services/OutboxIngestor.php:973` dispatches projection jobs independently after commit; there is no same-session dependency between the open event job and the close event job.
- `apps/api/app/Modules/Fiscal/Application/Jobs/ApplyFiscalEventProjectionJob.php:408` marks a clean-returning projector as `applied`.

**Problem**

Ingest validates that a `SESSION_OPEN` fiscal event exists before accepting later z-session events (`OutboxIngestor.php:536`), but it does not guarantee the `SESSION_OPEN` projection job has already created `pos_shifts`. If the `SESSION_CLOSE` projection runs first, it returns cleanly at line 190. The projection row is then `applied`; when the open projection later creates the row, there is no pending close projection left to close it.

That violates the Phase 3 contract: a synced `SESSION_CLOSE` must close `pos_shifts` idempotently. Missing-row-as-no-op is only safe for explicitly grandfathered cases, not for ordinary async ordering.

**Spec reference**

Spec §7 Phase 3: "`SESSION_CLOSE` -> close `pos_shifts`" and re-delivery is a no-op only for an already-closed row. Spec §12 F-5 says extending the same projector dissolves cross-projector ordering, but it does not dissolve cross-event job ordering.

**Recommended fix**

For a verified non-grandfathered close, treat missing `pos_shifts` as a retryable dependency miss, not success. For example, throw a dedicated dependency exception until the open projection creates the row, or make the close projection upsert/close from the fiscal event pair after proving the matching open event is present.

### BLOCKER - `variance_severity = balanced` violates the PostgreSQL check

**Evidence**

- `apps/api/app/Modules/POS/Application/Projections/ZSessionLifecycleProjection.php:206` reads `payload['variance_severity']` as an arbitrary string.
- `apps/api/app/Modules/POS/Application/Projections/ZSessionLifecycleProjection.php:231` writes that value to `pos_shifts.variance_severity`.
- `apps/api/database/migrations/tenant/2026_04_25_000003_add_cash_count_metadata_to_pos_shifts.php:21` adds the PG check; line 23 allows only `info`, `warning`, or `critical`.
- `apps/pos/src/lib/offline/cashCountValidation.ts:52` returns `severity: 'balanced'` for zero variance.
- `apps/pos/src/lib/offline/zReportService.ts:381` stores that severity into the Z report shift fields, and `apps/pos/src/lib/fiscal/zSessionAuthoring.ts:438` carries it into `SESSION_CLOSE`.
- The new fixture repeats the same invalid value at `apps/api/tests/Feature/POS/PosShiftProjectionTest.php:292`.

**Problem**

A common balanced cash count projects cleanly under SQLite but fails on real PostgreSQL. Because of the atomicity bug above, the first PG failure can leave `pos_z_session_events` inserted; the retry then skips the close side effect and marks the projection applied.

The legacy REST/Z-report sync path already knew this mismatch existed and normalized severities before writing `pos_shifts` (`apps/api/app/Modules/POS/Presentation/Controllers/ZReportSyncController.php:391`). The new projector bypasses that normalization.

**Spec reference**

Spec §7 Phase 3 requires `SESSION_CLOSE` projection to satisfy `pos_shifts_closed_logic`; the review scope also explicitly calls out PG checks that SQLite does not enforce. This is a PG-only failure hidden by the current test lane.

**Recommended fix**

Normalize `balanced` to `info` or `null` before writing `pos_shifts.variance_severity`, and reject/normalize any legacy labels consistently with `ZReportSyncController::normaliseVarianceSeverity()`. Add a PG-lane test or at least a projector test that uses the actual allowed enum.

### HIGH - Training z-session events become real `pos_shifts`

**Evidence**

- `apps/api/app/Modules/POS/Application/Projections/ZSessionLifecycleProjection.php:53` allows both `z_session` and `training_z_session`.
- The `pos_shifts` table definition at `apps/api/database/migrations/tenant/2026_01_08_190641_create_pos_shifts_table.php:20` through line 55 has no training flag.
- `apps/api/app/Modules/Accounting/Application/Services/Reports/CashRegisterReportService.php:30` reads from `pos_shifts` and filters by closed date at line 36, with no training exclusion.

**Problem**

The old lifecycle projection could mirror training events into `pos_z_session_events` without polluting production shift views. The new side effect creates/updates `pos_shifts`, where training status is not represented. A training terminal shift therefore looks like a normal cashier shift to current-shift reads, cash reconciliation, and any consumer that only has the `pos_shifts` row.

**Spec reference**

The review focus asks whether `integrity_status == Verified` plus `chain_context` gating ensures quarantined/training events do not mis-project. Quarantined events are skipped, but training events are not isolated at the `pos_shifts` boundary.

**Recommended fix**

Do not project `pos_shifts` for `training_z_session`, or add an explicit `is_training`/`training_flag` column and update every shift consumer/report to exclude or intentionally include it. The safer Phase 2/3 fix is to keep the lifecycle mirror for training but skip `pos_shifts`.

### MED - User FKs are fed directly from device payload ids

**Evidence**

- `apps/api/database/migrations/tenant/2026_01_08_190641_create_pos_shifts_table.php:28` makes `cashier_id` a `users` FK, and line 47 does the same for `closed_by`.
- `apps/api/database/migrations/tenant/2026_04_25_000003_add_cash_count_metadata_to_pos_shifts.php:16` adds `manager_override_by` as a `users` FK.
- `apps/api/app/Modules/POS/Application/Projections/ZSessionLifecycleProjection.php:163` writes `operator_id` to `cashier_id`; line 215 writes it to `closed_by`; line 235 writes `manager_approval.supervisor_user_id` to `manager_override_by`.
- `apps/api/app/Modules/Fiscal/Application/Services/FiscalPayloadConstraintValidator.php:588` validates `operator_id` as UUID shape, and line 624 validates only the z-report family UUID fields.

**Problem**

The projector does not resolve or validate that the payload ids exist in tenant `users` before writing FK columns. In the normal online login path the operator id should be a real user, but offline delivery can lag user deletion/deactivation, and manager approvals can come from cached offline approval data. On PG this becomes an FK violation in the projector. Combined with the non-atomic mirror write, that can become a permanently skipped shift projection on retry.

**Spec reference**

Spec §7 maps `operator_id -> cashier_id/closed_by` and manager approval to `manager_override_by`, but the review scope asks specifically whether the projection validates those ids or relies on the FK. It relies on the FK.

**Recommended fix**

Validate and fail before any mirror row is committed, or project nullable/snapshot fields when a user no longer exists. At minimum, wrap the projector atomically and add tests for missing cashier, missing closer, and missing supervisor ids against PostgreSQL semantics.

### MED - Public v3 `closeShift()` can clear state without authoring a close

**Evidence**

- `apps/pos/src/stores/terminalStore.ts:884` says v3 close events are authored locally before this action.
- `apps/pos/src/stores/terminalStore.ts:888` skips the REST close for v3, then line 897 removes `StorageKeys.SHIFT` and line 898 clears Zustand state.
- The normal Header EOD flow does author a Z/close first at `apps/pos/src/components/Header.tsx:250` and calls `closeShift()` at line 264.
- The public store action is still directly callable; `apps/pos/src/components/pos/CloseShiftModal.tsx:37` calls it without generating a Z report first, though the current Header comments indicate that modal has been replaced.

**Problem**

The current primary UI path is safe, but the store action itself is not Phase-3-complete: it does not prove that `SESSION_CLOSE` + `Z_REPORT` were authored or that `local_shifts` is already closed before clearing device state. Any future or stale caller of the public action can hide the active shift in memory/storage while leaving the local authoritative row open and no close event queued.

**Spec reference**

Spec §7 Phase 3 says `closeShift()` authors `SESSION_CLOSE` + `Z_REPORT` and updates `local_shifts` to `CLOSED`; this implementation relies on a caller convention.

**Recommended fix**

Make the v3 store action either route through the close-authoring flow itself or assert a locally closed shift row / authored close event before clearing state. If the direct modal is intentionally dead code, remove it or make it unable to call this path for v3.

### LOW - Tests miss the failure modes that matter here

**Evidence**

- `apps/api/tests/Feature/POS/PosShiftProjectionTest.php:69`, line 90, and line 160 call `ZSessionLifecycleProjection::apply()` directly.
- `apps/api/tests/Feature/POS/PosShiftProjectionTest.php:187` blesses missing-row close as a no-op.
- `apps/api/tests/Feature/POS/ShiftOpenV3GuardTest.php:97` covers close 409, but there is no v2 success-regression close test.
- `apps/pos/src/stores/__tests__/terminalStore.test.ts:328` verifies v3 `closeShift()` skips REST, but not that a close event/local close exists before clearing state.

**Problem**

The tests cover the happy shape but not the production failure surface: PG checks, FK violations, queue retry semantics, and out-of-order projection jobs. The use of SQLite and direct projector calls is exactly why `variance_severity = balanced` and post-mirror retry loss are invisible.

**Recommended fix**

Add a real job-level projection retry test, an out-of-order close/open projection test, a severity-normalization test using balanced input, and a PG-lane migration/projection check for `pos_shifts` constraints.

## Coverage / Test Quality

The new backend tests are useful as smoke tests but are overconfident because they call the projector directly and run under SQLite. They do not cover `ApplyFiscalEventProjectionJob` status transitions, retry after partial projector failure, PG-only check constraints, or FK violations. The v3 REST guard tests verify the intended 409s but do not prove v2 close/open behavior still succeeds, and the POS store test verifies skipped REST without verifying that the device close event was already authored.

The `(terminal_id, shift_number)` migration appears correct: the original migration used Laravel's default index name for `$table->index(['terminal_id', 'shift_number'])` at `2026_01_08_190641_create_pos_shifts_table.php:62`, and the new migration drops that plain index before creating `pos_shifts_terminal_shift_number_unique` at `2026_06_14_110000_make_pos_shifts_terminal_shift_number_unique.php:27`. It does not conflict with `pos_shifts_one_open_per_terminal`; the former prevents duplicate human shift numbers per terminal, while the latter prevents more than one open row per terminal.

The 409 guards are mutation-safe: `ShiftController::close()` checks authorization and company ownership before the v3 guard, but it does not call `ZReport` lookup or `ShiftManagementService::closeShift()` until after the guard. `SyncController::syncCloseShift()` similarly returns 409 before `isOpen()` and before the close service. `(int) ($terminal->fiscal_schema_version ?? 2)` handles null as legacy v2.

## Final Verdict

**REQUEST-CHANGES**
