# Ticket: VAT re-gate carry-overs (N2 pre-filing manual correction + minors N3/N4/N6/N7/N8)

From the VAT-declaration re-gate (2026-08-03, MERGE-READY —
docs/superpowers/reviews/2026-08-03-vat-declaration-gate.md §"Re-gate fix round"). N1 (backfill
subtotal guard) is being fixed in-lane.

## N2 — P2, MANDATORY BEFORE ANY REAL VAT FILING

The 6 backfill-skipped demo documents leave `vat_0 = 6.000` (VAT that doesn't exist) and
+680.000 of stamp base inside `base_0` on the open Aug-2026 period. 98.6% cleaner than pre-fix
and non-silent (the command reports the skips), but a manual correction pass on skipped documents
is REQUIRED before any declaration is filed from any tenant the backfill has run on. Runbook
item: after `vat:backfill-tax-details --apply`, resolve every reported skip (fix the document
header/lines or write an adjustment) until the skip list is empty.

## Minors

- N3 (P3): backfill has no audit trail — created_at overwritten, report is stdout-only. Persist a
  run record (per-tenant table or log file artifact) before production use.
- N4 (P3): tax is discontinuous in the discount on sub-scale documents (gold case 0.300 → 0.171
  with a 0.100 discount) — correct trade-off per the precision contract; DOCUMENT it in
  docs/architecture/precision-contract.md so nobody re-files it as a bug.
- N6 (P3): NULL tax_rate would break the base tie but is NOT reachable via the API (probed both
  omitted and explicit null → stores 19.00). Guard belongs at snapshot time anyway — cheap
  assertion.
- N7 (P3): BackfillTaxDetailsCommand sits in app/Console/Commands/ outside its module — move to
  the Taxation module per hexagonal placement.
- N8 (P3): DGI payload keys are dotted (`base_21.00`) — normalise key format before any external
  consumer parses them.
