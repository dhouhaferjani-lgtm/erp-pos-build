# Adversarial Review (Round 3) — Domestic Procurement-to-Pay GR-IR Spec
Date: 2026-06-24 | Reviewer: Codex (cross-model) | Verdict: NEEDS-REVISION (converging)

Round-2 closure: B2-3/4/5/6/7/8/10 + Media = CLOSED. B2-1/B2-2/B2-9 = PARTIAL (completed below). Round-3 fresh sweep found 2 BLOCKER + 3 HIGH + 3 MEDIUM — all accepted, spec revised.

## BLOCKER
- **R3-1 — `warn` could bypass the hard 408 over-clear invariant.** `exception` includes "unreceived"; `warn` lets an `exception` post → could clear 408 beyond received. **Fix:** SPLIT a HARD quantity invariant (over-received/unreceived ALWAYS blocks or posts only to a variance/suspense path — never subject to `warn`) from ADVISORY price/amount variance (the `warn|block` setting governs only tolerable price discrepancies).
- **R3-2 — `quantity_invoiced` increment lacks locking/idempotency.** Two concurrent invoice posts read the same `quantity_invoiced`, both pass, both over-clear; retries double-increment. **Fix:** one DB transaction → `SELECT … FOR UPDATE` the matched PO lines → recompute matchable from locked rows → update `quantity_invoiced` + post GL → unique idempotency anchor on supplier-invoice posting/allocation.

## HIGH
- **R3-3 — supplier credit notes don't define `quantity_invoiced` reversal.** Must specify when a credit note decrements `quantity_invoiced` (reopens the PO line for re-invoicing) vs not, and block negative/over-credit.
- **R3-4 — supplier credit-note GL matrix incomplete.** Need an explicit scenario table: price-only credit, returned goods, post-sale inventory no longer on hand, VAT reversal, stamp-duty — each with exact accounts, debit/credit direction, partner tagging, quantity effect, and whether inventory/408/variance is touched.
- **R3-5 — GR-IR rounding points at a truncating helper.** `CurrencyScale::bcformatStrict` TRUNCATES (`bcadd(...,scale)`, `:130`); `bcround` is half-up (`:172`, used by COGS at GL boundary `:959`). qty×cost can exceed scale → truncation, not "rounded once". **Fix:** compute at working precision, then `CurrencyScale::bcround($amount, $scale)` ONCE for both legs.

## MEDIUM
- **R3-6 — `match_status` enum inconsistent** (spec line 80 `unmatched/matched/variance/exception` vs line 93 `matched/price_variance/quantity_variance/exception`). Unify to ONE surface everywhere (PG CHECK, PHP enum, TS type, filters, tests).
- **R3-7 — procurement-policy persistence still "table OR column".** Commit to ONE tenant migration shape now: exact columns, decimal scales, enum CHECKs, **non-negative + ordering CHECKs on tolerances** (negative tolerance inverts match behavior), seeded defaults, resolver precedence.
- **R3-8 — recoverable-VAT source names a non-existent line column.** `line_tax_amount` is on **`documents`** (not `document_lines`). Line-level tax = `document_lines.tax_amount`/`recoverable_tax_amount` (exist in migration `2026_01_02_100004...:18`) but `DocumentLine` fillable/casts don't expose them (`:66,:105`). **Fix:** use `documents.line_tax_amount` only if ALL line tax is recoverable; otherwise sum persisted `document_lines.recoverable_tax_amount` and expose those columns on `DocumentLine`.

## Verdict: NEEDS-REVISION (directionally sound; the hard 408 invariant + concurrency + credit-note quantity/GL + rounding boundary + enum/persistence must be tight before planning). Addressed in spec revision 2026-06-24.
