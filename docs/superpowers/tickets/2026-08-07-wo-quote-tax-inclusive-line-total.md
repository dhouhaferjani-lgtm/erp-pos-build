# CRITICAL — Work-order → quote persists a tax-INCLUSIVE `line_total`; conversions then invoice VAT on VAT

**Severity:** CRITICAL. **Pre-existing** (NOT introduced by R2-B).
**Found by:** R2-B Document-conversion gate, finding C-1, 2026-08-07 — **probe-executed**, not a code-read.
**Vertical:** Otospex (workshop). **Recommendation: its own urgent pre-launch lane.**

This falsifies the R2-B lane's own out-of-lane finding 4, which recorded the
Workshop adapter as "already routes through `DocumentTotalsCalculator`,
discount-aware — not affected". The header IS discount-aware; the persisted
COLUMN is not, and the column is what survives conversion.

## The defect

`Workshop/WorkOrder/Infrastructure/Adapters/DocumentGenerationAdapter.php:199`

```php
'line_total' => $wol->line_total_incl_tax,
```

`line_total` on `document_lines` is by contract the line NET **before tax** —
that is the invariant `DocumentLine::computeLineTotal()` implements and that
every document controller now writes. The work-order line already carries the
correct value in a sibling column that is **never read here**:
`WorkOrderLineService.php:282` sets `$line->line_total_excl_tax = $excl;`
alongside `line_total_tax` and `line_total_incl_tax` (`:283-284`), so the
semantics are unambiguous and the right column already exists.

## Why the header masks it — and why the mask does not survive

- `DocumentGenerationAdapter` finishes with
  `DocumentTotalsCalculator::recalculate()` (`:59`, `:102`), which derives the
  header from `DocumentLine::calculateTotal()` — recomputed from
  quantity/unit_price/discount, **ignoring the stored column**. So the
  generated quote's own header looks right.
- `CopiesDocumentData::copyLine()` (`:139`) copies `line_total` **verbatim**,
  and `CopiesDocumentData::recalculateTotals()` (`:289-307`) rebuilds the
  target header **from the stored column**.

Conversion therefore reads the poisoned column that the generator's own header
calculation had papered over.

## Probe D (executed by the gate)

Work order: 1 × 100.000, 10% discount, VAT 20%.

| Document | subtotal | tax | total | stored `line_total` |
|---|---|---|---|---|
| WO-shaped quote | 90.000 | 18.000 | 108.000 | **108.000** ← tax-inclusive |
| → sales order | 108.000 | 21.600 | 129.600 | 108.000 |
| → invoice | 108.000 | 21.600 | **129.600** | 108.000 |

**A 108.000 job is invoiced at 129.600 — VAT charged on VAT, +20%** — on a
`FiscalCategory::TaxInvoice` document, i.e. hash-chained and immutable once
posted.

## No guard anywhere on the path

`QuoteToSalesOrderConverter::getConversionErrors()` (`:65-95`) validates type,
cancelled, draft, already-converted, expired, and empty-lines. There is **no
`work_order_id` / WO-origin predicate** — nothing stops a WO-generated quote
from entering the conversion chain.

## Fix shape

1. One line: `'line_total' => $wol->line_total_excl_tax` at
   `DocumentGenerationAdapter.php:199`.
2. Red test first: WO → quote asserts the persisted `line_total` is the NET
   value, and a WO → quote → order → invoice chain asserts byte-identical
   totals end to end (the R2-B `QuoteDiscountTotalsTest` chain test is the
   template — it now does the real `convert-to-invoice` hop).
3. Legacy-row assessment: any WO-generated quote already converted onward has
   an over-stated invoice. Detection = document lines on WO-origin documents
   (`work_order_line_id IS NOT NULL`) where `line_total` ≈ net × (1 + tax_rate/100)
   rather than net. Joins the accountant-disposition family alongside
   `2026-08-07-r2b-legacy-discount-blind-quotes.md`.

## Cross-references

- `2026-08-07-r2b-legacy-discount-blind-quotes.md` — same "stored column
  survives conversion, header recompute does not" mechanism.
- `2026-08-07-delivery-leg-discount-blind-line-totals.md` — third member of
  the same class.
