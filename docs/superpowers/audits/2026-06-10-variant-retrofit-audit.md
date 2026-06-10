# Variant Retrofit Audit — 2026-06-10

**Worktree:** `apps/erp.refund-completion` (branch `feat/refund-flow-completion`, based on origin/dev `3fcec7227`, includes PR #182 variant-aware transfers)
**Scope:** every path reading/writing `StockLevel` / `stock_levels`, `ReceiptLine` / `pos_receipt_lines`, or batch allocations that does NOT thread `variant_id`. Feeds the POS refund-flow completion session.
**Method:** read-only sweep of all `StockMovement::create` writers (a bounded set of 7 files), all `StockLevel::`/`stock_levels` query callsites, the POS sale/return/void/exchange/hold/order pipelines server-side, and the apps/pos offline layer (receiptService, syncService, qr-index, refund flow, holds, Z report).

## Summary

The T2 variant retrofit (PR #169) made the **sale-side write path fully variant-aware** (`ReceiptCreationService` threads `variant_id` into receipt lines, the stock decrement, the `stock_movements` row, and variant-scoped FEFO batch consumption), and PR #182 made **transfers** variant-aware. The **reverse paths were never retrofitted**: `ReceiptReturnService::restoreStock` and `ReceiptVoidService::reverseStockMovement` both query `stock_levels` by `product_id + location_id (+ company_id)` only. Because the post-T2 unique indexes are *partial* (`stock_levels_non_variant` WHERE variant_id IS NULL, `stock_levels_with_variant` WHERE variant_id IS NOT NULL), a product with variants has multiple rows per (product, location) and an unscoped `->first()` restocks an **arbitrary row** — the confirmed variant-refund restock bug. The return pipeline additionally **drops `variant_id` from the return receipt lines it writes** and performs **no batch restitution at all**. A second systemic fact dominates everything refund-related: production sales arrive at the server via the fiscal-event projection (`PosCoreReceiptProjection`), whose canonical `LineItemDTO` does **not** carry `variant_id` — so server `pos_receipt_lines.variant_id` is NULL for all projection-path receipts, and the projection's own decrement deliberately hits the `variant_id IS NULL` stock row (documented T2 Phase-2 gap). The refund fix must therefore thread `originalLine->variant_id` *when present* (NULL→NULL restore is symmetric with the projection decrement; non-NULL→variant restore is symmetric with the draft-path decrement) rather than try to re-derive variant identity. Outside the refund path, a handful of older readers/writers (opening balances, import upsert, reservations, marketplace, composite availability, stock-alert report, order-to-receipt) still key by product only — tickets below.

## Schema facts (verified)

| Table | variant_id? | Migration | Notes |
|---|---|---|---|
| `stock_levels` | YES, nullable | `tenant/2026_06_02_100005` | Partial unique indexes: `(tenant, product, location) WHERE variant_id IS NULL` and `(tenant, product, variant, location) WHERE variant_id IS NOT NULL`. Legacy `(tenant, product, location)` unique DROPPED → unscoped product+location queries can match multiple rows. |
| `stock_movements` | YES | `tenant/2026_06_02_100006` | Written by sale paths; NOT by return/void. |
| `pos_receipt_lines` | YES, nullable | `tenant/2026_06_02_100010` | CHECK: variant requires product. Populated by `ReceiptCreationService` (draft path) only; projection path writes NULL. |
| `pos_receipt_line_batch_allocations` | YES (column exists) | `tenant/2026_06_02_100012` | **Never written** — `ReceiptCreationService::allocateBatches` omits it. Variant identity recoverable via `batch_id` (batches are variant-scoped per PR #182). |
| `pos_order_lines` | YES | `tenant/2026_06_02_100011` | Never populated — `OrderManagementService::addLine` hardcodes `variant_name => null`, no variant_id. |
| `stock_reservations` | YES | `tenant/2026_06_02_100007` | Reservation events V2 read it, but `StockReservationService` StockLevel queries don't filter by it. |

## Findings table

| # | Path (file:line) | What it does | Variant-aware? | Refund-relevant? | Action |
|---|---|---|---|---|---|
| 1 | `apps/api/app/Modules/POS/Application/Services/ReceiptReturnService.php:995` (`restoreStock`) | Return restock: `StockLevel::where(product_id, location_id, company_id)->first()` — no variant predicate | **NO** | **YES** | **FIX in this session** |
| 2 | `ReceiptReturnService.php:789-806` (`computeReturnTotals` line array) | Builds return `pos_receipt_lines` copying original line fields — **omits `variant_id`** | **NO** | **YES** | **FIX in this session** |
| 3 | `ReceiptReturnService.php` (whole file) | No batch restitution on return — batch-tracked returns never restore `inventory_batch_stock` | n/a (gap is batch, not variant) | YES (correctness of batch stock after refunds) | **FIX or explicit-defer decision this session** (see guidance) |
| 4 | `apps/api/app/Modules/POS/Application/Services/ReceiptVoidService.php:144` (`reverseStockMovement`) | Void restock: same unscoped product+location+company query | **NO** | **YES** (void is part of refund surface) | **FIX in this session** |
| 5 | `ReceiptVoidService.php:155` | Void restock adds at **scale 2** (`bcadd(..., 2)`) vs canonical quantity scale 4 | n/a (precision) | YES | **FIX while touching** (one-line, scale 4) |
| 6 | `ReceiptVoidService.php:183-205` (`reverseBatchAllocations`) | Restores `inventory_batch_stock` keyed by `batch_id + location` | **YES by construction** (batch_id is variant-scoped since PR #182) | YES | OK — no variant change needed. (Side note: raw `DB::raw("quantity + {$allocation->quantity}")` string interpolation; value is internal numeric-string, but worth parameterizing when touched.) |
| 7 | `apps/api/.../Services/ExchangeService.php` | Composes `processReturn` (ExchangeDeferred) + `createReceipt`. **Route-less / inert** — zero controller/route references; only DTO + doc mentions | Inherits #1/#2 on return half; sale half variant-aware | NO (inert) | Ticket note only — fixing #1/#2 fixes it transitively |
| 8 | `apps/api/.../Services/ReceiptCreationService.php:326,647,883-934` | Sale path: receipt line `variant_id`, variant-scoped stock decrement, `stock_movements.variant_id`, variant-scoped FEFO (`allocateBatches` → `consumeBatchesAtomically(variantId:)`) | **YES** | YES (reference implementation) | By design OK |
| 9 | `ReceiptCreationService.php:~1355` (`allocateBatches`) | Writes `ReceiptLineBatchAllocation` rows **without** `variant_id` (column exists per migration 100012) | PARTIAL (denormalization only; batch_id carries it) | NO | Ticket |
| 10 | `apps/api/.../Projections/PosCoreReceiptProjection.php:550-558, 817-855` | Fiscal-event projection: canonical `LineItemDTO` has no `variant_id` → writes NULL on all projection-path `pos_receipt_lines`; decrement scopes to `variant_id IS NULL` row. Documented T2 Phase-2 deferral (canonical schema bump). Mechanism itself (`decrementStock` :884-886) is variant-capable. | PARTIAL (documented gap) | **YES (constraint on the fix, not a fix target)** | By-design-deferred — do NOT fix here (canonical payload is signed) |
| 11 | `apps/api/.../Services/OrderManagementService.php:242` | Table-service order lines: `variant_name => null`, no `variant_id` ever written to `pos_order_lines` | **NO** | NO | Ticket |
| 12 | `apps/api/.../Services/OrderToReceiptService.php:45-49` | Order→receipt conversion drops `variant_id` (doesn't map `$line->variant_id` into createReceipt lines) | **NO** | NO | Ticket (same ticket as #11) |
| 13 | `apps/api/.../Services/HeldOrderService.php` | Server holds: cart snapshot stored as pass-through JSON (only scales qty/price fields) — `variant_id` preserved if client sends it | YES (pass-through) | NO | OK |
| 14 | `apps/api/.../Services/ReportGenerationService.php` | Z/X server reports aggregate receipts/payments (joins `pos_receipt_payments`/`pos_receipts`) — no per-product grain | n/a (variant-neutral) | NO | OK — Z does not need variant granularity |
| 15 | `apps/api/.../Services/PosAnalyticsService.php:113,225` | Top-products / discount analytics group by `pos_receipt_lines.product_name` — variants merge into one bucket (or split only if name differs); no variant column in grouping | **NO** (cosmetic grain) | NO | Ticket (low) |
| 16 | `apps/api/.../Services/ReceiptQrIndexSyncService.php:44-75` | qr-index pull: header-only DTO (receiptUuid = **server** Receipt.id, qr_token, receipt_number, terminal_id, posted_at, total, currency, partner_id). **No line data → no variant_id** | n/a (no lines) | **YES (fact)** | OK as-is for restock fix; line-level data would come from server lookup at refund time |
| 17 | `apps/pos/src/lib/offline/receiptService.ts:424-430` | Local offline receipt lines JSON **carries `variant_id`** per line (out-of-band from fiscal payload) | YES | YES | OK |
| 18 | `apps/pos/src/lib/sync/syncService.ts:1682-1705` (`unpackCompositeIdsOnLines`) | Sync passes lines through with `{...obj}` spread → `variant_id` survives in the legacy payload; BUT new-sale server-authoring is retired (410, routes.php §14.2) so production ingest is the fiscal-event path (#10) | PARTIAL | YES (fact) | OK / superseded by #10 |
| 19 | `apps/pos/src/lib/refundFlow/hydrateFromReceipt.ts:15-27,60-76` | Refund cart hydration from local receipt: `OfflineReceiptLine` interface **omits `variant_id`**; produced return CartItems lose variant identity | **NO** | **YES** | **FIX in this session** |
| 20 | `apps/pos/src/lib/db/repositories/offlineReceiptRepository.ts:277-287` | `setServerReceiptId()` exists and `offline_receipts.server_receipt_id` column exists (migration v? at migrations.ts:309) but the function has **zero callers** — column never populated | n/a | **YES (fact)** | **FIX/wire in this session** (refund submit needs server receipt id; qr-index `receipt_uuid` is currently the only local source) |
| 21 | `apps/pos/src/lib/offline/zReportService.ts` | Offline Z aggregation: payments/totals only, no product grain | n/a | NO | OK — no variant granularity needed |
| 22 | `apps/pos/src/stores/holdStore.ts:52,122` | Local holds serialize `CartItem[]` wholesale → variant_id preserved; cartStore dedupes on `(product.id, variant_id)` | YES | NO | OK |
| 23 | `apps/api/.../Services/InventoryOpeningService.php:270` | Opening-balance import: `StockLevel::where(product, location)` (no variant, **also no company filter**) + creates product-level rows | **NO** | NO | Ticket |
| 24 | `apps/api/.../Services/InventoryService.php:45` (`upsertStockLevel`, Shared contract; sole caller `ImportService.php:389`) | `updateOrCreate` on (tenant, company, product, location) without variant predicate — can MATCH and clobber a variant row when product-level row is absent | **NO** | NO | Ticket (import collision risk) |
| 25 | `apps/api/.../Services/StockReservationService.php:104,227,348,524` | Reservation create/release/expire lock `StockLevel` by product+location only; reservation rows/events carry variant_id but the quantity bookkeeping row is arbitrary when variants exist | **PARTIAL** | NO (e-commerce/workshop) | Ticket |
| 26 | `apps/api/.../Services/CompositeItemAvailabilityService.php:55` | Composite availability reads component stock by product+location only — sums across variant rows? No: `->first()` picks arbitrary row. `recipe_lines.component_variant_id` exists (migration 100015) but unused here | **NO** | NO | Ticket |
| 27 | `apps/api/.../Marketplace/.../MarketplaceOrderService.php:57` + `ListingSyncService.php:78` | Marketplace stock pick / available-qty by product only | **NO** | NO | Ticket (low; marketplace pre-launch) |
| 28 | `apps/api/.../Reports/StockAlertReportService.php:27` | Low-stock alert joins `stock_levels`→`products` without selecting/labeling variant → variant rows appear as duplicate product rows | **NO** (cosmetic) | NO | Ticket (low) |
| 29 | `apps/api/.../Controllers/StockLevelController.php:29,63` | `index` lists all rows (variant rows included but not labeled); `show(product, location)` `firstOrFail` → arbitrary row when variants exist | PARTIAL | NO | Ticket |
| 30 | `apps/api/.../Services/InventoryCountingService.php:93-99` + `ApplyStockAdjustmentsOnCountingCompleted.php:67-91` | Counting iterates ALL stock rows in scope (variant rows included), propagates `variant_id` onto counting items, adjustment listener scopes variant row + threads variantId | **YES** (Task 20) | NO | OK |
| 31 | `apps/api/.../Domain/Services/StockAdjustmentService.php:703-720` (`lockStockLevel`) | Adjustment/receive/issue: variant-scoped via `when(variantId !== null, where, whereNull)` + `assertVariantConsistency` | **YES** | NO | OK — this is the canonical query shape to copy |
| 32 | `apps/api/.../Services/StockTransferService.php:404-413` | Transfer dispatch locks variant-scoped row | **YES** (PR #182) | NO | By design OK |
| 33 | `apps/api/.../Services/WeightedAverageCostService.php:100,159,372,479,725` | WAC reads stock by product (company-wide) | NO — **BY DESIGN** | NO | By design OK — do not "fix" |
| 34 | `apps/api/.../Jobs/RevalidateUnitQuantityScaleJob.php:78`, `FixOrphanedProducts.php:41`, `MigrationWizardService.php:352`, `StockLevelMigrationService.php` | Maintenance/count/migration utilities | n/a (grain-agnostic or T2 migration tooling itself) | NO | OK |
| 35 | Document module (`ReturnNoteController`, `CreditNoteService`) | **No direct stock writes** — the complete `StockMovement::create` writer set is the 7 files audited above; document returns don't restock directly | n/a | NO | OK (note: if return notes are *supposed* to restock, that's a separate pre-existing gap, not variant) |

## Refund-relevant fixes for this session

### F1 — `ReceiptReturnService::restoreStock` (the confirmed bug)
File: `apps/api/app/Modules/POS/Application/Services/ReceiptReturnService.php`

1. Thread `variant_id` from the original line: in the Step-11 loop (lines 328-348), pass `variantId: $originalLine->variant_id` into `restoreStock`.
2. In `restoreStock` (line 985), add the parameter and scope the query exactly like `StockAdjustmentService::lockStockLevel` (line 703) / `ReceiptCreationService::decrementStock` (line 883):
   ```php
   ->when($variantId !== null,
       fn ($q) => $q->where('variant_id', $variantId),
       fn ($q) => $q->whereNull('variant_id'))
   ```
3. Write `variant_id` onto the `StockMovement` row (column exists, migration 100006).

**Symmetry argument (important for the test):** projection-path receipts have `pos_receipt_lines.variant_id = NULL` and were decremented on the `variant_id IS NULL` row → NULL-scoped restore reverses the exact row. Draft-path receipts carry `variant_id` and were decremented on the variant row → variant-scoped restore reverses the exact row. Do NOT attempt to re-derive the variant for NULL lines (the canonical payload doesn't have it; guessing would *create* asymmetry).

### F2 — Return receipt lines must carry `variant_id`
File: same, `computeReturnTotals` line array (lines 789-806). Add `'variant_id' => $originalLine->variant_id` next to `product_id`. Without this, a *return of a return-era receipt* (and any reporting on return lines) loses variant identity, and `calculateAlreadyReturnedQuantities`' legacy product-attribute fallback (lines 957-969) can cross-match different variants of the same product (it compares product_id + composite_item_id + product_code only — note `product_code` is the line SKU, which for draft-path variant lines is the variant SKU, partially mitigating; for projection-path lines it is whatever the canonical payload carried).

### F3 — `ReceiptVoidService::reverseStockMovement`
File: `apps/api/app/Modules/POS/Application/Services/ReceiptVoidService.php:134-178`.
Same fix shape as F1: pass `$line->variant_id` from the loop at lines 84-98, add the `when(...)`/`whereNull` predicate at line 144, write `variant_id` on the StockMovement, and while editing change `bcadd(..., 2)` at line 155 to scale **4** (canonical quantity scale — the return path already uses 4).
Batch reversal (`reverseBatchAllocations`, lines 183-205) needs **no variant change** — `batch_id` is already variant-precise.

### F4 — Batch restitution on returns (decision required)
`ReceiptReturnService` performs zero `inventory_batch_stock` restitution: a refunded batch-tracked (FEFO) product restores the aggregate `stock_levels` row but the batch quantity stays consumed, so batch-level availability drifts down permanently. The void path proves the reversal recipe (allocation rows keyed by `receipt_line_id`). Minimal fix: in Step 11, for each returned line, load `ReceiptLineBatchAllocation::where('receipt_line_id', $originalLine->id)`, restore proportionally (`returnQty / originalQty` per allocation, FEFO order irrelevant for restitution), and increment `inventory_batch_stock` like `ReceiptVoidService::reverseBatchAllocations`. If the session scopes this out, file it as a P1 ticket — it is a stock-correctness bug for every parapharmacy/FEFO tenant, variant or not.

### F5 — POS refund cart loses variant identity
File: `apps/pos/src/lib/refundFlow/hydrateFromReceipt.ts`. Add `variant_id?: string` (and `variant_name` if stored) to the `OfflineReceiptLine` interface and copy it onto the produced `CartItem.product` (`receiptService.ts:430` already persists it into `offline_receipts.lines` JSON). Needed so the refund UI displays the right variant and so any future return payload can carry it.

### F6 — Local→server receipt id mapping (refund submit prerequisite)
Facts the implementation must build on:
- `PosCoreReceiptProjection.php:182` generates a **fresh server UUID** for the projected receipt — the server `pos_receipts.id` ≠ the local `offline_receipts.id`.
- The **only** local source of the server id is `receipt_qr_index.receipt_uuid` (synced via `GET /pos/receipts/qr-index` → `ReceiptQrIndexSyncService`, keyed locally by `receipt_number`/`qr_token`/`partner_id`). `refundFlowStore`/`refundDraftStore` already read `entry.receipt_uuid`.
- `offline_receipts.server_receipt_id` column EXISTS (migrations.ts:309) and `setServerReceiptId()` EXISTS (`offlineReceiptRepository.ts:277`) but is **never called** — wire it (e.g., on qr-index upsert match by receipt_number, or on sync ack) or rely solely on qr-index join at refund time.
- `ReceiptReturnService::processReturn` takes server `pos_receipt_lines.id` values (`line_id` per return line). The local receipt has no server line ids → the refund flow must fetch the server receipt (by `receipt_uuid` from qr-index) and map lines server-side before calling process-return. qr-index alone is header-only (no lines).

## Tickets (not this session)

1. **T2-RET-01 (P2):** `ReceiptLineBatchAllocation` rows omit `variant_id` (column shipped in migration 100012; `ReceiptCreationService::allocateBatches` never writes it). Denormalization-only — variant recoverable via batch.
2. **T2-RET-02 (P2):** `pos_order_lines.variant_id` never populated (`OrderManagementService::addLine:242`) and `OrderToReceiptService:45-49` drops it on conversion — table-service vertical sells variants as bare products end-to-end.
3. **T2-RET-03 (P2):** `StockReservationService` (lines 104/227/348/524) reserves/releases against an arbitrary stock row when variants exist; reservation rows/V2 events already carry `variant_id`. Affects e-commerce ATP and workshop reservations.
4. **T2-RET-04 (P2):** `InventoryService::upsertStockLevel` (`updateOrCreate` without variant predicate, sole caller `ImportService:389`) can match and overwrite a variant row during stock import.
5. **T2-RET-05 (P3):** `InventoryOpeningService:270` opening-balance lookup lacks variant AND company scoping.
6. **T2-RET-06 (P3):** `CompositeItemAvailabilityService:55` ignores `recipe_lines.component_variant_id` (migration 100015) — composite availability wrong for variant components.
7. **T2-RET-07 (P3):** `StockLevelController::show` `firstOrFail(product, location)` returns an arbitrary row for variant products; `index` doesn't expose variant labels.
8. **T2-RET-08 (P3):** `StockAlertReportService:27` low-stock report shows variant rows as duplicate unlabeled product rows.
9. **T2-RET-09 (P3):** `PosAnalyticsService` top-products groups by `product_name` — variant grain merged/inconsistent.
10. **T2-RET-10 (P3):** Marketplace `MarketplaceOrderService:57` / `ListingSyncService:78` product-only stock reads.
11. **T2-CANONICAL (tracked already, restated for linkage):** canonical `LineItemDTO` lacks `variant_id` → projection writes NULL lines + NULL-row decrements (`PosCoreReceiptProjection.php:550,817`). Owns the upstream limit on refund variant fidelity for offline sales. Deferred to canonical schema bump (Phase 2 T2) — signed-payload change, versioned-event discipline applies.

## By design — do not fix

- **WAC / costing stays product-grain** (`WeightedAverageCostService`, all five StockLevel callsites; `StockAdjustmentService` comment at :68): variant cost is advisory; company-wide cost per product is deliberate. Confirmed unchanged by PR #182 (§6.7).
- **Stock transfers** are variant-aware as of PR #182 (`StockTransferService:404`, variant-scoped batch picker) — verified, no gaps found in the transfer path.
- **Z/X reports** (server `ReportGenerationService`, offline `zReportService.ts`) aggregate at payment/receipt grain — variant granularity is not needed and nothing misattributes variants (the only per-product reporting is PosAnalyticsService, ticket 9).
- **Void batch reversal by `batch_id`** is variant-precise by construction.
- **Counting/adjustment** paths (Task 20) and the **sale draft path** (Task 18) are the reference variant-aware implementations.

## Out of scope (recorded statuses)

- **`ExchangeService` is route-less/inert**: zero controller/route references (only DTO docblocks and `RefundDestination::ExchangeDeferred` mentions). Its return half inherits F1/F2 transitively; no separate fix needed while inert.
- **`RETURN_WITHOUT_RECEIPT`**: exists only as a reserved `FiscalEventType` enum case (apps/api `Fiscal/Domain/Enums/FiscalEventType.php`) + POS payload-registry stub — no implementation; explicitly out of scope for this session.
- **Server new-sale authoring is retired** (routes.php §14.2 → 410 Gone); `void` and `processReturn` routes are knowingly retained and shared with the offline Tauri POS — these are the surfaces this session fixes.

## Session outcomes & new tickets (appended end of session, Phase 6)

### Fixed in this session

- **F1–F4 fixed** in Phase 5, commit `bbc167895` ("variant-aware restock + batch restitution on returns/voids"): variant-scoped `restoreStock` (F1), `variant_id` on return receipt lines (F2), variant-scoped + scale-4 `reverseStockMovement` in the void path (F3), and proportional cumulative batch restitution on returns (`restoreBatchAllocations`, F4).
- **F5/F6 fixed** in Phase 2: F5 (refund cart variant identity — `variant_id`/`variant_name` threaded through `hydrateFromReceipt`, which originally shipped without them in April's `33b659b74`) landed in `213e4673d`; F6 (local→server receipt/line mapping) also in `213e4673d`, with review fixes in `e4df4fdb7` (idempotency race, variant-blind mapping, receipt-number fallback).

### VoidReturnModal deleted — refund is the ONLY post-seal correction surface (decision)

Commit `7f81ed140` (POS), `745f9da8c` (API guard), `973834a13` (web quarantine). Rationale:

- The modal was mounted in HomePage but **never opened** (`setShowVoidReturnModal(true)` had zero callsites — dead since shipping).
- Its online lookup called `GET /pos/receipts/lookup`, which has **no route**.
- Its working manager-PIN handshake was already extracted to `apps/pos/src/lib/refundFlow/refundApproval.ts` (Phase 2).
- The completed refund flow (locate → hydrate → partial qty → destination → manager PIN → settle → AVOIR print) covers the post-seal correction need.
- Tunisia/NACEF compliance is a separate workstream that will re-evaluate sealed-receipt void if the MDF rules require it.

Companion server guard (TDD, `ReceiptReturnFlowTest::test_void_rejects_return_receipt`): `POST /pos/receipts/{id}/void` now rejects RETURN receipts with **422 `CANNOT_VOID_RETURN_RECEIPT`** (controller guard next to `ALREADY_VOIDED`, plus a defense-in-depth `RuntimeException` in `ReceiptVoidService::voidReceipt`). Voiding a return was incoherent post-F4: the void path never reverses the return's batch restitution, so re-returning the sale would over-restore `inventory_batch_stock`. Web-admin return surface (ReceiptSearchPage + ReturnItemsModal — 422'd on every submit, owner is removing web POS) was quarantined: route, sidebar entry, PosHub card, and dead components deleted; the backend `/return` route STAYS (desktop POS uses it).

### NEW TICKET — server Z `refunds_amount` sign bug (for the Z-server correctness session, B1/B3 owner)

`ReportGenerationService::calculateShiftTotals` (apps/api, ~line 922) folds return receipts in with `bcadd($refundsAmount, $receipt->total, ...)` — a RAW add — while the return pipeline persists **negative** `pos_receipts.total` on return receipts. The existing fixture masks this with a positive return total; on real data the server `refunds_amount` goes **negative**, and downstream `perpetual_grand_total` (which consumes `refunds_amount` at ~line 839) **inflates**. Fix shape: accumulate `abs(total)` (the device-side `zReportService.ts` already uses `bcabs`); add a fixture with a real negative-total return receipt.

### NEW HANDOVER NOTE — device endOfDayPreview expected_cash (B1 territory, deliberately untouched)

The device `endOfDayPreview` `expected_cash` does NOT subtract cash refunds. The signed Z is correct (Phase 4 folds `local_refund_records` into the signed totals); only the operator-facing preview drifts when cash refunds occurred in the shift. The B1 session (expected-cash key mismatch) must fold `local_refund_records.cash_impact` into the preview alongside its `payment_method_code` vs `method_code` fix.

### NEW TICKET — `ReceiptVoidService::reverseBatchAllocations` raw SQL interpolation

Uses `DB::raw("quantity + {$allocation->quantity}")` (string interpolation of a DB-sourced numeric — not attacker-controlled today, but a footgun). The return path's `restoreBatchAllocations` already uses a parameterized increment; adopt the same pattern when next touching the void service.

### Precision workstream note — scale-3 R_prev feeding scale-4 restitution

`calculateAlreadyReturnedQuantities` accumulates already-returned quantities at **scale 3** (`ReceiptReturnService` ~lines 1079–1098, plus the scale-3 over-return checks at ~1045–1047), and that value is passed as `alreadyReturnedQuantity` (R_prev) into the **scale-4** cumulative batch-restitution math (`restoreBatchAllocations`). Sub-milli return quantities would truncate in R_prev and skew the cumulative delta. Align the already-returned plumbing to scale 4 (canonical quantity scale) in the precision workstream.

### RETURN_WITHOUT_RECEIPT

Already recorded as out of scope above (see "Out of scope (recorded statuses)") — unchanged.
