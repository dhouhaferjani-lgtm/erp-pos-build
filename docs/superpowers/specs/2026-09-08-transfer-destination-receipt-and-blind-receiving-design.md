# Transfer destination receipt (partial + discrepancy) and blind receiving — design spec (lanes T-2, T-3, T-4-min)

Date 2026-09-08 · Status: DRAFT for Codex gate · Owner decisions Q1–Q5 confirmed 2026-09-08 (brief `docs/handoff/BRIEF-parallel-transfers-blind-receiving-2026-09-08.md` §4) · Lanes: T-2 (receipt + discrepancy, event-sourced), T-3 (blind receiving, transfers first, PO receipts on the same path second), T-4-min (only the two notifications T-2/T-3 need). Code paths cited against the main tree at HEAD `cdedc2830` (2026-09-08).

Vocabulary line (convention 11): Concepts: Transfer (glossary — NOT present today, row added §2), Transfer receipt (NEW), In-transit remainder (NEW), Discrepancy (NEW), Write-off close (NEW), Blind receiving (NEW), Receiver view (NEW), Reconciliation (NEW — counting already uses the word, `CountingDiscrepancyReportService.php:21`; the glossary row declares both consumers).

---

## 0. Industry baseline (benchmark-first — convention 10)

Flow: inter-site stock transfer — ship, receive at destination, handle short/over/damaged, blind receipt, notify. Reference systems: Odoo 17/18, ERPNext v15, Dolibarr (sources in the brief §0; all doc-page reads, none "from memory").

| # | Guarantee | Odoo 17/18 | ERPNext v15 | Dolibarr | AutoERP today (path:line) | Gap | Decision (owner-confirmed 2026-09-08) |
|---|---|---|---|---|---|---|---|
| B1 | Inter-site transfer is two-step: ship, then destination **confirms received quantities** | Yes when the inter-warehouse transit location is enabled: delivery at source + receipt at destination; stock sits in *Inter-warehouse transit* meanwhile | Yes: Material Transfer with **Add to Transit** → **Receive at Warehouse**; status *Goods In Transit* until fully received | No native in-transit: transfer moves stock immediately; reception module is PO-only | Two states but **one call**: `store` = initiate = decrement source + `in_transit` (`StockTransferService.php:86,514`); `complete` takes **no quantities**, all-or-nothing (`:317,346-384`), no receipt document | MISSING | **MATCH (Q1)** — lane T-2: `POST /stock-transfers/{id}/receive` with per-line received quantities, receipt document `stock_transfer_receipts` |
| B2 | Partial receipt at destination / remainder | Validate with `Done < Demand` → **Create backorder** prompt (or no backorder = remainder dropped) | Receive less than sent; remainder stays in the transit warehouse; further receipts allowed | Partial reception from PO, qty frozen after draft | **None** on transfers; on PO receipts yes (`partially_received` / `fully_received`, `GoodsReceiptService.php:1231-1235`) | MISSING | **MATCH Odoo backorder semantics (Q1)** — remainder stays in transit (`partially_received`); receive again later, or close with write-off |
| B3 | Discrepancy on receipt (short / over / damaged) | Short = backorder or scrap/adjustment; over-receipt allowed by editing Done (no policy gate by default) | Purchase Receipt has **rejected qty + Rejected Warehouse**; Stock Settings *Over Receipt Allowance %* | Stock correction via "Correct Stock" | PO receipt: **hard refusal** on over-receipt (`GoodsReceiptFailureReason::OverReceipt`, `GoodsReceiptService.php:326-353`); transfers: no discrepancy columns (`2026_05_28_120000_create_stock_transfers_table.php:86-88`) | MISSING | **Q2: over-receipt REFUSED (typed 422); short and damaged allowed with reason + alert.** Damaged = received then scrapped at destination with `MovementReason::Damage`; short at close = `MovementReason::WriteOff` at destination (§6) |
| B4 | **Blind receiving** (receiver does not see expected qty) | **Not native** — forum requests only; third-party Ventor PRO | Not native | Not native | **Absent** for receipts; mobile receiving exposes `quantity_ordered/remaining` (`erp-mobile/src/features/receiving/types.ts:62-63,106-108`). **Present for counting** (field omission by construction, `InventoryCountingController.php:339-378`, `CountingItemController.php:54,177`) and cash (`require_blind_cash_count`, `CompanyFraudSettings.php:58`) | MISSING (deliberate DIVERGE from all three baselines, in our favour) | **Q3: company-level setting `blind_receiving` (default false) in the fraud-settings family; transfers first (T-3), PO receipts second on the same payload-builder path (T-3b).** |
| B5 | Notifications on stock events | Activity/chatter + optional email; no push OOTB | Notifications on doc events, email | Agenda/email | **None** for movements/transfers/receipts; DB-notification module read-only (`Modules/Notification/Presentation/routes.php:19-31`), web bell renders by `type` (`NotificationPanel.tsx:45-63`); mobile push not wired | MISSING | **Q4: DB channel + web bell at launch; push later (T-4 full).** This spec ships only `TransferInitiatedNotification` and `TransferReceivedWithDiscrepancyNotification` (§7) |
| B6 | Who may receive: assignment vs location membership | Any user with rights on the destination operation type | Warehouse user permissions | Warehouse permission | Destination access via `LocationContext::canAccessLocation` (`StockTransferController.php:221`, `LocationContext.php:224`); visibility via `canSeeTransfer` (`:347-357`) | ALREADY (for access) | **Q5: location membership at destination + `inventory.transfers.complete`; no explicit receiver assignment.** Blind visibility rule in §5.3 |
| B7 | Cancel after goods moved | Cannot cancel a done picking; backorder can be cancelled | Cannot cancel after receipt entry | — | `canBeCancelled()` = draft or in_transit only (`TransferStatus.php:37-40`); cancel from in_transit restocks source (`StockTransferService.php:429-491`) | ALREADY | ALREADY — extended: `partially_received` is NOT cancellable (goods already landed); remainder is handled by close (§3.3) |
| B8 | Audit trail of receipt facts is immutable | Stock moves + chatter | Stock Ledger Entries | Stock movements | Transfer events are plain `Dispatchable`, not stored (`StockTransferInitiated.php:9-11`, `StockTransferCompleted.php:9-11`); PO receipt `GoodsReceived` is a stored `DomainEvent` (`GoodsReceived.php:18`, `DomainEvent.php:16`) | PARTIAL | MATCH — new stored `StockTransferReceivedV1`, `StockTransferClosedWithWriteoffV1` (§4) |

