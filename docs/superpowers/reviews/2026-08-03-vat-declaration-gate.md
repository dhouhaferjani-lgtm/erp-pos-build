# Gate review — VAT-declaration correctness lane (74383eb19 + ada81fec9)

**Date:** 2026-08-03
**Reviewer:** adversarial gate (independent probes, local dev, NOT pushed)
**Scope:** `74383eb19` (per-rate `tax_base` = taxed bucket net) and `ada81fec9` (`snapshotTaxDetails` writes `is_stamp_duty`)
**Ticket:** `docs/superpowers/tickets/2026-08-02-documents-gate-followups.md` §VAT-reporting (F4/F5)
**Environment:** local stack, api :8010, web :5173, tenant `demo-pharmacy-tn` (`tenant019fbe86-944a-7252-8a3b-8c341dfa9de9`), central `iziposcentral` on 127.0.0.1:5433

---

## VERDICT: **APPROVE-WITH-FIXES — NOT MERGE-READY AS A LANE**

The two commits are individually correct in what they set out to do and are strictly better than
the status quo for multi-rate documents and for TN stamp separation. **But one shape regresses
(V1) and the lane's headline claim — "the TN VAT declaration is now correct" — is false on four
independent axes (V2, V3, V4, V5).** Do not close the VAT-declaration correctness track on these
two commits, and do not let any tenant file a TN declaration from this system until V1/V2/V5 have
an owner ruling.

| # | Severity | Finding | Attribution |
|---|---|---|---|
| V1 | **P0 — REGRESSION** | Document-level discount: `tax_base` now snapshots the **pre-discount** bucket net; pre-fix it was the post-discount subtotal (which was legally correct for single-rate docs) | **introduced by 74383eb19** |
| V2 | **P0 — pre-existing, lane-blocking** | Credit notes **ADD** to declared output base + VAT instead of subtracting | pre-existing |
| V3 | **P1 — pre-existing, lane-blocking** | Zero-rated / exempt base never reaches the declaration; DGI `base_0` structurally always empty | pre-existing, **now more visible** |
| V4 | **P1 — new code** | The fix's own stated invariant (`Σ rateBase == subtotal`) is false under sub-scale truncation; no test covers it | **introduced by 74383eb19** |
| V5 | **P1 — process** | A second, unaudited writer of `document_tax_details` (`ExpenseService`) was missed; the INPUT side of the declaration is untouched and untested | pre-existing, missed by lane |
| V6 | **P1 — remediation** | Historical rows **cannot** be self-healed; commit message implies a re-confirm escape hatch that does not exist for invoices/credit notes | pre-existing, **commit msg misleading** |
| V7 | P2 | Cited no-regression evidence does not touch `tax_base` | evidence quality |
| V8 | P3 | Rate-zero guard is string-literal based | pre-existing |
| V9 | P3 | `leftJoin` on `percentage_rate` alone can fan out and double `SUM(tax_base)` | pre-existing |

---

## What is genuinely correct (verified, not trusted)

- **The core fix is minimal and symmetric.** `$rateBaseAccumulator` is built once
  (`TaxCalculationService.php:141-154`) before the matched/unconfigured branch and is used
  identically in both — `base: $rateBase` at `TaxCalculationService.php:166` (matched) and
  `:194` (UNCONFIGURED). Probes A1/A4 confirm byte-identical treatment across the two branches.
- **Line discounts land in the bucket base BEFORE bucketing, in both branches.** Probe A1
  (19% bucket = `100.000 + 90.000 = 190.000` with a 10% line discount; 7% bucket =
  `200.000 + 75.000 = 275.000` with a 25.000 flat discount). `$line->calculateTotal($scale+1)`
  (`DocumentLine.php:248-257` → `computeLineTotal:274-291`) already nets the line discount, so the
  discount is inside the bucket, never applied after. Answer to the gate question: **BEFORE, and
  consistent between matched and unconfigured.**
- **`base × rate` reproduces the snapshot tax exactly** in every probe (A1 three rates, A3/A4
  truncation vectors) — the two accumulators are now provably in step.
- **`is_stamp_duty` is genuinely persisted.** `DocumentTaxDetail.php:46` (`$fillable`) and `:57`
  (`'is_stamp_duty' => 'boolean'`) — no mass-assignment drop. Live tenant now carries 10 rows with
  `is_stamp_duty = true`, all `STAMP_TAX_INVOICE`, created 2026-08-03.
- **Both consumers behave.** `TunisiaVatStrategy::getSpecialLineItems()`
  (`TunisiaVatStrategy.php:97-109`) now sees the stamp; `EloquentVatDataRepository`
  (`EloquentVatDataRepository.php:30`, `where('dtd.is_stamp_duty', false)`) now excludes it.
- **Single writer on the sales side.** Every sales-side snapshot funnels through
  `snapshotTaxDetails()`: `SalesOrderService.php:106,185`; `PurchaseOrderService.php:95`;
  `DeliveryNoteService.php:130`; `ReturnNoteService.php:138`; `InvoiceController.php:612`;
  `QuoteController.php:498`; `CreditNoteController.php:300`. All are now consistent.
- **Conversion and CN-draft paths emit NO tax-detail rows** (so they cannot mix old-shape rows
  into a period). `CopiesDocumentData` has zero references to `document_tax_details` (grep);
  `applyConfirmEquivalentTotals()` (`CreditNoteService.php:94-107`) only rewrites
  `subtotal`/`tax_amount`/`total` and **does not** snapshot. Called at `:881`, `:1060`, `:1212` —
  all draft-time. The implementer's story here holds.
- **No signed-bytes impact — the hash chain is untouched.** `DocumentPostingService.php:394-401`
  signs exactly `document_number`, `posted_at`, `total`, `currency`. The Fiscal module has **zero**
  references to `tax_base`/`taxBase` (grep over `app/Modules/Fiscal/`). Neither commit changes any
  `amount` or `total`. A chain verifier cannot observe this change. **Gate question D: CLEAN.**
- **No FE display regression.** `tax_base` is declared in `apps/web/src/features/documents/api/taxApi.ts:10`
  but rendered nowhere (`grep -rn 'taxBase\|tax_base' src/ --include='*.tsx'` → test fixtures only).

---

## A. Per-rate base correctness — adversarial probes

All probes run against the live tenant inside `DB::beginTransaction()` / `DB::rollBack()`.
Post-probe verification: `select count(*) from documents where document_number like 'W4c-%'` → **0**.

### V1 — P0 REGRESSION: document-level discount inflates the declared base

`calculateSubtotal()` subtracts `$document->discount_amount` (`TaxCalculationService.php:272-274`);
the per-rate accumulator (`:149`) sums `$line->calculateTotal()` and **never sees it**.

**Probe B — single rate 19%, one line 300.000, `documents.discount_amount = 50.000`:**

