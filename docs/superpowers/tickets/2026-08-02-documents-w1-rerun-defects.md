# Ticket: 3 documents-chain defects from the W-1 reconciliation re-run (credit-note numbering collision, wrong FE confirm/post routes, conversion VAT-zeroing)

From the W-1 reconciliation re-run (2026-08-02, docs/sessions/MONEY-CAMPAIGN-RESULTS.md
§"W-1 reconciliation re-run"). Documents leg 16P/5F — all 5 fails trace to these three. Evidence +
live repros in the ledger section and inline spec comments.

## 1 — P0: credit-note numbering collision (blocks ALL credit-note creation once triggered)

`CreditNoteService::generateCreditNoteNumber()`'s regex misparses the newer `CN-{year}-{seq}`
format; with one legacy-format row present (a prior session authored one), the generator
permanently re-issues a colliding number → unique violation → every subsequent credit-note create
fails. Blocks all 5 documents-leg failures. Fix: parse BOTH formats (or scope the MAX query to the
current format properly); add a regression test seeding one row of each format.

## 2 — P0 candidate: CreditNoteDetailPage Confirm/Post hit nonexistent routes

`CreditNoteDetailPage.tsx` calls `/documents/{id}/confirm|post`; the real routes are
`/credit-notes/{id}/confirm|post`. The page's Confirm/Post buttons can never have worked. Fix FE
routes; assert via the E2E case that worked around it (remove the workaround in the same change).

## 3 — P0 candidate: quote→order→invoice conversion silently zeroes VAT on the final invoice

Two independent live repros; VAT zeroed EVEN for a fully-configured rate. Root cause only
partially isolated — likely the same family as
[2026-08-02-confirm-zeroes-vat-unconfigured-rates.md] (TaxCalculationService STEP 1 unmatched-rate
zeroing + fiscal-category mismatch when lines originate from a NonFiscal quote), but the
configured-rate repro says the conversion path loses or mismatches something else (rate format?
fiscal category carried from source doc?). INVESTIGATE ROOT CAUSE FIRST, then fix under this
orchestrator ruling: **an explicitly-supplied line rate must never be silently zeroed — when no
configuration row matches, confirm/conversion honours the explicit line rate (keeps the
draft==confirm identity that 18e61a554 established); silent zeroing is the only unacceptable
branch.** If the investigation shows the fix belongs in the parent VAT ticket's scope, implement
both arms there and close this item against it.

## Disposition

Fix lane dispatched 2026-08-02 (orchestrator), adversarial Opus gate before promote. The five
W-1 documents-leg cases re-run green = acceptance evidence.