Domain norm: blind receiving is a WMS control against confirmation bias — receiver counts without seeing the ASN quantity; the system compares afterwards and routes variances to a supervisor (same rationale as blind cash / blind counting already in this codebase).

Second-of-everything (convention 09): §10 rows S1–S3. No `CATALOGUE_TABLES` entity is touched; the two new tables carry `company_id` in every unique key, so the ratchet needs no baseline entry.

---

## 1. Problem and scope

**Problem.** A transfer today is decremented at source on `store` and incremented at destination by a quantity-less `complete` (`StockTransferService.php:346-384`): the receiver cannot say "11 of 12 arrived, one broken". There is no receipt document, so nothing can be blind; the three in-transit readers derive incoming stock from `status = in_transit` alone (`LocationStockQueryService.php:144,228`, `StockMatrixQueryService.php:402`, `WeightedAverageCostService.php:124`), which cannot express a partially received transfer. Nobody is told a transfer is coming or that it arrived short.

**In scope.**
- T-2: receipt document per transfer with per-line received / damaged quantities and lot grain; remainder stays in transit; explicit close with write-off; quantity-based in-transit derivation in the three readers; stored events; `complete` kept as the zero-discrepancy shortcut delegating to the same write path.
- T-3: `blind_receiving` company setting; receiver view that omits expected quantities by construction; supervisor reconciliation; the same guard contract on mobile types. Transfers first; the PO receiver-view (T-3b) reuses the payload-builder split and the setting, and is specified here only as a contract commitment (§9).
- T-4-min: the two DB notifications T-2/T-3 need (§7).

**Out of scope** (each is a separate lane or ticket): push notifications and Expo tokens (T-4); `ReplenishmentRequestedNotification` (T-4); mobile screens (M-1); PO receiver-view implementation (T-3b); inter-company transfers (`StockTransferService.php:96-101` still refuses them); over-receipt tolerance settings; discrepancy tolerance threshold for alerts (v1 alerts on any discrepancy); a receipt `void`/reversal (correct via the stock-adjustment document); a `return_to_source` disposition on close (OQ-1); GL account provisioning for shrinkage (exists: `MovementGlCounterFamily::Shrinkage`, `MovementReason.php:80-82`).

---

## 2. Vocabulary — glossary rows to add (`docs/glossary.md`, new section "Stock operations", same lane)

| Term | Definition | Table / module | Canonical surface | Synonyms |
|---|---|---|---|---|
| **Transfer** | An intracompany movement of stock from a source location to a destination location: source is decremented when the transfer is initiated (`in_transit`), destination is incremented by one or more **transfer receipts**. | `stock_transfers`, `stock_transfer_lines`, `stock_transfer_line_batch_allocations` / `Inventory` | Stock → Transfers (list/create/detail); write path `StockTransferService` only | transfert, stock transfer, inter-site move |
| **Transfer receipt** | A posted document recording what a destination physically received from one transfer at one moment: per line received quantity, damaged quantity, lots, discrepancy reason. Immutable once posted; a transfer may have several. | `stock_transfer_receipts`, `stock_transfer_receipt_lines`, `stock_transfer_receipt_line_lots` / `Inventory` | Transfer detail → **Receive**; `POST /stock-transfers/{id}/receive`; write path `StockTransferReceiptService::receive` only (`complete` delegates to it) | réception de transfert, destination receipt |
| **In-transit remainder** | Per transfer line: `quantity − quantity_received − quantity_damaged − quantity_written_off`; the only definition of "incoming" for stock readers. Positive only while the transfer is `in_transit` or `partially_received`. | derived; SQL expression `StockTransferLine::REMAINDER_SQL` | Stock matrix / location stock "incoming" column; transfer detail "remaining" (visibility-gated) | en transit, reste à recevoir, backorder (Odoo) |
| **Discrepancy** | The difference between what a transfer line shipped and what the destination received in good condition: **short** (not arrived — stays in transit until a later receipt or a close), **damaged** (arrived, unusable — scrapped at destination), **over** (refused, never recorded). Carries a `TransferDiscrepancyReason`. | columns on `stock_transfer_receipt_lines`; `TransferDiscrepancyReason` enum | Receive dialog (damaged + reason); reconciliation view | écart, variance, shortage |
| **Write-off close** | The explicit terminal action that ends a transfer whose remainder will never arrive: the remainder is written off at the destination (one `WriteOff` movement + GL shrinkage leg per line) and the transfer becomes `closed_with_writeoff`. | `stock_transfers.status`, `stock_transfer_lines.quantity_written_off`; a `stock_transfer_receipts` row of kind `writeoff_close` | Transfer detail → **Close with write-off**; `POST /stock-transfers/{id}/close` | clôture avec perte, close backorder without receiving (Odoo) |
| **Blind receiving** | Company setting `company_fraud_settings.blind_receiving` (default false): when on, a destination-side user without the reconcile permission never receives expected/remaining quantities on any transfer payload; they record what they see and a supervisor reconciles. | `company_fraud_settings.blind_receiving` / `Compliance` | Settings → Fraud & controls (same card as blind cash count) | réception à l'aveugle, blind receipt |
| **Receiver view** | The receiver-facing representation of a transfer (or, T-3b, of a PO): identity, lines, lots, own prior receipts — **never** the shipped/expected/remaining quantities. Built only by `TransferReceiverPayloadBuilder`. | none (projection) / `Inventory` | `GET /stock-transfers/{id}/receiver-view`; the web detail page and mobile switch to it when blind applies | vue réceptionnaire |
| **Reconciliation** | The supervisor comparison sent vs received vs damaged vs written-off vs remaining, per line and lot, with the discrepancy reasons. Two consumers share the word: counting (`CountingDiscrepancyReportService`) and transfers (`TransferReconciliationService`); both are read-only projections. | none (projection) / `Inventory` | `GET /stock-transfers/{id}/reconciliation`; detail page **Reconciliation** tab (`inventory.transfers.reconcile`) | rapprochement, variance report |

---

## 3. Domain model and schema contract

### 3.1 New columns (additive, self-guarding `Schema::hasColumn` migrations, tenant path `database/migrations/tenant/`)

