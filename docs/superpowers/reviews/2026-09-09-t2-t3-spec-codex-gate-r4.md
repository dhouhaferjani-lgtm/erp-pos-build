# Codex spec gate r4 — T-2/T-3 (gpt-5.6-sol, high, read-only, 2026-09-09)

Reviewed rev 4 against repository HEAD `80507fc0c6f65fbfb6d0166934456fcd0955b7aa`. No files were modified.

## Rev-3 closure table

| Rev-3 finding | Rev-4 disposition |
|---|---|
| B1 — replenishment responses expose transfer-linked quantities | **CLOSED** at `docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-4.md:235-270,326-327,454`. Both web and POS replenishment feeds mask transfer-linked `requested_qty` and `suggested_qty`. |
| B2 — a blind actor with close authority can post the hidden remainder | **CLOSED for the exact blind-leak finding** at `docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design переговор-rev-4.md:291-297,323-324,454,464`. Complete-only receivers cannot close. The replacement collapses read/reconcile and destructive-close authority; see M4. |
| B3 — runtime enablement leaves exact values in client caches | **NOT CLOSED.** The version contract is stated at `docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-4.md:217-223,233`, but the web and POS implementation recipes do not enforce it for every first successful versioned response; see B1. |
| M1 — complete-only receiver cannot reach the web receiving surface | **NOT CLOSED.** Web route guards are corrected at `docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-4.md:389,403`, but the backing list/show API routes remain specified nowhere for complete-only access; see M1. |
| M2 — migration self-healing promise | **NOT CLOSED.** Rev 4 rejects the repair requirement but still requires an impossible post-commit drop-and-`tenants:migrate` recovery test at `docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-4.md:104-108,437`. See M2. |
| M3 — ambiguous multi-lot movement identity | **CLOSED** at `docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-4.md:122-124,188-193,342,445,461`. Parent IDs are null and lot rows authoritative. |
| M4 — discrepancy reason contradicts movement reason | **CLOSED** at `docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-4.md:132-133,287,297,333-340,445-448`. Movement reason now derives from the action. |
| M5 — deterministic notification ID poisons a replayed/racing job | **CLOSED for exact idempotency** at `docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-4.md:375-381,456`. The custom insertion path has a separate timestamp defect; see M5. |
| M6 — incomplete second-of-everything writer matrix | **CLOSED** at `docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-4.md:426-437`. |
| m1 — generated receiver type cannot inherit a builder comment | **CLOSED** at `docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-4.md:304-307,388,455`. Rev 4 correctly relies on a runtime recursive key scan. |
| m2 — shared Eloquent scope cannot be used at the three query roots | **CLOSED** at `docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-4.md:151-157`. |
| m3 — every unique key need not carry `company_id` | **CLOSED** at `docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-4.md:68,120-124`. |
| Citation audit | **NOT CLOSED.** The baseline is stale, decision citations omit a later owner ruling, several claims are unsupported or incomplete, and basename/continuation citations remain despite the literal-path assertion. See the citation audit. |
| OQ-4 freight | **NOT CLOSED by rev 4, but already CLOSED by owner.** Rev 4 incorrectly calls it owner-open at `docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-4.md:484`; the owner confirmed the proposed residual/no-journal default at `docs/handoff/OWNER-QUESTIONS-consolidated-2026-09-06.md:106`. |
| OD-1 quantity-less complete in blind mode | **NOT CLOSED.** It remains an orchestrator default awaiting owner confirmation at `docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-4.md:487`. |
| OD-2 multi-receiver attribution | **NOT CLOSED by rev 4, but already CLOSED by owner.** Rev 4 still says “owner to confirm” at `docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-4.md:197-198,488`; confirmation is recorded at `docs/handoff/OWNER-QUESTIONS-consolidated-2026-09-06.md:106`. |
| Close authority implies reconciliation visibility | **CLOSED for blind safety**, but the permission-collapse product/security decision lacks an owner ruling; see M4. |
| Activation semantics | **NOT CLOSED.** OD-4 remains owner-unconfirmed and its proposed client implementation is incomplete at `docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-4.md:490`. |
| Round-1 tail rejecting `return_to_source` | **REJECTED-correctly.** Rev 4 includes return-to-source without GL at `docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-4.md:63,297,340,353-354,447`, matching the owner ruling at `docs/handoff/OWNER-QUESTIONS-consolidated-2026-09-06.md:100`. |
| OQ-1, OQ-2, OQ-3, D1, D2 and stored per-line facts | **CLOSED by owner ruling** at `docs/handoff/OWNER-QUESTIONS-consolidated-2026-09-06.md:98-103`; rev 4 implements their intended defaults. |

