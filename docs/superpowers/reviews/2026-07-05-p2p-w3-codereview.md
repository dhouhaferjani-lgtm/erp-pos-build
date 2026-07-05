# P2P Entry Points — Wave 3 Adversarial Code Review

- **Scope:** uncommitted Wave 3 diff (`git diff HEAD`) in worktree `apps/erp.p2p-flow`, branch `feat/p2p-entry-points`.
- **Reviewed against:** plan Rev 2 WAVE 3 (`docs/superpowers/plans/2026-07-05-p2p-entry-points-plan.md`), spec §5 (`docs/superpowers/specs/2026-07-05-p2p-entry-points-design.md`), task log `docs/sessions/TASK-LOG-w3.md`.
- **Verdict: READY** (no BLOCKER or MAJOR). Six MINOR follow-ups recommended, none merge-gating.

---

## 1. Behavior preservation of `receiveGoods` — PASS

`receiveGoods` signature and `GoodsReceiptResult` return type are unchanged (`GoodsReceiptService.php:63-88`). It now wraps `createDraft(...)` + `post(...)` in one outer `DB::transaction` (`:72-90`). Nested `DB::transaction` in `createDraft`/`post` = savepoints inside the same outer tx — safe.

Event order preserved exactly, per movement, BEFORE line backfill:
- Free branch: `wacService->recordPurchase` → `event(GoodsReceived)` → (optional fail-closed GRIR) → later `GoodsReceiptLine` backfill (`:513-557`, `:619-651`).
- Paid branch: same order (`:559-611`, `:619-651`).
- Line backfill now `forceFill(...)->save()` on the pre-created draft line (found via `keyBy('po_line_id')`) instead of `create()` — end-state identical (same payload columns: landed/accrual/effective cost, movement ids, price-override stamps). New `create()` fallback only for a missing draft line (`:647-651`).
- PO counters (`quantity_received`, `free_quantity_received`, `accrual_unit_cost` first-write immutability at `:606-608`), status flip to `Received` on full receipt (`:664-671`), and price-override audit stamps (`price_override_by/at/old_basis/reason`, `:641-644`) are all preserved and positively pinned by `post_draft_assigns_grn_and_applies_existing_paid_and_free_side_effects` (`GoodsReceiptLedgerWriteTest`: 2 movements, override stamps, effective_unit_cost `4.160000`, PO counters).

Task-log pins at `:63,:72-91,:513-544,:559-591,:619` verified accurate against current code.

## 2. Draft purity — PASS

`createDraft` (`:87-160`) writes header + lines ONLY. Explicit NULLs on `receipt_number`, `landed/accrual/effective_unit_cost`, `movement_id`, `free_movement_id` (`:139-149`). No `numberingService` call, no `wacService`, no `event()`, no GL, no PO-counter writes, no GRN. Pinned by `create_draft_persists_uncosted_receipt_lines_without_side_effects` (asserts `StockMovement::count() === 0`, PO `quantity_received` unchanged, NULL costs/movements). Draft delete is a hard delete, Draft-only, rejects Posted (`GoodsReceiptController::destroy` `:96-115`; pinned by `delete_draft_endpoint_removes_uncosted_receipt_lines` + `delete_draft_endpoint_rejects_posted_receipts`).

## 3. GRN-at-post — PASS

`receipt_number` assigned only inside `post()`'s locked closure via `numberingService->generateForKey(... 'GRN')` (`:245-249`). No sequence burn on abandoned drafts. Migration `2026_07_06_110000_goods_receipt_draft_columns.php` drops the full unique and re-creates a **partial** unique via raw `DB::statement('CREATE UNIQUE INDEX ... WHERE receipt_number IS NOT NULL')` (`:25-29`) — valid on BOTH pgsql (production) and SQLite (test), so it is not SQLite-shaped only. `down()` restores full unique. BROUILLON display identity is FE-side (draft badge); no server format helper needed for Wave 3.

## 4. Posted-only filters — PASS