```
subtotal (post-discount) = 250.000
  TVA_19  LINE_ITEMS  base=300.000  amount=57.000     <- POST-FIX
  PRE-FIX this row carried base = 250.000 (= $subtotal)
legally-correct base = 250.000, legally-correct 19% VAT = 47.500
```

For a **single-rate** document with a document-level discount the pre-fix base was *legally
correct* and the post-fix base is wrong by the full discount. That is a regression, not merely a
pre-existing defect being carried forward.

**Probe A2 — two rates 19% + 7%, `discount_amount = 50.000`:**

```
subtotal = 250.000
  TVA_19  base=100.000  amount=19.000
  TVA_7   base=200.000  amount=14.000
  Sigma(LINE_ITEMS base) = 300.000  vs subtotal 250.000   delta = +50.000
```

Separately (pre-existing, out of these commits' scope but now *masked* by the base agreeing with
it): the VAT itself is charged on the **pre-discount** base — 33.000 TND of VAT on a 250.000 net.
Pre-fix `base ≠ amount/rate` at least made the inconsistency detectable in the snapshot; post-fix
`base × rate == amount` exactly, so the defect is now internally self-consistent and invisible.

**Reachability — this is NOT a dead field.** `documents.discount_amount` is not accepted by
`CreateDocumentRequest`/`UpdateDocumentRequest` (only `lines.*.discount_amount`), **but**
`POSAccountChargeDraftService.php:62` and `:164` write it from
`$command->transactionDiscountAmount` on a `DocumentType::Invoice` /
`FiscalCategory::TaxInvoice` draft — the POS account-charge → invoice path, a launch flow. Those
drafts confirm through `InvoiceController::confirm()` → `snapshotTaxDetails()`.
`RefundService.php:115` propagates it to refund documents. Live count on this tenant today: **0
documents with a non-zero document-level discount**, so no data is wrong *yet*.

**Required fix:** prorate `$document->discount_amount` across the rate buckets (pro rata on bucket
net) and apply it to **both** `$rateBaseAccumulator` and `$taxAccumulator`, so
`Σ rateBase == $subtotal` and the VAT is charged on the discounted base. If that is too large for
this lane, at minimum restore `base` to the post-discount share so the *declared base* is right,
and ticket the tax-on-pre-discount-base defect explicitly.

### V4 — P1: the fix's stated invariant is false; tests only cover the trivially-true case

The comment at `TaxCalculationService.php:133-140` asserts:

> "so Σ(rateBase) across every LINE_ITEMS rate group == $subtotal exactly whenever every line
> carries a taxed (non-zero) rate"

**This is false**, and not only because of V1. `calculateSubtotal()` truncates **per line**
(`TaxCalculationService.php:264-268`, `CurrencyScale::bcformat` → `bcadd($str,'0',$scale)` =
truncation toward zero, `CurrencyScale.php:104`), while `$rateBaseAccumulator` accumulates at
`scale+1` and truncates **once per bucket** (`:149`, `:154`).

**Probe A3 — 3 lines, qty 1.5000 × 0.333 = 0.4995 each, all at 19%, no discounts:**

```
subtotal = 1.497        (0.499 + 0.499 + 0.499, per-line truncation)
  TVA_19  base=1.498    (1.4985 accumulated at scale+1, truncated once)
  Sigma(LINE_ITEMS base) = 1.498  vs subtotal 1.497   delta = +0.001
```

**Probe A4 — same vector through the UNCONFIGURED branch (21%) + a configured 19% line:**

```
subtotal = 100.998
  UNCONFIGURED 21%  base=0.999   amount=0.209
  TVA_19            base=100.000 amount=19.000
  Sigma = 100.999  vs subtotal 100.998   delta = +0.001
```

Consequence: the declared per-rate base does **not** tie to `Σ documents.subtotal`. An auditor
reconciling a DGI declaration against the invoice register gets a drift of ~0.0005 × (number of
sub-scale lines) per period. For a "certification-critical" lane, a knowingly-unreconcilable base
is not acceptable without a written ruling.

**Suggested fix (verified to lose nothing):** accumulate
`CurrencyScale::bcformat($line->calculateTotal($scale + 1), $scale)` per line — i.e. mirror
`calculateSubtotal()`/`DocumentTotalsCalculator` — instead of accumulating at `scale+1`. On probe
A3 that yields `base = 1.497` and the **same** tax `0.284`, so the tax accumulator is unaffected
and `Σ base == subtotal` holds exactly.

**Test coverage gap:** both new test classes use `quantity = '1'`, round unit prices, no line
discounts, no document discount, and no 0% line —
`TaxCalculationServiceTest::it_snapshots_per_rate_tax_base_as_the_taxed_bucket_not_the_whole_subtotal`
and `VatDeclarationCorrectnessTest::test_vat_period_aggregation_declared_base_equals_actual_taxed_bases_across_mixed_documents`.
They exercise only the case where the invariant is trivially true. The commit message's
verification claim is broader than what the tests prove.

### V3 — P1: zero-rated / exempt base never reaches the declaration

`TaxCalculationService.php:100-102` `continue`s on `''`/`'0'`/`'0.00'`, so **no**
`document_tax_details` row is ever written for a zero-rated line — even though TN has an active
`TVA_EXEMPT` config (`percentage_rate = 0.00`, `applies_to = LINE_ITEMS`, `is_active = t`,
verified in `tax_configurations`).

**Probe A1 — 3 rates + a 50.000 exempt (0%) line + line discounts + stamp:**

```
subtotal = 815.000
  TVA_19  base=190.000  amount=36.100
  TVA_7   base=275.000  amount=19.250
  TVA_13  base=300.000  amount=39.000
  STAMP_TAX_INVOICE  DOCUMENT_TOTAL  base=815.000  amount=1.000  stamp=Y
  Sigma(LINE_ITEMS base) = 765.000  vs subtotal 815.000   delta = -50.000
```

The 50.000 exempt turnover is in the invoice and in **nothing** the declaration can see.
`TunisiaVatStrategy::getExpectedRates()` (`TunisiaVatStrategy.php:128`) lists `'0.00'` and
`mapToDeclaration()` (`:54-59`) maps it to the DGI `base_0` / `vat_0` fields — so those form fields
are **structurally always zero**.

Aggravated by `ada81fec9`: pre-fix a rate-`0.00` OUTPUT bracket *did* appear (populated by the
mis-flagged stamp rows — garbage numbers, but a visible bracket). After both commits the bracket
disappears entirely, so the only accidental signal that exempt turnover exists is gone. Not a
correctness regression, but a silent zero on a legally-required line.

### V8 — P3: rate-zero guard is string-literal based

