# Codex spec gate r3 — T-2/T-3 (gpt-5.6-sol, high, read-only, 2026-09-09)

Reviewed rev 3 against repository HEAD `0310a1df69e8a9c2bc15778745017fcdd3c2d54a`. No files were modified.

## Rev-2 closure table

| Round-2 finding | Rev-3 disposition |
|---|---|
| B1 — quantity-less `complete` defeats blind receiving | **CLOSED at rev 3** `docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-3.md:213,220,278-279,394`. Blind `complete` now returns `BLIND_REQUIRES_COUNTED_RECEIPT`. A separate close-authority contradiction remains below. |
| B2 — sender notes and POS runtime/cache leakage | **CLOSED for the exact r2 findings** at `…design-rev-3.md:217-232,260-269,359-365,394`. Sender notes and batch quantities are omitted by construction; POS null/normalization work is specified. The replenishment feed and activation-time caches are new omissions below. |
| B3 — aggregate identity and insufficient replay payload | **CLOSED for the exact r2 defects** at `…design-rev-3.md:162-181,400`: explicit repository persistence, receipt aggregate UUID, versions, receipt notes, payload hash and receipt-lot IDs are present. Multi-lot scalar movement semantics remain underspecified below. |
| M1 — impossible mixed-concurrency expectations | **CLOSED at rev 3** `…design-rev-3.md:138,244-250,391-392`. Both forced orderings now describe their serialized outcomes. |
| M2 — completed backfill violates I4 | **CLOSED at rev 3** `…design-rev-3.md:109,120,153-155,180,396,410`. Synthetic `legacy_completion` receipts restore counter-to-receipt consistency. |
| M3 — periodic valuation not refused for lot write-offs | **CLOSED at rev 3** `…design-rev-3.md:247,258,303-305,385`. The root preflight covers lot and non-less paths. |
| M4 — landed weights cannot create a freight residual | **CLOSED as a technical specification** at `…design-rev-3.md:312-325,386-387`. The capitalizable pool and residual are now exact. OQ-4 itself remains an owner decision. |
| M5 — receiver-view permission missing | **CLOSED for the API endpoint** at `…design-rev-3.md:217,234,260-269`. The web route remains inaccessible to a complete-only receiver; see M1 below. |
| M6 — pattern query measures closers and crosses companies | **CLOSED at rev 3** `…design-rev-3.md:176-203,401`. Receipt and close actors are separated, and the result is company-filtered. OD-2 remains awaiting owner confirmation. |
| M7 — half-migrated schema cannot self-heal | **NOT CLOSED.** The four passes omit primary-key repair and cannot restore required no-default columns on populated tables; see M2 below (`…design-rev-3.md:97,109-113,382`). |
| M8 — status list is not exhaustive | **CLOSED at rev 3** `…design-rev-3.md:348-354`. The `Record<TransferStatus, true>` source is exhaustive. |
| M9 — second-company/location tests omit `complete` | **CLOSED for that exact omission** at `…design-rev-3.md:380-382`. Other every-writer matrix omissions remain below. |
| M10 — POS imports an Inventory application service | **CLOSED at rev 3** `…design-rev-3.md:262-264,281-282`. Visibility crosses through Shared contracts. |
| m1 — `defaultsForVertical()` PHPDoc omitted | **CLOSED at rev 3** `…design-rev-3.md:105`. |
| m2 — blind numeric-token test overbroad | **CLOSED at rev 3** `…design-rev-3.md:394`. The oracle uses forbidden semantic keys plus deliberate sent/remainder sentinels. |
| Round-2 citation audit | **NOT CLOSED.** The three substantive r2 miscites were corrected, but the HEAD/diff claim is stale, the precision ingress citation is wrong, planned client edits are presented as current line evidence, and many paths remain abbreviated despite the “literal paths everywhere” assertion. |
| Owner decision 1 — OQ-4 freight | **NOT CLOSED.** An implementable default exists, but the owner ruling is still required at `…design-rev-3.md:421`. |
| Owner decision 2 — multi-receiver attribution | **NOT CLOSED as an owner decision.** OD-2 is coherent and implemented in the spec, but still explicitly awaits confirmation at `…design-rev-3.md:423-425`. |
| Round-1 tail — reject `return_to_source` as out of scope | **REJECTED-correctly.** The owner overrode the brief; rev 3 includes return-to-source without GL at `…design-rev-3.md:258,298-310,387,428`, consistent with `docs/superpowers/reviews/2026-09-09-benchmark-transfer-discrepancy-gl.md:7-13`. |
| OQ-1, OQ-2, OQ-3, D1, D2 | **CLOSED by owner ruling**, correctly not reopened at `docs/handoff/OWNER-QUESTIONS-consolidated-2026-09-06.md:98-103` and `…design-rev-3.md:428`. |

## BLOCKER

