# Codex spec gate r9 — T-2/T-3 (gpt-5.6-sol, high, read-only, 2026-09-09)

## Rev-8 closure table

| Rev-8 finding | Rev-9 disposition |
|---|---|
| r8-B1 — multi-receiver movement feeds disclose hidden quantities | **NOT CLOSED end-to-end.** §5.10 correctly closes the two direct movement feeds, including actor-owned before/after balances and receipt-linked damage (`docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-9.md:386-405,552,604-605`). But the same fixture makes destination on-hand equal the forbidden sent sentinel, then scans stock-matrix and POS responses that still emit that value (`docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-9.md:560,586,588-601`). See B1. |
| r8-M1 — reconcile-only web test incorrectly showed Close | **CLOSED at rev-9 anchor.** Separate reconcile-only and reconcile+close cases now enforce the two-permission ruling (`docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-9.md:485`). |
| r8-m1 — `/complete` expected 201 instead of 200 | **CLOSED at rev-9 anchor.** Compatibility completion now expects 200, matching `apps/api/app/Modules/Inventory/Presentation/Controllers/StockTransferController.php:231-235`. |
| r8-m2 — `apiPost` discarded `meta.replayed` | **CLOSED at rev-9 anchor.** Receive and close now use raw `api.post` envelopes (`docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-9.md:466,469`; helper behavior at `apps/web/src/lib/api.ts:422-425`). |
| r8-m3 — POS replenishment metadata placed under `meta` | **CLOSED at rev-9 anchor.** Web and POS envelope placements are distinguished (`docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-9.md:267-268,383-384`). |
| r7-M3 carried by r8 — reconcile-only read reachability lacked executable tests | **CLOSED at rev-9 anchor.** Named backend and web cases, including first failing assertions, are present (`docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-9.md:485,549`). |
| r8 citation — stale baseline HEAD | **NOT CLOSED.** Rev 9 pins `4373ba2…` and claims 13 changed documentation files (`docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-9.md:5`). Audit-time HEAD is `75fa924a1bd5f0825327a792ac84e754aa441640`; the corresponding rev-7 diff contains 16 files. Production files remain unchanged. |
| r8 citation — false actor-owned movement premise | **CLOSED narrowly at rev-9 anchor.** The real company/location predicates and absence of actor scoping are correctly recorded (`docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-9.md:388-401`). The broader blind-surface closure remains open under B1. |
| r8 citation — detail-page edit range incomplete | **CLOSED at rev-9 anchor.** The target is correctly extended through the cancelled and notes rows and closing `</dl>` (`docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-9.md:471`; actual block `apps/web/src/features/stock-transfers/pages/StockTransferDetailPage.tsx:124-176`). |
| r8 owner-open OD-1, close authority and OD-4 | **CLOSED at rev-9 anchor.** They are recorded as applied rulings, not open alternatives (`docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-9.md:627-631`; ruling source `docs/handoff/OWNER-QUESTIONS-consolidated-2026-09-06.md:108`). |
| r8 rejection of `return_to_source` exclusion | **REJECTED-correctly.** Both close dispositions remain, with no GL for the return path (`docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-9.md:431-443,531-532`). |
| r8 rejection of a write-off-only close event | **REJECTED-correctly.** Generic `StockTransferClosedV1` carries disposition and supports both owner-approved outcomes while leaving existing events immutable (`docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-9.md:194,202`). |
| r8 verified readers, GL, events, locks, routes, one-writer and migration findings | **REJECTED-correctly / still verified.** Representative evidence appears below. |

## BLOCKER

### B1. The declared blind guarantee and T9 are impossible with reachable current-stock and batch-stock responses

The guarantee says a blind receiver cannot obtain sent/expected/remaining quantity from **any authenticated endpoint**, directly or through an equal aggregate (`docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-9.md:247`). The response-shape section claims to enumerate every reachable shape (`docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-9.md:270-288`).

The new test itself proves the omission:

1. It says receiver B’s posting changes product P at L2 from `0 → 2391.4517 → 7391.4517`, exactly the sent sentinel, while another line keeps the transfer `partially_received` (`docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-9.md:558-560,586`).
2. Receiver A then obtains `NOTHING_TO_RECEIVE`, confirming that the line remainder is zero (`docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-9.md:587`).
3. T9 scans the entire stock-matrix and POS bodies for `7391.4517` and requires it to be absent (`docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-9.md:588-601`).
4. Those bodies still emit that exact current-stock value:

   - Stock matrix fills `cells[].on_hand` directly from `stock_levels.quantity` (`apps/api/app/Modules/Inventory/Application/Services/StockMatrixQueryService.php:224-246,287-298`).
   - POS stock levels emit `stock[].quantity` (`apps/api/app/Modules/POS/Presentation/Controllers/PosStockLevelController.php:85-103`).
   - POS distribution emits each location and total `on_hand` (`apps/api/app/Modules/POS/Presentation/Controllers/StockDistributionController.php:80-99`), derived from stock levels at `apps/api/app/Modules/Inventory/Application/Services/LocationStockQueryService.php:113-135,167-207`.
   - Receipt posting updates the destination stock level to that value (`apps/api/app/Modules/Inventory/Domain/Services/StockAdjustmentService.php:133-158`).

The audit also omitted independently reachable stock shapes:

| Receiver-reachable shape | Code-derived result |
|---|---|
| Receiver-view, list/show union | Safe by planned omission: no sent/remainder, allocation quantity, sender notes, costs, or other receivers’ receipts (`docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-9.md:274-275,344-345`). |
| Receive response/replay | Safe except for the actor’s legitimate own input echo (`docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-9.md:276,300-304`). |
| Complete/close/typed and validation errors | Static or ID-only as designed. `partially_received` is harmless alone, but `NOTHING_TO_RECEIVE` confirms an exact aggregate when another response supplies one (`docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-9.md:277-281,315-325`). |
| Notifications | Counts and identities only; no hidden quantity or note (`docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-9.md:455-460`). |
| Replenishment feeds | Transfer-linked quantities and arbitrary notes are correctly masked (`docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-9.md:267-268,383-384`). |
| Movement and entry/exit feeds | §5.10 correctly masks all three quantity fields on carrying-transfer rows, including other users’ rows and the requesting actor’s before/after balances (`docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-9.md:386-405`). No additional HTTP movement export/PDF reader was found; counting report movement lookup is restricted to counting-linked grains (`apps/api/app/Modules/Inventory/Application/Services/CountingDiscrepancyReportService.php:306-332`). |
| `GET /stock-levels` and show | T9’s actor already has `inventory.view`, which reaches both routes (`apps/api/app/Modules/Inventory/Presentation/routes.php:57-64`). The DTO emits `quantity`, `available`, `incoming`, and projected availability (`apps/api/app/Modules/Inventory/Application/DTOs/StockLevelData.php:18-32,61-80`). |
| Product stock levels | An otherwise blind actor with `products.view` reaches the route (`apps/api/app/Modules/Product/routes.php:53-64`); locations and totals expose quantity/available stock (`apps/api/app/Modules/Product/Presentation/Controllers/ProductController.php:1064-1094,1096-1139`). |
| Stock rebalance | `inventory.view` reaches this endpoint (`apps/api/app/Modules/Inventory/Presentation/routes.php:73-75`); it emits per-location available quantities and excesses derived from `stock_levels` (`apps/api/app/Modules/Inventory/Application/Services/StockRebalanceQueryService.php:36-49,61-115`). |
| Batch list/show/stock/product-batch-stock/POS batches | The routes require authentication and the BatchExpiry module but no read permission (`apps/api/app/Modules/BatchExpiry/Presentation/routes.php:12-48`). Batch show/resource and stock responses expose exact per-location lot quantity (`apps/api/app/Modules/BatchExpiry/Presentation/Controllers/BatchController.php:105-116,264-281,329-356`; `apps/api/app/Modules/BatchExpiry/Presentation/Resources/BatchResource.php:55-60`). A lot receipt updates that exact quantity (`apps/api/app/Modules/Inventory/Domain/Services/StockAdjustmentService.php:168-176,2137-2175`). Thus shipped allocation quantities can be inferred after another receiver posts them while a different line keeps the transfer carrying. |
| Counting comparison | The dedicated counter endpoints correctly omit theoretical and other counters’ quantities (`apps/api/app/Modules/Inventory/Presentation/Controllers/InventoryCountingController.php:334-377`; `apps/api/app/Modules/Inventory/Presentation/Controllers/CountingItemController.php:54-74,161-177`; mobile NEVER-INCLUDE contract `/Users/houssamr/Projects/syneriva/erp-mobile/src/features/counting/api/countingApi.ts:193-197`). That protection works because the response is a dedicated counter projection. By contrast, counting reconciliation is reachable with `inventory.view` and emits `theoretical_qty` (`apps/api/app/Modules/Inventory/Presentation/routes.php:311-314`; `apps/api/app/Modules/Inventory/Application/Services/CountingReconciliationPayloadBuilder.php:61-80`). |
| Generated/POS/mobile types | Planned transfer receiver DTOs do not introduce hidden fields, and current mobile has no transfer movement consumer (`docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-9.md:264-266,500-504`). But existing POS stock types retain exact `quantity`/`on_hand` (`apps/pos/src/lib/db/repositories/locationStockRepository.ts:45-61`; `apps/pos/src/types/stockDistribution.ts:6-29`), matching the unsafe server shapes above. Current mobile PO supervisor types expose ordered/remaining quantity, but they are explicitly a separate future T-3b projection problem (`/Users/houssamr/Projects/syneriva/erp-mobile/src/features/receiving/types.ts:58-73,98-109`; `docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-9.md:501`). |