`TaxCalculationService.php:100` compares `$rateStr` against `''`, `'0'`, `'0.00'`. It only works
today because `DocumentLine` casts `tax_rate` to `decimal:2` — probe A5 confirmed a supplied
`'0.000'` arrives as `'0.00'`. A future scale change on that column silently converts the exempt
bucket into a taxed 0% bucket. `bccomp($rateStr, '0', 2) === 0` would be robust.

---

## B. Snapshot lifecycle — every writer of `document_tax_details`

**Answer to the gate question: NO — not all writers are consistent.**

### V5 — P1: `ExpenseService` is a second, unaudited writer on the declaration's INPUT side

`ExpenseService.php:385-402` writes `DocumentTaxDetail` **directly**, bypassing
`snapshotTaxDetails()` entirely. It feeds `d.type = 'expense'`, which
`EloquentVatDataRepository.php:29` and `:34-36` class as **INPUT** VAT. Four problems:

1. `'tax_base' => $taxBase` where `$taxBase = (string) ($expense->subtotal ?? '0')`
   (`ExpenseService.php:385`) — **the exact "whole document subtotal" semantic the sales side just
   repaired.** Single-rate today, so not inflated in practice, but it is the un-fixed shape.
2. `'tax_amount' => $deductibleVat` — the **deductible share**, while `tax_base` is the **full**
   subtotal. `base × rate ≠ amount` for any partially-deductible expense, so
   `SUM(base)` and `SUM(vat_amount)` in the declaration sit on different footings.
3. `firstOrCreate` keyed on `(document_id, tax_type, tax_name, tax_rate, tax_base, tax_amount)`
   rather than the delete-then-recreate `snapshotTaxDetails()` uses
   (`TaxCalculationService.php:334`). Any change to the expense's subtotal or VAT before a re-post
   creates a **second** row while the first survives → double-counted input VAT. `post()` is
   guarded to Draft-only (`ExpenseService.php:311`), so it needs an unpost path to be reachable —
   but the shape is fragile and should not differ from the canonical writer.
4. The **LinkedCost** branch (`ExpenseService.php:322` onward) writes **no** `DocumentTaxDetail`
   at all → those expenses' input VAT never reaches the declaration.

Also: `supplier_invoice` is **not** in `EloquentVatDataRepository.php:29`'s `whereIn`, so supplier
invoices never contribute INPUT VAT either (this tenant has 6 posted supplier invoices).

**Live consequence:** `document_tax_details` on `demo-pharmacy-tn` contains **zero** rows joined to
a `type = 'expense'` document. The INPUT half of the TN declaration is empty and has never been
exercised end-to-end.

### V6 — P1: historical rows cannot be self-healed; the commit message is misleading

`ada81fec9`'s body says `DocumentTaxDetail` is "never rewritten outside re-confirm", which reads as
though re-confirming an old document would fix it. **There is no such path for invoices or credit
notes:**

- `InvoiceController.php:587-593` and `CreditNoteController.php:269-275` both hard-guard confirm to
  `isDraft()`; an already-Confirmed document returns early (idempotent), anything else throws.
- `DocumentPostingService::revert()` (`:254-259`) supports Confirmed → Draft for **Quote,
  SalesOrder, PurchaseOrder only** — `default => throw new \DomainException('DOCUMENT_REVERT_NOT_SUPPORTED')`.

So the delete-and-recreate in `snapshotTaxDetails()` is reachable only for Quote/SO/PO, none of
which is in the declaration's `whereIn`. **Immutability boundary (gate question D): CLEAN — no
user-triggered re-confirm can silently rewrite a fiscal document's rows, and no audit discrepancy
against the hash chain is possible.** The corollary is that a **backfill/migration is mandatory**;
"re-confirm the affected documents" is not an available remediation.

---

## C. Declaration consumers

### V2 — P0: credit notes ADD to the declared output base and VAT instead of subtracting

`EloquentVatDataRepository.php:34-36` buckets `credit_note` as `'OUTPUT'` alongside `invoice`, and
`:38-39` does `SUM(dtd.tax_base)` / `SUM(dtd.tax_amount)` with **no sign inversion**. That is only
correct if credit-note rows are stored negative. **They are not.**

- Live: `credit_note` / `TVA_19` → `sum(tax_base) = +635.369`, `sum(tax_amount) = +120.717`;
  `credit_note` / `TVA_7` → `+57.140` / `+3.998`.
- Producer: `CreditNoteService::materializeLinesFromAllocation()` writes positive quantities and
  unit prices (`CreditNoteService.php:1040-1053`); confirmed CNs on this tenant carry
  `subtotal = 41.663`, `tax_amount = 8.515`, `total = 50.174` — all positive.
- The **only** test encoding the intended negative convention,
  `TaxSnapshotCreditNoteTest::test_credit_note_snapshots_reflect_negative_amounts`
  (`TaxSnapshotCreditNoteTest.php:71-86`, asserting `tax_base == '-100.00'`), is dead: the whole
  class calls `$this->markTestSkipped(...)` in `setUp()` at `TaxSnapshotCreditNoteTest.php:44`.
  It is part of the 24 skipped tests in the Feature/Taxation run.

**Under TN law a credit note must reduce the declared output base and output VAT.** Today it
increases both, so the error is **2×** the credit-note amount. Live impact on the open Aug-2026
period: 19% output VAT overstated by `2 × 120.717 = 241.434` TND and base by
`2 × 635.369 = 1,270.738` TND; 7% VAT overstated by `2 × 3.998 = 7.996` TND.

This is pre-existing and untouched by either commit — but it is the single largest correctness
defect in the artefact this lane is named after, and it dwarfs the base inflation the lane fixed.

### Stamp bucket vs rate buckets — no double-count, no drop (post-fix)

`TunisiaVatStrategy::getSpecialLineItems()` sums `tax_amount` where `is_stamp_duty = true`
(`:104-109`); `EloquentVatDataRepository` excludes the same rows (`:30`). Disjoint, complete.
Verified live: 10 correctly-flagged stamp rows are reported by the strategy and absent from the
rate breakdown. Note `snapshotTaxDetails()` writes `tax_rate = $tax->rate ?? '0'`
(`TaxCalculationService.php:344`) and TN stamp configs have `percentage_rate = NULL`, which is why
mis-flagged historical stamps land in the `0.00` bracket specifically.

### `periodSummary` live-vs-frozen — and what the declaration shows TODAY

**Frozen-period claim: INDEPENDENTLY VERIFIED, not assumed.**

- Tenant DB `tenant019fbe86-944a-7252-8a3b-8c341dfa9de9`: `vat_periods` = **0 rows**,
  `vat_period_breakdowns` = **0 rows**.
- Central DB `iziposcentral`: neither table exists (full `\dt` listing: 22 tables, no `vat_*`).
- `pg_database` lists exactly **one** `tenant*` database, so there is no other tenant to check.

