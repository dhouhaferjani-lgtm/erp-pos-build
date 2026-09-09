# Codex spec gate r2 — T-2/T-3 (gpt-5.6-sol, high, read-only, 2026-09-09)

Reviewed rev 2 against current code at `f5fba63fbcfc87a9d5f38f3cb8ea56996c38d871`. The only change since the spec’s cited baseline `ffd907b3d` is the rev-2 document itself, so production-code line locations have not drifted.

## Rev-1 closure table

| Round-1 finding | Rev-2 disposition |
|---|---|
| B1 blind not closed over every surface | **NOT CLOSED.** The surface table is much better, but quantity-less `complete` bypasses blind counting and its computed quantity reappears through `my_receipts`; sender-authored transfer notes remain exposed; the required POS-client contract changes are absent. See B1–B2 below. |
| B2 events insufficient for replay | **NOT CLOSED.** Per-line events are added at rev 2 §4.2, but direct Spatie dispatch does not persist the claimed aggregate UUID; receipt notes and lot-row IDs are absent from the replay data. See B3. |
| B3 replay order/key race/hash | **CLOSED** at rev 2 §5.1 rules 1–3 (`docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-2.md:229-236`). |
| M1 incorrect I2/I5 | **CLOSED** at rev 2 §3.6 (`…design-rev-2.md:150-157`). |
| M2 remainder versus short conflated | **CLOSED** at definitions and receive/close rules (`…design-rev-2.md:69-75,233-243`). |
| M3 non-batch GL kind missing | **CLOSED** for that exact issue at rev 2 §6.2: non-batch uses `MovementGlKind::Exit` (`…design-rev-2.md:292-297`). A different lot/periodic-valuation defect remains below. |
| M4 total-write-off freight outcome false | **NOT CLOSED.** Rev 2 acknowledges the owner question, but the stated landed-weight algorithm still allocates the whole cost and cannot produce the asserted uncapitalized share. See M4. |
| M5 authz/module gating | **CLOSED** for reconciliation, D1, and web module gating at rev 2 §§5.4, 5.8, 8. The new receiver-view route itself remains ungated in the contract; see M5. |
| M6 schema integrity/settings surfaces | **NOT CLOSED.** Partial uniques and named setting surfaces were added, but the half-migrated-schema guarantee is not implementable from the specified table-level guards, and a second defaults PHPDoc remains omitted. See M7 and m1. |
| M7 reader ratchet scans writer code | **CLOSED** at rev 2 §3.5 (`…design-rev-2.md:142-148`). |
| M8 frontend exhaustiveness | **NOT CLOSED.** `Record<TransferStatus,…>` is adequate for actions/badges, but `satisfies readonly TransferStatus[]` does not prove list completeness. See M8. |
| M9 notification identity/deep link/worker | **CLOSED** at rev 2 §§7 and 10 T10 (`…design-rev-2.md:310-317,362`). |
| M10 test matrix gaps | **NOT CLOSED.** Mixed concurrency was added but asserts impossible winner/loser behavior; `complete` is still absent from the second-company and second-location cases. See M1 and M9. |
| M11 benchmark rows | **CLOSED** at B9–B15 (`…design-rev-2.md:51-57`). |
| m1 ingress “strings only” | **CLOSED** at rev 2 §5.1 (`…design-rev-2.md:220-227`). |
| m2 blind cache identity | **CLOSED** at rev 2 §§8 and 10 T9 (`…design-rev-2.md:326,361`). |
| m3 Arabic fallback | **CLOSED** at rev 2 §8 (`…design-rev-2.md:329`). |
| m4 correction overclaim | **CLOSED** at rev 2 out-of-scope definition (`…design-rev-2.md:78`). |
| Round-1 citation audit | **NOT CLOSED.** Most citations are correct, but the claimed HEAD is not current, several citations remain non-resolving ellipses, and three substantive citation claims are wrong. See Citation audit. |
| OQ-3 coarse status | **CLOSED by owner ruling**, preserved as accepted residual R3 (`…design-rev-2.md:200`; `docs/handoff/OWNER-QUESTIONS-consolidated-2026-09-06.md:102`). |
| D1 seed reconcile to manager/admin | **CLOSED by owner ruling** at rev 2 §5.8 (`…design-rev-2.md:273-275`; owner ruling at `…OWNER-QUESTIONS…md:98`). |
| Freight owner decision | **NOT CLOSED.** Correctly remains open as OQ-4, but its default needs a coherent algorithm before it can serve as an interim implementation rule. |
| Round-1 tail: return-to-source out of scope | **REJECTED-correctly.** Owner overrode it; rev 2 includes `return_to_source` with no GL (`…design-rev-2.md:238-243,300-304`; benchmark `docs/superpowers/reviews/2026-09-09-benchmark-transfer-discrepancy-gl.md:7-13`). |
| OQ-2 replenishment settlement | **CLOSED by owner ruling**: remains settled (`…OWNER-QUESTIONS…md:101`; rev 2 `…design-rev-2.md:178`). |