## BLOCKER

### B1. The “first successful versioned response” cache guarantee is not implementable from the specified web/POS recipes

The global rule says every response carrying a new version drops every old transfer/incoming/distribution/replenishment cache before the new response is stored, and R4 ends at the first successful versioned request (`docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-4.md:221-223,233,270`).

Two implementation paths violate that rule:

- The fraud-settings response itself carries `visibility_version` (`docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-4.md:314-315`), but the web gate is applied only to transfer/matrix/replenishment responses. The settings mutation merely invalidates stock transfers, not stock matrix or replenishment caches (`docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-4.md:391`). Current mutation callbacks already receive/ignore or only partially use the returned data at `apps/web/src/features/compliance/pages/FraudSettingsPage.tsx:85-107`. Thus the browser performing the flip has completed a successful versioned request while exact aggregate caches may remain.
- On POS, only `pullLocationStock` explicitly calls the operation that zeroes `location_stock.incoming_transfer`; that operation exists at `apps/pos/src/lib/db/repositories/locationStockRepository.ts:191-230`. Rev 4 says a distribution or replenishment fetch performs the same version comparison before its own upsert, but it does not require either path to zero `location_stock` (`docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-4.md:408-411`). If distribution is the first versioned response, the stored version advances while the old exact location row remains. Product renderers continue reading it at `apps/pos/src/components/organisms/ProductGrid/ProductTable.tsx:115-124` and `apps/pos/src/components/molecules/ProductCard/ProductCard.tsx:233-250`.

This is a real blind leak, not accepted R4: it persists after the client’s first successful versioned response. Specify one atomic invalidation primitive that every version-bearing web/POS path invokes, including settings responses, and test each possible first response independently.

### Blind response-shape audit

| Receiver-reachable shape | Result |
|---|---|
| Receiver view | **Wire-safe.** Identity, products, lot identity and own receipts only; sent/remaining quantities, batch-allocation quantities, sender notes and costs are omitted by construction (`docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-4.md:256-260,304-307,455`). |
| List/show | **Shape-safe but authority path broken.** Per-transfer builder selection prevents quantity and batch-allocation leakage (`docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-4.md:238,260`), but a complete-only receiver cannot call the current routes; M1. |
| Receive 201 | **Safe.** It returns the actor’s submitted quantities and a gated transfer projection (`docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-4.md:239,261,289`). |
| Complete | **Safe.** Blind actors get a static 422 before idempotency replay; no server-derived “receive all” occurs (`docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-4.md:240,262,317-318`). |
| Close | **Quantity-safe.** Complete-only actors receive a static 403; closers receive the full quantities because reconcile currently confers visibility (`docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-4.md:241,263,291-297`). Permission separation remains M4. |
| Typed/state 422s | **Safe.** New messages are static and details contain IDs only. `current_status = partially_received` remains a coarse accepted signal (`docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-4.md:242,262-264`; owner ruling `docs/handoff/OWNER-QUESTIONS-consolidated-2026-09-06.md:102`). |
| Stock matrix | **Wire-safe.** Transfer incoming is removed while PO incoming remains (`docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-4.md:243,265,320-321`). |
| POS stock levels/distribution | **Wire-safe, cache-unsafe.** Transfer incoming becomes `null`, but first-response cache ordering leaks as B1 (`docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-4.md:244-245,266,405-411`). |
| Replenishment web/POS | **Wire-safe.** Transfer-fulfilled rows retain identity/status but null both quantities (`docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-4.md:253-254,267,326-327`). |
| Notifications | **Quantity-safe.** Only counts/status/identity are carried (`docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-4.md:249,268,380-381`). Delivery has the unrelated M5 defect. |
| Stock movements/entry-exit notes | **Safe under the specified location scoping.** Before receipt no destination movement exists; afterward the receiver sees quantities they submitted (`docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-4.md:246-247,269,459`). |
| Generated web types | **Safe if generated from the named DTOs.** Receiver types omit expected fields and runtime output is protected by T9b (`docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-4.md:250,388,455`). |
| Mobile types | **Contract-safe.** The proposed receiver type omits sent/ordered/remaining fields and mobile has no close/receive-all action (`docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-4.md:416-420`). |
| Client caches | **Not safe.** B1 falsifies the post-first-versioned-response guarantee. |

