# Codex spec gate r5 — T-2/T-3 (gpt-5.6-sol, high, read-only, 2026-09-09)

Reviewed rev 5 against repository HEAD `350da33a16a0d25b797a6271eec2847c138c5fc4`. No files were modified.

## Rev-4 closure table

| Rev-4 finding | Rev-5 disposition |
|---|---|
| r4-B1 / carried B3 — first-successful-response cache guarantee | **CLOSED** at `docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-5.md:11-12,217-224,234,410,428,438,473-474`. Rev 5 correctly withdraws the impossible client guarantee and makes server-side omission authoritative. The new broken best-effort web invalidation recipe is M3; it does not revive the withdrawn guarantee. |
| r4-M1 / carried M1 — complete-only receiver cannot reach list/show | **CLOSED** at `docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-5.md:13-14,230,326-343,483`. The API and web routes now use the existing any-of permission pattern for reads while receipt posting stays under `complete`. |
| r4-M2 / carried M2 — impossible post-commit migration repair | **CLOSED** at `docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-5.md:15-16,102-108,456,493`. Recovery is correctly limited to unlogged rollback; post-commit drift requires a forward migration. |
| r4-M3 — `visibility_version` concurrency | **CLOSED** at `docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-5.md:17,219-220,453-454,484-485`. Insert-or-ignore, row locking and `UPDATE … RETURNING` provide serialized versions. |
| r4-M4 — reconcile permission collapsed with destructive close | **CLOSED** at `docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-5.md:18-19,294-300,326-343,483,505`. Rev 5 restores a separate grantable close permission and defaults to requiring both permissions. Final product confirmation remains an allowed owner decision. |
| r4-M5 — notification timestamps | **CLOSED** at `docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-5.md:20,394-400,475`. Both timestamps and API/bell assertions are specified. |
| r4-M6 / OQ-4 / OD-2 — confirmed freight and attribution reopened | **CLOSED** at `docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-5.md:21-22,197-215,375-388,508`. They are applied rulings, not open questions. |
| Carried multi-lot movement identity | **CLOSED** at `docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-5.md:122-124,188-193,361,464,480`. Lot rows are authoritative and parent movement IDs are null. |
| Carried discrepancy reason versus movement reason | **CLOSED** at `docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-5.md:132-134,290,352-361,464-468`. Damage and write-off reasons derive from the action. |
| Carried notification duplicate-delivery issue | **CLOSED** at `docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-5.md:396-400,475`. |
| Carried second-of-everything matrix | **CLOSED** at `docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-5.md:443-456`. |
| Carried generated-type comment / Eloquent-root / company-id unique findings | **CLOSED** at `docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-5.md:64,120-124,151-157,251,307-310,407,474`. |
| Citation audit | **NOT CLOSED.** The current HEAD claim is stale, the owner range remains over-inclusive, and one notification anchor points to `read_at` rather than `created_at`; see Citation audit. |
| OD-1 | **NOT CLOSED**, intentionally owner-open at `docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-5.md:504`. |
| Close authority | **NOT CLOSED as an owner decision**, although the safe default and implementation contract are complete at `docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-5.md:505`. |
| OD-4 activation semantics | **NOT CLOSED**, intentionally owner-open at `docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-5.md:506`. |
| Round-1 rejection of `return_to_source` | **REJECTED-correctly** at `docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-5.md:59,300,359,372-373,466,508`. Return-to-source is included with stock movement and no GL. |
| D1, D2, OQ-1, OQ-2, OQ-3 and stored per-line facts | **CLOSED** at `docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-5.md:174-215,275-315,508`. |

## BLOCKER

None.

## MAJOR

### M1. Receipt validation permits payloads that contradict the receipt schema

The request rules require only a positive total across the whole request and do not require unique transfer-line or batch IDs (`docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-5.md:277-290`). The schema separately requires:

- One row per `(receipt_id, transfer_line_id)`.
- Each receipt line’s four quantities to sum to more than zero.
- One lot row per `(receipt_line_id, batch_allocation_id)`.

Those constraints are at `docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-5.md:122-124`.

Consequences:

- One positive line plus one all-zero line passes rule 5, then the zero line violates the PostgreSQL CHECK.
- A repeated `transfer_line_id` or repeated lot allocation reaches a unique violation.
- Rule 2 catches `UniqueConstraintViolationException` as an idempotency-key race, but these violations have no committed receipt to reread.

Specify request-level uniqueness and require every submitted line to satisfy `received + damaged > 0`; either reject zero lot rows or remove them deterministically before hashing and writing. Add tests asserting a controlled 422 and zero rows/movements/events.

