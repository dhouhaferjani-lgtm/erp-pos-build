# Supplier import finalization currency-context handback

**Status:** ROOT ACCEPTED — SCOPED VERIFICATION COMPLETE

**Branch:** `codex/parties-finalize-currency-20260912`  
**Worktree:** `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/parties-finalize-currency-20260912`  
**Source pin:** `15de4aba48bd0154dab150c4310a74d93b4693a5`

## Defect and fix

The queued path is `ProcessImportJob::handle()` → `ImportService::finalizeImport()` → `PartiesBalancesPhase::run()` → `ArApOpeningService::validateBatch()` / `postBatch()`. `BindsTenantContext` selects the tenant database but intentionally does not bind `CompanyContext`. `ArApOpeningService` previously called `CurrencyScaleResolver::getScale()` without a currency, so supplier imports could apply all rows and then fail during AR/AP finalization.

`ArApOpeningService` now derives a required currency from the authoritative opening batch/company for validation, posting, and preview. Its money scale is `max(getScale(explicit currency), 3)`, preserving the opening subsystem's existing fixed three-decimal storage contract. Preview uses the same company currency as its fallback instead of a hard-coded TND value. No ambient context, global binding, guessed currency, schema, status, import-flow, or history/export behavior changed.

## Tracked files

- `apps/api/app/Modules/Document/Application/Services/ArApOpeningService.php`
- `apps/api/tests/Feature/Import/PartiesImportBalancesTest.php`
- `docs/handoff/HANDBACK-parties-finalize-currency-2026-09-12.md`

The implementation files do not overlap IMP1 commit `4300d9ff3`; that work changes import job/status and history/export surfaces. No other active worktree contained edits to either implementation file when this branch was created.

## Test evidence

Autoload was isolated by cloning the existing vendor tree inside this worktree. `ReflectionClass::getFileName()` resolved both `ArApOpeningService` and `PartiesImportBalancesTest` to this worktree. The root dependency tree and generated autoload metadata were not changed.

The ignored file `apps/api/storage/logs/parties-finalize-currency-red.log` is a manually preserved **red-run evidence summary**, not verbatim raw output. The exact command was:

```text
php artisan test tests/Feature/Import/PartiesImportBalancesTest.php --filter='test_queued_tnd_supplier_balance_uses_target_company_currency_without_company_context|test_parties_import_posts_ar_and_ap_opening_balance_batches'
```

Before the production change, it failed in both intended ways: the EUR case stored `80.120` instead of `80.125`, and the queued TND case raised `UnboundCompanyContextException` from the no-argument scale call.

After the fix:

- `PartiesImportBalancesTest.php`: **5 passed, 54 assertions**. The new regression creates and validates a TND company-B job while EUR company A is bound, clears `CompanyContext`, invokes the real `ProcessImportJob::handle()`, and checks exact TND document, partner payable, Posted journal, balanced two-line GL, and company isolation. Re-running the supported finalization phase leaves partner, historical document, opening batch, journal, and line cardinalities unchanged. This proves no-context finalization; it does not claim a stale company remained bound during posting.
- `ArApOpeningPostLifecycleTest.php` + `ArApOpeningLedgerTest.php`: **16 passed, 56 assertions**.
- Relevant `OpeningBalancePreviewContractTest.php` cases: **2 passed, 12 assertions** (`AR open items` and the shared batch discriminator).
- Pint on the two PHP files: **pass**.
- PHPStan on the two PHP files: **no errors**.

The combined compatibility command over `ArApOpeningPostLifecycleTest.php`, `ArApOpeningLedgerTest.php`, and the full `OpeningBalancePreviewContractTest.php` produced 19 passes and one inventory assertion failure because its expected key list omits existing expiry fields. A separate archive of the unchanged source pin, with its own cloned autoload tree, reproduced that exact result in the full preview class: **3 passed, 1 failed, 28 assertions**. `ReflectionClass` confirmed both loaded classes came from `/private/tmp/parties-finalize-base-preview-15de4aba-20260912`; raw output is in the ignored `apps/api/storage/logs/parties-finalize-currency-base-preview.log`. This proves the inventory failure exists at the base pin. No inventory source or test was changed.

The required scoped preflight exited **0** and ended with `All preflight checks passed`. Its exact invocation was:

```text
PREFLIGHT_SCOPE=paths PREFLIGHT_TEST_PATHS='tests/Feature/Import/PartiesImportBalancesTest.php tests/Feature/Document/ArApOpeningPostLifecycleTest.php tests/Feature/Document/ArApOpeningLedgerTest.php' PREFLIGHT_PINT_PATHS='app/Modules/Document/Application/Services/ArApOpeningService.php tests/Feature/Import/PartiesImportBalancesTest.php' PREFLIGHT_PHPSTAN_PATHS='app/Modules/Document/Application/Services/ArApOpeningService.php tests/Feature/Import/PartiesImportBalancesTest.php' PREFLIGHT_VITEST_PATHS='src/lib/decimal.test.ts' ./scripts/preflight.sh
```

It passed the scoped backend selection (**21 tests, 110 assertions**), generated-artifact drift checks, web and POS typechecks, ESLint and audits, architecture/liveness guards, the bounded decimal Vitest selection (**30 tests**), fiscal parity (**29 tests**), and the fiscal chokepoint gate. Error text printed by detector-liveness negative fixtures is expected test input; those suites passed. Raw output is in ignored `apps/api/storage/logs/parties-finalize-currency-preflight.log`.

These are SQLite integration results; PostgreSQL, a live queue worker, staging, and full-suite CI were not run.

Green logs are ignored under `apps/api/storage/logs/parties-finalize-currency-*.log`. The private empty `.env`, cloned `vendor`, Laravel cache files, and PHPUnit cache are also ignored worktree-only test support.

## IMP1 campaign refresh

The original IMP1 staging artifact for the supplier fixture that reached 200/200 rows and then failed finalization must be retained. This fix does not retroactively repair amounts already mapped into an opening batch or any sealed batch. Recovery of the existing failed supplier job therefore requires a separately reviewed, scoped operation after deployment; do not patch balances or statuses with SQL.

Run a fresh supplier job as the later success campaign. The held IMP1 partial-failure/status proof remains valid because `ProcessImportJobStatusTest.php` uses one imported row and passes a controlled `RuntimeException` to `failed()` while checking persisted counters; it does not depend on preserving this currency defect. No staging data was read or written in this task.