The omission-by-construction design matches the counting precedent: counter payload literals exclude theoretical values at `apps/api/app/Modules/Inventory/Presentation/Controllers/InventoryCountingController.php:334-377` and `apps/api/app/Modules/Inventory/Presentation/Controllers/CountingItemController.php:54-74,158-177`. The existing frontend counting client has no comparable counter-view/NEVER-INCLUDE contract at `apps/web/src/features/inventory-counting/api/countingApi.ts:1-150`; rev 4’s backend recursive key scan is therefore the stronger relevant guard.

## MAJOR

### M1. The API routes still exclude the complete-only receiver

Rev 4 fixes only React route guards and then promises a complete-only user can reach list, detail and receive pages (`docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-4.md:389,403`). The backend remains:

- List: `inventory.transfers.view` at `apps/api/app/Modules/Inventory/Presentation/routes.php:99-101`.
- Show: `inventory.transfers.view` at `apps/api/app/Modules/Inventory/Presentation/routes.php:107-109`.
- Complete: `inventory.transfers.complete` at `apps/api/app/Modules/Inventory/Presentation/routes.php:111-113`.

Changing `RequirePermission` in React cannot make the list/detail requests succeed. T9’s complete-only receiver hitting list/show and T18’s stated authority model are therefore inconsistent with the specified routes (`docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-4.md:454,464`). Define the backend any-of gate or constrain the UI to receiver-view only.

### M2. `tenants:migrate` cannot repair the post-commit drift required by S3

A PostgreSQL transaction prevents a failed `up()` from committing half its statements; it does not make an already-recorded migration self-healing. Laravel compares migration files with the repository and runs only pending files at `apps/api/vendor/laravel/framework/src/Illuminate/Database/Migrations/Migrator.php:124-159`. Transaction wrapping occurs later, only for migrations actually selected to run, at `apps/api/vendor/laravel/framework/src/Illuminate/Database/Migrations/Migrator.php:432-451`.

Rev 4 nevertheless requires:

1. Fully apply the migration.
2. Drop an added column, CHECK and index.
3. Rerun `tenants:migrate`.
4. Recover the dropped objects.

That test is specified at `docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-4.md:108,437`; Laravel will skip the recorded migration. Either remove post-commit drift from S3 and narrow recovery strictly to a rolled-back pending migration, or define an explicit repair command/migration. The current rejection of r3-M2 is incorrect.

### M3. `visibility_version` and settings events have no concurrency discipline

I8 requires every settings write to advance exactly one version and every blind flip to receive exactly one stored event with that version (`docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-4.md:168`). The recipe is `updateOrCreate` followed by `$settings->increment()` in a transaction, with no `lockForUpdate` and no unique-create race handling (`docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-4.md:217-220`).

The table has one row per company at `apps/api/database/migrations/tenant/2025_12_23_160000_create_company_fraud_settings_table.php:16-35`. Two transactions can both hydrate version 1; Eloquent advances the in-memory attribute from that stale value before issuing the atomic SQL increment at `apps/api/vendor/laravel/framework/src/Illuminate/Database/Eloquent/Model.php:1048-1074`. Both may consequently return version 2 or attempt aggregate event version 2 even though the database reaches version 3. The stored-event uniqueness constraint is `(aggregate_uuid, aggregate_version)` at `apps/api/database/migrations/tenant/2025_11_30_102448_create_stored_events_table.php:13-23`, turning some valid races into rollback/collision failures.

Specify row creation/locking, refresh the authoritative incremented row, and add PG update/update plus update/reset concurrency tests. The sequential S-matrix at `docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-4.md:434-435,465` does not cover this.

### M4. Reconcile visibility and destructive close authority have been collapsed without an owner ruling

D1 authorizes a new reconciliation/expected-quantity permission, seeded to manager and admin and independently grantable (`docs/handoff/OWNER-QUESTIONS-consolidated-2026-09-06.md:98`). Rev 4 also makes that permission sufficient to write off or return all remaining stock and explicitly deletes a separate close permission (`docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-4.md:291-297,323-324,464,492`).

That is a material privilege expansion. Existing transfer permissions and routes separate viewing, creating, completing and cancelling at `apps/api/database/seeders/RolesAndPermissionsSeeder.php:196-200` and `apps/api/app/Modules/Inventory/Presentation/routes.php:99-117`. Under rev 4, a custom reconciliation-only auditor can perform a terminal stock/GL mutation.

Blind safety requires close callers to see expected quantities, but it does not require read/reconcile permission itself to authorize close. Default to requiring both reconcile and a close permission unless the owner explicitly approves the combined authority.