Consequently, the positive-control table is also incomplete: it lists only incoming values for stock-matrix and POS after receipt, although those same responses contain the on-hand sent sentinel (`docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-9.md:564-576`).

Rev 9 cannot be implemented with T9 green as written. It must either close current-stock/lot-stock projections under the blind predicate, including corresponding wire types and caches, or explicitly make current-stock delta inference an owner-approved residual and narrow the guarantee/oracle accordingly.

## MAJOR

None independently of B1.

## MINOR

### m1. The newly sensitive movement queries are omitted from the stated cache-convergence mechanism

The spec requires blind-relevant web queries to use `staleTime: 0` and focus refetching (`docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-9.md:235-237,470`). The movement section then claims the feeds hold no relevant client cache and excludes them from the visibility-version carriage set (`docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-9.md:403`).

All three web consumers use TanStack queries without overrides (`apps/web/src/features/inventory/StockMovementsPage.tsx:144-171`; `apps/web/src/features/inventory/components/ProductMovementsTab.tsx:85-101`; `apps/web/src/features/inventory/EntryExitNotesPage.tsx:73-85`). They therefore inherit five-minute freshness and disabled focus refetching (`apps/web/src/lib/queryClient.ts:3-9`). The planned cache-removal prefixes also omit `stock-movements`, `product-movements`, and `entry-exit-notes` (`docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-9.md:470,483-485`).

This remains inside accepted R4, but it contradicts the spec’s own stated best-effort implementation. The execution plan should either give those three queries the declared zero-staleness behavior or include their real prefixes in visibility cache removal.

### m2. The baseline pin and diff inventory are stale again

Rev 9 claims HEAD `4373ba2…`, 13 files since the rev-7 baseline and 3 files since the r8 review (`docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-9.md:5`). At audit time HEAD was `75fa924a1bd5f0825327a792ac84e754aa441640`; the two corresponding counts were 16 and 6. Both diffs remain documentation-only, so production citations are unaffected.

## Citation audit

