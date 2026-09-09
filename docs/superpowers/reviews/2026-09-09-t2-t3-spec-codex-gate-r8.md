# Codex spec gate r8 — T-2/T-3 (gpt-5.6-sol, high, read-only, 2026-09-09)

Reviewed REV 8 against repository HEAD `1a01be80f7487676038ed4d68446543886ecafb9`. The worktree already contained two unrelated untracked documents; no file was modified.

## Rev-7 closure table

| Rev-7 finding | Rev-8 disposition |
|---|---|
| r7-M1 — T9 could not reach POS stock distribution | **CLOSED** at `docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-8.md:11,548,555,573,580`: actors now carry `pos.view_cross_location_stock` and company A enables `allow_cross_location_stock_view`, matching `apps/api/app/Modules/POS/Presentation/Controllers/StockDistributionController.php:35-41`. |
| r7-M2 — list/show web types excluded the receiver projection | **CLOSED** at `docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-8.md:12,446-451,460,464`: list/show are discriminated unions and both pages narrow on `blind`. The detail-page edit-range citation remains inaccurate, recorded below. |
| r7-M3 — independently granted reconcile could not reach its canonical page | **NOT CLOSED as an executable acceptance contract.** Route reachability is repaired at `docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-8.md:253-254,367-382,439,448,526`, but the web test at line 464 incorrectly requires a reconcile-only user to see Close. That conflicts with the two-permission default and is new M1. |
| r7-m1 — return-to-source shortages absent from pattern query | **CLOSED** at `docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-8.md:217-230,524`; positive `quantityWrittenOff` and `quantityReturned` both count once per line. |
| r7-m2 — empty `details` alternated between object and array | **CLOSED** at `docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-8.md:256,279,567`; consistently `[]`. |
| r7-m3 — stale “submitted lot flag” definition | **CLOSED** at `docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-8.md:143,305,322`; shipment grain derives from allocations. |
| r7-m4 — Laravel validation summary suffix omitted | **CLOSED** at `docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-8.md:283,510`, matching `apps/api/vendor/laravel/framework/src/Illuminate/Validation/ValidationException.php:85-99`. |
| r7 citation — stale HEAD | **NOT CLOSED.** REV 8 pins `a67a68c3…` at `docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-8.md:5`; actual HEAD is `1a01be80f7487676038ed4d68446543886ecafb9`. The intervening diff is documentation-only. |
| r7 citation — framework validation message | **CLOSED** at `docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-8.md:283,510`. |
| r7 citation — T9 POS reachability | **CLOSED** at `docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-8.md:548,555,573,580`. The movement-feed leak below is distinct. |
| r7 verified audits — TanStack matching and GL buffer | **REJECTED-correctly / still verified** at `node_modules/.pnpm/@tanstack+query-core@5.90.11/node_modules/@tanstack/query-core/build/modern/utils.js:94-104` and `apps/api/app/Modules/Inventory/Application/Services/InventoryGlPostingBuffer.php:56-67`. |
| Round-1 rejection of `return_to_source` | **REJECTED-correctly.** The owner-ratified stock-only/no-GL path remains at `docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-8.md:53,70,84,337,398,411-412,605`. |
| Suggested write-off-only close event | **REJECTED-correctly.** General `StockTransferClosedV1` carries an explicit disposition and supports both approved outcomes without altering an existing event at `docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-8.md:196,204`. |
| OD-1, close authority, OD-4 | **NOT CLOSED intentionally and non-blocking**, with complete defaults at `docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-8.md:599-603`. |

## BLOCKER

### B1. Multi-receiver movement feeds break the blind-receiving guarantee

REV 8 guarantees that a blind actor cannot obtain a sent, expected, or remaining line/lot quantity for an `in_transit` or `partially_received` transfer (`docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-8.md:249`). Its audit assumes destination TransferIn rows exist only after “the actor’s own receipt” and that movement feeds expose only “the actor’s own posted quantities” (`docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-8.md:262-263,288,536`). The code disproves that assumption:

- `StockMovementController` scopes by company and permitted locations, not actor (`apps/api/app/Modules/Inventory/Presentation/Controllers/StockMovementController.php:60-80`). It exposes `quantity`, `quantity_before`, `quantity_after`, transfer `reference_id`, and posting user (`apps/api/app/Modules/Inventory/Presentation/Controllers/StockMovementController.php:208-245`).
- `EntryExitNoteController` is not actor-scoped. Even after adding location scope, it groups all movements sharing document/location/direction and emits every line’s quantities and before/after balances (`apps/api/app/Modules/Inventory/Presentation/Controllers/EntryExitNoteController.php:44-72,216-254`).
- The receiver builder limits history to `my_receipts`, but these generic feeds bypass that boundary (`docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-8.md:276,346`).

Concrete leak: receiver A posts part of a line; receiver B posts its remainder while another line keeps the transfer `partially_received`; A can read B’s TransferIn through either generic feed. A can then submit a positive amount for the now-full line and receive line-ID-bearing `NOTHING_TO_RECEIVE` (`docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-8.md:282,324`). That establishes zero remainder; summing transfer-linked movements yields the exact sent quantity while the transfer remains receivable. The accepted R2 residual only covers terminal transfers (`docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-8.md:249`).

`quantity_before` and `quantity_after` can also encode another receiver’s landed stock. Consequently, merely filtering movements by `user_id` would not be sufficient.

T9 misses this composition: `K_none` trusts the false actor-owned premise, and no second receiver posts a hidden sentinel before the movement scans (`docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-8.md:516,534-536,565-580`).

Gate minimum:

- For blind actors, omit or mask transfer-linked `quantity`, `quantity_before`, and `quantity_after` on both movement surfaces while the associated transfer is carrying, including receipt-linked destructive movements where applicable.
- Add a T9 fixture where another destination receiver posts a hidden sentinel while another line remains open.
- Scan both feeds as the first receiver, exercise `NOTHING_TO_RECEIVE`, and retain the reconcile positive control.

### Blind response-shape audit

| Receiver-reachable shape | Code-derived result |
|---|---|
| `/receiver-view` | Safe by construction: no sent/remaining/cost/sender note, allocation quantity, other users’ receipts, or close fields (`docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-8.md:276,344-347`). This follows the counting separation at `apps/api/app/Modules/Inventory/Presentation/Controllers/InventoryCountingController.php:334-377` and `apps/api/app/Modules/Inventory/Presentation/Controllers/CountingItemController.php:54-74,161-177`. |
| Transfer list/show | Safe server projection; generated unions and narrowing are coherent (`docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-8.md:254,276-277,446-460`). No allocation quantity is present. |
| Receive 201/replay 200 | Safe data: echoes only the actor’s submitted received/damaged values and reasons; snapshots and movement IDs are excluded (`docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-8.md:278,329,347`). The web envelope mismatch is m2. |
| Quantity-less complete | Safe order: visibility is checked before replay and blind callers receive static `BLIND_REQUIRES_COUNTED_RECEIPT` (`docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-8.md:255,279,357-358`). |
| Close/reconciliation | Blind receivers cannot reach either; close requires both permissions (`docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-8.md:256-257,331-337`). |
| Typed/framework 422s | Static messages and ID-only typed details (`docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-8.md:258,279-283,305-326`). `OVER_RECEIPT` remains accepted R1; `NOTHING_TO_RECEIVE` becomes exact only with B1’s aggregate. |
| Notifications | Safe: identities and line counts, no quantities or notes (`docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-8.md:287,435-440`). |
| Stock matrix | Safe when masked: PO incoming remains while transfer incoming is excluded (`docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-8.md:264,284,360-361`). |
| POS stock levels/distribution | Safe wire design: `incoming_transfer: null`; T9 reachability is repaired (`docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-8.md:265-266,285,548-580`). |
| Web/POS replenishment | Transfer-linked `requested_qty`, `suggested_qty`, and arbitrary `note` are null (`docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-8.md:269-270,286,385`). POS envelope placement has minor m3. |
| Stock movements/entry-exit | **Unsafe — B1.** Both expose other receivers’ quantities; entry/exit can combine receivers. |
| `partially_received` | Safe in isolation as the accepted coarse state (`docs/handoff/OWNER-QUESTIONS-consolidated-2026-09-06.md:102`). The unsafe composition is B1 plus `NOTHING_TO_RECEIVE`. |
| Generated web/POS types | Receiver entity projections and nullable POS aggregate types do not add hidden quantities (`docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-8.md:446-468`). |
| Mobile types | Safe direction: transfer receiver types are separate and have NEVER-INCLUDE guards (`docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-8.md:477-481`). Counting’s client contract excludes theoretical and other counters’ quantities at `/Users/houssamr/Projects/syneriva/erp-mobile/src/features/counting/api/countingApi.ts:193-197,300-305`. |
| Batch allocations | No separate production read endpoint exposes `stock_transfer_line_batch_allocations`; reads are transfer formatting/service internals (`apps/api/app/Modules/Inventory/Presentation/Controllers/StockTransferController.php:282-341`; `apps/api/app/Modules/Inventory/Application/Services/StockTransferService.php:346-383,734-742`). Receiver shapes expose lot identity without allocation quantity. |

