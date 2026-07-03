# Procurement Completeness — RFQ, Goods-Receipt Price Edit, Supplier-Invoice Creation UI — Design Spec

- **Date:** 2026-07-03
- **Status:** DESIGN · **Revision 3** (owner scope review, 2026-07-03) — approved for implementation on the `post-demo` integration branch; promote to demo/dev only when stable (owner call). Rev 3 deltas: multi-supplier RFQ groups pulled INTO v1, multi-PO supplier invoices pulled INTO v1 (final wave), new Procurement Presets section, receipt-first/invoice-first explicitly deferred to Phase 2 as owner decisions. See `## Revision 3 — owner scope decisions`.
- **Previous:** Revision 2 (post Codex RETHINK, 2026-07-03).
- **Author:** Claude (spec-writing session; every "current behavior" claim verified against `dev` at cited file:line).
- **Scope:** Three owner-identified gaps in the purchase module, surfaced by a prospective parapharmacy customer who runs a competitor ERP daily. Purchasing side only.
- **Revision 2 headline:** Gap 2 moves from PO-line grain to **first-class `goods_receipts` header + `goods_receipt_lines`** (the landed-cost spec's Phase-3 dependency, pulled forward). Each receipt line carries its own received qty/price/accrual snapshot; PO-line counters become derived aggregates. The invoice-vs-received residual now posts to a **Purchase Price Variance account** (WAC=GL preserved), not silently into the Inventory plug. See the `## Revision 2 — Codex RETHINK disposition` table below for finding-by-finding rationale and the code evidence behind every accept/reject.
- **Grounds on:**
  - `docs/superpowers/specs/2026-07-02-payment-document-landed-cost-design.md` (receipt-grain finding, accrual boundary, GR-IR reconciliation, `recordCostAdjustment` float prereq). **Revision 2 pulls that spec's Phase-3 `goods_receipts` header forward into this spec's v1.**
  - `docs/superpowers/specs/2026-07-02-purchase-bonus-quantity-design.md` (`free_quantity` + `price_entry_mode` on PO lines; the GR price edit must compose with bonus lines).
  - `docs/architecture/vertical-module-gating.md` (two-layer gating, `ModuleName` enum drift guard).
  - `project_supplier_invoice_ocr_capture` (in-flight; Scan-to-Document supplier-invoice OCR — a forward dependency for Gap 3 Phase 2; design uncommitted, referenced not consumed).

All paths relative to `apps/api/` or `apps/web/` unless noted. The ERP is nested at repo `apps/erp/apps/{api,web}`; the `.worktrees/` copies are duplicates and are ignored.

---

## Revision 2 — Codex RETHINK disposition

| # | Finding (Codex) | Disposition | Structural change in Rev 2 (or code evidence for rejection) |
|---|---|---|---|
| C1 | Received-price model is at the wrong grain (PO-line + immutable single `accrual_unit_cost` can't model multi-price partial deliveries without a 422) | **ACCEPTED** | Gap 2 rebuilt on `goods_receipts` header + `goods_receipt_lines` (§2.3). Each receipt line owns `received_qty / received_unit_price / landed_unit_cost / accrual_unit_cost (immutable per line) / movement_id / quantity_invoiced`. PO-line `quantity_received`/`quantity_invoiced` become derived summary counters (§2.3.1). Multi-price partial receipts are now normal — the 422 is **removed** (§2.6). Verified: `GoodsReceiptService.php:154-157,216-221` (single line-level basis) and `SupplierInvoicePostingService.php:127-154` (clears aggregate invoiced qty at one PO-line basis, B3 asserts it equals `accrual_unit_cost`). |
| C2 | Spec accepts a WAC-vs-GL divergence at invoice time | **ACCEPTED** | The invoice-vs-accrual price residual is **redirected to a two-sided Purchase Price Variance account** (601-side charge / 7-side income), NOT the Inventory plug. Inventory (and therefore perpetual WAC) is left at the received/booked basis, so **WAC == GL inventory permanently** (§2.4, walk-through Cases A/B/C). B3 guard unchanged: each receipt line still clears at its own immutable `accrual_unit_cost`. Verified: `GeneralLedgerService.php:1225-1230` (`plug = total − known`), `:1291-1311` (posts the plug to Inventory Dr/Cr), no WAC call on that path; `WeightedAverageCostService.php:236-248,285-286` (WAC updates only on receipt). |
| H3 | Bonus/free-quantity composition underspecified & wrong at service level (`last_purchase_cost` corrupted to 0 by the free movement) | **ACCEPTED** | Receipt-line grain stores `paid_qty`, `free_qty`, `received_unit_price` (paid only), `effective_unit_cost`, and both movement ids explicitly (§2.6). `last_purchase_cost` is **defined = last PAID supplier unit price** and the paid movement is written LAST so it wins (§2.6). Verified: `GoodsReceiptService.php:167-176` (one `recordPurchase` today), `WeightedAverageCostService.php:285-286` (`last_purchase_cost` = movement unit cost). |
| H4 | Landed-cost allocation by PO `line_total` conflicts with received-price truth | **ACCEPTED (scoped)** | Receipt-line `landed_unit_cost` allocates freight/insurance over **received value within the receipt batch**, not PO line_total (§2.4.1). Deep multi-receipt / multi-freight-document allocation stays owned by the landed-cost spec; this spec defines the single-receipt-batch case + tests for partial/free/zero lines. Verified: `LandedCostService.php:365-383,395-420,519-525`. |
| H5 | Matcher basis switch reclassifies existing draft invoices at post time (Block enforcement → throw) | **ACCEPTED** | Supplier-invoice lines now **snapshot the match basis at creation** (`price_match_basis`, `matched_receipt_line_id`); posting re-runs the matcher against the snapshot, not live state (§2.5). A `procurement:rematch-drafts` command + release note handle `match_enforcement=block` tenants. Verified: `SupplierInvoicePostingService.php:85-93` (`assertPostable` + `match` re-run inside posting txn), `SupplierInvoiceMatcher.php:220-228,375-381`. |
| H6 | Gap 1 "no DB migration" is false — `purchase_quote_request` (22 chars) overflows `documents.type string(20)` | **ACCEPTED** | Stored enum value changed to **`purchase_rfq`** (12 chars) — fits both `documents.type(20)` and `document_sequences.type(20)`; no column change, additive enum only (§1.3, §1.8). Verified: `2025_11_30_080000_create_documents_table.php:18` (`string('type', 20)`), `2025_11_30_080002_create_document_sequences_table.php:16`. |
| M7 | First-class RFQ document must enumerate its opt-outs (fiscal, payment, status, totals) | **ACCEPTED** | New **§1.12 RFQ invariants** section: `FiscalCategory::NonFiscal` (already the `default` arm — verified `FiscalCategory.php:44` `default => self::NonFiscal`), no payment/balance/stock/GL listeners, allowed operational statuses, `Converted`/`Closed` representation, mandatory-supplier decision. |
| M8 | From-receipts entry point contradicts the one-PO create contract | **ACCEPTED** (superseded in part by Rev 3/S4) | Rev 2 hard-scoped from-receipts selection to a single PO with a client-side block. **Rev 3 pulls multi-PO invoicing into v1 as the final wave** (§3.2, §3.11): `CreateSupplierInvoiceRequest` relaxed to `source_document_ids[]`, same-supplier/currency/company guard replaces the single-PO block. Until that wave lands, the intermediate waves keep the Rev 2 client-side block so the UI never composes a payload the backend rejects. Verified: `CreateSupplierInvoiceRequest.php:55-59,113-123,143-162` (single required PO source; every line must belong to it). |
| M9 | Supplier reference is not stored in `payload` — backend already writes `external_document_number` | **ACCEPTED** | Spec corrected: v1 reuses **`external_document_number`** (no new column) and adds a **non-blocking duplicate warning** on `(company_id, partner_id, external_document_number)` (§3.3). Verified: `CreateSupplierInvoiceRequest.php:63`, `CreateSupplierInvoiceService.php:123` (`'external_document_number' => $validated['supplier_reference'] ?? null`). |
| M10 | Receipt price edit needs audit, not only a permission | **ACCEPTED** | `goods_receipt_lines` carries `price_override_by / price_override_at / price_override_old_basis / price_override_reason`; the receipt service gains an actor param (§2.3, §2.7). Verified: `GoodsReceiptService.php:167-176` (no actor today), `WeightedAverageCostService.php:258-278`. |
| L11 | Code-grounding corrections | **ACCEPTED** | Applied: `SupplierInvoiceMatcher` lives in `Procurement/Application/` (not a Document domain path); there is **no Laravel `ReceiveGoodsRequest`** (the name is a FE interface in `ReceiveGoodsDialog.tsx:14-17`) — §2.8 now says "add a Laravel FormRequest"; the sales-`Quote` claim is re-grounded on controllers/routes/converters, not `affectsReceivable()` (which returns true only for `Invoice`/`CreditNote` — verified `DocumentType.php:64-66`). |
| CG | Supplier credit notes → received-price WAC/GL true-up not connected | **REJECTED for v1 (explicit non-goal)** | With C2's Purchase Price Variance destination, the price residual is already recognised at invoice posting, so `SupplierCreditNoteReason::PriceAdjustment` (verified present, `SupplierCreditNoteReason.php:24`) driving a retro WAC adjustment is a *separate* downstream-AP concern, not a completeness blocker. Deferred with justification (§Cross-Cutting). |
| CG | Multi-supplier RFQ comparison below competitor bar | ~~DEFERRED~~ **SUPERSEDED by Rev 3/S7 — owner pulled multi-supplier into v1** | Rev 2 kept single-supplier v1 pending OQ1. Owner resolved OQ1: multi-supplier fan-out + comparison + whole-RFQ award ship in v1 as sibling-document groups (§1.2). Codex's competitor-parity concern is thereby addressed structurally. |

---

## Revision 3 — owner scope decisions (2026-07-03)

Owner reviewed Rev 2 against the required product scenarios. Dispositions:

| # | Scenario / question | Owner decision | Structural change in Rev 3 |
|---|---|---|---|
| S1 | Full chain RFQ → PO → receipt → invoice | required, v1 | already covered (Gaps 1–3). |
| S2 | One PO ↔ multiple receipts (multi-price partials) | required, v1 | already covered (receipt ledger, §2). |
| S3 | One invoice ↔ multiple receipts | required, v1 | covered within one PO (FIFO, §2.5); across POs → S4. |
| S4 | One invoice ↔ multiple POs | **pulled into v1 as the FINAL wave** | `CreateSupplierInvoiceRequest` relaxed to `source_document_ids[]` (same supplier/currency/company); matcher is already PO-count-agnostic at receipt-line grain; UI drops the cross-PO block for same-supplier selections. §3.2/§3.11 updated; M8's client-side block becomes a same-supplier guard. |
| S5 | Start directly with receiving goods (no PO) | **explicitly Phase 2** (owner call — offered v1/schema-ready/Phase 2, chose Phase 2) | recorded in §2.11 + Presets; `goods_receipts.purchase_order_id` stays NOT NULL in v1 (trivial nullable migration when Phase 2 lands, slotting into the Léger preset). |
| S6 | Invoice-first purchasing (start from an invoice, derive the rest) | **left aside** (owner) | stays §3.6 forward-looking principle; no v1/Phase-2 commitment. |
| S7 | Multi-supplier RFQ ("demande de prix" fan-out + comparison) | **required in v1 from the get-go** (owner: "very practical, also needed later for automotive") — resolves OQ1 | Gap 1 expands: RFQ **groups** = N sibling `purchase_rfq` documents (one per supplier) sharing `payload->rfq.group_id`; comparison view; whole-RFQ award converts the winner to a PO and closes siblings. Per-line split award = Phase 2. §1.2/§1.3/§1.4/§1.5/§1.9/§1.10 updated. |
| S8 | Behavior configurable via seeding presets | required | new **§ Procurement Presets** section: `ProcurementPreset` enum `{Complet, Standard, Léger}` = named bundles over existing `procurement_policies` fields; seeder applies per tenant/vertical; company-settings switch. |
| S9 | Rev 2 open questions OQ2/OQ3/OQ5 + edit-price permission | accepted as recommended | match basis = receipt-line accrual; PPV posting (OQ3 still to be countersigned by expert-comptable — account numbers only, not the mechanism); duplicate supplier-ref = non-blocking warning; `goods-receipt.edit-price` separate permission. |

Rev 3 also updates §0's composition caveat: `free_quantity` / `free_quantity_received` / `free_quantity_invoiced` / `price_entry_mode` HAVE landed on dev (`2026_07_02_100000_add_purchase_bonus_fields_to_document_lines.php`, `DocumentLine.php`), so the bonus composition in §2.6 builds on merged columns, not a sibling spec assumption.

---

## 0. Shared context — the unified-document reality (verified)

Everything in procurement is a row in one `documents` table discriminated by `type` (`Document\Domain\Enums\DocumentType`); there is **no** `purchase_orders` or `supplier_invoices` table and **no** dedicated Eloquent model beyond `App\Modules\Document\Domain\Document`. **Revision 2 changes exactly one part of this reality: it adds `goods_receipts` / `goods_receipt_lines` as first-class NON-document tables (a receipt ledger), because a goods receipt is an inventory/costing event, not a fiscal document** (§2.3). Everything else stays document-centric.

- `DocumentType` cases (`app/Modules/Document/Domain/Enums/DocumentType.php:9-18`): `Quote, SalesOrder, PurchaseOrder, Invoice, CreditNote, DeliveryNote, ReturnNote, Expense, SupplierInvoice, SupplierCreditNote`. **No RFQ / purchase-quote-request case; no `GoodsReceipt` case.** `Quote` (:9) is a **sales** quote — proven by its controllers/routes/converters (`Quote→SalesOrder` in `DocumentServiceProvider.php:38-43`), NOT by `affectsReceivable()` (which returns true only for `Invoice`/`CreditNote`, `DocumentType.php:64-66`). The procurement chain today starts at `PurchaseOrder`.
- Documents link via `documents.source_document_id` (nullable uuid, no FK — `database/migrations/tenant/2025_11_30_080000_create_documents_table.php:33`). Conversions run through a registry keyed `"{sourceType}:{targetType}"` (`Document/Domain/Services/Conversion/DocumentConverterRegistry.php:91,118,135,202`), copy helper `Conversion/Concerns/CopiesDocumentData.php`, event `Document/Domain/Events/DocumentConverted.php`; registered converters at `Document/Providers/DocumentServiceProvider.php:38-43` (Quote→SalesOrder, SalesOrder→Invoice, SalesOrder→DeliveryNote, DeliveryNote→Invoice, Invoice→CreditNote, PurchaseOrder→GoodsReceipt).
- **A goods receipt is not a document today:** `PurchaseOrderToGoodsReceiptConverter` has `sourceType() === targetType() === PurchaseOrder` (`.../Converters/PurchaseOrderToGoodsReceiptConverter.php:48,55-57`; docblock :18-19 "does NOT create a new document … updates the source PurchaseOrder"). Receiving mutates PO-line counters + writes `StockMovement`s + posts a GR-IR journal entry. **Revision 2 keeps the converter but has it (and the receive endpoint) ALSO write a `goods_receipts` header + lines** (§2.3) — the receipt becomes queryable state without becoming a fiscal document.
- Line model = shared `document_lines` (`2025_11_30_080001_create_document_lines_table.php:19-25`): `quantity decimal(15,4)` (:19), `unit_price` (:20; widened to `decimal(15,3)` by `2026_03_11_200000_widen_monetary_columns_to_scale_3.php:60`), `line_total` (:24 → scale 3 :62), plus later `quantity_received` (`2025_12_13_081142:21`), `quantity_invoiced` (`2026_06_26_100000:36`), `source_line_id` self-FK (`2025_12_11_194522`), `landed_unit_cost decimal(19,6)` (`2026_05_30_000000:60`), `accrual_unit_cost decimal(15,6)` cast `decimal:6` (`DocumentLine.php:140`). **In Rev 2 these PO-line columns remain but are demoted to derived summary aggregates of the receipt-line ledger (§2.3.1); the accounting grain moves to `goods_receipt_lines`.**
- ~~`price_entry_mode` / `free_quantity` / `is_bonus_line` do NOT exist in the merged tree~~ **Rev 3 update: `free_quantity` / `free_quantity_received` / `free_quantity_invoiced` / `price_entry_mode` are now ON dev** (`2026_07_02_100000_add_purchase_bonus_fields_to_document_lines.php`; `DocumentLine.php` casts). §2.6's bonus composition builds on merged columns.

Cross-cutting gating fact (relevant to all three gaps): procurement is **not** module-gated on the backend — `config/verticals.php` names no `Procurement`/`Purchases` module, and `Procurement/Presentation/routes.php` documents this deliberately ("Access is governed by per-route `can:` permissions"), piggybacking `documents.*`. The **frontend** gates the whole `/purchases` tree with `RequirePermission moduleKey="purchases"` + `purchases.{create,edit}` (`apps/web/src/routes/index.tsx:741-847`). This FE/BE asymmetry is pre-existing; each gap below reuses it rather than churning it, and §Cross-Cutting flags the cleanup.

---

# GAP 1 — Quote Requests (RFQ / Demande de Prix)

## 1.1 Current behavior (cited)

There is no RFQ concept. `DocumentType` has no purchase-quote-request case (`DocumentType.php:9-18`); the earliest procurement document is `PurchaseOrder`. The sales `Quote` (:9) is receivable/sales-side (its converter is `Quote→SalesOrder`) and semantically wrong for purchasing. The conversion registry supports arbitrary type-pair converters (`DocumentServiceProvider.php:38-43`) but none produces a PO from an upstream purchase request. So a buyer today must jump straight to a committed `PurchaseOrder` with no "ask the supplier for a price first" step — the exact gap the customer flagged (`demande de prix`).

## 1.2 Design decision

**Add a first-class `PurchaseQuoteRequest` document type (RFQ) that sits upstream of `PurchaseOrder`, convertible to a PO through the existing registry — multi-supplier in v1 via RFQ *groups* (Rev 3 / S7).**

Lifecycle (per document): `Draft → Sent → Responded → (Converted | Closed)`. v1 flow = create RFQ for one **or several** suppliers (lines entered once, fanned out into N sibling documents — one per supplier, same lines, different `partner_id`, shared `payload->rfq.group_id`) → print/PDF or email each → record each supplier's response (per-line prices + a document-level validity date and optional lead time) → **compare** responses in a group view → convert the winning RFQ to a PO (copies lines + the responded prices — the **RFQ→PO price carry-over** the competitor table calls for); the sibling RFQs auto-close (`Cancelled` + `payload.rfq.closed_reason='lost'`). No supplier portal.

**Award grain (v1): whole-RFQ to one supplier.** Per-line split awards (line 1 → supplier A, line 2 → supplier B ⇒ multiple POs) are Phase 2. A single-supplier RFQ is just a group of one — no special-casing.

The RFQ is **non-fiscal, non-receivable, non-stock** — its invariants are enumerated in §1.12. Numbering prefix via `getPrefix()`.

### Alternatives (briefly)

- **Reuse `PurchaseOrder` with a `status='rfq'`** — rejected: pollutes the PO lifecycle, numbering (a PO number implies a committed order), and the `quantity_received`/`quantity_invoiced` counters; downstream matcher/GR-IR code keys off `DocumentType::PurchaseOrder`.
- **Reuse sales `Quote`** — rejected: it is sales/receivable-side (converter `Quote→SalesOrder`); partner/tax/print semantics are wrong for a supplier ask.
- **Separate `rfq` table** — rejected: breaks the unified-document model and forfeits the conversion registry, PDF pipeline, numbering, attachments, and list/detail scaffolding that come free with a `DocumentType`.

### Multi-supplier model = sibling documents in a group (Rev 3 — v1)

**One RFQ document per supplier; the group is metadata, not an entity.** Each sibling keeps every piece of single-document machinery for free (numbering, print blade, email, response recording, status mapping, converter). The group is `payload->rfq.group_id` (uuid stamped at fan-out creation) + a Postgres expression index on `(payload->'rfq'->>'group_id')` for the group view. No `rfq_groups` table in v1 — the group has no state of its own (its "status" derives from members; award = the member conversion event).

Alternative rejected: one RFQ document + a `supplier_responses` side table — breaks the one-partner-per-document semantics that printing, numbering, `partner_id`-based tax/currency defaults, and the conversion registry all assume, and rebuilds response/status machinery the sibling model inherits. (This was Rev 2's Phase-2 sketch "RFQ-group entity"; the sibling model supersedes it — owner pulled multi-supplier into v1, S7.)

## 1.3 Schema deltas (additive)

No new table. RFQ-specific fields live on the existing `documents.payload` jsonb (established pattern for type-specific metadata) with a typed DTO:

```
payload->'rfq' = {
  group_id:             uuid,          // shared by all siblings of one fan-out (single-supplier RFQ = group of 1) — Rev 3
  validity_date:        date|null,     // supplier quote valid until
  supplier_reference:   string|null,   // supplier's own quote number
  lead_time_days:       int|null,      // stated delivery lead time
  response_recorded_at: timestamptz|null,
  sent_at:              timestamptz|null,
  closed_reason:        string|null    // 'lost' when a sibling wins the award — Rev 3
}
```

- **Index (Rev 3):** `CREATE INDEX documents_rfq_group_idx ON documents (tenant_id, ((payload->'rfq'->>'group_id'))) WHERE type = 'purchase_rfq';` — the one migration Gap 1 now carries.

- Per-line responded price reuses `document_lines.unit_price` (already scale 3). Requested quantity reuses `quantity`.
- New enum (rule 9): add **`PurchaseQuoteRequest = 'purchase_rfq'`** to `DocumentType` (**stored value `purchase_rfq` = 12 chars, fits `documents.type` `string(20)` and `document_sequences.type` `string(20)` — see §1.8; the previous draft's `'purchase_quote_request'` was 22 chars and overflowed**) + its `getPrefix()`/`label()`/`affectsReceivable()`/`canTransitionToPaid()`/`FiscalCategory` arms. Reuse `DocumentStatus` — see §1.12 for the Sent/Responded/Converted/Closed mapping.
- DTO/types → `php artisan typescript:transform` (rule 7). Update the FE `DocumentType` TS union + any document-type length assertion/test.

## 1.4 API contract

RFQs render through the generic document scaffolding (like POs, which use `DocumentForm documentType="purchase_order"`). Endpoints (new prefix `purchase-quote-requests`, rule-12 middleware `['api','auth:sanctum',SetPermissionsTeam::class]`):

```
GET    /api/v1/purchase-quote-requests            can:purchase-quote-requests.view    (list)
GET    /api/v1/purchase-quote-requests/{id}       can:purchase-quote-requests.view
POST   /api/v1/purchase-quote-requests            can:purchase-quote-requests.create  (create draft)
PUT    /api/v1/purchase-quote-requests/{id}       can:purchase-quote-requests.update  (edit lines / record response)
POST   /api/v1/purchase-quote-requests/{id}/send  can:purchase-quote-requests.update  (mark Sent; optional email)
POST   /api/v1/purchase-quote-requests/{id}/convert-to-po
                                                  can:purchase-quote-requests.convert (→ PurchaseOrder via registry;
                                                  also auto-closes group siblings — Rev 3)
GET    /api/v1/purchase-quote-requests/groups/{groupId}
                                                  can:purchase-quote-requests.view    (comparison view: all siblings +
                                                  per-line responded prices, validity, lead time — Rev 3)
```

- **Fan-out create (Rev 3):** `POST /purchase-quote-requests` accepts `partner_ids: uuid[]` (min 1). The service generates one `group_id`, then creates one document per supplier in a single transaction (each with its own number from the `purchase_rfq` sequence). Response returns the group.
- **Award side-effect (Rev 3):** `convert-to-po` (winner must be `Responded`) converts via the registry as before, then closes every *other* non-converted sibling in the group (`Cancelled` + `closed_reason='lost'`) in the same transaction. Converting a second sibling of an already-awarded group → 422 (`RFQ_GROUP_ALREADY_AWARDED`) unless the first PO was cancelled — re-award is then allowed (the guard checks for a live converted PO, not a boolean flag).

- `convert-to-po` registers a `PurchaseQuoteRequestToPurchaseOrderConverter` (`sourceType()=PurchaseQuoteRequest`, `targetType()=PurchaseOrder`) in `DocumentConverterRegistry`, copies partner/currency/lines + responded prices via `CopiesDocumentData` **into the PO's `unit_price` (contractual price carry-over)**, sets the new PO's `source_document_id` to the RFQ, dispatches `DocumentConverted`. The PO opens as `Draft` (buyer still confirms). Precondition: RFQ must be `Responded` (see §1.12).
- FormRequest `CreatePurchaseQuoteRequestRequest` with the standard precision regex ceilings: `lines.*.quantity` `regex:/^-?\d+(\.\d{1,4})?$/`, `lines.*.unit_price` `regex:/^-?\d+(\.\d{1,3})?$/` (mirror `CreateSupplierInvoiceRequest.php:71,77`).

## 1.5 UI sketch (`apps/web/src/features/purchases`)

New route group under the gated `/purchases` tree, sibling to POs:

```
Purchases ▸ Demandes de prix
┌────────────────────────────────────────────────────────────────────┐
│ DP-2026-0007      Fournisseur: LaboDerm      Statut: Envoyée        │
│ Validité: 15/07/2026   Réf. fournisseur: [____]   Délai: [__] jours │
│ ──────────────────────────────────────────────────────────────────│
│ Produit                 Qté demandée   PU proposé (réponse)   TVA   │
│ Crème solaire SPF50      [   50   ]     [   8.200   ]          19%   │
│ Sérum vit C 30ml         [   30   ]     [  12.000   ]          19%   │
│ ──────────────────────────────────────────────────────────────────│
│ [Imprimer] [Envoyer par e-mail] [Enregistrer réponse] [→ Convertir en BC] │
└────────────────────────────────────────────────────────────────────┘
```

- Reuse `DocumentListPage documentType="purchase_rfq"` and `DocumentForm` where possible.
- **Creation (Rev 3):** the new-RFQ form has a multi-select supplier picker; submitting with N suppliers fans out N documents and routes to the group comparison view (N=1 routes straight to the single RFQ detail, exactly the single-supplier flow).
- "Enregistrer réponse" flips the price cells editable and records `response_recorded_at` (→ status `Responded`, §1.12).
- **Comparison view (Rev 3):** `/purchases/quote-requests/groups/{groupId}` — one column per supplier, one row per line; responded unit prices side by side with best-price-per-line highlighting; validity date + lead time + total per supplier; award button per responded column. List page groups siblings (chip "3 fournisseurs — 2 réponses").
- "Convertir en BC" (bon de commande) calls `convert-to-po` (from the RFQ detail or the comparison view), then routes to the new PO's detail page; siblings show as `Clôturée (non retenue)`.
- All strings via `t()` (rule 11); money via `<MoneyInput>` / `<QuantityInput>` emitting strings; design tokens (rule 18).
- Print: new blade `resources/views/documents/templates/purchase_rfq.blade.php` reusing `components/line_items.blade.php`; email via the existing Send-Email action.

## 1.6 GL / precision implications

None on GL — an RFQ never posts (§1.12 invariants). Precision: responded prices are money strings at scale 3, quantities scale 4; no float. Conversion copies strings verbatim; the PO's own confirm path does the tax/landed-cost math as today.

## 1.7 Permissions

New permission set `purchase-quote-requests.{view,create,update,convert,delete}` seeded in `RolesAndPermissionsSeeder`. FE gates under the existing `purchases` module (`RequirePermission moduleKey="purchases"`). BE uses the dedicated `can:` per route (consistent with the per-route pattern; not module-gated, matching supplier invoices).

## 1.8 Migration

1. Add `PurchaseQuoteRequest = 'purchase_rfq'` enum case + helper arms; update any exhaustive `match` on `DocumentType` (search + fix). **`FiscalCategory::fromDocumentType` needs no change — the `default => NonFiscal` arm (`FiscalCategory.php:44`) already covers it (§1.12).**
2. Register the converter in `DocumentServiceProvider`.
3. Add the blade template + i18n keys + seeder permissions.
3b. **(Rev 3)** Migration for the `documents_rfq_group_idx` expression index (§1.3) — additive, no column change.
4. **No column migration** — `purchase_rfq` (12 chars) fits `documents.type` `string(20)` (`2025_11_30_080000:18`) and `document_sequences.type` `string(20)` (`2025_11_30_080002:16`). This corrects Revision 1's incorrect "no DB migration needed because value fits" reasoning: the *value* was 22 chars and would have overflowed; the fix is the shorter stored value, not a wider column.

## 1.9 TDD test list

- **Unit:** `DocumentType::PurchaseQuoteRequest` helpers (stored value `purchase_rfq` ≤ 20 chars — explicit length assertion; prefix `DP`, non-receivable, non-payable, `FiscalCategory::NonFiscal`). `PurchaseQuoteRequestToPurchaseOrderConverter`: copies lines + responded prices into PO `unit_price`, sets `source_document_id`, target `Draft`, dispatches `DocumentConverted`; rejects wrong source type; rejects non-`Responded` source.
- **Feature (RefreshDatabase + seeder):** create RFQ draft; edit/record response persists prices + `validity_date` + status→Responded; `send` marks Sent; `convert-to-po` yields a `PurchaseOrder` with matching lines and `source_document_id`; RFQ never appears on any receivable/payable/fiscal report (§1.12); permission matrix; tenant isolation. **(Rev 3 group tests:)** fan-out with `partner_ids=[A,B,C]` creates 3 documents sharing one `group_id`, each with its own number; group endpoint returns all siblings + response state; award of B closes A and C with `closed_reason='lost'`; award on an already-awarded group → 422 `RFQ_GROUP_ALREADY_AWARDED`; re-award allowed after the winning PO is cancelled; single-supplier fan-out (N=1) behaves exactly like the plain flow.
- **FE (Vitest):** list/form render, record-response flow, convert button routes to PO, i18n keys present; one Playwright pass create→respond→convert.

## 1.10 Phasing

- **v1 (Rev 3):** multi-supplier RFQ groups — fan-out create, print/send, record responses, group comparison view, whole-RFQ award-to-PO with sibling auto-close (price carry-over). Single-supplier = group of one.
- **Phase 2:** per-line split awards (multiple POs from one group); supplier-reference dedup; supplier portal / e-mail response ingestion.

## 1.11 Non-goals

No supplier portal / self-service quoting; no auto-award; no multi-currency comparison; no RFQ→SupplierInvoice shortcut (must go through a PO); no e-procurement/tender workflow.

## 1.12 RFQ invariants (opt-outs from document machinery) — NEW in Rev 2

A first-class `documents` row inherits fiscal/payment/status/totals machinery. The RFQ must explicitly opt out (Codex M7). These invariants are enforced and tested:

| Concern | RFQ behavior | Enforcement |
|---|---|---|
| **Fiscal category** | always `NonFiscal` | `FiscalCategory::fromDocumentType` `default` arm already returns `NonFiscal` (`FiscalCategory.php:44`) — no change, but a unit test pins it so a future explicit arm can't regress it. No hash-chain sealing. |
| **Payment / balance** | never payable/receivable; no `balance_due`, no payment actions | `affectsReceivable()` false, `canTransitionToPaid()` false (new arms → `default false`, verified `DocumentType.php:64-66,89-90`). FE PO/invoice payment actions gate on `type`, so they never render for `purchase_rfq`. |
| **Stock / GL listeners** | never fires `GoodsReceived`, never posts a journal entry, never touches WAC | RFQ has no receive/confirm-to-post path; converter targets a *Draft* PO, and only the PO's own confirm/receive posts. Test: creating + converting an RFQ writes zero `journal_entries` and zero `stock_movements`. |
| **Statuses** | `Draft → Sent → Responded → (Converted \| Closed)` mapped onto existing `DocumentStatus` (`Draft`, `Confirmed`, `Cancelled`) + payload flags | `DocumentStatus` has only `Draft/Confirmed/Posted/Paid/Received/Cancelled` (verified `DocumentStatus.php:9-14`). Map: **Sent** = `Confirmed` + `payload.rfq.sent_at`; **Responded** = `Confirmed` + `payload.rfq.response_recorded_at`; **Converted** = source of a `DocumentConverted` to a PO (has a child PO with `source_document_id`); **Closed** = `Cancelled`. This avoids editing the shared status enum's exhaustive `match`es. `Posted`/`Paid`/`Received` are never reachable for an RFQ (no code path sets them). |
| **Document totals** | totals compute from lines as usual (informational only); no fiscal meaning | reuse existing total computation; totals never post anywhere. |
| **List filters** | RFQs appear only under the Demandes-de-prix list (filtered by `type='purchase_rfq'`), never in PO/invoice/fiscal lists | list endpoints already filter by `type`. |
| **Supplier mandatory?** | `partner_id` **required** on every RFQ document — each sibling in a group has exactly one supplier (Rev 3). | `documents.partner_id` is nullable since `2026_06_27_110000`, so the FormRequest enforces `required` per document; multi-supplier = N sibling documents (§1.2), never one document with N partners. (A supplier-less item-list-first draft is Phase 2.) |

---

# GAP 2 — Goods-Receipt Line Validation with Price Edit (receipt-line grain)

## 2.1 Current behavior (cited)

Receiving is already line-level with partial quantities, but **no price is captured at receipt, and there is no receipt-header/line entity — receipt reality is smeared onto PO-line state**:

- FE `ReceiveGoodsDialog.tsx` captures per line only `<QuantityInput>` (:190-197, `max = remaining`) + batch fields when `requires_batch_tracking` (:203,:211). **No price/cost field.** Payload `{ quantities: Record<lineId,string>, batches? }` (:14-17,:142-165). (The `ReceiveGoodsRequest` name at :14-17 is a **FE interface**, not a Laravel FormRequest.)
- Endpoint `POST /purchase-orders/{id}/receive` (`Document/Presentation/routes.php:235-237`, `can:purchase-orders.receive`) → `PurchaseOrderController::receive` (:533) reads raw `quantities`/`batches` (:549,:552). **No Laravel FormRequest, no precision regex ceiling** — a precision-contract outlier.
- `GoodsReceiptService::receiveGoods(Document, receivedQuantities, batchData)` (:42): partial-qty guards (:109-127); cost basis **read** from the PO line `unitCost = landed_unit_cost ?? unit_price` (`:154-157`) — never entered; stamps immutable `accrual_unit_cost = unitCost` **only if null** (`:216-221`); `quantity_received += qtyToReceive` (:224); `recordPurchase(..., landedUnitCost, variantId)` (:167-176); fires `GoodsReceived` (:181-191); flips PO to `Received` on full receipt (:235-245). **One `recordPurchase` per line — no bonus split, no actor.**
- WAC `recordPurchase(Product, Location, string $quantity, string $landedUnitCost, …)` (`WeightedAverageCostService.php:144`) blends product-grain company-wide (:236-248), writes `cost_price` + `last_purchase_cost` at `COST_SCALE=6` (:285-286). On the string contract already — no float prereq on this path.
- GR-IR accrual `createGoodsReceiptGrIrEntry` amount `= bcround(bcmul(unitCost, receivedQty, scale+2), scale)`, Dr Inventory / Cr 408, idempotent on `movementId`.
- **Supplier-invoice price match & clearing are PO-line-grain with ONE immutable basis per PO line:** `SupplierInvoiceMatcher::computePriceStatus` compares `$invoiceLine->unit_price` vs `$poLine->unit_price` (`:375-376`). Posting clears 408 at `$poLine->landed_unit_cost ?? $poLine->unit_price` and **asserts it equals the immutable `accrual_unit_cost`** (B3 guard, `SupplierInvoicePostingService.php:132-151`), then `accruedHt = Σ invoiced_qty × accrual_unit_cost` (`:153-154`). **This single-basis-per-PO-line design is exactly why multi-price partial receipts can't clean-clear — the structural driver of Codex C1.**

## 2.2 Design decision (Rev 2 — receipt-line grain)

**Introduce a first-class receipt ledger — `goods_receipts` (header) + `goods_receipt_lines` — as the accounting grain for receiving. Each receipt line captures its own received quantity, optional `received_unit_price`, derived `landed_unit_cost`, and an immutable per-receipt-line `accrual_unit_cost` snapshot. Supplier invoices match and clear against receipt lines (FIFO by default). PO-line `quantity_received` / `quantity_invoiced` become derived summary counters, not the accounting basis.**

This directly resolves Codex C1: a PO line received in two deliveries at 5.200 then 5.400 becomes **two receipt lines, each with its own basis** — both clear cleanly, no 422, no PO split. It also gives H3 (bonus), H4 (allocation), H5 (matcher-basis transition), and M10 (audit) a natural home on the receipt line.

Two variances stay explicit and cleanly separated:

| Variance | Where captured | What it drives |
|---|---|---|
| **Delivery variance** = PO price vs actually-delivered price | at **goods receipt** (`goods_receipt_lines.received_unit_price`) | the **cost** side: per-receipt-line `landed_unit_cost` → `accrual_unit_cost` → `recordPurchase` (WAC) → GR-IR accrual |
| **Billing variance** = accrued/received price vs invoiced price | at **supplier invoice** (matcher + posting) | the **commercial/AP** control + the **Purchase Price Variance** posting (§2.4), NOT an Inventory plug |

### Alternatives (briefly)

- **Keep PO-line `received_unit_price` + first-receipt lock + 422 (Revision 1)** — rejected (Codex C1): forces a warehouse failure on legitimate multi-price second deliveries; can't host bonus/allocation/audit; below competitor bar.
- **Overwrite the PO line `unit_price` at receipt** — rejected: destroys the PO↔invoice commercial control and mutates a confirmed document's contractual price.
- **Book the delivery variance as a separate expense/adjustment** — rejected for v1: the received price simply IS the receipt-line cost basis; the *billing* variance (not delivery) is what routes to a variance account (§2.4).

## 2.3 Schema deltas (additive — the receipt ledger)

```sql
CREATE TABLE goods_receipts (
  id                uuid PRIMARY KEY,
  tenant_id         uuid NOT NULL,
  company_id        uuid NOT NULL,
  purchase_order_id uuid NOT NULL,          -- documents.id (type=purchase_order); no cross-tenant FK per topology
  receipt_number    varchar(30) NOT NULL,   -- own sequence, prefix GRN, via document_sequences (type='goods_receipt')
  status            varchar(20) NOT NULL,    -- GoodsReceiptStatus enum: Draft|Posted|Cancelled
  received_at       timestamptz NOT NULL,
  received_by       uuid NULL,               -- actor (M10)
  notes             text NULL,
  payload           jsonb NULL,
  created_at        timestamptz, updated_at timestamptz,
  UNIQUE (tenant_id, receipt_number)
);

CREATE TABLE goods_receipt_lines (
  id                     uuid PRIMARY KEY,
  tenant_id              uuid NOT NULL,
  company_id             uuid NOT NULL,
  goods_receipt_id       uuid NOT NULL REFERENCES goods_receipts(id),
  po_line_id             uuid NOT NULL,           -- document_lines.id (the PO line)
  product_id             uuid NOT NULL,
  variant_id             uuid NULL,
  received_qty           decimal(15,4) NOT NULL,  -- PAID quantity
  free_qty               decimal(15,4) NOT NULL DEFAULT 0,   -- bonus (H3)
  received_unit_price    decimal(15,3) NULL,      -- NULL = received at PO/landed cost (no override)
  landed_unit_cost       decimal(19,6) NOT NULL,  -- derived: received value + freight share (+ non-rec tax) / qty
  accrual_unit_cost      decimal(15,6) NOT NULL,  -- IMMUTABLE snapshot = landed_unit_cost at post; per-receipt-line
  effective_unit_cost    decimal(19,6) NOT NULL,  -- (received_qty × landed) / (received_qty + free_qty)  (H3)
  movement_id            uuid NULL,               -- paid-units StockMovement
  free_movement_id       uuid NULL,               -- free-units StockMovement (H3)
  quantity_invoiced      decimal(15,4) NOT NULL DEFAULT 0,   -- billed against THIS receipt line
  price_override_by      uuid NULL,               -- audit (M10)
  price_override_at      timestamptz NULL,
  price_override_old_basis decimal(15,6) NULL,    -- PO/landed cost that the override replaced
  price_override_reason  varchar(255) NULL,
  created_at             timestamptz, updated_at timestamptz
);
CREATE INDEX ON goods_receipt_lines (tenant_id, po_line_id);
CREATE INDEX ON goods_receipt_lines (tenant_id, goods_receipt_id);
```

- Money scale 3 (`received_unit_price`), cost scale 6 (`landed_/accrual_/effective_unit_cost`) — matches the WAC/landed convention.
- New enums (rule 9): `GoodsReceiptStatus {Draft, Posted, Cancelled}`. DTOs → `typescript:transform`.

### 2.3.1 PO-line counters become derived aggregates

`document_lines.quantity_received` = `Σ goods_receipt_lines.received_qty + free_qty` for that `po_line_id` (posted receipts). `document_lines.quantity_invoiced` = `Σ goods_receipt_lines.quantity_invoiced`. `accrual_unit_cost` on the PO line stays as a *legacy* column but is **no longer the clearing basis** — kept written (= first receipt line's basis) only so pre-existing reports/tests that read it don't break, and so the compat fallback (§2.5) has a value. The receipt service keeps these counters in sync on every post; the matcher/posting read receipt lines.

### 2.3.2 Migration for existing receipt history — **backfill headers-from-movements**

Decision: **backfill, not clean cutover** (history is live and referenced by open supplier invoices).

- A one-time data migration synthesises `goods_receipts` + `goods_receipt_lines` from existing purchase `StockMovement`s grouped by (`po_document_id`, receipt batch = the movement set behind one `GoodsReceived`/GR-IR `movementId`). Per synthesised receipt line: `received_qty` = movement quantity; `received_unit_price` = NULL (unknown historically); `landed_unit_cost` / `accrual_unit_cost` = the PO line's existing `accrual_unit_cost` (the single basis history actually cleared at — preserves 408 reconciliation exactly); `movement_id` = the source movement; `quantity_invoiced` seeded from the PO line's `quantity_invoiced`, apportioned FIFO across the synthesised lines.
- Result: historical POs get exactly the receipt lines that reproduce today's single-basis behavior — so the new matcher path is **uniform** (no special-casing) and old open invoices post identically. A defensive compat fallback remains: if a PO has zero receipt lines (data older than backfill coverage), the matcher falls back to the PO-line aggregate basis (Codex rec #2 "aggregate fallback for old data").
- The backfill is idempotent (keyed on `movement_id`) and runs per-tenant (db-per-tenant) via a console command, not an auto-migration, so it can be batched and verified.

## 2.4 Cost-basis flow (receipt-line grain)

At receipt post, per line, when `received_unit_price` is provided (gated by permission §2.7):

1. **Derive the receipt line's landed cost** from the received price (reusing `LandedCostService::landedUnitCost`'s formula at working scale, then `bcformat` to `COST_SCALE=6`):
   `landed_unit_cost = bcdiv( received_qty × received_unit_price + allocated_freight_share + nonRecoverable_tax_share , received_qty , working )`
   Allocation share is over **received value within THIS receipt batch** (§2.4.1), not PO line_total (Codex H4).
2. **Snapshot** `accrual_unit_cost = landed_unit_cost` on the receipt line — immutable, **per receipt line** (not per PO line). Each delivery keeps its own basis.
3. **WAC:** paid movement `recordPurchase(qty = received_qty, landedUnitCost = landed_unit_cost)`; free movement `recordPurchase(free_qty, '0')` — written in the order defined in §2.6 so `last_purchase_cost` = the paid price.
4. **GR-IR:** `GoodsReceived` carries the receipt line's unit cost → `createGoodsReceiptGrIrEntry` accrues Dr Inventory / Cr 408 at `received_qty × accrual_unit_cost`. Accrual and inventory debit agree by construction, per receipt line.

### 2.4.1 Landed-cost allocation on received value (H4)

Freight/insurance attached to a receipt batch allocate across that batch's receipt lines by **received value** (`received_qty × received_unit_price`, or PO price when no override), with the existing largest-remainder absorber. This keeps allocation coherent with the valuation basis. Multi-receipt freight documents that span deliveries remain the landed-cost spec's territory; this spec covers the single-receipt-batch allocation + tests for partial receipt, free lines (allocate on paid value only), and zero-priced lines.

### 2.4.2 GL walk-through — billing variance routes to Purchase Price Variance (C2)

PO line: 100 units @ **5.000** ordered. Delivered and received at **5.200** (`received_unit_price=5.200`, one receipt line). No freight. VAT 19%. Scale 3. The Inventory plug that today absorbs the price delta (`GeneralLedgerService.php:1225-1230,1291-1311`) is **split**: non-recoverable VAT + sub-minor rounding stay on Inventory (legitimately capitalizable); the **invoice-vs-accrual price delta routes to a two-sided Purchase Price Variance account** (`PurchasePriceVarianceExpense` 60x-side / `PurchasePriceVarianceIncome` 7x-side — new `SystemAccountPurpose` cases mirroring the existing `PaymentToleranceExpense/Income` 658/758 and FX 666/766 pairs).

| Event | Entry (scale 3) | 408 | Inventory (GL) | Perpetual WAC |
|---|---|---|---|---|
| Goods receipt @ 5.200 | Dr Inventory 520.000 / Cr 408 520.000 | −520.000 | +520.000 | `recordPurchase(100, 5.200)` → 5.200 |
| receipt-line `accrual_unit_cost` = 5.200 (immutable) | — | | | |

**Case A — supplier bills 100 @ 5.200 (matches delivery).** Matcher basis = receipt-line accrual 5.200 vs invoice 5.200 → **Matched**. Posting: clear 408 at accrual `Dr 408 520.000`; `Dr VAT 98.800`; `Cr 401 618.800`. Price delta 0 → no PPV line. **408 nets 0; Inventory untouched → WAC 5.200 == GL 520.000.** ✓

**Case B — supplier bills 100 @ 5.000 (favorable; honored the original quote).** Matcher basis 5.200 vs invoice 5.000 → **PriceVariance** (advisory; postable under `Warn`, blocked under `Block`). Posting: clear 408 at the immutable accrual `Dr 408 520.000` (B3 guard passes — receipt-line basis unchanged); `Dr VAT 95.000` (19% of billed 500.000); balancing → `Cr 401 595.000` + **`Cr PurchasePriceVarianceIncome 20.000`** (favorable). Debits 615.000 = Credits 615.000. **408 nets 0; Inventory NOT touched → WAC 5.200 == GL 520.000; the 20.000 favorable variance is recognised in P&L, not smeared into stock.** ✓ (Contrast Revision 1, where this 20.000 hit the Inventory plug and pushed GL inventory to 500.000 while WAC stayed 5.200 — the exact divergence Codex C2 quantified.)

**Case C — supplier bills 100 @ 5.400 (unfavorable).** Clear `Dr 408 520.000`; `Dr VAT 102.600`; `Dr PurchasePriceVarianceExpense 20.000`; `Cr 401 642.600`. Debits 642.600 = Credits 642.600. **408 nets 0; Inventory untouched; WAC 5.200 == GL 520.000; unfavorable variance in P&L.** ✓

**Non-recoverable VAT and sub-minor rounding** still capitalize to Inventory (they are real acquisition costs / unavoidable residue) — only the *price delta* component is diverted to PPV. The plug computation in `GeneralLedgerService` is refactored to compute the price-delta component (invoiceHT − accruedHT for the cleared lines) explicitly, route it to PPV, and leave the non-rec-VAT/rounding remainder on Inventory. **WAC == GL inventory is now an invariant, tested per case.**

> Rationale for PPV over a WAC true-up: the alternative (`recordCostAdjustment` splitting the residual across on-hand + sold COGS) requires the `recordCostAdjustment(float $additionalCost)` path (`WeightedAverageCostService.php:674`), which is still on the float contract (the landed-cost spec's noted precision blocker) and only capitalizes across *on-hand* units (`companyOwnedQuantity`), leaving already-sold units mis-costed. PPV is deterministic, string-clean, expert-comptable-standard (standard-cost purchase price variance), and keeps 408 at zero and WAC==GL. **OQ3** asks the expert-comptable to confirm PPV vs a full inventory/COGS true-up.

## 2.5 Supplier-invoice matching — receipt-line basis + creation snapshot (H5)

- **Matcher basis** becomes the receipt line's `accrual_unit_cost` (the price we actually booked), consumed via the receipt lines the invoice line links to. `matchableQty` per receipt line = `received_qty + free_qty − quantity_invoiced`; invoice lines consume receipt lines **FIFO** by default within a PO line.
- **Transition (H5):** supplier-invoice lines **snapshot the basis at creation** — add `document_lines.price_match_basis decimal(15,6) NULL` + `matched_receipt_line_id uuid NULL` (or a side table if multiple receipt lines back one invoice line). Posting re-runs the matcher **against the snapshot**, so an invoice matched today cannot silently reclassify when a later receipt is entered. Drafts created before this ships are handled by a `procurement:rematch-drafts` console command + a release note for `match_enforcement=block` tenants. This closes the "posting re-runs `match()` on live state and can throw under Block" hole (`SupplierInvoicePostingService.php:85-93`, `SupplierInvoiceMatcher.php:220-228`).
- The dual percentage+max-amount threshold (`SupplierInvoiceMatcher.php:379-411`) and over-clear guard are untouched.

## 2.6 Multi-receipt & bonus-line composition (Rev 2 — no 422)

- **Multi-price partial receipts are NORMAL.** Two deliveries at different prices = two receipt lines, each with its own immutable `accrual_unit_cost`; the invoice consumes them FIFO and each clears against its own basis. **The Revision 1 `RECEIPT_PRICE_BASIS_LOCKED` 422 is removed** — the single-basis-per-PO-line constraint that required it no longer exists.
- **Bonus lines (`free_quantity`, from the bonus spec):** the receipt line stores `received_qty` (paid) + `free_qty` explicitly. Two movements: paid `recordPurchase(received_qty, landed_unit_cost)` and free `recordPurchase(free_qty, '0')`. **`last_purchase_cost` is defined = last PAID supplier unit price** (H3); to guarantee it, the **paid movement is written LAST** so it wins `WeightedAverageCostService.php:285-286`. `effective_unit_cost = (received_qty × landed_unit_cost) / (received_qty + free_qty)` is stored for reporting; blended on-hand WAC still emerges from the two movements. If the PO line is `price_entry_mode='total'`, the receipt override is entered **per unit** (the receiver reads a delivery-note unit price). Tests assert: `last_purchase_cost` = paid price (not 0, not blended), effective WAC correct, invoice matches paid price, and a supplier credit-note reversal (Phase-2 non-goal, §Cross-Cutting) does not corrupt either.

## 2.7 Permissions & audit (M10)

- New permission `goods-receipt.edit-price` (seeded), **separate** from `purchase-orders.receive`: a receiving clerk receives at PO/landed cost; only a holder may override the delivered price. Both layers: BE — the price field in the new FormRequest is `prohibited` unless the user holds `goods-receipt.edit-price`; FE — the price cell renders read-only unless `hasPermission('goods-receipt.edit-price')`. Holder confirmed by OQ4.
- **Audit is first-class, not "log is enough":** an override stamps `goods_receipt_lines.price_override_by / _at / _old_basis / _reason` and the receipt service takes an explicit actor param. The receipt line + these columns are the durable evidence (Codex M10) — the generic audit log is supplementary. Optional supervisor approval field is an additive follow-up if OQ4 wants dual control.

## 2.8 Precision implications

- **Add a Laravel `ReceiveGoodsRequest` FormRequest** (closes the existing precision outlier — the endpoint has none today; the `ReceiveGoodsRequest` name currently used is a FE interface, `ReceiveGoodsDialog.tsx:14-17`): `quantities.*` `regex:/^-?\d+(\.\d{1,4})?$/`, `free_quantities.*` same, `received_unit_prices.*` `regex:/^-?\d+(\.\d{1,3})?$/`, all `numeric`. Payload adds `received_unit_prices` + `free_quantities` (optional records).
- All costing bcmath at `workingScale()` (currency+4 / `COST_SCALE+1`), one boundary round to scale 6. Queue/console GR-IR paths pass explicit currency to scale resolution (rule 19/20 — projections run with no CompanyContext).
- FE: `<MoneyInput>` emitting strings; **no `parseFloat`/`Number`** (ESLint `no-parsefloat-on-money`) — the current PO detail page uses `parseFloat` (`PurchaseOrderDetailPage.tsx:215-217`); the new dialog must not.

## 2.9 UI sketch

`ReceiveGoodsDialog.tsx` gains one gated per-line money cell + a bonus cell:

```
Réception — BC-2026-0042 · LaboDerm                         Réception n°: GRN-2026-0031
┌────────────────────────────────────────────────────────────────────────────────┐
│ Produit         Cmdé Reste Reçu(payé) Gratuit  PU commande  PU livré*  Écart     │
│ Crème SPF50      100   100  [ 100 ]   [  0 ]     5.000       [ 5.200 ]  +4.0%    │
│ Sérum vit C       30    30  [  30 ]   [  3 ]    12.000       [12.000 ]   —       │
│ ───────────────────────────────────────────────────────────────────────────────│
│ * PU livré modifiable (droit « Modifier prix réception »). Vide = PU commande.   │
│   Une 2ᵉ livraison à un autre prix crée une nouvelle ligne de réception (aucun blocage).│
│ [Lot / Péremption ▾]                                       [Annuler] [Recevoir]  │
└────────────────────────────────────────────────────────────────────────────────┘
```

- "PU livré" read-only without `goods-receipt.edit-price`; inline "Écart" chip shows the delivery variance %. Each post creates a `goods_receipts` header (shown as GRN-number) — the receipts list now shows real receipt events, not just received POs.

## 2.10 Phasing

- **v1:** `goods_receipts`/`goods_receipt_lines` ledger + backfill; receipt service writes headers/lines; per-line `received_unit_price` + `free_qty` → per-line `landed_/accrual_unit_cost`/WAC/GR-IR; receipt-value freight allocation (single batch); matcher consumes receipt-line basis + creation-time snapshot + rematch command; **Purchase Price Variance** posting (WAC==GL invariant); `ReceiveGoodsRequest` FormRequest; `goods-receipt.edit-price` permission + audit columns; bonus composition. **No 422.**
- **Phase 2:** multi-freight-document / cross-receipt landed-cost allocation (landed-cost spec); supplier-credit-note-driven price adjustment → WAC/GL true-up (§Cross-Cutting); receipt-first PO-less receiving (S5). (Multi-PO invoice matching moved INTO v1 Wave 8 — Rev 3/S4.)

## 2.11 Non-goals

No retroactive re-costing of already-sold units at receipt (linked-cost sold/on-hand split — separate spec); no mutation of the PO contractual `unit_price`; no supplier-credit-note WAC true-up in v1 (§Cross-Cutting); the RFQ→PO price carry-over is Gap 1's concern.

**Receipt-first (PO-less receiving) = Phase 2 — an explicit owner decision (Rev 3/S5, offered v1 and schema-ready options, chose full deferral).** `goods_receipts.purchase_order_id` stays NOT NULL in v1; Phase 2 relaxes it with a trivial nullable migration, adds a standalone "Nouvelle réception" entry point (product/qty/price entered directly — the receipt ledger already carries `received_unit_price`), and pairs with the Phase-2 PO-less two-way invoice. It slots into the **Léger** preset (§ Procurement Presets).

---

# GAP 3 — Supplier-Invoice Creation + Linking UI

## 3.1 Current behavior (cited)

The **backend is complete; the UI does not exist.**

- `useCreateSupplierInvoice()` (`apps/web/src/features/purchases/supplier-invoices/api.ts:90`, `apiPost('/supplier-invoices', payload)` :93-95) has **ZERO callers** (`rg` returns only the definition). Sibling hooks `usePostSupplierInvoice` (:127), `useRematchSupplierInvoice` (:109), attachment hooks, and `useRecordSupplierPayment` (:213) ARE wired to `SupplierInvoiceDetailPage.tsx`.
- Backend: `POST /api/v1/supplier-invoices` (`Procurement/Presentation/routes.php:44`, `can:documents.update`) → `SupplierInvoiceController::store` (:144) → `CreateSupplierInvoiceService::create(array $validated, tenantId, companyId): Document` (:48) creates a `Document type=SupplierInvoice`, `source_document_id = PO` (:109), one `DocumentLine` per line with `source_line_id` → PO line (:150), persists `external_document_number = supplier_reference` (:123), then auto-matches via `SupplierInvoiceMatcher::match` (:178-182).
- `CreateSupplierInvoiceRequest` (`.../Requests/CreateSupplierInvoiceRequest.php:28`) **requires a single `source_document_id`** that must be a `PurchaseOrder` for this company/partner/currency (:55-59,:113-123), and every `lines.*.source_line_id` must belong to **that** PO (:143-162). Precision ceilings already present (:71,:77,:84).
- Matching is **PO-line-grain** today; **Rev 2 moves it to receipt-line grain (§2.5)**, so this gap's prefill/preview consume receipt lines. There is still no invoice↔document link to a receipt (receipts are the new ledger tables, not documents).
- PO detail action bar (`PurchaseOrderDetailPage.tsx:269-283`) has Confirm, Receive Goods, Record Payment, PDF/email — **no "Create Invoice"**. `DocumentActionBar.tsx:108-109` gates PO actions on `type==='purchase_order'`.

## 3.2 Design decision

**Wire three creation entry points, all converging on the existing `POST /supplier-invoices` at single-PO grain (link-at-create). Prefill and match-preview consume the Gap 2 receipt lines.**

1. **From a PO** — "Créer facture fournisseur" button on PO detail, enabled when the PO has any receipt line with `received_qty + free_qty > quantity_invoiced`. Prefills lines from the **uninvoiced receipt lines** with `invoice_qty = matchable` and `unit_price = received_unit_price ?? PO unit_price` (Gap 2 basis).
2. **From receipts** — "Facturer les réceptions" on `GoodsReceiptListPage` (now a real receipt-header list, not just received POs — Gap 2). Select receipt lines → prefill. **Cross-PO selection is blocked client-side until Wave 8** (Codex M8, interim): the picker groups receipt rows by their PO; selecting rows from a second PO disables the action with a tooltip "Multi-BC arrive avec la dernière vague." Because the create endpoint takes a single `source_document_id` until Wave 8 relaxes it (`CreateSupplierInvoiceRequest.php:113-123,143-162`), the UI never lets the user compose a payload that can only fail at submit. **After Wave 8 (Rev 3/S4): same-supplier cross-PO selection is allowed; cross-SUPPLIER selection stays blocked.**
3. **Standalone then link** — from the supplier-invoices list "Nouvelle facture" → pick supplier → load that supplier's open POs (`GET /purchase-orders?partner_id=&status=received&has_uninvoiced=1`) → pick one → prefill from its receipt lines. Feels standalone; still resolves to one PO before submit.

**Rev 3 (S4): multi-PO invoices are IN v1, as the final wave** (after the receipt-line matcher is proven on single-PO):

- `CreateSupplierInvoiceRequest` accepts `source_document_ids: uuid[]` (min 1; every id a `PurchaseOrder` of the **same company, partner, and currency** — the same-supplier guard replaces the single-PO rule). `lines.*.source_line_id` must belong to *one of* the listed POs.
- `documents.source_document_id` (single column) keeps the **first** PO for backward compatibility with every existing reader; the full list persists in `payload->supplier_invoice.source_document_ids`. Line-level linkage (`source_line_id`, and the §2.5 `matched_receipt_line_id` snapshot) is already per-line and therefore PO-count-agnostic — the matcher consumes receipt lines FIFO *per PO line* regardless of how many POs contribute lines.
- Posting is unchanged structurally: it clears each consumed receipt line at its own immutable `accrual_unit_cost`; the B3-style basis guard, over-clear invariant, and PPV routing (§2.4) all operate per line and need no multi-PO awareness beyond iterating the lines present.
- UI: the from-receipts picker and the standalone entry point allow selecting receipt lines across POs **of one supplier** (cross-supplier stays blocked); the create page shows a "BC liés: BC-…42, BC-…57" chip row. Until this wave lands, intermediate waves keep the Rev 2 single-PO client block.

A **truly PO-less supplier invoice** (service invoice, `MatchMode::TwoWay`) still requires a null-source contract + two-way matcher — **Phase 2** (with S5 receipt-first, which it pairs with naturally).

### Alternatives (briefly)

- **A join table for invoice↔PO links** — rejected: line-level `source_line_id` already carries the truth; a header-level array in `payload` + first-PO compat column is enough, and no reader today joins on a multi-PO header.
- **A new dedicated invoice-creation endpoint** — rejected: the backend service + validation already exist and are correct; this gap is FE wiring + receipt-line prefill + the final-wave request relaxation.

## 3.3 Schema deltas

**None required for v1.** Supplier's own invoice number reuses the **existing `external_document_number`** column — **not `payload`** (Codex M9): the request already accepts `supplier_reference` (`CreateSupplierInvoiceRequest.php:63`) and the service writes it to `external_document_number` (`CreateSupplierInvoiceService.php:123`), indexed by company (`2025_12_11_100001:32-36`). v1 adds a **non-blocking duplicate warning** when `(company_id, partner_id, external_document_number)` already exists (a real AP control gap). A hard uniqueness constraint is deferred (OQ5) — duplicates are sometimes legitimate (re-issued invoices), so v1 warns rather than blocks.

## 3.4 API contract

No new write endpoint — reuse `POST /supplier-invoices` via `useCreateSupplierInvoice`, then `useRematchSupplierInvoice` + `usePostSupplierInvoice`. Reads:

```
GET /api/v1/purchase-orders?partner_id={uuid}&status=received&has_uninvoiced=1   can:documents.view
    → POs with at least one receipt line where received_qty+free_qty > quantity_invoiced
GET /api/v1/purchase-orders/{id}/receipt-lines?uninvoiced=1                       can:documents.view
    → the receipt lines to prefill (qty, basis price, GRN number) — Gap 2 ledger
```

The FE derives per-line matchable qty + basis price from receipt lines. A duplicate-reference check read backs §3.3's warning.

## 3.5 UI sketch

PO detail bar gains a gated action:

```
[Confirmer] [Recevoir] [Créer facture fournisseur] [Enregistrer paiement] [PDF ▾]
                        └ activée si des lignes de réception restent à facturer
```

Creation page (`features/purchases/supplier-invoices/SupplierInvoiceCreatePage.tsx`, new):

```
Nouvelle facture fournisseur — LaboDerm            Réf. fournisseur: [ FA-8842 ] ⚠ déjà utilisée?
BC lié: BC-2026-0042 · Réceptions: GRN-...0031     Date facture: [02/07/2026]  Échéance: [01/08/2026]
┌───────────────────────────────────────────────────────────────────────────┐
│ Produit          Reçu  Déjà fact.  À facturer   PU (base réception)  TVA  Montant│
│ Crème SPF50       100      0        [ 100 ]     5.200                19%  520.000 │
│ Sérum vit C        33      0        [  33 ]    12.000                19%  396.000 │
│ ───────────────────────────────────────────────────────────────────────────────│
│ Rapprochement (aperçu): ● Qté: OK  ● Prix: OK (tolérance ±2% / 5.000)  Total: …  │
│                                                    [Enregistrer brouillon]        │
└───────────────────────────────────────────────────────────────────────────┘
```

- Live **match preview** shows Matched / PriceVariance / QuantityVariance chips **and the active tolerance** (percentage + max amount) from `procurement_policies` (competitor-table "invoice matching tolerances" — surface the existing policy, don't rebuild it).
- Save → Draft (with the §2.5 basis snapshot); Détail page (existing) offers Rematch + Post.
- Attachments: reuse existing upload hooks so the scanned invoice attaches at creation — the seam the OCR project will later prefill from.
- Strings via `t()`; money via `<MoneyInput>`; **no `parseFloat`**.

## 3.6 Forward-looking: flexible document chains (sales symmetry — principle only)

The customer's underlying ask is **entry-point flexibility**: on the purchase side you can now enter PO-then-receive-then-invoice, or invoice received goods directly. The sales side should mirror this — **invoice-first, optionally generating a delivery note** (and vice versa). Design principle (not a spec here): documents are nodes linked by `source_document_id`; the registry already has `SalesOrder→DeliveryNote`, `DeliveryNote→Invoice`; completing invoice-first needs an additive `InvoiceToDeliveryNoteConverter`; the counters (`quantity_delivered`, `quantity_invoiced`) — not creation order — are the truth. A full spec is deferred; this records the direction so Gap 3's grain choices stay compatible.

## 3.7 GL / precision implications

None new — creation writes a Draft (no GL); posting uses the receipt-line clearing path (§2.4, PPV). Payloads already string with regex ceilings. The FE must use decimal helpers, not `parseFloat`.

## 3.8 Permissions

Reuse BE `can:documents.update` (unchanged). FE gates the button + page under the `purchases` module + `purchases.create`. The BE-`documents.*` vs FE-`purchases.*` asymmetry is pre-existing (§0) and flagged for cleanup, not fixed here.

## 3.9 Migration

No DB migration for v1 (the §2.5 `price_match_basis`/`matched_receipt_line_id` columns land with Gap 2). Work: wire `useCreateSupplierInvoice`; add the PO-detail button (extend `DocumentActionBar` with `onCreateInvoice` for `type==='purchase_order'`, gated on uninvoiced receipt lines); build `SupplierInvoiceCreatePage`; three entry points (with the single-PO guard) + routes + i18n; duplicate-reference warning.

## 3.10 TDD test list

- **FE (Vitest):** `useCreateSupplierInvoice` invoked with a correct single-PO payload (string money/qty); PO-detail button enabled only when a receipt line is uninvoiced; each entry point prefills correct matchable qty + basis price from receipt lines; **from-receipts cross-PO selection is blocked with a message**; match-preview chips + tolerance render; duplicate-reference warning fires on a repeat `(partner, ref)`; no `parseFloat`; i18n keys present.
- **Feature (backend):** the exact payload the UI sends passes `CreateSupplierInvoiceRequest` and yields `source_document_id = PO`, correct `source_line_id`s, and an auto `match_status`; over-matchable qty → `QuantityVariance`; price beyond tolerance → `PriceVariance`; the basis reflects the receipt-line `accrual_unit_cost` (Gap 2 dependency); posting clears receipt lines FIFO and 408 nets 0 with any variance in PPV.
- **Playwright:** one pass — received PO → Create Invoice → preview Matched → Post → 408 clears, WAC==GL asserted.

## 3.11 Phasing

- **v1 (waves before the last):** three entry points at single-PO grain, receipt-line prefill/match, match preview + tolerance display, attachments, `external_document_number` + duplicate warning.
- **v1 FINAL wave (Rev 3/S4):** multi-PO invoices — `source_document_ids[]` request relaxation, same-supplier guard, cross-PO receipt-line selection UI, payload source list + first-PO compat column.
- **Phase 2:** true PO-less/service invoices (pairs with S5 receipt-first); OCR prefill; hard duplicate-reference uniqueness.

## 3.12 Non-goals

No PO-less invoice in v1; no OCR in v1; no supplier-statement reconciliation; no change to the matcher/posting engines beyond the Gap 2 receipt-line basis + PPV (the multi-PO wave iterates lines, it does not alter clearing semantics).

---

# Procurement Presets (NEW in Rev 3 — S8)

**A `ProcurementPreset` enum — `Complet | Standard | Leger` — as named bundles over the existing `procurement_policies` fields. The fields stay the source of truth; a preset is just which values get written, plus a record of which bundle was applied.**

| Preset | Chain | `match_mode` | `match_enforcement` | Notes |
|---|---|---|---|---|
| **Complet** | RFQ → PO → receipt → invoice | `three_way` | `block` | strictest control; RFQ encouraged (never *required* by code in v1 — a PO can still be created directly; "RFQ required" hard-gating is a Phase-2 policy flag if demanded) |
| **Standard** (default) | PO → receipt → invoice, RFQ optional | `three_way` | `warn` | today's seeded behavior, now nameable |
| **Léger** | PO → receipt → invoice, lightest control | `two_way` | `warn` | Phase-2 receipt-first (S5) lands here: direct no-PO receipts become a Léger capability |

- **Schema:** add `procurement_policies.preset varchar(20) NULL` (records the applied bundle; NULL = custom/hand-tuned fields). New enum `ProcurementPreset` (rule 9) with a `values(): array` mapping preset → policy field values.
- **Seeding:** `RolesAndPermissionsSeeder`-style preset application in the procurement policy seeder — per tenant/vertical (demo seeders: parapharmacy = `Standard`); `DemoPharmacySeeder` asserts the preset it expects.
- **Settings:** `PUT /api/v1/procurement-policies` (existing policy update path or a thin new endpoint) accepts either `preset` (applies the bundle, stamps the column) or raw fields (clears `preset` to NULL). Company-settings UI: a three-card preset picker + an "avancé" section exposing the raw fields.
- **Tests:** applying each preset writes exactly the mapped fields; raw-field edit nulls the preset; seeder idempotency; the §2.5 matcher honors `two_way` (no receipt-line requirement for matching under Léger — two-way = invoice vs PO price only, receipt lines still *created* and *cleared* for GR-IR correctness).

---

## Cross-Cutting Notes

- **FE/BE gating asymmetry** (§0): the FE asserts a `purchases` module + `purchases.*` the BE does not enforce (BE uses `documents.*`). All three gaps reuse this as-is. A cleanup — seed a real `Purchases`/`Procurement` `ModuleName` case (drift-guarded by `tests/Unit/Enums/ModuleNameTest.php`) and gate BE routes, or align FE onto `documents.*` — is out of scope but should be logged.
- **New account purposes:** `PurchasePriceVarianceExpense` + `PurchasePriceVarianceIncome` (§2.4) seeded in the chart-of-accounts seeder, mirroring the existing 658/758 tolerance and 666/766 FX pairs. Country COA mappings (France PCG / Tunisia) confirmed with the expert-comptable (OQ3).
- **Supplier credit notes (DEFERRED, explicit non-goal — Codex CG):** `SupplierCreditNoteReason::PriceAdjustment`/`GoodsReturn` exist (`SupplierCreditNoteReason.php:24-25`) but are not wired to received-price WAC/GL true-up. With PPV recognising the price residual at invoice posting, a credit-note-driven retro adjustment is a *separate downstream-AP* concern, not a completeness blocker. Deferred to Phase 2 with this justification; §2.6 tests still assert a future credit-note reversal won't corrupt `last_purchase_cost`.
- **No float prerequisite** on the receipt/WAC path: `recordPurchase` is string-contract (`WeightedAverageCostService.php:144`). Choosing PPV over a `recordCostAdjustment` true-up specifically *avoids* dragging in that method's `float $additionalCost` blocker (`:674`).
- **Gap 2 → Gap 3 dependency:** the receipt ledger + matcher basis (§2.5) and receipt-line prefill (§3.4) mean **Gap 2 must land before Gap 3** (Gap 3 has no meaningful prefill without receipt lines). This is a firmer ordering than Revision 1's "prefers, but can ship on `unit_price`."

## Open Questions — RESOLVED by owner review (Rev 3, 2026-07-03)

1. **(Gap 1)** ~~single- vs multi-supplier~~ → **Multi-supplier RFQ is a v1 requirement** (owner: very practical, also needed for automotive) — S7, sibling-group design (§1.2). Prefix: `DP` (default accepted; trivial to change before Wave 1 ships).
2. **(Gap 2)** Match basis = **receipt-line accrual price** — ACCEPTED as recommended.
3. **(Gap 2)** **PPV posting ACCEPTED** as the mechanism (WAC==GL invariant). Still owed: expert-comptable countersign of the France PCG / Tunisia **account numbers** for the PPV expense/income pair (numbers only — does not block implementation; seeder ships with 601-side/7-side placeholders mirroring 658/758).
4. **(Gap 2)** `goods-receipt.edit-price` = **separate permission** — ACCEPTED. Default seeding: owner/manager roles, not the receiving-clerk role; no dual-control column in v1 (additive later if wanted).
5. **(Gap 3)** Duplicate supplier reference = **non-blocking warning** — ACCEPTED. Hard uniqueness deferred.

---

## Suggested Implementation-Session Plan (Codex CLI pipeline waves)

Each wave is one TDD-able unit (tests first, red→green→refactor), scoped to explicit paths, with an inline `claude -p` / Codex review gate before merge, and `./scripts/preflight.sh` at the end. Run scoped PHPUnit by path — never the full suite.

- **Wave 1 — Gap 1 backend (RFQ core + groups).** `PurchaseQuoteRequest = 'purchase_rfq'` enum + helper arms (+ length assertion) + exhaustive-`match` fixes; RFQ invariants (§1.12) incl. NonFiscal test + zero-GL/zero-stock test; **fan-out create (`partner_ids[]` → N siblings, one `group_id`, single txn) + group read endpoint + group index migration (Rev 3)**; `PurchaseQuoteRequestToPurchaseOrderConverter` + registration + **award sibling auto-close + `RFQ_GROUP_ALREADY_AWARDED` guard**; `CreatePurchaseQuoteRequestRequest`; controller + routes + seeder permissions; blade. Exit: fan-out→respond→compare(API)→award green; siblings close; PO carries `source_document_id` + carried prices; RFQ posts nothing.
- **Wave 2 — Gap 1 frontend (RFQ UI + comparison).** List/form/detail with multi-select supplier picker; record-response + convert + print/email; **group comparison view with best-price highlighting + award action (Rev 3)**; routes under `purchases` gating; i18n. Exit: Playwright fan-out(2 suppliers)→respond both→compare→award→sibling shows Clôturée.
- **Wave 3 — Gap 2a: receipt ledger foundation.** `goods_receipts` + `goods_receipt_lines` migrations + enums + DTOs; `GoodsReceiptStatus`; `GoodsReceiptService` writes header+lines and keeps PO-line counters as derived aggregates (§2.3.1); **backfill console command** (headers-from-movements, idempotent, per-tenant) + compat fallback. Exit: receiving writes a GRN header/lines; PO counters reconcile; backfill reproduces historical single-basis behavior; existing GR-IR/posting tests stay green.
- **Wave 4 — Gap 2b: received-price cost flow + PPV.** `ReceiveGoodsRequest` Laravel FormRequest (closes precision outlier); per-line `received_unit_price` + `free_qty` → per-receipt-line `landed_/accrual_/effective_unit_cost`; receipt-value freight allocation; two movements with paid-last ordering; `goods-receipt.edit-price` permission + audit columns; **new `PurchasePriceVariance` account purposes + GL plug split** (price delta → PPV, non-rec VAT/rounding → Inventory). Exit: GL walk-through Cases A/B/C green (408 nets 0; **WAC==GL invariant asserted**); B3 guard green; bonus `last_purchase_cost`=paid-price test.
- **Wave 5 — Gap 2c: matcher receipt-line basis + transition.** Matcher consumes receipt-line `accrual_unit_cost`, FIFO consumption, `matchableQty` per receipt line; `price_match_basis`/`matched_receipt_line_id` snapshot at creation; posting re-runs matcher against snapshot; `procurement:rematch-drafts` command + release note. Exit: no-reclassify test for a pre-existing draft after a new receipt; multi-price two-receipt clearing test (both bases, 408 nets 0) — **the case Rev 1's 422 forbade**.
- **Wave 6 — Gap 2 frontend (receive dialog).** Gated per-line price + bonus cells + variance chip; GRN number surfaced; string payloads; Vitest + one Playwright receive-at-variance / two-price-two-receipt pass.
- **Wave 7 — Gap 3 (supplier-invoice creation UI).** Wire `useCreateSupplierInvoice`; PO-detail "Create Invoice" button; three entry points (from-PO, from-receipts with **single-PO client guard**, standalone-via-supplier); receipt-line prefill; match preview + tolerance display; `external_document_number` + duplicate warning; attachments; routes + gating + i18n. Exit: Playwright received-PO → create → Matched → post → 408 clears, WAC==GL.
- **Wave 8 — multi-PO supplier invoices (Rev 3/S4 — v1 FINAL wave).** Relax `CreateSupplierInvoiceRequest` to `source_document_ids[]` + same-supplier/currency/company guard; payload source list + first-PO compat column; cross-PO receipt-line selection UI (drop the single-PO client block, keep cross-supplier block); "BC liés" chips. Exit: one invoice over receipt lines from two POs of one supplier — matches, posts, clears each receipt line at its own basis, 408 nets 0, cross-supplier rejected 422.
- **Wave 9 — Procurement presets (Rev 3/S8).** `ProcurementPreset` enum + `procurement_policies.preset` column; seeder preset application per tenant/vertical; settings endpoint preset/raw-fields semantics; preset picker UI card in company settings; two-way-mode matcher test. Small; can run parallel to Waves 6–8 once Wave 5's matcher lands.
- **Wave 10 — forward-looking (optional, unchanged from Rev 2 Wave 8).** `InvoiceToDeliveryNoteConverter` stub + a short flexible-doc-chain follow-up spec. No behavioral shipping.

**Dependency order (Rev 3):** Waves 1–2 (Gap 1, now incl. groups) are independent of Gap 2/3. **Gap 2 is sequential (3 → 4 → 5 → 6) and MUST precede Gap 3 (Wave 7)**; **Wave 8 (multi-PO) strictly follows Wave 7**; Wave 9 (presets) needs only Wave 5's matcher for its two-way test. Effort: Gap 2 remains the critical path; Rev 3 adds ~1.5 waves of scope (groups + multi-PO + presets).

**Integration target (Rev 3, owner):** all waves merge to the **`post-demo`** integration branch (fast-forwarded onto current `dev` tip first); promotion to demo/`origin/dev` only after the owner's stability call.