## BLOCKER

### B1. `complete` defeats blind receiving and contradicts T9

Rev 2 explicitly permits a blind actor to invoke quantity-less `complete`, and the web retains a “Receive all” button (`docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-2.md:207,265-267,327`). Current code confirms that `complete` computes and receives every allocation or entire line without receiver-entered quantities (`apps/api/app/Modules/Inventory/Application/Services/StockTransferService.php:317-383`).

That is a functional bypass of “count without seeing expected quantity.” It also contradicts the proposed response contract:

- `complete` creates an actor-owned receipt for the computed remainder (`…design-rev-2.md:265-267`).
- The blind builder exposes the actor’s `my_receipts[]` quantities (`…design-rev-2.md:252`).
- T9 requires the `complete` 200 response to contain no numeric token equal to sent or remaining quantity (`…design-rev-2.md:361`).

Those three rules cannot all hold. `complete` must be unavailable when `canSeeExpected()` is false; retaining it as a delegate for non-blind/reconcile/source actors remains compatible with the legacy surface.

### B2. Blind closure omits two live leakage/runtime surfaces

First, the receiver builder exposes sender-authored transfer `notes` (`…design-rev-2.md:252`). Current transfer payloads store and emit that unrestricted text (`apps/api/app/Modules/Inventory/Presentation/Controllers/StockTransferController.php:181,293`). A note such as “12 sent” directly defeats the stated guarantee and T9’s value scan.

Second, surfaces 8–9 specify omitting `incoming_transfer`, but rev 2 specifies no corresponding `apps/pos` work (`…design-rev-2.md:211-212,269-271,321-339`). The live POS contracts require the field:

- Cross-location DTO: `apps/pos/src/types/stockDistribution.ts:6-29`.
- Device feed DTO: `apps/pos/src/lib/db/repositories/locationStockRepository.ts:55-61`.
- SQLite writer binds it unconditionally: `apps/pos/src/lib/db/repositories/locationStockRepository.ts:191-227`.
- UI arithmetic/renderers consume it unconditionally: `apps/pos/src/components/organisms/ProductGrid/ProductTable.tsx:115-123`, `apps/pos/src/components/organisms/CrossLocationStockSection/CrossLocationStockSection.tsx:104-117`.

Omitting the key without updating these contracts produces `undefined` arithmetic/database values rather than a safe masked projection. The POS types, persistence normalization, cached-value clearing, and renderers belong in the surface-closure contract.

### B3. Stored-event aggregate identity and replay remain incomplete

Rev 2 says the new events extend the repository’s `DomainEvent`, are dispatched with `event()` inside the receipt transaction, and persist `aggregate uuid = receiptId/receiptLineId` (`…design-rev-2.md:161-174`).

