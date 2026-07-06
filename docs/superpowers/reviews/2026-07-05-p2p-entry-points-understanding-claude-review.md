# Adversarial design review — P2P flexible entry points (pre-spec)

> Target: `docs/sessions/DESIGN-UNDERSTANDING-p2p-entry-points.md`
> Research basis: `docs/superpowers/audits/2026-07-05-p2p-entry-points-research.md`
> Worktree: `apps/erp.p2p-flow` @ `feat/p2p-entry-points` (base = procurement v1 complete)
> Method: every code claim verified against this worktree; file:line cited. Claims that
> could not be verified are marked "cannot verify" rather than asserted.
> Reviewer: Claude (Opus). Date: 2026-07-05.

---

## Verdict: NEEDS-REVISION

Two BLOCKER findings invalidate load-bearing claims in the design as written:
- **B1** — the SI matcher does NOT filter goods_receipt_lines by receipt status, so the
  proposed Draft goods receipts create phantom matchable windows. The design's "zero
  matcher changes" claim (§1b, §5) is false once Draft receipts exist.
- **B2** — `procurement:rematch-drafts` does NOT relink parked (unlinked) Draft SIs to
  later receipts; it only re-prices already-PO-linked lines. The invoice-first
  "not yet delivered" linkage mechanism (§1c, R5) does not exist in the code the design
  claims to reuse.

Both are fixable in the spec (add status filtering / define the relink mechanism), so the
approach is sound but the understanding must be revised before it becomes a spec.

---

## Attack surface 1 — the seven risk questions (§7)

### R1 — auto-PO Document invariants (numbering, events, projections) — MAJOR
Creating + confirming an auto-PO is mechanically supported (numbering works, §R4), but
**confirming a PO is not side-effect-free**:
- `PurchaseOrderService::confirm()` snapshots taxes and calls
  `landedCostService->allocateCostsAndTaxes()` then dispatches `PurchaseOrderConfirmed`
  (`apps/api/app/Modules/Document/Domain/Services/PurchaseOrderService.php:79-101`). Any
  listener/analytics on that event fires for auto-POs.
- Aged Payables treats the **PurchaseOrder itself** as the payable and queries
  `type = PurchaseOrder, status = Posted, balance_due > 0`
  (`.../Accounting/Application/Services/Reports/AgedPayablesService.php:91-101`) with no
  payload filter — an auto-PO that ever carries a balance would inflate supplier aging.
  (No PO service writes `balance_due` — cannot verify whether the auto-PO path leaves it
  zero; the spec must state it explicitly.)

**Spec must say:** auto-PO confirm must not emit analytics-affecting events (or those
consumers must exclude auto-POs), and must guarantee auto-POs never surface in AgedPayables
(the invoice, not the PO, is the payable — but the report queries the PO).

### R2 — PO + receipt + SI in one transaction — MAJOR (nesting is unsafe as-is)
`receiveGoods` opens its own `DB::transaction()`
(`GoodsReceiptService.php:76`) and wraps `ProductCostLock::acquire()`
(`:103-141`), which takes **transaction-scoped** `pg_advisory_xact_lock` in sorted product
order (`ProductCostLock.php:43-48`). `CreateSupplierInvoiceService::create()` also opens its
own transaction (`.../Procurement/Application/CreateSupplierInvoiceService.php:60`), as does
`PurchaseOrderService::confirm()` (`:60`) and `DocumentNumberingService` (`:44`). Under a
single outer transaction these become SAVEPOINTs.

Two concrete hazards:
1. **`attempts: 3` retry is defeated by nesting.** `WeightedAverageCostService::recordPurchase`
   (and `recordReturn`, `recordCostAdjustment`) run `DB::transaction(closure, attempts: 3)`
   (`WeightedAverageCostService.php:154`, `:482`, `:686`). When NOT the outermost
   transaction, Laravel's retry only rolls back to the inner SAVEPOINT. A real PostgreSQL
   deadlock/serialization failure aborts the ENTIRE transaction, so the savepoint-level
   retry re-runs against an already-aborted transaction ("current transaction is aborted")
   — converting a recoverable deadlock into a hard failure. Standalone this retry works;
   wrapped in an invoice-first umbrella transaction it does not.