`stock_transfer_lines` (today `quantity decimal(15,4)`, `2026_05_28_120000_…:86`; model casts `decimal:4`, `StockTransferLine.php:59`):
- `quantity_received decimal(15,4) NOT NULL DEFAULT 0` — good units landed at destination.
- `quantity_damaged decimal(15,4) NOT NULL DEFAULT 0` — units landed then scrapped at destination.
- `quantity_written_off decimal(15,4) NOT NULL DEFAULT 0` — units written off by a close.
- PG CHECK `stock_transfer_lines_received_within_sent`: `quantity_received >= 0 AND quantity_damaged >= 0 AND quantity_written_off >= 0 AND quantity_received + quantity_damaged + quantity_written_off <= quantity` (same `DB::getDriverName() === 'pgsql'` guard as `:96-100`).

`stock_transfer_line_batch_allocations` (today `quantity decimal(15,4)`, unique `(stock_transfer_line_id, batch_id)`, `2026_06_05_121000_…:25,28`): same three columns + the same CHECK, so the lot-grain remainder is derivable and "received lots ⊆ shipped allocations" is enforceable per lot.

`stock_transfers.status` stays `string(20)` (`:42`); `closed_with_writeoff` is exactly 20 chars — fits, no ALTER. New nullable `closed_by_user_id` (FK users, nullOnDelete), `closed_at timestampTz`, `close_reason string(32)`, `close_note text`.

`company_fraud_settings.blind_receiving boolean NOT NULL DEFAULT false` (family of `require_blind_cash_count`, `CompanyFraudSettings.php:58`; defaults array `:52-64`).

### 3.2 New tables

`stock_transfer_receipts` — one row per posted receipt or write-off close:
`id uuid PK`, `tenant_id uuid` (plain indexed, no FK — `:31-36` rationale), `company_id` FK companies, `transfer_id` FK stock_transfers restrictOnDelete, `receipt_number string(64)`, `kind string(20)` (`TransferReceiptKind`), `status string(20)` (`TransferReceiptStatus`), `is_blind boolean` (snapshot of the setting as it applied to the actor at post time), `has_discrepancy boolean`, `idempotency_key string(128) NOT NULL`, `payload_hash char(64) NOT NULL` (sha256 of the canonical JSON body), `received_by_user_id` FK users restrictOnDelete, `received_at timestampTz`, `notes text null`, `timestampsTz`.
Uniques: `(tenant_id, company_id, receipt_number)`, `(tenant_id, company_id, idempotency_key)`. Indexes: `(tenant_id, company_id, transfer_id)`, `(transfer_id, received_at)`.

`stock_transfer_receipt_lines`: `id`, `receipt_id` FK cascade, `transfer_line_id` FK stock_transfer_lines, `tenant_id`, `company_id`, `product_id`, `variant_id null`, `quantity_received decimal(15,4)`, `quantity_damaged decimal(15,4)`, `quantity_written_off decimal(15,4)`, `discrepancy_reason string(32) null` (`TransferDiscrepancyReason`), `discrepancy_note text null`, `in_movement_id uuid null` (the `TransferIn` movement), `scrap_movement_id uuid null` (the `Damage` / `WriteOff` movement), `timestampsTz`. Unique `(receipt_id, transfer_line_id)`. PG CHECK all three ≥ 0 and `quantity_received + quantity_damaged + quantity_written_off > 0` (a zero line is not stored — "nothing arrived" is the absence of a line).

`stock_transfer_receipt_line_lots`: `id`, `receipt_line_id` FK cascade, `batch_allocation_id` FK stock_transfer_line_batch_allocations, `tenant_id`, `company_id`, `batch_id int`, `quantity_received`, `quantity_damaged`, `quantity_written_off`, `in_movement_id null`, `scrap_movement_id null`. Unique `(receipt_line_id, batch_allocation_id)`.

Numbering: `receipt_number` via the numbering service key `stock_transfer_receipt` (precedent `GoodsReceiptService.php:272`), format `TRR-{YYYY}-{NNNN}` per company.

### 3.3 Enums (rule 9)

- `TransferStatus` (`TransferStatus.php:17-20`) gains `PartiallyReceived = 'partially_received'`, `ClosedWithWriteoff = 'closed_with_writeoff'`. `isTerminal()` (`:22`) → Completed | Cancelled | ClosedWithWriteoff. `canBeCompleted()` (`:32`) → InTransit | PartiallyReceived. `canBeCancelled()` (`:37`) unchanged (Draft | InTransit; PartiallyReceived refused). New `canReceive()` and `canBeClosed()` → InTransit | PartiallyReceived. `label()` extended. `packages/shared/types/generated.d.ts:1240` regenerates.
- `TransferReceiptKind`: `Receipt = 'receipt'`, `WriteoffClose = 'writeoff_close'`.
- `TransferReceiptStatus`: `Posted = 'posted'` only. Single case is deliberate: receipts post atomically, and reversal is out of scope; the column exists so a future `Voided` is additive, not a rename.
- `TransferDiscrepancyReason`: `ShortShipped = 'short_shipped'`, `LostInTransit = 'lost_in_transit'`, `DamagedInTransit = 'damaged_in_transit'`, `Other = 'other'`. Method `movementReason(): MovementReason` → Damaged → `MovementReason::Damage`, everything else → `MovementReason::WriteOff` (both `requiresGLEntry()` true, `MovementReason.php:103-105`, family Shrinkage `:80-82`). No new `MovementReason` case.
- `TransferReceiptFailureReason` (typed 422 codes, mirrors `GoodsReceiptFailureReason.php:9-19`): `OverReceipt = 'OVER_RECEIPT'`, `LotOverReceipt = 'LOT_OVER_RECEIPT'`, `UnknownLot = 'UNKNOWN_LOT'`, `LotRequired = 'LOT_REQUIRED'`, `LineNotOnTransfer = 'LINE_NOT_ON_TRANSFER'`, `NothingToReceive = 'NOTHING_TO_RECEIVE'`, `DiscrepancyReasonRequired = 'DISCREPANCY_REASON_REQUIRED'`, `IdempotencyKeyReused = 'IDEMPOTENCY_KEY_REUSED'`. State errors keep `TransferStateException` → `INVALID_TRANSFER_STATE` (`StockTransferController.php:389-402`).
- `StockMovementReferenceType` gains `StockTransferReceipt = 'stock_transfer_receipt'` (precedent `PosReceiptReturnScrap`, `StockMovementReferenceType.php:72`) — used ONLY on the scrap/write-off movements. The `TransferIn` movements keep `reference_type = StockTransfer::class, reference_id = transfer id` exactly as `markMovementAsTransfer` writes today (`StockTransferService.php:745-752`) so every existing trace consumer keeps working.

### 3.4 State machine

