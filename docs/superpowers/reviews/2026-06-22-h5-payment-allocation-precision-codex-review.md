# H-5 Payment Allocation Precision — Codex Adversarial Review

Date: 2026-06-22  
Scope reviewed: `payment_allocations.amount` migration, `PaymentAllocation` cast, smart-payment validation, allocation preview/result formatting, and allocation/refund test updates.  
Review mode: refute the patch against schema safety, validation drift, tolerance precision, API output consistency, and regression coverage.

## Verdict

PASS.

## Findings

### No BLOCKER/HIGH findings

The schema change is additive widening for PostgreSQL: `amount` moves from scale 2 to scale 3. Existing values are padded, not rounded down. The down migration restores the old shape for rollback.

The model cast now matches the money contract at `decimal:3`, and a regression test proves a `12.345` allocation no longer reads as `12.3450`. A PostgreSQL-only schema assertion checks `numeric_precision = 15` and `numeric_scale = 3`.

Smart-payment allocation ingress now rejects 4-decimal money. The service still uses scale 4 internally where tolerance math needs it, but allocation money and total/excess outputs are formatted back to currency scale. `tolerance_writeoff` remains `decimal:4` and its tests still assert four decimals.

### LOW — Historical SQLite raw storage can still contain four-decimal strings

Status: ACCEPTED.

SQLite does not enforce numeric scale, and Eloquent does not rewrite all historical raw values. The contract is enforced at validation, casts, service output, and PostgreSQL schema. Tests that need persisted display semantics read through the model cast.

## Verification Reviewed

- Red: `php artisan test tests/Feature/Treasury/PaymentAllocationPrecisionTest.php` failed on `12.3450` vs `12.345`.
- Red: `php artisan test --filter it_rejects_manual_allocation_amount_above_currency_scale` failed because `99.9999` returned 200.
- Green: real PostgreSQL precision test passed against isolated `autoerp_h5_test`, including `information_schema.columns`.
- Green: focused Treasury/Fiscal allocation set passed 67 tests, 1 PostgreSQL-only skip, 298 assertions.
- Green: adjacent payment/document allocation set passed 48 tests, 6 PostgreSQL-only skips, 147 assertions.
- Green: `PartnerBalanceServiceTest` passed 18 tests, 35 assertions.
- PHPStan L8, Pint, and `git diff --check` passed.

## Residual Risk

The preview service formats totals using the company currency while individual allocation lines use invoice currency. That matches the current single-company smart-payment contract. A future multi-currency allocation policy should make payment currency explicit at the API boundary instead of inferring it.