Therefore `VatReportController::periodSummary()`'s CLOSED/FILED branch (`:70-114`) is unreachable
today and no frozen snapshot bakes in the wrong numbers. **The implementer's claim holds.**

**But the OPEN period is materially wrong right now.** Running the repository's aggregation
verbatim against the live tenant for 2026-08-01 → 2026-08-31 (all documents on this tenant fall in
August 2026):

| direction | rate | declared base | declared VAT | docs |
|---|---|---|---|---|
| OUTPUT | 0.00 | 130,834.976 | 430.200 | 439 |
| OUTPUT | 7.00 | 1,457.140 | 52.998 | 10 |
| OUTPUT | 19.00 | 16,353.864 | 2,983.727 | 139 |
| OUTPUT | 21.00 | 300.000 | 63.000 | 3 |
| INPUT | — | *(nothing)* | *(nothing)* | 0 |

Against the true per-rate line nets for the same documents: 7% true net **757.140** (declared
inflated by **+700.000**), 19% true net **15,705.864** (inflated by **+648.000**). The entire
`0.00` bracket — **130,834.976 base / 430.200 VAT** — is fictitious: 417 `STAMP_TAX_INVOICE` +
22 `STAMP_CREDIT_NOTE` rows still carrying `is_stamp_duty = false`.

Per-document, per-rate diff of `dtd.tax_base` against `SUM(document_lines.line_total)` for the
same rate:

| tax_code | rate | rows | matching | mismatched | Σ inflation |
|---|---|---|---|---|---|
| STAMP_CREDIT_NOTE | 0.00 | 22 | 4 | 2 | +692.507 |
| STAMP_TAX_INVOICE | 0.00 | 417 | 288 | 1 | +15,968.495 |
| TVA_19 | 19.00 | 139 | 122 | 13 | +648.000 |
| TVA_7 | 7.00 | 10 | 3 | 7 | +700.000 |
| UNCONFIGURED | 21.00 | 3 | 3 | 0 | 0.000 |

**Remediation surface (independently counted, not taken from the commit message):** 439
mis-flagged stamp rows; **145** documents carrying 2+ distinct non-stamp rate rows; 20 non-stamp
rate rows whose base still differs from the true line net. Rows created 2026-08-01/02 and most of
2026-08-03 are pre-fix; only the 10 `is_stamp_duty = true` rows are post-fix. **The period is
genuinely mixed** — exactly the scenario the gate asked about — and since V6 proves there is no
in-app path to rewrite them, a backfill script is the only remedy.

### V9 — P3: `leftJoin` fan-out risk

`EloquentVatDataRepository.php:19-26` joins `tax_configurations` on `percentage_rate` alone — no
`applies_to`, no `effectiveOn`, no document-type filter. TN currently has exactly one active
non-stamp config per rate (verified: `TVA_EXEMPT` 0.00, `TVA_7`, `TVA_13`, `TVA_19`), so no fan-out
today. If a second active config ever shares a rate (e.g. a dated replacement left active), the
join duplicates rows and `SUM(tax_base)` doubles. Worth a `->where('tc.applies_to','LINE_ITEMS')`
plus a uniqueness guard.

---

## D. Immutability boundary and the fiscal chain

**CLEAN.** See V6 for the reachability analysis (no re-confirm path for invoices/credit notes) and
"What is genuinely correct" for the signed-payload analysis (`DocumentPostingService.php:394-401`
signs only `document_number` / `posted_at` / `total` / `currency`; zero `tax_base` references in
`app/Modules/Fiscal/`). Neither commit alters any `amount` or `total`, so newly-signed bytes are
byte-identical to what the pre-fix code would have produced, and no verifier or chain-integrity
check is affected.

One cosmetic residue: reverting a Quote/SO/PO to Draft (`DocumentPostingService.php:272-278`,
`:303-309`, `:361-367`) leaves its stale `document_tax_details` rows in place until the next
confirm deletes them. None of those types is in the declaration's `whereIn`, so there is no
declaration impact.

---

## E. Regressions — all verified locally, not trusted

| Suite | Result |
|---|---|
| `tests/Unit/Taxation/` (85 tests) | **OK** 85/85, 320 assertions |
| `tests/Feature/Taxation/` (152 tests) | 1 failure — `FranceTaxConfigurationSeederTest::test_seeds_five_french_vat_configs_with_20pct_default` (`'20.0000'` vs `'20.00'`, `:29`). **Pre-existing and unrelated** — the file is untouched by both commits and the assertion is about a seeder's rate cast, not `tax_base`. 24 skipped (incl. the whole `TaxSnapshotCreditNoteTest` class). |
| `VatDeclarationCorrectnessTest` + `TaxCalculationServiceTest` | **OK** 7/7, 55 assertions |
| `CreditNoteMoneyLaneTest` + `ConversionChainVatIntegrityTest` + `InvoiceDraftDocumentTaxTest` | **OK** 19/19, 159 assertions |
| `tests/Feature/Document/IngressPrecisionTest.php` | **13 errors — exactly the stated baseline.** All `ArgumentCountError: Too few arguments to CreateDocumentRequest::__construct(), 3 passed … 4 expected` (`CreateDocumentRequest.php:24`). FormRequest DI arity; unrelated to taxation. |
| E2E `documents-tax.spec.ts` (MTP-TAX-01/08/13) | **3/3 passed** |
| E2E `documents-totals.spec.ts` (MTP-DOC-01..05) | **3/3 passed** at `--workers=1`. One login-race flake (`page.waitForURL` timeout in `w1b-support.ts:37`) at `--workers=3`; clean serially. |
| PHPStan level 8 on `TaxCalculationService.php` | **No errors** |
| Pint on all 3 changed files | **pass** |

### V7 — P2: the cited no-regression evidence does not touch `tax_base`

`CreditNoteMoneyLaneTest`, `ConversionChainVatIntegrityTest` and `InvoiceDraftDocumentTaxTest`
contain **zero** references to `tax_base` or `document_tax_details` (verified by grep). Their green
status proves totals and amounts are unmoved — which is true, and is what the commit message
claims. But "CreditNoteMoneyLaneTest values unmoved" must not be read as evidence that credit-note
`tax_base` is now correct. **No test in the repository asserts a per-rate `tax_base` on a credit
note produced by the real `CreditNoteService` path** — the only one that would
(`TaxSnapshotCreditNoteTest`) is skipped wholesale at `:44`. Given V2, that gap is exactly where
the P0 lives.

---

## Required before this lane can be called done

**Must fix in-lane (blocks merge of 74383eb19 as-is):**

1. **V1** — prorate `documents.discount_amount` across rate buckets in both the base and the tax
   accumulators (`TaxCalculationService.php:141-154` + `:272-274`), or at minimum restore a
   post-discount base. Add a test with a document-level discount on a single-rate document
   (the shape that regressed).