That is not what the installed Spatie path does. The wildcard subscriber calls `persist($event)` without a UUID (`apps/api/vendor/spatie/laravel-event-sourcing/src/StoredEvents/EventSubscriber.php:21-36`), and the repository writes `aggregate_uuid` from that method argument, not from `DomainEvent::aggregateRootUuid()` (`apps/api/vendor/spatie/laravel-event-sourcing/src/StoredEvents/Repositories/EloquentStoredEventRepository.php:101-123`). The custom base constructor’s UUID at `apps/api/app/Shared/Domain/Events/DomainEvent.php:27-40` is therefore not the persisted aggregate anchor on direct dispatch.

The replay payload is also incomplete:

- `stock_transfer_receipts.notes` exists in the proposed table (`…design-rev-2.md:111`), but `StockTransferReceivedV1` has no receipt note (`…design-rev-2.md:165-167`).
- `stock_transfer_receipt_line_lots.id` is part of the proposed table (`…design-rev-2.md:116`), but lot events carry no receipt-lot row ID (`…design-rev-2.md:169-170`).
- T15 nevertheless requires a byte-equal table rebuild (`…design-rev-2.md:367`).

Specify the real persistence mechanism/anchor and include every non-derived stored field, or explicitly define deterministic IDs and replay normalization. Until then B2 from round 1 is not closed.

## MAJOR

### M1. Mixed-concurrency expectations contradict the state machine

T7b says concurrent partial receive versus close yields exactly one winner; T7c says partial receive versus `complete` yields one terminal transition (`…design-rev-2.md:357-359`). The header lock only serializes requests (`apps/api/app/Modules/Inventory/Application/Services/StockTransferService.php:317-339,734-742`); it does not make the second request invalid.

Under the proposed states:

- If receive-5 wins first, status becomes `partially_received`.
- Both close and complete remain allowed from `partially_received` (`…design-rev-2.md:122,131-137,238-243`).
- Therefore both requests can succeed sequentially under the lock.

The matrix must assert the actual serialized outcome—possibly two successful documents with consistent counters—or introduce an explicit optimistic-version/precondition rule. The present tests cannot pass against the stated service rules.

### M2. The completed-row backfill violates I4 and replay/reconciliation

I4 requires each line counter to equal the sum of immutable receipt lines (`…design-rev-2.md:155`). The rollout backfills completed line/allocation `quantity_received = quantity` but creates no legacy receipt, receipt lines, or stored events (`…design-rev-2.md:377`).

Existing completion creates only movements and a completed transfer (`apps/api/app/Modules/Inventory/Application/Services/StockTransferService.php:346-420`); there is no historical receipt table. Every pre-feature completed transfer therefore becomes immediate “counter drift” under I4 and cannot be rebuilt from the new event stream. Define a legacy exemption/discriminator or an idempotent synthetic legacy-receipt strategy.

### M3. Periodic valuation is not refused for lot write-offs

Rev 2 claims every damaged receipt in a periodic-valuation company rolls back with `VALUATION_MODE_UNSUPPORTED` (`…design-rev-2.md:297,352`). This is true for the non-batch `Exit` path because `postMovement()` calls `requirePerpetual()` (`apps/api/app/Modules/Inventory/Application/Services/InventoryGlPostingService.php:130-159`).

It is false for the specified lot path. `postForBatchWriteOff()` checks accounts and amount but never checks valuation mode (`apps/api/app/Modules/Inventory/Application/Services/InventoryGlPostingService.php:89-127`). The cited resolver only throws when actually called (`apps/api/app/Modules/Inventory/Application/Services/InventoryValuationModeResolver.php:74-88`).

Require an explicit receipt-root perpetual-mode preflight before either destructive path, or specify the shared GL-service change and its regression impact.

### M4. Landed weights do not create `freight_uncapitalized`

Rev 2 says to replace shipped weights with landed weights and leave the written-off/returned share uncapitalized (`…design-rev-2.md:303-304,388`). The existing allocator divides the entire `transferCost` among whatever weights remain (`apps/api/app/Modules/Inventory/Application/Services/StockTransferService.php:635-667`) and makes the final allocations sum exactly to the whole cost (`:671-707`).