2. **Lock hold time balloons.** The advisory xact locks (product) and the
   `document_sequences` `FOR UPDATE` row lock (§R4) are held until the OUTER commit — i.e.
   across PO create + receive + SI create + tax calc + matcher — widening the contention
   window well beyond the receive step.

**Spec must say:** whether invoice-first "already delivered" is a saga of two transactions
with compensation, or one transaction with the `attempts:` retry hazard explicitly
neutralized (e.g. hoist retry to the outermost boundary). Either way, name the transaction
boundary and the deadlock-recovery strategy.

### R3 — auto-PO consumer exposure — MAJOR (2 of the exposures are behavioral)
The `payload->auto_generated` flag persists (plain `array` cast,
`Document.php:192`) but is **dropped by the API serializer**: `DocumentData::fromModel()`
cherry-picks a whitelist of payload keys and has no `auto_generated` / raw-payload
passthrough (`.../Document/Application/DTOs/DocumentData.php:124-141`). So the frontend
"show auto-generated" toggle (§1b) **cannot work client-side** — filtering must be a
server-side query, and a DTO field must be added if the UI needs the flag.

Every PO consumer selects by `type = PurchaseOrder` with no payload filter:
- PO list index — `PurchaseOrderController.php:225` (filter set has no payload flag,
  `Concerns/HandlesDocuments.php:135-180`)
- Generic document list — `DocumentController.php:66`
- Related-chain reader — `DocumentController.php:201-263` + `Document::getDocumentChain()`
  (`Document.php:389`)
- **RFQ award guard (behavioral)** — `PurchaseQuoteRequestAwardService.php:85-90` counts any
  non-cancelled RFQ-sourced PO as a live award → an auto-PO would falsely trip
  `RFQ_GROUP_ALREADY_AWARDED` (`:94`).
- **RFQ group `has_live_purchase_order` (behavioral)** —
  `PurchaseQuoteRequestController.php:100-105`.
- Aged Payables — `AgedPayablesService.php:95` (see R1).
- SupplierInvoiceMatcher line-parent check — `SupplierInvoiceMatcher.php:350-355`.
- PDF — `DocumentPdfService.php:43+`.

**Spec must say:** the canonical server-side auto-PO exclusion predicate and the exhaustive
list of surfaces it is applied to (must include the two behavioral ones — the RFQ award
guard must still see auto-POs or must explicitly exclude them by intent), plus whether
`DocumentData` gains an `auto_generated` field.

### R4 — Draft goods receipts and GRN numbering — MAJOR
`generateForKey` runs inside `receiveGoods`'s transaction
(`GoodsReceiptService.php:112`), and the sequence increment is a `lockForUpdate()`
read-modify-write on the single `document_sequences` row for `(company_id, type, year)`
(`DocumentNumberingService.php:44-66`; unique key `add_company_id_to_document_sequences.php:26`).
Consequences:
- A rollback DOES return the number (increment is in the same transaction) — good.
- But `receipt_number` is **NOT NULL** with a unique index
  (`create_goods_receipts_tables.php:19,30`). A committed Draft therefore **burns a GRN
  number** unless the spec makes `receipt_number` nullable and defers `generateForKey` to
  `post()`.
- The `FOR UPDATE` row lock serializes all same-company/same-year GRN creation (draft or
  posted) and is held for the whole receive op (`DocumentNumberingService.php:50`).

**Spec must say:** number at POST time, make `receipt_number` nullable for Draft, and note
that draft creation must NOT take the sequence lock.

