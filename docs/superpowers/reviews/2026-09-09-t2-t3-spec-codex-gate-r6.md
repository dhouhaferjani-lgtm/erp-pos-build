# Codex spec gate r6 — T-2/T-3 (gpt-5.6-sol, high, read-only, 2026-09-09)

Reviewed REV 6 against repository HEAD `dfb5ff2af211dd37bba61d51a9c576d62d4c0436`. No files were modified.

## Rev-5 closure table

| Rev-5 finding | Rev-6 disposition |
|---|---|
| r5-M1 — request validation contradicts receipt constraints | **CLOSED** at `docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-6.md:298-319,497`. Per-line positivity, uniqueness, lot grain, typed failures and zero-write assertions are now explicit. |
| r5-M2 — T9 is not an executable blind-leak oracle | **NOT CLOSED.** The original permission/key/version defects were repaired, but the replacement positive control is still impossible; see M3. The conflict is at `docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-6.md:519,528,551-552`. |
| r5-M3 — web cache removal cannot match real keys | **CLOSED** at `docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-6.md:440`. Bare namespace prefixes match the actual suffix-scoped leaves. |
| r5-M4 — missing decimal-string model contract | **CLOSED** at `docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-6.md:108-122`. |
| r5-m1 — generated DTO replacement deletes request/query types | **CLOSED** at `docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-6.md:437`. |
| r5-m2 — pattern query counts postings rather than receiver/line pairs | **CLOSED** at `docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-6.md:203-223,511`. |
| r5 citation — stale HEAD | **NOT CLOSED.** REV 6 again claims a prior commit is current HEAD; see Citation audit. |
| r5 citation — owner range included unrelated line 104 | **CLOSED** at `docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-6.md:3`; the applicable rulings are correctly split across `docs/handoff/OWNER-QUESTIONS-consolidated-2026-09-06.md:98-103,106`. |
| r5 citation — notification `created_at` line | **CLOSED** at `docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-6.md:428`; the real field is `apps/api/app/Modules/Notification/Presentation/Controllers/NotificationController.php:35`. |
| OD-1 | **NOT CLOSED intentionally**, with a complete non-blocking default at `docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-6.md:572`. |
| Close authority | **NOT CLOSED intentionally**, with a complete non-blocking default at `docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-6.md:573`. |
| OD-4 | **NOT CLOSED intentionally**, with a complete non-blocking default at `docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-6.md:574`. |
| Round-1 rejection of `return_to_source` | **REJECTED-correctly.** REV 6 preserves the owner-ratified stock-only/no-GL path at `docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-6.md:330,402-403,496`. |
| D1, D2, OQ-1, OQ-2, OQ-3, freight OQ-4, multi-receiver attribution, accountant seed and stored per-line facts | **CLOSED.** The rulings are applied at `docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-6.md:178-223,324-330,405-418`; source rulings are `docs/handoff/OWNER-QUESTIONS-consolidated-2026-09-06.md:98-103,106`. |

## BLOCKER

None.

## MAJOR

### M1. Transfer-linked replenishment notes remain an expected-quantity leak

REV 6 masks `requested_qty` and `suggested_qty` but deliberately returns the unrestricted `note` on the same transfer-linked row (`docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-6.md:262-263,279,545-546`).

That note is arbitrary text up to 2,000 characters (`apps/api/app/Modules/Replenishment/Presentation/Requests/CaptureReplenishmentRequest.php:37-38`), is persisted from receiver input (`apps/api/app/Modules/Replenishment/Presentation/Controllers/ReplenishmentRequestController.php:117-125`), and is emitted verbatim (`apps/api/app/Modules/Replenishment/Presentation/Resources/ReplenishmentRequestResource.php:25-32`). The fulfillment operation copies its selected quantity into the transfer line (`apps/api/app/Modules/Replenishment/Application/Services/ReplenishmentFulfillmentService.php:64-74`) and links the request to that transfer (`apps/api/app/Modules/Replenishment/Application/Listeners/SettleRequestsOnTransferInitiated.php:64-70`).

Therefore a note such as `send 7391.4517` exposes the value that the adjacent structured fields are masking. This contradicts the server guarantee against transfer-linked expected quantities and free-text leakage at `docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-6.md:242`.

For transfer-linked rows viewed by an actor failing `canSeeIncomingAggregates`, omit or null the note alongside the two quantities. T9 must use a quantity-bearing note authored by a different destination user so this path is exercised.

### M2. A receiver can reserve the synthetic `complete:{transferId}` idempotency key

The wire example calls client keys UUIDs, but rule A6 accepts any string up to 128 characters (`docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-6.md:286-291,307`). Receipt keys are unique company-wide, not per transfer (`docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-6.md:126,172`), while quantity-less completion uses the predictable internal key `complete:{transferId}` (`docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-6.md:350-351`).

