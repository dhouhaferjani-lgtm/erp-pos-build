# Legacy discount-blind quotes — detection + disposition (R2-B merge condition)

**Raised by:** R2-B dual gate (precision gate I-1, conversion gate I-2), 2026-08-07.
**Branch:** `fix/r2b-quote-totals`. Record-only — **no product code in this ticket**.
**Status:** OPEN — needs an OWNER disposition decision before/at deploy.

## Why this exists

R2-B fixed `QuoteController::store()`/`update()` so a quote's stored
`subtotal`/`tax_amount`/`total` and each line's `line_total` are NET of the
line's own discount. The fix is **write-path only**. Every quote persisted
BEFORE the fix keeps its discount-blind (gross) `line_total`, and nothing in
the normal lifecycle repairs it:

- `confirm()` writes only `tax_amount` and `total`
  (`QuoteController.php:545-548`) — never `subtotal`, never `line_total`.
- `CopiesDocumentData::copyLine()` (`:139`) copies `line_total` **verbatim**,
  and `recalculateTotals()` (`:289-307`) rebuilds the target header **from
  that stored column** — not from the discount-aware
  `DocumentLine::calculateTotal()`.

So confirming a legacy quote does not merely fail to repair it: it turns a
self-consistent-but-wrong draft into a self-**inconsistent** confirmed
document, and conversion then discards even that partial correction.

## Per-path truth table (probe-verified by the conversion gate)

Legacy pre-fix row: 2 × 100.000 @ 10%, VAT 20% → stored `line_total 200.000`,
header `200 / 40 / 240`. Canonical is `180 / 36 / 216`.

| Path taken on a pre-fix quote | Resulting header | Wrong document? |
|---|---|---|
| left as draft | `200 / 40 / 240` | wrong, but non-fiscal |
| → `confirm` | subtotal **200.000 unchanged**, tax 36.000, total 216.000 | **YES — newly incoherent** (200 + 36 ≠ 216) |
| → lines-less `PATCH` → `confirm` | identical; a lines-less PATCH leaves `200 / 40 / 240` intact | YES |
| → `PATCH` re-sending `lines` | fully repaired to `180 / 36 / 216` | no — this is the only self-heal |
| → `confirm` → `convert-to-order` | order `200 / 40 / 240` — fully re-inflated, and now divergent from its own source quote's confirmed 216.000 | **YES — propagates downstream** |

## Detection query (per tenant DB)

Flags quote lines whose stored `line_total` exceeds the discount-computed net
by more than a rounding tolerance. Tolerance `+0.0005` — half a millime, below
any real discount and above `decimal(15,3)` round-trip noise.

```sql
-- Run per tenant_<uuid> database.
SELECT
    d.id                AS document_id,
    d.document_number,
    d.status,
    d.fiscal_status,
    d.document_date,
    d.currency,
    d.subtotal          AS header_subtotal,
    d.tax_amount        AS header_tax,
    d.total             AS header_total,
    dl.id               AS line_id,
    dl.line_number,
    dl.quantity,
    dl.unit_price,
    dl.discount_percent,
    dl.discount_amount,
    dl.line_total       AS stored_line_total,
    GREATEST(
        ROUND(
            (dl.quantity * dl.unit_price)
            - CASE
                WHEN COALESCE(dl.discount_percent, 0) <> 0
                    THEN (dl.quantity * dl.unit_price) * (dl.discount_percent / 100.0)
                ELSE COALESCE(dl.discount_amount, 0)
              END
        , 3),
        0
    )                   AS expected_net_line_total,
    dl.line_total - GREATEST(
        ROUND(
            (dl.quantity * dl.unit_price)
            - CASE
                WHEN COALESCE(dl.discount_percent, 0) <> 0
                    THEN (dl.quantity * dl.unit_price) * (dl.discount_percent / 100.0)
                ELSE COALESCE(dl.discount_amount, 0)
              END
        , 3),
        0
    )                   AS overstatement
FROM documents d
JOIN document_lines dl ON dl.document_id = d.id
WHERE d.type = 'quote'
  AND d.deleted_at IS NULL
  AND (COALESCE(dl.discount_percent, 0) <> 0 OR COALESCE(dl.discount_amount, 0) <> 0)
  AND dl.line_total > GREATEST(
        ROUND(
            (dl.quantity * dl.unit_price)
            - CASE
                WHEN COALESCE(dl.discount_percent, 0) <> 0
                    THEN (dl.quantity * dl.unit_price) * (dl.discount_percent / 100.0)
                ELSE COALESCE(dl.discount_amount, 0)
              END
        , 3),
        0
      ) + 0.0005
ORDER BY d.document_date DESC, d.document_number, dl.line_number;
```

Companion query — confirmed quotes that are already internally incoherent
(the state `confirm()` creates), useful as the accountant-facing list:

```sql
SELECT d.id, d.document_number, d.document_date, d.currency,
       d.subtotal, d.tax_amount, d.total,
       (d.subtotal + d.tax_amount) - d.total AS incoherence
FROM documents d
WHERE d.type = 'quote'
  AND d.deleted_at IS NULL
  AND d.status <> 'draft'
  AND ABS((d.subtotal + d.tax_amount) - d.total) > 0.0005
ORDER BY d.document_date DESC;
```

> The SQL deliberately uses native numeric arithmetic (not bcmath) because it
> runs in the database as a **detection/reporting** aid only. It must not be
> used to WRITE corrected values — any backfill has to go through the PHP
> `DocumentLine::computeLineTotal()` path so the currency scale and the
> zero-floor are applied identically to the write path.

## Disposition — OWNER DECISION REQUIRED

Two mutually exclusive options; this is **not** an implementer call:

1. **Backfill migration** — recompute `line_total` + header for affected
   quotes through the canonical pipeline. Cheap while quote volume is near
   zero pre-launch. Note that quotes are non-fiscal — `quote` falls through
   the `default => self::NonFiscal` arm of
   `FiscalCategory::fromDocumentType()` (`FiscalCategory.php:38-47`) — so
   this does not disturb a fiscal chain. But a *converted* descendant (sales
   order → invoice, `FiscalCategory::TaxInvoice`) may already be chained, and
   a quote-scoped backfill does NOT reach it.
2. **Accountant-disposition list** — leave the rows, hand the detection
   output to the accountant, same treatment as the stranded `19.000`, the
   C-2 warehouse fiscal sale, and the persisted `-75.000` line in
   `2026-08-07-discount-lane-out-of-lane-findings.md` §4.

This ticket joins the **Phase-0.3 detection-query family** of plan v4
(`2026-08-07-remediation-round-2-plan.md`, step 0.3 "Detection queries per
tenant → accountant-disposition list").

## Coverage gap worth closing separately

`AuditDiscountsCommand` covers `sales_order` and `invoice` only — **quotes are
uncovered today** (`Treasury/Presentation/Console/AuditDiscountsCommand.php:126`
and `:178`, both `whereIn('type', ['sales_order', 'invoice'])`; the restriction
is documented as deliberate at `:22`). Whichever disposition is chosen,
extending that command to `quote` would make this class of drift
self-detecting rather than requiring a hand-run SQL sweep.
