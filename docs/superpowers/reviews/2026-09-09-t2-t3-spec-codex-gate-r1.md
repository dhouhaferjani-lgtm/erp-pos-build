# Adversarial spec gate — Round 1

Reviewed only the designated spec, required context, and current repository code. Current checkout is `7311f3c`; the spec cites the older `cdedc2830` baseline at `docs/superpowers/specs/2026-09-08-transfer-destination-receipt-and-blind-receiving-design.md:3`.

## BLOCKER

### B1. Blind receiving is not closed over every reachable response surface

The proposed builders protect the intended transfer list/show/receiver-view paths, but exact sent or remaining quantities remain inferable or directly visible through other endpoints already available to likely receiver roles:

| Surface reachable by receiver | Result |
|---|---|
| Receiver-view | Safe by construction if implemented exactly: the array omits quantities and allocation quantities (`docs/superpowers/specs/2026-09-08-transfer-destination-receipt-and-blind-receiving-design.md:168-173`). This matches the backend counting pattern at `apps/api/app/Modules/Inventory/Presentation/Controllers/InventoryCountingController.php:335-377` and `apps/api/app/Modules/Inventory/Presentation/Controllers/CountingItemController.php:54-74`. |
| Transfer list/show | Safe only if every response goes through the proposed visibility gate (`docs/superpowers/specs/2026-09-08-transfer-destination-receipt-and-blind-receiving-design.md:170-172`). Current show returns exact line and batch-allocation quantities at `apps/api/app/Modules/Inventory/Presentation/Controllers/StockTransferController.php:311-335`. |
| Receive 201 | Not proven safe. Only the nested `transfer` is said to be visibility-gated; `StockTransferReceiptData` is undefined and could nest full transfer-line or lot DTOs (`docs/superpowers/specs/2026-09-08-transfer-destination-receipt-and-blind-receiving-design.md:156`). Define the blind-safe receipt projection explicitly. |
| Complete 200 | Direct contradiction. The spec says the response remains unchanged (`docs/superpowers/specs/2026-09-08-transfer-destination-receipt-and-blind-receiving-design.md:183-185`), but today that response uses the full formatter (`apps/api/app/Modules/Inventory/Presentation/Controllers/StockTransferController.php:231-235`), which emits line and batch quantities (`apps/api/app/Modules/Inventory/Presentation/Controllers/StockTransferController.php:311-335`). T9 does not test this response (`docs/superpowers/specs/2026-09-08-transfer-destination-receipt-and-blind-receiving-design.md:256`). |
| Typed 422s | Numeric detail keys are gated, but messages are not. The existing envelope passes exception messages verbatim (`apps/api/app/Modules/Inventory/Presentation/Controllers/StockTransferController.php:389-400`). T9 scans keys only, so a message such as “remaining quantity is 7” would pass (`docs/superpowers/specs/2026-09-08-transfer-destination-receipt-and-blind-receiving-design.md:157,256`). |
| Stock matrix | Exact destination/product incoming remainder remains available through `include=incoming` (`apps/api/app/Modules/Inventory/Presentation/Controllers/StockMatrixController.php:30-48`) and is emitted from the transfer aggregate (`apps/api/app/Modules/Inventory/Application/Services/StockMatrixQueryService.php:395-408`). The endpoint requires only `inventory.view` (`apps/api/app/Modules/Inventory/Presentation/routes.php:70-75`). A single matching transfer makes the remainder exact. |
| POS cross-location stock | Emits exact `incoming_transfer` (`apps/api/app/Modules/POS/Presentation/Controllers/StockDistributionController.php:80-96`) from the same transfer reader (`apps/api/app/Modules/Inventory/Application/Services/LocationStockQueryService.php:137-164`). |
| POS device stock feed | Emits exact incoming transfer per product/location (`apps/api/app/Modules/POS/Presentation/Controllers/PosStockLevelController.php:94-109`), sourced at `apps/api/app/Modules/Inventory/Application/Services/LocationStockQueryService.php:217-245`. |
| Movement history | Destination movements expose `quantity`, before/after balances, reference and type (`apps/api/app/Modules/Inventory/Presentation/Controllers/StockMovementController.php:208-228`). A write-off close creates a TransferIn equal to the remainder, revealing it to an `inventory.view` holder with destination access. The route requires only `inventory.view` (`apps/api/app/Modules/Inventory/Presentation/routes.php:77-80`). |
| Entry/exit notes | The company-wide query exposes transfer-linked movement groups without applying `LocationScopeResolver` (`apps/api/app/Modules/Inventory/Presentation/Controllers/EntryExitNoteController.php:44-72`); its route also requires only `inventory.view` (`apps/api/app/Modules/Inventory/Presentation/routes.php:82-84`). |
| Reconciliation | Intentionally reveals quantities, but the proposed route states only the new permission and never requires `canSeeTransfer()` or destination membership (`docs/superpowers/specs/2026-09-08-transfer-destination-receipt-and-blind-receiving-design.md:175-177`). Current transfer show explicitly applies `canSeeTransfer()` and converts invisibility to 404 (`apps/api/app/Modules/Inventory/Presentation/Controllers/StockTransferController.php:103-114,343-357`). |
| Notifications | No exact quantities are proposed; only counts and discrepancy state are exposed (`docs/superpowers/specs/2026-09-08-transfer-destination-receipt-and-blind-receiving-design.md:213-217`). This is quantitatively safe, subject to the owner’s coarse-signal ruling below. |
| Generated web types | Separate generated receiver/full DTOs are compatible with safe construction, but the 201 receipt type remains unspecified (`docs/superpowers/specs/2026-09-08-transfer-destination-receipt-and-blind-receiving-design.md:156,224`). |
| Mobile types | Cannot be verified: this checkout contains no `erp-mobile/` tree or `pendingCountSyncService.ts`; the cited web `countingApi.ts` ends at line 150 and contains no NEVER-INCLUDE contract (`apps/web/src/features/inventory-counting/api/countingApi.ts:132-150`). |

