# Delivery leg — discount-blind `line_total` on two paths (same class as R2-B)

**Severity:** HIGH. **Pre-existing** (NOT introduced by R2-B).
**Found by:** R2-B Document-conversion gate (C-2) + R2-B implementer out-of-lane sweep, both confirmed by the precision gate, 2026-08-07.

Two independent members of the R2-B defect class — a `line_total` written
GROSS while the discount columns are persisted alongside it — on the delivery
leg. R2-B itself was scoped to quotes, so both were correctly deferred; they
need their own lane.

---

## (i) `SalesOrderToDeliveryNoteConverter` — full-delivery FEFO batch branch recomputes GROSS

**Reachability:** high-confidence but **code-read, NOT executed** — confirming
it needs a batch-tracked FEFO fixture. The arithmetic is verified; the
reachability is the open item.

`copyLinesForFullDelivery()`, batch-split branch:

- `:292` — `$lineTotal = bcmul($batchQty, $unitPrice, $this->scale());` — no
  discount applied at all.
- `:304` — `'discount_percent' => $line->discount_percent,` — the percent is
  still **carried and displayed**.
- `:305` — `'discount_amount' => null, // Fixed discount not prorated per batch`.
- `:307` — `'line_total' => $lineTotal,` — the gross value.

So the delivery-note line shows a 10% discount while its `line_total` has no
discount in it.

**The two branches of the same class disagree.** The partial-delivery twin
`copyLinesForPartialDelivery()` computes the same gross at `:422` and then
**does** apply the percent at `:424-431`
(`bcsub($lineTotal, bcmul($lineTotal, bcdiv($discountPercent,'100',4), scale))`).
Full delivery and partial delivery of the *same order line* therefore produce
different money. (Note both branches drop the flat `discount_amount`; the
full-delivery branch additionally drops the percent.)

**Propagation:** `DeliveryNoteToInvoiceConverter.php:210` copies
`'line_total' => $dnLine->line_total ?? '0.00'` **verbatim**, and the header is
rebuilt from that stored column. A discounted 216.000 order re-inflates to a
**240.000 invoice** that still displays the 10% discount — and
`TaxCalculationService` (which recomputes NET from
`DocumentLine::calculateTotal()`) then disagrees with the stored subtotal on a
posted fiscal document.

**Fix shape:** route both branches through `DocumentLine::computeLineTotal()`
— the same one-call fix R2-B applied to `QuoteController`. Decide explicitly
what a per-batch split does with a line-level flat `discount_amount`
(prorate by batch quantity, or assign wholly to the first slice); the current
`null` silently discards it in BOTH branches.

---

## (ii) `DeliveryNoteController::store()` — same store-path defect R2-B fixed for quotes

Identical shape to the R2-B defect, gate-confirmed (**no `computeLineTotal`
call anywhere in the file**):

- `:236` — `$lineTotal = bcmul($quantity, $unitPrice, $this->scale());`
- `:256-257` — persists `discount_percent` and `discount_amount` on the very
  same line.

A delivery note created directly through the API with a line discount stores a
gross `line_total`, which then flows into `DeliveryNoteToInvoiceConverter`
verbatim exactly as in (i).

**Fix shape:** literally the R2-B patch — add the `lineDiscount()` reader and
route `:236` through `DocumentLine::computeLineTotal()`. See
`QuoteController.php:78` / `:225` / `:273` / `:398` for the applied precedent,
and `InvoiceController.php:88`/`:276`/`:321` for the original.

---

## Not affected (checked, recorded so it is not re-investigated)

`ReturnNoteController` (`:331`, `:464`) also uses a bare `bcmul`, but the
controller **never persists the discount columns** — so its stored
`line_total` is internally consistent. The (lesser, separate) issue there is
that a submitted line discount is **silently dropped** rather than
mis-totalled.

## Cross-references

- `2026-08-07-r2b-legacy-discount-blind-quotes.md` — the quote-side member,
  plus the detection-SQL pattern reusable here.
- `2026-08-07-wo-quote-tax-inclusive-line-total.md` — CRITICAL third member
  (tax-inclusive rather than discount-blind, same "stored column survives
  conversion" mechanism).