A destination receiver can post a valid partial receipt using `complete:{T}`. A later authorized `complete(T)` hashes a different payload and returns `IDEMPOTENCY_KEY_REUSED`, permanently disabling that compatibility action. The header lock and existing canonical product-lock ordering at `apps/api/app/Modules/Inventory/Application/Services/StockTransferService.php:317-339,734-742` do not prevent namespace preemption.

Require UUID validation for every client-supplied receive/close key, leaving colon-prefixed keys exclusively internal, and add the collision case to T5/T7. A separate explicit internal namespace discriminator would also close it.

### M3. T9’s positive-control contract is still impossible

`assertSentinelsPresent` requires every supplied sentinel to occur (`docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-6.md:519`). Step 3 then invokes it on each of transfer show, stock matrix and replenishment while asserting that each full payload carries line quantities, cost and notes (`docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-6.md:521,528`). Step 8 repeats the same unscoped requirement after blind mode is disabled (`docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-6.md:552`).

Those shapes cannot satisfy it:

- Stock matrix exposes aggregated `incoming`, not transfer cost or notes (`apps/api/app/Modules/Inventory/Application/Services/StockMatrixQueryService.php:395-408`).
- Replenishment exposes requested/suggested quantities and its own note, not transfer cost, transfer notes or remaining quantity (`apps/api/app/Modules/Replenishment/Presentation/Resources/ReplenishmentRequestResource.php:17-42`).

Step 7 already demonstrates the correct design—surface-specific sentinel subsets (`docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-6.md:551`). Apply that explicitly to steps 3 and 8. The transfer response can prove all transfer sentinels; matrix and replenishment must each prove only values their full shape is contractually capable of containing.

### Blind response-shape audit

| Receiver-reachable shape | Code-derived result |
|---|---|
| Receiver view, list and show | Quantity-safe by the specified receiver builder: expected, remaining, costs, sender notes, other users’ receipts and allocation quantities are absent (`docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-6.md:265-270,337-340`). Batch identity without allocation quantity does not expose the sent quantity. |
| `POST /receive` 201 | Safe: receipt echoes the actor’s own quantities; transfer uses the gated builder (`docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-6.md:248,271,321-322`). |
| `POST /complete` | Safe from quantity disclosure because visibility is checked before replay, but vulnerable to M2’s key preemption (`docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-6.md:249,350-351`). |
| `POST /close` | Safe: reconcile and close permissions are both required, so a blind receiver gets a static 403 (`docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-6.md:250,324-330`). |
| Typed and framework 422s | Safe: messages are static and details contain IDs only (`docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-6.md:251,272-276,300-319`). `partially_received` remains only the owner-accepted coarse signal (`docs/handoff/OWNER-QUESTIONS-consolidated-2026-09-06.md:102`). |
| Stock matrix | Safe when implemented as specified: transfer incoming is excluded while PO incoming remains (`docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-6.md:252,277`). |
| POS stock-level/distribution | Safe: transfer aggregates become `null`, not false zeroes (`docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-6.md:253-254,278`). |
| Web/POS replenishment | **Leaks through arbitrary `note`; M1.** Structured quantity masking itself is correct. |
| Stock movements | Safe after receipt: current output quantities are the actor’s destination movement at `apps/api/app/Modules/Inventory/Presentation/Controllers/StockMovementController.php:208-221`. |
| Entry/exit notes | Safe only with the specified location-scope fix; current quantities are at `apps/api/app/Modules/Inventory/Presentation/Controllers/EntryExitNoteController.php:241-253`. |
| Notifications | Safe: initiation/discrepancy shapes contain counts, identities and disposition but no quantities or notes (`docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-6.md:426-431`). |
| Generated web types | Safe direction: generated DTOs replace entity shadows, while request/query-only types remain local (`docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-6.md:437`). |
| Mobile types | Safe direction: the new transfer-receiving contract is isolated from existing PO expected-quantity shapes (`docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-6.md:465-468`). |
| Client caches | Matches accepted OD-4 residual: no post-flip server response leaks, but legitimately fetched pre-flip data may remain until the next successful refresh (`docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-6.md:225-232`). |

## MINOR

### m1. The visibility-version invariant overlooks the company-creation writer

I8 says every committed settings-row write increments the version (`docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-6.md:174,227`). Code has a third writer: `CompanyFraudSettingsService::ensureForCompany()` performs `firstOrCreate` (`apps/api/app/Modules/Compliance/Application/Services/CompanyFraudSettingsService.php:18-24`), invoked by the company-created listener (`apps/api/app/Modules/Compliance/Listeners/EnsureFraudSettingsOnCompanyCreated.php:17-20`).