Shared scope `GoodsReceiptLine::scopePostedReceipts()` (`GoodsReceiptLine.php:104-113`) uses a clean `whereIn('goods_receipt_id', GoodsReceipt::where(status=Posted)->select('id'))` subquery (avoids join-alias ambiguity). Applied at all 6 plan-listed sites:
- `ReceiptLineConsumptionPlanner::plan` (`:30`) ✔
- `SupplierInvoiceMatcher::matchableQty` (`:151`) + `buildQtyGroupStatuses` (`:415`) ✔
- `SupplierInvoicePostingService` lock (`:83`) + paid sum (`:175`) + free sum (`:224`) ✔
- `SupplierCreditNotePostingService` post() lock (`:152`) + `receiptLedgerSum()` (`:517`) ✔ (`decrementReceiptLineInvoiced` operates on the already-filtered locked collection — no extra query)
- `PurchaseOrderController::index` has_uninvoiced (`:237`, now `GoodsReceiptLine::query()->postedReceipts()` passed to `whereExists`) + `receiptLines()` picker (`:846`) ✔

Independent `rg -n "GoodsReceiptLine::|goods_receipt_lines" apps/api/app` run: remaining unfiltered sites justified —
- `GoodsReceiptService::poLineIdsWithReceipts()` (`:725`) is a PO-line mutation guard; drafts SHOULD block mutation (more conservative, correct).
- `BackfillGoodsReceiptsCommand` writes Posted backfill rows (historical, not a live window).
- `GrirDriftReportCommand` (`:42`) already filters `goods_receipts.status = Posted` — **verified** by reading the file.
- Model/DTO/relation references are not matchable/posting/picker windows.

`post()` re-validates quantities against CURRENT PO counters via `assertQuantitiesWithinRemaining` (`:436`), so a stale draft cannot silently over-receive.

## 5. Permission relocation — PASS (strengthening, not weakening)

