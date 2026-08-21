# Voucher redemption via the fiscal projection throws `UnboundCompanyContextException` on a Horizon worker

- **Opened:** 2026-08-21
- **Severity:** P1 — live NON-training path, breaks the whole SALE_RECEIPT projection
- **Owner lane:** treasury / accounting (GL scale resolution)
- **Status:** OPEN — deliberately not fixed in the G-3 wave
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

That test currently binds `CompanyContext` in the control arm **on purpose**, with
an inline comment pointing at this ticket, so the arm can still prove the fixture
is redemption-capable. **When this ticket lands, delete the bind and the note.**

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
