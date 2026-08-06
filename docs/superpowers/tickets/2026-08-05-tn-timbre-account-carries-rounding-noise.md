# Ticket: on the Tunisian chart, `4375` carries tax-rounding noise alongside real timbre

**Filed:** 2026-08-05, by the L1 fiscal-integrity fix lane.
**Source:** `docs/superpowers/reviews/2026-08-05-l1-fiscal-gate.md` finding **N-9**.
**Related:** `docs/superpowers/tickets/2026-08-05-w6-finance-gl-defects.md` (D1a),
`docs/superpowers/tickets/2026-08-05-lineless-document-gl-posting.md`.
**Status:** OPEN — **needs an expert-comptable ruling before any code change.**

## What happens

A sales document debits AR with the header `total` and credits the LINES' revenue plus
a RECOMPUTED per-line VAT. The two sides round differently:

- `AccountingService::groupTaxByRate()` computes `bcmul(line_total, rate/100, scale)` —
  **truncated per line**;
- `TaxCalculationService` accumulates each line's tax at `scale+1` and truncates **once
  per rate bucket**.

Because `Σ trunc(xᵢ) <= trunc(Σ xᵢ)`, the header tax can exceed the recomputed GL VAT by
up to one unit of the last place per line. That difference lands in the document
residual — and on the Tunisian chart `residualPlan()` books **any** positive residual to
`SalesStampDutyPayable` (`4375 — État, droit de timbre à reverser`), because that is
where the genuine document-level timbre must go and preserving that behaviour exactly
was a condition of the D1a fix.

So `4375` accumulates two different things:

1. **real collected stamp duty**, which the company owes the State and remits; and
2. **sub-millime tax-rounding residue**, which it does not.

On the France/Generic charts the two are already separated: those charts have no timbre
concept, so the residue goes to the dedicated `SalesRoundingDifference*` pair
(PCG 658/758, seeded and backfilled by
`2026_08_05_120000_backfill_sales_rounding_difference_accounts`). Tunisia is the only
chart where they are commingled.

## Why it matters now

Pre-existing, but the D1a fix makes `4375` **load-bearing**: it is now the only thing
keeping Tunisian postings green for any document with a positive residual, so the
commingling is no longer incidental. The balance of `4375` is the figure a Tunisian
company reports and remits for droit de timbre — it is currently **over-stated by the
accumulated rounding residue**.

Magnitude is expected to be small (bounded by one millime per invoice line) but it is
non-zero, monotonically accumulating, and sitting in a **State liability** account.

## What is NOT proposed

Do **not** silently redirect the residue. Splitting it changes a previously reported
remittance base, which is an accounting decision, not an engineering one.

## The ruling needed (expert-comptable)

1. **Is the current commingling material** for a Tunisian filer, or is sub-millime
   residue in `4375` acceptable de minimis?
2. If it must be split: **may prior periods be restated**, or does the split apply
   prospectively only from a cut-over date?
3. Which account should carry the Tunisian side of the residue — the same
   `SalesRoundingDifference*` pair the other charts use (which would mean seeding
   `6581`/`7581` into `TunisiaChartOfAccountsSeeder` and lifting the "skip companies
   with a timbre account" guard in the backfill migration), or a TN-specific code?

## Implementation sketch, once ruled

- Split `residualPlan()`'s positive branch: book the portion attributable to per-line
  truncation (bounded by `roundingTolerance()`) to the rounding account, and only the
  remainder to `SalesStampDutyPayable`.
- Seed the pair into `TunisiaChartOfAccountsSeeder` and remove the timbre-account skip
  from `2026_08_05_120000_backfill_sales_rounding_difference_accounts` (or add a
  follow-up migration — that one is already applied by then).
- Quantify the historical amount before any restatement:
  `SELECT sum(jl.credit) FROM journal_lines jl JOIN accounts a ON a.id = jl.account_id
   WHERE a.system_purpose = 'sales_stamp_duty_payable'` cross-checked against the count
  of `STAMP_TAX_INVOICE` documents times the configured timbre — the difference is the
  accumulated residue.
