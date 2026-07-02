# CODEX Report - Landed Cost Phase 1

Date: 2026-07-02
Branch: `feat/payment-landed-cost-p1`
Spec: `docs/superpowers/specs/2026-07-02-payment-document-landed-cost-design.md` Revision 2

## Spec Section Map

- §0 / §10 / §11 P0 precision prerequisite: implemented against the merged `WeightedAverageCostService::recordCostAdjustment` numeric-string signature; all new money sent as strings.
- §2 / §6 Expense spine classification: added `expense_kind` enum on `expense_metadata` with `generic|linked_cost`; added enums for linked-cost rows.
- §3.2 / §3.3 invoice-to-operation resolution: added purchase-side resolver honoring current one supplier invoice to one PO reality; server re-resolves on write and blocks invalid operation ids.
- §4.1 receipt boundary: Phase 1 checks per-line `accrual_unit_cost`; no received product lines returns `OPERATION_NOT_RECEIVED`; mixed receipt state returns `OPERATION_PARTIALLY_RECEIVED`.
- §4.6 reversal: added `POST /expenses/{id}/reverse`, reversal row, original `reversed_at`, mirror GL, WAC contra, cash inflow reversal.
- §5.2 / §5.2a post-receipt cost path: added value/quantity split, shared `ProportionalMoneyAllocator`, sold-since-receipt split capped at received quantity, WAC adjustment for on-hand, COGS for sold/null-WAC portions.
- §5.4 GR-IR guard: landed-cost summation now excludes `wac_adjustment` rows and reversed rows; no post-receipt row re-enters `LandedCostService`.
- §7 API: extended `ExpenseRequest`, `ExpenseResource`, linkable invoice/operation endpoints, reverse route.
- §8 FE: added generic/linked toggle, invoice picker, auto operation chip, no-operation downgrade action, cost type and split method fields, tenant-scoped query keys, `t()` keys in `en/fr/ar`.
- §12 TDD: added backend unit/feature tests and FE Vitest regression before implementation.
- §13 Phase 1: limited to purchase-side, post-receipt, single-currency, expense-paid fee, PO grain, with reversal. Phase 2 items left out.
- §16 disposition: implemented accepted fixes for per-line boundary, sold split, one-PO supplier invoice resolution, reversal, split-method enum, and string-money WAC call.

## Red Runs

Backend red command:

```bash
cd apps/api
DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=5433 ./vendor/bin/phpunit tests/Unit/Shared/ProportionalMoneyAllocatorTest.php tests/Feature/Expense/LinkedCostExpenseTest.php
```

Output:

```text
PHPUnit 11.5.55 by Sebastian Bergmann and contributors.

Runtime:       PHP 8.4.15
Configuration: /Users/houssamr/Projects/syneriva/apps/erp.landedcost/apps/api/phpunit.xml

..EEE 5 / 5 (100%)

There were 3 errors:

Tests\Feature\Expense\LinkedCostExpenseTest::* failed with:
SQLSTATE[08006] [7] connection to server at "127.0.0.1", port 5433 failed: Operation not permitted
Is the server running on that host and accepting TCP/IP connections?

ERRORS!
Tests: 5, Assertions: 3, Errors: 3.
```

Frontend red command:

```bash
pnpm --filter @autoerp/web test -- src/features/expenses/components/organisms/ExpenseFormFields.linkedCost.test.tsx
```

Output:

```text
FAIL src/features/expenses/components/organisms/ExpenseFormFields.linkedCost.test.tsx
ExpenseFormFields linked cost controls > submits linked purchase cost classification with resolved operation and split method
TestingLibraryElementError: Unable to find a label with the text of: expenses:form.kindLinked

Test Files 1 failed (1)
Tests 1 failed (1)
```

## Green / Final Verification

Backend DB-backed command after implementation:

```bash
cd apps/api
DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=5433 ./vendor/bin/phpunit tests/Unit/Shared/ProportionalMoneyAllocatorTest.php tests/Feature/Expense/LinkedCostExpenseTest.php
```

Output:

```text
PHPUnit 11.5.55 by Sebastian Bergmann and contributors.

Runtime:       PHP 8.4.15
Configuration: /Users/houssamr/Projects/syneriva/apps/erp.landedcost/apps/api/phpunit.xml

..EEE 5 / 5 (100%)

There were 3 errors:

Tests\Feature\Expense\LinkedCostExpenseTest::* failed with:
SQLSTATE[08006] [7] connection to server at "127.0.0.1", port 5433 failed: Operation not permitted
Is the server running on that host and accepting TCP/IP connections?

ERRORS!
Tests: 5, Assertions: 3, Errors: 3.
```

Backend non-DB unit command:

```bash
cd apps/api
./vendor/bin/phpunit tests/Unit/Shared/ProportionalMoneyAllocatorTest.php
```

Output:

```text
PHPUnit 11.5.55 by Sebastian Bergmann and contributors.

Runtime:       PHP 8.4.15
Configuration: /Users/houssamr/Projects/syneriva/apps/erp.landedcost/apps/api/phpunit.xml

..                                                                  2 / 2 (100%)

Time: 00:00.009, Memory: 20.00 MB

OK (2 tests, 3 assertions)
```

Frontend regression command:

```bash
pnpm --filter @autoerp/web test -- src/features/expenses/components/organisms/ExpenseFormFields.linkedCost.test.tsx
```

Output:

```text
✓ src/features/expenses/components/organisms/ExpenseFormFields.linkedCost.test.tsx (1 test) 141ms

Test Files 1 passed (1)
Tests 1 passed (1)
Duration 1.68s
```

Frontend typecheck:

```bash
pnpm --filter @autoerp/web typecheck
```

Output:

```text
> @autoerp/web@0.1.0 typecheck /Users/houssamr/Projects/syneriva/apps/erp.landedcost/apps/web
> tsc --noEmit

exit code 0
```

PHP syntax checks:

```text
php -l passed for changed services, controller/request/resource, providers, contracts, enums, migration, and new tests.
```

## Deviations / Notes

- The sandbox blocked PostgreSQL TCP access to `127.0.0.1:5433`, so DB-backed feature tests could not reach migrations. The tests were written first and rerun after implementation; the non-DB allocator unit test and FE regression are green.
- Phase 1 did not add a supplier-invoice-backed fee spine, fee TVA/AP/retenue, pre-receipt/mixed-path handling, sales-side profit-only flow, or multi-currency support, per §13/§14.
- The linked invoice id is not persisted as a new column; Phase 1 persists the resolved operation through `document_additional_costs.document_id` and the cost-bearing expense through `expense_document_id`, matching §6.
- No git commit was made.
