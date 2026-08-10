# Accounting Gaps C/G/H/I Implementation Plan

> **For Codex:** Execute this approved handoff plan incrementally with red/green tests and revert-replay proof for each fix commit.

**Goal:** Close the four accounting gaps assigned by `CODEX-DISPATCH-accounting-gaps-CGHI-2026-08-09.md` without changing the already-seeded A/B/D/E/F lane or the excluded J scope.

**Architecture:** Extend the existing fiscal projection recovery command rather than introducing a second recovery path. Keep country stamp-duty support in `CountryTaxConfigurationRegistry`, expose that capability through the taxation API, and make both mutation validation and calculation fail closed. Preserve projection atomicity by propagating audit persistence errors through the existing projection job retry/dead-letter lifecycle.

**Tech Stack:** Laravel 12/PHP 8.4, PostgreSQL and SQLite test lanes, React/TypeScript/Vite, TanStack Query, Vitest/Testing Library.

---

## Task C: Sales discount recovery

- Add failing command tests for an `ACCOUNT_CHARGE` event-type filter and deterministic dry-run inventory output in `apps/api/tests/Feature/Fiscal/RetryFiscalProjectionsCommandTest.php`.
- Extend `apps/api/app/Modules/Fiscal/Infrastructure/Commands/RetryFiscalProjectionsCommand.php` with validated `--event-type`, stable ordering, and actionable inventory fields.
- Add a failing real-projector recovery test proving a missing `SalesDiscount` account dead-letters, the idempotent chart-purpose backfill creates the TN/FR mapping, and synchronous replay posts the balanced discount entry.
- Run the two focused feature paths on SQLite and PostgreSQL; capture revert-replay failure and restored green evidence.

## Task G: Stamp-duty capability guard

- Add failing registry and HTTP tests for TN support, FR store/update refusal, and refusal of generic non-stamp `DOCUMENT_TOTAL` configurations.
- Add `supportsStampDuty()` to `CountryTaxConfigurationRegistry`; inject the registry into `TaxConfigurationController`; expose a tenant-scoped capability endpoint and validate the merged final state on create/update.
- Add failing modal tests, then add the capability query, types, disabled controls, and translated English/French reason text.
- Run focused API and UI tests plus revert-replay proof.

## Task H: Stamp-only document totals

- Add failing service byte-identity coverage showing an invalid non-stamp document-total row cannot alter the TN stamped total or hash input.
- Add failing credit-note end-to-end coverage proving a non-stamp document-total row never reaches stamp-duty persistence or GL purposes, while retaining the positive TN timbre flow.
- Restrict `TaxCalculationService` document-level accumulation to `is_stamp_duty=true` and add the separately scoped own-named-total follow-up ticket.
- Run focused service and credit-note tests on SQLite and PostgreSQL plus revert-replay proof.

## Task I: Fail-closed missing-purpose alerts

- Add a failing real projection-job test that forces `pos.gl.tolerance_purpose_missing` audit insertion to fail and proves the projection stays retryable with all accounting writes rolled back.
- Re-throw the audit persistence exception from `TreasuryReceiptBridge`, preserving the outer transaction and existing projection retry lifecycle.
- Remove the injected failure and prove the same row retries to `Applied` with its audit alert and accounting entries.
- Run the focused test on SQLite and PostgreSQL plus revert-replay proof.

## Final verification and handoff

- Run touched PHP paths, Pint, scoped PHPStan, frontend tests/typecheck/lint, and React Doctor without the full PHPUnit suite.
- Write `docs/sessions/codex-accounting-gaps-cghi-report.md` with exact commands, SQLite/PostgreSQL evidence, test counts, revert-replay evidence, files changed, and residual follow-up scope.
- Leave branch `codex/accounting-gaps-cghi` local, unpushed, and unmerged.
