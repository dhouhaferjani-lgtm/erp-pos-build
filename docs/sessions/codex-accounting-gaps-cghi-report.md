# Codex Accounting Gaps C/G/H/I Report

Date: 2026-08-10
Branch: `codex/accounting-gaps-cghi`
Worktree: `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/accounting-gaps-cghi`
Status: implemented, committed locally, not pushed, not merged into `dev`

## Dependency reconciliation

The dispatch stated that the accounting seeder lane was already on local `dev`, but `dev` at branch creation (`ed6fe896f`) did not contain it. The local completed lane `fix/dpa-seeder-gaps-accounting` ended at `c05812444`. It was merged into this feature branch only as `9ff9fc6eb` (`Merge completed accounting seeder dependency`), preserving root `dev` and its unrelated untracked files.

That dependency supplies the idempotent `accounting:backfill-chart-purposes` command and the TN/FR `SalesDiscount` mapping at code `7097`. The feature work then proves the operational dead-letter recovery against those real artifacts.

Local `dev` advanced independently to `7d423d162` while this task was running. That later unrelated merge was not pulled into this already-tested feature branch.

## Delivered scope

### C — SalesDiscount recovery

- Added validated `--event-type` filtering to `fiscal:retry-projections`.
- Dry runs now emit deterministic per-row inventory with tenant, event, event type, projector, status, attempts, and last error.
- Added a real `treasury_account_charge_bridge` flow that:
  1. submits a discounted `ACCOUNT_CHARGE`;
  2. fails because `SalesDiscount` is absent;
  3. records the projection as dead-lettered;
  4. runs the chart-purpose backfill twice to prove creation plus idempotence;
  5. synchronously replays only the targeted account-charge projection;
  6. proves the balanced `Dr AR 114 + Dr SalesDiscount 5 / Cr Revenue 100 + Cr VAT 19` entry.

### G — stamp-duty country capability

- Made `CountryTaxConfigurationRegistry` the single authority for both country seeder support and `supportsStampDuty()`; TN is supported and FR is not.
- Added `GET /api/v1/taxation/configurations/capabilities`, scoped through the current company.
- Store and update now reject:
  - stamp duty in unsupported countries (`is_stamp_duty` validation error);
  - any non-stamp `DOCUMENT_TOTAL` configuration (`applies_to` validation error).
- Update validation evaluates the merged final state and now validates `tax_type` and `applies_to` when supplied.
- The React modal reads the server capability, fails closed while unavailable, disables unsupported stamp/document-total controls, and explains the restriction in English and French.
- TN positive creation, FR negative create/update, tenant-scoped capability query keys, and the surrounding settings page are covered.

### H — stamp-only document totals

- `TaxCalculationService` now includes a document-level row only when `applies_to=DOCUMENT_TOTAL` and `is_stamp_duty=true`.
- A brownfield non-stamp document-total row is ignored even if inserted outside the guarded API.
- The TN exact-byte test pins:
  - `documentTaxTotal = 1.000`;
  - `total = 101.000`;
  - hash input `INV-H-BYTES|2026-08-10T00:00:00Z|101.000|TND`;
  - byte-identical calculation output before and after adding an invalid non-stamp row.
- The live credit-note create → confirm → post flow proves a non-stamp document-total row leaves `stamp_duty_amount=0.000`, total `50.000`, and creates no `PurchaseStampDuty` or `SalesStampDutyPayable` journal lines.
- The existing positive TN credit-note timbre flow remains green, including exact `0.600` persistence and GL legs.
- Hash-path inspection confirmed the sealed input includes finalized `total`, so exclusion occurs before sealing rather than after immutable bytes exist.
- Follow-up design boundary recorded in `docs/superpowers/tickets/2026-08-10-own-named-document-total-taxes.md`.

### I — fail-closed purpose-missing alert persistence

- The missing-purpose alert remains savepoint-contained so PostgreSQL can recover from a failed statement, but the error is now rethrown to the outer projection transaction.
- A real database-trigger failure proves:
  - the projection row returns to `pending` with `attempts=1` and no `applied_at`;
  - payment and journal writes roll back;
  - no audit row is falsely assumed to exist.
- After removing the trigger, the same projection job retries to `applied`, persists the operator alert, and commits the payment and receipt journal.
- The trigger fixture runs on both SQLite and PostgreSQL syntax.

## Local commits

- `ac924200e` — `Phase 0.0.0: Recover account charge projection dead letters`
- `c5c97ec5d` — `Phase 0.0.1: Enforce stamp duty country capability`
- `0e98d9ae6` — `Phase 0.0.2: Isolate stamp duty document totals`
- `5852f516d` — `Phase 0.0.3: Fail closed on missing purpose alert writes`
- `e163a9914` — `Phase 0.0.4: Align accounting gap tests with PostgreSQL`