### M2. T9 is not a valid executable blind-leak oracle

The T9 user lacks `inventory.view`, yet T9 includes stock matrix, stock movements and entry/exit notes (`docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-5.md:473`). Those endpoints require `inventory.view` at `apps/api/app/Modules/Inventory/Presentation/routes.php:70-84`; the fixture therefore audits authorization errors, not their payload shapes.

Granting `inventory.view` exposes a second contradiction: the spec explicitly allows those surfaces to show the actor’s own posted quantities (`docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-5.md:272`), and the actual shapes use `quantity`, `quantity_before` and `quantity_after` at:

- `apps/api/app/Modules/Inventory/Presentation/Controllers/StockMovementController.php:208-221`
- `apps/api/app/Modules/Inventory/Presentation/Controllers/EntryExitNoteController.php:241-253`

T9 nevertheless forbids a `quantity` key anywhere.

Its requirement that “every payload” carry `visibility_version` is also incompatible with the defined carriage list at `docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-5.md:221`: 403s, 422s, movements and entry/exit notes do not carry it (`docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-5.md:264-272`).

Split T9 into surface-specific assertions, grant the permissions needed to reach positive read shapes, and apply the version assertion only to the enumerated version-bearing surfaces.

### M3. The web visibility-version cache removal cannot match real cache keys

Rev 5 removes queries using `tenantScopedKey(['stock-transfers'])` and equivalent matrix/replenishment roots (`docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-5.md:410,422`).

But `tenantScopedKey` appends tenant/company as suffixes at `apps/web/src/lib/tenantScopedKey.ts:29-34`. Real leaf keys therefore look like:

- `['stock-transfers', 'list', filters, tenant, company]`
- `['stock-transfers', 'detail', id, tenant, company]`

as defined at `apps/web/src/features/stock-transfers/api/queries.ts:11-20`.

The repository already documents that `['stock-transfers', tenant, company]` cannot prefix-match those leaves at `apps/web/src/features/stock-transfers/__tests__/queries.test.tsx:40-46`. Existing scope-aware predicates show the correct suffix-aware pattern at `apps/web/src/features/inventory/_invalidation.ts:1-20`.

This does not break the narrowed server guarantee, but the specified best-effort mechanism and its proposed test are no-ops for real leaf entries. Require a namespace-plus-scope predicate and seed actual leaf-shaped keys in the version-gate test.

### M4. The decimal-string contract omits the model changes needed to enforce it

Rev 5 requires scale-4 storage, numeric-string events and string-valued API quantities (`docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-5.md:110-124,174,390,472`) but does not require corresponding model PHPDoc, fillable fields and `decimal:4` casts.

Current affected surfaces are:

- `StockTransfer`: fillable/casts at `apps/api/app/Modules/Inventory/Domain/StockTransfer.php:60-95`; `freight_uncapitalized` is absent.
- `StockTransferLine`: fillable/casts at `apps/api/app/Modules/Inventory/Domain/StockTransferLine.php:42-62`; all four counters are absent.
- Batch allocation: fillable/casts at `apps/api/app/Modules/Inventory/Domain/StockTransferLineBatchAllocation.php:35-51`; all four counters are absent.
- The three new receipt models are not yet defined at all.

The precision contract requires an Eloquent decimal cast on every quantity/money property at `docs/architecture/precision-contract.md:18-20`. PostgreSQL may return numerics as strings, but SQLite can return numeric affinity values, so relying on the driver is not portable.

Enumerate model PHPDoc/fillable/casts for every new decimal property and require DTO/builders to preserve those strings.

## MINOR

### m1. “Replace the whole transfer types file” deletes request and query types that have no generated replacement

Rev 5 names generated response/domain DTOs and says the entire local file is replaced (`docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-5.md:407`).

The same file also owns non-shadow transport types:

- `CreateStockTransferInput` at `apps/web/src/features/stock-transfers/types/index.ts:74-95`
- `StockTransferListFilters` at `apps/web/src/features/stock-transfers/types/index.ts:97-103`
- `StockTransferListResponse` at `apps/web/src/features/stock-transfers/types/index.ts:105-108`

They are consumed by the create page and API hooks. Clarify that generated DTOs replace only backend-owned entity projections, while request/filter types remain in a request/query-only module—or add generated equivalents.

### m2. The pattern query counts receipt-line postings, not distinct receiver/line pairs

The prose describes line-level receiver metrics and says a shared line counts once for each receiver (`docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-5.md:197-198`). The SQL uses one row per stored line event and `COUNT(*)` (`docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-5.md:203-213`).

