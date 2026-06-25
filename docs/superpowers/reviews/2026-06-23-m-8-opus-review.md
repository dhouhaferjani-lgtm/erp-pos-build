# M-8 Opus Adversarial Review — Customer Advance Clearing Cap

Item: M-8 (Uncapped advance clearing)
Commit: feb51c33a82d43b7a9d6cc1d53e21942e5ae5956
Reviewer: Opus (true cross-model adversarial pass; supersedes the `opus-fallback` note)
Date: 2026-06-23

## Summary

The cap logic in `GeneralLedgerService::clearCustomerAdvanceToReceivable()` is
fiscally sound and the bcmath/precision discipline is correct: no float touches
money, the magnitude convention (`0 - balance` for a credit-normal advance) is
right, the draft-clearing subtraction prevents double-reservation, and the
partner row lock plus single-transaction wrap are reasonable. The four tests are
genuine red-first checks (over-cap, zero-amount, exact-available, draft-reuse)
and pass; PHPStan L8 on the two changed files is clean; the existing converter
scenario test (40 advance / 40 clearing) still passes.

The material concern is a behavioral regression at the only production caller:
the converter degrades gracefully on GL failure by catching `\RuntimeException`,
but the new guards throw `\InvalidArgumentException` (a `LogicException`, NOT a
`RuntimeException`). A cap/positive-amount violation therefore propagates
uncaught and rolls back the entire SalesOrder->Invoice conversion instead of
degrading. In the common flow advances are pre-posted so the cap holds, but
partial/repeated conversions or same-transaction advance posting can trip it,
and there is no converter-level test for that path. This is a HIGH, not a
blocker, because the happy path is verified and the rollback is safe (no data
corruption, no leaked events).

## BLOCKER

None. No fiscal/money math is wrong; double-entry stays balanced (debit advance,
credit AR, equal amounts); partner_id is tagged on both subledger lines; no float
on money; no unsafe migration (this commit ships no migration); no event
immutability violation (the `JournalEntryPosted` event and hash chain are
untouched).

## HIGH

### H-1 — New guard throws `InvalidArgumentException`, which the sole caller's graceful-degradation `catch (\RuntimeException)` does not catch
`SalesOrderToInvoiceConverter::transferPrepayments()` wraps the clearing call to
keep GL problems from breaking conversion:

`apps/api/app/Modules/Document/Domain/Services/Conversion/Converters/SalesOrderToInvoiceConverter.php:399-431`
```php
$this->glService->clearCustomerAdvanceToReceivable(... $totalPrepaid ...);
} catch (\RuntimeException $e) {
    // log warning, set payload gl_entry_skipped, do not fail conversion
}
```
The new guards throw `\InvalidArgumentException`
(`GeneralLedgerService.php:826` and `:832`), and
`(new InvalidArgumentException) instanceof RuntimeException === false` (verified).
The whole `convert()` body runs inside `DB::transaction`
(`SalesOrderToInvoiceConverter.php:167`), so an uncaught throw rolls back the
entire invoice conversion rather than skipping only the GL clearing.

Why it can fire in practice:
- `transferPrepayments()` sums ALL `PaymentAllocation` for the order
  (`:354`, `:401`) and clears `$totalPrepaid` in one call. For a partial
  conversion that produces multiple invoices, each invoice attempts a clearing;
  the first (draft) clearing reduces available advance
  (`availableCustomerAdvanceMagnitude` subtracts draft `prepayment_application`
  debits, `GeneralLedgerService.php:899-908`), so a later legitimate clearing
  can be capped and now hard-fails the conversion.
- The customer-advance JE is posted via `DB::afterCommit`
  (`postEntryAndDispatchPostedEventAfterCommit`, `GeneralLedgerService.php:...`).
  The cap reads only `status = 'posted'` advance balance
  (`PartnerBalanceService::getPartnerBalance` filters `status = 'posted'`,
  `PartnerBalanceService.php:46`). If an advance is created and the order is
  converted within the same outer transaction, the advance is still unposted
  when the cap reads it -> available `0` -> `InvalidArgumentException` ->
  conversion rollback.