| Claim | Verified/wrong with the real line |
|---|---|
| Rev-9 baseline HEAD/diff inventory at line 5 | **WRONG/stale.** Audit-time HEAD is `75fa924a1bd5f0825327a792ac84e754aa441640`; 16 documentation files differ from `85a455605`, not 13. No production file differs. |
| §5.0b enumerates every receiver-reachable shape | **WRONG/incomplete** at `docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-9.md:270`. It omits stock-level, product-stock, rebalance, counting-reconciliation, and batch-stock response families identified in B1. |
| T9’s whole-body scan of stock-matrix/POS contains no sentinel after step 5b | **WRONG/unsatisfiable.** The spec itself says `stock_levels` becomes `7391.4517` at `docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-9.md:560,586`; code emits it through matrix `on_hand` (`apps/api/app/Modules/Inventory/Application/Services/StockMatrixQueryService.php:289-298`), POS quantity (`apps/api/app/Modules/POS/Presentation/Controllers/PosStockLevelController.php:94-103`) and POS distribution `on_hand` (`apps/api/app/Modules/POS/Presentation/Controllers/StockDistributionController.php:85-95`). |
| §5.10 says the movement feeds hold no client-side cache requiring blind convergence | **WRONG/underspecified** at `docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-9.md:403`. All three consumers are TanStack-cached and inherit the five-minute default (`apps/web/src/features/inventory/StockMovementsPage.tsx:144-171`; `apps/web/src/features/inventory/components/ProductMovementsTab.tsx:85-101`; `apps/web/src/features/inventory/EntryExitNotesPage.tsx:73-85`; `apps/web/src/lib/queryClient.ts:3-9`). |
| §5.10’s current movement-controller predicates and fields | **VERIFIED.** No actor predicate exists at `apps/api/app/Modules/Inventory/Presentation/Controllers/StockMovementController.php:60-80`; quantity/before/after are emitted at `apps/api/app/Modules/Inventory/Presentation/Controllers/StockMovementController.php:208-245`. Entry/exit grouping and quantities are at `apps/api/app/Modules/Inventory/Presentation/Controllers/EntryExitNoteController.php:44-72,216-254`. |
| Exactly three status-based in-transit aggregate readers | **VERIFIED.** `apps/api/app/Modules/Inventory/Application/Services/LocationStockQueryService.php:137-164,217-245`, `apps/api/app/Modules/Inventory/Application/Services/StockMatrixQueryService.php:390-408`, and `apps/api/app/Modules/Inventory/Application/Services/WeightedAverageCostService.php:117-127`. The remaining transfer-line reader is initiate-time replenishment settlement only (`apps/api/app/Modules/Inventory/Application/Services/TransferLineQueryService.php:13-25`). |
| Damage/WriteOff enum and GL claims | **VERIFIED.** Reasons exist and use the Shrinkage counter family/GL at `apps/api/app/Modules/Inventory/Domain/Enums/MovementReason.php:26-28,68-112`; lot and lot-less bridges are at `apps/api/app/Modules/Inventory/Application/Services/InventoryGlPostingService.php:89-127,150-207`. |
| Stored-event aggregate anchoring | **VERIFIED.** Direct wildcard storage lacks aggregate UUID (`apps/api/vendor/spatie/laravel-event-sourcing/src/StoredEvents/EventSubscriber.php:34-38`); explicit UUID/version persistence is at `apps/api/vendor/spatie/laravel-event-sourcing/src/StoredEvents/Repositories/EloquentStoredEventRepository.php:101,118-134`. |
| Detail-page corrected range | **VERIFIED.** Full summary block is `apps/web/src/features/stock-transfers/pages/StockTransferDetailPage.tsx:124-176`. |
| `/complete` returns 200 | **VERIFIED** at `apps/api/app/Modules/Inventory/Presentation/Controllers/StockTransferController.php:231-235`. |
| POS replenishment metadata is top-level | **VERIFIED** at `apps/api/app/Modules/POS/Presentation/Controllers/PosReplenishmentController.php:106-110`. |
| Receive/close raw envelopes are needed | **VERIFIED.** `apiPost` unwraps to `response.data.data` at `apps/web/src/lib/api.ts:422-425`; rev 9 correctly uses raw `api.post` at `docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-9.md:466,469`. |
| Remaining line-qualified citations | **VERIFIED mechanically and semantically spot-checked.** All 518 unique line-qualified tokens resolve to existing files and in-range lines. No additional wrong or stale `path:line` claim was found. Future edit targets without line suffixes are intentionally absent today. |

## Rejected false positives

- The §5.10 predicate is safe in isolation. `canSeeIncomingAggregates === false` is a conservative company-level mask, and masking actor-owned before/after balances is necessary because those balances include other receivers’ stock (`docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-9.md:388-401`). Terminal history remains visible as accepted R2.

- No fourth status-based in-transit aggregate reader was found. The completed-row backfill preserves historical answers: completed transfers were excluded previously, remain outside the carrying set, and produce zero remainder after received-equals-sent (`docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-9.md:618-620`).

- Status exhaustiveness is accounted for. Current PHP methods and label match are centralized (`apps/api/app/Modules/Inventory/Domain/Enums/TransferStatus.php:17-49`); web badge, filters and detail actions are the affected exhaustive consumers (`apps/web/src/features/stock-transfers/components/StockTransferStatusBadge.tsx:5-10`; `apps/web/src/features/stock-transfers/pages/StockTransferListPage.tsx:15-24`; `apps/web/src/features/stock-transfers/pages/StockTransferDetailPage.tsx:38-39`). `closed_with_writeoff` is 20 characters and fits the existing `string(20)` (`apps/api/database/migrations/tenant/2026_05_28_120000_create_stock_transfers_table.php:41-42`).

- TransferIn followed by Damage/WriteOff is viable. Receipt posting uses bcmath and scale-4 storage (`apps/api/app/Modules/Inventory/Domain/Services/StockAdjustmentService.php:133-176`); GL posting uses the existing shrinkage paths (`apps/api/app/Modules/Inventory/Application/Services/InventoryGlPostingService.php:89-127,150-207`) and buffer boundary (`apps/api/app/Modules/Inventory/Application/Services/InventoryGlPostingBuffer.php:39-67`). WAC remains untouched by the quantity receipt/issue pair.