Changing only `computeAllocationWeights()` from shipped to landed quantities (`:714-728`) still allocates 100% of freight across landed lines. It cannot produce T4’s asserted `7/12` uncapitalized amount (`…design-rev-2.md:353`). The spec needs a separate capitalizable-cost pool and residual calculation for each distribution mode before OQ-4’s default is implementable.

### M5. `receiver-view` has no specified endpoint permission

Receive is explicitly gated by `inventory.transfers.complete`; reconciliation and close also name permissions. The new `GET /stock-transfers/{id}/receiver-view` never does (`…design-rev-2.md:204,245-255`).

Every current transfer route has an explicit action permission (`apps/api/app/Modules/Inventory/Presentation/routes.php:98-117`). Owner Q5 requires destination membership plus `inventory.transfers.complete` (`docs/handoff/BRIEF-parallel-transfers-blind-receiving-2026-09-08.md:50-54`). State that middleware and the destination membership/404 behavior explicitly.

### M6. The pattern query measures supervisors, not mis-receivers, and crosses companies

The companion event uses one generic `actorUserId` for receipts and closes (`…design-rev-2.md:169-170`). Close rows are authored by the supervisor/closer (`…design-rev-2.md:238-243`). The reference query then counts `quantityWrittenOff > 0` grouped by that actor (`…design-rev-2.md:181-190`), so it ranks supervisors who close shortages—not employees who mis-received.

The query also has no `companyId` or tenant predicate even though each event carries `companyId` (`…design-rev-2.md:170,181-188`). It therefore conflates companies in the same tenant. This does not satisfy the owner’s per-receipt pattern requirement (`docs/handoff/OWNER-QUESTIONS-consolidated-2026-09-06.md:103`; benchmark note `docs/superpowers/reviews/2026-09-09-benchmark-transfer-discrepancy-gl.md:15`).

### M7. “Half-migrated schema” self-healing is not specified

The guard recipe says tables use only `Schema::hasTable`, while columns use `Schema::hasColumn` (`…design-rev-2.md:99`). S3 promises rerunning against a half-migrated schema produces the complete same schema (`…design-rev-2.md:349`).

If a receipt table exists but is missing a column, FK, index, or CHECK, a table-level `hasTable` guard skips its entire creation block. Likewise, skipping an already-present FK column does not repair a missing FK constraint. The rollout repeats the unsupported guarantee (`…design-rev-2.md:375-377`). Specify per-column and per-constraint repair paths, or narrow the promise to transactional rerun after a rolled-back migration.

### M8. The status-list “exhaustiveness” construct is not exhaustive

Rev 2 proposes:

```ts
STATUS_OPTIONS satisfies readonly TransferStatus[]
```

and claims it lists every generated case (`…design-rev-2.md:328`). TypeScript only proves that listed elements belong to the union; it does not fail when a union member is omitted. The current manually enumerated site is `apps/web/src/features/stock-transfers/pages/StockTransferListPage.tsx:15-24`.

Use a `Record<TransferStatus, …>` source or a type-level missing-case assertion. The badge’s existing `Record` pattern at `apps/web/src/features/stock-transfers/components/StockTransferStatusBadge.tsx:5-10` is genuinely exhaustive.

### M9. The second-of-everything closure still omits `complete`

Rev 2 claims S1–S3 cover receive, both closes, `complete`, and the settings writer (`…design-rev-2.md:61`). In the actual table:

- S1 covers company B receive and both closes, but not the `complete` endpoint (`…design-rev-2.md:347`).
- S2 covers destination receipt/return and alert location behavior, but not `complete` at the second location (`…design-rev-2.md:348`).
- Only S3 mentions `complete` replay (`…design-rev-2.md:349`).

The legacy compatibility writer delegates, but it remains a distinct reachable mutation and needs the claimed company/location assertions.

### M10. POS visibility injection would violate the module boundary as written

`ExpectedQuantityVisibility` is placed in the Inventory application layer, while rev 2 says POS controllers pass its decision into the reader (`…design-rev-2.md:247-249,269-271`). Directly injecting that Inventory service into POS controllers violates rule 6 (`CLAUDE.md:30-31`).

