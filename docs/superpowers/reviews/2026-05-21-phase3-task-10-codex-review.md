# Task 10 Codex Self-Adversarial Review

Date: 2026-05-22
Reviewer: Codex
Implementation commits:

- `e2db04370` (`Phase 3.10.1: Add account charge integration gate`)
- `520078d87` (`Phase 3.10.2: Harden account charge integration assertions`)
Scope reviewed:

- `.github/workflows/ci.yml`
- `apps/api/scripts/check-accountCharge-chokepoints.sh`
- `apps/api/tests/Feature/Fiscal/TaskPhase3AccountChargeFullFlowTest.php`
- `apps/pos/src/lib/accountCharge/__tests__/accountChargeFullFlow.test.ts`

## Verdict

APPROVE

## Review Axes

### Dead-Path Rebuild

PASS. The backend test posts sealed `ACCOUNT_CHARGE` envelopes through the live `/api/v1/pos/sync/fiscal-events` endpoint, not direct model insertion. It verifies persisted `fiscal_events.canonical_bytes`, POS-core `AccountChargeReceipt`, Treasury AR journal projection, Sales draft facture projection, and module-inactive behavior. The POS test calls the live `authorAccountCharge()` service and verifies it reaches `FiscalEventEngine.append()`.

### D16 Bounded-Module Guard

PASS. Task 10 adds tests and a shell sentinel only. No production projector imports or engine dependencies were added. The test exercises the existing `ModuleActivationResolver` seam and explicitly forgets both `FiscalEventProjectionRegistry` and `OutboxIngestor` after rebinding the resolver, preventing stale singleton state from hiding module-gating behavior.

### Server-Authoring / Legacy Route Regression

PASS. The backend matrix asserts `POST /api/v1/pos/receipts` returns `410 NEW_SALE_AUTHORING_RETIRED` and that `POST /api/v1/pos/receipts/sync` remains absent (`405`). The shell sentinel forbids `ACCOUNT_CHARGE` branching in `ReceiptCreationService`, `ReceiptController`, and POS route definitions, and checks the account-charge device path does not call legacy receipt endpoints.

### Device No-Append / No-Sync Rejections

PASS. The POS full-flow test verifies insufficient credit and hard-stale balance snapshots reject before `getFiscalEventEngine()`, before `append()`, before sync-store pending-count increment, before `triggerSync()`, and before any fetch call.

### Fail-Loud vs Silent Downgrade

PASS. The backend test includes malformed device-bug envelopes for production over-limit and hard-stale blocked decisions. Those are admitted as `canonical_parse_failure` quarantine rows and do not project POS, Treasury, Sales side effects, or `fiscal_event_projections` rows. The valid projection tests assert applied projection rows for the active paths.

### Cross-Tenant FK Safety

PASS for Task 10 scope. This task adds integration tests, not new FK-producing production code. The tests still seed tenant/company/customer/terminal consistently and assert projected rows preserve the same tenant and company. Cross-company customer rejection remains covered in the Task 8 and Task 9 bridge tests already in the broadened suite.

### Contract Drift

PASS. The backend fixture uses the production parser envelope shape, including the full ACCOUNT_CHARGE buyer key set required by `FiscalPayloadConstraintValidator`; this caught and fixed an initial direct-projector-fixture drift during focused verification. The CI PG filter now includes `TaskPhase3AccountChargeFullFlowTest` with an explanatory PG-only rationale.

### Discriminated-Union / Matrix Completeness

PASS. No new discriminated-union DTO was introduced. The matrix covers the requested major flow variants: all modules active, POS-only, business customer with Sales draft facture, retired server-authored route, insufficient credit, and hard-stale block.

### Skip Discipline And Citations

PASS. No new skips were added. No class-level `markTestSkipped` was introduced.

### Constructor Injection / Service Location

PASS. No production service location was added. The test uses `$this->app->make()` and `$this->app->bind()` in Laravel test setup only; no `app()`, `App::make()`, or `resolve()` calls were introduced in application code.

### CI Sentinel Wiring

PASS. `check-accountCharge-chokepoints.sh` is executable and wired into the existing lightweight chokepoint job next to `check-saleReceipt-chokepoints.sh`.

## R2 Self-Review After Opus Minor Findings

Verdict: APPROVE.

Opus R1 requested three minor hardening edits. Commit `520078d87` addresses them without changing production code:

- Quarantined insufficient-credit and hard-stale envelopes now assert zero `fiscal_event_projections` rows for the event, not only zero projection side-effect rows.
- Business-customer draft facture flow now asserts exactly one stored fiscal event and that it is the original `ACCOUNT_CHARGE`, pinning that the Sales bridge did not author a Tax Invoice or any other fiscal event.
- The account-charge chokepoint sentinel no longer bans harmless `/pos/receipts` text anywhere under account-charge tests/comments. It now flags only call-site-shaped `fetch` / `apiPost` / `apiFetch` / `apiRequest` references to legacy receipt endpoints.

R2 attack vectors:

- R2 did not weaken the legacy route sentinel for production code: backend POS route definitions still forbid `/pos/receipts/sync`, and device account-charge production files still fail on call-site-shaped legacy receipt endpoint usage.
- R2 did not add a new dead path: added assertions read tables already created by the live endpoint/projection flow.
- R2 did not add service-location or production dependency edges.
- R2 kept the phase-specific review filenames to avoid overwriting the existing Phase 2 Task 10 audit files.

## Verification Evidence

- `APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA= ./vendor/bin/phpunit tests/Feature/Fiscal/TaskPhase3AccountChargeFullFlowTest.php`
  Result: OK, 6 tests, 73 assertions after R2.
- `./vendor/bin/phpstan analyse --level=8 tests/Feature/Fiscal/TaskPhase3AccountChargeFullFlowTest.php`
  Result: OK, no errors.
- `./vendor/bin/pint --test tests/Feature/Fiscal/TaskPhase3AccountChargeFullFlowTest.php`
  Result: pass.
- `pnpm vitest run src/lib/accountCharge/__tests__/accountChargeFullFlow.test.ts`
  Result: 1 file, 3 tests passed.
- `APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA= ./vendor/bin/phpunit tests/Feature/Fiscal/ tests/Unit/Fiscal/ tests/Feature/POS/ tests/Feature/Accounting/POSAccountChargeJournalEntryTest.php tests/Feature/Document/`
  Result: OK, 1530 tests, 5388 assertions, 49 deprecations, 114 skipped, 2 incomplete.
- `./vendor/bin/phpstan analyse --level=8 --memory-limit=2G`
  Result: OK, no errors. The default 512M run hit PHPStan's memory ceiling before diagnostics completed.
- `pnpm test`
  Result: 169 files, 1503 tests passed.
- `pnpm typecheck`
  Result: passed.
- `pnpm lint`
  Result: exit 0, existing 41 warnings.
- `bash apps/api/scripts/check-saleReceipt-chokepoints.sh && bash apps/api/scripts/check-accountCharge-chokepoints.sh && bash apps/pos/scripts/check-pass-2b-pending.sh`
  Result: PASS for sale receipt gate, PASS for ACCOUNT_CHARGE gate, Pass 2B sentinel exits 0.
- `git diff --check`
  Result: clean.
- `bash apps/api/scripts/check-accountCharge-chokepoints.sh`
  Result: PASS after R2 narrowing.

## Residual Risk

Low. The new backend matrix relies on Laravel sync queue behavior to run projection jobs in-process, matching the existing Phase 2 closure test pattern. CI PG coverage is wired so the same class also runs against PostgreSQL-specific constraints.
