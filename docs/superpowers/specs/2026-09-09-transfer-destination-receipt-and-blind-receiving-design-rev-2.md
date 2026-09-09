# Transfer destination receipt (partial + discrepancy), close dispositions and blind receiving — design spec rev 2 (lanes T-2, T-3, T-4-min)

Date 2026-09-09 · Status: REV 2 for Codex gate round 2 · Supersedes rev 1 (`2026-09-08-transfer-destination-receipt-and-blind-receiving-design.md`, left untouched) · Gate r1: `docs/superpowers/reviews/2026-09-09-t2-t3-spec-codex-gate-r1.md` · Owner rulings 2026-09-09: `docs/handoff/OWNER-QUESTIONS-consolidated-2026-09-06.md` §"T-2/T-3 spec rulings" (D1, D2, OQ-1, OQ-2, OQ-3, pattern detection) · GL benchmark: `docs/superpowers/reviews/2026-09-09-benchmark-transfer-discrepancy-gl.md`. Every `path:line` below was re-derived at HEAD **`ffd907b3d`** (2026-09-09). Paths are relative to `apps/api/` unless prefixed `apps/web/`, `packages/`, `docs/` or `erp-mobile/` (sibling repo `/Users/houssamr/Projects/syneriva/erp-mobile`, HEAD `51e3445`).

## Rev 2 change log (gate r1 finding → disposition → rev-2 anchor)

| Finding | Disposition | Rev-2 anchor |
|---|---|---|
| B1 blind not closed over every surface | CLOSED — blind guarantee defined precisely; 13-surface closure table; receipt DTO defined; `complete` 200 gated; 422 messages static; incoming aggregates masked; entry/exit notes location-scoped; reconciliation gets `canSeeTransfer` | §5.0, §5.3, §5.6, §5.7, §10 T9 |
| B2 events insufficient for replay | CLOSED — per-line stored event `StockTransferReceiptLineRecordedV1` (owner pattern-detection requirement) + header events carry previous/new state, movement ids, lot facts; events persisted synchronously inside the transaction; replay contract stated | §4 |
| B3 replay order / key race / hash | CLOSED — idempotency lookup precedes the state check; unique-violation catch + committed-winner reread (initiate precedent); canonical JSON defined (JCS-shape, 4-dp quantities, sorted lines/lots, absent≡null) | §5.1 rules 1–3, §3.6 I6 |
| M1 I2/I5 wrong | CLOSED — I2 rewritten on the implemented metric (on-hand + in-transit); I5 restricted to carrying states, Cancelled excluded | §3.6 |
| M2 remainder vs short conflated | CLOSED — open remainder (no reason, no alert) vs confirmed discrepancy (damaged at receipt; short at close with mandatory reason + alert) | §1 definitions, §5.1 rule 5, §5.2, §7 |
| M3 GL kind for non-batch lines | CLOSED — `MovementGlKind::Exit` for lot-less lines, `BatchWriteOff` with the real `batch_number` for lot lines; flush inside the root transaction | §6.2 |
| M4 freight on total write-off | CLOSED with a DEFAULT (owner has not ruled): weights on landed quantity; written-off/returned share never capitalized; listed as the single open owner question | §6.4, §12 |
| M5 authz / module gating | CLOSED — reconciliation requires `canSeeTransfer` + endpoint access; web routes get `moduleKey="inventory"`; D1 accepted (reconcile seeded to manager + admin, grantable); permission map regenerated | §5.4, §5.8, §8, §11 |
| M6 schema integrity | CLOSED — "three new tables"; partial unique indexes on every movement FK; `pg_constraint`/`IF NOT EXISTS` guards; every `CompanyFraudSettings` surface listed | §3.1, §3.2, §5.5, §11 |
| M7 ratchet fails on writer code | CLOSED — ratchet scoped to the three reader classes, with liveness fixture | §3.5 |
| M8 FE exhaustiveness sites | CLOSED — `STATUS_OPTIONS` and `canComplete`/`canReceive` derived from the generated union | §8 |
| M9 notification identity / deep link / worker test | CLOSED — deterministic uuid5 notification id (PK-enforced dedupe), `deep_link`, `KNOWN_TYPES`, context-cleared worker test | §7, §10 T10 |
| M10 test matrix gaps | CLOSED — S1–S3 extended to close (both dispositions) and the setting writer/reset; T7b/T7c mixed concurrency | §10 |
| M11 benchmark rows | CLOSED — rows B9–B15 added (idempotent posting, re-run, second company, second location, correction/reversal, return-to-source, receipt audit for pattern detection) | §0 |
| m1 ingress "strings only" | CLOSED — backend `numeric` + regex accepts numbers and strings; FE sends strings | §5.1 request |
| m2 blind cache identity | CLOSED — settings mutation invalidates transfer keys; `staleTime: 0` on transfer detail/list; shape derived from payload discriminator | §8 |
| m3 Arabic fallback | CLOSED — English fallback for `stock-transfers` preserved and stated; `notifications` keys in en/fr/ar | §8 |
| m4 correction overclaim | CLOSED — claim removed; correction paths stated honestly, `void` ticketed | §1 out of scope |
| Citation audit (8 rows) | CLOSED — every citation re-derived at `ffd907b3d`; mobile cites carry the sibling-repo prefix; `countingApi.ts` precedent replaced by the backend builders | throughout |
| Gate owner decisions 1–3 | 1 = OQ-3 ACCEPT (owner); 2 = D1 ACCEPTED (owner); 3 = default in §6.4, open in §12 | §5.3, §5.8, §12 |
| Gate "OQ-1 stays out of scope" | REJECTED by owner ruling: `disposition: write_off \| return_to_source` is IN scope, no GL on return | §5.2, §6.3 |

Nothing under the gate's "Preserve" list changed: Odoo-style remainder + hard over-receipt refusal; company-level opt-in `blind_receiving`, transfers first; DB notifications only; destination membership, no assignment; three-reader remainder conversion, backfill, cancelled exclusion, scale-4 strings, bcmath; immutable existing events; separate full/receiver builders; dedicated `inventory.transfers.reconcile`; one receipt writer with `complete` as delegate; partial receipts not cancellable.

Vocabulary line (convention 11): Transfer (glossary row NEW — `docs/glossary.md` has no "Stock operations" section today, headings at `:13,22,32,48,67,80`), Transfer receipt (NEW), In-transit remainder (NEW), Discrepancy (NEW), Close (NEW, two dispositions), Blind receiving (NEW), Receiver view (NEW), Reconciliation (NEW; counting already uses it, `app/Modules/Inventory/Application/Services/CountingDiscrepancyReportService.php:21`), Receiver note (ticketed, D2).

---

## 0. Industry baseline (benchmark-first — convention 10)

Flow: inter-site stock transfer — ship, receive at destination, handle short/over/damaged, close the remainder (write-off or return), blind receipt, notify, audit. Reference systems: Odoo 17/18, ERPNext v15, Dolibarr (brief §0 sources + the GL benchmark note; doc-page reads, none from memory).