### M5. The custom notification insert omits timestamps and can break the web bell

The proposed channel bypasses Eloquent and supplies ID, morph fields, type and encoded data to `insertOrIgnore`, but no timestamps (`docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-4.md:379`). The stock channel uses relation `create()`, which supplies model timestamps at `apps/api/vendor/laravel/framework/src/Illuminate/Notifications/Channels/DatabaseChannel.php:17-21`.

`notifications.created_at` and `updated_at` are nullable because `timestampsTz()` creates nullable columns (`apps/api/database/migrations/tenant/2026_07_12_110000_create_notifications_table.php:13-20`; `apps/api/vendor/laravel/framework/src/Illuminate/Database/Schema/Blueprint.php:1293-1304`). The API consequently emits `created_at: null` at `apps/api/app/Modules/Notification/Presentation/Controllers/NotificationController.php:20,28-36`, while the web contract declares a string and unconditionally calls `localeCompare` at `apps/web/src/features/notifications/api/notificationsApi.ts:4-10` and `apps/web/src/features/notifications/components/NotificationPanel.tsx:81-83`.

Require `created_at` and `updated_at` in the conflict-safe insert and extend T10 to assert the API contract and bell rendering/order, not merely row count and queue success.

### M6. Rev 4 reopens two owner decisions that are already confirmed

Rev 4 calls freight OQ-4 owner-open and says the lane cannot merge without a ruling (`docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-4.md:484`). It likewise says multi-receiver attribution still awaits confirmation (`docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-4.md:197-198,488`).

The mandated owner register now records both as confirmed at `docs/handoff/OWNER-QUESTIONS-consolidated-2026-09-06.md:106`. Preserve the specified freight algorithm and attribution behavior, but move both from open questions to applied rulings.

## MINOR

### m1. The citation audit is still not clean

Rev 4 states that every citation was re-derived at its baseline, every path is full, and no abbreviation is used (`docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-4.md:5`). All three claims are false. The detailed discrepancies follow.

## Citation audit

