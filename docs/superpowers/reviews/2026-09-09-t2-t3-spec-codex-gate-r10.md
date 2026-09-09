# Codex spec gate r10 — T-2/T-3 (gpt-5.6-sol, high, read-only, 2026-09-09)

## Rev-9 closure table

| Rev-9 finding | Rev-10 disposition |
|---|---|
| r9-B1 — blind guarantee contradicted reachable current-stock positions | **CLOSED at rev-10 anchor.** The guarantee is correctly narrowed to document-derived transfer, receipt, replenishment, movement and notification surfaces, while destination stock positions and multi-receiver delta inference are explicitly accepted as R5 (`docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-10.md:244-262`). T9 now separates forbidden document sentinels from required stock-position values (`docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-10.md:597-610`). The incomplete enumeration and executable coverage described in M1 are new rev-10 defects, not a reopening of the owner ruling. |
| r9-m1 — movement queries omitted from cache convergence | **CLOSED at rev-10 anchor.** All three TanStack consumers receive `staleTime: 0` and `refetchOnWindowFocus: true`; the spec correctly explains why they are outside version-triggered cache removal (`docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-10.md:435,502,515`). |
| r9-m2 — stale baseline pin and diff inventory | **NOT CLOSED.** Rev 10 pins `8745853…` and 20 files at `docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-10.md:5`; audit-time HEAD is `c8a484d43cb6eb501b9b330297f4831227243903`, with 22 files since `85a455605` and 7 since the r9 audit HEAD. The changes remain documentation-only. |
| Carried r8-B1 — direct movement feeds disclose other receivers’ postings | **CLOSED at rev-10 anchor.** Carrying-transfer movement quantities and running balances are masked for all receivers, including the posting actor, while terminal/non-transfer rows remain visible (`docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-10.md:425-435,657-658`). |
| r8-M1/r7-M3 — reconcile-only UI and read-gate reachability | **CLOSED at rev-10 anchor.** Reconcile and close authority are separated and the complete permission/read matrix is executable in T18 (`docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-10.md:582`). |
| r8-m1/m2/m3 — `/complete` status, raw replay envelopes and POS metadata placement | **CLOSED at rev-10 anchor.** `/complete` remains 200, receive/close retain raw `meta.replayed`, and POS flags remain top-level (`docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-10.md:498-501,566`). |
| Rejection of excluding `return_to_source` | **REJECTED-correctly.** The approved return disposition restores the source and creates no GL leg (`docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-10.md:368,450,463-464`). |
| Rejection of a write-off-only stored close event | **REJECTED-correctly.** Generic `StockTransferClosedV1` carries the disposition and supports both approved close outcomes (`docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-10.md:191,193-198`). |
| Remaining verified readers, GL, events, locks, routes, one-writer and migration findings | **REJECTED-correctly / still verified.** See the checks below. |

## BLOCKER

None.

## MAJOR

### M1. R5 is ruled correctly, but the claimed complete surface inventory and executable presence oracle are incomplete

This is not a request to mask on-hand or lot stock. Those values remain visible under accepted R5. The defect is that rev 10 repeatedly claims to enumerate and test every reachable stock-position response (`docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-10.md:250,291-293,690`) but does not do so:

- The T9 actor already holds `products.view` (`docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-10.md:634`) and can call `GET /products/{product}` (`apps/api/app/Modules/Product/routes.php:58-60`). That response computes `stock_quantity` with `withSum` (`apps/api/app/Modules/Product/Presentation/Controllers/ProductController.php:304-319`) and emits the scale-4 aggregate through `ProductData` (`apps/api/app/Modules/Product/Application/DTOs/ProductData.php:131,166-183`; controller response at `apps/api/app/Modules/Product/Presentation/Controllers/ProductController.php:356-374`). It is absent from the R5 catalogue and T9.
- The batch family names five endpoints (`docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-10.md:259,288`), but the response-shape table describes only batch show, batch stock and product-batch-stock (`docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-10.md:318`). `GET /batches` also eagerly loads `batchStock` (`apps/api/app/Modules/BatchExpiry/Infrastructure/Persistence/BatchRepository.php:89-119`) and therefore emits the resource’s per-location quantities (`apps/api/app/Modules/BatchExpiry/Presentation/Resources/BatchResource.php:39-60`). The POS-batch route instead emits `suggestions[].quantity`, `shortfall` and `total_quantity_suggested` (`apps/api/app/Modules/BatchExpiry/Application/DTOs/BatchSuggestionDTO.php:27-40`; `apps/api/app/Modules/BatchExpiry/Application/DTOs/BatchSuggestionResultDTO.php:23-34`). Neither exact shape is recorded or exercised.
- Counting reconciliation is explicitly excluded from T9 (`docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-10.md:630`), despite earlier claims that T9 asserts its presence (`docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-10.md:287`).
- Rebalance presence is conditional on thresholds and may test only HTTP 200 (`docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-10.md:628,664`). That does not prove that its stock-position fields remain visible. The fixture can deterministically set thresholds so the row exists.
- T9’s batch step exercises only show, stock and product-batch-stock (`docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-10.md:665`), omitting the batch list and POS-batch response that §5.0 names.

