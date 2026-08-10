# DN factory drops per-line `location_id` and silently degrades a failed FEFO allocation

**Severity:** HIGH. **Pre-existing** (byte-identical behaviour inherited from
`SalesOrderToInvoiceConverter::createDeliveryNoteForOrder`), but **NEWLY
LOAD-BEARING**: under `require_delivery_first` this factory is on the MANDATED
path for every standalone goods invoice.
**Raised by:** 3E code gate — inventory **P2-7**, 2026-08-10.
**Route:** 3A / 3C. **Do not fix in 3E** (gate ruling: pre-existing, on the
required path, ticket only).

---

## Defect 1 — per-line `location_id` is dropped

`DeliveryNoteFromDocumentFactory::createDraftFrom()` stamps ONE
`location_id` (the caller-resolved document location) on the delivery note and
`copyLine()` copies no line-level location. A multi-location invoice whose lines
name different locations therefore produces a delivery note that decrements **one
location for all lines**.

Two consequences, both silent:

- Stock leaves a warehouse the goods were never in.
- If the product has **no `stock_levels` row at that location**,
  `WeightedAverageCostService` degrades to a **no-op**: no movement, no exception,
  no COGS. That is exactly the population **D-f** exists to detect — arriving from
  the very path the compliance gate now mandates.

## Defect 2 — a failed FEFO allocation degrades to an unbatched line

Same file: when `FEFOInventoryService::suggestBatchesForSale()` reports
`fullyFulfilled = false`, the factory falls back to `copyLine()` (one unbatched
line) and emits a `Log::warning` only.

The delivery note then confirms, **location stock decrements, batch balances do
not**. For a batch-tracked catalogue — parapharmacy is batch-tracked by default —
that is a permanent divergence between location stock and batch stock, created by
a warning nobody reads.

## Why it matters more now

Before 3E this factory ran only on the sales-order conversion path, which an
operator chose. After T25b/T25c it is the ONLY compliant way to post a standalone
goods invoice under `require_delivery_first`, so both behaviours move from
"occasional" to "on the required path for every tenant".

Related, same file, and named separately by the gate as **P2-8**: the WAC exit
path is float-typed — `DeliveryNoteService.php:257` casts `(float)` into
`WeightedAverageCostService` (`:368-375`). Rule 19 violation, byte-identical to
base, ticketed alongside this one.

## Fix shape (for 3A/3C, not 3E)

1. Copy `location_id` per line, and resolve the document location only as the
   fallback — mirroring `DeliveryNoteService::issueStock()`, which already resolves
   `line.location ?? document.location` per line, so the generator and the issuer
   currently disagree about where goods are.
2. Decide the FEFO failure policy explicitly: REFUSE (typed, actionable) rather
   than degrade, or degrade with a persisted, surfaced marker — but not a log line.
3. When the per-line location lands, revisit
   `DeliveryComplianceGate::assessGuidedDeliveryFeasibility()`, which deliberately
   asks for ONE document-level location today precisely because the generator
   stamps one (fix round 1, P2-3 — the predicate must keep matching the act).

## Cross-references

- 3E gate: `gate-w3e-code-review-inv.md` P2-7 (and P2-8, float on the WAC exit).
- D-f detector: `UndeliveredGoodsLineScanner` — this is one of the two named
  silent skips it exists to catch.