The stock-matrix, POS and movement surfaces must either use a blind-safe projection/authorization rule or be explicitly shown not to reveal transfer-derived expected quantities. This is required to satisfy the spec’s own “never receives expected/remaining quantities” definition (`docs/superpowers/specs/2026-09-08-transfer-destination-receipt-and-blind-receiving-design.md:52-53`).

### B2. The stored events are insufficient to replay T-2

`StockTransferReceivedV1` and `StockTransferClosedWithWriteoffV1` contain only aggregate totals (`docs/superpowers/specs/2026-09-08-transfer-destination-receipt-and-blind-receiving-design.md:129-133`). They omit:

- transfer-line IDs;
- product/variant IDs;
- per-line received, damaged and written-off quantities;
- batch-allocation/batch IDs and lot quantities;
- movement IDs;
- previous and resulting transfer states.

The proposed fallback is `StockMovementRecordedV2`, but that event has no line ID or batch ID (`apps/api/app/Modules/Inventory/Domain/Events/StockMovementRecordedV2.php:20-40`). TransferIn movements deliberately continue to reference only the transfer, not the receipt (`docs/superpowers/specs/2026-09-08-transfer-destination-receipt-and-blind-receiving-design.md:93`). Multiple partial receipts therefore cannot be reconstructed or associated with their receipt from the stored event stream.

There is also an atomicity gap: `StockAdjustmentService::receive()` and `issue()` dispatch their stored movement events only in `DB::afterCommit` callbacks (`apps/api/app/Modules/Inventory/Domain/Services/StockAdjustmentService.php:181-224,325-367`). The receipt and its aggregate event can commit while a movement event fails afterward. That disproves the claim that movement events supply the replay facts.

`DomainEvent` explicitly describes events as containing the information needed to understand what happened (`apps/api/app/Shared/Domain/Events/DomainEvent.php:9-16`). These event contracts do not meet that bar. Receipt IDs are valid listener idempotency anchors, but not a substitute for replay data.

### B3. Idempotent replays fail on terminal transitions and concurrent unique-key races

The declared order is state validation before idempotency lookup (`docs/superpowers/specs/2026-09-08-transfer-destination-receipt-and-blind-receiving-design.md:155`). Consequently:

- replaying a receipt that completed the transfer fails `canReceive()` before reaching the stored receipt;
- replaying `close` fails after the first request made the transfer terminal;
- the deterministic `complete:{transferId}` replay asserted by S3 cannot work (`docs/superpowers/specs/2026-09-08-transfer-destination-receipt-and-blind-receiving-design.md:185,247`).

