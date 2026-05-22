# Task 8 Codex Self-Adversarial Review — Treasury Account Charge Bridge

Date: 2026-05-22
Commits reviewed:
- `522b049e8 Phase 3.8.1: Bridge account charges to AR`
- `167638db8 Phase 3.8.2: Validate charge replay journal lines`
Scope: Phase 3 Task 8 from `docs/superpowers/plans/2026-05-21-pos-charge-to-account-phase3.md`.

## Verdict

APPROVE.

No BLOCKER or REQUEST-CHANGES remains after the pre-review correction and R2 replay-line fix below.

## Pre-review correction

Before writing this review, I found one real plan-coverage gap: Task 8 explicitly required a discounted charge regression proving `SystemAccountPurpose::SalesDiscount` receives the balancing debit. The first implementation relied on Task 7 service coverage plus the bridge happy path. I added `test_discounted_charge_posts_sales_discount_line`, reran focused and broad verification, and amended the implementation commit before this review.

## R2 correction after Opus REQUEST-CHANGES

Opus R1 found a valid fail-loud/idempotency defect: the replay path accepted a single existing `pos_account_charge` journal entry after validating only header fields. A header-matching but line-corrupt entry could therefore mark the projector as applied without the required AR line shape.

R2 fixes this in `167638db8`:

- `existingJournalEntryForEvent()` now scopes the replay probe by `tenant_id` and `company_id` and eager-loads `lines.account`.
- `assertExistingJournalEntryMatches()` now validates both header and required line shape before returning idempotently.
- The expected replay line set is derived from the sealed canonical view: CustomerReceivable debit for `totals.total` with resolved partner, ProductRevenue credit for `totals.subtotal`, VatCollected credit only when VAT is positive, and SalesDiscount debit only when discount is positive.
- A new regression seeds a header-matching entry with no lines and asserts `idempotency_conflict:line_count`, one existing journal entry, and no journal lines.

## Requirements checked

- Bridge identity: `name()` returns `treasury_account_charge_bridge`; `handlesEventType()` accepts only `ACCOUNT_CHARGE`; `requiresModule()` returns `Treasury`; `priority()` is `150`.
- Live registration: `TreasuryServiceProvider` tags `TreasuryAccountChargeBridge::class` as a `FiscalEventProjector`.
- PG CI gate: `.github/workflows/ci.yml` adds `TreasuryAccountChargeBridgeTest` to the PG fiscal filter with a task-specific citation in the same implementation commit.
- Transaction and idempotency: `apply()` wraps work in `DB::transaction()`, takes the PG advisory lock on `$event->id.':treasury_account_charge_bridge'`, checks tenant/company-scoped `source_type='pos_account_charge'` + `source_id=$event->id`, and fails on duplicate/conflicting existing entries, including line-shape conflicts.
- Canonical source: the command is built from `CanonicalPayloadReader::forAccountCharge($event)`; `vatBreakdown` receives `$view->vatBreakdown`, and `lineVatSummary` receives `$view->lineItems`.
- AR shape: the bridge calls `GeneralLedgerService::createPOSChargeEntry()` and never creates Treasury `payments`, POS receipt payments, or calls the POS payment path.
- Retry safety: `GeneralLedgerService::createPOSChargeEntry()` now refreshes partner balance inside the same DB transaction before returning. This is deliberate: a partner-balance refresh failure must roll back the just-created journal entry, otherwise a retry would see the idempotency row and silently skip the stale-balance refresh.

## Standing-pattern attack vectors