The existing POS controllers correctly depend on Shared contracts (`apps/api/app/Modules/POS/Presentation/Controllers/StockDistributionController.php:7-10,29-33`; `PosStockLevelController.php:51-54`). Define a Shared visibility contract or keep the policy behind the existing Shared stock-reader boundary.

## MINOR

### m1. One `CompanyFraudSettings` typed surface is still missing

Rev 2 lists the `getDefaults()` array-shape PHPDoc but misses the separate `defaultsForVertical()` array-shape declaration at `apps/api/app/Modules/Compliance/Domain/CompanyFraudSettings.php:188-199`. Adding `blind_receiving` to the returned array without updating both shapes leaves the second contract stale.

### m2. T9’s numeric-token oracle is overbroad

T9 rejects any numeric token equal to sent/remainder (`…design-rev-2.md:361`), while safe receiver fields include transfer/receipt numbers, batch numbers and dates (`…design-rev-2.md:252-253`). A receipt suffix or batch number can coincidentally equal the test quantity. Assert forbidden semantic fields and deliberately seeded note/error leakage instead of arbitrary equality across identifiers.

## Citation audit

| Claim/citation group | Result and real line |
|---|---|
| Baseline “HEAD `ffd907b3d`” (`…design-rev-2.md:3`) | **WRONG/stale label.** Current HEAD is `f5fba63fb`; `ffd907b3d` is its parent. `git diff --name-only ffd907b3d..HEAD` contains only the rev-2 spec, so production code itself did not drift. |
| Glossary headings and counting service (`:33`) | **VERIFIED** at `docs/glossary.md:13,22,32,48,67,80` and `apps/api/app/Modules/Inventory/Application/Services/CountingDiscrepancyReportService.php:21`. |
| Transfer initiation/completion (`:43,67`) | **VERIFIED** at `apps/api/app/Modules/Inventory/Application/Services/StockTransferService.php:86,317-383,514,602`. |
| PO partial/over-receipt (`:44-45`) | **VERIFIED** at `GoodsReceiptService.php:326-345,1231-1234` and `GoodsReceiptFailureReason.php:9`. |
| “No discrepancy columns” cited only to transfer migration `:86` (`:45`) | **INCOMPLETE citation.** Line 86 shows only `quantity`; absence is established by the full table definition at `apps/api/database/migrations/tenant/2026_05_28_120000_create_stock_transfers_table.php:74-94`. |
| Mobile/counting/blind-cash precedents (`:46`) | **VERIFIED** at sibling `erp-mobile/src/features/receiving/types.ts:58-65,98-109`, `InventoryCountingController.php:334-377`, `CountingItemController.php:54-74,161-177`, and `CompanyFraudSettings.php:58`. |
| Notification routes/panel (`:47`) | **VERIFIED** at `Notification/Presentation/routes.php:19-30` and `NotificationPanel.tsx:20-26,45-71`. |
| Location authorization (`:48`) | **VERIFIED** at `StockTransferController.php:215-222,347-357` and `LocationContext.php:194-206,224-238`. |
| Cancellation semantics (`:49,56`) | **VERIFIED** at `TransferStatus.php:37-40` and `StockTransferService.php:429-488`. |
| Plain versus stored event precedents (`:50,57`) | **VERIFIED** at `StockTransferInitiated.php:9-11`, `GoodsReceived.php:18-42`, `DomainEvent.php:16`, and `StockMovementRecordedV2.php:22-37`. |
| Initiation unique-race recovery (`:51`) | **VERIFIED** at `StockTransferService.php:170-190`. |
| CHECK/index guard precedent (`:52`) | **WRONG.** `2026_08_08_120000_create_stock_adjustments_tables.php:130-139` contains raw `CREATE UNIQUE INDEX` statements without `IF NOT EXISTS`; it is not a guard precedent. |
| Company numbering/destination (`:53-54`) | Underlying claims **VERIFIED** at `2026_05_28_120000_create_stock_transfers_table.php:45,66` and `2026_07_04_100000_create_goods_receipts_tables.php:27-30`; cited ellipsis paths are not literal openable paths. |
| Adjustment correction description (`:55`) | **WRONG wording.** `StockAdjustmentDocumentService.php:520-531` refuses destructive reasons for every batch-tracked product, not merely “lot-less lines.” |
| Intercompany/account provisioning (`:78`) | **VERIFIED** at `StockTransferService.php:96-101`, `InventoryVarianceAccountProvisioner.php:162-169`, and `SystemAccountPurpose.php:65-68`. |
| Existing transfer line/allocation schema and settings surfaces (`:101-107`) | **VERIFIED** at `StockTransferLine.php:56-62`, batch-allocation migration `:14-30`, `CompanyFraudSettings.php:25-64,108-185`, `CompanyFraudSettingsData.php:31-110`, and `FraudSettingsController.php:108-123,194-203`. The omitted second PHPDoc is at `CompanyFraudSettings.php:188-199`. |
| Receipt partial-unique precedents (`:114`) | **VERIFIED as uniqueness precedents** at goods-receipt migration `:61-70` and stock-adjustment migration `:123-139`; they are not rerun-guard precedents. |
| Numbering service (`:118`) | **VERIFIED** at `DocumentNumberingService.php:24-39` and `GoodsReceiptService.php:272-277`. |
| Transfer enums/status storage/generated type (`:122-126`) | **VERIFIED** at `TransferStatus.php:15-49`, transfer migration `:41-42`, `generated.d.ts:1240`, `MovementReason.php:73-111`, `StockMovementReferenceType.php:72`, and `StockTransferService.php:745-752`. |
| State ordering and three remainder readers (`:140-148,152-153`) | **VERIFIED** at `StockTransferService.php:386-394`, `LocationStockQueryService.php:137-164,217-245`, `StockMatrixQueryService.php:379-408`, and `WeightedAverageCostService.php:100-127`. |
| Stored-event synchronous persistence (`:163-175`) | **PARTLY VERIFIED.** Synchronous persistence is real at Spatie `EventSubscriber.php:21-37`; movement dispatch is after commit at `StockAdjustmentService.php:181-207,325-350`. The claimed persisted aggregate UUID is **wrong**, as explained in B3; repository reality is `EloquentStoredEventRepository.php:101-123`. |
| Existing event/listener wiring (`:176-178`) | **VERIFIED** at `StockTransferCompleted.php:9-26`, `EventServiceProvider.php:145-148`, `InventoryServiceProvider.php:89-96`, `SettleRequestsOnTransferInitiated.php:22-44`, and `ReplenishmentServiceProvider.php:18-20`. |
| Stored-events JSONB/index (`:181`) | **VERIFIED** at `2025_11_30_102448_create_stored_events_table.php:11-23`; the ellipsized filename in the spec is non-resolving. |
| Inventory middleware/envelopes (`:196`) | **VERIFIED** at `Inventory/Presentation/routes.php:31,98-117` and `StockTransferController.php:359-402`. |
| Current transfer/list/show/complete shapes (`:205-209`) | **VERIFIED** at `StockTransferController.php:231-235,282-341,389-402`. |
| Matrix/POS/movement/entry-note surfaces (`:210-215`) | **VERIFIED** at `Inventory/Presentation/routes.php:70-84`, `StockMatrixController.php:25-48`, `StockMatrixQueryService.php:390-408`, POS routes `:141-148`, `StockDistributionController.php:35-99`, `PosStockLevelController.php:56-109`, `StockMovementController.php:54-80,208-232`, and `EntryExitNoteController.php:26-72,241-253`. |
| Generated/local/mobile transfer types (`:217-218`) | **VERIFIED** at web stock-transfer types `:1-43` and sibling mobile receiving types `:58-65,98-109`. |
| Lock/race/canonical JSON precedents (`:230-232`) | **VERIFIED** at `StockTransferService.php:170-190,734-742` and `TerminalRegistrySnapshotService.php:540-558`. |
| Visibility/location/reconciliation/settings (`:248-263`) | **VERIFIED** at `LocationContext.php:194-206,224-238`, `CountingDiscrepancyReportService.php:43-56`, `QuantityScale.php:64-75`, `FraudSettingsController.php:108-123`, and Compliance routes `:23-33`. |
| Permissions and generated map (`:275`) | **VERIFIED** at `RolesAndPermissionsSeeder.php:196-200,582-602,702,741,771,806`, exporter `:12-17`, and `scripts/preflight.sh:136-149`. |
| Movement reasons/service signatures (`:281-290`) | **VERIFIED** at `StockAdjustmentService.php:115-129,253-267`, `MovementReason.php:73-111`, and `Product.php:315-325`. |
| GL buffer “throws otherwise” (`:294`) | **WRONG.** At transaction level `>1`, it registers a leak alarm and returns `[]`; it does not throw (`InventoryGlPostingBuffer.php:56-68`). It throws only outside a transaction. |
| Exit/batch GL contexts (`:295-298`) | **PARTLY VERIFIED.** `Exit` and required batch fields are at `InventoryGlPostingService.php:26-28,89-127,130-207`; POS precedent is `ReturnScrapWriteOffService.php:135-189`. The periodic-valuation claim is wrong for `BatchWriteOff`, as explained in M3. |
| Freight/WAC behavior (`:304,388`) | **VERIFIED as current-code description** at `StockTransferService.php:631-728` and `WeightedAverageCostService.php:745-831`; the proposed replacement algorithm remains underspecified. |
| Notification queue/id/deep-link claims (`:312-317`) | **VERIFIED** at `UserInvitation.php:8-16`, `config/tenancy.php:38-43`, `TreasuryAlertNotification.php:22-40`, `config/horizon.php:201-217`, Laravel `NotificationSender.php:150-153,220-228`, `DatabaseChannel.php:31-38`, notification migration `:13-20`, and `NotificationPanel.tsx:20-26,45-98,164-169`. |
| Web routes/API/status/i18n/settings (`:323-330`) | **VERIFIED** at local transfer types `:1-46`, web routes `:1083-1100,1401-1429`, `stockTransferApi.ts:37-50`, queries `:11-20,43-51`, status list `:15-24`, badge `:5-10`, i18n `:52,108,218,275,474-477`, and cash controls `:8-15,47-54`. |
| Mobile replay and feature-lane citations (`:337,343`) | **VERIFIED** at sibling `pendingCountSyncService.ts:58-68` and `apps/api/tests/feature-lane-manifest.json:161-168`. |
| GL boundary guard (`:365`) | **VERIFIED** at `InventoryGlPostingBoundaryGuard.php:16-39` and `InventoryServiceProvider.php:119-133`. |
| Ellipsized filenames throughout (`2026_05_28_120000_…`, `…create_goods_receipts_tables.php`, `…create_stored_events_table.php`) | **WRONG citation form.** Replace with literal repository paths named above; the underlying lines were separately verified. |
| All other explicit current-code line claims | **VERIFIED** against the unchanged production tree between `ffd907b3d` and current HEAD. |