The transfer lock serializes two receipts against the same transfer (`apps/api/app/Modules/Inventory/Application/Services/StockTransferService.php:317-339,734-742`), but it does not serialize the company-global idempotency key across different transfer rows. Two requests using the same key on different transfers can pass their independent lookups and collide at the unique constraint. Existing transfer initiation explicitly catches the unique violation and re-reads the committed winner after rollback (`apps/api/app/Modules/Inventory/Application/Services/StockTransferService.php:170-190`); the new receipt flow specifies no equivalent recovery.

Finally, “canonical JSON body” is not defined for line/lot order, absent versus null fields, or numerically equivalent strings such as `1`, `1.0`, and `1.0000` (`docs/superpowers/specs/2026-09-08-transfer-destination-receipt-and-blind-receiving-design.md:76-78,123`). The hash contract is not deterministic enough to support typed equal-versus-different replay.

## MAJOR

### M1. State invariants are incorrect

I2 says company-owned quantity decreases at source initiation and “never again” (`docs/superpowers/specs/2026-09-08-transfer-destination-receipt-and-blind-receiving-design.md:119`). The implemented metric is on-hand plus in-transit (`apps/api/app/Modules/Inventory/Application/Services/WeightedAverageCostService.php:100-127`), so initiation leaves company-owned quantity unchanged. Damage/write-off then decreases it once. T4’s expected decrease by seven is correct (`docs/superpowers/specs/2026-09-08-transfer-destination-receipt-and-blind-receiving-design.md:251`); I2 is not.

I5 says a transfer is terminal iff remainder is zero (`docs/superpowers/specs/2026-09-08-transfer-destination-receipt-and-blind-receiving-design.md:122`). Cancelled is terminal (`apps/api/app/Modules/Inventory/Domain/Enums/TransferStatus.php:22-24`), but cancelling an in-transit transfer restocks the source without updating receipt counters (`apps/api/app/Modules/Inventory/Application/Services/StockTransferService.php:441-488`). Its calculated remainder therefore remains equal to the sent quantity. I5 must exclude cancellation or define a separate carrying-state invariant.

### M2. Partial remainder and “short discrepancy” are conflated

The confirmed default requires short and damaged discrepancies to carry a reason and alert (`docs/handoff/BRIEF-parallel-transfers-blind-receiving-2026-09-08.md:50-54`). The spec instead:

- treats an omitted line as an open remainder;
- requires a reason only for damaged quantity;
- computes shortness server-side;
- says a supervisor supplies the reason only when closing;
- proposes notifying on any short receipt (`docs/superpowers/specs/2026-09-08-transfer-destination-receipt-and-blind-receiving-design.md:154,164,216`).

Under Odoo backorder semantics, receiving 5 of 12 can simply mean seven remain on the truck; it is not yet a confirmed shortage. The current text would either alert without the owner-required reason or incorrectly label every partial receipt as discrepant. Define separately:

- open backorder/remainder;
- confirmed short/write-off at close, with mandatory reason and alert.

No new disposition or scope is needed.

### M3. The GL bridge is incomplete for non-batch transfer lines

`Damage` and `WriteOff` exist, require GL, and map to Shrinkage (`apps/api/app/Modules/Inventory/Domain/Enums/MovementReason.php:26-28,73-111`). The buffer precedent is valid.

However, the spec mandates `MovementGlKind::BatchWriteOff` for every destructive transfer movement (`docs/superpowers/specs/2026-09-08-transfer-destination-receipt-and-blind-receiving-design.md:193-203`). That posting kind throws unless both `batchNumber` and `productId` exist (`apps/api/app/Modules/Inventory/Application/Services/InventoryGlPostingService.php:89-97`). Non-batch transfer lines have no batch.

The cited POS precedent works only because it supplies a synthetic label, `"POS-SCRAP {receipt}"` (`apps/api/app/Modules/POS/Application/Services/ReturnScrapWriteOffService.php:168-189`). The spec must name the exact non-batch context/kind and required fields.

### M4. The WAC write-off cost policy is not actually guaranteed by current code

The spec says the freight share of a written-off line remains capitalized (`docs/superpowers/specs/2026-09-08-transfer-destination-receipt-and-blind-receiving-design.md:205`). But terminal status is persisted before capitalization (`apps/api/app/Modules/Inventory/Application/Services/StockTransferService.php:386-402`), and WAC capitalization no-ops when company-owned quantity is zero (`apps/api/app/Modules/Inventory/Application/Services/WeightedAverageCostService.php:763-775`).