- Cross-tenant FK safety: customer alias lookup is scoped by `tenant_id`, `company_id`, and `client_customer_uuid`; the foreign-company alias probe is diagnostic only and throws `customer_alias_cross_company`. Final `Partner` lookup is scoped by `tenant_id`, `company_id`, and customer-compatible type. The test suite covers synced cross-company rejection and pending alias resolution.
- Fail-loud vs silent downgrade: unsupported customer sync status, missing customer, cross-company alias, conflicting existing journal entry, replay line-shape drift, and non-numeric money fields throw projection exceptions. Partner balance refresh failures bubble and roll back the journal entry/lines.
- Dead-path rebuild: the bridge is live through `TreasuryServiceProvider` and the test asserts it appears in the tagged projector list.
- Discriminated-union/test matrix: Task 8 has no TS discriminated-union wrapper. The PHP projection matrix covers happy path, idempotency, conflict, pending alias, cross-company customer, no payment rows, discounted charge, VAT/line summary handoff, balance-refresh rollback, and projector contract/registration.
- Contract drift: bridge behavior matches Phase 3 spec and plan: ACCOUNT_CHARGE only, Treasury-gated, AR GL posting by fiscal event id, no payment rows, accounting command receives canonical line/VAT details. The PG CI comment matches the actual test and bridge dependencies.
- Constructor injection: production code uses constructor injection for `CanonicalPayloadReader` and `GeneralLedgerService`. No `app()`, `App::make`, or `resolve()` calls were introduced.
- D16 bounded-modules guard: Fiscal/POS core is untouched. The operational AR dependency lives in `App\Modules\Treasury\Application\Projections`, behind `requiresModule(): 'Treasury'`, consistent with the existing AccountPayment bridge seam.
- R2-fix caution: the Opus-requested R2 fix changes production replay validation, so focused and full gates were rerun after the fix. I also checked that optional VAT/discount lines are conditional on the sealed canonical numeric amounts and that `line_count` catches unexpected extra/missing lines.
- Per-method skip rule: no skipped tests were added.
- Skip-citation accuracy: no skip citation was added.
- PG primitive guard: the advisory lock is PG-only via `DB::getDriverName() === 'pgsql'`, matching the Phase 1 standing pattern for projector-level idempotency locks.

## Verification evidence

- RED before implementation: `APP_KEY=... ./vendor/bin/phpunit tests/Feature/Fiscal/TreasuryAccountChargeBridgeTest.php` failed because `TreasuryAccountChargeBridge` did not exist.
- Focused GREEN after final correction: `APP_KEY=... ./vendor/bin/phpunit tests/Feature/Fiscal/TreasuryAccountChargeBridgeTest.php` — 11 tests, 47 assertions.
- Focused PHPStan: `APP_KEY=... ./vendor/bin/phpstan analyse --level=8 app/Modules/Treasury app/Modules/Accounting app/Modules/Fiscal tests/Feature/Fiscal/TreasuryAccountChargeBridgeTest.php --memory-limit=1G` — no errors.
- Backend suite: `APP_KEY=... ./vendor/bin/phpunit tests/Feature/Fiscal/ tests/Unit/Fiscal/ tests/Feature/POS/ tests/Feature/Accounting/POSAccountChargeJournalEntryTest.php` — 1201 tests, 4144 assertions, 107 skipped, 2 incomplete, 16 PHPUnit deprecations.
- Full PHPStan: `APP_KEY=... ./vendor/bin/phpstan analyse --level=8 --memory-limit=1G` — no errors across 1861 files.
- Pint: `./vendor/bin/pint --test app/Modules/Treasury/Application/Projections/TreasuryAccountChargeBridge.php app/Modules/Treasury/Providers/TreasuryServiceProvider.php app/Modules/Accounting/Domain/Services/GeneralLedgerService.php tests/Feature/Fiscal/TreasuryAccountChargeBridgeTest.php` — pass.
- POS: `pnpm test && pnpm typecheck && pnpm lint` in `apps/pos` — 168 test files / 1500 tests passed; typecheck passed; lint exited 0 with the existing 41 warnings.
- Chokepoint gate: `bash scripts/check-saleReceipt-chokepoints.sh` — manifest receiver_type validator PASS, §14.3 chokepoint gate PASS.
- Deptrac ratchet: `php tools/deptrac-ratchet.php --config=deptrac.yaml --baseline=deptrac.baseline.json` still fails on pre-existing/current-tree `SharedContracts -> ModuleDomain` growth (`AccountingServiceInterface` and `FiscalEventProjector`). Task 8 did not increase `ModuleDomain -> ModuleApplication` or introduce a new Task-owned deptrac category growth.

## Residual risk

The bridge follows the existing Treasury AccountPayment projection pattern and depends on `GeneralLedgerService::createPOSChargeEntry()` for account-resolution and balanced-entry enforcement. The Task 8 test suite covers the bridge contract and the AR posting shape, but it does not simulate true concurrent PG workers; the advisory-lock primitive is covered structurally and through the PG CI sentinel rather than a parallel integration test.