## MAJOR

### M1. The web acceptance test grants Close to a reconcile-only user

The applied default says Close renders only with both `inventory.transfers.reconcile` and `inventory.transfers.close` (`docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-8.md:336,448,452,602`). `RequirePermission` supports `requireAll` (`apps/web/src/features/auth/components/RequirePermission.tsx:60-62`), and T18 correctly expects reconcile-only close 403 (`docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-8.md:526`).

The web test nevertheless requires reconcile-only to see the Close action (`docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-8.md:464`). The requirements cannot both pass.

Change the test to require:

- Reconcile-only: reconciliation tab visible, Close absent.
- Reconcile+close: reconciliation tab and Close visible.

This follows the applied default and needs no owner decision.

## MINOR

### m1. T5 gives compatibility `/complete` the wrong success status

T5 expects 201 (`docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-8.md:510`). The existing endpoint uses `response()->json(...)` without a status argument and therefore returns 200 (`apps/api/app/Modules/Inventory/Presentation/Controllers/StockTransferController.php:231-235`). REV 8 says `/complete` keeps its contract (`docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-8.md:357-358,592`). T5 should expect 200.

### m2. Web receive types cannot retain replay metadata through `apiPost`

The API puts `meta.replayed` beside `data` (`docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-8.md:301,329`). The web section puts that metadata on `StockTransferReceiveResponse` but also says receive uses `apiPost` (`docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-8.md:446,449`). The real helper returns only `response.data.data`, discarding top-level `meta` (`apps/web/src/lib/api.ts:420-425`).

Specify either a raw `api.post` envelope type or an unwrapped data-only type whose caller intentionally does not consume replay metadata.

### m3. The replenishment table puts POS metadata under the wrong key

The consolidated table describes web and POS as using `meta.transfer_quantities_masked`, `meta.notes_masked`, and `meta.visibility_version` (`docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-8.md:286`).

The implementation paragraph correctly places POS keys beside top-level `as_of` and `truncated` (`docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-8.md:385`), matching `apps/api/app/Modules/POS/Presentation/Controllers/PosReplenishmentController.php:106-110`. Split or qualify the table row.

## Citation audit