If all owned units of a product are written off, that line’s allocated cost is saved and passed to `recordCostAdjustment` (`apps/api/app/Modules/Inventory/Application/Services/StockTransferService.php:683-707`) but cannot be capitalized. The asserted financial outcome is therefore false for total loss and needs an owner ruling.

### M5. Authorization and module gating are incomplete

- Reconciliation needs transfer visibility/location enforcement in addition to the permission (`docs/superpowers/specs/2026-09-08-transfer-destination-receipt-and-blind-receiving-design.md:175-177`; existing enforcement at `apps/api/app/Modules/Inventory/Presentation/Controllers/StockTransferController.php:103-114,343-357`).
- Backend transfer routes inherit `module:Inventory` (`apps/api/app/Modules/Inventory/Presentation/routes.php:31`), while web transfer routes are permission-only (`apps/web/src/routes/index.tsx:1401-1429`). This violates the two-layer gating rule in `CLAUDE.md:48-49`.
- D1 is necessary: `inventory.view` is broadly granted (`apps/api/database/seeders/RolesAndPermissionsSeeder.php:702,741,771,806`). Keep the dedicated reconcile permission.
- The proposed role grant gives both close and reconcile to the existing manager bundle (`docs/superpowers/specs/2026-09-08-transfer-destination-receipt-and-blind-receiving-design.md:187`). Managers are currently the seeded non-admin role with transfer completion (`apps/api/database/seeders/RolesAndPermissionsSeeder.php:600-602`). Granting reconcile there would mean every standard seeded receiver bypasses blind mode. The intended supervisor grant requires an owner decision.
- Regenerating the permission map must be stated; permissions are generated artifacts under rule 7, and preflight guards them (`CLAUDE.md:33-34,42-43`).

### M6. Receipt and setting schemas omit required integrity surfaces

- The document says “two new tables,” but defines three (`docs/superpowers/specs/2026-09-08-transfer-destination-receipt-and-blind-receiving-design.md:26,74-82`).
- Nullable `in_movement_id` and `scrap_movement_id` receive no partial unique indexes (`docs/superpowers/specs/2026-09-08-transfer-destination-receipt-and-blind-receiving-design.md:80-82`). Existing receipt and adjustment schemas enforce one document line per movement with partial unique indexes (`apps/api/database/migrations/tenant/2026_07_04_100000_create_goods_receipts_tables.php:61-70`, `apps/api/database/migrations/tenant/2026_08_08_120000_create_stock_adjustments_tables.php:123-139`).
- “Self-guarding” covers only columns/tables. Raw CHECK and index creation also need explicit catalog or `IF NOT EXISTS` guards for a rerun after a partially completed staging migration (`docs/superpowers/specs/2026-09-08-transfer-destination-receipt-and-blind-receiving-design.md:60,269`).
- Adding `blind_receiving` requires updating model defaults, fillable fields, casts, defaults return shape and PHPDoc, not just the migration/controller/DTO. Those current surfaces are at `apps/api/app/Modules/Compliance/Domain/CompanyFraudSettings.php:52-64,108-149,162-185`; reset rebuilds from `getDefaults()` at `apps/api/app/Modules/Compliance/Presentation/Controllers/FraudSettingsController.php:194-203`.

### M7. The proposed reader ratchet fails on legitimate writer code

The guard scans every file under `Modules/Inventory/Application/Services` for `TransferStatus::InTransit` (`docs/superpowers/specs/2026-09-08-transfer-destination-receipt-and-blind-receiving-design.md:114,259`). Legitimate transfer-state code in that directory compares the previous status during cancellation (`apps/api/app/Modules/Inventory/Application/Services/StockTransferService.php:438-450`). The test would fail immediately even after all three readers were corrected. Restrict it to the reader classes or to status-filtered stock-transfer query expressions.

### M8. The state-machine UI misses two exhaustiveness sites

The spec covers the enum and badge but not:

- list filter options, which enumerate only the four current states (`apps/web/src/features/stock-transfers/pages/StockTransferListPage.tsx:15-24`);
- `canComplete`, which allows the action only in `in_transit`, excluding `partially_received` (`apps/web/src/features/stock-transfers/pages/StockTransferDetailPage.tsx:38-39`).