### B1. A missed replenishment response exposes a transfer-linked quantity through both web and POS APIs

The surface inventory at `docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-3.md:215-232` omits replenishment responses.

For a transfer created from replenishment requests, the selected action quantity becomes the transfer-line quantity at `apps/api/app/Modules/Replenishment/Application/Services/ReplenishmentFulfillmentService.php:67-74`. On initiation, matching destination/product requests are marked fulfilled with `fulfillment_id = transferId` at `apps/api/app/Modules/Replenishment/Application/Listeners/SettleRequestsOnTransferInitiated.php:44-71`.

The common resource then returns both quantities and the linking transfer UUID:

- `requested_qty` and `suggested_qty`: `apps/api/app/Modules/Replenishment/Presentation/Resources/ReplenishmentRequestResource.php:17-31`.
- `fulfillment_id`: `apps/api/app/Modules/Replenishment/Presentation/Resources/ReplenishmentRequestResource.php:38-40`.

That resource is reachable through:

- `GET /replenishment-requests`, including fulfilled rows, at `apps/api/app/Modules/Replenishment/Presentation/routes.php:14-16` and `apps/api/app/Modules/Replenishment/Presentation/Controllers/ReplenishmentRequestController.php:36-92`.
- `GET /pos/replenishment-requests`, whose feed deliberately retains fulfilled rows for 14 days, at `apps/api/app/Modules/POS/Presentation/Controllers/PosReplenishmentController.php:88-110` and `apps/api/app/Modules/Replenishment/Application/Services/ReplenishmentQueryService.php:29-51`.

A destination-restricted user can be granted `inventory.transfers.complete` together with `replenishment.view` or `pos.operate_terminal` without `inventory.transfers.reconcile`; the permissions are independently grantable (`…design-rev-3.md:285`). In the valid one-request/one-line case where the selected transfer quantity equals the request quantity, this reveals the exact sent quantity and transfer association.

This directly falsifies the universal blind guarantee at `…design-rev-3.md:213` and is absent from T9 at `…design-rev-3.md:394`. The surface contract must mask or unlink transfer-fulfilled replenishment quantities for actors failing `canSeeExpected()`.

### B2. A blind destination actor with `close` can still make the server post the hidden remainder

The guarantee says an actor failing `canSeeExpected()` “cannot cause the system to post a quantity on their behalf” (`…design-rev-3.md:213`). Rev 3 enforces that for `complete`, but not for `close`.

The close endpoint requires only `inventory.transfers.close` and destination access (`…design-rev-3.md:253-258`). `canSeeExpected()` does not include the close permission; it is setting-off, reconcile, source-access or unrestricted only (`…design-rev-3.md:263-264`). Both close and reconcile are independently grantable (`…design-rev-3.md:285`).

Consequently, a destination-only user granted close but not reconcile can submit `write_off` without knowing a quantity, and the service posts the entire server-derived line/lot remainder (`…design-rev-3.md:258`). The default manager role happens to receive both permissions, but the grantable authorization model permits the contradictory state.

Either close must require expected-quantity visibility/reconcile authority, or close authority must itself confer that visibility. The API, web affordance and permission tests must enforce one rule.

### B3. “No client cache” is not closed when blind receiving is enabled at runtime

The guarantee covers “any authenticated endpoint or client cache” (`…design-rev-3.md:213`), but the rollout only invalidates the browser that performs the setting mutation and clears POS values after a later successful pull (`…design-rev-3.md:232,346,362-363`).

Current web queries normally remain fresh for five minutes and do not refetch on focus (`apps/web/src/lib/queryClient.ts:4-9`). Rev 3 proposes `staleTime: 0` and focus refetch, but that still leaves a second already-open browser rendering its formerly authorized full payload until another fetch occurs. The mutation is local to `FraudSettingsPage`; current invalidation occurs through its own query client (`apps/web/src/features/compliance/pages/FraudSettingsPage.tsx:85-107`).

The POS problem is stronger:

- Exact transfer incoming remains in SQLite until `replaceIncoming()` runs (`apps/pos/src/lib/db/repositories/locationStockRepository.ts:185-229`).
- Normal stock sync is a 60-second tick and may fail without blocking POS operation (`apps/pos/src/lib/sync/syncService.ts:1019-1051`).
- Exact cross-location results remain opaque cached JSON until that product is fetched again (`apps/pos/src/lib/db/repositories/crossLocationStockRepository.ts:1-23`).

An offline device can therefore retain exact values indefinitely after the company setting changes. Rev 3 must define whether activation is immediate or effective only after a successful client re-baseline. Its present absolute guarantee and implementation cannot both be true.

### Blind response-shape audit