Consequently, T9 cannot yet enforce the round-10 requirement that stock positions stay present on every recorded R5 surface. The fix is documentation and executable positive-control coverage under R5—not additional masking or authorization.

## MINOR

### m1. The baseline pin is stale again

The baseline claims audit HEAD `8745853b7a86045c157036a15129c3d22765be2f` and 20 changed files (`docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-10.md:5`). Current HEAD is `c8a484d43cb6eb501b9b330297f4831227243903`; `git diff --name-only 85a455605..HEAD` contains 22 documentation files and the r9-HEAD diff contains 7. No production file changed, so the production-code conclusions remain valid.

## Citation audit

All 560 unique line-qualified path tokens resolve to existing files and in-range lines. The following claims are wrong, stale or incomplete; the remaining citations were re-derived without another mismatch.

| Claim | Verified/wrong with the real line |
|---|---|
| Rev-10 HEAD and diff inventory | **WRONG/stale** at `docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-10.md:5`. Current HEAD is `c8a484d43cb6eb501b9b330297f4831227243903`; the rev-7 diff has 22 documentation files, not 20. |
| Stock-level R5 is tested by step 6p | **WRONG cross-reference** at `docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-10.md:284`. Step 6p is stock matrix; stock-level list/show are step 6s (`docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-10.md:624,627,663`). |
| Product stock-levels are tested by step 6q | **WRONG cross-reference** at `docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-10.md:285`. Step 6q is POS stock; product stock-levels are 6t-a (`docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-10.md:625,628,664`). |
| Rebalance presence is asserted by step 6r | **WRONG cross-reference and overstated control** at `docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-10.md:286`. Step 6r is POS distribution; rebalance is 6t-b and its field-presence assertion is conditional (`docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-10.md:626,628,664`). |
| Counting reconciliation presence is asserted by step 6s | **WRONG** at `docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-10.md:287`. Step 6s is stock-level list/show, and counting is expressly not exercised (`docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-10.md:627,630`). |
| Batch presence is asserted by steps 6t/6u | **WRONG/incomplete** at `docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-10.md:288`. Step 6t is product stock/rebalance; 6u covers only three of the five named batch endpoints (`docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-10.md:628-629,664-665`). |
| “Rev 10 lists them all” | **WRONG/incomplete** at `docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-10.md:293`. At minimum, `GET /products/{product}` is omitted even though it emits `stock_quantity` (`apps/api/app/Modules/Product/Presentation/Controllers/ProductController.php:304-319,356-374`; `apps/api/app/Modules/Product/Application/DTOs/ProductData.php:131,166-183`). |
| Batch/lot response-shape description covers the five named routes | **WRONG/incomplete** at `docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-10.md:318`. Batch list obtains `batch_stock` through eager loading (`apps/api/app/Modules/BatchExpiry/Infrastructure/Persistence/BatchRepository.php:116-119`); POS batches use the distinct suggestion envelope (`apps/api/app/Modules/BatchExpiry/Application/DTOs/BatchSuggestionResultDTO.php:23-34`). |
| Table-P row 6u names its exercised responses | **INTERNALLY STALE** at `docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-10.md:629`: its request column names two routes while its value text assumes batch show; the procedure actually makes three requests at line 665. |
| Table-P arithmetic after step 5b | **VERIFIED.** P on-hand is `2391.4517 + 5000.0000 = 7391.4517`; PB/B1 is `1017.2500`; PB remainder is `2617.2500 − 1017.2500 = 1600.0000`; P matrix incoming is the PO-only `12.0000`; total sent is `7391.4517 + 2617.2500 = 10008.7017` (`docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-10.md:610-631`). |

## Rejected false positives

- The owner’s R5 ruling is applied correctly in principle. The guarantee is documentary, current-stock delta inference is expressly accepted, §5.10 movement masking remains intact, and mobile is committed to the receiver projection by construction (`docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-10.md:244-262,425-435,531-537`).

- Receiver-view, list/show unions, receipt responses, typed 422s, notifications, generated transfer DTOs and mobile NEVER-INCLUDE contracts do not expose transfer-derived sent/remaining quantities as designed (`docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-10.md:291-320,370-377,485-492,498-515,531-537`). `partially_received` alone remains accepted R3; `NOTHING_TO_RECEIVE` is static and ID-only (`docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-10.md:303-304,340,640`).