The badge’s `Record<StockTransferStatus, BadgeVariant>` will correctly force compile-time additions (`apps/web/src/features/stock-transfers/components/StockTransferStatusBadge.tsx:5-10`) and is already covered by the spec.

### M9. Notification idempotency and navigation are underspecified

The listener’s existence-check against JSON data is a race-prone check-then-send without a unique database constraint (`docs/superpowers/specs/2026-09-08-transfer-destination-receipt-and-blind-receiving-design.md:136`). Concurrent/replayed listeners can both observe no row and insert duplicates. Use a durable deterministic notification identity or unique dedupe marker.

The proposed notification payloads omit `deep_link` (`docs/superpowers/specs/2026-09-08-transfer-destination-receipt-and-blind-receiving-design.md:215-217`), but the bell navigates only when that field is present (`apps/web/src/features/notifications/components/NotificationPanel.tsx:85-98`). The three new types must also be added to `KNOWN_TYPES`, otherwise their raw internal names are rendered (`apps/web/src/features/notifications/components/NotificationPanel.tsx:20-26,164-169`).

The scalar notification payload and request-side recipient resolution are otherwise compatible with no-`CompanyContext` workers, but T10 lacks the required context-cleared worker test (`CLAUDE.md:81-87`; proposed tests at `docs/superpowers/specs/2026-09-08-transfer-destination-receipt-and-blind-receiving-design.md:257`).

### M10. The test matrix does not cover every writer or mixed concurrency

S1–S3 exercise receive and `complete`, but not:

- close under second-company/second-location/replay/different-payload conditions;
- the company fraud-setting writer and reset path;
- concurrent receive versus close;
- concurrent receive versus complete.

The convention requires second-company, second-location and rerun assertions on data meaning (`docs/conventions/09-SECOND-OF-EVERYTHING.md:37-50`), with omissions classified MAJOR (`docs/conventions/09-SECOND-OF-EVERYTHING.md:79-83`). The current matrix is at `docs/superpowers/specs/2026-09-08-transfer-destination-receipt-and-blind-receiving-design.md:245-261`.

### M11. Benchmark-first coverage is structurally incomplete

The benchmark table has eight rows and decisions, satisfying the count requirement. It does not include applicable baseline rows for duplicate/idempotent posting, rerun, second company, second location or correction/reversal. Convention 10 explicitly lists these guarantees where applicable (`docs/conventions/10-BENCHMARK-FIRST-SPECS.md:59-66`). Tests elsewhere do not replace the missing benchmark decisions.

## MINOR

### m1. Precision ingress contradicts the repository contract

The request is described as `numeric` plus regex but “strings only” (`docs/superpowers/specs/2026-09-08-transfer-destination-receipt-and-blind-receiving-design.md:147`). Backend `numeric` deliberately accepts both JSON numbers and numeric strings (`docs/architecture/precision-contract.md:30-40`). Frontend payloads should send strings, but the backend compatibility contract should not claim strings-only.

### m2. Blind cache identity is incomplete

Transfer query keys vary only by tenant/company (`apps/web/src/features/stock-transfers/api/queries.ts:9-20`; `apps/web/src/lib/tenantScopedKey.ts:29-34`), while payload shape now also depends on user permission, location membership and a mutable fraud setting. A live permission/settings change can retain a previously cached full payload. State how transfer queries are invalidated when `blind_receiving` or role/location membership changes, or include an appropriate visibility discriminator.

### m3. Arabic is an English fallback, not an Arabic translation

The spec requires new keys in en/fr/ar (`docs/superpowers/specs/2026-09-08-transfer-destination-receipt-and-blind-receiving-design.md:227`), but stock transfers currently use the English bundle for Arabic (`apps/web/src/lib/i18n.ts:474-477`). Either add an actual Arabic bundle or say the existing English fallback is intentionally preserved.

### m4. The receipt-correction statement overclaims existing capability

The spec says an erroneous receipt can be corrected through a stock-adjustment document (`docs/superpowers/specs/2026-09-08-transfer-destination-receipt-and-blind-receiving-design.md:39`). A stock adjustment can correct on-hand stock, but it cannot correct immutable receipt totals, transfer line counters, reconciliation or terminal state. Keep reversal out of scope, but remove the claim that stock adjustment fully corrects the receipt document.

## Citation audit

I reopened every referenced repository path. This is the complete wrong/stale citation set; unlisted citations resolved to the claimed construct.