- Stored per-line events meet the owner’s pattern-detection grain: actor, role, blind flag, sent/previous/current quantities, reason, lot detail, and stable movement IDs are present (`docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-9.md:192-224`). Existing events remain unchanged and integration dispatch is after commit (`docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-9.md:188-203`). Worker-side recipient resolution does not depend on `CompanyContext` (`docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-9.md:455-459`).

- Idempotency and concurrency are coherent: transfer header lock first, company-wide key lookup/hash discriminator, reserved `sys:` keys, sorted product locks, and partial uniques (`docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-9.md:300-327`). The order matches today’s header-first/sorted-product path (`apps/api/app/Modules/Inventory/Application/Services/StockTransferService.php:317-339,346-420,734-742`).

- Routes inherit the required tenant, permission-team and Inventory-module middleware (`apps/api/app/Modules/Inventory/Presentation/routes.php:31`). D1 is justified because `inventory.view` is broadly seeded while reconcile/close are supervisory and separately grantable (`apps/api/database/seeders/RolesAndPermissionsSeeder.php:196-200,565-602,702,741,771,806`).

- `/complete` delegation does not create a second receipt writer. It becomes compatibility syntax over the same `StockTransferReceiptService`, consistent with the one-surface rule (`docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-9.md:80-84,357-360`).

- Benchmark, glossary plan, second-company/location/rerun matrix, migrations and PostgreSQL partial checks remain adequate apart from B1’s missing surfaces (`docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-9.md:32-84,510-552,616-623`).

## Preserve

The next revision must preserve:

- Odoo-style open remainder, hard over-receipt refusal, and the owner-approved coarse `partially_received` status (`docs/handoff/BRIEF-parallel-transfers-blind-receiving-2026-09-08.md:50-54`; `docs/handoff/OWNER-QUESTIONS-consolidated-2026-09-06.md:102`).
- `return_to_source` with exact source restoration and no GL (`docs/handoff/OWNER-QUESTIONS-consolidated-2026-09-06.md:100`; `docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-9.md:431-443`).
- Company-level, default-off blind receiving; transfers first, PO receipts later.
- Destination membership with no receiver assignment.
- DB notifications and web bell first.
- Quantity-based remainder in exactly the three aggregate readers, completed-row backfill, and cancelled exclusion.
- Scale-4 decimal strings, `QuantityScale`, bcmath, decimal casts/storage, and no float arithmetic (`docs/architecture/precision-contract.md:18-45`).
- Immutable existing events plus stored header/per-line receipt facts with receiver/closer identity (`docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-9.md:180-224`).
- Separate full and receiver builders, generated entity DTOs, and list/show discriminated unions.
- Reconcile and close permissions seeded to manager/admin and independently grantable; Close requires both (`docs/handoff/OWNER-QUESTIONS-consolidated-2026-09-06.md:98,108`).
- One receipt writer, `/complete` delegation, no cancel after partial receipt, and no blind quantity-less completion.
- Confirmed freight residual/no-journal behavior, multi-receiver attribution, replenishment-note masking, reserved `sys:` keys, accepted R1 probing, accepted R2 terminal history, and advisory cache convergence (`docs/handoff/OWNER-QUESTIONS-consolidated-2026-09-06.md:106,108`).
- §5.10’s correct direct movement masking: carrying transfers only, all receivers’ rows including the actor’s own balances, receipt-linked damage, and terminal history restored.

## Owner decisions required

One genuinely new ruling is required:

1. **Current-stock residual:** may a blind destination receiver continue to see ordinary destination on-hand, available and lot-stock quantities while a transfer is carrying? Current code exposes them through stock-level, matrix, POS, product, rebalance, counting-reconciliation and batch surfaces, and a before/after delta can equal the hidden transfer quantity (`apps/api/app/Modules/Inventory/Application/DTOs/StockLevelData.php:18-32,61-80`; `apps/api/app/Modules/BatchExpiry/Presentation/Resources/BatchResource.php:55-60`). If yes, rev 9 must explicitly accept that inference and narrow §5.0/T9. If no, those response families, types and caches must join the blind-surface closure. This does not reopen OD-1, close authority, OD-4, D1/D2, OQ-1–4, freight or attribution.

VERDICT: CHANGES-REQUIRED