Initial provisioning at version 1 is sensible. Narrow I8 to controller mutations after initialization and add an S-matrix assertion that real company creation produces exactly one default row at version 1.

### m2. SQLite interrupted-backfill convergence is not guaranteed

REV 6 claims per-statement guards make every partially applied SQLite migration converge (`docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-6.md:98`). Laravel runs a migration transaction only when the grammar supports it (`apps/api/vendor/laravel/framework/src/Illuminate/Database/Migrations/Migrator.php:448-451`); the base/SQLite setting is false (`apps/api/vendor/laravel/framework/src/Illuminate/Database/Schema/Grammars/Grammar.php:27-31`), while PostgreSQL explicitly enables it (`apps/api/vendor/laravel/framework/src/Illuminate/Database/Schema/Grammars/PostgresGrammar.php:14-18`).

The backfill selects only completed transfers with no receipt (`docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-6.md:561`). An SQLite interruption after inserting the header but before lines/lots makes the retry skip that transfer. Either make child insertion independently convergent or limit the crash-recovery claim to PostgreSQL; the clean rerun test does not prove interruption recovery.

### m3. Receipt-lot `batch_id int` undersizes the existing batch key

REV 6 specifies SQL `batch_id int` (`docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-6.md:130`). `product_batches.id` is created with `$table->id()` (`apps/api/database/migrations/tenant/2026_01_05_150000_create_product_batches_table.php:13-15`), and existing allocation storage uses `foreignId` (`apps/api/database/migrations/tenant/2026_06_05_121000_create_stock_transfer_line_batch_allocations_table.php:24`). The repository explicitly represents this family as `unsignedBigInteger` (`apps/api/database/migrations/tenant/2026_08_08_120000_create_stock_adjustments_tables.php:101-104`).

Specify `bigint`/`foreignId`-width storage. The PHP `int` property and integer cast remain appropriate.

### m4. T11 contradicts the authoritative lot-grain definition

The service and backfill define `is_lot_tracked` by whether the transfer line has allocations (`docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-6.md:298,321,561`), correctly preserving shipment-time grain if a product flag later changes. T11 instead derives it from the current product (`docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-6.md:506`). Make T11 derive it from allocations.

## Citation audit

| Claim | Result and real line |
|---|---|
| REV-6 baseline says current HEAD is `1adaf56d…` at line 5 | **WRONG/stale.** Current HEAD is `dfb5ff2af211dd37bba61d51a9c576d62d4c0436`. Diff from `1adaf56d…` contains only REV 6 and an unrelated documentation plan; production anchors remain unchanged. |
| TanStack positional-prefix semantics cited only at `utils.js:94` at rev-6 lines 13 and 440 | **WRONG/incomplete range.** Line 94 only declares `partialMatchKey`; recursive front-position matching is implemented at `node_modules/.pnpm/@tanstack+query-core@5.90.11/node_modules/@tanstack/query-core/build/modern/utils.js:94-104`, specifically lines 101-103. The substantive cache conclusion is correct. |
| GL buffer nested behavior at rev-6 line 396 cites `InventoryGlPostingBuffer.php:56-60` | **WRONG range.** Registration and return are at `apps/api/app/Modules/Inventory/Application/Services/InventoryGlPostingBuffer.php:56-64`. |
| Same sentence says “throws outside any transaction” at `InventoryGlPostingBuffer.php:62-64` | **WRONG line.** Lines 62-64 are the nested return; the outside-transaction throw is `apps/api/app/Modules/Inventory/Application/Services/InventoryGlPostingBuffer.php:66-67`. |
| Owner rulings | **VERIFIED** at `docs/handoff/OWNER-QUESTIONS-consolidated-2026-09-06.md:98-103,106`. |
| Notification ordering and `created_at` | **VERIFIED** at `apps/api/app/Modules/Notification/Presentation/Controllers/NotificationController.php:20,35`. |
| Exactly three remainder readers | **VERIFIED** at `apps/api/app/Modules/Inventory/Application/Services/LocationStockQueryService.php:137-164,217-245`, `apps/api/app/Modules/Inventory/Application/Services/StockMatrixQueryService.php:390-408`, and `apps/api/app/Modules/Inventory/Application/Services/WeightedAverageCostService.php:100-127`. |
| Transfer lifecycle, response shapes and authorization | **VERIFIED** at `apps/api/app/Modules/Inventory/Application/Services/StockTransferService.php:317-390,734-780`, `apps/api/app/Modules/Inventory/Presentation/Controllers/StockTransferController.php:205-357`, and `apps/api/app/Modules/Inventory/Presentation/routes.php:98-117`. |
| Movement reasons and GL families | **VERIFIED.** Damage/WriteOff exist, map to shrinkage and require GL at `apps/api/app/Modules/Inventory/Domain/Enums/MovementReason.php:26-28,68-112`; TransferIn is neither/no-GL. |
| Counting blind precedent | **VERIFIED in backend literals** at `apps/api/app/Modules/Inventory/Presentation/Controllers/InventoryCountingController.php:334-377` and `apps/api/app/Modules/Inventory/Presentation/Controllers/CountingItemController.php:54-74,161-177`. |
| Remaining REV-6 `path:line` citations | **VERIFIED** against current code or correctly labelled edit targets. The stale/incomplete citations above are the complete discrepancy list found. |