| # | Guarantee | Odoo 17/18 | ERPNext v15 | Dolibarr | AutoERP today (path:line @ ffd907b3d) | Gap | Decision |
|---|---|---|---|---|---|---|---|
| B1 | Transfer is two-step: ship, then destination **confirms received quantities** | Transit location: delivery at source + receipt at destination | Material Transfer, *Add to Transit* → *Receive at Warehouse* | No in-transit; reception is PO-only | `store` = initiate = decrement source + `in_transit` (`app/Modules/Inventory/Application/Services/StockTransferService.php:86,514,602`); `complete` takes **no quantities** (`:317,346-383`); no receipt document | MISSING | **MATCH (Q1)** — `POST /stock-transfers/{id}/receive`, receipt document (§3.2) |
| B2 | Partial receipt / remainder | *Done < Demand* → backorder prompt | Remainder stays in transit; further receipts | Partial PO reception | None on transfers; PO receipts have `partially_received`/`fully_received` (`app/Modules/Inventory/Application/Services/GoodsReceiptService.php:1233-1234`) | MISSING | **MATCH Odoo backorder (Q1)** — remainder stays in transit (`partially_received`); receive again or close |
| B3 | Discrepancy (short / over / damaged) | Short = backorder or scrap; over by editing Done | Rejected qty + Rejected Warehouse; over-receipt allowance % | "Correct Stock" | PO receipt refuses over-receipt (`GoodsReceiptService.php:330-345`, `app/Modules/Inventory/Domain/Enums/GoodsReceiptFailureReason.php:9`); transfers: no discrepancy columns (`database/migrations/tenant/2026_05_28_120000_create_stock_transfers_table.php:86`) | MISSING | **Q2: over REFUSED (typed 422); damaged at receipt with reason; short confirmed only at close with reason + alert** (§1 definitions) |
| B4 | **Blind receiving** | Not native (Ventor PRO) | Not native | Not native | Absent for receipts; PO mobile types expose `quantity_ordered/remaining` (`erp-mobile/src/features/receiving/types.ts:60-65,104-109`); present for counting (`app/Modules/Inventory/Presentation/Controllers/InventoryCountingController.php:335-377`, `CountingItemController.php:54-74,177`) and cash (`app/Modules/Compliance/Domain/CompanyFraudSettings.php:58`) | MISSING (deliberate DIVERGE in our favour) | **Q3: `company_fraud_settings.blind_receiving` (default false); transfers first (T-3), PO receipts second (T-3b)** |
| B5 | Notifications on stock events | Chatter + optional email | Doc-event notifications | Agenda/email | None for transfers; DB-notification module read-only (`app/Modules/Notification/Presentation/routes.php:22-30`), web bell by `type` (`apps/web/src/features/notifications/components/NotificationPanel.tsx:45-71`) | MISSING | **Q4: DB channel + web bell; push later.** Two notifications (§7) |
| B6 | Who may receive | Rights on the destination operation type | Warehouse permissions | Warehouse permission | `canAccessLocation(destination)` (`app/Modules/Inventory/Presentation/Controllers/StockTransferController.php:221`, `app/Modules/Company/Services/LocationContext.php:224-238`); `canSeeTransfer` (`:347-357`) | ALREADY | **Q5: destination membership + `inventory.transfers.complete`; no assignment** |
| B7 | Cancel after goods moved | Done picking cannot be cancelled | Not after receipt entry | — | `canBeCancelled()` = Draft \| InTransit (`app/Modules/Inventory/Domain/Enums/TransferStatus.php:37-40`); cancel from in_transit restocks source (`StockTransferService.php:441-481`) | ALREADY | ALREADY — `partially_received` NOT cancellable; remainder handled by close (§3.4) |
| B8 | Receipt audit trail immutable | Stock moves + chatter | Stock Ledger Entries | Stock movements | Transfer events are plain `Dispatchable` (`app/Modules/Inventory/Domain/Events/StockTransferInitiated.php:9-11`); PO `GoodsReceived` is stored (`GoodsReceived.php:18`, `app/Shared/Domain/Events/DomainEvent.php:16`) | PARTIAL | MATCH — stored header + per-line events (§4) |
| B9 | Duplicate / idempotent posting: the same receipt submitted twice lands once | Validate is guarded by picking state | Stock Entry submit is idempotent by docstatus | — | Transfers: `(tenant_id, company_id, idempotency_key)` on initiate + unique-violation reread (`2026_05_28_120000_…:67`, `StockTransferService.php:170-190`); PO receipts: `NOTHING_TO_RECEIVE` (`GoodsReceiptFailureReason.php:21`) | PARTIAL (no receipt yet) | MATCH — required `idempotency_key` per receipt/close, replay 200 `meta.replayed` (§5.1 rules 1–3) |
| B10 | Re-run safety (migrations, backfill, seeders) | n/a | n/a | n/a | Column/table guards are the repo norm; CHECK/index guards exist (`database/migrations/tenant/2026_08_08_120000_create_stock_adjustments_tables.php:130-139` raw indexes) | — | MATCH — every DDL statement self-guarding; backfill idempotent by predicate (§11) |
| B11 | Second company: receipts, numbering, settings and blind mode are per company | Per company | Per company | Per entity | Transfer number unique per company (`2026_05_28_120000_…:66`); GRN numbering per company (`…create_goods_receipts_tables.php:30`) | — | MATCH — every unique key carries `company_id`; S1 (§10) |
| B12 | Second location: stock lands on the selected destination only | Per picking location | Per warehouse | Per warehouse | Destination is a transfer attribute (`2026_05_28_120000_…:45`) | — | MATCH — S2 (§10) |
| B13 | Correction / reversal of a posted receipt | Return picking (reverse transfer) | Cancel + amend Stock Entry | Manual correction | No receipt yet; stock adjustment document corrects on-hand only (`app/Modules/Inventory/Application/Services/StockAdjustmentDocumentService.php:522-531` refuses destructive lot-less lines on batch products) | MISSING | DIVERGE v1: under-report → further receipt; over-report → no `void` (ticketed); on-hand via stock adjustment, receipt rows immutable (§1) |
| B14 | Remainder physically returned to source: stock moves back, **no P&L** | Return = internal move transit → source, no journal | Receive back into source warehouse, no P&L | Stock moved, no posting | Cancel restock loop exists but only from `in_transit` (`StockTransferService.php:441-481`) | MISSING | **MATCH (OQ-1 owner)** — `disposition: return_to_source` on close, stock movement only, no GL (§5.2, §6.3) |
| B15 | Every receipt carries who received what against what was sent, queryable for pattern detection | Stock moves carry user + qty | SLE carries user | — | No receipt facts anywhere; `StockMovementRecordedV2` has no receiver/variance semantics (`app/Modules/Inventory/Domain/Events/StockMovementRecordedV2.php:22-37`) | MISSING | **MATCH (owner 2026-09-09)** — per-line stored event with receiver identity + sent/received/damaged/written_off/returned/reason/blind (§4.2, §4.4) |

Domain norm: blind receiving is a WMS control against confirmation bias — the receiver counts without seeing the ASN quantity; the system compares afterwards and routes variances to a supervisor (same rationale as blind cash and blind counting in this codebase).

Second-of-everything (convention 09): §10 S1–S3 (second company, second location, re-run) cover receive, close (both dispositions), `complete` and the fraud-setting writer. No `CATALOGUE_TABLES` entity is touched; every unique key on the three new tables carries `company_id`, so the ratchet needs no baseline entry.

---

## 1. Problem, definitions, scope

**Problem.** A transfer is decremented at source on `store` and incremented at destination by a quantity-less `complete` (`StockTransferService.php:346-383`): the receiver cannot say "11 of 12 arrived, one broken". No receipt document exists, so nothing can be blind; the three in-transit readers derive incoming from `status = in_transit` alone (`app/Modules/Inventory/Application/Services/LocationStockQueryService.php:144,228`, `StockMatrixQueryService.php:402`, `WeightedAverageCostService.php:124`) and cannot express a partial receipt. Nobody is told a transfer is coming or arrived short.

**Definitions (M2 — two different things).**
- **Open remainder**: per line, `sent − received − damaged − written_off − returned > 0` while the transfer is `in_transit` or `partially_received`. Receiving 5 of 12 means 7 are still on the truck (Odoo backorder). No reason is asked, no alert is raised, `has_discrepancy` stays false.
- **Confirmed discrepancy**: (a) **damaged** units declared on a receipt — reason required at receipt, alert at receipt; (b) **short** units confirmed by a **close** — the supervisor states the remainder will never arrive (write-off) or went back (return), reason required, alert at close. Shortness is never asked of a receiver (a blind receiver cannot know it — D2 accepted).

**In scope.**
- T-2: receipt document (per-line received/damaged, lot grain); remainder stays in transit; explicit close with `disposition: write_off | return_to_source` (OQ-1 owner ruling); quantity-based in-transit derivation; stored header + per-line events sufficient for replay and pattern analytics; `complete` kept as the zero-discrepancy delegate.
- T-3: `blind_receiving` setting; receiver view by construction; blind closure over every reachable surface (§5.0); supervisor reconciliation; mobile contract. PO receiver view (T-3b) reuses the builder split and the setting; contract only (§9).
- T-4-min: the two DB notifications (§7).

**Out of scope** (separate lane/ticket each): push/Expo (T-4); `ReplenishmentRequestedNotification` (T-4); mobile screens (M-1); T-3b implementation; inter-company transfers (`StockTransferService.php:96-101` still refuses); over-receipt tolerance; discrepancy alert threshold (v1 = any); **receipt `void`/reversal** — an over-reported receipt has no reversal in v1: a supervisor corrects on-hand with a stock-adjustment document, while receipt rows, line counters, reconciliation and terminal state stay as posted (m4: no claim that the adjustment corrects the receipt document); **receiver note** (D2 owner follow-up: nullable `receiver_note` on `stock_transfer_receipt_lines`, written by the receiver after submit, never gating, never revealing expected — ticket T-2b); **analytics read model / dashboard** for mis-receiving patterns (T-4 or later) — this lane only guarantees the stored facts and ships the reference query (§4.4); GL account provisioning for shrinkage (exists: `app/Modules/Accounting/Application/Services/InventoryVarianceAccountProvisioner.php:168`, purpose `InventoryShrinkageExpense`, `SystemAccountPurpose.php:67`).

---

## 2. Vocabulary — glossary rows (`docs/glossary.md`, new section "Stock operations", same lane)

| Term | Definition | Table / module | Canonical surface | Synonyms |
|---|---|---|---|---|
| **Transfer** | Intracompany movement of stock from a source to a destination location: source decremented on initiate (`in_transit`), destination incremented by one or more transfer receipts. | `stock_transfers`, `stock_transfer_lines`, `stock_transfer_line_batch_allocations` / Inventory | Stock → Transfers; single writer `StockTransferService` (initiate/cancel) + `StockTransferReceiptService` (receive/close) | transfert, stock transfer |
| **Transfer receipt** | Posted, immutable document recording what a destination physically received from one transfer at one moment (per line received, damaged, lots, reason). A transfer may have several. | `stock_transfer_receipts`, `…_lines`, `…_line_lots` / Inventory | Transfer detail → Receive; `POST /stock-transfers/{id}/receive`; writer `StockTransferReceiptService::receive` (`complete` delegates) | réception de transfert |
| **In-transit remainder** | Per line/lot: `quantity − received − damaged − written_off − returned`; the only definition of "incoming" for stock readers; positive only in `in_transit`/`partially_received`. | derived; `StockTransferLine::REMAINDER_SQL` | Matrix/location "incoming"; detail "remaining" (visibility-gated) | reste à recevoir, backorder |
| **Discrepancy** | Confirmed variance: damaged (at receipt) or short (at close). Carries `TransferDiscrepancyReason`. Over-receipt is refused, never recorded. An open remainder is NOT a discrepancy. | columns on `stock_transfer_receipt_lines` | Receive dialog (damaged + reason); close dialog; reconciliation | écart, variance |
| **Close** | Terminal action on a transfer with an open remainder, one disposition for the whole remainder: **write-off** (remainder written off at destination, GL shrinkage) or **return to source** (remainder restocked at source, no GL). Writes a receipt row of kind `close`. | `stock_transfers.status/closed_*`, `stock_transfer_receipts.kind = close`, `disposition` | Transfer detail → Close; `POST /stock-transfers/{id}/close` | clôture, close backorder |
| **Blind receiving** | `company_fraud_settings.blind_receiving` (default false): when on, a destination user without `inventory.transfers.reconcile` cannot learn the expected/remaining quantity of an open transfer line from any endpoint before their receipt is posted (§5.0 guarantee). | `company_fraud_settings.blind_receiving` / Compliance | Settings → Fraud & controls | réception à l'aveugle |
| **Receiver view** | Receiver-facing projection: identity, lines, lot identity, own prior receipts — never sent/expected/remaining. Built only by `TransferReceiverPayloadBuilder`. | projection / Inventory | `GET /stock-transfers/{id}/receiver-view`; detail page + mobile when blind applies | vue réceptionnaire |
| **Reconciliation** | Supervisor comparison sent vs received/damaged/written-off/returned/remaining per line and lot with reasons. Consumers: counting (`CountingDiscrepancyReportService`) and transfers (`TransferReconciliationService`); read-only projections. | projection / Inventory | `GET /stock-transfers/{id}/reconciliation`; detail Reconciliation tab | rapprochement |

