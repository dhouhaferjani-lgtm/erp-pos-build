# Task 11 Codex Self-Adversarial Review - Account Payment Full-Flow Closure

Commit reviewed: `7f65be0af` - `Phase 2.11.1: Verify account payment full flow`

Verdict: **APPROVE**

## Scope Reviewed

- `apps/api/tests/Feature/Fiscal/TaskPhase2AccountPaymentFullFlowTest.php`
- `apps/api/tests/Feature/Fiscal/Task33FiscalFullFlowVerificationTest.php`
- `docs/superpowers/specs/2026-05-14-pos-customer-accounts-roadmap-v2.md`

## What Changed

- Added endpoint-driven Phase 2 closure tests for device-authored `ACCOUNT_PAYMENT`:
  - Treasury-active flow: sealed event syncs through `/api/v1/pos/sync/fiscal-events`, stores exact canonical bytes, parses strictly, creates POS-core printable receipt, creates Treasury `Payment`, and allocates FIFO to an open invoice.
  - POS-only flow: resolver reports Treasury inactive, the same event still stores and projects printable POS receipt, and no Treasury `Payment` or allocation rows are created.
- Updated roadmap v2 to mark Phase 2 implementation complete through Task 11 while preserving the explicit Phase 1.5 per-country tax-number deployment gate.
- Made the existing Phase 1 Task33 closure fixture derive its device time from current UTC minus 30 seconds. The prior fixed `2026-05-20T14:30:00Z` timestamp became a `time_anomaly` after the real date crossed more than 24h, which was incompatible with `OutboxIngestor`'s DB-clock drift check.

## Verification Evidence

- Initial red signal: new Task 11 test first failed on persisted `payment_allocations.amount` scale (`100.0000` vs `100.000`), then on SQLite not maintaining the PostgreSQL `documents.balance_due` trigger. The test was corrected to assert the real allocation row rather than SQLite trigger behavior.
- Full backend gate: `APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA= ./vendor/bin/phpunit tests/Feature/Fiscal/ tests/Unit/Fiscal/ tests/Feature/POS/` -> 1124 tests / 3820 assertions, 107 skipped, 2 incomplete, 16 deprecations.
- Focused closure check: `APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA= ./vendor/bin/phpunit tests/Feature/Fiscal/Task33FiscalFullFlowVerificationTest.php tests/Feature/Fiscal/TaskPhase2AccountPaymentFullFlowTest.php` -> 3 tests / 89 assertions.
- PHPStan L8: `./vendor/bin/phpstan analyse --level=8 tests/Feature/Fiscal/Task33FiscalFullFlowVerificationTest.php tests/Feature/Fiscal/TaskPhase2AccountPaymentFullFlowTest.php` -> no errors.
- Pint: `./vendor/bin/pint --test tests/Feature/Fiscal/Task33FiscalFullFlowVerificationTest.php tests/Feature/Fiscal/TaskPhase2AccountPaymentFullFlowTest.php` -> pass.
- POS Vitest: `pnpm test` in `apps/pos` -> 162 files / 1445 tests.
- POS typecheck: `pnpm typecheck` -> pass.
- POS lint: `pnpm lint` -> 0 errors / 41 existing warnings.
- Chokepoint + sentinel: `bash apps/api/scripts/check-saleReceipt-chokepoints.sh && test ! -e apps/pos/src/lib/offline/.PASS_2B_PENDING` -> pass.

## Standing-Pattern Checks

- Cross-tenant/company FK safety: **PASS**. The full-flow fixture seeds customer, payment method, repository, account, invoice, terminal, and actor in one tenant/company and asserts the projected Payment keeps those IDs. POS-only test proves Treasury operational rows are not created when the module is inactive.
- Fail-loud vs silent downgrade: **PASS**. The test asserts projection rows are `applied`; any projection failure would surface as missing rows or failed status. Task33 time anomaly was fixed at the fixture source, not by accepting quarantined output.
- Dead-path rebuild: **PASS**. The tests use the real `/api/v1/pos/sync/fiscal-events` endpoint and production projector registry paths, not direct projector invocation.
- D16 bounded-modules guard: **PASS**. The POS-only resolver test proves `pos_core_account_payment_receipt` remains live without the Treasury bridge.
- Canonical byte equivalence: **PASS**. The test asserts device canonical bytes equal `fiscal_events.canonical_bytes`, hash to `current_hash`, parse to `payload`, and match the POS printable payload snapshot through `CanonicalPayloadReader`.
- FIFO allocation coverage: **PASS**. A posted open invoice is seeded and the resulting `payment_allocations` row is asserted against that invoice.
- Contract drift: **PASS**. Roadmap status uses the exact deployment-blocking language required by the Task 11 plan for unresolved Phase 1.5 per-country tax-number validation.
- Per-method skips / skip-citation accuracy: **PASS**. No new skips were introduced.
- Constructor injection / service location: **PASS**. No production code changed. Test-only container usage is limited to fixture setup and a `ModuleActivationResolver` test double.
- R2-defect standing pattern: **PASS**. The Task33 flake fix was re-verified with focused tests and the full backend suite.

Residual risk: the FIFO allocation assertion runs on SQLite, so it validates the `payment_allocations` row and deliberately avoids asserting PostgreSQL trigger-maintained `documents.balance_due` cache. Existing Treasury allocation tests remain the owner for trigger/cache behavior.