Repeated partial receipts by the same receiver on the same transfer line therefore inflate `lines_posted`, damage-rate weighting and `lines_later_written_off`. T16 covers only one posting per line (`docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-5.md:481`).

Define the metric as either postings or distinct receiver/line pairs. If it is line-based as currently named, aggregate `receipts` by company/user/line before calculating rates and add a repeated-partial-receipt fixture.

## Blind response-shape audit

| Receiver-reachable shape | Result |
|---|---|
| Receiver view, list and show | **Wire-safe.** The receiver builder omits sent/remainder values, sender notes, costs, receipt history belonging to others and batch-allocation quantities (`docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-5.md:261-263,307-310`). Lot identity alone does not reveal allocation quantity. |
| Receive 201 | **Safe.** It exposes only quantities the actor submitted plus a gated transfer projection. |
| Complete | **Safe.** Visibility is checked before receipt replay, so a blind actor receives static `BLIND_REQUIRES_COUNTED_RECEIPT` and cannot replay a full receipt (`docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-5.md:320-321`). |
| Close | **Safe.** Both reconcile visibility and close authority are required. |
| 403/422 envelopes | **Quantity-safe.** New messages are static and details contain IDs only. `INVALID_TRANSFER_STATE` can expose `partially_received`, but that coarse status is owner-accepted. |
| Stock matrix and POS incoming feeds | **Wire-safe.** Transfer incoming becomes omitted or null rather than zero; PO incoming remains independently visible. |
| Replenishment feeds | **Wire-safe.** Transfer-linked requested/suggested quantities are null while identity/status remain. |
| Stock movements and entry/exit notes | **Safe after the specified location fix.** They expose only movements already produced by the actor’s receipt; T9’s generic key prohibition must be corrected. |
| Notifications | **Safe.** Counts, identities, status/disposition and deep links only. |
| Generated web/mobile receiver types | **Contract-safe.** Expected fields are absent. Runtime safety still comes from the backend literal and recursive key scan, not comments in generated types. |
| Create/cancel | **No missed leak.** Creation requires source access; cancellation also requires source access at `apps/api/app/Modules/Inventory/Presentation/Controllers/StockTransferController.php:238-276`. Source access satisfies `canSeeExpected`. A destination-only receiver cannot cancel. |
| Web cache | **Server guarantee preserved, best-effort recipe broken.** See M3. |
| POS/mobile caches | **Consistent with accepted R4.** Cached pre-flip data may remain until the next successful pull/sync. |

This follows the actual counting precedent: omission is implemented in backend array literals at `apps/api/app/Modules/Inventory/Presentation/Controllers/InventoryCountingController.php:334-377` and `apps/api/app/Modules/Inventory/Presentation/Controllers/CountingItemController.php:54-74,161-177`. The frontend `apps/web/src/features/inventory-counting/api/countingApi.ts:1-150` has no NEVER-INCLUDE contract; rev 5’s backend key-scan approach is the stronger guard.

## Citation audit

