# Ticket: draft credit notes omit the 0.600 TND STAMP_CREDIT_NOTE duty + CreditNoteService formats totals at scale 4

From the W1b money-campaign defect-fix lane (2026-08-01, docs/sessions/W1B-DEFECT-FIXES-REPORT.md
"New findings" 3–4). Both live in `CreditNoteService` (apps/api, Document module).

## Finding 3 — draft credit notes omit the credit-note stamp duty

Exact analogue of the fixed invoice defect (18e61a554): `CreditNoteService` hand-rolls its totals;
`CreditNoteController::confirm()` is the first thing to run the document-tax pipeline
(`TaxCalculationService::calculateDocumentTaxes()`), so a Draft credit note's total is short by the
0.600 TND `STAMP_CREDIT_NOTE` duty until confirmation.

Deliberately not fixed in the W1b lane: the ruling named `InvoiceController::store()` only, and
changing credit-note draft totals moves the asserted values of campaign cases MTP-DOC-16/18 and
interacts with the over-credit remaining-amount guard (which sums credit notes regardless of
status). Recommended treatment: same as 18e61a554 — fold
`$taxResult->documentTaxTotal` (NOT `totalTax`, see the confirm-zeroes-VAT ticket) into the draft
write paths, then update MTP-DOC-16/18 expected values and re-check the over-credit guard.

## Finding 4 — scale-4 totals on standalone / line-based credit notes

`CreditNoteService` computes totals with `bcmul(..., 4)` / `bcadd(..., 4)` instead of the resolved
currency scale, so the API returns `60.0000` where the amount-based path returns `100.000`.
Cosmetic at the surface, but a precision-contract violation (rule 19: round ONCE at the boundary
at `CurrencyScaleResolverInterface` scale; intermediates at scale+1). The campaign specs currently
compare these values numerically only because of this. Fix alongside finding 3 (same code paths);
constructor-inject the resolver, keep `bcformatStrict` at the boundary.

## Disposition

Follow-up micro-lane after the money campaign completes; money-affecting (draft totals understate
by the stamp) but confirm() self-heals, so lower urgency than the confirm-zeroes-VAT ticket.
Update MTP-DOC-16/18 + credit-note campaign specs in the same change.