| Receiver-reachable shape | Result |
|---|---|
| Receiver view | Structurally safe as specified: only identity, product/lot identity and the actor’s own submitted receipts; no batch-allocation quantity (`…design-rev-3.md:217,267`). |
| Transfer list/show | Safe only through the required per-transfer builder choice (`…design-rev-3.md:218,263-268`). |
| Receive response | Safe: echoes submitted quantities and uses the gated transfer builder (`…design-rev-3.md:219,251`). |
| Complete response/422 | Safe after r2: blind use returns a static typed 422 before replay lookup (`…design-rev-3.md:220,278-279`). |
| Close response | Response masking is safe, but the mutation itself violates the no-server-derived-posting guarantee; see B2 (`…design-rev-3.md:221,258`). |
| Validation/domain 422s | Static messages and IDs-only details prevent sent/remainder leakage; `partially_received` is an owner-accepted coarse signal (`…design-rev-3.md:213,222,394`; owner ruling `docs/handoff/OWNER-QUESTIONS-consolidated-2026-09-06.md:102`). |
| Stock matrix | Safe if the proposed transfer-only masking flag is applied; PO incoming remains visible (`…design-rev-3.md:223,281-282`). |
| POS stock levels/distribution | Wire shapes are safe with `incoming_transfer: null`; existing exact device caches remain a runtime activation leak, B3 (`…design-rev-3.md:224-225,359-365`). |
| Stock movements | No pre-receipt destination movement exists; post-receipt values are the actor’s own submitted quantities, with terminal history accepted as R2 (`…design-rev-3.md:226`). |
| Entry/exit notes | The specified location scoping closes the current company-wide exposure (`…design-rev-3.md:227`). |
| Reconciliation | Expected quantities remain behind the dedicated permission plus endpoint access (`…design-rev-3.md:228,271-273`). |
| Notifications | Proposed data contains counts/status only, not sent or expected quantities (`…design-rev-3.md:229,333-337`). |
| Generated web/mobile types | Receiver DTOs omit expected fields, subject to the comment-generation issue under MINOR (`…design-rev-3.md:230-231,343,369-372`). |
| Replenishment web/POS responses | **Leak.** Missing from the surface table; see B1. |
| Browser/POS caches | **Not closed during a setting transition.** See B3. |

## MAJOR

### M1. The web route still excludes the complete-only receiver defined by Q5

The receiver-view API intentionally requires `inventory.transfers.complete`, not `inventory.transfers.view` (`…design-rev-3.md:217,234`). However, the only existing web list and detail routes require `inventory.transfers.view` at `apps/web/src/routes/index.tsx:1401-1429`, and the sidebar link uses the same permission at `apps/web/src/components/organisms/Sidebar/Sidebar.tsx:239`.

Rev 3 only adds `moduleKey="inventory"` to those route wrappers (`…design-rev-3.md:344`) and does not provide an accessible receiver route or change the detail gate to accept complete authority. A destination member satisfying owner Q5—location membership plus `inventory.transfers.complete`, no assignment—can call the API but cannot reach the web receiving UI. Q5 is confirmed at `docs/handoff/BRIEF-parallel-transfers-blind-receiving-2026-09-08.md:50-54`.

### M2. The self-healing migration recipe still cannot satisfy its “ANY partial failure” promise

The recipe at `…design-rev-3.md:97` has passes for tables, columns, FKs/CHECKs and indexes, but no pass for primary keys. The new tables require UUID primary keys (`…design-rev-3.md:109-113`); existing migrations create them explicitly with `$table->uuid('id')->primary()` at `apps/api/database/migrations/tenant/2026_05_28_120000_create_stock_transfers_table.php:29-30` and `apps/api/database/migrations/tenant/2026_07_04_100000_create_goods_receipts_tables.php:14-16,34-36`.

There is a second failure mode: S3 drops an arbitrary receipt-table column and expects rerun repair (`…design-rev-3.md:382`). Adding a required, no-default column such as `tenant_id`, `company_id`, `transfer_id`, `receipt_number` or `received_by_user_id` to a populated surviving table cannot restore `NOT NULL` in one plain `Schema::table` add. It needs a nullable/add-backfill-not-null repair sequence or a narrower transactional-only guarantee.

The existing named-CHECK and `CREATE INDEX IF NOT EXISTS` precedents are real (`apps/api/database/migrations/tenant/2026_08_10_100000_add_inventory_valuation_mode_to_companies.php:39-55`; `apps/api/database/migrations/tenant/2026_04_24_000002_add_z_report_alert_type_unique_index_to_fraud_alerts.php:37-42`), but they do not solve either defect.

### M3. Multi-lot receipt-line movement identity is ambiguous and T15 does not pin it

The parent receipt-line table has one scalar `in_movement_id`, `scrap_movement_id` and `return_movement_id`, while the lot table repeats those columns (`…design-rev-3.md:111-113`). The movement algorithm creates a distinct receive/issue movement for each lot (`…design-rev-3.md:291-298`), and the line event likewise carries both scalar parent movement IDs and per-lot IDs (`…design-rev-3.md:176-180`).