## Rejected false positives

- No fourth production reader computes in-transit remainder. The three are `LocationStockQueryService.php:137-164,217-245`, `StockMatrixQueryService.php:390-408`, and `WeightedAverageCostService.php:117-127`. `TransferLineQueryService.php:13-25` intentionally reads shipped quantities for initiation-time replenishment settlement.
- The completed-only counter backfill preserves historical answers for all three readers: completed transfers were already status-excluded; in-transit/cancelled rows remain zero. The defect is I4/replay consistency, not reader totals.
- `partially_received`, `closed_with_writeoff`, and `closed_returned` fit the existing `string(20)` status column (`database/migrations/tenant/2026_05_28_120000_create_stock_transfers_table.php:41-42`); no PostgreSQL enum ALTER is needed.
- `Damage` and `WriteOff` exist, require GL, and belong to Shrinkage (`MovementReason.php:26-28,73-111`).
- Land-then-issue stock arithmetic is valid; both methods accept batch, reason, cost and source-reference context (`StockAdjustmentService.php:115-129,253-267`). Neither method changes product WAC.
- The non-batch `MovementGlKind::Exit` fix is valid (`InventoryGlPostingService.php:26-28,130-207`).
- Return-to-source with stock movement only and no P&L is correct and owner-ruled (`benchmark-transfer-discrepancy-gl.md:7-13`).
- Existing transfer events remain untouched, satisfying rule 8 (`StockTransferInitiated.php:9-23`, `StockTransferCompleted.php:9-26`; `CLAUDE.md:36-37`).
- Header-first and sorted product advisory locks match current completion ordering (`StockTransferService.php:317-339,734-742`).
- Keeping `complete` does not violate one-surface-per-concept if it delegates entirely to the receipt writer; the problem is allowing blind actors to use it, not its existence (`docs/conventions/11-ONE-SURFACE-PER-CONCEPT.md:37-39`).
- D1 is necessary and now owner-settled: broad `inventory.view` grants appear at `RolesAndPermissionsSeeder.php:702,741,771,806`, while reconcile is seeded to manager/admin.
- The backend transfer group already carries the rule-12 middleware and `module:Inventory` (`Inventory/Presentation/routes.php:31`).
- Coarse `partially_received` visibility is not a finding; the owner accepted it (`…OWNER-QUESTIONS…md:102`).