2. **V4** — either change `$rateBaseAccumulator` to per-line `bcformat(..., $scale)` so
   `Σ rateBase == $subtotal` holds exactly, or delete the false invariant claim from the comment at
   `TaxCalculationService.php:133-140` and record the accepted drift in the precision contract.
   Add the truncation vector (qty 1.5000 × 0.333) as a test either way.

**Must have an owner ruling before ANY tenant files a TN declaration:**

3. **V2 (P0)** — credit-note direction. Either negate `credit_note` rows in
   `EloquentVatDataRepository.php:34-39` or make `CreditNoteService` write negative bases/amounts
   (and un-skip `TaxSnapshotCreditNoteTest`). Pick one; the two conventions currently disagree
   silently.
4. **V3 (P1)** — zero-rated/exempt turnover. Decide whether `TaxCalculationService.php:100-102`
   should emit a 0-rate row so `base_0` on the DGI form is real.
5. **V6 (P1)** — backfill plan for the 439 mis-flagged stamp rows + 145 multi-rate documents.
   There is no in-app remediation; a migration or console command is required, and it must be
   written before the first filing, not after.
6. **V5 (P1)** — bring `ExpenseService.php:385-402` onto the canonical writer, decide the
   base-vs-deductible-amount semantics for INPUT VAT, and decide whether `supplier_invoice` belongs
   in `EloquentVatDataRepository.php:29`. The INPUT half of the declaration is currently empty.

**Ticket (non-blocking):** V8 (string-literal rate guard), V9 (leftJoin fan-out).

---

## Probe artefacts

- `W4c-probeA.php` / `W4c-probeB.php` (scratchpad, not committed) — construct documents against
  the live tenant inside a transaction and roll back. Post-run verification:
  `select count(*) from documents where document_number like 'W4c-%'` → **0**. No tenant data was
  mutated by this review. The `document_tax_details` count moved 643 → 658 solely from the two
  Playwright E2E runs, which create real W1b-prefixed campaign fixtures through the app.
- No commits, no pushes, no migrations were made by this review.

---

# Re-gate fix round

**Date:** 2026-08-03 (same day, second pass)
**Scope:** `d8cf49b0e` (V1/V3/V4), `32e6808a4` (V2), `cd8436e21` (V5), `9e4ee8a70` (V6)
**Method:** every finding re-probed independently against the live tenant — the original probe vectors re-run
verbatim, plus new adversarial shapes the fix round could plausibly have broken. All construction probes run
inside `DB::beginTransaction()` / `DB::rollBack()`; post-run `documents WHERE document_number LIKE 'W4c%'` → **0**.

## VERDICT: **MERGE-READY**

All six gate findings are verifiably closed. Every original blocker (V1 regression, V2 credit-note sign,
V3 vanishing exempt turnover, V4 false sum-identity, V5 unaudited INPUT writer, V6 no remediation path) now
behaves correctly under independent probe, and the declaration reads sane end-to-end on the live open period.

One condition and one mandatory manual step, neither of which blocks the merge:

- **N1 (P2) — harden `BackfillTaxDetailsCommand`'s invariant guard with a `subtotal` comparison before it is
  run on any production tenant.** One line. It has already run on demo-pharmacy-tn, which is fine (the 9
  affected documents are corrupt fixtures), but the asymmetry is real.
- **N2 (P2) — demo-pharmacy-tn's declaration still shows `vat_0 = 6.000` of VAT that does not exist**, from the
  6 documents the guard correctly refused to touch. Non-silent (the command warns), but it must be manually
  corrected before any filing from this tenant.

---

## V1 — document-level discount: **CLOSED**

`TaxCalculationService.php:150-198` (pass 1: per-rate pre-discount base) + `:200-210` (proration call) +
`:346-424` (`prorateDiscount()`, largest-remainder).

| probe | result |
|---|---|
| **R1** single rate 19% / 300.000, `discount_amount = 50.000` — the exact regression vector | `subtotal = 250.000`, `TVA_19 base = 250.000`, `amount = 47.500`. **Legally correct, identity exact.** (was: base 300.000 / tax 57.000) |
| **R2** 19%(100) + 7%(200) + 13%(300), discount 50.000 | bases `91.667 / 183.333 / 275.000` → Σ **550.000 == subtotal**, shares `8.333 + 16.667 + 25.000 = 50.000` exactly. Largest-remainder allocation loses nothing. |
| **R7** three equal buckets 100/100/100, discount **0.001** (remainder stress) | one unit lands on 19% (`99.999`), others untouched (`100.000`). Σ `299.999 == subtotal`. |
| **R8** discount 10.000 with an **exempt** bucket present | 19% → `95.000`, `TVA_EXEMPT` → `95.000`, Σ `190.000 == subtotal`. The exempt bucket takes its proportional share, as it must. |
| **R11** discount **exceeds** subtotal (100 line, discount 150.000) | no crash: `subtotal = -50.000`, base `-50.000`, tax `-9.500`, Σ ties. Stamp still `1.000` (fixed amount). Degenerate but coherent — unchanged from pre-round behaviour. |

## V3 — exempt / zero-rated turnover: **CLOSED**

`TaxCalculationService.php:112-114` now skips only a genuinely absent (`''`/NULL) rate.

- **R3** (the original A1 vector: 3 rates + a 0% line + line discounts + stamp) now emits
  `TVA_EXEMPT rate=0.00 base=50.000 amount=0.000`, and Σ LINE_ITEMS base `815.000 == subtotal` (was
  `765.000`, delta −50.000).
- **Live delta probe**: snapshotting a real invoice with a 50.000 exempt line + a 100.000 19% line moves the
  declaration by `OUTPUT|0.00 dBase = +50.000 / dVat = 0.000` and `OUTPUT|19.00 dBase = +100.000 / dVat = +19.000`.
  The turnover no longer vanishes.
- Live tenant: `base_0 = 113,806.028` of genuine exempt turnover now reaches the DGI form (298 `TVA_EXEMPT`
  rows). Previously that bracket held 130,834.976 of mis-flagged stamp base and zero real exempt turnover.

## V4 — declared base ties to subtotal: **CLOSED, and the scaling trade-off is sound**

`TaxCalculationService.php:170-176` (per-line `bcformat` to currency scale before summing, matching
`calculateSubtotal()`), with the pre-existing high-precision tax accumulator deliberately preserved at `:177-179`.

Σ LINE_ITEMS base == `subtotal` **exactly** in every probe: R1 (250.000), R2 (550.000), R3 (815.000),
R4 (1.497), R5 (100.998), R6 (171.497), R7 (299.999), R8 (190.000), R9 (1.000), R10 (0.900), R11 (−50.000).

- **R4** — the original drift vector (3 × qty `1.5000` × `0.333` = 0.4995 each): base `1.497 == subtotal 1.497`.
  Was `1.498` vs `1.497`. **Closed.**
