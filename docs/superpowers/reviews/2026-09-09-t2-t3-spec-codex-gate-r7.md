<!-- Recovered verbatim from Codex rollout ~/.codex/sessions/2026/09/09/rollout-2026-09-09T15-11-41-01a08682-9b68-7033-9482-c94d96a00c8c.jsonl after the 2026-09-09 laptop reboot wiped the scratchpad -o file. Run completed 14:29Z. -->
# Codex spec gate r7 — T-2/T-3 (gpt-5.6-sol, high, read-only, 2026-09-09)

Reviewed REV 7 against repository HEAD `c76435df4a98193dfacb80ad9c25839169179509`. No files were modified.

## Rev-6 closure table

| Rev-6 finding | Rev-7 disposition |
|---|---|
| r6-M1 — transfer-linked replenishment notes leak quantities | **CLOSED** at `docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-7.md:248,268-269,285,381-382,568-569`. Transfer-linked `requested_qty`, `suggested_qty`, and `note` are null together; T9 supplies another user’s quantity-bearing note. |
| r6-M2 — clients can reserve the synthetic completion key | **CLOSED** at `docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-7.md:132,178,294,313,356-357,504,508,584`. Client requests reject the case-insensitive reserved `sys:` namespace before lookup. |
| r6-M3 — T9 positive controls demand impossible sentinel sets | **CLOSED** at `docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-7.md:532-546,551,574-575`. Table P now assigns each surface only values that surface can emit. A separate reachability defect remains as new M1 below. |
| r6-m1 — settings initialization writer omitted from the version invariant | **CLOSED** at `docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-7.md:180,233-234,491`. |
| r6-m2 — SQLite interrupted-backfill convergence was overclaimed | **CLOSED** at `docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-7.md:98-104,513,584`. Crash recovery is now explicitly PostgreSQL-only. |
| r6-m3 — receipt-lot `batch_id` was too narrow | **CLOSED** at `docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-7.md:136`. The design now uses `unsignedBigInteger`, matching `product_batches.id` and the existing allocation FK at `apps/api/database/migrations/tenant/2026_01_05_150000_create_product_batches_table.php:14` and `apps/api/database/migrations/tenant/2026_06_05_121000_create_stock_transfer_line_batch_allocations_table.php:24`. |
| r6-m4 — backfill lot grain derived from the mutable product flag | **CLOSED** at `docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-7.md:513,584`; allocations are authoritative. |
| r6 citation — stale HEAD | **NOT CLOSED.** REV 7 names `85a455605…` as HEAD at `docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-7.md:5`; actual HEAD is `c76435df4a98193dfacb80ad9c25839169179509`. Production code is unchanged between those commits. |
| r6 citation — incomplete TanStack range | **CLOSED** at `docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-7.md:446`; positional recursion is correctly cited at `node_modules/.pnpm/@tanstack+query-core@5.90.11/node_modules/@tanstack/query-core/build/modern/utils.js:94-104`. |
| r6 citations — GL buffer nested return and outside-transaction throw | **CLOSED** at `docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-7.md:402`, matching `apps/api/app/Modules/Inventory/Application/Services/InventoryGlPostingBuffer.php:56-67`. |
| r5-M2 carried by r6 — executable T9 positive control | **CLOSED for the per-surface sentinel problem** at `docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-7.md:532-575`; new M1 is a distinct authorization/setup defect. |
| Round-1 rejection of `return_to_source` | **REJECTED-correctly.** The owner-ratified stock-only/no-GL path remains at `docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-7.md:330-336,395,408-409,503`. |
| OD-1 | **NOT CLOSED intentionally**, with a complete non-blocking default at `docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-7.md:595`. |
| Close authority | **NOT CLOSED intentionally**, with a complete non-blocking default at `docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-7.md:596`. |
| OD-4 | **NOT CLOSED intentionally**, with a complete non-blocking default at `docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-7.md:597`. |

## BLOCKER

None.

## MAJOR

### M1. T9 still cannot reach the POS stock-distribution surface