### R5 — parked Draft SIs + matcher status filtering — BLOCKER (B1 + B2)
**B2 (rematch-drafts does not relink).**
`RematchDraftSupplierInvoicesCommand` selects `type = SupplierInvoice, status = Draft`
(`.../Console/Commands/RematchDraftSupplierInvoicesCommand.php:46-47`) and, for each line
that ALREADY has a `source_line_id` (skipping unlinked lines,
`:70-90`), re-runs the FIFO planner and rewrites `price_match_basis` +
`matched_receipt_line_id`. It **re-prices already-PO-linked lines**; it does NOT scan for
parked SIs whose lines lack a `source_line_id` and attach them to newly-arrived receipts.
The design's §1c/R5 claim ("becomes postable when receipts covering its lines exist… or
`procurement:rematch-drafts`") assumes a relink mechanism that does not exist.

**B1 (matcher ignores receipt status).** Both matchable-qty paths query
`GoodsReceiptLine where po_line_id = ?` with **no status filter**:
- `ReceiptLineConsumptionPlanner.php:30-34` (FIFO; `matchableQty = received_qty −
  quantity_invoiced`, `:77-83`)
- `SupplierInvoiceMatcher.php:151-153` and the bonus path `:414-416`
- The matcher also reads the PO-line counter `quantity_received` directly
  (`SupplierInvoiceMatcher.php:168`, `:428`).

`GoodsReceiptLine` has no status column; status lives on the parent `GoodsReceipt`
(`GoodsReceiptLine.php:118-122`). Today this is harmless because every receipt is written
`Posted` (`GoodsReceiptService.php:118`). Introduce Draft receipts (§3.2) and their lines
immediately count toward matchable qty and FIFO basis → phantom matchable windows and
premature/incorrect SI matching.

**Spec must say:** (a) define the actual relink pass for parked unlinked Draft SIs (new
command or extend rematch to attach `source_line_id` from receipts), and (b) either add a
`whereHas('goodsReceipt', status = Posted)` filter to BOTH the planner and the matcher, or
guarantee Draft receipts never write `goods_receipt_lines.received_qty` / never advance
`quantity_received`. The "zero matcher changes" claim must be retracted.

### R6 — preset / country defaults — MAJOR
- **Country → preset does not exist today.** Default policy resolution keys off
  `tenant->vertical` (`ProcurementPolicyResolver.php:40`,
  `ProcurementPolicy::defaultForVertical`), NOT country. `Company::country_code` is
  `char(2)` NOT NULL default `TN` (`create_companies_table.php:34`) and would be the source,
  but the mapping is net-new.
- **Preset re-pick force-overwrites only preset-governed fields.**
  `ProcurementPolicy::applyPreset()` force-fills `preset` + `ProcurementPreset::fields()`
  (bill_control_mode / match_mode / match_enforcement) and PRESERVES variance tolerances
  (`ProcurementPolicy.php:122-128`, `ProcurementPreset.php:22-38`). If the new
  `allow_receipt_first` / `allow_invoice_first` toggles are to change on re-pick they must be
  added to `ProcurementPreset::fields()`; otherwise a preset re-pick silently preserves the
  old toggle values.

**Spec must say:** where country→preset reads country from, when it fires (creation only? on
country change?), and whether the new toggles are preset-governed (in `fields()`) or
independent.

### R7 — revert() of PO-before-receipt vs RFQ award / parked SI — MAJOR
Reverting an awarded PO to Draft is unsafe:
- The award guard treats ANY non-Cancelled RFQ-sourced PO as a live award
  (`PurchaseQuoteRequestAwardService.php:85-90`, `where status != Cancelled`). A PO reverted
  to Draft is still non-Cancelled → still blocks re-award (`RFQ_GROUP_ALREADY_AWARDED`,
  `:94`), while the losing siblings were already set `Cancelled/closedReason:'lost'` at award
  time (`:61-71`) and are NOT un-cancelled by a revert. The RFQ group gets stuck: winner
  unusable, losers dead.