## Preserve

- Preserve Odoo-style open remainder/backorder behavior and hard over-receipt refusal (`BRIEF-parallel-transfers-blind-receiving-2026-09-08.md:50-51`).
- Preserve the distinction between an unreasoned open remainder and a reasoned damaged/closed shortage (`…design-rev-2.md:69-75`).
- Preserve company-level opt-in blind receiving, transfers first and PO receipts later (`…BRIEF…md:52`).
- Preserve DB notifications/web bell first, with no push in this lane (`…BRIEF…md:53`).
- Preserve destination membership and no receiver assignment (`…BRIEF…md:54`).
- Preserve owner-ruled `return_to_source` on close with no GL (`…OWNER-QUESTIONS…md:100`; benchmark `:7-13`).
- Preserve quantity-based remainder in exactly the three readers, completed backfill for reader parity, cancelled exclusion, scale-4 storage, bcmath, decimal strings and no floats (`…design-rev-2.md:142-157,306`).
- Preserve immutable existing events and versioned new stored receipt events (`CLAUDE.md:36-37`).
- Preserve separate full and receiver builders with omission by construction (`…design-rev-2.md:245-255`).
- Preserve dedicated `inventory.transfers.reconcile`, seeded to manager plus admin and grantable (`…OWNER-QUESTIONS…md:98`).
- Preserve one receipt writer, with `complete` delegating only for actors allowed to see expected quantities.
- Preserve refusal to cancel after any partial receipt (`…design-rev-2.md:131-138`).
- Preserve stored per-line receipt/close facts for pattern detection (`…OWNER-QUESTIONS…md:103`).

## Owner decisions required

1. **OQ-4 remains genuinely open:** decide the treatment of freight attributable to written-off/returned units (`…design-rev-2.md:386-390`). Before implementation, every option needs an exact capitalizable-pool/residual algorithm; merely changing weights does not implement the default.

2. **Pattern attribution for multi-receiver transfers:** when receiver A and receiver B post partial receipts and a supervisor later closes the remainder, decide whether the shortage is analyzed at transfer/source level, attributed only to the closer, or associated with some explicitly defined receipt actor. With no receiver assignment, attributing it to the last receiver would be an unsupported assumption (`…BRIEF…md:54`; event/query at `…design-rev-2.md:169-190`).

OQ-1, OQ-2, OQ-3, D1 and D2 are ruled and must not be reopened.

VERDICT: CHANGES-REQUIRED