---

## 3. Domain model and schema contract

### 3.1 New columns (additive; tenant path `database/migrations/tenant/`; each statement self-guarding — columns `Schema::hasColumn`, tables `Schema::hasTable`, CHECKs `SELECT 1 FROM pg_constraint WHERE conname = ?`, indexes `CREATE UNIQUE INDEX IF NOT EXISTS`; M6)

`stock_transfer_lines` (today `quantity decimal(15,4)`, `2026_05_28_120000_…:86`; cast `decimal:4`, `app/Modules/Inventory/Domain/StockTransferLine.php:59`): `quantity_received`, `quantity_damaged`, `quantity_written_off`, `quantity_returned` — all `decimal(15,4) NOT NULL DEFAULT 0`. PG CHECK `stock_transfer_lines_received_within_sent`: each ≥ 0 AND `received + damaged + written_off + returned <= quantity` (same `DB::getDriverName() === 'pgsql'` guard as `:96-99`).

`stock_transfer_line_batch_allocations` (`quantity decimal(15,4)`, unique `(stock_transfer_line_id, batch_id)`, `2026_06_05_121000_create_stock_transfer_line_batch_allocations_table.php:25,28`): the same four columns + CHECK, so the lot-grain remainder is derivable and "received lots ⊆ shipped allocations" is enforceable per lot.

`stock_transfers`: `status` stays `string(20)` (`:42`) — `closed_with_writeoff` = 20 chars, `closed_returned` = 15; no ALTER. New nullable `closed_by_user_id` (FK users nullOnDelete, as `:56-57`), `closed_at timestampTz`, `close_disposition string(20)`, `close_reason string(32)`, `close_note text`.

`company_fraud_settings.blind_receiving boolean NOT NULL DEFAULT false`. Model surfaces that MUST change together (M6): `$attributes` (`CompanyFraudSettings.php:53-64`), `@property` block (`:36`), `$fillable` (`:108-120`), `casts()` (`:131-144`), `getDefaults()` + its array-shape PHPDoc (`:165-185`); DTO `CompanyFraudSettingsData` ctor/`fromModel`/`fromDefaults` (`app/Modules/Compliance/Application/DTOs/CompanyFraudSettingsData.php:44,75,105`); controller validation (`app/Modules/Compliance/Presentation/Controllers/FraudSettingsController.php:120`) and `reset` which rebuilds from `getDefaults()` (`:194-203`).

### 3.2 Three new tables (M6)

`stock_transfer_receipts` — one row per posted receipt or close: `id uuid PK`, `tenant_id uuid` (plain indexed, no FK — `2026_05_28_120000_…:31-36` rationale), `company_id` FK companies, `transfer_id` FK stock_transfers restrictOnDelete, `receipt_number string(64)`, `kind string(20)` (`TransferReceiptKind`), `disposition string(20) null` (`TransferCloseDisposition`, non-null iff `kind = close`), `status string(20)` (`TransferReceiptStatus`), `sequence smallint` (1-based per transfer), `is_blind boolean` (snapshot of `canSeeExpected` = false for the actor at post time), `has_discrepancy boolean`, `idempotency_key string(128) NOT NULL`, `payload_hash char(64) NOT NULL`, `received_by_user_id` FK users restrictOnDelete, `received_at timestampTz`, `notes text null`, `freight_uncapitalized decimal(15,4) NOT NULL DEFAULT 0` (§6.4), `timestampsTz`.
Uniques: `(tenant_id, company_id, receipt_number)`, `(tenant_id, company_id, idempotency_key)`, `(transfer_id, sequence)`. Indexes: `(tenant_id, company_id, transfer_id)`, `(transfer_id, received_at)`. PG CHECK `(kind = 'close') = (disposition IS NOT NULL)`.

`stock_transfer_receipt_lines`: `id`, `receipt_id` FK cascade, `transfer_line_id` FK, `tenant_id`, `company_id`, `product_id`, `variant_id null`, `quantity_received`, `quantity_damaged`, `quantity_written_off`, `quantity_returned` (`decimal(15,4)`), `quantity_sent_snapshot decimal(15,4)` (line `quantity` at post time — audit only, never projected to a blind actor), `discrepancy_reason string(32) null`, `discrepancy_note text null`, `in_movement_id uuid null` (TransferIn at destination), `scrap_movement_id uuid null` (Damage/WriteOff at destination), `return_movement_id uuid null` (TransferIn at source on return), `timestampsTz`. Unique `(receipt_id, transfer_line_id)`. **Partial uniques** (precedent `2026_08_08_120000_…:137`, `2026_07_04_100000_create_goods_receipts_tables.php:62-63`): `…_in_movement_unique ON (in_movement_id) WHERE in_movement_id IS NOT NULL`, same for `scrap_movement_id`, `return_movement_id`. PG CHECK: all four ≥ 0, `received + damaged + written_off + returned > 0` (a zero line is not stored), and `(written_off > 0 OR returned > 0) → received = 0 AND damaged = 0` (close lines carry only close quantities).

`stock_transfer_receipt_line_lots`: `id`, `receipt_line_id` FK cascade, `batch_allocation_id` FK, `tenant_id`, `company_id`, `batch_id int`, the same four quantities, `in_movement_id null`, `scrap_movement_id null`, `return_movement_id null` (same partial uniques). Unique `(receipt_line_id, batch_allocation_id)`.

Numbering: `DocumentNumberingService::generateForKey($tenantId, $companyId, 'stock_transfer_receipt', 'TRR')` (`app/Modules/Document/Domain/Services/DocumentNumberingService.php:29`; precedent `GoodsReceiptService.php:272-275`), format `TRR-{YYYY}-{NNNN}` per company.

### 3.3 Enums (rule 9)

- `TransferStatus` (`TransferStatus.php:17-20`) gains `PartiallyReceived = 'partially_received'`, `ClosedWithWriteoff = 'closed_with_writeoff'`, `ClosedReturned = 'closed_returned'`. `isTerminal()` (`:22`) → Completed | Cancelled | ClosedWithWriteoff | ClosedReturned. `canBeCompleted()` (`:32`) → InTransit | PartiallyReceived. `canBeCancelled()` (`:37`) unchanged. New `canReceive()`, `canBeClosed()` → InTransit | PartiallyReceived. `isCarrying()` → InTransit | PartiallyReceived (the reader scope, §3.5). `label()` extended. `packages/shared/types/generated.d.ts:1240` regenerates.
- `TransferReceiptKind`: `Receipt = 'receipt'`, `Close = 'close'`. `TransferCloseDisposition`: `WriteOff = 'write_off'`, `ReturnToSource = 'return_to_source'`. `TransferReceiptStatus`: `Posted = 'posted'` (single case; a future `Voided` is additive).
- `TransferDiscrepancyReason`: `ShortShipped`, `LostInTransit`, `DamagedInTransit`, `Other`. `movementReason()`: DamagedInTransit → `MovementReason::Damage`, else `MovementReason::WriteOff` (both GL-required `app/Modules/Inventory/Domain/Enums/MovementReason.php:103,105`, family Shrinkage `:80-82`). No new `MovementReason` case.
- `TransferReceiptFailureReason` (typed 422, mirrors `GoodsReceiptFailureReason.php:9-21`): `OverReceipt`, `LotOverReceipt`, `UnknownLot`, `LotRequired`, `LineNotOnTransfer`, `NothingToReceive`, `DiscrepancyReasonRequired`, `IdempotencyKeyReused`, `DispositionRequired`. Each case has `message(): string` — a **static** sentence with no numbers (B1). State errors keep `TransferStateException` → `INVALID_TRANSFER_STATE` (`StockTransferController.php:389-402`).
- `StockMovementReferenceType` gains `StockTransferReceipt = 'stock_transfer_receipt'` (precedent `PosReceiptReturnScrap`, `app/Shared/Domain/Enums/StockMovementReferenceType.php:72`) — on scrap/write-off movements only. `TransferIn`/`TransferOut` movements keep `reference_type = StockTransfer::class, reference_id = transfer id` exactly as `markMovementAsTransfer` writes (`StockTransferService.php:745-752`).

### 3.4 State machine

```
draft ─initiate─▶ in_transit ─receive(partial)─▶ partially_received ─receive(rest)/complete─▶ completed
                     │  │                                 │
                     │  └─receive(all)/complete─▶ completed │
                     │                                     └─close(write_off)─▶ closed_with_writeoff
                     ├─close(write_off)─▶ closed_with_writeoff       └─close(return_to_source)─▶ closed_returned
                     ├─close(return_to_source)─▶ closed_returned
                     └─cancel─▶ cancelled        (partially_received: cancel REFUSED 422 INVALID_TRANSFER_STATE)
draft ─cancel─▶ cancelled
```
Terminal rule kept from `StockTransferService.php:385-394`: the status row is persisted BEFORE `capitalizeTransferCost` so the in-transit re-read in `recordCostAdjustment` does not double count.

### 3.5 In-transit remainder — three readers move from status-based to quantity-based (preserved)