- A parked Draft SI links to PO lines via `source_line_id`; reverting/mutating a PO with
  linked SI lines would strand those links.

**Spec must say:** revert() is forbidden for RFQ-sourced POs and for POs referenced by any
non-cancelled SI line (`source_line_id`), OR define the sibling un-cancel + SI-relink
compensation.

---

## Attack surface 2 — Draft goods receipts (§3.2) blast radius

Splitting `receiveGoods` into Draft + `post()` moves these receive-time effects to
post-time (all in `GoodsReceiptService.php`):
- WAC `recordPurchase` (stock_movement + WAC blend) — `:276`, `:315`
- `GoodsReceived` event → GR-IR/408 GL (single listener
  `PostGrIrOnGoodsReceipt`, `EventServiceProvider.php:117-118`, keyed on `movementId`) — `:287`, `:326`
- `batchStockService->receiveBatchStock` — `:300-308`, `:339-347`
- `accrual_unit_cost` capture on PO line — `:352-354`
- **PO-line counters** `quantity_received` / `free_quantity_received` `$line->save()` —
  `:310`, `:356`, `:363` (these drive the matcher — see B1)
- PO status → `Received` + `fully_received` payload — `:399-409`
- receipt-line `movement_id` / `free_movement_id` / `landed_unit_cost` /
  `effective_unit_cost` are post-time artifacts (movement id is the GL/idempotency anchor) —
  `:365-389`

Assumers of "receipt == posted / counters == stock":
- `SupplierInvoiceMatcher` (matchable = `quantity_received − quantity_invoiced`) —
  `:141`, `:168`, `:428`
- `SupplierInvoicePostingService` asserts `newInvoiced <= quantity_received` — `:189`, `:196`, `:230`, `:237`
- `BackfillGoodsReceiptsCommand` writes `Posted` and reconciles counters == posted receipt
  sums — `:176`, `:437-465` (fine, as long as Draft never touches counters)
- `PurchaseOrderToGoodsReceiptConverter.php:97`, `PurchaseOrderController.php:662,754`
  (receipt-status endpoints)

Wave 4 price-override fields (`received_unit_price` decimal(15,3),
`price_override_*`, `create_goods_receipts_tables.php:44-54`) may persist on a Draft; the
cost fields (`landed_unit_cost`/`accrual_unit_cost`/`effective_unit_cost`) cannot exist until
post.

**Spec must say:** a Draft receipt writes header + line skeleton + `received_unit_price` only;
it must NOT write `received_qty` in a matcher-visible way, must NOT advance PO counters, must
NOT set `movement_id`/cost fields, and `post()` performs everything above atomically. Reconcile
with B1 (matcher status filter is required regardless, because `goods_receipt_lines.received_qty`
is written at line-create time today, `:372`).

---

## Attack surface 3 — auto-PO provenance (§1b/1c)

Covered under R3. Net: flag persists in JSONB, is invisible through the serializer, must be
enforced server-side. There is a typed-payload precedent (`RfqPayload`,
`.../Procurement/Domain/Dto/RfqPayload.php:10`) applied only to `payload->rfq`; top-level
`payload` writes bypass it, so `auto_generated` round-trips.

---

## Attack surface 4 — policy fail-open (§1d) — MAJOR

The design says the new toggles are "enforced server-side in the services (fail closed)".
The existing resolver is **fail-OPEN to a safe default**: when no `procurement_policies` row
exists, `ProcurementPolicyResolver::forCompany()` returns
`ProcurementPolicy::defaultForVertical(...)` (unsaved Standard policy)
(`ProcurementPolicyResolver.php:40`), it does not throw. Since **Standard =
receipt-first ON** (§1d), a tenant with no row (pre-migration, or provisioning race)
fail-opens INTO an entry point the design intends to gate.