For a single transfer line received from two shipped lots, there is no single parent movement ID to store. Rev 3 must say that parent movement columns are null for lot-tracked lines and lot rows are authoritative, or define another deterministic mapping. T15 only promises exact replay generally (`…design-rev-3.md:400`); T3 uses “the real batch number” but does not exercise one line with multiple lots (`…design-rev-3.md:385`). Without that fixture, replay can be internally consistent while implementations choose incompatible meanings.

### M4. The discrepancy-reason-to-movement mapping contradicts the receipt rules

`TransferDiscrepancyReason::movementReason()` maps only `DamagedInTransit` to `Damage` and maps every other reason to `WriteOff` (`…design-rev-3.md:121`). The receive rules permit any enum reason whenever `quantity_damaged > 0`; they do not restrict it to `damaged_in_transit` (`…design-rev-3.md:236-249`). The movement table, however, says every damaged quantity posts `MovementReason::Damage` (`…design-rev-3.md:295-297`).

Thus a damaged receipt with `reason = other`, `short_shipped` or `lost_in_transit` either posts `WriteOff`, contradicting §6.1, or ignores `movementReason()`, making the enum method false/dead. Both reasons exist and require shrinkage GL (`apps/api/app/Modules/Inventory/Domain/Enums/MovementReason.php:26-28,73-111`), so the monetary leg balances, but the movement’s audit reason becomes wrong. Movement reason must derive from the action, or the accepted discrepancy reasons must be constrained per action.

### M5. Deterministic notification IDs prevent duplicate rows by failing the queued job, not by making delivery idempotent

Rev 3 assigns a deterministic notification UUID and relies on the primary key to refuse duplicates (`…design-rev-3.md:333-336`). Laravel preserves a preset notification ID (`apps/api/vendor/laravel/framework/src/Illuminate/Notifications/NotificationSender.php:150-154,214-228`), but the database channel performs an ordinary Eloquent `create()` (`apps/api/vendor/laravel/framework/src/Illuminate/Notifications/Channels/DatabaseChannel.php:17-21`). It has no conflict-ignore behavior.

A replayed listener therefore raises a unique-constraint exception and retries/fails the queued job. T10 merely says “replayed listener → no second row” (`…design-rev-3.md:395`), which can be true while the queue is poisoned. Specify an existence/no-op or conflict-safe insertion and assert that the duplicate job completes successfully with one row.

### M6. The second-of-everything matrix still does not exercise every new writer on every required axis

Rev 3 claims complete coverage at `…design-rev-3.md:60`, but the actual matrix has residual holes:

- S1 changes blind mode in company A and resets company B, but never performs the settings update writer in company B (`…design-rev-3.md:380`).
- S2 receives, completes and returns at the second location but does not execute the write-off close branch there (`…design-rev-3.md:381`).
- S3 reruns receive, both close dispositions, complete and settings update, but not the settings reset writer (`…design-rev-3.md:382`).

The required matrix is second company, second location and rerun with data-meaning assertions at `docs/conventions/09-SECOND-OF-EVERYTHING.md:37-50`.

## MINOR

### m1. The generated receiver type cannot inherit a guard comment from an array builder

Rev 3 says the generated `TransferReceiverViewData` type carries the NEVER-INCLUDE comment “from the backend builder” (`…design-rev-3.md:343`). Type generation operates from PHP DTO declarations, not comments inside a separate array literal. The counting precedent’s comment exists only in controller payload construction at `apps/api/app/Modules/Inventory/Presentation/Controllers/InventoryCountingController.php:334-377` and `apps/api/app/Modules/Inventory/Presentation/Controllers/CountingItemController.php:54-74,161-177`.

The frontend counting API has no counter-view method or NEVER-INCLUDE contract at `apps/web/src/features/inventory-counting/api/countingApi.ts:1-150`. Put the generated comment on the PHP DTO/property itself and test the emitted declaration.

### m2. The shared `scopeCarryingInTransit()` cannot be applied directly at the three current query roots

Rev 3 says three readers replace their status comparison with `StockTransfer::scopeCarryingInTransit()` (`…design-rev-3.md:142-146`). Those readers are rooted in query-builder joins on `stock_transfer_lines`, not a `StockTransfer` Eloquent builder:

- `apps/api/app/Modules/Inventory/Application/Services/LocationStockQueryService.php:139-156,223-233`.
- `apps/api/app/Modules/Inventory/Application/Services/StockMatrixQueryService.php:395-405`.
- `apps/api/app/Modules/Inventory/Application/Services/WeightedAverageCostService.php:119-127`.

The change is implementable through a subquery or a shared SQL status predicate, but the spec should name that shape so the three sites do not silently recreate separate status lists while the ratchet checks only for `REMAINDER_SQL`.

