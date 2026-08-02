# Ticket: 3 P1 treasury defects from the W2b money-campaign leg (partial-refund allocation, withholding 500, instrument-cancel deadlock)

From the W2b treasury campaign leg (2026-08-02, docs/sessions/MONEY-CAMPAIGN-RESULTS.md
"## W2b — treasury"). All three proven live against demo-pharmacy-tn; live repros are pinned in
apps/web/e2e/money-campaign/{treasury-payments,treasury-instruments}.spec.ts. None are on the
pre-registered expected-FAIL list.

## 1 — Partial refund does not reopen the settled invoice (MTP-TRE-10)

`PaymentRefundService::partialRefund()`
(apps/api/app/Modules/Treasury/Domain/Services/PaymentRefundService.php:214-320) reverses the
GL/cash leg but never touches `PaymentAllocation` / the document's `balance_due`, unlike the
full-refund path. A settled invoice stays settled after money has left it — receivables
understated. Fix direction: mirror the full-refund allocation unwind pro-rata for the refunded
amount.

## 2 — `withholding_rate` 500s instead of validating (MTP-TRE-15)

`PaymentController::store()` (~L886) passes the validated numeric-STRING into
`WithholdingCertificateService::createFromPayment()`'s `?float` parameter under `strict_types=1`
→ uncaught `TypeError` → bare 500 where a 422 belongs. Fix direction per precision contract
(rule 19): the service should take the string (bcmath domain), not `?float` — do NOT "fix" by
casting to float at the call site.

## 3 — Circular precondition deadlock: cancelling a `received` instrument vs reversing its Completed payment (MTP-TRE-23)

`InstrumentLifecycleService::cancel()` (InstrumentLifecycleService.php:605-617) requires the
payment already `Reversed`; `PaymentRefundService::assertInstrumentSettledForCashUndo()`
(PaymentRefundService.php:581-591 — used by reverse, refund, AND partial-refund) requires the
instrument already cancelled-or-cleared. Neither side can go first — proven live in BOTH
directions (two-sided repro pinned in the MTP-TRE-23 test). Consequence: any cheque/effet payment
whose instrument has not cleared can NEVER be undone via the API. Fix direction needs a ruling:
either an atomic cancel-with-reversal operation or one side's precondition relaxed to
"cancellable in the same transaction".

## Disposition

Fix lane dispatched by the money-campaign orchestrator (2026-08-02) with a mandatory
treasury-reviewer adversarial gate before merge. Campaign specs already assert current (defective)
behaviour as tripwires where applicable — flip them to assert fixed behaviour in the same lane.