New tenants do get a row (`TenantProvisioningService.php:159`
`firstOrCreateForCompany`) and the API lazily creates on read
(`ProcurementPolicyController.php:25,36`), but the migration for existing tenants and the
in-memory default path are the exposure. Also: the existing columns are NOT NULL with DB
defaults, so the resolver has no NULL-coalescing logic; a new nullable boolean column would
be read as its raw model value with no default handling in the resolver.

The only fail-CLOSED path today is the Phase-1 `bill_control_mode === Ordered` guard that
throws (`ProcurementPolicyResolver.php:43-49`). Note also there is **no procurement policy
middleware** — enforcement is purely in `SupplierInvoiceMatcher` / `SupplierInvoicePostingService`
via the resolver (`Procurement/Presentation/routes.php:19-23` explicitly documents "no
`module:Procurement` gate").

**Spec must say:** the resolved default for `allow_receipt_first` / `allow_invoice_first` when
the row/column is absent must be OFF (fail-closed) — this contradicts falling back to the
Standard vertical default; either backfill every existing tenant's row in the migration with
explicit values, or add coalescing in the resolver that defaults the new toggles to false
independent of preset.

---

## Attack surface 5 — lifecycle proposals (§3)

### SalesOrder is NOT stock/GL-inert — MAJOR (design mischaracterization)
The design lists SalesOrder among "stock/GL-inert types" eligible for `revert()` (§3.3).
`SalesOrderService::confirm()` → `confirmAndReserveStock()` reserves stock when the company
setting `autoReserveOnSalesOrder` is on (`SalesOrderService.php:72,93,142-152`), creating real
`StockReservation` rows that decrement available (`StockReservationService.php:120-122,156`).
A revert MUST call `releaseBySource(ReservationSource::SalesOrder, ...)` — the existing
release path is `cancelAndReleaseStock()` (`SalesOrderService.php:230-244`) — and only when
the setting was on at confirm time. Confirm also snapshots taxes and emits
`SalesOrderConfirmed` + `SalesOrderConfirmedV2` audit events (`:180-186,270-295`) that a
revert leaves dangling.

**Spec must say:** SO revert is conditional on `autoReserveOnSalesOrder` and must release
reservations; account for the dangling confirm audit events (events are immutable — CLAUDE.md
rule 8 — so a compensating `SalesOrderReverted` event is the pattern, not deletion).

### GoodsReceiptStatus dead enums — confirmed
`Draft` / `Cancelled` are never written or read anywhere; the only writes are `Posted`
(`GoodsReceiptStatus.php:9-11`; `GoodsReceiptService.php:118`;
`BackfillGoodsReceiptsCommand.php:176`). The design's plan to make them reachable is sound.

### cancel() unpaid-guard is scattered, not unified — MINOR (scope)
There is no single `Document::cancel()`. An unpaid-guard exists in
`RefundService::cancelInvoice()` (blocks Posted AND Paid,
`RefundService.php:29-35`); `SalesOrderService::cancel()` blocks Posted only (`:211-217`);
`DocumentPostingService::cancel()` is the fiscal-void path for Posted docs (`:100-112`). The
design's "cancel() gains the unpaid-guard; transitions live in ONE place" (§3.4) understates
the consolidation effort — three cancel implementations must be reconciled.

### No existing revert transition — confirmed
The only precedent is `PurchaseQuoteRequestService::reopenGroup()`
(`.../Procurement/Application/PurchaseQuoteRequestService.php:138`), an RFQ-group reopen,
not a generic document revert. `document_number` is assigned at draft creation
(`DraftPersistenceService.php:116`), so a revert retains the number.

---

## Attack surface 6 — hand-waves

### Module boundary — StandaloneReceiptService placement — MAJOR
`GoodsReceiptService` is in the **Inventory** module
(`GoodsReceiptService.php:5`); PurchaseOrder is a **Document**-module model (there is no
separate Procurement module for it — `receiveGoods` imports
`App\Modules\Document\Domain\Document`, `:9`). The cross-module rule
(`.claude/context/architecture.md:45-52`) forbids importing models across modules;
communication only via `Shared/Contracts/`, Events, or a public Service. `receiveGoods`'s
public signature takes a `Document` model and returns an Inventory DTO
(`GoodsReceiptResult`, `:13,59-67`) — there is no primitive/ID-based or `Shared/Contracts`
receipt-creation interface. A `StandaloneReceiptService` that creates a PO Document AND calls
`receiveGoods` touches Document + Inventory internals; to be compliant it must live inside
Document or Inventory (whichever legitimately depends on the other), not in a new module, and
it re-implements PO creation that the Document module owns.

**Spec must say:** the module that owns `StandaloneReceiptService`, and whether a
`Shared/Contracts` receipt-creation interface is introduced (preferred) or the service is
placed inside Inventory/Document.

### Precision contract (rule 19) on BL prices — MINOR
`goods_receipt_lines.received_unit_price` is `decimal(15,3)` — compliant money floor
(`create_goods_receipts_tables.php:44`). The existing `ReceiveGoodsRequest` already carries
the money regex `/^\d+(\.\d{1,3})?$/` (`.../Requests/ReceiveGoodsRequest.php:36`). Any NEW
standalone-receipt / invoice-first FormRequest carrying BL prices must add the same regex
ceiling. The proposed `goods_receipts.external_reference` / `external_date` columns are
net-new (confirmed absent from the migration).

### Permissions naming — MINOR
`goods-receipt.create-standalone` is new and matches the convention (only
`goods-receipt.edit-price` exists, `RolesAndPermissionsSeeder.php:121`).
`supplier-invoice.create-invoice-first` is new AND **breaks convention** — invoice
permissions use the `invoices.*` namespace (`:130-136`); there is no `supplier-invoice.*`
permission anywhere. Rename to `invoices.create-invoice-first`.

### i18n namespace — MINOR
There is no `procurement` / `receipts` namespace; procurement/receipt strings live in
**`purchases`** (`apps/web/src/lib/i18n.ts:404` `ns` array; `frPurchases` at `:104`).
"Facturer les réceptions" already exists (`fr/purchases.json`,
`supplierInvoices.create.invoiceReceipts`). "Nouvelle réception" is net-new — add under the
existing `purchases` namespace, do not introduce a new top-level namespace.

### Migration hazard for existing tenants — MAJOR (folds into §1d)
The new `procurement_policies` boolean columns and `goods_receipts.external_reference/
external_date` columns land per-tenant (db-per-tenant). The policy migration must backfill
explicit toggle values for every existing tenant (fail-closed), or the resolver's fail-open
default (Standard = receipt-first on) silently enables receipt-first on upgrade. Draft-receipt
support additionally requires making `receipt_number` nullable (§R4) — a migration on the
week-old `goods_receipts` table.

---

## Finding index

| # | Sev | One-liner |
|---|-----|-----------|
| B1 | BLOCKER | SI matcher/planner don't filter goods_receipt_lines by receipt status → Draft receipts create phantom matchable windows (`ReceiptLineConsumptionPlanner.php:30-34`, `SupplierInvoiceMatcher.php:151-153,414-416`); "zero matcher changes" is false. |
| B2 | BLOCKER | `procurement:rematch-drafts` only re-prices already-PO-linked Draft SI lines; it never relinks parked unlinked SIs to new receipts (`RematchDraftSupplierInvoicesCommand.php:46-90`) — the invoice-first "not yet delivered" linkage doesn't exist. |
| M1 | MAJOR | `attempts:3` retry in `recordPurchase` is defeated once nested (savepoint retry vs aborted outer txn); PO+receipt+SI in one transaction turns recoverable deadlocks into hard failures + holds advisory/sequence locks across all 3 steps (`WeightedAverageCostService.php:154`, `ProductCostLock.php:43-48`). |
| M2 | MAJOR | Auto-PO `payload->auto_generated` flag is dropped by `DocumentData` serializer (`:124-141`); 8 PO consumers select by type with no payload filter, 2 behavioral (RFQ award guard `:85-90`, RFQ group flag `:100-105`); AgedPayables queries PO as payable (`:95`). |
| M3 | MAJOR | Policy resolver is fail-OPEN to Standard default when no row (`ProcurementPolicyResolver.php:40`); Standard = receipt-first ON, so pre-migration tenants fail-open into the gated entry point — contradicts "fail closed". New nullable columns have no resolver coalescing. |
| M4 | MAJOR | Reverting an awarded PO to Draft leaves it non-Cancelled (still trips `RFQ_GROUP_ALREADY_AWARDED`, `PurchaseQuoteRequestAwardService.php:90-94`) while losing siblings stay permanently Cancelled → RFQ group stuck; parked-SI `source_line_id` links also stranded. |
| M5 | MAJOR | Design lists SalesOrder as "stock/GL-inert" for revert, but SO confirm reserves stock when `autoReserveOnSalesOrder` is on (`SalesOrderService.php:142-152`); revert must conditionally release reservations + handle dangling confirm audit events. |
| M6 | MAJOR | Draft receipt split moves WAC/GL/counters/status to post-time; PO counters & `goods_receipt_lines.received_qty` drive the matcher (`GoodsReceiptService.php:310,356,372`) — Draft must not write matcher-visible qty (ties to B1). |
| M7 | MAJOR | `StandaloneReceiptService` touches Inventory (`GoodsReceiptService`) + Document (PO creation); no `Shared/Contracts` receipt interface, `receiveGoods` takes a Document model — placement must be inside Inventory/Document or a new contract, per cross-module rule (`.claude/context/architecture.md:45-52`). |
| M8 | MAJOR | GRN `receipt_number` is NOT NULL + unique (`create_goods_receipts_tables.php:19,30`); a committed Draft burns a sequence number unless numbering defers to post() and the column becomes nullable; sequence `FOR UPDATE` lock held for whole receive op (`DocumentNumberingService.php:50`). |
| M9 | MAJOR | Migration hazard: per-tenant policy columns must backfill fail-closed toggle values for existing tenants; `goods_receipts` needs `receipt_number` nullable + net-new `external_reference/external_date`. |
| m1 | MINOR | `supplier-invoice.create-invoice-first` breaks the `invoices.*` permission convention (`RolesAndPermissionsSeeder.php:130-136`); rename to `invoices.create-invoice-first`. |
| m2 | MINOR | No `procurement`/`receipts` i18n namespace — use existing `purchases` (`i18n.ts:404`); "Nouvelle réception" is net-new. |
| m3 | MINOR | New standalone/invoice-first FormRequests must carry the rule-19 money regex `/^\d+(\.\d{1,3})?$/` on BL prices (existing `ReceiveGoodsRequest.php:36` precedent). |
| m4 | MINOR | cancel() is not unified — 3 implementations (`RefundService.php:29-35`, `SalesOrderService.php:211-217`, `DocumentPostingService.php:100-112`); "one place + unpaid-guard" understates the consolidation. |
| R1 | MAJOR (in M2) | Auto-PO confirm emits `PurchaseOrderConfirmed` + allocates landed cost (`PurchaseOrderService.php:98-101`) — not side-effect-free for analytics/listeners. |

Confirmed-safe / no finding: payload `auto_generated` DOES persist at rest (`Document.php:192`
plain array cast); GRN numbering is rollback-safe within the transaction (number returned on
abort); GoodsReceiptStatus Draft/Cancelled are genuinely dead and safe to make reachable;
`ProductCostLock` acquires advisory locks in deterministic sorted order (`:43-48`), so
combining transactions introduces no new AB-BA cycle.

---

## Verdict line

READY / **NEEDS-REVISION** / BLOCKED  →  **NEEDS-REVISION** (2 BLOCKERs + 9 MAJORs to
resolve in the spec; approach is sound).