### m3. “Every unique key carries company_id” is false and is not required by convention 09 here

Rev 3 says every unique key on the three receipt tables carries `company_id` (`…design-rev-3.md:60`). Its own schema defines parent-scoped and movement-ID uniques without `company_id`: `(transfer_id, sequence)`, `(receipt_id, transfer_line_id)`, movement IDs, and `(receipt_line_id, batch_allocation_id)` (`…design-rev-3.md:109-113`).

Those keys are safe because their parent/document IDs are UUID identities. Convention 09 applies the company-bearing unique rule to catalogue tables and expressly excludes primary IDs (`docs/conventions/09-SECOND-OF-EVERYTHING.md:30-35,54-59`). Correct the prose rather than adding redundant company columns to every relationship-scoped unique.

## Citation audit

| Claim | Result and real line |
|---|---|
| Baseline HEAD and “only four docs changed” (`…design-rev-3.md:5`) | **WRONG/stale.** Actual HEAD is `0310a1df69e8a9c2bc15778745017fcdd3c2d54a`, not `87a16056…`. `git diff --name-only ffd907b3d..HEAD` contains nine documentation files. No production file appears, so production anchors have not drifted. |
| Owner rulings (`:3,428`) | **VERIFIED** at `docs/handoff/OWNER-QUESTIONS-consolidated-2026-09-06.md:94-103`. OQ-1, D1, OQ-3 and stored per-line events are correctly represented. |
| Glossary headings and convention references (`:32,60,78-89`) | **VERIFIED** at `docs/glossary.md:13,22,32,48,67,80`, `docs/conventions/09-SECOND-OF-EVERYTHING.md:37-50`, and `docs/conventions/11-ONE-SURFACE-PER-CONCEPT.md:37-47`. |
| Transfer initiation/completion/current quantity-less behavior (`:42,66`) | **VERIFIED** at `apps/api/app/Modules/Inventory/Application/Services/StockTransferService.php:86-101,317-383,514-608`. |
| PO partial and over-receipt precedents (`:43-44`) | **VERIFIED** at `apps/api/app/Modules/Inventory/Application/Services/GoodsReceiptService.php:326-345,1231-1234` and `apps/api/app/Modules/Inventory/Domain/Enums/GoodsReceiptFailureReason.php:9-21`. |
| Counting, blind cash and mobile supervisor shapes (`:45`) | **VERIFIED** at `InventoryCountingController.php:334-377`, `CountingItemController.php:54-74,161-177`, `CompanyFraudSettings.php:53-64`, and sibling `erp-mobile/src/features/receiving/types.ts:58-65,98-109`. The frontend `countingApi.ts` has no NEVER-INCLUDE contract. |
| Notification routes/panel (`:46`) | **VERIFIED** at `apps/api/app/Modules/Notification/Presentation/routes.php:19-30` and `apps/web/src/features/notifications/components/NotificationPanel.tsx:20-26,45-98`. |
| Location authorization and transfer visibility (`:47,209,217`) | **VERIFIED** at `apps/api/app/Modules/Inventory/Presentation/Controllers/StockTransferController.php:110-114,215-223,347-357` and `apps/api/app/Modules/Company/Services/LocationContext.php:194-206,224-238`. |
| Cancellation semantics (`:48,55,142`) | **VERIFIED** at `apps/api/app/Modules/Inventory/Domain/Enums/TransferStatus.php:37-40` and `apps/api/app/Modules/Inventory/Application/Services/StockTransferService.php:429-505`. |
| Plain/stored event precedents (`:49,56,162`) | **VERIFIED** at `StockTransferInitiated.php:9-23`, `GoodsReceived.php:18-42`, `StockMovementRecordedV2.php:22-37`, and `apps/api/app/Shared/Domain/Events/DomainEvent.php:7-16`. |
| Initiation unique-race recovery (`:50,245`) | **VERIFIED** at `apps/api/app/Modules/Inventory/Application/Services/StockTransferService.php:170-190`. |
| DDL guard precedents (`:51,97`) | **VERIFIED as CHECK/index precedents** at `2026_08_10_100000_add_inventory_valuation_mode_to_companies.php:39-55` and `2026_04_24_000002_add_z_report_alert_type_unique_index_to_fraud_alerts.php:37-42`. They do not establish primary-key or populated-column repair; M2 applies. |
| Current transfer/line/allocation schema (`:52-53,99-103,109-113`) | **VERIFIED** at `2026_05_28_120000_create_stock_transfers_table.php:29-99`, `2026_06_05_121000_create_stock_transfer_line_batch_allocations_table.php:14-30`, and `StockTransferLine.php:56-62`. Status is `string(20)`; all proposed values fit. |
| Company-fraud settings surfaces (`:105,262,276`) | **VERIFIED** at `CompanyFraudSettings.php:36,53-64,108-144,165-200`, `CompanyFraudSettingsData.php:31-110`, `FraudSettingsController.php:108-123,194-203`, and `CompanyFraudSettingsRepository.php:9-14`. |
| Receipt partial-unique precedents (`:111-113`) | **VERIFIED as uniqueness precedents** at `2026_07_04_100000_create_goods_receipts_tables.php:61-70` and `2026_08_08_120000_create_stock_adjustments_tables.php:123-139`. |
| Numbering (`:115`) | **VERIFIED** at `DocumentNumberingService.php:24-39` and `GoodsReceiptService.php:272-277`. |
| Transfer status/enums/generated type (`:119-123`) | **VERIFIED** at `TransferStatus.php:15-49`, `MovementReason.php:73-111`, `StockMovementReferenceType.php:72`, `StockTransferService.php:745-752`, and `packages/shared/types/generated.d.ts:1240`. Generated type is currently the old four-case union, correctly identified as an edit target. |
| State persistence order and locks (`:138,244,248`) | **VERIFIED** at `StockTransferService.php:317-339,386-394,734-742`. |
| Exactly three in-transit remainder readers (`:140-146`) | **VERIFIED** at `LocationStockQueryService.php:137-164,217-245`, `StockMatrixQueryService.php:390-408`, and `WeightedAverageCostService.php:100-127`. |
| Spatie direct-dispatch and repository behavior (`:166-168,181`) | **VERIFIED** at `EventSubscriber.php:34-38`, `EloquentStoredEventRepository.php:40-51,101-138`, `ShouldBeStored.php:63-73`, and stored-event migration `:11-23`. Manual `persist($event,$receiptId)` is necessary and sufficient for the aggregate anchor. |
| Existing event/listener after-commit behavior (`:170-183`) | **VERIFIED** at `StockTransferService.php:406-418,492,608`, `StockAdjustmentService.php:181-207,325-350`, `InventoryServiceProvider.php:89-105`, and `ReplenishmentServiceProvider.php:18-20`. |
| Inventory middleware and transfer routes (`:209`) | **VERIFIED** at `apps/api/app/Modules/Inventory/Presentation/routes.php:31,98-117`. |
| Current transfer response/error shapes (`:218-222`) | **VERIFIED** at `StockTransferController.php:231-235,282-341,359-402`; current formatter exposes notes, costs, line quantities and batch-allocation quantities exactly as stated. |
| Matrix/POS/movement/entry-note surfaces (`:223-227`) | **VERIFIED** at Inventory routes `:70-84`, `StockMatrixController.php:25-48`, `StockMatrixQueryService.php:390-430`, POS routes `:141-162`, `StockDistributionController.php:35-99`, `PosStockLevelController.php:56-109`, `StockMovementController.php:54-80,208-232`, and `EntryExitNoteController.php:26-72,241-253`. Replenishment endpoints were missed. |
| Ingress precision citation (`:236`) | **WRONG range.** `docs/architecture/precision-contract.md:13-26` covers the no-float/service rules. The `numeric` plus regex FormRequest contract is actually at `docs/architecture/precision-contract.md:30-40`, especially `:32-36`. |
| Canonical JSON/valuation preflight anchors (`:246-248`) | **VERIFIED** at `TerminalRegistrySnapshotService.php:540-558`, `InventoryValuationModeResolver.php:79-88`, and `UnsupportedValuationModeException.php:22`. |
| Visibility contracts and location semantics (`:262-269`) | **VERIFIED** at `InventoryServiceProvider.php:61-69`, `ComplianceServiceProvider.php:49-58`, `CompanyFraudSettingsRepository.php:9-14`, `FraudSettingsResolver.php:7-22`, and `LocationContext.php:194-206,224-238`. |
| Reconcile permission rationale (`:273,285`) | **VERIFIED** at `RolesAndPermissionsSeeder.php:196-200,582-602,702,741,771,806`; `inventory.view` is broadly granted, so D1 is necessary. |
| Movement reasons and stock-adjustment signatures (`:291-298`) | **VERIFIED** at `StockAdjustmentService.php:115-129,253-267`, `MovementReason.php:26-28,73-111`, and `Product.php:315-325`. The reasons exist, require GL and belong to Shrinkage. |
| GL buffer and posting bridge (`:302-307`) | **VERIFIED** at `InventoryGlPostingBuffer.php:29-81`, `InventoryGlPostingService.php:89-127,130-207`, `MovementGlKind.php:9-12`, and `ReturnScrapWriteOffService.php:135-189`. Lot-less uses `Exit`; lot write-off requires batch/product context; the root preflight is required. |
| Freight/WAC current behavior (`:313-323,421`) | **VERIFIED** at `StockTransferService.php:631-728` and `WeightedAverageCostService.php:745-831`. WAC adjustment is quantity-zero, uses bcmath/string values and creates no journal. |
| Notification queue/type/id claims (`:333-337`) | **PARTLY VERIFIED.** Queue and DB-shape precedents are correct at `UserInvitation.php:8-16`, `TreasuryAlertNotification.php:22-40`, `NotificationSender.php:150-153,220-228`, `DatabaseChannel.php:17-38`, and notification migration `:13-20`. The inference that a PK collision is successful idempotency is false; M5 applies. |
| Web transfer anchors (`:343-356`) | **VERIFIED as edit locations** at local types `:1-46`, routes `:1086-1096,1401-1429`, API `:37-50`, queries `:11-20,43-51`, detail `:38,98-107`, list `:15-24`, badge `:5-10`, i18n `:52,108,218,275,474-477`, and fraud settings page `:396-412`. |
| Fraud API invalidation claim (`:346`) | **WRONG as a current-line claim.** `apps/web/src/features/compliance/api/fraudApi.ts:28-38` only performs HTTP calls. Query invalidation currently lives in `FraudSettingsPage.tsx:85-107`; the planned transfer invalidation belongs there or in a new hook. |
| POS nullable type/normalization/render claims (`:361-364`) | **EDIT TARGETS, not current facts.** Current `stockDistribution.ts:12,28` and `locationStockRepository.ts:55-61` use non-null strings; current writer line `locationStockRepository.ts:216` binds the raw value, not `?? '0'`; current `CrossLocationStockSection.tsx:104-117` formats the value and has no masked branch. The zero-first cache-clearing and sync locations at `locationStockRepository.ts:195-199` and `syncService.ts:1035,1123-1148` are correctly cited. |
| Mobile replay and feature-lane anchors (`:369-376`) | **VERIFIED** at sibling `pendingCountSyncService.ts:58-68`, sibling receiving types `:58-65,98-109`, and `apps/api/tests/feature-lane-manifest.json:161-168`. |
| Backfill source fields/movement link (`:410`) | **VERIFIED** at transfer migration `:55-60` and `StockTransferService.php:745-752`. |
| “Literal paths everywhere; no abbreviation” (`:5`) | **WRONG.** Non-literal continuation or basename-only citations remain at spec lines `42,44,47-48,50,52-55,74,97,103,105,109,119,121,123,138,142-146,150-151,166,168,170,181,186,209,217-227,244-245,248,262,264,266-267,273,276,279,282,285,291,295-298,303,343-364,398,410,421`. Their underlying targets were resolved from context and audited above, but they are not full repo-root paths as claimed. |

