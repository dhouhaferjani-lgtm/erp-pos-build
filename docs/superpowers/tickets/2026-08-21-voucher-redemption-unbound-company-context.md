# Voucher redemption via the fiscal projection throws `UnboundCompanyContextException` on a Horizon worker

- **Opened:** 2026-08-21
- **Severity:** P1 — live NON-training path, breaks the whole SALE_RECEIPT projection
- **Owner lane:** treasury / accounting (GL scale resolution)
- **Status:** FIXED — `fix/c5-voucher-projection-context` (LEDGER row C-5)
- **Raised by:** LEDGER gate G-3 (`fix/g3-training-receipt-containment`), discovered
  while building the control arm of `TrainingVoucherRedemptionContainmentTest`

## Summary

Any **non-training** POS receipt paid (wholly or partly) with a store voucher
cannot be projected on a real Horizon worker. `VoucherRedemptionService::redeem()`
reaches a bare no-arg `getScale()`, which throws when no `CompanyContext` is
bound. `redeemVouchers()` has no `try`/`catch`, so the exception propagates out
of `PosCoreReceiptProjection::apply()`, rolls the whole projection transaction
back, and the job retries forever.

This is house rule 20 / audit finding F-RES-1, violated on a live path.

## Call chain (all cites in `apps/api`)

1. `ApplyFiscalEventProjectionJob` — **binds no `CompanyContext`** (verified:
   zero `CompanyContext` / `setCompanyId` occurrences in the file).
2. `PosCoreReceiptProjection::apply()` `:244` → `:493` `redeemVouchers(...)`.
   The class docblock at `:152-153` explicitly acknowledges it runs "on a queue
   worker with NO `CompanyContext` (house rule 20), where the bare no-arg
   resolver throws `UnboundCompanyContextException`".
3. `redeemVouchers()` — **no try/catch** (contrast `earnLoyaltyPoints()`, which
   is wrapped).
4. `VoucherRedemptionService::redeem()` `:81` → `:204`
   `generalLedger->createVoucherLedgerEntry(...)`.
5. `GeneralLedgerService::createVoucherLedgerEntry()` `:2611` → `:2616`
   `$this->scale()`.
6. `GeneralLedgerService::scale()` `:64-66` → `$this->scaleResolver->getScale()`
   — **bare, no currency argument**.
7. `CurrencyScaleResolver:46` → throws `UnboundCompanyContextException`.

## Reproduction

`tests/Feature/Treasury/TrainingVoucherRedemptionContainmentTest.php`, control
arm, with the `app(CompanyContext::class)->setCompanyId(...)` line removed:

```
App\Shared\Exceptions\UnboundCompanyContextException: CurrencyScaleResolver::getScale()
called with no currency code and no CompanyContext bound. ... See audit finding F-RES-1.

.../app/Shared/Infrastructure/CurrencyScaleResolver.php:46
.../app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:64
.../app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:2616
.../app/Modules/Voucher/Application/Services/VoucherRedemptionService.php:204
.../app/Modules/POS/Application/Projections/PosCoreReceiptProjection.php:1593
.../app/Modules/POS/Application/Projections/PosCoreReceiptProjection.php:493
```

That test bound `CompanyContext` in the control arm **on purpose**, with an
inline comment pointing at this ticket, so the arm could still prove the fixture
was redemption-capable.

> **Superseded — see Resolution below.** The instruction that stood here
> ("delete the bind and the note") was wrong: `setUp()` binds the context too,
> so deleting the arm's own bind alone changes nothing. The arm was instead
> routed through the `project()` helper, which clears.

## Why it was not fixed in the G-3 wave

Out of the training-containment lane, and the fix is not cosmetic:
`GeneralLedgerService::scale()` is a shared private helper, so either
`createVoucherLedgerEntry` threads an explicit currency (the class already has
`currencyCodeForCompany(string $companyId)` immediately below `scale()` at
`:68`, which is the obvious source) or every caller of `scale()` is audited.
Both have GL-wide blast radius and belong to a treasury/accounting reviewer.

## Suggested approach

- Give `createVoucherLedgerEntry()` an explicit currency — `$voucher->currency`
  is right there on the model and is the correct denomination — and resolve
  scale via `getScale($currency)` rather than the bare helper.
- Audit the other `scale()` callers in `GeneralLedgerService` for the same
  worker-reachability question.
- Regression test must run with `CompanyContext` **cleared** (rule 20).

## Severity note

This is arguably more severe than the gate G-3 closed: it affects ordinary
customer transactions, not rehearsals. Recommend it is triaged before the
first-tenant launch if store vouchers are enabled for that tenant.

---

## Resolution (2026-08-21, branch `fix/c5-voucher-projection-context`)

**Fix.** `GeneralLedgerService::createVoucherLedgerEntry()` resolves its bcmath
scale via `getScale($currencyCode)`, with the currency taken from the
`VoucherLedger` row (the denomination of the movement itself) and falling back
to `$voucher->currency`. An empty result now throws rather than limping on —
`CurrencyScale::for()` returns `DEFAULT_SCALE` for an unknown code, so an empty
string would otherwise silently pick a scale. The two posting calls at the end
of the method use the same resolved value instead of re-casting the raw column.

The same change also repairs the `RoundingAdjustment` leg
(`VoucherRedemptionService:253`), which went through the identical broken path.

**Sweep.** Every other `$this->scale()` site in the file was checked for
queued/projection reachability and found unreachable: `createFromInvoice()`
(seeder-only, binds context first), `createSupplierInvoiceJournalEntry()` (no
production caller), `clearCustomerAdvanceToReceivable()` (HTTP-only), and
`createInventoryWriteOffEntry()` (bare branch needs a null currency; both
callers pass non-null). None were changed.

**Tests.** `tests/Feature/Treasury/VoucherRedemptionProjectionWorkerContextTest`
— three arms, all with `CompanyContext` CLEARED. Mutation-verified: the original
bare `$this->scale()`, `$scale = 2`, `$scale = 3`, and a company-sourced
currency each fail at least one arm.

**G-3 control arm converted.** `TrainingVoucherRedemptionContainmentTest`'s
control arm no longer binds `CompanyContext` — note that deleting the arm's own
bind would have been a no-op, since `setUp()` binds too; it now runs through the
`project()` helper, which clears. It is therefore an independent C-5 regression
guard: with the fix reverted, that arm is the ONLY failure in the file, and it
fails with the original trace.