| Claim | Result and real line |
|---|---|
| Baseline HEAD at rev-5 line 5 | **WRONG/stale.** Current HEAD is `350da33a16a0d25b797a6271eec2847c138c5fc4`, not `8c26bdb…`. `git diff --name-only 8c26bdb…HEAD` currently lists rev 5 plus `docs/superpowers/plans/2026-09-06-WLOTA-1a-permissions-roles-web-rev-6.md`; no production file changed. |
| Owner register `:98-104` at rev-5 lines 3 and 26 | **OVER-INCLUSIVE.** T-2/T-3’s D1/D2/OQ-1/OQ-2/OQ-3/pattern rulings are exactly `docs/handoff/OWNER-QUESTIONS-consolidated-2026-09-06.md:98-103`. Line 104 is the then-open PO-revert item. Its later resolution, freight and attribution are at line 106. |
| Nullable `created_at` reaches the API at `NotificationController.php:20,34` in rev-5 line 398 | **WRONG line.** Line 34 emits `read_at`; `created_at` is emitted at `apps/api/app/Modules/Notification/Presentation/Controllers/NotificationController.php:35`. Ordering remains correctly anchored at line 20. |
| Transfer lifecycle, current response fields and authorization | **VERIFIED** at `apps/api/app/Modules/Inventory/Application/Services/StockTransferService.php:317-505,514-608,631-752`, `apps/api/app/Modules/Inventory/Presentation/Controllers/StockTransferController.php:32-118,205-341,347-402`, and `apps/api/app/Modules/Inventory/Presentation/routes.php:98-117`. |
| Transfer/line/allocation schema and status capacity | **VERIFIED** at `apps/api/database/migrations/tenant/2026_05_28_120000_create_stock_transfers_table.php:29-99` and `apps/api/database/migrations/tenant/2026_06_05_121000_create_stock_transfer_line_batch_allocations_table.php:14-30`. |
| Exactly three remainder readers | **VERIFIED.** Repository search found only `LocationStockQueryService.php:137-164,217-245`, `StockMatrixQueryService.php:390-408`, and `WeightedAverageCostService.php:100-127`. Other matches are lifecycle writes/checks or initiation-time replenishment settlement. |
| Completed-row backfill preserves historical reader answers | **VERIFIED.** Completed rows were excluded before and remain excluded; setting completed counters equal to sent makes their remainder zero. In-transit/cancelled rows remain untouched. |
| Stored-event repository, aggregate UUID/version and atomic persistence | **VERIFIED** at the cited Spatie repository/subscriber and `stored_events` migration anchors. No projectors/reactors exist under `apps/api/app/` today. |
| Movement reasons and GL families | **VERIFIED.** `Damage` and `WriteOff` exist at `apps/api/app/Modules/Inventory/Domain/Enums/MovementReason.php:26-28`, map to shrinkage at lines 73-93 and require GL at lines 96-112; `TransferIn` maps to neither and requires no GL. |
| Stock↔GL bridge and WAC behavior | **VERIFIED** at `apps/api/app/Modules/Inventory/Domain/Services/StockAdjustmentService.php:115-129,253-267`, `apps/api/app/Modules/Inventory/Application/Services/InventoryGlPostingService.php:89-207`, and `apps/api/app/Modules/POS/Application/Services/ReturnScrapWriteOffService.php:135-189`. Receive/issue do not change WAC; freight capitalization remains the separate WAC path. |
| Mobile anchors and HEAD | **VERIFIED.** Mobile remains at `51e3445`; the cited expected-quantity and pending-idempotency locations match. |
| Remaining rev-5 `path:line` expressions | **VERIFIED.** They resolve to the stated current-code fact or are explicitly labelled edit targets. The three discrepancies above are the complete wrong/stale citation list found in rev 5. |

## Rejected false positives

- There is no missed fourth in-transit aggregate reader. UI status checks and `StockTransferService.php:441,602` are lifecycle consumers, not remainder readers.
- The completed-transfer backfill does not change historical totals.
- `partially_received` is not a prohibited leak; the owner accepted that coarse signal at `docs/handoff/OWNER-QUESTIONS-consolidated-2026-09-06.md:102`.
- Land-then-issue is correct: TransferIn creates destination stock and Damage/WriteOff removes the discrepant portion, yielding one shrinkage GL effect.
- `return_to_source` without GL is correct and owner-ruled.
- The GL buffer is retry-safe: the root rollback listener clears pending contexts at `apps/api/app/Modules/Inventory/Providers/InventoryServiceProvider.php:98-116`.
- Keeping `complete` as a delegate does not introduce a second receipt writer.
- Calling stored-event `handle()` does not currently dispatch an early queued projector: there are no projectors/reactors for these events today. The plain notification event is explicitly after-commit.
- The close CTE’s lack of `company_id` does not itself cross companies because transfer-line UUIDs are primary keys. The separate repeated-line metric ambiguity is m2.

## Preserve

- Odoo-style partial receipt with open remainder and hard over-receipt refusal.
- Company-level, default-off blind receiving; transfers first, PO receiving later.
- Destination membership with no receiver assignment.
- DB notifications and web bell only.
- `return_to_source` with stock movement and no GL.
- Exactly three quantity-based remainder readers, cancelled exclusion and completed-row backfill.
- Scale-4 storage, decimal strings, `QuantityScale`, bcmath and no floats.
- Immutable existing events plus stored header and per-line receipt facts with actor identity.
- Separate full/receiver builders with omission by construction.
- Reconcile seeded to manager and admin and grantable independently.
- One receipt writer; no cancel after partial receipt; no quantity-less completion by a blind receiver.
- Confirmed freight residual/no-journal and multi-receiver attribution decisions.

## Owner decisions required

1. **OD-1:** confirm that quantity-less `complete` remains unavailable whenever `canSeeExpected()` is false (`docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-5.md:504`).

2. **Close authority:** confirm the safe default requiring both `inventory.transfers.reconcile` and `inventory.transfers.close` (`docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-5.md:505`).

3. **OD-4:** confirm immediate server-side activation with advisory, eventually convergent client cache invalidation (`docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-5.md:506`).

VERDICT: CHANGES-REQUIRED