Request-layer prohibition is PRESERVED: `ReceiveGoodsRequest` still emits `received_unit_prices => ['prohibited']` when `!can('goods-receipt.edit-price')` (`ReceiveGoodsRequest.php:35`). The service now ADDITIONALLY enforces at `post()` time via `assertCanApplyDraftPriceOverrides` (`GoodsReceiptService.php:290-305`, `User::find($actorId)->can('goods-receipt.edit-price')`). This closes the draft→post path (request layer doesn't apply to the standalone post endpoint) and direct service callers — a net strengthening. The two fixture edits (`GoodsReceiptPriceOverrideTest`, `ReceiptBatchAllocationTest`) grant `goods-receipt.edit-price` to actors that call the service directly with overrides — this is the correct consequence of the relocation (happy-path tests still assert the override IS applied), NOT papering over a behavior change. A negative-enforcement pin exists (task log records `ReceiptBatchAllocationTest::received_price_override_changes_batch_freight_shares` failed until permission granted, i.e. service now rejects).

## 6. New endpoints & routes — PASS

`GoodsReceiptController` constructor-injects `CompanyContext` + `GoodsReceiptService` (no `app()`). All reads scoped by `requireTenantId()` + `requireCompanyId()` + `findOrFail` → cross-company access = 404 (`receiptForCurrentCompany` `:117-124`). `destroy` Draft-only guard (`:100-107`); `post` passes `$user->id` as actor and catches `DomainException` → 422 (`:80-89`). Routes in `Inventory/Presentation/routes.php` sit under the module group with `EnforceTokenTenantClaim` + `SetPermissionsTeam`; `index/show` gated `can:inventory.view`, `post/destroy` gated `can:purchase-orders.receive`, all `{receipt}` constrained `whereUuid` (`:103-121`). Draft-post endpoint correctly uses the listener GRIR path (`failClosedGrir=false`) — fail-closed is a later-wave (invoice-first) concern.

## 7. Frontend — PASS with MINORs

`ReceiveGoodsDialog` splits into `Save draft` (`save_as_draft:true`) and `Save and post` actions; payload built by shared `buildRequest(saveAsDraft)` (`:181-231`). `GoodsReceiptListPage` draft badge query uses `tenantScopedKey(['goods-receipts','draft-count'])` and invalidates the `goods-receipts` scoped namespace on success (rule-14 compliant). No `parseFloat`/`Number()` on money. FE tests updated to the new button name (`ReceiveGoodsDialog.test.tsx`, `GoodsReceiptListPage.tenantScope.test.tsx`).

## 8. Enum removal — PASS

`GoodsReceiptStatus::Cancelled` removed (`GoodsReceiptStatus.php`); `generated.d.ts` now `'draft' | 'posted'`. Sweep found no straggler in `apps/web/src`, `packages/shared`, or migration CHECK constraints. Model status cast unchanged (`GoodsReceipt.php:65`).

---

## Findings

### MINOR

- **M1 — ar locale missing the two new action-button keys.** `ar/sales.json > purchaseOrders.receive` is otherwise fully translated but lacks `saveDraft` / `saveAndPost` (`ReceiveGoodsDialog.tsx:411,414`). Arabic (RTL) users get the i18next fallback (English) on the primary confirm button. `ar/inventory.json` has no `goodsReceipt.status` subtree at all, so the badge key `goodsReceipt.status.draft` also falls back — but that subtree was already absent pre-Wave-3. Recommend adding the two `saveDraft`/`saveAndPost` ar keys (and, optionally, seeding the ar `goodsReceipt.status` subtree). File: `apps/web/src/locales/ar/sales.json`.
- **M2 — Hardcoded Tailwind colors on the new draft badge.** `bg-amber-100 ... text-amber-700` (`GoodsReceiptListPage.tsx:357`) violates rule 18 (design tokens). `purchases` is a pre-existing feature dir so it is reminder-level not CI-error, but the badge is newly added code — migrate to `tokens`/`textColors`.
- **M3 — Replacement auth test asserts middleware strings only.** `test_goods_receipt_draft_lifecycle_routes_are_guarded` (`GoodsReceiptTest.php`) checks `gatherMiddleware()` contains the `can:` strings on show/post/destroy but does NOT exercise a real request, does not assert the `index` guard, and there is NO cross-company 404 / tenant-scoping test for the new endpoints (the scoping IS correctly implemented in `receiptForCurrentCompany`). Add a cross-company `assertNotFound` case for show/post/destroy.
- **M4 — Golden under-pins the GR-IR/GL side of the deviation.** Task log documents a deliberate deviation from the plan's "2 GR-IR entries" golden (free branch = zero amount → `createGoodsReceiptGrIrEntry` returns null → no free entry). The deviation is defensible and matches the existing GL contract, but the pinning test (`post_draft_assigns_...`) asserts only 2 stock movements — it does NOT positively assert "paid GR-IR entry EXISTS and free GR-IR entry ABSENT." Add a journal-entry assertion so the deviation is pinned, not merely skipped.
- **M5 — JSON indentation inconsistency.** The rewritten `goodsReceipt.status` block in `en/inventory.json` and `fr/inventory.json` is indented at 2 spaces while sibling keys are at 4 (valid JSON, `jq` passes; cosmetic).
- **M6 — Service-line (product_id NULL) over-receive validation skipped in `createDraft`.** `createDraft` does `if ($line->product_id === null) continue;` BEFORE `assertQuantitiesWithinRemaining` (`:118-123`), whereas the original `processReceiptLines` validated then skipped. A non-physical line receiving qty > remaining no longer throws at draft time and (because no draft line is created) is not re-validated at post. Extreme edge case (services are not normally "received"); marginal. No action required beyond awareness.

### No BLOCKER / MAJOR findings.

Migration partial-index is driver-correct for pgsql; event order, both branches, audit stamps, PO counters, GRN-at-post, draft purity, and posted-filter isolation are all preserved and test-pinned.