## Rejected false positives

- There is no fourth production reader of in-transit remainder. The only three are `LocationStockQueryService.php:137-164,217-245`, `StockMatrixQueryService.php:390-408`, and `WeightedAverageCostService.php:117-127`. `TransferLineQueryService.php:13-25` intentionally reads shipped lines for initiation-time replenishment settlement.
- The completed-transfer backfill preserves every remainder reader’s historical answer: completed rows were already excluded by status, and in-transit/cancelled rows are untouched (`…design-rev-3.md:396,410`).
- The new statuses do not require a PostgreSQL enum alteration. The column is `string(20)` (`2026_05_28_120000_create_stock_transfers_table.php:41-42`); `closed_with_writeoff` is exactly 20 characters.
- The backend and web TransferStatus exhaustiveness sites are covered: backend `label()` is a match at `TransferStatus.php:42-49`; web detail/list/badge sites are identified at `StockTransferDetailPage.tsx:38-39`, `StockTransferListPage.tsx:15-24`, and `StockTransferStatusBadge.tsx:5-10`.
- Land-then-issue arithmetic is sound. `Damage` and `WriteOff` exist, require GL and use Shrinkage (`MovementReason.php:26-28,73-111`); `receive()` and `issue()` accept batch, cost and source-reference context (`StockAdjustmentService.php:115-129,253-267`). Neither directly changes WAC.
- The GL bridge is viable: lot-less destruction can use `MovementGlKind::Exit`, lot destruction can use `BatchWriteOff`, and `ReturnScrapWriteOffService.php:135-189` is a valid buffer/context precedent.
- Return-to-source with no GL is correct and owner-ruled (`docs/superpowers/reviews/2026-09-09-benchmark-transfer-discrepancy-gl.md:7-13`; owner ruling `docs/handoff/OWNER-QUESTIONS-consolidated-2026-09-06.md:100`).
- The new stored events do not mutate existing event schemas, satisfying rule 8 (`CLAUDE.md:36-37`; `StockTransferInitiated.php:9-23`; `StockTransferCompleted.php:9-26`).
- Header-first then sorted product locks match the current completion ordering (`StockTransferService.php:317-339,734-742`).
- Keeping `complete` alongside `receive` does not violate one-surface-per-concept if it delegates entirely to `StockTransferReceiptService` (`docs/conventions/11-ONE-SURFACE-PER-CONCEPT.md:37-39`).
- D1 is necessary: `inventory.view` is too broad, while reconcile is correctly seeded to manager plus admin and remains grantable (`RolesAndPermissionsSeeder.php:702,741,771,806`; owner ruling `…OWNER-QUESTIONS…md:98`).
- The new Inventory routes inherit the rule-12 middleware and `module:Inventory` from `apps/api/app/Modules/Inventory/Presentation/routes.php:31`.
- Coarse `partially_received` status is not a leak finding; the owner explicitly accepted it (`…OWNER-QUESTIONS…md:102`).
- Rev 3 has a valid benchmark-first table with fifteen decision rows, exceeding the required five (`…design-rev-3.md:36-56`; convention `docs/conventions/10-BENCHMARK-FIRST-SPECS.md:37-56`).
- No shadow web transfer DTO is intended: rev 3 removes the current hand-written domain interfaces and switches to generated DTOs (`…design-rev-3.md:343`; convention `docs/conventions/11-ONE-SURFACE-PER-CONCEPT.md:44-47`).

