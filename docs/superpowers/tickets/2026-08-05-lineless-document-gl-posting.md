# Ticket: a document with NO lines posts a one-legged GL entry

**Filed:** 2026-08-05, by the L1 fiscal-integrity fix lane, while implementing W-6 **D1a**.
**Related:** `docs/superpowers/tickets/2026-08-05-w6-finance-gl-defects.md` (D1a),
`docs/superpowers/reviews/2026-08-05-l1-fiscal-gate.md` (C-1, C-2, I-5, and the
round-2 finding that corrected this ticket).
**Status:** OPEN — the L1 lane preserves the pre-lane OUTCOME for this shape; it does
not endorse it.

> **Correction, 2026-08-05 (re-gate round 2).** The first version of this ticket said
> the lane "deliberately left the behaviour UNCHANGED". That was inaccurate: the
> lineless carve-out initially returned no absorbing account, which DOWNGRADED the
> Tunisian case from "balanced (nonsensically) via 4375" to a one-legged UNBALANCED
> hash-chained entry — a regression the lane itself introduced, on every chart. Fixed:
> `residualPlan()`'s lineless branch now resolves `SalesStampDutyPayable` exactly as
> the old inline step-3b did. Pinned by
> `InvoiceGLIntegrationTest::test_a_lineless_document_still_sweeps_its_total_to_the_timbre_account_on_the_tunisian_chart`.

## What happens

`AccountingService::createInvoiceGLEntries()` debits AR with the header `total` and
credits one revenue leg per document line. A document with **zero lines** therefore
has no revenue or VAT credits at all, and its entire `total` becomes the residual:

- on a chart that defines `SalesStampDutyPayable` (Tunisia), that whole total is
  swept into `4375 — droit de timbre à reverser`. The entry BALANCES, but an entire
  invoice is booked as collected stamp duty — and it inflates the figure the company
  remits to the State. Nonsense, silently.
- on any other chart (France, Generic), the residual has no home and the entry is
  written **unbalanced** — a one-legged, `Posted`, hash-chained journal entry.

Both outcomes are pre-existing and unchanged by the L1 lane; neither is acceptable
long-term, which is what this ticket is for.

This is the same failure class as D1a but a different trigger, and materially larger:
D1a's negative residual fired on exactly one document out of 274 on `demo-pharmacy-tn`,
whereas this fires on every lineless posting.

## Why the L1 lane did not fix it

1. **Not reachable through the documented API.** `CreateDocumentRequest` requires
   `'lines' => ['required', 'array', 'min:1']`, so an invoice or credit note authored
   through `POST /documents` always has at least one line. The shape survives in
   legacy rows and, overwhelmingly, in **test fixtures** that build a `Document` with a
   `total` and no `DocumentLine`.
2. **The blast radius is a fixture sweep, not a code change.** Making the D1a
   pre-flight refuse this shape turned **~35 existing tests red across at least 10
   files** (`DocumentPostingServiceTest`, `DocumentCancelConsolidationTest`,
   `CreditNoteAllocationTest`, `CompleteSalesCycleWithReturnTest`,
   `RefundResidualTenantIsolationTest`, `DeliveryNoteHashChainTest`,
   `Types/InvoiceDocumentTest`, `InvoiceDeliveryNoteConfirmationTest`,
   `VerifyFiscalChainGenesisDocumentTest`, `FiscalHardeningE2ETest`), every one of them
   with `[no_absorbing_account] … residual == the full document total`. That is a
   correct diagnosis of a real defect, but rewriting 35 unrelated fixtures inside a
   merge-gated P0 lane is exactly the over-reach the D1a gate already rejected once.
   The true count may be higher — a full PHPUnit run was not permitted.

So `AccountingService::residualPlan()` returns `balanceAssertable = false` for a
lineless document — both the pre-flight and the defence-in-depth assertion skip it —
AND it still resolves `SalesStampDutyPayable` as the absorbing account, so the leg is
written exactly where the old inline code wrote it. It deliberately does NOT fall back
to the new `SalesRoundingDifference*` pair: booking a whole invoice total as an
"écart d'arrondi" would trade one silent misstatement for another. The outcome is
byte-identical to before the lane, on every chart.

## Suggested fix

Refuse the shape where it is authored, not where it is posted — a document with no
lines should not be **confirmable**, let alone postable. That gives a clean 422 at the
boundary and lets `residualPlan()` drop its carve-out (and with it,
`DocumentGlResidualPlan::$balanceAssertable`).

Order of work:

1. Add the emptiness guard to the confirm/post path in `DocumentPostingService` (or
   `DocumentService::confirm()`), returning a domain error.
2. Sweep the fixtures: every test that builds a `Document` it then posts needs one
   `DocumentLine` whose `line_total` reconciles with the header. Mechanical.
3. Remove `$balanceAssertable`, the carve-out in `residualPlan()`, and the two
   `InvoiceGLIntegrationTest` cases that pin the carve-out's behaviour.
4. Decide what to do with any lineless posted documents already in staging/production
   data — on TN they are sitting in `4375` and will distort the timbre remittance.
   Query: `documents d LEFT JOIN document_lines dl ON dl.document_id = d.id WHERE
   d.type IN ('invoice','credit_note') AND d.status = 'posted' AND d.deleted_at IS NULL
   GROUP BY d.id HAVING COUNT(dl.id) = 0`.

## Related, also open

- **N-9 (gate):** even for documents WITH lines, the Tunisian chart books tax-rounding
  residual into `4375` alongside genuine timbre, because `SalesStampDutyPayable` takes
  precedence over the new `SalesRoundingDifference*` pair. That preserves existing TN
  behaviour on purpose (the gate required it), but it means the timbre account carries
  sub-millime noise. Splitting it needs an accountant's ruling on whether the
  remittance figure may be restated.
- **Existing-tenant backfill for the new accounts.** `FranceChartOfAccountsSeeder` and
  `GenericChartOfAccountsSeeder` now seed `6581` / `7581`, but seeders only run for NEW
  companies. There are no FR/Generic tenants yet, so no migration was written (per the
  orchestrator ruling). If one is onboarded from an older chart before that changes, it
  needs an idempotent backfill command in the shape of
  `database/migrations/tenant/2026_06_30_120000_backfill_sales_stamp_duty_account.php`.