| Claim | Verified/wrong with the real line |
|---|---|
| REV-8 baseline HEAD is `a67a68c3…` at `docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-8.md:5` | **WRONG/stale.** Actual HEAD is `1a01be80f7487676038ed4d68446543886ecafb9`. The intervening diff is documentation-only. |
| Detail’s “completed/cancelled rows through 152” and `<dl>` range 124-152 at REV-8 lines 12 and 451 | **WRONG/incomplete.** Completed rows end at `apps/web/src/features/stock-transfers/pages/StockTransferDetailPage.tsx:152`; cancelled rows are 155-166, notes 168-175, and the `<dl>` closes at 176. Real edit range: 124-176. |
| Movement feeds expose only the actor’s quantities at REV-8 lines 262, 288, and 536 | **WRONG.** Stock movements have no actor predicate at `apps/api/app/Modules/Inventory/Presentation/Controllers/StockMovementController.php:60-80`; entry/exit groups all matching movements at `apps/api/app/Modules/Inventory/Presentation/Controllers/EntryExitNoteController.php:44-72,216-254`. |
| Authorized `/complete` succeeds with 201 at REV-8 line 510 | **WRONG.** Current response is 200 at `apps/api/app/Modules/Inventory/Presentation/Controllers/StockTransferController.php:231-235`. |
| Both replenishment clients put fields under `meta` at REV-8 line 286 | **WRONG for POS.** POS has top-level `as_of`/`truncated` at `apps/api/app/Modules/POS/Presentation/Controllers/PosReplenishmentController.php:106-110`; planned placement is correctly stated at REV-8 line 385. |
| `StockTransferReceiveResponse` includes replay metadata while receive uses `apiPost` at REV-8 lines 446 and 449 | **WRONG/underspecified.** `apiPost` discards the envelope at `apps/web/src/lib/api.ts:420-425`. |
| Exactly three status-based in-transit aggregate readers | **VERIFIED** at `apps/api/app/Modules/Inventory/Application/Services/LocationStockQueryService.php:137-164,217-245`, `apps/api/app/Modules/Inventory/Application/Services/StockMatrixQueryService.php:390-408`, and `apps/api/app/Modules/Inventory/Application/Services/WeightedAverageCostService.php:100-127`. |
| Other transfer-line reader | **VERIFIED and not an aggregate omission.** `TransferLineQueryService` reads original quantity at `apps/api/app/Modules/Inventory/Application/Services/TransferLineQueryService.php:13-25` only for initiate-time replenishment settlement. |
| Damage/WriteOff and GL claims | **VERIFIED.** Both reasons exist and map to Shrinkage/GL at `apps/api/app/Modules/Inventory/Domain/Enums/MovementReason.php:26-28,68-112`; lot and lot-less bridges are at `apps/api/app/Modules/Inventory/Application/Services/InventoryGlPostingService.php:89-127,130-207`. |
| Stored-event aggregate anchoring | **VERIFIED.** Explicit UUID/version persistence is at `apps/api/vendor/spatie/laravel-event-sourcing/src/StoredEvents/Repositories/EloquentStoredEventRepository.php:101-138`; wildcard dispatch lacks the UUID at `apps/api/vendor/spatie/laravel-event-sourcing/src/StoredEvents/EventSubscriber.php:34-38`. |
| Counting blind precedent | **VERIFIED** at `apps/api/app/Modules/Inventory/Presentation/Controllers/InventoryCountingController.php:334-377`, `apps/api/app/Modules/Inventory/Presentation/Controllers/CountingItemController.php:54-74,161-177`, and `/Users/houssamr/Projects/syneriva/erp-mobile/src/features/counting/api/countingApi.ts:193-197`. |
| Permission seeding | **VERIFIED.** Permission block: `apps/api/database/seeders/RolesAndPermissionsSeeder.php:196-200`; manager grants: 585-602; admin all-permissions: 565-582; broad `inventory.view`: 702,741,771,806. |
| Remaining REV-8 `path:line` citations | **VERIFIED.** A mechanical pass opened all 474 unique path/range tokens, including the sibling mobile repository. Every range exists; no further claim-to-line discrepancy was found. |

## Rejected false positives