## Preserve

- Preserve Odoo-style open remainder/backorder semantics and hard over-receipt refusal (`docs/handoff/BRIEF-parallel-transfers-blind-receiving-2026-09-08.md:50-51`).
- Preserve the distinction between an unreasoned open remainder and a reasoned damaged/closed shortage (`…design-rev-3.md:68-70`).
- Preserve company-level, default-off blind receiving; transfers first and PO receipts later (`…BRIEF…md:52`; `…design-rev-3.md:72-74`).
- Preserve DB notifications/web bell first and no push in this lane (`…BRIEF…md:53`; `…design-rev-3.md:331-337`).
- Preserve destination membership with no receiver assignment (`…BRIEF…md:54`; owner-confirmed default in the prompt).
- Preserve owner-ruled `return_to_source` on close with stock movement only and no GL (`…OWNER-QUESTIONS…md:100`; benchmark `…benchmark-transfer-discrepancy-gl.md:7-13`).
- Preserve quantity-based remainder in exactly the three readers, completed-row backfill for historical reader parity, and cancelled exclusion (`…design-rev-3.md:140-156,396,410-411`).
- Preserve decimal strings, `QuantityScale`, scale-4 quantity storage, bcmath arithmetic and no floats (`docs/architecture/precision-contract.md:7-26`; `CLAUDE.md:71-79`).
- Preserve immutable existing events, new versioned stored header/per-line events, explicit aggregate anchoring and transactionally persisted facts (`…design-rev-3.md:160-183`).
- Preserve separate full and receiver payload builders with omission by construction (`…design-rev-3.md:260-269`).
- Preserve `inventory.transfers.reconcile`, seeded to manager plus admin and independently grantable (`…OWNER-QUESTIONS…md:98`; `…design-rev-3.md:271-285`).
- Preserve one receipt writer, with `complete` delegating only for actors authorized to see expected quantities (`…design-rev-3.md:278-279`).
- Preserve refusal to cancel after any partial receipt (`…design-rev-3.md:126-138,388`).
- Preserve stored per-line receipt and close facts for pattern detection (`…OWNER-QUESTIONS…md:103`; `…design-rev-3.md:172-203`).
- Preserve the exact freight pool/residual algorithm as the interim default until OQ-4 is ruled (`…design-rev-3.md:312-325`).