The T9 fixture grants `U_blind` `pos.operate_terminal` but not `pos.view_cross_location_stock`, and it does not enable the company’s cross-location-stock switch (`docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-7.md:549`).

The real endpoint first requires `pos.view_cross_location_stock`, then refuses access unless `allow_cross_location_stock_view` is true (`apps/api/app/Modules/POS/Presentation/Controllers/StockDistributionController.php:35-41`). That company setting defaults false (`apps/api/database/migrations/tenant/2026_06_14_100000_add_allow_cross_location_stock_view_to_companies.php:13-15`).

Consequently steps 6j, 7, and 8 cannot receive the promised 200 payloads (`docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-7.md:567,574-575`). They receive 403, so neither the blind assertion nor the positive-control assertion exercises this response shape.

Add `pos.view_cross_location_stock` to both applicable actors and set `allow_cross_location_stock_view = true` through the fixture’s normal company setup.

### M2. The web list/show types exclude the receiver projection those endpoints return

REV 7 says list and show select `TransferReceiverPayloadBuilder` for a complete-only blind receiver (`docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-7.md:253,275-276`). That projection uses nested `source_location`/`destination_location` and omits cost, creation, and sender fields.

The web contract nevertheless defines `StockTransferListResponse.data` as `StockTransferData[]`, not `(StockTransferData | TransferReceiverViewData)[]` (`docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-7.md:443`). The same section specifies a union only for the receive mutation, not list/show.

Today the list unconditionally reads full-shape fields such as `source_location_name`, `destination_location_name`, `transfer_cost`, and `created_at` (`apps/web/src/features/stock-transfers/pages/StockTransferListPage.tsx:114-137`). The detail summary likewise unconditionally reads full-only fields (`apps/web/src/features/stock-transfers/pages/StockTransferDetailPage.tsx:124-152`). The current API also types show as a single full transfer (`apps/web/src/features/stock-transfers/api/stockTransferApi.ts:37-38`).

Specify generated unions for list and show, require both pages to narrow on `blind`, and test them using the actual receiver-shaped payload. Otherwise the newly authorized complete-only route is either falsely typed or renders missing fields.

### M3. An independently granted reconcile permission cannot reach its canonical web surface

Reconciliation’s canonical operator surface is the transfer detail page (`docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-7.md:349-351`), and the permission is explicitly grantable independently (`docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-7.md:362-366`).

However:

- Backend list/show accept only `inventory.transfers.view` or `inventory.transfers.complete`, excluding reconcile (`docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-7.md:370-372`).
- The web list/detail guards use that same two-permission set (`docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-7.md:444`).
- The reconciliation tab is nested inside that inaccessible detail page (`docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-7.md:447`).
- Discrepancy notifications go to reconcile holders but deep-link to `/inventory/stock-transfers/{id}` (`docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-7.md:436`). The web bell navigates directly to that link (`apps/web/src/features/notifications/components/NotificationPanel.tsx:85-98`).

Thus the reconcile-only actor intentionally tested at `docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-7.md:520` can call the reconciliation API but cannot open its declared UI or its notification. A reconcile+close user without view/complete also cannot reach the Close action.

Include `inventory.transfers.reconcile` in the backend and web list/show read gates and extend T18 plus the web route test. This is safe for blind receiving because reconcile is already the authority that makes `canSeeExpected()` true.

### Blind response-shape audit