- No fourth status-based aggregate reader was found. Remaining matches are lifecycle/controller/UI consumers or initiate-only `TransferLineQueryService` (`apps/api/app/Modules/Inventory/Application/Services/TransferLineQueryService.php:13-25`).
- Completed-transfer backfill preserves historical answers. Completed rows were excluded by old predicates and remain outside `CARRYING_STATUSES`; received-equals-sent also gives zero remainder. In-transit and cancelled rows remain untouched (`docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-8.md:163-169,519,584-588`).
- Exhaustive status consumers are accounted for: PHP enum methods, web badge, filters, and detail actions (`apps/api/app/Modules/Inventory/Domain/Enums/TransferStatus.php:17-49`; `apps/web/src/features/stock-transfers/components/StockTransferStatusBadge.tsx:5-10`; `apps/web/src/features/stock-transfers/pages/StockTransferListPage.tsx:15-24`; `apps/web/src/features/stock-transfers/pages/StockTransferDetailPage.tsx:38-39`). `closed_with_writeoff` is exactly 20 characters and fits `string(20)` at `apps/api/database/migrations/tenant/2026_05_28_120000_create_stock_transfers_table.php:41-42`.
- TransferIn followed by Damage/WriteOff is viable with string arithmetic and stable movement IDs (`apps/api/app/Modules/Inventory/Domain/Services/StockAdjustmentService.php:115-228,253-370`). The buffer and `ReturnScrapWriteOffService` support both paths (`apps/api/app/Modules/Inventory/Application/Services/InventoryGlPostingBuffer.php:39-67`; `apps/api/app/Modules/Inventory/Application/Services/ReturnScrapWriteOffService.php:135-189`). WAC remains untouched; scale-4 strings and bcmath comply with `docs/architecture/precision-contract.md:18-45`.
- Stored events are replay-sufficient. Headers carry document identity, state, actor, idempotency/hash, and freight; line events carry snapshots, counters, lot grain, and movement IDs (`docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-8.md:184-204`). Receipt ID plus aggregate versions supplies the anchor. Existing events remain immutable; stored writes are transactional; integration events dispatch after commit. Queued notification tenancy is configured at `apps/api/config/tenancy.php:38-43`.
- Header-first locking, company-wide key uniqueness, payload hashing, reserved `sys:` keys, and sorted product locks cover concurrent receivers and replay races. The order matches `apps/api/app/Modules/Inventory/Application/Services/StockTransferService.php:317-420,734-742`.
- New routes inherit rule-12 middleware and the Inventory module gate from `apps/api/app/Modules/Inventory/Presentation/routes.php:31`. D1 remains necessary because `inventory.view` is broadly seeded, while reconcile/close are supervisory and independently grantable (`apps/api/database/seeders/RolesAndPermissionsSeeder.php:565-602,702,741,771,806`).
- Keeping `/complete` does not violate one-surface-per-concept when it delegates to the sole receipt writer (`docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-8.md:82-84,357-358`). Generated DTOs remove the existing entity shadow types as required by `docs/conventions/11-ONE-SURFACE-PER-CONCEPT.md:44-47`.
- Convention coverage is otherwise adequate: at least fifteen benchmark decisions at REV-8 lines 36-60; glossary/canonical-writer rows at 78-90; second-company, second-location, rerun, receive, both closes, complete, settings writers, migration, and backfill cases at 485-499.
- Migrations are additive and self-guarding, use string status rather than a database enum, contain valid PostgreSQL checks/partial indexes, and define idempotent backfill behavior (`docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-8.md:92-169,582-588`). No enum-column ALTER is needed.
- D1, D2, OQ-1, OQ-2, OQ-3, freight OQ-4, multi-receiver attribution, and the accountant seed ruling are closed at `docs/handoff/OWNER-QUESTIONS-consolidated-2026-09-06.md:98-106` and correctly applied at REV-8 line 605.

## Preserve

The fix round must not change:

- Odoo-style open remainder, hard over-receipt refusal, and coarse `partially_received`.
- `return_to_source` with source stock restoration and no GL.
- Company-level default-off blind receiving; transfers first, PO receipts later.
- Destination membership with no receiver assignment.
- DB notifications and web bell first.
- Quantity-based remainder in exactly three aggregate readers, completed-row backfill, and cancelled exclusion.
- Scale-4 decimal strings, `QuantityScale`, bcmath, decimal storage/casts, and no floats.
- Immutable existing events plus stored receipt headers and per-line receiver/closer facts.
- Separate full/receiver builders and generated list/show unions.
- Reconcile and close permissions seeded to manager/admin and independently grantable; Close requires both under the applied default.
- One receipt writer, `/complete` delegation, no cancellation after partial receipt, and no blind quantity-less completion.
- Confirmed freight residual/no-journal behavior, multi-receiver attribution, replenishment-note masking, reserved `sys:` keys, and best-effort cache convergence.
- Accepted R1 probing and R2 terminal history. B1’s fix must cover carrying transfers without erasing legitimate terminal history.

## Owner decisions required

These remain intentionally open, have complete defaults, and do not block acceptance by themselves:

1. **OD-1:** retain `BLIND_REQUIRES_COUNTED_RECEIPT` for blind quantity-less `/complete` (`docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-8.md:601`).
2. **Close authority:** retain both reconcile and close permissions (`docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-8.md:602`).
3. **OD-4:** retain server-side activation at commit with advisory client convergence (`docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-8.md:603`).

No additional owner question is needed. B1 and M1 are answerable from the existing guarantee and applied default.

VERDICT: CHANGES-REQUIRED