| Spec citation | Result |
|---|---|
| HEAD `cdedc2830` (`docs/superpowers/specs/2026-09-08-transfer-destination-receipt-and-blind-receiving-design.md:3`) | Stale; reviewed checkout is `7311f3c`. |
| `erp-mobile/src/features/receiving/types.ts:62-63,106-108` and `pendingCountSyncService.ts` (`:18,235-236`) | Unverifiable/nonexistent in this repository. |
| `countingApi.ts:171-197` and `:171-173,190-196` (`:224,236`) | Wrong: `apps/web/src/features/inventory-counting/api/countingApi.ts` ends at line 150 and has no NEVER-INCLUDE contract. The real precedent is backend array construction at `InventoryCountingController.php:335-377` and `CountingItemController.php:54-74,161-178`. |
| `routes.php:99-117` as the group middleware source (`:141`) | Wrong: those lines contain individual transfer routes. The middleware, including `EnforceTokenTenantClaim` and `module:Inventory`, is at `apps/api/app/Modules/Inventory/Presentation/routes.php:31`. |
| `RolesAndPermissionsSeeder.php:681,720,750,785` for broad `inventory.view` grants (`:177`) | Stale: current grants are at `apps/api/database/seeders/RolesAndPermissionsSeeder.php:702,741,771,806`. The underlying claim remains true. |
| Seeder `:192-195` and `:579-581` for transfer permission placement (`:187`) | Wrong/stale: transfer permission definitions are at `apps/api/database/seeders/RolesAndPermissionsSeeder.php:196-200`; manager transfer grants are at `:600-602`. |
| `StockTransferService.php:63` as a “service assertion” (`:118`) | Wrong: line 63 is only `QTY_SCALE = 4`; it is not an invariant assertion. |
| “rounded … FLOOR as the readers do,” citing matrix and location readers (`:207`) | Overstated: `LocationStockQueryService` uses FLOOR at `apps/api/app/Modules/Inventory/Application/Services/LocationStockQueryService.php:160-164,242`, but `StockMatrixQueryService` uses HALF_UP at `apps/api/app/Modules/Inventory/Application/Services/StockMatrixQueryService.php:379-382`. |

## Rejected false positives

- Grep found no fourth production reader of transfer in-transit quantity. The only read-side occurrences are `LocationStockQueryService.php:137-164,222-245`, `StockMatrixQueryService.php:395-408`, and `WeightedAverageCostService.php:117-127`. Other `TransferStatus::InTransit` occurrences belong to transfer lifecycle code.
- The completed-transfer backfill preserves historical reader answers: completed transfers are already status-excluded; in-transit and cancelled rows remain zero; completed line/allocation counters become sent quantity, so their derived remainder is zero (`docs/superpowers/specs/2026-09-08-transfer-destination-receipt-and-blind-receiving-design.md:269-270`).
- `closed_with_writeoff` is exactly 20 characters and fits the current `string(20)` column (`apps/api/database/migrations/tenant/2026_05_28_120000_create_stock_transfers_table.php:41-42`).
- `Damage` and `WriteOff` exist and both require Shrinkage GL entries (`apps/api/app/Modules/Inventory/Domain/Enums/MovementReason.php:26-28,73-111`).
- The land-then-issue stock arithmetic is viable: `receive()` accepts batch, reason, unit cost and references (`apps/api/app/Modules/Inventory/Domain/Services/StockAdjustmentService.php:115-129`); `issue()` accepts the same destructive context (`:253-267`).
- The GL buffer precedent and static boundary are valid; the defect is specifically the missing non-batch context, not the overall buffer strategy (`apps/api/app/Modules/POS/Application/Services/ReturnScrapWriteOffService.php:148-189`).
- Existing transfer events remain untouched, satisfying rule 8 (`apps/api/app/Modules/Inventory/Domain/Events/StockTransferInitiated.php:9-23`, `StockTransferCompleted.php:9-26`; `CLAUDE.md:36-37`).
- Header-first then sorted product advisory locks match the existing lock order (`apps/api/app/Modules/Inventory/Application/Services/StockTransferService.php:317-339,734-742`). Two distinct-key receivers against the same transfer will serialize correctly.
- Keeping `complete` does not violate one-surface-per-concept if its old loop is deleted and it delegates wholly to the receipt service. Convention 11 permits façade paths sharing one terminal writer (`docs/conventions/11-ONE-SURFACE-PER-CONCEPT.md:37-39`).
- Replacing the local stock-transfer interfaces with generated DTOs is correct and necessary; the current shadow types are at `apps/web/src/features/stock-transfers/types/index.ts:1-43`, and convention 11 forbids retaining them (`docs/conventions/11-ONE-SURFACE-PER-CONCEPT.md:44-47`).
- The glossary section names the new concepts, tables, primary writer and synonyms, satisfying the vocabulary portion of convention 11 (`docs/superpowers/specs/2026-09-08-transfer-destination-receipt-and-blind-receiving-design.md:43-54`).
- D1 is justified: using `inventory.view` for reconciliation would defeat blind mode because that permission is broadly seeded (`apps/api/database/seeders/RolesAndPermissionsSeeder.php:702,741,771,806`).