| Receiver-reachable shape | Code-derived result |
|---|---|
| Receiver view | Safe by construction: no sent/remaining/cost/sender note or allocation quantity; lot identity only (`docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-7.md:275,343-346`). |
| List and show | Server projection is quantity-safe, including batch allocations and other users’ receipts, but the web contract is broken by M2. |
| Receive 201 | Safe: the receipt echoes the actor’s submitted received/damaged quantities, while hidden snapshots and movement IDs are excluded (`docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-7.md:277,328,346`). |
| Complete | Safe: visibility is checked before replay; a blind actor gets a static 422 (`docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-7.md:255,356-357`). |
| Close | Safe: reconcile and close are required before the service; the remaining issue is M3’s route reachability for authorized reconcilers (`docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-7.md:256,330-336`). |
| Typed 422s | Safe: static messages and ID-only details (`docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-7.md:281,314-326`). |
| Framework validation 422s | Quantity-safe because validation is structural and does not read the transfer. The exact message-shape description is inaccurate; see Citation audit. |
| `partially_received` | Reveals only the owner-accepted coarse status, not a number (`docs/handoff/OWNER-QUESTIONS-consolidated-2026-09-06.md:102`). |
| Stock matrix | Safe when masked: PO incoming remains, transfer incoming is omitted (`docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-7.md:258,283`). |
| POS stock levels/distribution | Defined safely as nullable masked transfer incoming. T9 does not currently prove distribution because of M1 (`docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-7.md:259-260,284`). |
| Web/POS replenishment | The r6 note leak is closed: transfer-linked quantities and arbitrary notes are null together (`docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-7.md:268-269,285,381-382`). |
| Movements/entry-exit notes | They expose posted destination movement quantities, not pre-receipt expectations; entry/exit notes become location-scoped (`docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-7.md:261-262,287`). |
| Notifications | Quantity-safe: counts and identities only. The reconcile-only deep-link problem is M3 (`docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-7.md:286,435-437`). |
| Generated web types | The receiver DTO itself is safe, but list/show incorrectly claim the full DTO only; M2. |
| Mobile types | Safe direction: transfer receiver types are separated from the existing PO supervisor shapes (`docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-7.md:471-475`). |
| Client caches | Matches accepted OD-4: server responses are masked immediately; legitimately fetched pre-flip data may persist until the next successful pull (`docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-7.md:231-238`). |

## MINOR

### m1. The pattern query ignores returned shortages

REV 7 defines both write-off and return-to-source close outcomes as confirmed shortages (`docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-7.md:69-70`) and stores `quantityReturned` in the line event (`docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-7.md:200-201`).

The reference query nevertheless treats a line as supervisor-confirmed only when `quantityWrittenOff > 0` (`docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-7.md:219-225`). Either include positive `quantityReturned` in the close outcome or name the metric strictly as later-written-off lines. The stored event fields themselves are sufficient, so the owner’s event-storage requirement remains satisfied.

### m2. Empty `details` has two incompatible JSON contracts

The response-shape table specifies `details: {}` for `BLIND_REQUIRES_COUNTED_RECEIPT` (`docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-7.md:278`), while T9 requires `error.details == []` (`docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-7.md:561`). Choose one wire representation and use it everywhere.

### m3. `LOT_TRACKING_MISMATCH` retains a stale “submitted flag” definition

The failure enum description says submitted `is_lot_tracked` is compared with the product flag (`docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-7.md:145`). The request intentionally has no such field; the service derives shipment grain from allocations (`docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-7.md:304`), and B8 compares that grain with the current product flag (`docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-7.md:321`). Rewrite the enum description to match B8.

### m4. The validation-envelope description omits Laravel’s summary suffix

REV 7 calls `error.message` “the first field message” (`docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-7.md:282`). Laravel starts with the first message but appends `(and N more errors)` when more exist (`apps/api/vendor/laravel/framework/src/Illuminate/Validation/ValidationException.php:85-101`).

This remains quantity-safe, but T5’s claim that the only digit permitted in framework messages is the fixed decimal ceiling is incompatible with a multi-error response (`docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-7.md:504`).

## Citation audit