- **R5** — same vector through the UNCONFIGURED branch: base `0.998` (was `0.999`), Σ `100.998 == subtotal`.

**The design question the coordinator asked — does the discount-bucket-only recompute reintroduce the scaling
concern, and do discounted + undiscounted buckets still tie together?** Both answered by probe:

- **R7 is the mixed case**: the 0.001 discount floors to a share for the 19% bucket only, so 19% goes through
  the `base × rate` recompute branch (`:222-232`) while 7% and 13% stay on the untruncated accumulator
  (`:213-221`). Σ base ties **exactly** (299.999) and every bucket's `base × rate == amount`. The two branches
  compose correctly.
- **R6** — a sub-scale truncation bucket (19%, net 1.497) and a clean bucket (7%, 200.000) with a 30.000
  document discount: bases `1.274 + 170.223 = 171.497 == subtotal`, identity holds on both.
- **R9 — `TaxCalculationScalingTest`'s gold case reproduced live** (1000 lines, qty `0.9999` × `0.0019` @ 19%):
  `subtotal = 1.000`, `base = 1.000` (**ties**), `amount = 0.300` — the high-precision accumulator is
  **unchanged**, so the catastrophic truncation is NOT reintroduced. `TaxCalculationScalingTest` green.
  In this pathological case `base × rate = 0.190 ≠ amount 0.300`; that is the documented trade. Note the
  snapshot rows are now *exactly consistent with the invoice's own printed header* (`subtotal 1.000`,
  `tax 0.300`) — which is what an auditor reconciles — whereas pre-round the base was `1.800`, matching
  neither. **Strictly better.**
- **R10** — same gold case + a `0.100` discount, forcing the recompute branch: base `0.900`, tax `0.171`
  (`base × rate` exact), Σ ties. See **N4** below for the discontinuity this exposes.

**Real-data confirmation.** Across all 457 invoice/credit-note documents on the live tenant carrying tax
details, `SUM(non-stamp tax_base)` equals `documents.subtotal` on **448**. The 9 exceptions are *exactly* the
9 documents whose own header is internally inconsistent (`subtotal + tax_amount ≠ total`) — see **N1**.

## V2 — credit notes reduce the declaration: **CLOSED**

`EloquentVatDataRepository.php:51-52`,
`SUM(CASE WHEN d.type = 'credit_note' THEN -dtd.tax_base ELSE dtd.tax_base END)` (same for `tax_amount`).

Live probe — a credit note snapshotted through the real `TaxCalculationService` → `snapshotTaxDetails()` path,
19% line of 42.017:

```
CN computed : subtotal=42.017 lineTax=7.983 docTax=0.600 total=50.600
STORED row  : TVA_19             rate=19.00 base=42.017  amount=7.983  stamp=f   <- POSITIVE, as ruled
STORED row  : STAMP_CREDIT_NOTE  rate=0.00  base=42.017  amount=0.600  stamp=t   <- excluded
declaration delta: OUTPUT|19.00  dBase = -42.017   dVat = -7.983
```

The `-42.017` convention verified end-to-end: storage stays positive (immutable-snapshot semantics preserved,
no sign overloading), aggregation subtracts. Arithmetic cross-check on the whole tenant: raw 19% non-stamp rows
sum to `base 17,033.863 / tax 3,236.426`; the repository reports `15,763.125 / 2,994.992`; the difference is
exactly `2 × 635.369` and `2 × 120.717` — the credit-note totals, flipped from +1× to −1×. `document_count`
still counts credit notes positively, which is correct.

`TaxSnapshotCreditNoteTest` is genuinely revived off `markTestSkipped` and drives the real
`CreditNoteController::confirm()` path — the Feature/Taxation skip count dropped 24 → 21.

## V5 — `ExpenseService` INPUT writer: **CLOSED (fix correct; still unexercised on live data)**

`ExpenseService.php:426-516` (`writeDeductibleVatSnapshot()`), called on **both** post branches
(`:343` LinkedCost, `:386` Generic).

- Deductible-proportion base via `ExpenseVatSplit::deductible($subtotal, $percent, $scale)` — the *same*
  `bcmul`/`bcdiv`/`bcround` shape as the VAT split, so both share one rounding convention. At 100% deductible
  the base is the subtotal unchanged.
- `firstOrCreate` replaced by a delete-then-create scoped to `sequence_order = 1` (`:506-511`), so a re-post
  can no longer leave a stale duplicate row double-counting input VAT, while an unrelated row at another
  `sequence_order` survives (pinned by the existing
  `test_unrelated_percentage_detail_does_not_absorb_the_expense_tva_snapshot`).
- The LinkedCost branch now writes. `LinkedCostExpenseTest::test_linked_cost_expense_snapshots_input_vat_when_metadata_carries_a_rate`
  asserts the row, the `base × rate == tax_amount` identity, **and** a non-zero declaration INPUT bucket via
  `EloquentVatDataRepository` — a real end-to-end assertion, not a unit stub. `ExpenseVatPostingTest` +
  `LinkedCostExpenseTest`: **14/14, 84 assertions**.
- The docblock (`:426-470`) flags both open questions for the expert-comptable — that `vat_amount`/`vat_rate`/
  `subtotal` are independently attested (so the identity is a common-case property, not a data-model invariant),
  and that reporting the deductible-proportion base rather than the full transaction face value is a
  declaration-mapping decision. **Correctly labelled as an open owner ruling, not silently resolved.**

Carried forward, unchanged: the live tenant's INPUT side is still empty (`input total_base = 0.000`,
`total_deductible_vat = 0.000`) because no VAT-bearing expense has been posted, and `supplier_invoice` remains
absent from `EloquentVatDataRepository.php:29`'s `whereIn`. The writer is fixed; the INPUT half of the
declaration has still never been exercised on real data.

## V6 — backfill: **CLOSED**

`app/Console/Commands/BackfillTaxDetailsCommand.php`.

**Idempotency verified.** Re-running the dry-run on the already-backfilled live tenant produces
BEFORE == AFTER byte-for-byte in every bucket — proof the applied run converged and that a second `--apply`
would be a no-op:

```
Scanned 452 document(s). Would rewrite 446. Skipped (signed total would change) 6.
  rate=0.00   STAMP  base 132434.975 -> 132434.975   tax  437.200 -> 437.200   rows 446 -> 446
  rate=0.00   VAT    base 115003.972 -> 115003.972   tax    6.000 ->   6.000   rows 304 -> 304
  rate=19.00  VAT    base  17033.863 ->  17033.863   tax 3236.426 -> 3236.426  rows 145 -> 145
  rate=21.00  VAT    base    500.000 ->    500.000   tax  105.000 ->  105.000  rows   5 ->   5
  rate=7.00   VAT    base   1057.140 ->   1057.140   tax   73.998 ->  73.998   rows  13 ->  13
```

