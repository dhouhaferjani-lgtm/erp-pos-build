# H-5 — Treasury Allocation Precision Mismatch — Opus Adversarial Review

Date: 2026-06-23
Reviewer: Opus 4.8 (adversarial, cross-model)
Commit: `6c366093d` ("Phase 0.1.16: Align payment allocation precision")
Item: H-5 (work-list `docs/superpowers/audits/2026-06-22-balance-conventions-audit/work-list.md`)

## Summary

The commit correctly closes a real, verified gap: the `2026_03_11_200000_widen_monetary_columns_to_scale_3` migration widened every sibling treasury money column (`payments.amount`, `payment_instruments.amount`, `payment_repositories.balance`, `credit_note_allocations.amount`) to `NUMERIC(15,3)` but **omitted `payment_allocations.amount`**, which stayed `NUMERIC(15,2)` while the Eloquent cast claimed `decimal:4`. This commit adds a PG migration to widen the column to `(15,3)`, fixes the cast to `decimal:3`, tightens `SmartPaymentController` validation from 4→3 decimals (matching what `PaymentController` already did), and formats preview/result money to currency scale. The money-contract direction is correct, no float touches money, `CurrencyScaleResolverInterface` is constructor-injected, tolerance writeoff is intentionally left at scale 4, and refund-negative allocations remain representable. The implementation holds up under refutation. The defects are about verification durability (the meaningful schema test never runs in CI) and a non-strict edge case, not fiscal correctness.

## BLOCKER

None.

- Migration direction is correct and matches the established sibling pattern; `down()` reverts to the genuine original `(15,2)` (`2025_11_30_120000_create_treasury_tables.php:174` confirms original was `decimal(15,2)`).
- No `(float)` cast or `number_format((float)...)` on money. `formatMoney()` uses `bcadd($amount, '0', scale)` (truncating per the "bcformat truncates" contract), and in practice both inputs (validated ≤3dp manual amounts, 3dp document balances) are already at scale so no silent truncation drift occurs.
- GL amounts (`createPaymentReceivedJournalEntry`, `createCustomerAdvanceJournalEntry`) are re-summed from the same formatted allocation amounts at `$this->scale($payment->currency)`, so the ledger ties to the persisted allocation rows.

## HIGH

### H1 — The only test that actually proves the migration never runs in CI

`PaymentAllocationPrecisionTest` lives in `tests/Feature/` and the schema assertion is the one with teeth:

```
tests/Feature/Treasury/PaymentAllocationPrecisionTest.php:39
    if (DB::connection()->getDriverName() !== 'pgsql') {
        $this->markTestSkipped('information_schema.columns is Postgres-specific');
    }
```

Per the project's own CI memory, CI runs only `--testsuite=Unit` on SQLite plus a hardcoded PG allowlist; `tests/Feature` is never executed in CI. So:
- `test_payment_allocations_amount_column_is_decimal_15_3_on_postgresql` is **skipped on SQLite and not run in CI at all** — zero automated regression protection for the actual `(15,3)` schema claim. If a future migration or table-recreate regresses the scale, nothing catches it.
- `test_payment_allocation_amount_cast_preserves_currency_scale_3` (the SQLite-runnable one) only exercises the Eloquent `decimal:3` cast formatting; because the migration `return`s early on SQLite, this test would pass identically whether or not the PG migration exists. It does not prove the migration.

The real-PG run was performed manually and documented in the work-list, which is acceptable for a one-time check, but the durable guard is missing. Recommend adding the PG schema assertion to the CI PG allowlist (the same approach used elsewhere) or moving it into a suite CI executes against Postgres.

## MEDIUM

### M1 — `formatMoney` silently falls back to scale 2 on empty/unknown currency

`formatMoney($amount, (string) $invoice->currency)` resolves scale via `CurrencyScaleResolver::getScale($code)`, which for a non-null code calls `CurrencyScale::for($code)`:

```
app/Shared/Domain/CurrencyScale.php:64
    return self::SCALE_MAP[strtoupper($currencyCode)] ?? self::DEFAULT_SCALE; // DEFAULT_SCALE = 2
```

If `$invoice->currency` is ever empty string (the `(string)` cast turns null into `''`, which is `!== null`, so it does NOT throw the unbound exception — it falls through to `CurrencyScale::for('')` = **2**), preview/result amounts would be truncated to 2 decimals rather than 3, silently undercutting the very contract this item enforces. Documents normally carry a currency, so this is `[HYPOTHESIS]` for the production path, but the code accepts the degraded case without asserting. A guard (or routing empty→company currency) would make the intent explicit.

### M2 — `down()` is lossy and unguarded; no idempotency guard on `up()`

`down()` runs `ALTER COLUMN amount TYPE NUMERIC(15,2)`, which on a tenant DB that already stored 3-decimal allocation amounts would round/truncate persisted fiscal data with no warning. `up()` has no "already (15,3)" guard, so re-running rewrites the table. For db-per-tenant single-run migrations this is tolerable, but the rollback path is a data-loss footgun if ever invoked on populated tenant DBs. Consider documenting `down()` as destructive or making it a no-op.

### M3 — Preview vs persistence currency basis is asymmetric

In `previewAutoAllocation`/`previewManualAllocation`, per-line `amount`/`original_balance` are formatted with `$invoice->currency`, while `total_to_invoices`/`excess_amount` use `$companyCurrency`. In a single-currency-per-company system these coincide, but the asymmetry is latent: a future multi-currency invoice would format line items and totals at different scales within one response. Not a defect today; flag for the multi-currency epic.

## LOW

### L1 — SQLite stores the raw value; cast does the work

On SQLite the column keeps NUMERIC affinity (migration skipped) and the `decimal:3` cast formats on read. This is the documented "legacy raw SQLite storage" residual and is acceptable, but it means the SQLite test proves only the cast, reinforcing H1.

### L2 — `formatMoney` carries a blanket `@phpstan-ignore-next-line argument.type`

`app/.../PaymentAllocationService.php:45` suppresses the bcadd arg-type error rather than narrowing `$amount` to `numeric-string`. Matches an existing pattern in the file, but the ignore hides any future genuinely-wrong-typed caller.

## Verdict

APPROVE-WITH-MINOR-EDITS

The fiscal/money substance is correct and the root-cause gap (March widen migration skipped `payment_allocations`) is genuinely closed. No BLOCKER. The single HIGH (H1) is a verification-durability gap, not a correctness defect: the schema test that proves the claim is parked in a suite CI never runs, so the `(15,3)` guarantee rests on a one-time manual PG run rather than an enforced regression guard. Promote the PG schema assertion into a CI-executed Postgres lane (or the existing PG allowlist) and the item is fully solid; M1/M2 are worthwhile hardening for the currency edge case and the lossy rollback.