`StockTransferLine::REMAINDER_SQL = '(stock_transfer_lines.quantity - stock_transfer_lines.quantity_received - stock_transfer_lines.quantity_damaged - stock_transfer_lines.quantity_written_off - stock_transfer_lines.quantity_returned)'`; `StockTransfer::scopeCarryingInTransit()` = `whereIn('status', [in_transit, partially_received])`. The status filter stays necessary: a cancelled transfer restocks source (`:452-481`) with counters still 0.
- `LocationStockQueryService.php:139-144,156` and `:223-228,233`: `SUM(quantity)` → `SUM(REMAINDER_SQL)`, status equality → scope. Rounding stays FLOOR (`:160-163,242`).
- `StockMatrixQueryService.php:396-404`: same; its rounding stays HALF_UP (`:382`) — unchanged per reader (citation audit: the two readers differ today and this lane does not harmonize them).
- `WeightedAverageCostService.php:119-125`: `->sum('stock_transfer_lines.quantity')` → `selectRaw('COALESCE(SUM(REMAINDER_SQL),0)')` under the scope; `bcadd` at the working scale (`:127`).
Guard (M7): `TransferInTransitReadersUseRemainderTest` asserts, for exactly the three reader files above, (a) no literal `TransferStatus::InTransit` and (b) `REMAINDER_SQL` present; liveness fixture per convention 08 (`docs/conventions/08-DETECTOR-LIVENESS.md`) — a fixture copy of a reader with the old literal must fail. Lifecycle code in `StockTransferService.php:441` is out of the scan set.

### 3.6 Invariants (each a test in §10; M1 fixed)

I1 Per line and per lot: `received + damaged + written_off + returned ≤ sent` (DB CHECK + service `bccomp` at `QTY_SCALE = 4`, `StockTransferService.php:63` — the constant; the assertion is new code in the receipt service).
I2 Company-owned quantity = on-hand + in-transit (`WeightedAverageCostService::companyOwnedQuantity`, `:100-127`). Initiate: −1 on-hand at source, +1 in-transit → unchanged. Receipt: +1 on-hand at destination, −1 remainder → unchanged. Damage/write-off: TransferIn +1 then scrap −1 at destination, −1 remainder → **−1 exactly once**. Return: +1 on-hand at source, −1 remainder → unchanged. One destructive movement and one GL leg per damaged/written-off unit; never a second aggregate decrement.
I3 Batch-tracked lines: every received/damaged/written-off/returned lot ⊆ that line's shipped allocations; lot sums = line grain; a batch-tracked line without `lots` → `LOT_REQUIRED`.
I4 A receipt is immutable; each transfer line's four counters = Σ of its receipt lines (reconciliation asserts; drift = data bug).
I5 Carrying invariant: a transfer in `in_transit`/`partially_received` has Σ remainder > 0 (`in_transit` ⇔ no receipt row yet); `completed` ⇔ Σ remainder = 0 with `written_off = returned = 0` on every line; `closed_with_writeoff` / `closed_returned` ⇔ Σ remainder = 0 with the matching close counter > 0. `cancelled` is terminal but outside this invariant: its counters stay 0 and the reader scope excludes it.
I6 Idempotency: `(tenant_id, company_id, idempotency_key)` unique on receipts; replay with equal `payload_hash` → the stored receipt (200); unequal hash or a different `transfer_id` → `IDEMPOTENCY_KEY_REUSED` (§5.1).

---

## 4. Event sourcing (B2, owner pattern-detection requirement)

New stored events extend `App\Shared\Domain\Events\DomainEvent` (`app/Shared/Domain/Events/DomainEvent.php:16`, Spatie `ShouldBeStored`; NOT the other base at `app/Shared/Domain/DomainEvent.php`), template `GoodsReceived.php:18-42`. Payloads are scalars, numeric strings (4 dp) and lists of such scalars (Spatie serializes into `stored_events.event_properties jsonb`, `database/migrations/tenant/2025_11_30_102448_create_stored_events_table.php:17`).

### 4.1 Header events (one per posted document)
- `StockTransferReceivedV1` — aggregate uuid = `receiptId`. Fields: `receiptId, transferId, tenantId, companyId, transferNumber, receiptNumber, sequence, sourceLocationId, destinationLocationId, receivedByUserId, isBlind, hasDiscrepancy, previousStatus, newStatus, lineCount, totalReceived, totalDamaged, lineEventIds: list<string>, occurredAt`. Name `inventory.stock_transfer.received.v1`.
- `StockTransferClosedV1` — aggregate uuid = `receiptId` of the close row. Fields: `receiptId, transferId, tenantId, companyId, transferNumber, receiptNumber, sequence, sourceLocationId, destinationLocationId, closedByUserId, disposition, closeReason, closeNote, previousStatus, newStatus, lineCount, totalWrittenOff, totalReturned, freightUncapitalized, lineEventIds, occurredAt`. Name `inventory.stock_transfer.closed.v1`.

### 4.2 Per-line event (companion, owner-accepted) — `StockTransferReceiptLineRecordedV1`
Aggregate uuid = `receiptLineId`. One per receipt line (receipts and closes alike). Fields: `receiptLineId, receiptId, transferId, transferLineId, tenantId, companyId, kind, disposition|null, sequence, sourceLocationId, destinationLocationId, actorUserId, isBlind, productId, variantId|null, requiresBatchTracking, quantitySent, quantityPreviouslyReceived, quantityPreviouslyDamaged, quantityReceived, quantityDamaged, quantityWrittenOff, quantityReturned, quantityRemainingAfter, discrepancyReason|null, discrepancyNote|null, inMovementId|null, scrapMovementId|null, returnMovementId|null, lots: list<{batchAllocationId, batchId, batchNumber, quantityReceived, quantityDamaged, quantityWrittenOff, quantityReturned, inMovementId, scrapMovementId, returnMovementId}>, occurredAt`. Name `inventory.stock_transfer.receipt_line.recorded.v1`.

### 4.3 Replay and atomicity contract
- **Replay**: from the three event classes alone, in `stored_events.id` order, a rebuild reproduces `stock_transfer_receipts`, `…_lines`, `…_line_lots`, the four counters on lines and allocations, `stock_transfers.status/closed_*`, and the movement ids that link to `stock_movements`. Stock levels are rebuilt by the movement stream, as today. `StockMovementRecordedV2` (`StockMovementRecordedV2.php:22-37`, no line/batch/receiver fields) is NOT a replay source for this document and is not cited as one.
- **Atomicity**: Spatie persists a `ShouldBeStored` event synchronously in the dispatching listener (`vendor/spatie/laravel-event-sourcing/src/StoredEvents/EventSubscriber.php:18,36`), so `event()` inside the receipt transaction writes the `stored_events` row in the same transaction: the receipt rows and their three event classes commit or roll back together. Movement events stay `DB::afterCommit` (`app/Modules/Inventory/Domain/Services/StockAdjustmentService.php:187,331`) — they are not part of this document's replay set, which is why the receipt events carry the movement ids themselves.
- Listeners with out-of-DB effects (notifications) run in `DB::afterCommit` (precedent `StockTransferService.php:406,492,608`).
- Compatibility (rule 8): plain `StockTransferInitiated`/`StockTransferCancelled` unchanged; plain `StockTransferCompleted` fires on the `completed` transition whichever path caused it (`StockTransferCompleted.php:9-25`). Nothing renamed.
- Wiring: `EventServiceProvider::$listen` (precedent `app/Providers/EventServiceProvider.php:146-147`): `StockTransferReceivedV1 => [NotifyOnTransferDiscrepancy]`, `StockTransferClosedV1 => [NotifyOnTransferDiscrepancy]`; `StockTransferInitiated => [NotifyDestinationOnTransferInitiated]` via `Event::listen` in `InventoryServiceProvider::boot` (`app/Modules/Inventory/Providers/InventoryServiceProvider.php:92-96` style).
- Replenishment settlement stays on initiate (`app/Modules/Replenishment/Application/Listeners/SettleRequestsOnTransferInitiated.php:22-44`, wired `ReplenishmentServiceProvider.php:19`); no listener on close (OQ-2 owner: leave settled; T-4 notifies `replenishment.process` holders; requester re-raises). `ReopenRequestsOnTransferCancelled` stays bound to cancel (`:20`).

### 4.4 Pattern-detection reference query (owner requirement; read model deferred)
Because `event_class` is indexed and `event_properties` is jsonb (`…create_stored_events_table.php:16-17,20`), the receiver-variance signal needs no new table:
```sql
SELECT event_properties->>'actorUserId' AS user_id,
       COUNT(*) FILTER (WHERE (event_properties->>'quantityDamaged')::numeric > 0
                        OR (event_properties->>'quantityWrittenOff')::numeric > 0) AS discrepant_lines,
       COUNT(*) AS lines, bool_or((event_properties->>'isBlind')::boolean) AS any_blind
FROM stored_events WHERE event_class = 'App\Modules\Inventory\Domain\Events\StockTransferReceiptLineRecordedV1'
  AND created_at >= now() - interval '90 days' GROUP BY 1 ORDER BY discrepant_lines::float / NULLIF(COUNT(*),0) DESC;
```
The per-line event carries `quantitySent` and `quantityRemainingAfter`, so "under-reports that a supervisor later wrote off" and "damage declared abnormally often" are both computable per receiver, per product and per source location. A `T-4`/later lane turns this into a read model + `fraud-alerts` rule; this lane ships the query as a PHPUnit assertion (T16) so the event shape cannot regress.

---

## 5. API contract

Module `Inventory`; the group middleware is `['api','auth:sanctum',SetPermissionsTeam::class,EnforceTokenTenantClaim::class,'module:Inventory']` at `app/Modules/Inventory/Presentation/routes.php:31` (rule 12); the transfer routes sit at `:99-117`. Envelopes as `StockTransferController.php:359-402`: `{error:{code,message,details}}`, 422 typed, 403 `LOCATION_ACCESS_DENIED`, 404 for invisible transfers (`:112-113`).

### 5.0 Blind guarantee and surface closure (B1)