| Claim | Result and real line |
|---|---|
| Baseline says current HEAD is `85a455605…` at rev-7 line 5 | **WRONG/stale.** Actual HEAD is `c76435df4a98193dfacb80ad9c25839169179509`. The intervening changes are documentation-only; production anchors remain unchanged. |
| Framework validation `error.message` is exactly the first field message at rev-7 line 282 | **WRONG.** `ValidationException::summarize()` uses the first message and appends a count suffix when additional messages exist at `apps/api/vendor/laravel/framework/src/Illuminate/Validation/ValidationException.php:85-101`. |
| T9’s distribution endpoint is reachable with the user defined at rev-7 line 549 | **WRONG.** The real endpoint requires `pos.view_cross_location_stock` and an enabled company switch at `apps/api/app/Modules/POS/Presentation/Controllers/StockDistributionController.php:35-41`; the switch defaults false at `apps/api/database/migrations/tenant/2026_06_14_100000_add_allow_cross_location_stock_view_to_companies.php:13-15`. |
| Round-6 TanStack correction | **VERIFIED** at `node_modules/.pnpm/@tanstack+query-core@5.90.11/node_modules/@tanstack/query-core/build/modern/utils.js:94-104`; recursive positional matching is lines 101-103. |
| Round-6 GL-buffer corrections | **VERIFIED** at `apps/api/app/Modules/Inventory/Application/Services/InventoryGlPostingBuffer.php:56-67`. |
| Exactly three status-based in-transit aggregate readers | **VERIFIED** at `apps/api/app/Modules/Inventory/Application/Services/LocationStockQueryService.php:137-164,217-245`, `apps/api/app/Modules/Inventory/Application/Services/StockMatrixQueryService.php:390-408`, and `apps/api/app/Modules/Inventory/Application/Services/WeightedAverageCostService.php:100-127`. |
| Current transfer lifecycle and canonical lock ordering | **VERIFIED** at `apps/api/app/Modules/Inventory/Application/Services/StockTransferService.php:317-420,734-742`. |
| Damage/WriteOff reasons and GL family | **VERIFIED**: both exist and map to Shrinkage/GL at `apps/api/app/Modules/Inventory/Domain/Enums/MovementReason.php:26-28,68-112`; TransferIn is Neither/no-GL. |
| Counting blind precedent | **VERIFIED** at `apps/api/app/Modules/Inventory/Presentation/Controllers/InventoryCountingController.php:334-377` and `apps/api/app/Modules/Inventory/Presentation/Controllers/CountingItemController.php:54-74,161-177`. |
| Permission seeding facts | **VERIFIED**: the current transfer block is `apps/api/database/seeders/RolesAndPermissionsSeeder.php:196-200`, manager grants are at `apps/api/database/seeders/RolesAndPermissionsSeeder.php:585-602`, and admin receives all permissions at `apps/api/database/seeders/RolesAndPermissionsSeeder.php:565-582`. |
| Remaining REV-7 `path:line` citations | **VERIFIED.** Every cited file/range exists; no missing or out-of-range citation was found. The stale/incorrect claims above are the complete discrepancy list. |

## Rejected false positives