## Preserve

- Preserve the confirmed Odoo-style remainder/backorder behavior and hard over-receipt refusal (`docs/handoff/BRIEF-parallel-transfers-blind-receiving-2026-09-08.md:50-51`).
- Preserve company-level, opt-in `blind_receiving`, transfers first and PO receipts later on the same builder pattern (`docs/handoff/BRIEF-parallel-transfers-blind-receiving-2026-09-08.md:52`).
- Preserve DB notifications/web bell first and no push in this lane (`docs/handoff/BRIEF-parallel-transfers-blind-receiving-2026-09-08.md:53`).
- Preserve destination location membership with no receiver assignment (`docs/handoff/BRIEF-parallel-transfers-blind-receiving-2026-09-08.md:54`).
- Preserve the three-reader remainder conversion, completed backfill, cancelled-status exclusion, scale-4 decimal strings, bcmath arithmetic and no-float rule (`docs/superpowers/specs/2026-09-08-transfer-destination-receipt-and-blind-receiving-design.md:108-114,207,269-270`).
- Preserve immutable existing events and versioned new stored events; fix their fields/atomicity without restructuring an existing event (`CLAUDE.md:36-37`).
- Preserve separate full and receiver payload builders, with omission by construction rather than frontend hiding (`docs/superpowers/specs/2026-09-08-transfer-destination-receipt-and-blind-receiving-design.md:168-173`).
- Preserve the dedicated `inventory.transfers.reconcile` permission; do not revert reconciliation to `inventory.view` (`docs/superpowers/specs/2026-09-08-transfer-destination-receipt-and-blind-receiving-design.md:175-177`).
- Preserve one receipt writer with `complete` as a compatibility delegate (`docs/superpowers/specs/2026-09-08-transfer-destination-receipt-and-blind-receiving-design.md:183-185`).
- Preserve the decision that partial receipts are not cancellable and that remaining stock closes through write-off (`docs/superpowers/specs/2026-09-08-transfer-destination-receipt-and-blind-receiving-design.md:88,97-104`).

## Owner decisions required

1. **Coarse status visibility:** decide whether a blind receiver may see `partially_received`, which reveals that a remainder exists but not its amount (`docs/superpowers/specs/2026-09-08-transfer-destination-receipt-and-blind-receiving-design.md:171,282`). The counting precedent does expose status (`apps/api/app/Modules/Inventory/Presentation/Controllers/InventoryCountingController.php:380-390`), so accepting the coarse signal is defensible.
2. **Seeded reconciler role:** decide whether the standard manager role receives `inventory.transfers.reconcile`. Today manager is the seeded operational transfer receiver (`apps/api/database/seeders/RolesAndPermissionsSeeder.php:584-602`); granting reconcile means blind mode affects only custom receiver roles.
3. **Freight on total write-off:** decide whether freight allocated to units with no surviving company-owned quantity is expensed, omitted, or capitalized elsewhere. Current WAC cannot capitalize it when total owned quantity is zero (`apps/api/app/Modules/Inventory/Application/Services/WeightedAverageCostService.php:763-775`).

OQ-1 and OQ-2 should not remain owner blockers. Write-off-only was already confirmed, so return-to-source stays out of scope (`docs/handoff/BRIEF-parallel-transfers-blind-receiving-2026-09-08.md:50-51`). Replenishment currently settles on initiation and the spec already tests preserving that behavior (`docs/superpowers/specs/2026-09-08-transfer-destination-receipt-and-blind-receiving-design.md:137,261`).

VERDICT: CHANGES-REQUIRED