| Claim | Result and real line |
|---|---|
| Baseline HEAD and diffs (`rev-4.md:5`) | **WRONG/stale.** Actual HEAD during this gate was `80507fc0c6f65fbfb6d0166934456fcd0955b7aa`, not `8d16e706…`. `git diff --name-only 0310a1df6..HEAD` listed six documentation files, not one; `87a16056b..HEAD` listed ten, not six. No production file appeared. |
| Owner-rulings range (`rev-4.md:3`) | **STALE/incomplete.** `docs/handoff/OWNER-QUESTIONS-consolidated-2026-09-06.md:94-103` contains the original rulings, but the decisive later freight and attribution confirmations are at `docs/handoff/OWNER-QUESTIONS-consolidated-2026-09-06.md:106`. |
| “Production/staging lane (`tenants:migrate` per tenant DB)” supported by database config (`rev-4.md:106`) | **WRONG support.** `apps/api/config/database.php:87-120` defines PostgreSQL and central-connection configuration; it does not establish the `tenants:migrate` deployment workflow. |
| Migration rerun repairs a dropped added column/CHECK/index (`rev-4.md:108,437`) | **WRONG.** Applied migrations are filtered out at `apps/api/vendor/laravel/framework/src/Illuminate/Database/Migrations/Migrator.php:124-159`; transaction wrapping at `apps/api/vendor/laravel/framework/src/Illuminate/Database/Migrations/Migrator.php:432-451` does not change that. |
| All controller envelopes have `{error:{code,message,details}}` (`rev-4.md:229`) | **OVERSTATED.** The cited helpers at `apps/api/app/Modules/Inventory/Presentation/Controllers/StockTransferController.php:359-402` do, but existing `INVALID_TRANSFER` omits `details` at `apps/api/app/Modules/Inventory/Presentation/Controllers/StockTransferController.php:187-195`. |
| Hand-written transfer types (`rev-4.md:250,388`) | **INCOMPLETE range.** The hand-written transfer domain file extends through `apps/web/src/features/stock-transfers/types/index.ts:106`; `:1-46` ends at the start of the header interface and does not cover all interfaces being replaced. |
| “Full repo-root path on every citation” (`rev-4.md:5,29`) | **WRONG.** Continuation/basename citations remain at rev-4 lines `24,26,108,114,116,130,154-156,178,219,229,237-238,244-245,247,253,274,324,327,333,347-349,357,364,379,382,389-390,392,402,407-410`. The brace expression `apps/pos/src/locales/{en,fr}/pos.json` at line 410 is also not a literal path. Their targets were resolved from context; this is a format/traceability defect, not evidence that each underlying claim is false. |
| Current transfer lifecycle, response quantities, notes, allocations and authorization | **VERIFIED** at `apps/api/app/Modules/Inventory/Application/Services/StockTransferService.php:317-505,514-608,631-752`, `apps/api/app/Modules/Inventory/Presentation/Controllers/StockTransferController.php:32-114,205-235,282-402`, and `apps/api/app/Modules/Inventory/Presentation/routes.php:98-117`. |
| Transfer/line/allocation schema and status capacity | **VERIFIED** at `apps/api/database/migrations/tenant/2026_05_28_120000_create_stock_transfers_table.php:29-99` and `apps/api/database/migrations/tenant/2026_06_05_121000_create_stock_transfer_line_batch_allocations_table.php:14-30`. |
| Exactly three in-transit remainder readers | **VERIFIED** at `apps/api/app/Modules/Inventory/Application/Services/LocationStockQueryService.php:137-164,217-245`, `apps/api/app/Modules/Inventory/Application/Services/StockMatrixQueryService.php:390-408`, and `apps/api/app/Modules/Inventory/Application/Services/WeightedAverageCostService.php:100-127`. |
| Counting, POS, replenishment, movement, entry-note and mobile surface anchors | **VERIFIED** at the cited controller/resource/client lines. Mobile HEAD remains `51e3445`; the current expected-quantity types are at `/Users/houssamr/Projects/syneriva/erp-mobile/src/features/receiving/types.ts:58-73,98-109`. |
| Stored-event persistence/aggregate anchoring | **VERIFIED** at `apps/api/vendor/spatie/laravel-event-sourcing/src/StoredEvents/EventSubscriber.php:34-38`, `apps/api/vendor/spatie/laravel-event-sourcing/src/StoredEvents/Repositories/EloquentStoredEventRepository.php:40-51,101-134`, and `apps/api/database/migrations/tenant/2025_11_30_102448_create_stored_events_table.php:11-23`. |
| Movement reasons, GL families and GL requirement | **VERIFIED.** `Damage` and `WriteOff` exist at `apps/api/app/Modules/Inventory/Domain/Enums/MovementReason.php:26-28`, map to Shrinkage at `:73-93`, and require GL at `:96-112`. `TransferIn` requires no GL. |
| Stock/GL bridge and WAC | **VERIFIED.** Receive/issue accept the required context at `apps/api/app/Modules/Inventory/Domain/Services/StockAdjustmentService.php:115-129,253-267`; lot and lot-less posting are supported at `apps/api/app/Modules/Inventory/Application/Services/InventoryGlPostingService.php:89-127,130-207`; WAC remains separate. |
| Precision contract | **VERIFIED** at `docs/architecture/precision-contract.md:7-28,30-40,63-67,79-97` and `CLAUDE.md:71-79`: scale-4 storage, decimal strings, bcmath/Big.js and no floats are correctly specified. |
| Notification idempotency primitives | **VERIFIED for duplicate suppression** at `apps/api/vendor/laravel/framework/src/Illuminate/Notifications/NotificationSender.php:150-158,195-200,220-228` and `apps/api/vendor/laravel/framework/src/Illuminate/Database/Query/Builder.php:4132`. The timestamp omission in M5 remains. |

All other semantic `path:line` anchors in rev 4 were re-opened and matched their stated current-code fact or were clearly labelled edit targets.

## Rejected false positives