- No fourth in-transit aggregate reader was found. `StockRebalanceQueryService` reads only on-hand/reserved stock (`apps/api/app/Modules/Inventory/Application/Services/StockRebalanceQueryService.php:18-49`); other transfer matches are lifecycle, internal line access, seed/demo code, or UI status handling.
- The completed-transfer backfill preserves historical reader answers. Completed rows were excluded by the old status predicates and remain excluded by `CARRYING_STATUSES`; setting counters equal to sent also yields zero remainder. In-transit and cancelled rows remain untouched (`docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-7.md:163-169,513,584`).
- The new status strings fit `string(20)`: `closed_with_writeoff` is exactly 20 characters, and the current column is length 20 (`apps/api/database/migrations/tenant/2026_05_28_120000_create_stock_transfers_table.php:41-42`).
- All current exhaustive `TransferStatus` consumers are named for modification: the PHP enum match, the web badge `Record`, list filters, and detail actions (`apps/api/app/Modules/Inventory/Domain/Enums/TransferStatus.php:17-49`; `apps/web/src/features/stock-transfers/components/StockTransferStatusBadge.tsx:5-10`; `apps/web/src/features/stock-transfers/pages/StockTransferListPage.tsx:15-24`; `apps/web/src/features/stock-transfers/pages/StockTransferDetailPage.tsx:38-39`).
- TransferIn followed by Damage/WriteOff is supported. `receive()` and `issue()` preserve string quantities and movement identity (`apps/api/app/Modules/Inventory/Domain/Services/StockAdjustmentService.php:115-207,253-350`); Damage/WriteOff use shrinkage, while TransferIn requires no GL.
- The GL bridge is viable for both lot-less and lot paths. Exit posting resolves the shrinkage counter at `apps/api/app/Modules/Inventory/Application/Services/InventoryGlPostingService.php:130-207`; batch write-off requires its batch/product context at `apps/api/app/Modules/Inventory/Application/Services/InventoryGlPostingService.php:89-127`.
- The stored-event mechanism supplies a real receipt aggregate anchor and versions. Direct repository persistence writes the supplied aggregate UUID and event version at `apps/api/vendor/spatie/laravel-event-sourcing/src/StoredEvents/Repositories/EloquentStoredEventRepository.php:101-138`; existing transfer events remain untouched.
- Using general `StockTransferClosedV1` instead of a write-off-only class is acceptable: the event carries the explicit disposition and covers both owner-approved close outcomes without modifying an existing event (`docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-7.md:196-201`).
- Receipt and event writes are atomic; plain listener dispatch occurs after commit. Queued notification payloads carry explicit tenant/company/actor data, and recipients are resolved before the worker (`docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-7.md:192-205,430-436`).
- The reserved `sys:` namespace closes client pre-emption without narrowing the published client-key shape. Header-first locking and sorted product advisory locking match the existing order at `apps/api/app/Modules/Inventory/Application/Services/StockTransferService.php:317-339,734-742`.
- The new Inventory routes inherit the full rule-12 middleware set and module gate from `apps/api/app/Modules/Inventory/Presentation/routes.php:31`.
- D1 remains necessary: `inventory.view` is broadly seeded to non-supervisory roles (`apps/api/database/seeders/RolesAndPermissionsSeeder.php:702,741,771,806`), so it cannot safely expose reconciliation.
- Keeping `complete` does not violate one-surface-per-concept if it remains only a compatibility/convenience delegate into `StockTransferReceiptService`. It does not create a second terminal writer (`docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-7.md:82-83,356-357`).
- The schema is additive; transfer status is a string rather than a database enum, so no enum-column ALTER is required. The named PostgreSQL checks are expressible over numeric columns, and the partial movement-ID unique indexes are valid.
- The benchmark table has fifteen decided rows, the glossary additions name canonical surfaces and synonyms, and the S-matrix covers receive, both close dispositions, completion, settings update/reset/initialization, backfill, and clean migration reruns (`docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-7.md:36-60,78-90,481-493`).

## Preserve

The fix round must not change:

- Odoo-style open remainder and hard over-receipt refusal.
- `return_to_source` on close, with source stock movement and no GL.
- Company-level, default-off blind receiving; transfers first, PO receiving later.
- Destination membership with no receiver assignment.
- DB notifications and the web bell only.
- Quantity-based remainder in exactly the three identified readers, completed-row backfill, and cancelled exclusion.
- Scale-4 decimal strings, `QuantityScale`, bcmath, model decimal casts, and no floats.
- Immutable existing events plus stored receipt-header and per-line facts with receiver/closer identity.
- Separate full and receiver builders with omission by construction.
- `inventory.transfers.reconcile` seeded to manager and admin and independently grantable.
- Separate `inventory.transfers.close` authority under the current owner-open default.
- One receipt writer; `complete` delegates; blind actors cannot use quantity-less completion.
- No cancellation after partial receipt.
- The confirmed freight residual/no-journal algorithm and confirmed multi-receiver attribution.
- The accepted coarse `partially_received` signal and pre-flip client-cache residual.
- The repaired replenishment-note masking and reserved `sys:` idempotency namespace.

## Owner decisions required

These three decisions remain intentionally open and do not themselves block acceptance:

1. **OD-1:** keep the default that blind actors receive `BLIND_REQUIRES_COUNTED_RECEIPT` from quantity-less `complete` (`docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-7.md:595`).
2. **Close authority:** keep the default requiring both reconcile and close permissions (`docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-7.md:596`).
3. **OD-4:** keep server-side activation at commit with advisory client convergence (`docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-7.md:597`).

VERDICT: CHANGES-REQUIRED