## Owner decisions required

1. **OQ-4 remains genuinely open.** Confirm whether the exact §6.4 residual/no-journal default is accepted or choose one of the stated alternatives before merge (`…design-rev-3.md:312-325,421`).

2. **OD-1 remains open.** Confirm that quantity-less `complete` is unavailable whenever `canSeeExpected()` is false (`…design-rev-3.md:423-425`). Nothing in the existing code or owner rulings settles that product decision.

3. **OD-2 remains open.** Confirm the proposed multi-receiver attribution: receiver events measure what each receiver posted, while close shortages remain closer-authored transfer facts joined back to every contributing receiver line (`…design-rev-3.md:185-203,423-425`).

4. **New question: does close authority imply reconciliation visibility?** Independent grants currently permit a blind destination actor to write off a hidden remainder (`…design-rev-3.md:253-264,285`). Decide whether close requires reconcile or whether close permission itself makes expected quantities visible.

5. **New question: when does enabling blind mode become effective for cached clients?** Decide between immediate revocation semantics and “effective after the next successful re-baseline.” The absolute no-cache guarantee cannot be met for offline devices that previously received authorized exact values (`…design-rev-3.md:213,232,346,362-363`).

OQ-1, OQ-2, OQ-3, D1 and D2 are already ruled and must not be reopened (`docs/handoff/OWNER-QUESTIONS-consolidated-2026-09-06.md:98-103`).

VERDICT: CHANGES-REQUIRED