```
draft ──initiate──▶ in_transit ──receive(partial)──▶ partially_received ──receive(rest)/complete──▶ completed
                       │  │                                │   │
                       │  └──receive(all)/complete──▶ completed │
                       │                                        └──close──▶ closed_with_writeoff
                       ├──close (nothing arrived)──▶ closed_with_writeoff
                       └──cancel──▶ cancelled            (partially_received: cancel REFUSED, 422 INVALID_TRANSFER_STATE)
draft ──cancel──▶ cancelled
```
Terminal transition rule (kept from `:386-391`): the status row is persisted BEFORE `capitalizeTransferCost` so the in-transit re-read in `recordCostAdjustment` does not double count.

### 3.5 In-transit remainder — the three readers move from status-based to quantity-based

Single definition: `StockTransferLine::REMAINDER_SQL = '(stock_transfer_lines.quantity - stock_transfer_lines.quantity_received - stock_transfer_lines.quantity_damaged - stock_transfer_lines.quantity_written_off)'` and scope `StockTransfer::scopeCarryingInTransit()` = `whereIn('status', [in_transit, partially_received])`. The status filter remains necessary: a cancelled transfer restocks source (`:450-482`) with the received columns still 0.
- `LocationStockQueryService.php:144-160` and `:228-242`: `SUM(quantity)` → `SUM(REMAINDER_SQL)`, status equality → the scope.
- `StockMatrixQueryService.php:402-404`: same.
- `WeightedAverageCostService.php:124-125`: Eloquent `->sum('stock_transfer_lines.quantity')` → `selectRaw('COALESCE(SUM(REMAINDER_SQL),0)')` under the scope; result still passed through `bcadd` at the working scale (`:127`).
Guard: architecture test `TransferInTransitReadersUseRemainderTest` fails if any file under `Modules/Inventory/Application/Services` contains the literal `TransferStatus::InTransit` outside `StockTransfer::scopeCarryingInTransit` (detector liveness fixture per convention 08).

### 3.6 Invariants (each is a test in §10)

I1 Per line and per lot: `received + damaged + written_off ≤ sent` (DB CHECK + service assertion at `QTY_SCALE = 4`, `StockTransferService.php:63`).
I2 Company-owned quantity (`WeightedAverageCostService::companyOwnedQuantity`, `:100-129`) changes exactly once per shipped unit: −1 at source on initiate, and never again by a transfer action. A receipt moves the unit from the derived remainder to a destination `stock_levels` row (+1 on-hand, −1 remainder = net 0); a damage or write-off does +1 (TransferIn) then −1 (scrap) at destination = net −1 on-hand, −1 remainder — one destructive movement, one GL leg, never a second aggregate decrement.
I3 Batch-tracked lines: every received/damaged/written-off lot ⊆ the shipped allocations of that line; the lot grain sums equal the line grain; a batch-tracked line without `lots` is refused (`LOT_REQUIRED`). Short on a lot = that lot's remainder (owner Q2).
I4 A receipt is immutable; a transfer's `quantity_*` columns equal Σ of its receipt lines (reconciliation asserts it; drift = data bug).
I5 A transfer is terminal iff Σ remainder = 0 over its lines (Completed when written_off = 0 on every line, ClosedWithWriteoff otherwise).
I6 Idempotency: `(tenant_id, company_id, idempotency_key)` unique; replay with equal `payload_hash` returns the stored receipt; unequal → `IDEMPOTENCY_KEY_REUSED` 422.

---

## 4. Event sourcing

New stored events extend `App\Shared\Domain\Events\DomainEvent` (`DomainEvent.php:16`, Spatie `ShouldBeStored`), scalars and numeric strings only, template `GoodsReceived.php:18-42`:

- `StockTransferReceivedV1` — aggregate uuid = `receiptId` (idempotency anchor for listeners, like `GoodsReceived::$movementId`, `:26-27`). Fields: `receiptId, transferId, tenantId, companyId, transferNumber, receiptNumber, sourceLocationId, destinationLocationId, receivedByUserId, isBlind: bool, hasDiscrepancy: bool, lineCount: int, totalReceived, totalDamaged` (numeric strings 4 dp), `occurredAt` ISO-8601. `getEventName()` = `inventory.stock_transfer.received.v1`.
- `StockTransferClosedWithWriteoffV1` — aggregate uuid = `receiptId` of the close row. Fields: `receiptId, transferId, tenantId, companyId, transferNumber, sourceLocationId, destinationLocationId, closedByUserId, closeReason (TransferDiscrepancyReason value), lineCount, totalWrittenOff, occurredAt`. Name `inventory.stock_transfer.closed_with_writeoff.v1`.
- Per-line facts are NOT a third event: every movement already emits the stored `StockMovementRecordedV2` (`StockMovementRecordedV2.php:20-40`) carrying `referenceType/referenceId`, and the receipt tables hold the line/lot grain. Adding a per-line event would be a second surface for the same fact.
- Dispatch timing: stored events are dispatched INSIDE the receipt transaction so the `stored_events` row commits with the receipt (precedent `GoodsReceiptService.php:849 → flushPendingGlPostings :464-467`). Listeners that have side effects outside the DB (notifications) defer with `DB::afterCommit` (precedent `StockTransferService.php:406,492,608`).
- Compatibility: the plain `StockTransferInitiated` / `StockTransferCancelled` (`Dispatchable`, `StockTransferInitiated.php:11`) keep firing unchanged; the plain `StockTransferCompleted` fires on the terminal `Completed` transition whichever path caused it (receive-all or `complete`). Rule 8: no existing event is renamed or restructured.
- Wiring: `EventServiceProvider::$listen` (precedent `GoodsReceived::class => [PostGrIrOnGoodsReceipt::class]`, `EventServiceProvider.php:146-148`): `StockTransferReceivedV1 => [NotifyOnTransferDiscrepancy::class]`; `StockTransferInitiated => [NotifyDestinationOnTransferInitiated::class]` in `InventoryServiceProvider::boot` (`InventoryServiceProvider.php:92-96` style). Both listeners are idempotent on the aggregate uuid (a `notification_sent` marker is unnecessary: DB notifications keyed by `receipt_id` in `data`; the listener checks `DatabaseNotification` existence for `(type, data->receipt_id)` before sending).
- Replenishment settlement stays on initiate (`SettleRequestsOnTransferInitiated.php:22-42`, wired `ReplenishmentServiceProvider.php:19`): a request is "fulfilled" when a transfer is shipped for it. A write-off close does NOT reopen requests (`ReopenRequestsOnTransferCancelled` stays bound to cancel only, `:20`) — see OQ-2.

---

