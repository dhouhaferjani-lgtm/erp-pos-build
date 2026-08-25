# test-infra inherited-reds r2 — handback

- **Branch:** `chore/test-infra-inherited-reds-r2` · **Worktree:** `.worktrees/test-infra-r2`
- **Base:** local `dev` `274594ebf` · **Tip:** `d07c10165`
- **Scope:** TEST/FIXTURE ONLY — `git diff dev...HEAD -- apps/api/app` is **empty**. No migration, no allowlist change (both classes are already in the `backend-test-pgsql` filter).
- **Harness:** PG throwaway `autoerp_test_ti2` on `127.0.0.1:5433`, dropped after; sqlite `:memory:`. By path, one process.

| | BEFORE | AFTER |
|---|---|---|
| PostgreSQL (both classes) | **2 failed** / 27 passed (190 assertions) — I-1 ×2; I-4 green on PG as documented | **29 passed (225 assertions)** |
| SQLite (both classes) | **4 failed** / 25 passed — I-1 ×2 + I-4 ×2 | **27 passed, 2 skipped (218 assertions)** |

Gates: Pint pass · PHPStan **6 vs dev's 6 — zero new** · manifest **EXIT=0**.

**Neither item is a production defect.** Both production behaviours are correct and deliberate; the tests were asserting things their fixtures/driver could not support.

---

## I-1 — `TaskPhase3AccountChargeFullFlowTest` ×2 (both drivers)

**Symptom.** `Failed asserting that 1 is identical to 0` at `:381`, i.e. `Artisan::call('accounting:backfill-chart-purposes')` returned FAILURE.

**Root cause (production is correct).** `app/Console/Commands/BackfillChartPurposesCommand.php:387` ends with
`return $invalid === 0 ? self::SUCCESS : self::FAILURE;` — a deliberate, documented operator contract (the runbook in that class's docblock at `:106-114` gates CI on exactly these summary tokens). Captured command output shows the exit code was earned:

```
Chart purpose backfill: 4 account(s) created; 0 promoted; 0 already satisfied; 11 invalid.
CHART-PURPOSE BACKFILL UNMAPPED REQUIRED: 11
CHART-PURPOSE BACKFILL FAILURES: 11
```

…split between 8 purposes whose PARENT code was absent (`40`, `3`, `62`, `65`, `75`, `5`, `60`, `63`) and 11 REQUIRED purposes the command refuses to guess a code for (`bank`, `cash`, `supplier_payable`, `vat_deductible`, `service_revenue`, `cost_of_goods_sold`, …).

The fixture's chart was **four hand-built accounts** (`setUp()` `:129-132`: CustomerReceivable, ProductRevenue, VatCollected, SalesDiscount). So `assertSame(0, …)` was reading a **whole-chart verdict against a chart the fixture never built**. The purpose actually under test, `sales_discount` / `7097`, was created correctly on every run — the next assertion would have passed.

**Fix (fixture completed, assertion NOT weakened).** `tests/Feature/Fiscal/TaskPhase3AccountChargeFullFlowTest.php`, inside `recoverDiscountedAccountChargeAfterBackfill()` only (the helper both failing tests share; the other 6 tests in the class are untouched):
- seed the company's real TN chart with `app(ChartOfAccountsService::class)->seedForCompany($this->company)` **before** the SalesDiscount delete, so the backfill is measured against a chart an operator could actually own and the exit code means what the assertion says;
- the SalesDiscount delete still follows, so the dead-letter is still armed;
- the two parent-account inserts (`41`, `70`) became `ensureParentAccount()` — `accounts` is UNIQUE on `(company_id, code)` and the TN chart already ships both.

`assertSame(0, Artisan::call(...))` is kept verbatim. Assertion count on that test rose 7 → 23 (it used to abort at the exit-code check).

## I-4 — `CorrectingEntryEndpointTest` ×2 (sqlite only)

**Root cause (production is correct).** Both tests `expectException(QueryException::class)` from `trg_document_immutability` / `trg_prevent_fiscal_deletion`. Those triggers are created by `database/migrations/tenant/2025_12_11_054716_add_document_immutability_trigger.php`, which returns early on any non-pgsql driver **by explicit design** (`:13-16`, comment: *"Only apply triggers on PostgreSQL / SQLite doesn't support triggers in the same way"*). On SQLite the UPDATE/DELETE simply succeeds, so the expectation could never be met.

**Fix.** `tests/Feature/Document/CorrectingEntryEndpointTest.php` — new `skipUnlessPostgres()` helper citing the migration line, applied to both tests. In `test_a_posted_correction_is_sealed_and_the_database_refuses_to_mutate_its_identity` the skip is placed **after** the driver-neutral `fiscal_status === SEALED` assertion, so SQLite still pins the column that arms the triggers; only the trigger half is gated. `test_a_posted_correction_cannot_be_deleted_at_the_database_level` is wholly trigger-dependent and skips early.

Both classes are already named in the `backend-test-pgsql` `--filter`, so the PG-only halves keep running in the live gate.

## Residual — not touched

**R2-N1 `OpeningCashFloatSeedsRepositoryTest::test_a_debit_on_an_account_with_no_repository_is_not_examined`** (consolidation §4, the *blocking* red) is **out of this lane's scope** and untouched. The consolidation attributes it to W4-3 `2b471d61c` / `7281c9121` with a production stack (`AccountingOpeningService.php:725` ← `:621`), i.e. it is the one item that may need a production ruling rather than a fixture change.
