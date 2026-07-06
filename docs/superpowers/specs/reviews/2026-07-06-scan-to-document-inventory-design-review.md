# Inventory-Costing Design Review — Scan-to-Document Spec B (BL → goods receipt path)

> Reviewer: inventory-costing-reviewer (adversarial, code-grounded) · Date: 2026-07-06
> Scope: §8a / §6 / §7 of `specs/2026-07-06-scan-to-document-spec-b.md` + Tasks 5, 7 of
> `plans/2026-07-06-scan-to-document-plan.md`. Design-stage only (no implementation exists yet).
> Verified against dev code in worktree `apps/erp.scan-to-doc` (branch `feat/scan-to-document`).
> All citations are `apps/api/...` unless noted.

## Verdict: NEEDS-REVISION (design)

One BLOCKER, two MAJOR, three MINOR. The happy path is sound (WAC blends at working scale, GR-IR
posts fail-closed, idempotency key fits and replays cleanly), but three commit-time correctness
gaps must be closed in the Task 7 design before implementation, and the plan's "retry safe" claim
is materially false in one crash window.

---

## Q1 — BL commit with postImmediately=true: stock + WAC + GR-IR for a batch-tracked product

Functionally YES, with two required-field gaps the plan's `ReviewedPayloadData` does not enforce.

- Stock + WAC + GR-IR wiring is correct: `StandaloneReceiptService::execute` calls
  `goodsReceiptService->post($draft, actorId, true)` (StandaloneReceiptService.php:136) — the
  `true` is `failClosedGrir`, so `postFailClosedGrirIfRequested` fires
  `createGoodsReceiptGrIrEntry` (GoodsReceiptService.php:384-403, 592-599). WAC cost flows from the
  PO line landed cost (`$line->landed_unit_cost ?? $line->unit_price`, GoodsReceiptService.php:482)
  and the auto-PO sets `landed_unit_cost = unitPrice` (StandaloneReceiptService.php:289). Note
  `receiptMaps()` returns `receivedUnitPrices => []` always (StandaloneReceiptService.php:306,326),
  so there is NO receipt-time price override — WAC cost == the committer-supplied line `unitPrice`.
  Good, provided that price is positive (see Q2).

- **[MAJOR] Batch is MANDATORY for batch-tracked products and `expiry_date` is NON-NULL required —
  `ReviewedPayloadData` makes `batch` nullable, so a batch-tracked line committed without a batch
  throws a raw `DomainException` at post time.** GoodsReceiptService.php:476-478 throws "Batch data
  is required for batch-tracked product" when `requires_batch_tracking` and no `batchData[line]`.
  The lot mint `BatchStockService::findOrCreateBatch` takes `string $expiryDate` (non-nullable,
  BatchStockService.php:120-128) — a BL line without an expiry cannot be committed. There is **no
  default-expiry fallback on the receipt path**: `ensureDefaultBatch`'s shelf-life default
  (BatchStockService.php:60-88) is used only by the opening-balance path, NOT by the GR receive
  path. `manufacturing_date` IS optional (StandaloneReceiptLineInput.php:10, findOrCreateBatch
  arg `?string $manufacturingDate = null`) — so the plan correctly omits it. Fix: the committer /
  `CommitDocumentIngestionRequest` must (a) hard-require `batch{batch_number, expiry_date}` for
  every line whose matched product is batch-tracked and reject with a clean 422 (not let the domain
  exception surface as a 500), and (b) define a policy for BL lines that genuinely have no printed
  expiry (reject vs. a stated default). Parapharmacy defaults EVERY product to batch tracking, so
  this is the majority case, not an edge.

## Q2 — Price fallback "BL line price else current product purchase price"

- 'current product purchase price' = `Product::purchase_price` (Product.php:39, 101, 157) — a
  `decimal(N,3)` column that is **`string|null` (nullable)**.
- **[MAJOR] A null/zero fallback price corrupts WAC and is not guarded.**
  `StandaloneReceiptLineInput::unitPrice` is a **non-nullable `string`** (StandaloneReceiptLineInput.php:16)
  and the auto-PO builder feeds it straight into `CurrencyScale::bcformatStrict($line->unitPrice, $scale)`
  with **no positivity check** (StandaloneReceiptService.php:266, 289) — unlike the received-price
  override path which does enforce `> 0` (GoodsReceiptService.php:155, 490) but is unused here
  (`receivedUnitPrices` is always `[]`). Consequences: if the committer resolves the fallback to
  `null`/empty → `bcformatStrict` throws (500); if it coerces to `'0'` → WAC records a
  zero-cost paid purchase and blends it into the running average (recordPurchase blends
  `landedUnitCost` at working scale, WeightedAverageCostService.php:224, 237, 246-248), biasing the
  average DOWNWARD — silent WAC corruption at rest. The plan's claim that the fallback is safe is
  NOT safe when `purchase_price` is null or 0 (new product, never purchased). Fix: the BL committer
  must reject any paid line where neither an extracted price nor a positive `product.purchase_price`
  exists (clean 422, surfaced per-line in the review UI), never emit `'0'` or null into the auto-PO.

## Q3 — Idempotency: key validity, replay, crash-before-Committed

- `'ing-' . <uuid>` = 40 chars ≤ 64 — valid (assertInput length guard, StandaloneReceiptService.php:167).
  Unique index is `(company_id, idempotency_key)` (migration
  2026_07_06_130000_create_procurement_idempotency_keys.php:22) — replay detection works.
