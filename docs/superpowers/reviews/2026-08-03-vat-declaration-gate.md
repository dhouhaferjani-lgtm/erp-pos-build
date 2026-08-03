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