## 5. API contract (module `Inventory`, group middleware `['api','auth:sanctum',SetPermissionsTeam::class]` as the existing transfer routes `routes.php:99-117`; module gating unchanged — transfers are core, not vertical-exclusive)

Envelopes follow `StockTransferController.php:359-402`: `{error:{code,message,details}}`, 422 for typed failures, 403 `LOCATION_ACCESS_DENIED`, 404 for invisible transfers (`:110-114`).

### 5.1 `POST /stock-transfers/{id}/receive` — `can:inventory.transfers.complete` + destination access (`canAccessLocation(destination)`, as `:221`)

Request (`ReceiveStockTransferRequest`, precision rule 19: `numeric` + regex `/^\d+(\.\d{1,4})?$/`, strings only):
```json
{ "idempotency_key": "uuid (required)", "notes": "string|null",
  "lines": [ { "transfer_line_id": "uuid", "quantity_received": "11.0000", "quantity_damaged": "1.0000",
               "discrepancy_reason": "damaged_in_transit", "discrepancy_note": "box crushed",
               "lots": [ { "batch_id": 812, "quantity_received": "11.0000", "quantity_damaged": "1.0000" } ] } ] }
```
Rules: `idempotency_key` REQUIRED (a partial receipt double-submitted without a key would double count silently; mobile offline replay depends on it). Lines omitted from the body = nothing received on them (they stay in transit) — in blind mode the receiver reports only what arrived. `discrepancy_reason` is required only when `quantity_damaged > 0`; shortness is computed server-side and never asked of a blind receiver (they cannot know it) — the supervisor supplies the reason at close. Σ(received + damaged) over the body must be > 0 else `NOTHING_TO_RECEIVE`.
Server: `DB::transaction(attempts: 3)` → `lockTransfer` (`lockForUpdate`, `StockTransferService.php:734-742`) → `canReceive()` else `TransferStateException` → advisory locks for all line products up-front in one sorted call (`:336`) → idempotency lookup (equal hash → return stored receipt, HTTP 200, `meta.replayed = true`; unequal → `IDEMPOTENCY_KEY_REUSED`) → per line: assert line ∈ transfer, `received + damaged ≤ remainder` else `OVER_RECEIPT`, lot assertions (I3) → movements (§6) → update `quantity_*` on line and allocations → receipt rows → status (`Completed` if Σ remainder = 0 else `PartiallyReceived`; on `Completed`: `completed_by/at`, then `capitalizeTransferCost` `:631`) → stored event → `afterCommit` plain `StockTransferCompleted` when terminal.
Response 201 `{ data: { receipt: StockTransferReceiptData, transfer: StockTransferData } }` where `transfer` is built by the visibility-gated builder of §5.3 (a blind receiver gets no `quantity`/`quantity_remaining` in the echo either).
Blind-mode 422 detail rule: `OVER_RECEIPT` / `LOT_OVER_RECEIPT` details carry only `transfer_line_id` / `batch_id` when the actor is blind — never `remaining`, `sent`, `already_received` (the non-blind envelope mirrors `GoodsReceiptFailureDetails`, `GoodsReceiptService.php:339-345`). Accepted residual: a blind receiver can still learn an upper bound by probing; owner Q2 chose refusal over silent acceptance.

### 5.2 `POST /stock-transfers/{id}/close` — new permission `inventory.transfers.close` + destination access

```json
{ "idempotency_key": "uuid", "reason": "lost_in_transit", "note": "string|null" }
```
Allowed from `in_transit` (nothing arrived) or `partially_received`. Writes one `writeoff_close` receipt row whose lines carry `quantity_written_off = remainder` per line/lot, the movements of §6, status `closed_with_writeoff`, `closed_by/at/close_reason/close_note`, then `capitalizeTransferCost` (§6 cost note) and `StockTransferClosedWithWriteoffV1`. Zero remainder → `NOTHING_TO_RECEIVE` (nothing to close; use `complete`). Not exposed to mobile in M-1.

### 5.3 Visibility rule and `GET /stock-transfers/{id}/receiver-view` — `can:inventory.transfers.complete` + destination access

