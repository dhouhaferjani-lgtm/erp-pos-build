# Ticket: F2/F3 re-gate carry-overs (R1/R2/R3/R5/R6)

From the F2/F3 re-gate (2026-08-03, MERGE-READY —
docs/superpowers/reviews/2026-08-02-documents-fixlane-gate.md §"F2/F3 re-gate").

## R1 — pre-existing path-symmetric draft-vs-confirm rounding divergence (boundary-dirty numbers)

Probe: 2 lines of 14.285 @ 7% TND → draft 31.568 / confirm 31.569, IDENTICAL on the direct-create
path (control) — so conversion is byte-parity with direct creation (F3 achieved its goal), but the
hand-rolled per-line loop (`bcdiv(rate,'100',4)` + per-line truncate) vs the service's
6dp/scale+1/round-once disagree by 1 millime on boundary numbers at CONFIRM time everywhere.
Fix direction: unify draft total computation on the SAME rounding pipeline as
TaxCalculationService STEP 1 (single source of truth), then draft==confirm holds universally.
Money-affecting at 1-millime scale; certification-relevant (totals must be reproducible).

## R2 — one-liner: blank-currency guard in CopiesDocumentData

`CopiesDocumentData.php:291` `getScale($document->currency)` lacks the `!== ''` guard its sibling
`scaleFor()` has — a blank currency would compute at PG-default scale 2 instead of 3. Apply in the
next documents-territory lane (credit-note lane).

## R3 — record: commit d3b5410f5's justification was wrong (correct conclusion)

The seed-based "no DOCUMENT_TOTAL targets NonFiscal" claim is defeated by
`TaxConfiguration::scopeForDocumentType()`'s `orWhereJsonLength(...,0)` wildcard, and
Order→DeliveryNote's target IS in the TN rows. The load-bearing reason documentTaxTotal-only is
correct: EVERY confirm path already writes `tax_amount = totalTax` (STEP 2 applies at confirm for
all types), so the fold makes draft match confirm for ANY config. Recorded here so future readers
don't re-derive from the commit message.

## R5 — no `credit-notes.confirm` permission exists

The confirm route family is gated `can:deliveries.confirm` etc., but credit-note confirm has no
dedicated permission; the role at RolesAndPermissionsSeeder.php:723 can POST but not CONFIRM a
credit note. Decide the intended permission and align seeder + route + FE affordance.

## R6 — `/return-notes/:id` registered twice under different module gates

Both registrations verified reachable; the E2E path is valid. Deduplicate to one registration
under the correct module gate before it drifts.
