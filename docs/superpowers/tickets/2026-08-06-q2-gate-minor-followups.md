# Q2 expense-VAT-base gate — out-of-lane follow-ups (2026-08-06)

Source: docs/superpowers/reviews/2026-08-06-q2-expense-vat-base-gate.md (gate on
`fix/expense-vat-declared-base`). Verdict APPROVE-WITH-FIXES; I-1/I-2 + minors closed in-lane.
These two findings are outside the lane's scope and are parked here.

## m-3 — scale-2-currency truncation + rounding-convention shift on the expense VAT snapshot (P2)

`ExpenseService::writeDeductibleVatSnapshot()` and the backfill expense leg compare/write
`tax_base` at the RESOLVED CURRENCY scale against `document_tax_details` columns that are
`decimal(15,3)`. For a scale-2 currency the third decimal is truncated on both the write and
the compare side; and the convention shifted from V5's `bcround` (half-up) to
`bcformatStrict` (truncate). Rule-19-compliant but untested. Action: pin with a scale-2
currency test (EUR-style) asserting the stored base and the bccomp no-op guard agree; decide
whether truncate-vs-round matters for declared bases (likely not — base is attested facial
value, not computed) and document the choice in the method docblock. Tenant #1 is TND
(scale 3), so not launch-blocking.

## m-6 — `VatBreakdownTable.tsx:22` does `parseFloat` on money (P2, pre-existing)

`apps/web/src/features/vat-reporting/components/VatBreakdownTable.tsx:22` runs
`parseFloat(b.base_amount)` — a precision-contract (rule 19) violation on the exact surface
the Q2 lane changed. Pre-existing, not introduced by the lane. Action: replace with the
canonical string-based formatting path (`formatCurrency` / lib/decimal), sweep the
vat-reporting feature directory for siblings, and check why the `no-parsefloat-on-money`
ESLint rule did not fire here (file predates the rule's ratchet baseline?). Fold into the next
FE hygiene batch.

## m-7/m-8/m-9 — re-gate probe findings on the closed-period reporting (P2, from the fix-round re-verify)

- **m-7**: the closed-period lookup filters `status = Closed` only — a **FILED** period is
  silently not reported. Vacuous today (no tenant has filed), but FILED is the
  highest-consequence case and needs its OWN message: `reopenPeriod()` refuses filed periods,
  so the remedy is a filed-declaration correction/escalation, not reopen+re-close.
- **m-8**: the period lookup uses `->first()` on a set that can hold >1 (monthly + quarterly
  after a `period_type` switch, or two `country_code` rows covering one date) — second period
  goes unreported. One-liner: `->get()` + merge into the report map.
- **m-9**: both new report paths (duplicate-slot skip, closed-period impact) are tested only
  under `--apply`; dry-run is what an operator runs first — add dry-run-mode assertions.

Close all three in the same motion as the I-3 ruling implementation (one small backfill-leg
touch-up round).

## I-3 — 0%-deductible expense base: OWNER RULING PENDING (tracked here until answered)

The Q2 fix makes a fully NON-deductible expense (vat_deductible_percent = 0) declare
`tax_base = full subtotal, tax_amount = 0.000` (previously 0.000/0.000 under V5). This grows
UK box 7 and the TN achats base. Consistent with the expert's cross-matching rationale but
OUTSIDE the ruling's stated scope (the expert answered the 80% case). Interim posture shipped
in-lane: writer keeps full-base behaviour (pinned test cites this finding); backfill leg
SKIPS 0%-deductible rows with an "awaiting ruling" report line. Owner question to relay to
the expert-comptable alongside Q1:

> Pour une charge dont la TVA n'est PAS DU TOUT déductible (0 %), faut-il quand même déclarer
> la base faciale totale de la transaction dans la déclaration (avec TVA déduite = 0), ou
> exclure entièrement cette charge de la déclaration TVA ?