## Rejected false positives

- No fourth status-based in-transit aggregate reader was found. Other `StockTransfer`/`InTransit` matches are lifecycle code, internal line access or UI status handling; the three actual readers are listed in the Citation audit.
- The completed-row backfill preserves historical reader answers: completed rows were excluded before and remain outside `CARRYING_STATUSES`; setting counters equal to sent produces zero remainder. In-transit and cancelled rows stay untouched (`docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-6.md:157-163,561`).
- The new status values fit the existing `string(20)` column: `closed_with_writeoff` is exactly 20 characters (`apps/api/database/migrations/tenant/2026_05_28_120000_create_stock_transfers_table.php:41-42`; spec updates at `docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-6.md:104,136`).
- Every current exhaustive `TransferStatus` consumer is named for modification: enum label/terminal methods, web `Record`, status options and detail actions (`apps/api/app/Modules/Inventory/Domain/Enums/TransferStatus.php:17-49`; `apps/web/src/features/stock-transfers/components/StockTransferStatusBadge.tsx:5-10`; `apps/web/src/features/stock-transfers/pages/StockTransferListPage.tsx:15-24`; `apps/web/src/features/stock-transfers/pages/StockTransferDetailPage.tsx:38-39`).
- TransferIn followed by Damage/WriteOff is correct. `receive()` and `issue()` support the movement identity (`apps/api/app/Modules/Inventory/Domain/Services/StockAdjustmentService.php:115-129,253-267`); only the destructive leg produces shrinkage GL. WAC remains untouched except for the separately specified terminal freight capitalization.
- The stored header and per-line events contain sufficient receipt, lot, counter, status, identity, movement and idempotency fields for the declared document replay (`docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-6.md:178-200`). Existing events remain unchanged, satisfying rule 8.
- Stored-event persistence is transaction-bound; the plain notification event is after-commit. Queued notification jobs do not resolve recipients or depend on `CompanyContext`, and T10 explicitly clears it (`docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-6.md:186-199,426,505`).
- D1 is necessary. `inventory.view` is granted to cashier, viewer, technician and operator (`apps/api/database/seeders/RolesAndPermissionsSeeder.php:702,741,771,806`), while manager/admin grant mechanics are at `apps/api/database/seeders/RolesAndPermissionsSeeder.php:582,585-602`.
- Keeping `complete` as a delegate does not violate one-surface-per-concept: `StockTransferReceiptService` remains the only receipt writer (`docs/conventions/11-ONE-SURFACE-PER-CONCEPT.md:37-39`; delegation contract at `docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-6.md:350-351`).
- Contrary to the prompt’s shorthand, `countingApi.ts` has no NEVER-INCLUDE contract (`apps/web/src/features/inventory-counting/api/countingApi.ts:1-150`). The established guard lives in backend response literals; REV 6 correctly follows and strengthens that pattern.

## Preserve

- Odoo-style partial receipt, open remainder and hard over-receipt refusal.
- Default-off company-level blind receiving; transfers first, PO receiving deferred.
- Destination membership with no receiver assignment.
- DB notifications and web bell only.
- `return_to_source` with stock movement and no GL.
- Exactly three quantity-based remainder readers, cancelled exclusion and completed-row backfill.
- Scale-4 storage, decimal strings, `QuantityScale`, bcmath and no floats.
- Immutable existing events plus stored header and per-line receipt facts with actor identity.
- Separate full/receiver builders with omission by construction.
- Reconciliation behind a grantable permission seeded only to manager and admin.
- One receipt writer, no cancellation after partial receipt, and no quantity-less completion for a blind receiver.
- Confirmed freight residual/no-journal and multi-receiver attribution defaults.

These are consolidated in `docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-6.md:24`.

## Owner decisions required

These three owner-open items have complete defaults and do not independently block acceptance:

1. **OD-1:** retain refusal of quantity-less `complete` whenever `canSeeExpected()` is false (`docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-6.md:572`).
2. **Close authority:** retain the default requiring both reconcile and close permissions (`docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-6.md:573`).
3. **OD-4:** retain immediate server-side activation with advisory, eventually convergent client invalidation (`docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-6.md:574`).

No additional owner decision is required; M1–M3 are contract/execution defects under already confirmed defaults.

VERDICT: CHANGES-REQUIRED