**The 6 skips are verifiable and correctly reasoned** (I inspected each against its lines, not just the report):
- `INV-2026-0039 / 0070 / 0146 / 0416` — stored total `286.600`, recomputed `1.000`: these documents have
  **no `document_lines` at all** while their header claims `subtotal 240.000`. Recomputing would have zeroed
  their base. Guard held.
- `INV-2026-0319 / 0363` — stored total `101.000`, recomputed `122.000`: a single 21% line whose stored total
  predates the UNCONFIGURED-rate fallback. Guard held.

**Live guard probe (the coordinator's explicit ask).** I seeded a **sealed, hash-chained** document
(`fiscal_status = Sealed`, `fiscal_hash` set, `chain_sequence` set) whose stored total is the pre-V1-fix figure
— 300.000 @ 19% with a 50.000 document discount, stored `total = 308.000` — plus old-shape tax rows, then ran
the command with **`--apply`** inside a transaction:

```
Scanned 458 document(s). Rewrote 451. Skipped (signed total would change) 7.
  SKIPPED W4c-GUARD-0001 (…): stored total 308.000 != recomputed 298.500 -- needs manual review, NOT backfilled

ROWS AFTER --apply (UNTOUCHED old-shape):
  TVA_19             rate=19.00 base=250.000 amount=57.000 stamp=f
  STAMP_TAX_INVOICE  rate=0.00  base=250.000 amount=1.000  stamp=f
Header after: subtotal=250.000 tax=58.000 total=308.000 hash=cf568f7b7f83   (all unchanged)
```

The guard blocks under `--apply`, reports the document by number and id, and leaves rows, header and hash
untouched. `BackfillTaxDetailsCommandTest` (3 tests, 25 assertions) pins the same behaviour, including the
skip case at the exact V1-fix divergence (`stored total 307.000 != recomputed 298.500`) with an explicit
assertion that the row keeps its old `300.000` / `57.000` values.

## End-to-end: the open Aug-2026 period now reads sane

`VatReportGenerationService::generateSummary()` → `TunisiaVatStrategy::mapToDeclaration()`, live:

| | base | VAT | docs |
|---|---|---|---|
| OUTPUT 0.00 | 113,806.028 | **6.000** ← see N2 | 304 |
| OUTPUT 7.00 | 942.860 | 66.002 | 13 |
| OUTPUT 19.00 | 15,763.125 | 2,994.992 | 145 |
| OUTPUT 21.00 | 500.000 | 105.000 | 5 |
| INPUT | 0.000 | 0.000 | 0 |

`net_vat = 3,171.994`. Special items: `stamp_duty_count = 446`, `stamp_duty_total = 437.200` (was **0** before
`ada81fec9`), `retenue_source_total = 0.000`. DGI fields emitted: `base_0/vat_0`, `base_7/vat_7`,
`base_19/vat_19`, `base_21.00/vat_21.00` (see N8), `total_output_vat`, `total_deductible_vat`.

Compare to the pre-fix declaration recorded in the first gate: a fictitious rate-0.00 bracket of
**130,834.976 / 430.200**, 19% base inflated **+648.000**, 7% inflated **+700.000**, credit notes adding rather
than subtracting, zero exempt turnover, zero stamp reported. All of that is gone.

---

## New findings this round

### N1 — P2: the backfill's invariant guard is one-sided (`total` only, not `subtotal`)

`BackfillTaxDetailsCommand.php:108-115` compares only `$result->total` against `documents.total`. A document
whose header is internally inconsistent (`subtotal + tax_amount ≠ total`) passes the guard and has its rows
rewritten to a base that no longer ties to `documents.subtotal`.

On the live tenant **exactly 9** invoice/credit-note documents have such a header, and those same 9 are exactly
the drift set from the V4 real-data check (448/457 tie):

| document | header subtotal | Σ non-stamp base after backfill | delta |
|---|---|---|---|
| INV-2026-0029 | 250.000 | *(no VAT row at all)* | **−250.000** |
| CN-2026-0041 | 20.000 | 18.000 | −2.000 |
| CN-2026-0021 / 0022 | 99.667 | 99.656 | −0.011 |
| CN-2026-0020 | 99.668 | 99.660 | −0.008 |
| CN-2026-0009 / 0014 / 0018 / 0019 | 41.663 | 41.659 | −0.004 |

Worst case `INV-2026-0029`: header `subtotal = 250.000, tax_amount = 1.000, total = 1.000` on a **lineless**
document. The guard compared `1.000 == 1.000`, passed, and rewrote the rows to a single stamp row with
`tax_base = 0.000`. The credit notes are the mirror image — their stale field is `subtotal` (and, for
CN-2026-0041, a stale `line_total` that ignores its own 10% line discount), and the recomputed base is the
*more* correct value.

On this tenant every one of the 9 is a demo-campaign artifact and the recomputation is an improvement. But the
asymmetry means a real tenant's stale header would be silently re-based instead of reported. **Fix: add a
`subtotal` comparison to the same guard** (skip + report, identical shape to the existing check) before this
command is ever run on a production tenant. One line.

### N2 — P2: residual fictitious VAT in the live declaration — mandatory manual correction

The 6 documents the guard correctly refused to touch still carry `is_stamp_duty = false` on their
`STAMP_TAX_INVOICE` rows, so the live Aug-2026 declaration reports **`vat_0 = 6.000`** — 6.000 TND of VAT at a
0% rate that does not exist — plus **680.000** of stamp base folded into `base_0`
(`TunisiaVatStrategy.php:64-65` maps the bucket straight onto the DGI fields).

Down from 439 rows / 130,834.976 base / 430.200 VAT pre-backfill — a **98.6%** reduction — and entirely
non-silent (the command names all 6). But it is still wrong, and there is no in-app path to fix it (V6's
finding stands: no re-confirm for invoices). Requires an owner decision — repair the 6 source documents and
re-run, or correct manually on the declaration — **before any filing from this tenant.**

### N3 — P3: no audit trail of the rewrite

`snapshotTaxDetails()` (`TaxCalculationService.php:404,419`) deletes and re-creates with `created_at = now()`.
Every backfilled row on the live tenant now reads `2026-08-03 04:21`; the original snapshot timestamps and the
prior values are gone, and the command persists nothing (the before/after report is stdout only). For a
certification lane, recommend writing the report to a file or an audit table on every `--apply`, and/or a
`backfilled_at` marker column.

### N4 — P3: the tax method is discontinuous in the discount (sub-scale documents only)

A bucket that absorbs a discount share is recomputed as `trunc(base × rate)` (`TaxCalculationService.php:222-232`);
a bucket that absorbs none keeps the high-precision per-line accumulator (`:213-221`). On normal documents the
two agree exactly (verified R1/R2/R3/R5/R7/R8). On sub-scale-heavy documents they do not: the gold case yields
tax **0.300** undiscounted (R9) but **0.171** with a 0.100 discount (R10) — a 0.100 discount cuts VAT by 0.129.

This is the right trade (the alternative — truncated `base × rate` everywhere — reintroduces the catastrophic
truncation `TaxCalculationScalingTest` exists to prevent), and it is documented in the code. But it belongs in
[`docs/architecture/precision-contract.md`](../../architecture/precision-contract.md) as a known non-linearity,
not only in a comment.

### N5 — P3: `base × rate == amount` is exact everywhere except undiscounted sub-scale buckets

R9: `base = 1.000`, `amount = 0.300`, `base × rate = 0.190`. Log, don't block — as noted under V4, the rows are
now exactly consistent with the document's own header, which is the reconciliation an auditor performs.

### N6 — P3 (probed: **not reachable via the API**): a NULL `tax_rate` line breaks the tie

`TaxCalculationService.php:112-114` skips only `''`. Probe: an invoice with a 19% line (100.000) plus a
NULL-rate line (80.000) gives Σ base `100.000` vs subtotal `180.000` (**−80.000**), and with a 30.000 document
discount the taxed bucket absorbs the *whole* discount (base `70.000` instead of a proportional `83.333`),
under-declaring VAT. The column is nullable and `CreateDocumentRequest.php:131` / `UpdateDocumentRequest.php:109`
mark `lines.*.tax_rate` `nullable`.

**But I probed the real HTTP path twice** — omitting `tax_rate` entirely, and sending an explicit `null` —
and both stored `19.00` (a default is resolved upstream of `DocumentLine::create`). Live tenant: 0 rows with
a NULL rate. So this is reachable only by a direct DB / seeder / import write. Ticket a guard
(`bccomp($rateStr, '0', 2)` plus a NULL-bucket row), don't block. *(Both probe drafts were deleted — HTTP 204.)*

### N7 — P3: module-boundary placement of the new command

`BackfillTaxDetailsCommand` lives at `app/Console/Commands/` under namespace `App\Console\Commands` and imports
`App\Modules\Document\Domain\Document` and `App\Modules\Taxation\Domain\*` directly from outside any module.
Every other module keeps its commands inside itself (`app/Modules/{Compliance,POS,Tenant,Accounting,Channel,
Vehicle,Scheduling,PlatformIntegration}/**/Commands/`). `deptrac.yaml` defines no Console layer, so the ratchet
will not catch it. CLAUDE.md rule 6 — move to `app/Modules/Taxation/Infrastructure/Commands/`.

### N8 — P3 cosmetic (pre-existing): dotted DGI field keys for unmapped rates

`TunisiaVatStrategy.php:63` falls back to `$breakdown->taxRate` when a rate is not in `$rateFieldMap`, so the
live declaration emits `base_21.00` / `vat_21.00`. 21% is not a TN rate (these are campaign fixtures), but the
exporter should have a defined behaviour for an out-of-form rate rather than minting a field name with a dot in it.

---

## Regressions — full E-list, re-run

| Suite | Result |
|---|---|
| `tests/Unit/Taxation/` + `tests/Feature/Taxation/` | **242 tests, 843 assertions, 1 failure** — `FranceTaxConfigurationSeederTest` (`'20.0000'` vs `'20.00'`, `:29`), pre-existing and untouched by all four commits. **21 skipped, down from 24** (TaxSnapshotCreditNoteTest revived). |
| `CreditNoteMoneyLaneTest` + `ConversionChainVatIntegrityTest` + `InvoiceDraftDocumentTaxTest` + `TaxCalculationScalingTest` | **OK 20/20, 161 assertions** — the scaling gold case is explicitly green, confirming the preserved accumulator |
| `ExpenseVatPostingTest` + `LinkedCostExpenseTest` | **OK 14/14, 84 assertions** |
| `BackfillTaxDetailsCommandTest` | **OK 3/3, 25 assertions** |
| `tests/Feature/Document/IngressPrecisionTest.php` | **exactly 13 errors — the stated baseline**, all `ArgumentCountError` on `CreateDocumentRequest::__construct()` (`:24`), unrelated |
| PHPStan level 8 — `app/Modules/Taxation/`, `BackfillTaxDetailsCommand.php`, `ExpenseService.php` | **No errors** (re-confirmed on branch tip) |
| Pint — same paths | **pass** |
| E2E `documents-tax.spec.ts` (MTP-TAX-01/08/13) + `documents-totals.spec.ts` (MTP-DOC-01..05) | **6/6 passed** at `--workers=1` |

**Environment caveat (not attributable to this lane).** Mid-review a concurrent session left *uncommitted*
edits to `InstrumentLifecycleService` / `InstrumentReversalCancellerInterface` / `PaymentRefundService` in the
shared working tree, with the interface signature updated ahead of the implementation — a hard
`Fatal error: Declaration … must be compatible` on any full-app boot, which broke PHPStan and
`IngressPrecisionTest`. I re-ran both in a detached `git worktree` at `9e4ee8a70` with a hard-linked `vendor`
(autoloader verified resolving into the worktree, not the main repo), then again on the branch tip once that
session committed `670651ce5` / `3b2e96888`. Both runs agree. The reviewed code is byte-identical between
`9e4ee8a70` and `HEAD` (`git diff 9e4ee8a70 HEAD -- Taxation/ Expense/ BackfillTaxDetailsCommand.php` → empty).

---

## Required before the first TN filing (updated)

1. **N1** — add the `subtotal` comparison to the backfill guard *before running it on a production tenant*.
2. **N2** — owner decision on the 6 skipped documents on demo-pharmacy-tn (`vat_0 = 6.000`, `base_0 +680.000`).
3. **V5 carry-over** — expert-comptable ruling on the deductible-proportion INPUT base (flagged in the
   docblock, deliberately unresolved), and a ruling on whether `supplier_invoice` belongs in
   `EloquentVatDataRepository.php:29`. The INPUT side of the declaration has still never been exercised on
   real data.
4. **N3** — persist the backfill before/after report for the audit file.
5. Ticket: N4 (precision contract), N6 (NULL-rate guard), N7 (module placement), N8 (out-of-form rate keys),
   plus the still-open first-round V9 (`leftJoin` fan-out on `percentage_rate` alone).

## Probe artefacts

`W4c-regate.php` (R1–R11), `W4c-null.php`, `W4c-cn.php`, `W4c-live.php`, `W4c-guard.php` — scratchpad, not
committed; every one wraps its writes in a transaction and rolls back. Post-review verification:
`documents WHERE document_number LIKE 'W4c%'` → **0**; both HTTP probe drafts deleted (204). The
`document_tax_details` count moved only through the Playwright campaign runs. No commits, no pushes, no
migrations, and no `--apply` run outside a rolled-back transaction.