- There is no fourth production remainder reader. Repository search found the three named readers plus lifecycle writes/checks at `apps/api/app/Modules/Inventory/Application/Services/StockTransferService.php:441,602`. `TransferLineQueryService` reads shipped lines for initiation-time replenishment settlement at `apps/api/app/Modules/Inventory/Application/Services/TransferLineQueryService.php:13-25`, consumed at `apps/api/app/Modules/Replenishment/Application/Listeners/SettleRequestsOnTransferInitiated.php:22-41`; it is not an incoming-remainder reader.
- The completed-transfer backfill preserves every reader’s historical answer. Completed transfers were excluded by status before the change and remain excluded afterward; setting completed counters to sent produces remainder zero. In-transit and cancelled transfers are explicitly untouched at `docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-4.md:457,473-474`.
- The pattern query does not cross companies merely because the close CTE joins by `transferLineId`. `stock_transfer_lines.id` is a database-wide primary key at `apps/api/database/migrations/tenant/2026_05_28_120000_create_stock_transfers_table.php:74-83`; a legitimate line ID cannot identify a second company’s line. Final receiver results and baselines are also company-filtered at `docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-4.md:200-213`.
- `partially_received` is not a leak finding. The owner explicitly accepted that coarse status at `docs/handoff/OWNER-QUESTIONS-consolidated-2026-09-06.md:102`.
- Land-then-issue arithmetic is sound. The destination TransferIn creates the stock that Damage/WriteOff removes, producing net on-hand zero for the damaged/disposed portion and reducing company-owned quantity exactly once (`docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-4.md:337-344`).
- Return-to-source without GL is correct and owner-ruled (`docs/handoff/OWNER-QUESTIONS-consolidated-2026-09-06.md:100`; `docs/superpowers/reviews/2026-09-09-benchmark-transfer-discrepancy-gl.md:7-13`).
- The new status strings fit the existing `string(20)` column; `closed_with_writeoff` is exactly 20 characters (`apps/api/database/migrations/tenant/2026_05_28_120000_create_stock_transfers_table.php:41-42`).
- Keeping `complete` as a fully delegating compatibility endpoint does not create a second receipt writer (`docs/conventions/11-ONE-SURFACE-PER-CONCEPT.md:37-47`; `docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-4.md:317-318`).

## Preserve

- Preserve Odoo-style open-remainder/backorder semantics and hard over-receipt refusal (`docs/handoff/BRIEF-parallel-transfers-blind-receiving-2026-09-08.md:50-51`).
- Preserve company-level default-off blind receiving, transfers first and PO receipts later (`docs/handoff/BRIEF-parallel-transfers-blind-receiving-2026-09-08.md:52`).
- Preserve DB notifications/web bell first, with no push work in this lane (`docs/handoff/BRIEF-parallel-transfers-blind-receiving-2026-09-08.md:53`).
- Preserve destination membership and no receiver assignment (`docs/handoff/BRIEF-parallel-transfers-blind-receiving-2026-09-08.md:54`).
- Preserve owner-ruled `return_to_source` on close with stock movement only and no GL (`docs/handoff/OWNER-QUESTIONS-consolidated-2026-09-06.md:100`).
- Preserve quantity-based remainder in exactly the three readers, completed-row backfill and cancelled exclusion (`docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-4.md:151-168,457,473-474`).
- Preserve scale-4 quantity storage, decimal strings, `QuantityScale`, bcmath and no floats (`docs/architecture/precision-contract.md:7-28,30-40,63-67`).
- Preserve immutable existing events and versioned stored header/per-line receipt facts with receiver identity (`CLAUDE.md:36-37`; `docs/handoff/OWNER-QUESTIONS-consolidated-2026-09-06.md:103`).
- Preserve separate full and receiver payload builders with omission by construction (`docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-4.md:299-307`).
- Preserve reconciliation behind `inventory.transfers.reconcile`, seeded to manager and admin and independently grantable (`docs/handoff/OWNER-QUESTIONS-consolidated-2026-09-06.md:98`).
- Preserve one receipt writer, refusal to cancel after partial receipt, and no quantity-less completion for blind receivers (`docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-4.md:137-149,317-318`).
- Preserve the freight residual/no-journal algorithm and multi-receiver attribution as confirmed decisions, not provisional defaults (`docs/handoff/OWNER-QUESTIONS-consolidated-2026-09-06.md:106`).

## Owner decisions required

1. **OD-1 remains genuinely open.** Confirm that quantity-less `complete` is unavailable whenever `canSeeExpected()` is false (`docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-4.md:317-318,487`).

2. **Close authority needs an explicit ruling.** Confirm whether `inventory.transfers.reconcile` alone authorizes terminal write-off/return, or whether close requires both reconcile visibility and a separate close permission. D1 settles expected-quantity visibility and default seeding, but not this destructive privilege expansion (`docs/handoff/OWNER-QUESTIONS-consolidated-2026-09-06.md:98`; `docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-4.md:291-297,323-324`).

3. **OD-4 activation semantics remain open.** Confirm the stated “effective server-side immediately; clients become consistent at their next successful versioned response” rule (`docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-4.md:490`). The implementation must still fix B1 whichever activation policy is selected.

OQ-1, OQ-2, OQ-3, D1, D2, freight OQ-4, multi-receiver attribution and stored per-line event facts are already answered and must not be reopened (`docs/handoff/OWNER-QUESTIONS-consolidated-2026-09-06.md:98-103,106`).

VERDICT: CHANGES-REQUIRED