`TransferExpectedQuantityVisibility::canSeeExpected(user, transfer, settings)` = `blind_receiving` off OR user holds `inventory.transfers.reconcile` (new) OR `canAccessLocation(source)` (the sender knows what they shipped; unrestricted memberships return true at `LocationContext.php:224-239`, so blind mode targets restricted destination staff — the same shape as counting, where admins always see theoretical quantities).
Two payload builders, one concept each (convention 11):
- `TransferPayloadBuilder` — the full shape `formatTransfer` produces today (`StockTransferController.php:282-341`) plus `quantity_received`, `quantity_damaged`, `quantity_written_off`, `quantity_remaining`, `receipts[]`.
- `TransferReceiverPayloadBuilder` — a separate class whose line array literal has no expected-quantity keys at all, with the counting guard comment (`InventoryCountingController.php:376`): `// NEVER INCLUDE: quantity, quantity_remaining, quantity_sent, unit_cost_snapshot, allocated_transfer_cost, batch_allocations[].quantity`. Lines: `id, product{id,name,sku,barcode}, variant{id,sku,name_suffix}|null, unit{decimal_places}, requires_batch_tracking, lots[]{batch_id,batch_number,expiry_date}` (lot identity only), `my_receipts[]` (the actor's own posted receipt lines, so a second pass can be resumed), `receiving_open: bool`. Header: `id, transfer_number, source_location{id,name}, destination_location{id,name}, status, initiated_at, notes, blind: true`.
- `GET /stock-transfers/{id}` and the list use `TransferReceiverPayloadBuilder` whenever `canSeeExpected` is false — otherwise the full builder. `/receiver-view` ALWAYS uses the receiver builder (even for a supervisor), so the mobile screen and its tests have one deterministic shape.
- Neither web nor mobile reads `/fraud-settings` to decide the mode (receivers typically lack `fraud-settings.view`, `Compliance/Presentation/routes.php:23-25`): they read `blind` from the transfer payload.

### 5.4 `GET /stock-transfers/{id}/reconciliation` — `can:inventory.transfers.reconcile` (new)

Deviation from the brief (which said `inventory.view`): `inventory.view` is granted to most seeded roles (`RolesAndPermissionsSeeder.php:681,720,750,785`), so gating the sent-vs-received view on it would make blind mode leak through the reconciliation endpoint. `inventory.transfers.reconcile` is the transfer twin of the counting admin reconciliation (`CountingItemController.php:185`). Shape (reuses the counting report vocabulary, `CountingDiscrepancyReportService.php:44-56`): per line and lot `sent, received, damaged, written_off, remaining, variance = sent − received`, `discrepancy_reasons[]`, `receipts[]{receipt_number, kind, received_by, received_at, is_blind, has_discrepancy}`, `summary{lines, lines_with_discrepancy, total_sent, total_received, total_damaged, total_written_off, total_remaining}`; quantities are numeric strings at the unit's `decimal_places` (`QuantityScale::formatForUnit`, `QuantityScale.php:72`).

### 5.5 Company setting `blind_receiving`

`FraudSettingsController` (`FraudSettingsController.php:120` validation block) gains `'blind_receiving' => 'sometimes|boolean'` under the existing `can:fraud-settings.update` route (`Compliance/Presentation/routes.php:27-29`); it is NOT in `CASH_CONTROL_KEYS` (`:35`) so it does not require `pos.configure_cash_count`. `CompanyFraudSettingsData` (`:44,75,105`) gains `blind_receiving`; the `show` DTO regenerates to TS. Existing-tenant rows: the column default `false` is the migration; no data migration (contrast with `2026_08_12_100000_enable_blind_cash_count_for_existing_settings.php`, which flipped cash to true by owner ruling — receiving stays opt-in).

### 5.6 `POST /stock-transfers/{id}/complete` — kept, `can:inventory.transfers.complete`

Becomes `StockTransferReceiptService::receiveAllRemaining(transfer, user, idempotencyKey: "complete:{transferId}")`: same write path, one receipt row of kind `receipt` with every remaining line/lot, zero discrepancy. The current `completeLocked` loop (`:346-384`) is deleted — one writer (convention 11 rule 2). Response unchanged (`:231-235`).

Permissions seeder: add `inventory.transfers.close`, `inventory.transfers.reconcile` next to `:192-195` and to the role bundles at `:579-581`; deploy runs `permission:cache-reset`.

---

## 6. Discrepancy → movements + GL

All movements go through `StockAdjustmentService` (`receive` `:115-129`, `issue` `:253-267`, both accept `batchId`, `reason`, `unitCost`, `referenceType/referenceId`), inside the receipt transaction, under the up-front product advisory locks.

| Quantity | Movement(s) at DESTINATION | Reason / GL |
|---|---|---|
| received (good) | `receive(qty, reference = transfer_number, batchId)` then `markMovementAsTransfer(TransferIn, transferId)` — byte-for-byte today's completion path (`:349-384`) | `TransferIn`: `requiresGLEntry()` false (`MovementReason.php:111` default), family `Neither` (`:88-92`) — no GL, WAC untouched |
| damaged | the same `receive` + `markMovementAsTransfer`, then `issue(qty, reference = receipt_number, batchId, reason: MovementReason::Damage, unitCost: current company WAC, referenceType: StockMovementReferenceType::StockTransferReceipt, referenceId: receipt id)` | `Damage`: GL true (`:103`), family Shrinkage (`:80-82`) → Dr Shrinkage / Cr Inventory |
| written off (close) | `receive` + `markMovementAsTransfer`, then `issue(... reason: MovementReason::WriteOff ...)` | `WriteOff`: GL true (`:105`), Shrinkage |

Why land-then-scrap: the in-transit remainder is derived, not a `stock_levels` row, so nothing can be issued from "transit"; the `TransferIn` gives the destination row the units the scrap then removes (net on-hand 0 for the line, one destructive movement, one GL leg — invariant I2). This is Odoo's "receive, then scrap at destination" and puts the shrinkage on the location that observed it, which is also the location whose `inventory.adjust` holders get the alert (§7). Source-side blame is a reporting question (reconciliation shows `discrepancy_reason`), not a stock-location question.

GL bridge: the receipt service is a composite root of the D-28 buffer — `InventoryGlPostingBuffer::mark()` at entry, `enqueue(new MovementGlContext(kind: MovementGlKind::BatchWriteOff, reason: Damage|WriteOff, …))` per destructive movement exactly as `ReturnScrapWriteOffService.php:148-186`, `flushIfOutermost()` after the transaction body, `rollbackTo(marker)` on failure (`InventoryGlPostingBuffer.php:29,34,39,56`). Direct `InventoryGlPostingService::postFor*` calls are forbidden by PHPStan (`InventoryGlPostingViaBufferOnly.php:40-44`). Non-positive resolved unit cost → log and post no journal, as `ReturnScrapWriteOffService.php:135-146`. `BatchWriteOffService::writeOff` (`BatchWriteOffService.php:44-57`) is NOT used: its string reason + direct `GeneralLedgerService` path predates the buffer; D7a's refusal (`StockAdjustmentDocumentService.php:520-530`) applies to the manual document, not to this document-driven path, which always names the lot (I3).

WAC: transfers never touch WAC except `capitalizeTransferCost` (`:631-712`, `recordCostAdjustment` call at `:696`) on the terminal transition; unchanged. Cost note: allocation weights stay on shipped `quantity` (`computeAllocationWeights`, `:714`), so the freight share of a written-off line is still capitalized. DIVERGE, deliberate: the alternative (expensing that share) needs a GL purpose that does not exist and affects a field almost never filled; ticket it if a tenant uses `transfer_cost` with write-offs.

Precision (rule 19, `docs/architecture/precision-contract.md` §Storage/§Service): all quantities numeric strings; `bccomp`/`bcadd`/`bcsub` at `QTY_SCALE = 4`; derived sums rounded once with `QuantityScale::round(…, 4, FLOOR)` as the readers do (`LocationStockQueryService.php:160,242`); display via `QuantityScale::formatForUnit` (`:72`). No float anywhere; `ForbidFloatCastOnDecimalProperty` guards the models.

---

## 7. Notifications (T-4 minimum)

Both `Illuminate\Notifications\Notification implements ShouldQueue` (precedent `UserInvitation.php:14`; tenancy on the worker via `QueueTenancyBootstrapper`, `config/tenancy.php:42`), `via = ['database']`, `databaseType()` and `toDatabase()` as `TreasuryAlertNotification.php:22-40`, `public bool $afterCommit = true`. Recipients are resolved in the listener, in the request (inside `DB::afterCommit`), never in the worker (rule 20: no `CompanyContext`, no Spatie team in the job) — the notification receives only ids and display strings.

- `TransferInitiatedNotification` — type `inventory.transfer.initiated`; data `{transfer_id, transfer_number, source_location_name, destination_location_id, destination_location_name, line_count, initiated_by_name, blind: bool}` (no quantities: the bell must not leak in blind mode). Recipients: users with a membership in the company whose `allowed_location_ids` is null or contains the destination (`LocationContext.php:194-207` semantics, queried on `user_company_memberships`) AND `inventory.transfers.complete`, minus the initiator. Listener on the plain `StockTransferInitiated` (already `afterCommit`, `:608`).
- `TransferReceivedWithDiscrepancyNotification` — type `inventory.transfer.received_with_discrepancy`; sent only when `hasDiscrepancy` (short or damaged) on `StockTransferReceivedV1`, and on `StockTransferClosedWithWriteoffV1` (type `inventory.transfer.closed_with_writeoff`); data `{transfer_id, transfer_number, receipt_id, receipt_number, destination_location_name, lines_with_discrepancy, received_by_name}` — counts only, quantities live behind `inventory.transfers.reconcile`. Recipients: `inventory.adjust` holders with access to the destination location. v1 tolerance = 0 (any discrepancy notifies); a threshold setting is deferred.
- Web: `NotificationPanel.tsx:45-63` switch gains the three types with `t()` labels and a link to the transfer detail; i18n in the `notifications` namespace (`notificationsI18n.test.ts` guards fr/en/ar parity). Query keys stay `tenantScopedKey` (`useNotifications.ts:23,34`).
- No push, no mail.

---

## 8. Web (`apps/web/src/features/stock-transfers/`)

- Types: `types/index.ts:8` already says the hand-written interfaces should be replaced by generated output. This lane adds PHP DTOs `StockTransferData`, `StockTransferLineData`, `StockTransferLineBatchAllocationData`, `StockTransferReceiptData`, `StockTransferReceiptLineData`, `TransferReceiverViewData`, `TransferReconciliationData`, `CompanyFraudSettingsData.blind_receiving`, runs `php artisan typescript:transform`, and deletes the local `StockTransfer*` interfaces (`:21,36`) in favour of `@autoerp/shared/types/generated` (convention 11 rule 4). The receiver-view TS type carries the counting guard comment (`countingApi.ts:171-197` wording).
- API (`stockTransferApi.ts:45-51` pattern, `apiPost` unwraps once — rule 14): `receive(id, payload)`, `close(id, payload)`, `receiverView(id)`, `reconciliation(id)`; hooks `useReceiveStockTransfer`, `useCloseStockTransfer`, `useTransferReceiverView`, `useTransferReconciliation` with `tenantScopedKey([...])` keys and invalidation of the transfer, list, stock-levels and notifications keys.
- Detail page (`StockTransferDetailPage.tsx`): the Complete button (`:106`) stays as "Receive all"; new **Receive** button opens `ReceiveTransferDialog` (canonical `ConfirmDialog`/`Dialog`, `DataTable`, `QuantityInput` for received and damaged — strings, unit decimals from `quantity_decimals`, ESLint `no-parsefloat-on-money`/`no-raw-quantity-input`), a `Select` for `discrepancy_reason` enabled when damaged > 0, lot rows under batch-tracked lines (lot identity from `lots[]`, quantities typed by the receiver), an `idempotency_key` generated once per dialog open (`crypto.randomUUID()`), and a **Close with write-off** `ConfirmDialog` (reason `Select` + note `Textarea`, `inventory.transfers.close`). When the payload is the receiver shape (`blind: true`), the lines table has no expected/remaining columns — because the keys do not exist, not because they are hidden; the table columns are derived from the payload discriminator, never from a client-side setting read. A **Reconciliation** tab (`RequirePermission inventory.transfers.reconcile`) renders §5.4 with variance cells in `semanticColorTokens` (design tokens, rule 18). Status badge (`StockTransferStatusBadge.tsx`) gains the two statuses.
- i18n: namespace `stock-transfers` (registered `lib/i18n.ts:52,108,218,275`), keys `receive.title/lines/received/damaged/reason/lots/submit/success/errors.{OVER_RECEIPT,…}`, `close.*`, `reconciliation.*`, `status.partially_received`, `status.closed_with_writeoff`, `discrepancyReason.{short_shipped,lost_in_transit,damaged_in_transit,other}` in en/fr/ar (verify the ar namespace registration at lane time — the grep shows en/fr imports only).
- Settings: `CashDrawerControlsSection.tsx:13,52` pattern — a `Checkbox` "Blind receiving" in a new `ReceivingControlsSection` on `FraudSettingsPage.tsx`, wired through `fraudApi.ts`; i18n in `compliance`.
- Tests: Vitest for the dialog (submits strings, disables submit when Σ = 0, reason required when damaged > 0), for the blind rendering (no expected column when `blind: true`), and for the reconciliation tab.

---

## 9. Mobile (M-1 later) — contract commitments only

- The mobile receive screen will use ONLY `GET /stock-transfers/{id}/receiver-view` and `POST /stock-transfers/{id}/receive` with a client-generated `idempotency_key` per submission; offline replay (counting's `pendingCountSyncService.ts` pattern) resends the same key and treats HTTP 200 + `meta.replayed` as success.
- `erp-mobile/src/features/receiving/types.ts` gets, on `ReceiptStatusLine` (`:62-63`) and `MergedReceiptLine` (`:106-108`), the comment that these are SUPERVISOR shapes, plus a new `TransferReceiverLine` / `PurchaseOrderReceiverLine` pair carrying `// NEVER INCLUDE: quantity, quantity_ordered, quantity_remaining, quantity_sent` (the `countingApi.ts:171-173,190-196` wording). T-3b adds `GET /purchase-orders/{id}/receiver-view` built by a `PurchaseOrderReceiverPayloadBuilder` under the same `blind_receiving` setting and the same visibility rule (`inventory.transfers.reconcile` is transfer-specific; T-3b names its own permission).
- Discrepancy reason on mobile is required only with damaged > 0 (same as the API); `close` is not a mobile action.

---

## 10. Test matrix (PHPUnit under `tests/Feature/Inventory/`, lane `feature-lane-inventory/Inventory`, `tests/feature-lane-manifest.json:161-168`; PG-only cases mark-skip on SQLite; existing transfer tests listed there, e.g. `InventoryTransferServiceTest.php`, `StockTransferIdempotencyCollisionPostgresTest.php`, keep passing)

| # | Test | Asserts (data meaning) |
|---|---|---|
| S1 | second company | Company B (real `POST /api/v1/companies` path) initiates and receives its own transfer; receipt numbers `TRR-…-0001` in both companies; company A's receipts/reconciliation 404 in B; `blind_receiving` on in A does not blind B |
| S2 | second location | Destination = a second `pos_enabled` location; `stock_levels` row appears on THAT location; the first/default location is untouched; the incoming column of the location matrix drops on that location only |
| S3 | re-run / idempotency | Same body twice → one receipt, one set of movements, 200 + `meta.replayed`; same key different body → `IDEMPOTENCY_KEY_REUSED`, no rows; `complete` twice → second replays |
| T1 | partial then complete | 12 sent; receive 5 → `partially_received`, remainder 7 in all three readers (`LocationStockQueryService`, `StockMatrixQueryService`, `WeightedAverageCostService::companyOwnedQuantity` unchanged); receive 7 → `completed`, remainder 0, `StockTransferCompleted` fired once, freight capitalized once |
| T2 | over-receipt | receive 13 of 12 → `OVER_RECEIPT`, zero movements; after 5 received, receive 8 → `OVER_RECEIPT` |
| T3 | damaged | receive 11 + 1 damaged → destination on-hand +11, movements: TransferIn 12, Damage 1 with `reference_type = stock_transfer_receipt`, one journal entry Dr Shrinkage / Cr Inventory at WAC; `has_discrepancy` true; notification row for `inventory.adjust` holders at destination only |
| T4 | close with write-off | 5 of 12 received, close `lost_in_transit` → status `closed_with_writeoff`, `quantity_written_off = 7`, on-hand at destination still 5, one WriteOff movement + one GL leg, company-owned quantity down by 7 exactly once (I2), remainder 0 in all readers |
| T5 | state rules | close then receive → `INVALID_TRANSFER_STATE`; cancel on `partially_received` → 422; close on `completed` → 422; receive on `draft`/`cancelled` → 422 |
| T6 | lots | received lot ∉ shipped allocations → `UNKNOWN_LOT`; lot over its allocation → `LOT_OVER_RECEIPT`; batch-tracked line without `lots` → `LOT_REQUIRED`; per-lot remainder after partial equals allocation − received − damaged |
| T7 | concurrency (PG) | two receivers post 8 and 8 of 12 in parallel → one 201, one `OVER_RECEIPT`; header row lock serializes (`lockForUpdate`, `:734`) |
| T8 | precision | line `0.0003` sent; receive `0.0001`, `0.0001`, `0.0001` → completed; readers sum with `bcadd` at 4 dp, no float in any payload (all quantities are strings) |
| T9 | blind leakage (recursive key scan) | with `blind_receiving` on, a restricted destination user: `/receiver-view`, `GET /stock-transfers/{id}`, the list, the `receive` 201 echo, and every 422 envelope contain NO key in `{quantity, quantity_remaining, quantity_sent, remaining, ordered, already_received, unit_cost_snapshot, allocated_transfer_cost}` at any depth, including `lots[]`; the same user with `inventory.transfers.reconcile`, and an unrestricted user, see them; with the setting off everyone sees them |
| T10 | notifications | initiator excluded from `inventory.transfer.initiated`; recipients limited to destination-accessible memberships with `inventory.transfers.complete`; no notification when a receipt has no discrepancy; `afterCommit` — a rolled-back receipt sends nothing |
| T11 | backfill | migration on a fixture with completed / in_transit / cancelled transfers → completed lines and allocations get `quantity_received = quantity`, others 0; readers' totals identical before and after |
| T12 | readers ratchet | `TransferInTransitReadersUseRemainderTest` fails on a literal `TransferStatus::InTransit` in a reader (liveness fixture) |
| T13 | GL boundary | `InventoryGlPostingBoundaryGuard::assertEmpty` at the request boundary after receive/close; no direct `postFor*` (PHPStan) |
| T14 | replenishment | a request settled on initiate stays `fulfilled` after a write-off close (documents OQ-2's current answer) |

Reviewers: `inventory-costing-reviewer` + `stock-gl-interaction-reviewer` (GL leg) + `tenancy-authz-reviewer` (visibility rule, new permissions) + `frontend-conventions-reviewer` (web).

---

## 11. Migration and rollout

1. Migrations (tenant path, `tenants:migrate` runs on every push to `origin/dev` = staging auto-deploy): (a) add columns + CHECKs to lines and allocations, guarded by `Schema::hasColumn`; (b) create the three receipt tables, guarded by `Schema::hasTable`; (c) add `stock_transfers.closed_*`; (d) `company_fraud_settings.blind_receiving` default false; (e) backfill: `UPDATE stock_transfer_lines SET quantity_received = quantity WHERE transfer_id IN (SELECT id FROM stock_transfers WHERE status = 'completed') AND quantity_received = 0`, same for allocations — idempotent by the `= 0` predicate; cancelled/in_transit untouched (their remainder derivation is correct at 0 because the status scope excludes cancelled).
2. Order inside the lane: readers switched to `REMAINDER_SQL` in the SAME merge as the backfill (a reader switched before backfill would show completed transfers as incoming).
3. No feature flag: the endpoints are new; `complete` keeps its contract; `blind_receiving` defaults off.
4. Permissions: seeder rows + `permission:cache-reset` in the deploy checklist; `docs/handoff/PROMOTION-CHECKLIST-*` gets the two steps.
5. `REALIGNMENT-LOG.md` entry (root rule 9): new public endpoints and the `TransferStatus` value set.
6. `docs/glossary.md` rows of §2 land in the same PR; `packages/shared/types/generated.d.ts` regenerated.

---

## 12. Open questions for the owner

- **OQ-1 Return-to-source on close.** Confirmed default is write-off only. When the remainder physically comes back to the source (truck returned), a write-off is wrong and today's `cancel` is refused after a partial receipt. Recommend adding `disposition: write_off | return_to_source` to `POST …/close` in this lane (reuses the cancel restock loop `:450-482`, no GL). Ruling needed: include now, or ticket.
- **OQ-2 Replenishment after write-off.** Settlement happens on initiate; a close with write-off leaves the request `fulfilled` although the destination did not get the goods. Recommend: leave as is for v1 and notify `replenishment.process` holders in T-4 (the requester re-raises); alternative is a `ReopenRequestsOnTransferClosedWithWriteoff` listener for the written-off quantity (needs partial-quantity reopen, which the request model lacks today).
- **OQ-3 Status visibility in blind mode.** The receiver view hides quantities but the status `partially_received` on the list tells a blind receiver that something is missing (not how much). Recommend accepting this coarse signal (counting likewise reveals that a recount is happening); alternative is to collapse it to `in_transit` in the receiver builder.