## TDD and revert-replay evidence

Every production fix was first observed red and then mutation-checked by temporarily reverse-applying only its production patch while retaining the committed tests.

- C reverted: both recovery-command probes failed because `--event-type` did not exist. Restored: 2 passed, 10 assertions.
- G reverted: registry failed on missing `supportsStampDuty()` and the modal failed because unsupported controls remained enabled. Restored: registry/API/modal paths passed.
- H reverted: TN signed total changed `101.000 → 108.000`; live credit note changed `50.000 → 57.000`. Restored: both probes passed with 14 assertions.
- I reverted: the forced audit failure was swallowed and the projection was acknowledged. Restored: retry/rollback/replay probe passed with 12 assertions.

## Final verification evidence

No full PHPUnit suite was run, per dispatch. The final backend matrix was limited to the nine directly affected paths.

### SQLite

```text
php artisan test \
  tests/Feature/Accounting/BackfillChartPurposesCommandTest.php \
  tests/Feature/Fiscal/RetryFiscalProjectionsCommandTest.php \
  tests/Feature/Fiscal/TaskPhase3AccountChargeFullFlowTest.php \
  tests/Unit/Taxation/CountryTaxConfigurationRegistryTest.php \
  tests/Feature/Taxation/TaxConfigurationManagementTest.php \
  tests/Feature/Taxation/TaxConfigManageSeededRoleGrantHttpTest.php \
  tests/Unit/Taxation/TaxCalculationServiceTest.php \
  tests/Feature/Document/CreditNoteMoneyLaneTest.php \
  tests/Feature/Treasury/TreasuryReceiptBridgeRoundingGlTest.php

76 passed, 446 assertions, 34.41s
```

### PostgreSQL 15.15 on 127.0.0.1:5432

Dedicated database: `autoerp_cghi_test`; user: `houssamr`.

```text
DB_HOST=127.0.0.1 DB_PORT=5432 \
DB_DATABASE=autoerp_cghi_test DB_CENTRAL_DATABASE=autoerp_cghi_test \
DB_USERNAME=houssamr DB_PASSWORD='' \
php artisan test -c phpunit-pgsql.xml <the same nine paths>

76 passed, 446 assertions, 49.33s
```

The first PG run exposed three test-fixture portability defects: a paired-null fiscal-event field, two over-length tax codes against `varchar(20)`, and PostgreSQL's required `ON audit_events` trigger-drop clause. Each root cause was corrected and the identical matrix was rerun green.

### Frontend

```text
pnpm exec vitest run \
  src/components/organisms/TaxConfigFormModal/TaxConfigFormModal.test.tsx \
  src/features/settings/hooks/__tests__/useTaxConfigurations.tenantScope.test.tsx \
  src/features/settings/TaxSettingsPage.test.tsx

3 files passed; 11 tests passed

pnpm typecheck
passed

pnpm exec eslint <changed TypeScript paths>
0 errors; 11 existing-line warnings

npx react-doctor@latest --verbose --scope changed --base dev
Score 90/100; 7 changed files scanned; no issues found
```

The React test run still prints the suite's existing `act(...)` warnings. No new React Doctor findings or ESLint errors were introduced.

### PHP formatting and static analysis

```text
./vendor/bin/pint <all touched PHP production and test paths>
passed

./vendor/bin/phpstan analyse --memory-limit=2G \
  app/Modules/Fiscal/Infrastructure/Commands/RetryFiscalProjectionsCommand.php \
  app/Modules/Taxation/Application/Registries/CountryTaxConfigurationRegistry.php \
  app/Modules/Taxation/Domain/Services/TaxCalculationService.php \
  app/Modules/Taxation/Presentation/Controllers/TaxConfigurationController.php \
  app/Modules/Treasury/Application/Projections/TreasuryReceiptBridge.php

No errors
```

An exploratory PHPStan run including entire legacy test files reported 40 existing test-analysis findings (mainly nullable collection indexing, broad array annotations, and existing pending-command union types). Production touched files are clean. PHPUnit also reports the file's pre-existing doc-comment metadata deprecation warnings.

## Residual scope

- Non-stamp document-total charges remain intentionally unavailable until the own-named money lane ticket is designed and implemented end to end.
- The dedicated local PostgreSQL database `autoerp_cghi_test` remains available for reruns; no production or shared database was dropped or rewritten.
- The branch is intentionally local and unmerged. Because `dev` advanced independently during execution, integration should merge/rebase the then-current `dev` and rerun these same path-scoped matrices.