Recommendation: either throw a `RuntimeException` subclass (so the existing
graceful-degradation path catches it and records `gl_entry_skipped`), or update
the converter `catch` to also handle the cap exception, or add a `?string`
override/clamp so the converter can never hard-fail on a benign over-cap. Add a
converter-level test exercising over-cap and confirming the conversion either
degrades or fails by intent — currently neither outcome is tested.

## MEDIUM

### M-1 — Concurrency claim is unverified on the only engine where it matters
The `lockForUpdate()` partner row lock (`GeneralLedgerService.php:766-770`) is a
no-op on SQLite, where all feature tests run. The "same-partner clearings
serialize on PostgreSQL" claim in the work-list Outcome and both prior reviews is
therefore asserted, not demonstrated. [NEEDS-REAL-PG] Add a real-PG concurrency
test (or at minimum a documented manual check) before treating the race
protection as proven. The lock scopes only the partner row; the
`availableCustomerAdvanceMagnitude` reads of `journal_lines`/`journal_entries`
are unlocked, which is acceptable *only because* every clearing for a partner
serializes behind that single row lock — that invariant should be stated in a
comment so a future refactor does not break it.

### M-2 — New no-arg `getScale()` dependency introduced into a path that previously had none
The pre-commit method made zero precision/scale calls; this commit adds four
`$this->scale()` (no-arg `getScale()`) calls inside the transaction
(`GeneralLedgerService.php:824,826,829,831`). `getScale()` with no currency
throws `UnboundCompanyContextException` when no `CompanyContext` is bound
(`CurrencyScaleResolver.php:42-52`). The method already receives an explicit
`?string $currencyCode`; the guards could have used `getScale($currencyCode)` to
stay safe in queued/console contexts. The sole caller is request-scoped today, so
this is not currently broken, but it narrows the method's callable contexts
silently. Prefer `getScale($currencyCode)` (falling back to a safe default) for
the validation arithmetic.

### M-3 — "Cap unless explicit override exists" acceptance criterion only half-met
The work-list acceptance criterion reads: "Clearing amount cannot exceed
available customer advance unless an explicit override path exists." The
implementation provides the cap but NO override path, and the caller cannot opt
out. Combined with H-1 this means a benign over-cap has no escape hatch and
becomes a conversion-blocking error. Either document that no override is needed,
or add one as the criterion anticipates.

## LOW

### L-1 — Dual-review gate not genuinely satisfied at merge time
Both shipped reviews (`...-codex-review.md`, `...-opus-fallback-review.md`)
report "No BLOCKER/HIGH/MEDIUM findings"; the fallback explicitly states Opus was
unavailable and `opus-review: PENDING`. The item was marked DONE and merged on
Codex-only review. H-1 above shows the fallback pass missed a cross-file caller
interaction, which is exactly what a true cross-model pass is for. Process nit,
not code.

### L-2 — `selectRaw('CAST(... AS TEXT)')` portability
`GeneralLedgerService.php:907` uses `CAST(COALESCE(SUM(...),0) AS TEXT)`. Valid on
both PG and SQLite, so functionally fine, but on SQLite `SUM` over decimal-as-TEXT
columns can yield a float-formatted string; the surrounding `bcsub` tolerates it.
No action required; noted for awareness.

### L-3 — Cross-module model reliance deepened (pre-existing)
The method now also reads/locks `App\Modules\Partner\Domain\Partner` directly
from the Accounting module (`GeneralLedgerService.php:766`). The import predates
this commit, so this is not a new boundary violation, but M-8 leans on it more.
Long-term this should go through a Partner contract/service.

## Verdict

APPROVE-WITH-MINOR-EDITS

The fiscal core, precision discipline, sign convention, and tests hold up under
refutation. The cap is correct and the happy path is verified green. The one
substantive issue (H-1) is a robustness regression at the caller boundary, not a
money/data-integrity defect, and it is fixable with a one-line exception-type
change plus a converter-level test. Recommend addressing H-1 and M-1 before the
owner closes the dual-review gate.