- Exactly three status-based in-transit aggregate readers exist: two queries in `LocationStockQueryService` (`apps/api/app/Modules/Inventory/Application/Services/LocationStockQueryService.php:137-165,217-245`), stock matrix (`apps/api/app/Modules/Inventory/Application/Services/StockMatrixQueryService.php:390-408`) and WAC (`apps/api/app/Modules/Inventory/Application/Services/WeightedAverageCostService.php:117-127`). `TransferLineQueryService` reads shipment lines for initiate-time replenishment settlement, not an in-transit aggregate (`apps/api/app/Modules/Inventory/Application/Services/TransferLineQueryService.php:13-28`). Completed rows were excluded before and remain excluded; received-equals-sent makes their remainder zero.

- Damage and WriteOff exist, require the Shrinkage GL family, and work through the established inventory/GL bridge (`apps/api/app/Modules/Inventory/Domain/Enums/MovementReason.php:26-28,68-112`; `apps/api/app/Modules/Inventory/Application/Services/InventoryGlPostingService.php:89-127,150-207`; `apps/api/app/Modules/Inventory/Application/Services/InventoryGlPostingBuffer.php:39-67`). TransferIn followed by destination issue preserves WAC quantity treatment and the design retains scale-4 decimal strings and bcmath (`docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-10.md:441-481`).

- Stored header and per-line events carry replay, attribution, lot, movement and idempotency facts. Explicit `persist(..., receiptId)` supplies the missing aggregate anchor; existing stored events remain untouched; integration and movement events dispatch after commit (`docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-10.md:177-225,359`). Queued notification recipients are resolved before the worker and T10 clears `CompanyContext` (`docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-10.md:487-492,574`).

- Transfer status exhaustiveness is accounted for in PHP and web edit targets. Current enum behavior is centralized (`apps/api/app/Modules/Inventory/Domain/Enums/TransferStatus.php:17-49`), and the spec replaces web comparisons with exhaustive records (`docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-10.md:133-170,504-511`). `closed_with_writeoff` is exactly 20 characters and fits the existing `string(20)` column.

- Idempotency and concurrency remain coherent: company-wide unique key plus hash discrimination, reserved `sys:` namespace, header-first locking, sorted product locks, replay behavior and PG forced-order tests are specified (`docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-10.md:322-368,559-575`). This matches the existing header-lock path at `apps/api/app/Modules/Inventory/Application/Services/StockTransferService.php:317-420,734-742`.

- Routes inherit tenant, token, permission-team and Inventory-module middleware (`apps/api/app/Modules/Inventory/Presentation/routes.php:31,98-117`). D1 is necessary: `inventory.view` is seeded broadly (`apps/api/database/seeders/RolesAndPermissionsSeeder.php:702,741,771,806`), whereas reconcile and close are supervisory permissions planned for manager/admin and remain independently grantable (`docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-10.md:380-414,580-582`).

- Convention coverage is otherwise sound: the benchmark table has more than five decision rows, glossary additions and a single receipt writer are explicit, `/complete` delegates rather than duplicating receipt logic, generated DTOs replace entity shadows, and the S-matrix covers second company, second location and rerun for every writer (`docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-10.md:33-83,543-555`).

- Migrations are additive and guarded; partial indexes/checks are PostgreSQL-safe, the completed backfill is idempotent in its stated lanes, and status is already a string column, so no enum-column alteration is required (`docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-10.md:89-129,675-682`).

## Preserve

The fix round must preserve:

- The narrowed document-only blind guarantee and accepted R5 visibility of destination on-hand, available and lot-stock positions, including the multi-receiver `NOTHING_TO_RECEIVE` composition (`docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-10.md:244-262`).
- §5.10 masking of carrying-transfer movement quantities for every receiver, with terminal and non-transfer history intact (`docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-10.md:425-435`).
- The corrected Table-P arithmetic and the separation of forbidden `S` from required-present `R` values (`docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-10.md:597-631`).
- Odoo-style partial receipt, hard over-receipt refusal, coarse `partially_received`, destination membership without assignment, and blind refusal of quantity-less completion.
- Both close dispositions, with `return_to_source` restoring source stock without GL and write-off using the existing shrinkage bridge.
- Quantity-based remainder in exactly the three aggregate readers, completed-row backfill, cancelled exclusion, scale-4 decimal strings and no floats.
- Immutable existing events, stored header/per-line receipt facts, receipt-id aggregate anchoring, after-commit integration dispatch and worker independence from `CompanyContext`.
- Header-first concurrency, company-wide idempotency keys, payload-hash mismatch 422, reserved `sys:` keys and PG partial uniqueness.
- Reconcile and close permissions seeded to manager/admin, independently grantable, with close requiring both.
- One receipt writer with `/complete` delegation, generated entity DTOs and mobile/web receiver projections blind by construction.
- DB notifications and web bell first; transfers before PO receiving; freight residual/no-journal and confirmed multi-receiver attribution.

## Owner decisions required

None. OD-1, close authority, OD-4 and R5 are applied rulings; no new product decision is necessary.

VERDICT: CHANGES-REQUIRED