**Guarantee (precise).** With `blind_receiving` on, an actor for whom `canSeeExpected(transfer)` is false (§5.3) cannot obtain, from any authenticated endpoint, the sent/expected/remaining quantity of any line or lot of a transfer that is still receivable (`in_transit`/`partially_received`) — neither directly nor as an aggregate that equals it. **Accepted residuals** (documented, tested as such): R1 probing `OVER_RECEIPT` reveals an upper bound (owner Q2 chose refusal over silent acceptance); R2 after the transfer is terminal, destination stock history shows what landed (a closed write-off's TransferIn equals the remainder) — the supervisor outcome, after the receiver's count is committed, identical to counting where posted corrections are visible; R3 the coarse status `partially_received` (OQ-3 owner ACCEPT).

| # | Surface reachable by a receiver | Today | Rev-2 rule |
|---|---|---|---|
| 1 | `GET /stock-transfers/{id}/receiver-view` | new | Always `TransferReceiverPayloadBuilder` (§5.3) |
| 2 | `GET /stock-transfers` list, `GET /{id}` show | full formatter emits line + allocation quantities (`StockTransferController.php:311-335`) | Builder chosen per transfer by `canSeeExpected` (§5.3); no other formatter survives (`formatTransfer` deleted) |
| 3 | `POST /{id}/receive` 201 | new | `receipt` = `StockTransferReceiptData` (§5.3): echoes only what the actor submitted; `transfer` via the gated builder |
| 4 | `POST /{id}/complete` 200 | full formatter (`:233-235`) | Same gated builder as show; T9 covers it. (A blind actor may still call `complete` — the response then carries no quantities.) |
| 5 | `POST /{id}/close` 201 | new | `receipt` with close quantities only when `canSeeExpected`; otherwise the receipt header + line ids (a closer without reconcile is a custom-role case) |
| 6 | Typed 422s | messages pass through verbatim (`:394`) | `TransferReceiptFailureReason::message()` static strings; details for a blind actor = `{transfer_line_id, batch_id}` only; T9 scans keys AND string values for any digit sequence equal to a sent/remaining quantity |
| 7 | `GET /inventory/stock-matrix?include=incoming` (`can:inventory.view`, `routes.php:70-72`; `StockMatrixController.php:38`; `StockMatrixQueryService.php:390-408`) | exact transfer incoming per product/location | `StockMatrixQueryService::incoming` takes `bool $withTransferIncoming`; controller passes `ExpectedQuantityVisibility::canSeeIncomingAggregates(user, company)` (§5.3); when false the transfer sum is skipped and `cells[].incoming` carries PO incoming only, with `incoming_transfer_masked: true` on the response meta |
| 8 | `GET /pos/products/{product}/stock-distribution` (`app/Modules/POS/routes.php:148`; gate `pos.view_cross_location_stock`, `StockDistributionController.php:37`; emits `incoming_transfer` `:91,95`) | exact | `LocationStockQueryService::forProduct(..., withTransferIncoming)`: when false `incomingTransfer` is `null` in the DTO and the key is **omitted** from the JSON |
| 9 | `GET /pos/stock-levels` device feed (`routes.php:142`; gate `pos.operate_terminal`, `PosStockLevelController.php:58`; emits `incoming_transfer` `:104-109`) | exact | Same flag on `LocationStockQueryService::incoming` (`:217-245`); `incoming[].incoming_transfer` omitted when masked; the POS device renders "incoming" from `incoming_po` alone |
| 10 | `GET /stock-movements` (`can:inventory.view`, `routes.php:78-80`; payload `StockMovementController.php:208-228`) | location-scoped by `LocationScopeResolver` (`:65,80`; `app/Modules/Company/Services/LocationScopeResolver.php:31-66`) | Unchanged: a restricted destination user never sees source `TransferOut` rows; destination `TransferIn` rows exist only after that user's own receipt (R2 covers the close) |
| 11 | `GET /entry-exit-notes` (`can:inventory.view`, `routes.php:82-84`) | company-wide, NO location scope (`EntryExitNoteController.php:26-72` filters only by optional `location_id`; movement rows expose `quantity` `:247-250`) | Fix in this lane: `sm.location_id` constrained to `LocationScopeResolver::resolve($user, $requested)` exactly as stock movements — an authz gap independent of blind mode |
| 12 | `GET /{id}/reconciliation` | new | `can:inventory.transfers.reconcile` AND `canSeeTransfer` (404 otherwise, `StockTransferController.php:347-357` shape) AND `canAccessLocation(destination) || canAccessLocation(source)` |
| 13 | Notifications (§7) | new | Counts and status only; no quantities in `data` |
| 14 | Generated TS types | `apps/web/src/features/stock-transfers/types/index.ts:1-12` hand-written | `TransferReceiverViewData` has no expected-quantity members; `StockTransferReceiptData` defined below |
| 15 | Mobile (`erp-mobile`, sibling repo) | PO supervisor shapes expose `quantity_ordered/remaining` (`erp-mobile/src/features/receiving/types.ts:60-65,104-109`) | §9: receiver shapes without those members; T-3b masks PO |

### 5.1 `POST /stock-transfers/{id}/receive` — `can:inventory.transfers.complete` + `canAccessLocation(destination)` (`:221`)

Request (`ReceiveStockTransferRequest`; precision rule 19: `numeric` + `regex:/^\d+(\.\d{1,4})?$/` — the backend accepts JSON numbers and numeric strings alike (`docs/architecture/precision-contract.md` §Ingress); the web and mobile clients send strings; m1):
```json
{ "idempotency_key": "uuid (required)", "notes": "string|null",
  "lines": [ { "transfer_line_id": "uuid", "quantity_received": "11.0000", "quantity_damaged": "1.0000",
               "discrepancy_reason": "damaged_in_transit", "discrepancy_note": "box crushed",
               "lots": [ { "batch_id": 812, "quantity_received": "11.0000", "quantity_damaged": "1.0000" } ] } ] }
```
Rules:
1. **Idempotency first (B3).** `DB::transaction(attempts: 3)` → `lockTransfer` (`StockTransferService.php:734-742`) → lookup `stock_transfer_receipts` by `(tenant_id, company_id, idempotency_key)`: found with same `transfer_id` and equal `payload_hash` → return the stored receipt, HTTP 200, `meta.replayed = true`, regardless of the transfer's current state (so a replay after the terminal transition succeeds); found with a different `transfer_id` or hash → `IDEMPOTENCY_KEY_REUSED`. Only then `canReceive()` else `TransferStateException`.
2. **Key race across transfers.** Two requests with the same key on different transfers pass their lookups and collide on the unique key: the service catches `UniqueConstraintViolationException` outside the transaction and re-reads the committed winner by key (precedent `StockTransferService.php:170-190`), applying rule 1's discriminator to it.
3. **Canonical payload hash.** `ReceiptPayloadCanonicalizer`: validated array → drop `idempotency_key`; `notes`/`discrepancy_note` absent ≡ null; quantities normalized to 4-dp strings via `bcadd($q, '0', 4)`; lines sorted by `transfer_line_id`, lots by `batch_id`; recursive `ksort`; `json_encode(JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)` (precedent `app/Modules/Fiscal/Application/Services/TerminalRegistrySnapshotService.php:540-558`); sha256. `1`, `1.0`, `1.0000` hash equal; a reordered body hashes equal.
4. Locks: after the header lock, all line product advisory locks in one sorted call (`:335-339`), then per line: line ∈ transfer else `LINE_NOT_ON_TRANSFER`; `received + damaged ≤ remainder` else `OVER_RECEIPT`; lot rules (I3) else `UNKNOWN_LOT` / `LOT_OVER_RECEIPT` / `LOT_REQUIRED`; Σ(received + damaged) > 0 else `NOTHING_TO_RECEIVE`.
5. **Remainder vs discrepancy (M2).** Lines omitted from the body = nothing received on them (open remainder). `discrepancy_reason` required iff `quantity_damaged > 0` (`DISCREPANCY_REASON_REQUIRED`); `has_discrepancy = Σ damaged > 0`. Shortness is never computed as a discrepancy at receipt.
6. Writes: movements (§6) → counters on line and allocations → receipt rows (`sequence` = count + 1) → status (`completed` if Σ remainder = 0 else `partially_received`; on `completed`: `completed_by/at`, then `capitalizeTransferCost`, `:631`) → stored events (§4) inside the transaction → GL buffer flush inside the transaction (§6.2) → `afterCommit` plain `StockTransferCompleted` when terminal.
7. Response 201 `{ data: { receipt: StockTransferReceiptData, transfer: <gated builder output> }, meta: { replayed: false } }`.

### 5.2 `POST /stock-transfers/{id}/close` — `can:inventory.transfers.close` + `canAccessLocation(destination)`

```json
{ "idempotency_key": "uuid", "disposition": "write_off | return_to_source", "reason": "lost_in_transit", "note": "string|null" }
```
Allowed from `in_transit` or `partially_received`; rules 1–3 of §5.1 apply verbatim (lookup first, race catch, canonical hash). `disposition` required (`DISPOSITION_REQUIRED`); `reason` required for both dispositions (a confirmed short always has a reason — M2). One disposition applies to the whole remainder (mixed dispositions = two transfers' worth of paperwork; refused by design, note it in the dialog). Zero remainder → `NOTHING_TO_RECEIVE`. Writes one `kind = close` receipt whose lines carry `quantity_written_off = remainder` (write-off) or `quantity_returned = remainder` (return) per line/lot, the movements of §6.3, status `closed_with_writeoff` / `closed_returned`, `closed_by/at/close_disposition/close_reason/close_note`, `capitalizeTransferCost` (§6.4), `StockTransferClosedV1` + line events, alert (§7). `return_to_source` additionally requires `canAccessLocation(source)` (stock is being put back there). Not a mobile action in M-1.

### 5.3 Visibility rule, builders, receipt DTO

`ExpectedQuantityVisibility` (Inventory application service, constructor-injected — rule 13):
- `canSeeExpected(user, transfer, settings)` = `blind_receiving` off OR user holds `inventory.transfers.reconcile` OR `canAccessLocation(source)` (the sender knows what they shipped; unrestricted memberships return true at `LocationContext.php:233-234`, so blind mode targets restricted destination staff — as counting, where admins see theoretical quantities).
- `canSeeIncomingAggregates(user, company)` = `blind_receiving` off OR reconcile permission OR unrestricted membership (`getAllowedLocationIds` null, `LocationContext.php:194-206`). Used by surfaces 7–9.
Builders (convention 11 — one class per concept, separate array literals):
- `TransferPayloadBuilder` — today's shape (`StockTransferController.php:282-341`) plus `quantity_received/damaged/written_off/returned/remaining` per line and allocation, `receipts[]`, `close_*`, `blind: false`.
- `TransferReceiverPayloadBuilder` — line literal with no expected-quantity keys, guard comment as `InventoryCountingController.php:376`: `// NEVER INCLUDE: quantity, quantity_remaining, quantity_sent, unit_cost_snapshot, allocated_transfer_cost, transfer_cost, batch_allocations[].quantity`. Lines: `id, product{id,name,sku,barcode}, variant{id,sku,name_suffix}|null, unit{decimal_places}, requires_batch_tracking, lots[]{batch_id,batch_number,expiry_date}` (identity only), `my_receipts[]` (the actor's own posted lines: received/damaged/reason), `receiving_open: bool`. Header: `id, transfer_number, source_location{id,name}, destination_location{id,name}, status, initiated_at, notes, blind: true`.
- `StockTransferReceiptData` (B1 surface 3): `id, receipt_number, kind, disposition|null, sequence, status, is_blind, has_discrepancy, received_by{id,name}, received_at, notes, lines[]{id, transfer_line_id, product_id, variant_id, quantity_received, quantity_damaged, discrepancy_reason, discrepancy_note, lots[]{batch_id, batch_number, quantity_received, quantity_damaged}}`. It nests **no** transfer line or allocation DTO; for `kind = close` the `quantity_written_off/returned` members are present only when `canSeeExpected` (the builder takes the visibility result, never a setting read).
- `GET /stock-transfers/{id}` and the list choose the builder per transfer by `canSeeExpected`; `/receiver-view` ALWAYS uses the receiver builder; `complete` and `receive` echo through the same choice.
- Web and mobile never read `/fraud-settings` to decide the mode (`can:fraud-settings.view`, `app/Modules/Compliance/Presentation/routes.php:23-25`); they read `blind` from the payload.

### 5.4 `GET /stock-transfers/{id}/reconciliation` — `can:inventory.transfers.reconcile` + `canSeeTransfer` + endpoint access (M5)

Not `inventory.view` (granted to cashier/viewer/technician/operator: `database/seeders/RolesAndPermissionsSeeder.php:702,741,771,806`); counting's reconciliation is on `inventory.view` (`routes.php:312-314`) precisely because counting has no company-level blind setting — the transfer twin needs its own permission (D1 accepted). Shape (vocabulary of `CountingDiscrepancyReportService.php:44-56`): per line and lot `sent, received, damaged, written_off, returned, remaining, variance = sent − received`, `discrepancy_reasons[]`, `receipts[]{receipt_number, kind, disposition, sequence, received_by, received_at, is_blind, has_discrepancy}`, `summary{lines, lines_with_discrepancy, total_sent, total_received, total_damaged, total_written_off, total_returned, total_remaining, freight_uncapitalized}`; quantities at the unit's decimals via `QuantityScale::formatForUnit` (`app/Shared/Domain/QuantityScale.php:72`).

### 5.5 Company setting `blind_receiving`

`FraudSettingsController::update` validation (`FraudSettingsController.php:108-120`) gains `'blind_receiving' => 'sometimes|boolean'` under `can:fraud-settings.update` (`Compliance/Presentation/routes.php:27-29`); NOT in `CASH_CONTROL_KEYS` (`:30-35`), so it does not require `pos.configure_cash_count`. All model/DTO/reset surfaces of §3.1 change in the same commit; `show` DTO regenerates to TS. Existing tenants: column default `false`; no data migration (contrast `2026_08_12_100000_enable_blind_cash_count_for_existing_settings.php`, cash flipped to true by owner ruling; receiving stays opt-in). Saving the setting invalidates the web transfer queries (§8, m2).

### 5.6 `POST /stock-transfers/{id}/complete` — kept, `can:inventory.transfers.complete`

Becomes `StockTransferReceiptService::receiveAllRemaining(transfer, user, idempotencyKey: "complete:{transferId}")`: same writer, one `kind = receipt` row with every remaining line/lot, zero discrepancy; `completeLocked` (`:346-383`) deleted (one writer, convention 11 rule 2). Replay: rule 1 of §5.1 returns the stored receipt after the terminal transition (B3), so `complete` twice → 200 replayed. Response: `{ data: <gated builder output> }` — same keys as today for a non-blind actor, receiver shape for a blind one (B1 surface 4).

### 5.7 Incoming aggregates (surfaces 7–9)

`LocationStockQueryService` (`:81,137-164,217-245`) and `StockMatrixQueryService::incoming` (`:390-408`) gain `bool $withTransferIncoming = true`; controllers pass `canSeeIncomingAggregates`. DTO `incomingTransfer` becomes `?string`; JSON omits the key when null (never `"0.0000"`, which would be a false statement). The remainder conversion of §3.5 is independent of this flag.

### 5.8 Permissions (M5, D1)

New `inventory.transfers.close`, `inventory.transfers.reconcile` defined next to `RolesAndPermissionsSeeder.php:197-200`; both granted to `manager` (`:585`, transfer grants at `:602`) and to `admin` via `permissionNames()` (`:582`); grantable per role/user in the existing role surface (owner D1: managers are supervisors; blind mode targets custom receiver roles and restricted memberships). Deploy: `tenants:seed RolesAndPermissionsSeeder` + `permission:cache-reset`; regenerate the frontend permission map with `php artisan permissions:export-frontend-map` (`app/Console/Commands/ExportFrontendPermissionsMap.php:14`; preflight drift guard `scripts/preflight.sh:136-144`).

---

## 6. Discrepancy → movements + GL

### 6.1 Movements (all via `StockAdjustmentService`: `receive` `:115`, `issue` `:253`, both take `batchId`, `reason`, `unitCost`, `referenceType/referenceId`; inside the receipt transaction, under the up-front product locks)

| Quantity | Movement(s) | Reason / GL |
|---|---|---|
| received (good) | destination `receive(qty, reference = transfer_number, batchId)` + `markMovementAsTransfer(TransferIn, transferId)` — byte-for-byte `:358-383` | `TransferIn`: no GL (`requiresGLEntry()` default false `MovementReason.php:111`; family Neither `:86-92`); WAC untouched |
| damaged (receipt) | same receive + mark, then destination `issue(qty, reference = receipt_number, batchId, reason: Damage, unitCost: Product::resolveMovementUnitCost() (app/Modules/Product/Domain/Product.php:322), referenceType: StockTransferReceipt, referenceId: receipt id)` | `Damage`: GL true (`:103`), Shrinkage (`:80-82`) → Dr Shrinkage / Cr Inventory |
| written off (close, `write_off`) | receive + mark, then `issue(... reason: WriteOff ...)` | `WriteOff`: GL true (`:105`), Shrinkage |
| returned (close, `return_to_source`) | **source** `receive(qty, reference = transfer_number.'-RETURN', batchId)` + `markMovementAsTransfer(TransferIn, transferId)` — the cancel restock loop `:452-481` extracted into `restockAtSource(line, qty, lots)` and shared by cancel and return | `TransferIn`: no GL (benchmark note: Odoo/ERPNext/Dolibarr post nothing on a transit → source move). Unit cost = `unit_cost_snapshot` as cancel does today |

Land-then-scrap rationale: the remainder is derived, not a `stock_levels` row, so nothing can be issued from "transit"; the TransferIn gives the destination row the units the scrap removes (net on-hand 0, one destructive movement, one GL leg — I2). Shrinkage lands on the location that observed it (Odoo "receive then scrap"); source-side blame is a reporting question (reconciliation shows the reason).

### 6.2 GL bridge — kinds per line type (M3)

The receipt service is a composite root of the D-28 buffer: `InventoryGlPostingBuffer::mark()` at entry, `enqueue(MovementGlContext)` per destructive movement, `flushIfOutermost()` **inside the root transaction after the body** — the buffer requires `DB::transactionLevel() === 1` and throws otherwise (`app/Modules/Inventory/Application/Services/InventoryGlPostingBuffer.php:56-68`; precedent `app/Modules/Document/Domain/Services/DeliveryNoteService.php:95,208`), so a posting failure rolls back movements, receipt and events together; `rollbackTo(marker)` on any earlier failure (`:39`). Direct `postFor*` calls fail PHPStan (`app/PHPStan/Rules/InventoryGlPostingViaBufferOnly.php:17-42`). Callers must not nest the receipt in their own transaction (level > 1 = leak alarm, no posting).
- **Lot-less line**: `kind: MovementGlKind::Exit` → `postForExit` → `postMovement` (`InventoryGlPostingService.php:26,130-200`): `requirePerpetual` (`:158`), `hasInventoryMovementAccounts(company, reason)` (`:161`), counter purpose `InventoryShrinkageExpense` for the Shrinkage family (`:187`). Context fields as `ReturnScrapWriteOffService.php:169-189` minus `batchNumber/productId`.
- **Lot line**: `kind: MovementGlKind::BatchWriteOff` with `batchNumber = Batch.batch_number` of the lot and `productId` — both required or `postForBatchWriteOff` throws (`:95-96`); `hasInventoryWriteOffAccounts` (`:99`). The POS precedent's synthetic `"POS-SCRAP …"` label (`ReturnScrapWriteOffService.php:187`) is not reused: transfer scrap always names the real lot (I3).
- Non-positive resolved unit cost → warn and post nothing (`ReturnScrapWriteOffService.php:135-141`). Periodic-valuation companies: `requirePerpetual` throws `UnsupportedValuationModeException` (`app/Modules/Inventory/Application/Services/InventoryValuationModeResolver.php:79-88`) → the receipt with damage is refused as a whole; the receipt controller catches it and answers a typed 422 `VALUATION_MODE_UNSUPPORTED` (new mapping in this lane — the exception is a plain `RuntimeException` with no HTTP mapping today, `app/Modules/Inventory/Domain/Exceptions/UnsupportedValuationModeException.php:22`).
- `BatchWriteOffService::writeOff` (`app/Modules/BatchExpiry/Domain/Services/BatchWriteOffService.php:44-57`, string reason + direct `GeneralLedgerService`) is NOT used; D7a's refusal (`StockAdjustmentDocumentService.php:522-531`) applies to the manual document, not to this lot-naming path.

### 6.3 Return-to-source — no GL (OQ-1, benchmark note)
Stock movement pair only: the remainder never existed at destination, so no TransferIn/scrap there; one `TransferIn` at source per line/lot (§6.1). Company-owned quantity unchanged (I2). `capitalizeTransferCost` runs on the terminal transition with landed weights (§6.4).

### 6.4 Freight — default pending the single open owner question (M4, §12)
Today `capitalizeTransferCost` (`StockTransferService.php:631-712`) weights on shipped `quantity` (`computeAllocationWeights`, `:714`) and capitalizes each share into WAC via `recordCostAdjustment` (`:696`), which writes a quantity-0 `Adjustment` movement and **no journal** (`WeightedAverageCostService.php:745-830`, `StockMovement::create` at `:812`) and no-ops when company-owned quantity is 0 (`:771-775`). **Default in this spec**: allocation weights = **landed** quantity (`received + damaged`) instead of shipped; the share attributable to written-off or returned units is never capitalized (it never enters inventory value), is recorded as `stock_transfer_receipts.freight_uncapitalized` on the close row and surfaced in reconciliation; no journal is posted for it because transfer freight has no GL leg in this module — the carrier's cost sits where its invoice was booked (Expenses/Purchasing), which is the "expensed, never capitalized" outcome the brief recommends without a second P&L hit. A returned lot's share is likewise uncapitalized (the units did not land). Owner alternatives in §12.

Precision (rule 19): quantities numeric strings; `bccomp/bcadd/bcsub` at `QTY_SCALE = 4`; reader rounding unchanged per reader (§3.5); display via `formatForUnit`. No float; `ForbidFloatCastOnDecimalProperty` guards models.

---

## 7. Notifications (T-4 minimum; M9)

Both extend `Illuminate\Notifications\Notification implements ShouldQueue` (precedent `app/Modules/Identity/Application/Notifications/UserInvitation.php:14`; tenancy on the worker via `QueueTenancyBootstrapper`, `config/tenancy.php:42`), `via = ['database']`, `databaseType()` + `toDatabase()` as `app/Modules/Treasury/Application/Notifications/TreasuryAlertNotification.php:22-35`, `public bool $afterCommit = true`. Default queue (listed in `config/horizon.php:209`; `tests/Unit/Config/HorizonQueueCoverageTest.php` guards). Recipients resolved in the listener, in the request, inside `DB::afterCommit` — never in the worker (rule 20).

- **Identity (M9).** Each notification sets `$this->id = Uuid::uuid5(NS_TRANSFER_NOTIFICATIONS, "{type}:{receipt_or_transfer_id}:{user_id}")` in its constructor. Laravel keeps a preset id (`vendor/laravel/framework/src/Illuminate/Notifications/NotificationSender.php:152-153,226-228`) and the database channel inserts it as the row id (`Channels/DatabaseChannel.php:34`); `notifications.id` is the uuid primary key (`database/migrations/tenant/2026_07_12_110000_create_notifications_table.php:14`). A replayed or concurrent listener therefore cannot create a second row: the listener checks the deterministic id before queueing and the PK refuses the loser of any race (the failed job is logged, not retried).
- `TransferInitiatedNotification` — type `inventory.transfer.initiated`; data `{transfer_id, transfer_number, source_location_name, destination_location_id, destination_location_name, line_count, initiated_by_name, blind, deep_link: "/inventory/stock-transfers/{id}"}` (no quantities). Recipients: memberships whose `allowed_location_ids` is null or contains the destination (`LocationContext.php:194-206` semantics on `user_company_memberships`) AND `inventory.transfers.complete`, minus the initiator. Listener on the plain `StockTransferInitiated` (`afterCommit` at `:608`).
- `TransferDiscrepancyNotification` — types `inventory.transfer.received_with_discrepancy` (on `StockTransferReceivedV1` when `hasDiscrepancy`, i.e. damage — M2) and `inventory.transfer.closed` (on `StockTransferClosedV1`, both dispositions); data `{transfer_id, transfer_number, receipt_id, receipt_number, kind, disposition, destination_location_name, lines_with_discrepancy, received_by_name, deep_link}` — counts only. Recipients: `inventory.adjust` holders with destination access. A plain partial receipt (open remainder) notifies nobody.
- Web: `NotificationPanel.tsx` switch (`:45-71`) gains the three types with `t()` labels; `KNOWN_TYPES` (`:20-26`) gains them (else the raw type renders, `:165-169`); navigation uses `deep_link` (`:94-98`); i18n `notifications` namespace in en/fr/ar (`apps/web/src/features/notifications/notificationsI18n.test.ts` parity guard). Query keys stay `tenantScopedKey` (`useNotifications.ts:23,34`).

---

## 8. Web (`apps/web/src/features/stock-transfers/`)

- Types: `types/index.ts:1-12` already says the hand-written interfaces are interim. This lane adds PHP DTOs `StockTransferData`, `StockTransferLineData`, `StockTransferLineBatchAllocationData`, `StockTransferReceiptData`, `StockTransferReceiptLineData`, `TransferReceiverViewData`, `TransferReconciliationData`, `CompanyFraudSettingsData.blind_receiving`; runs `php artisan typescript:transform`; deletes the local `StockTransfer*` interfaces (`:12-46`) for `@autoerp/shared/types/generated` (convention 11 rule 4). The receiver-view TS type carries the NEVER-INCLUDE comment (from the backend builder, §5.3 — there is no web precedent: `apps/web/src/features/inventory-counting/api/countingApi.ts` is 150 lines with no such contract).
- Module gating (M5): the three transfer routes (`apps/web/src/routes/index.tsx:1403,1413,1423`) are permission-only today; add `moduleKey="inventory"` exactly as the inventory hub routes (`:1086,1096`) to match the backend `module:Inventory` (`routes.php:31`; `docs/architecture/vertical-module-gating.md`).
- API (`api/stockTransferApi.ts:46,50` pattern, `apiPost` unwraps once — rule 14): `receive`, `close`, `receiverView`, `reconciliation`; hooks `useReceiveStockTransfer`, `useCloseStockTransfer`, `useTransferReceiverView`, `useTransferReconciliation` with `tenantScopedKey` keys (`api/queries.ts:11-20`; audited by `apps/web/tools/audit-tanstack-keys.mjs`) and invalidation of transfer, list, `stock-levels`, `stock-movements` (as `queries.ts:48-51`) and notifications keys.
- Cache identity (m2): transfer detail/list queries use `staleTime: 0` and refetch on focus; `updateFraudSettings`/`resetFraudSettings` (`features/compliance/api/fraudApi.ts:28-34`) invalidate `['stock-transfers']`; the lines table derives its columns from the payload discriminator (`blind` + presence of `quantity`), so a stale full payload can only outlive one refetch. Role/membership changes take effect on the next fetch — accepted (the server is the gate; the client never widens).
- Detail page (`pages/StockTransferDetailPage.tsx`): `canComplete` (`:38`) becomes `canReceive = status === 'in_transit' || status === 'partially_received'` via an exhaustive `Record<TransferStatus, …>` (M8); the Complete button (`:98-107`) stays as "Receive all"; new **Receive** button → `ReceiveTransferDialog` (`Dialog`, `DataTable`, `QuantityInput` for received/damaged — strings, unit decimals from `unit.decimal_places`; ESLint `no-parsefloat-on-money`/`no-raw-quantity-input`), `Select` for `discrepancy_reason` enabled when damaged > 0, lot rows under batch-tracked lines, one `crypto.randomUUID()` idempotency key per dialog open, replay 200 treated as success; **Close** `ConfirmDialog` with disposition radio (`write_off` / `return_to_source`), reason `Select`, note `Textarea` (`inventory.transfers.close`); when the payload is the receiver shape the table has no expected/remaining columns because the keys do not exist. **Reconciliation** tab (`RequirePermission permission="inventory.transfers.reconcile"`) renders §5.4 with variance cells in design tokens (rule 18).
- List page: `STATUS_OPTIONS` (`pages/StockTransferListPage.tsx:15-21`) becomes a `satisfies readonly TransferStatus[]` list of every generated case (M8); `StockTransferStatusBadge.tsx:5-10` `Record` forces the two new variants.
- i18n: namespace `stock-transfers` (`lib/i18n.ts:52,108,218,275`) keys `receive.*`, `close.*` (incl. `close.disposition.{write_off,return_to_source}`), `reconciliation.*`, `status.{partially_received,closed_with_writeoff,closed_returned}`, `discrepancyReason.*`, `errors.{OVER_RECEIPT,…}` in **en/fr**; Arabic keeps the existing English fallback for this namespace (`lib/i18n.ts:474-477`) — stated, not accidental (m3). `notifications` and `compliance` namespaces get ar keys (they have ar bundles).
- Settings: `CashDrawerControlsSection.tsx:13,52` pattern — a `Checkbox` "Blind receiving" in a new `ReceivingControlsSection` on `FraudSettingsPage.tsx` (`:397-403` wiring), through `fraudApi.ts`.
- Tests: Vitest for the receive dialog (strings, submit disabled at Σ = 0, reason required when damaged > 0, replay success), the close dialog (disposition required), blind rendering (no expected column when `blind: true`), reconciliation tab, status option exhaustiveness.

---

## 9. Mobile (M-1 later) — contract commitments only (sibling repo `erp-mobile` @ `51e3445`)

- The mobile receive screen uses ONLY `GET /stock-transfers/{id}/receiver-view` and `POST /stock-transfers/{id}/receive` with a client-generated `idempotency_key` per submission; offline replay resends the same key (counting precedent `erp-mobile/src/features/counting/services/pendingCountSyncService.ts:58-66`) and treats 200 + `meta.replayed` as success.
- `erp-mobile/src/features/receiving/types.ts`: `ReceiptStatusLine` (`:60-65`) and `MergedReceiptLine` (`:104-109`) get a SUPERVISOR-SHAPE comment; a new `TransferReceiverLine` / `PurchaseOrderReceiverLine` pair carries `// NEVER INCLUDE: quantity, quantity_ordered, quantity_remaining, quantity_sent`. T-3b adds `GET /purchase-orders/{id}/receiver-view` (`PurchaseOrderReceiverPayloadBuilder`) under the same setting and rule, with its own permission name, and masks PO incoming in surfaces 7–9.
- Damaged reason required only with damaged > 0; `close` is not a mobile action.

---

## 10. Test matrix (PHPUnit `tests/Feature/Inventory/`, lane `feature-lane-inventory/Inventory`, `tests/feature-lane-manifest.json:161-168`; PG-only cases mark-skip on SQLite; existing `InventoryTransferServiceTest.php`, `StockTransferIdempotencyCollisionPostgresTest.php`, `StockTransferLocationScopeTest.php`, `StockTransferShowBatchAllocationsTest.php` keep passing)

| # | Test | Asserts (data meaning) |
|---|---|---|
| S1 | second company | Company B (real `POST /api/v1/companies`) initiates, receives, closes (write-off) and closes (return) its own transfers; `TRR-…-0001` in both companies; A's receipts/reconciliation 404 in B; `blind_receiving` on in A leaves B's payloads full; B's fraud-settings `reset` leaves `blind_receiving` false and A's unchanged |
| S2 | second location | Destination = second `pos_enabled` location: `stock_levels` row appears there only; matrix/POS incoming drops there only; return-to-source restocks the *source* of that transfer, not the default location; alert recipients are that location's members |
| S3 | re-run / idempotency | receive: same body twice → one receipt, one movement set, 200 `meta.replayed`; same key different body → `IDEMPOTENCY_KEY_REUSED`, no rows; same key on another transfer → `IDEMPOTENCY_KEY_REUSED`; `1`/`1.0`/`1.0000` and reordered lines hash equal; close (each disposition) twice → replay 200 after terminal; `complete` twice → replay; `PATCH /fraud-settings {blind_receiving:true}` twice → one row, true; migration rerun on a half-migrated schema → no error, same schema |
| T1 | partial then complete | 12 sent; receive 5 → `partially_received`, remainder 7 in all three readers, company-owned unchanged (I2); receive 7 → `completed`, `StockTransferCompleted` once, freight capitalized once |
| T2 | over-receipt | 13 of 12 → `OVER_RECEIPT`, zero movements; after 5, receive 8 → `OVER_RECEIPT` |
| T3 | damaged | 11 + 1 damaged → on-hand +11; TransferIn 12, Damage 1 (`reference_type = stock_transfer_receipt`); one journal Dr Shrinkage / Cr Inventory at WAC; lot-less line posts via `Exit`, lot line via `BatchWriteOff` with the real batch number; `has_discrepancy` true; alert to `inventory.adjust` at destination only; periodic-valuation company → 422, no rows |
| T4 | close write-off | 5 of 12 received, close `write_off/lost_in_transit` → `closed_with_writeoff`, `quantity_written_off = 7`, on-hand still 5, one WriteOff movement + one GL leg, company-owned −7 once, remainder 0 everywhere, `freight_uncapitalized` = 7/12 of `transfer_cost` under landed weights |
| T4b | close return | 5 of 12 received, close `return_to_source/short_shipped` → `closed_returned`, `quantity_returned = 7`, source on-hand +7 (lot-exact), destination 5, **no journal entry**, company-owned unchanged, remainder 0; from `in_transit` (nothing received) → source back to its pre-transfer quantity |
| T5 | state rules | close then receive → `INVALID_TRANSFER_STATE`; cancel on `partially_received` → 422; close on `completed` → 422; receive on `draft`/`cancelled` → 422; close without `disposition` → `DISPOSITION_REQUIRED`; close without reason → 422 |
| T6 | lots | unknown lot → `UNKNOWN_LOT`; lot over allocation → `LOT_OVER_RECEIPT`; batch-tracked line without `lots` → `LOT_REQUIRED`; per-lot remainder = allocation − received − damaged |
| T7 | concurrency (PG) | 8 + 8 of 12 in parallel → one 201, one `OVER_RECEIPT` (`lockForUpdate` `:734-742`) |
| T7b | receive vs close (PG) | parallel receive 5 and close write-off → exactly one wins; loser 422; counters consistent (I4, I5) |
| T7c | receive vs complete (PG) | parallel receive 5 and `complete` → one terminal transition, one `StockTransferCompleted`, no double TransferIn |
| T8 | precision | `0.0003` sent; receive `0.0001` ×3 → completed; readers `bcadd` at 4 dp; all payload quantities strings |
| T9 | blind leakage (recursive scan of keys AND string values) | `blind_receiving` on, restricted destination user without reconcile: `/receiver-view`, show, list, receive 201, **complete 200**, close 201, every 422 envelope (incl. `message`), stock-matrix `include=incoming`, POS stock-distribution, POS stock-levels feed, entry/exit notes, stock-movements — none contains a key in `{quantity, quantity_remaining, quantity_sent, remaining, ordered, already_received, unit_cost_snapshot, allocated_transfer_cost, transfer_cost, incoming_transfer}` at any depth nor a numeric token equal to the sent or remaining quantity; the same user with reconcile, an unrestricted user, and everyone with the setting off see them; reconciliation 404 for a user without endpoint access |
| T10 | notifications | initiator excluded; recipients = destination-accessible + `inventory.transfers.complete`; no alert on a plain partial receipt; alert on damage and on both close dispositions; rolled-back receipt sends nothing; replayed listener creates no second row (deterministic id); **worker test**: `app(CompanyContext::class)->clear()` then process the queued job → row written with correct tenant/company (rule 20) |
| T11 | backfill | fixture with completed / in_transit / cancelled transfers → completed lines and allocations get `quantity_received = quantity`; others 0; readers' totals identical before/after |
| T12 | readers ratchet | `TransferInTransitReadersUseRemainderTest` fails on the liveness fixture; passes on the three readers |
| T13 | GL boundary | `InventoryGlPostingBoundaryGuard::assertEmpty` (`app/Modules/Inventory/Application/Services/InventoryGlPostingBoundaryGuard.php:20`; wired `InventoryServiceProvider.php:125-133`) clean after receive/close; no direct `postFor*` (PHPStan) |
| T14 | replenishment | request settled on initiate stays `fulfilled` after both close dispositions (OQ-2) |
| T15 | events | receipt + close each persist header + N line events in the same transaction (a forced failure after `event()` leaves no `stored_events` row); a replay from the three event classes rebuilds receipts, counters and statuses of a fixture with two partial receipts and a close, byte-equal |
| T16 | pattern query | the §4.4 SQL run against a fixture (receiver A damages 3 of 4 lines, receiver B 0 of 4) ranks A first with `discrepant_lines = 3`, `any_blind = true` |
| T17 | entry/exit scope | restricted user sees only allowed-location groups in `/entry-exit-notes`; requesting another location → 403 |

Reviewers: `inventory-costing-reviewer` + `stock-gl-interaction-reviewer` (GL legs, no-GL return) + `tenancy-authz-reviewer` (visibility rule, permissions, entry/exit scope, module gating) + `frontend-conventions-reviewer` (web).

---

## 11. Migration and rollout

1. Migrations (tenant path; `tenants:migrate` runs on every push to `origin/dev`): (a) four counters + CHECK on lines and allocations; (b) three receipt tables + partial uniques; (c) `stock_transfers.closed_*`; (d) `company_fraud_settings.blind_receiving`; (e) backfill `UPDATE stock_transfer_lines SET quantity_received = quantity WHERE transfer_id IN (SELECT id FROM stock_transfers WHERE status = 'completed') AND quantity_received = 0`, same for allocations — idempotent by predicate; cancelled/in_transit untouched. Every DDL statement guarded (§3.1) so a rerun after a partial staging run is a no-op (M6, B10).
2. Readers switch to `REMAINDER_SQL` in the SAME merge as the backfill.
3. No feature flag: endpoints are new; `complete` keeps its contract; `blind_receiving` defaults off.
4. Permissions: seeder rows, `tenants:seed RolesAndPermissionsSeeder`, `permission:cache-reset`, `permissions:export-frontend-map`; `docs/handoff/PROMOTION-CHECKLIST-*` gets the steps.
5. `REALIGNMENT-LOG.md` (root rule 9): new endpoints, `TransferStatus` value set, masked `incoming_transfer` on the POS feeds.
6. `docs/glossary.md` rows (§2), `packages/shared/types/generated.d.ts`, frontend permission map regenerated in the same PR.

---

## 12. Owner question (one) and rulings applied

**OQ-4 Freight on written-off / returned units (gate owner decision 3 — not yet ruled).** Default in §6.4: weights on landed quantity; the share of written-off/returned units is never capitalized, recorded as `freight_uncapitalized`, no journal (transfer freight has no GL leg today, `WeightedAverageCostService.php:745-800`). Alternatives: (a) keep shipped-quantity weights and capitalize the written-off share onto the product's surviving units (over-values them; silently lost when company-owned quantity is 0, `:771-775`); (b) post an explicit Dr Shrinkage / Cr Inventory line for that share (books a credit against an inventory value that was never debited — needs a transfer-freight contra account and a GL purpose that do not exist); (c) the default. Ruling needed before the T-2 close lane merges; T4 asserts the default until then.

Applied rulings (2026-09-09): D1 accepted (§5.4, §5.8); D2 accepted + receiver-note ticket (§1); OQ-1 include `disposition` with no GL on return (§5.2, §6.3); OQ-2 leave settled, notify processors in T-4 (§4.3); OQ-3 accept the coarse status signal (§5.0 R3); pattern detection via stored per-line events + reference query (§4.2, §4.4).