- Clean happy replay: both `purchase_order_id` and `goods_receipt_id` present →
  `existingResult` returns the SAME `GoodsReceiptResult` (StandaloneReceiptService.php:342-348) —
  no double receive. Good.
- **[BLOCKER] "posted but crashed before the key's `goods_receipt_id` was written" → replay
  DOUBLE-MOVES stock and orphans the posted receipt.** The `goods_receipt_id` write
  (StandaloneReceiptService.php:144-150) is **outside** the receipt-post transaction (116-137) and
  is **not wrapped in try/catch**. If the process dies — or that UPDATE itself fails — after the
  receipt posted (stock moved, GR-IR written, committed), the idempotency row is left
  `{purchase_order_id set, goods_receipt_id NULL}` with a live posted receipt. On replay:
  `existingResult` cannot match the receipt (gr_id null, line 342 fails), falls through to
  line 351-357, sees the PO is `Confirmed`, and returns the **Document (PO)**. `execute` then
  re-enters receipt creation (line 107-137) → `assertQuantitiesWithinRemaining` sees
  `remaining == 0` and throws (GoodsReceiptService.php:306) → `compensateFailedReceiptCreation`
  **cancels the PO and DELETES the idempotency key** with no guard for the already-posted receipt
  (StandaloneReceiptService.php:367-382). The next commit re-inserts a fresh key, builds a NEW
  auto-PO and a NEW posted receipt → **second stock movement for the same BL**, plus an orphaned
  first receipt against a cancelled PO. The committer never gets the first receipt id back. This
  directly contradicts the plan's Task 7 assertion that "the shipped idempotency delete-on-
  compensation makes retry safe." It is a pre-existing property of `StandaloneReceiptService`, but
  the scan committer inherits it and the plan explicitly relies on the false safety claim.
  Fix (any of): write `goods_receipt_id` inside the same transaction as the post; have
  `existingResult` recover a posted `GoodsReceipt` by `purchase_order_id` before deciding to
  re-receive; and/or refuse compensation when a posted receipt exists for the PO. At minimum the
  design must stop asserting retry-safety and document the residual window.

## Q4 — Uninvoiced-receipt-line suggestion query (Task 5)

This feeds the SI committer (Task 8, treasury domain) but was asked here.
- **[MINOR] The plan's `quantity_received - quantity_invoiced > 0` formula does not match the
  shipped matcher grain and omits the paid/free split.** The shipped window is computed on the
  RECEIPT line, not the PO line: `matchableQty = received_qty − quantity_invoiced` and a SEPARATE
  `freeMatchableQty = free_qty − free_quantity_invoiced` (ReceiptLineConsumptionPlanner.php:78-94;
  aggregated in SupplierInvoiceMatcher::matchableQty:149-173). `quantity_received`/`quantity_invoiced`
  are DocumentLine (PO) columns; the receipt line carries `received_qty`/`free_qty`. Fix: build
  suggestions from `ReceiptLineConsumptionPlanner::matchableQty` (and keep free separate) rather
  than a raw PO-column subtraction, so free-only receipt lines are not offered as paid-invoice
  suggestions. The plan already says "reuse ... do NOT reimplement FIFO" — the Task 5 formula text
  contradicts that instruction and should be corrected.

## Q5 — Double-move / ledger corruption under retry or concurrency

- The concrete double-move risk is the Q3 crash window (BLOCKER) — that is the finding.
- No NEW concurrency defect: the scan path only calls the shipped `StandaloneReceiptService`, which
  posts under `ProductCostLock` in the canonical order (advisory → stock_level → product;
  WeightedAverageCostService.php:155-218, GoodsReceiptService.php:233-281). No hand-rolled locking,
  no reversed lock order introduced. WAC running average is persisted at cost/working scale with NO
  mid-stream currency rounding (WeightedAverageCostService.php:239-248) — bias-safe.
- **[MINOR] WAC's `scale()` uses a no-arg `getScale()`** (WeightedAverageCostService.php:50-52),
  which throws outside HTTP-request context. It is safe here ONLY because commit is a synchronous
  HTTP request (POST /commit), not the queued `ExtractDocumentJob`. Design guardrail: the commit
  MUST stay synchronous / request-context — if any future refactor moves the receipt commit onto
  the `ingestion` queue, `workingScale()`→`getScale()` will throw (rule 19/20). Add this as an
  explicit constraint in Task 7.
- **[MINOR] Currency mismatch is silently ignored.** `StandaloneReceiptInput` has no currency
  field; the auto-PO always uses `company->currency` for scale and pricing
  (StandaloneReceiptService.php:188, 215). `ReviewedPayloadData.currency` is dropped on the BL
  path. A foreign-currency BL is booked at company-currency scale/price. Pre-existing and out of
  MVP scope, but the review UI should not imply the extracted currency is honored on the receipt.
  (Also minor mapping: `ReviewedLineData.freeQuantity`/`unitPrice` are nullable but
  `StandaloneReceiptLineInput` requires non-null strings — committer must default freeQuantity→'0'
  and resolve unitPrice per Q2.)

## What to fix before merge (Task 7 design)
Close the BLOCKER (crash-window double stock — stop claiming retry-safety; make gr_id write atomic
or add posted-receipt recovery/compensation guard), and the two MAJORs (require batch+expiry for
batch-tracked lines with a clean 422; reject null/zero fallback price so WAC is never fed a
zero cost). MINORs (receipt-line matchable grain, keep commit synchronous, currency handling) are
correctness/clarity nits to fold into the same revision.
