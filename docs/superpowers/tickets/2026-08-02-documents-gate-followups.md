# Ticket: documents fix-lane gate follow-ups (F2/F3 + numbering notes + 2 VAT-reporting findings)

From the documents fix-lane gate (2026-08-02, APPROVE-WITH-FIXES —
docs/superpowers/reviews/2026-08-02-documents-fixlane-gate.md). F1 blocker (stale component-test
routes) fixed in-lane; these are the ticketed remainder.

## F2 — P1: DeliveryNote + ReturnNote detail pages POST a nonexistent route

`DeliveryNoteDetailPage.tsx:60` and `ReturnNoteDetailPage.tsx:56` both POST
`/documents/{id}/confirm` — no such route exists (identical to the credit-note defect fixed in
b9653b604). Their component tests PIN the broken URL (`DetailPagesAndRepository…:273`,
`ReturnCreditNotePages…:334`). Confirm buttons on both pages can never have worked. Fix both
pages to their real module routes + flip the pinned tests + an E2E case each.

## F3 — P1 (money-display): conversion path draft totals omit document-level taxes

`CopiesDocumentData::recalculateTotals()` (Conversion/Concerns/CopiesDocumentData.php:272-298)
applies only LINE_ITEMS VAT — a converted draft shows 119.000 where confirm produces 120.000
(ConversionChainVatIntegrityTest.php:212-236 currently asserts the DIVERGENCE). Same class as the
defect 18e61a554 killed on the create path. Fix: fold `documentTaxTotal` in (same treatment),
update the test to assert draft==confirm through conversion.

## Numbering (from gate section A — non-blocking notes)

- The regression test passes by coincidence (fixture `CN-{year}-0006` vs sequence start): change
  the fixture to `-0001` and it collides at CreditNoteService.php:93. Strengthen the test to seed
  a fixture that actually exercises the takeover of an existing sequence.
- Multi-company tenant residual: `documents` unique is `(tenant_id,…)` while sequences are
  `(company_id,…)` — two companies in one tenant can still collide (pre-existing, unchanged by
  the fix). Needs a ruling: align the unique to company, or make numbering tenant-scoped.

## VAT-reporting findings (pre-existing, widened-adjacent — certification-relevant)

1. `tax_base` on every rate row is the WHOLE document subtotal (TaxCalculationService.php:154,182)
   — Σ tax_base across 3 rate rows = 900 on a 300.000 subtotal; feeds `SUM(dtd.tax_base)` in the
   VAT declaration → declared base inflated per extra rate. Must fix before any real VAT filing.
2. `snapshotTaxDetails()` never writes `is_stamp_duty` → `TunisiaVatStrategy` reports zero stamp
   duty while the declaration folds stamp in as rate-0 output VAT. Declaration composition is
   wrong on both axes for TN. Same lane as finding 1.

## Disposition

F2+F3 = next documents micro-lane (after W-2 wave completes; gate before promote). VAT-reporting
pair = own lane feeding the TN declaration correctness track (pre-launch for any tenant that
files VAT from the system). Numbering notes fold into whichever lane touches CreditNoteService
next.
