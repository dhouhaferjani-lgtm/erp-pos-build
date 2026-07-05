# Spec — P2P flexible entry points, document lifecycle & entry/exit notes

**Date:** 2026-07-05 · **Status:** Draft for owner review (Rev 1)
**Branch:** `feat/p2p-entry-points` (worktree `apps/erp.p2p-flow`, base `b3e81a13e` = procurement v1 complete)
**Provenance:** owner directive 2026-07-05; design understanding Rev 2
(`docs/sessions/DESIGN-UNDERSTANDING-p2p-entry-points.md`) approved after dual
adversarial review (Codex + Claude, both reports in `docs/superpowers/reviews/
2026-07-05-p2p-entry-points-understanding-{codex,claude}-review.md`); research audit
`docs/superpowers/audits/2026-07-05-p2p-entry-points-research.md`.
**Relationship to procurement v1:** this spec IS the "Phase 2 receipt-first / PO-less"
campaign recorded in `docs/superpowers/specs/2026-07-03-procurement-completeness-design.md`
(S5, §Phase-2 backlog), superseding those backlog entries with owner-decision provenance.
**Follow-up spec (gated on this one):** Scan-to-Document capture (OCR of supplier BLs +
invoices) — its committers target the flows defined here.

---

## 1. Business requirement (owner, 2026-07-05)

Suppliers sometimes send invoices; most of the time they send delivery notes (bons de
livraison, BL) that are invoiced in a consolidated month-end supplier invoice. The system
must support the COMPLETE procure-to-pay chain but let users START from any of: RFQ,
purchase order, goods receipt/BL, or supplier invoice. The UI hides the mechanics:

- Start from an invoice → ask "delivered?" → if yes, a goods receipt is created and
  confirmed implicitly.
- Start from a receipt (BL) → once confirmed, it becomes invoicable (month-end).
- Start from a PO or RFQ → the full explicit chain (shipped in procurement v1).

Configurable per country and per tenant/company. **Invariant: the goods receipt (stock)
and the invoice (finance) are ALWAYS part of the chain, explicit or implicit.** First
target market reality: Tunisia parapharmacy, BL-heavy suppliers (pilot customer name
withheld pending owner confirmation — no customer naming in seeders/docs).

### 1.1 Research grounding (see audit doc for sources/confidence)
- SAP B1: invoice-first with implicit receipt is mainstream; its phantom-posting model
  needs a manual double-count guard — we materialize explicit rows instead.
- SAP S/4HANA: auto-PO-at-goods-receipt is the canonical "receive without PO" answer —
  the PO is materialized implicitly as the pricing anchor. We adopt this.
- D365: hierarchical per-legal-entity matching policy, stricter-only overrides, matching
  failures as approval-gated exceptions. We adopt the principles.
- Odoo: refuses implicit creation; its receipt→invoice mapping is aggregate-only — our
  receipt-line grain is ahead; keep it.
- France: facture récapitulative legal (same client, same calendar month, issued by
  month-end); numbered BLs mandatory when invoicing deferred; each delivery = own invoice
  line with execution date. Tunisia: BL "tient lieu de facture" in transport; all invoice
  formalities extend to BLs; consolidation is supplier-side practice; 2026 El Fatoora
  e-invoicing excludes BLs (emission side, out of scope). Expert-comptable to countersign.

---

## 2. Invariants & principles

1. **Two invariant artifacts per chain**, explicit rows always: `goods_receipts(+lines)`
   for stock, `SupplierInvoice` Document for finance. No phantom stock/GL postings.
2. **408 accrual honesty:** new entry-point flows post GR-IR **in-transaction,
   fail-closed** (direct `GeneralLedgerService` call, rethrow). The legacy PO-first
   listener path (`PostGrIrOnGoodsReceipt`, swallowing) is unchanged in v1; a new
   `procurement:grir-drift` command reports receipts missing their GR-IR entry.
   Unification to fail-closed everywhere = Phase 2 (ops decision).
3. **Orchestration over umbrella transactions:** multi-artifact flows run as sequential
   transactions with compensation, preserving `WeightedAverageCostService` retry
   semantics and keeping lock hold times short.
4. **Fail-closed policy:** entry-point capabilities default OFF at the resolver level;
   services re-assert policy server-side; both-layer gating (rule 12).
5. **Corrections, not mutation:** posted artifacts are corrected by reversing/corrective
   documents, never edited.
6. **Precision contract (rule 19)** everywhere: BL/billed prices arrive as strings with
   regex ceilings; `bcformatStrict` at rest; no floats.
7. **Wave 9 preset semantics:** presets map chain config only and preserve hand-tuned
   values; extended fields follow the same preservation-test pattern.

---

## 3. Policy model

### 3.1 Schema — `procurement_policies` (tenant migration)
```
allow_receipt_first            boolean NOT NULL DEFAULT false
allow_invoice_first            boolean NOT NULL DEFAULT false
invoice_first_requires_approval boolean NOT NULL DEFAULT true
```
Backfill: existing rows get the defaults explicitly (no NULL window).

### 3.2 Resolution — fail-closed
`ProcurementPolicyResolver` currently synthesizes a vertical-based `Standard` policy when
no row exists (fail-open). Amendment: the synthesized default carries
`allow_receipt_first=false, allow_invoice_first=false, invoice_first_requires_approval=true`
regardless of vertical/preset. `StandaloneReceiptService` and the invoice-first
orchestrator assert the toggle at entry (403/422 domain error), independent of route/UI
gating.

### 3.3 Presets (extends Wave 9 `ProcurementPreset`)
| Preset | allow_receipt_first | allow_invoice_first |
|---|---|---|
| Complet | false | false |
| Standard | true | false |
| Léger | true | true |
`invoice_first_requires_approval` is NOT preset-mapped (control knob, stays hand-set,
default true). Applying a preset sets the two toggles; the Wave 9 preservation test is
extended to prove hand-tuned tolerance fields survive. Country appears ONLY as a
suggested preset in the settings/onboarding UI (Tunisia → Léger suggestion chip); no
silent country-based behavior.

### 3.4 Permissions & routes
New permissions (seeder + per-tenant sync note): `goods-receipt.create-standalone`,
`supplier-invoices.create-pending`, `supplier-invoices.link-receipts`,
`supplier-invoices.approve-invoice-first` (used when `invoice_first_requires_approval`).
Existing `goods-receipt.edit-price` unchanged. Routes: standard
`['api','auth:sanctum',SetPermissionsTeam::class]` stack in Procurement/Inventory
routes.php; deploy checklist inherits per-tenant migrate → seeder →
`permission:cache-reset` (tenant-blind cache gotcha).

### 3.5 Settings UI
Wave 9 three-card preset picker gains the two toggles (shown with the preset's implied
values, editable) + approval toggle. i18n in existing `purchases` namespace (fr/en/ar).

---

## 4. PO integrity guard (independent correctness wave)

**Problem (review-verified):** `DocumentStatus::Confirmed` is editable;
`PurchaseOrderController::update` delete-replaces lines; `goods_receipt_lines.po_line_id`
is a bare UUID. A partially-received PO's lines can be deleted → orphaned receipt rows,
broken matcher/posting.

**v1 fix:** server-side guard in the PO update path: if ANY `goods_receipt_lines` row
references one of the PO's lines, line mutation (delete/replace/qty-or-price change on
received lines) is rejected with a domain error; safe header fields (notes, expected
date…) stay editable. Covering tests: receipted-PO line-delete → 422; unreceipted PO
still fully editable.

**Phase 2:** real FK or `NOT VALID` constraint + per-tenant validation pass.

---

## 5. Goods-receipt lifecycle (draft receipts)

### 5.1 States
`GoodsReceiptStatus`: `Draft → Posted`, `Draft → (deleted)`, `Posted → Cancelled`
(Phase 2, via corrective receipt). Dead enum cases become reachable; no enum lies.

### 5.2 Draft semantics
- Header + lines persisted; `movement_id`/`free_movement_id` NULL; **no stock, no WAC,
  no GL, no PO-counter updates, no `GoodsReceived` event**.
- Editable + deletable; `received_unit_prices` overrides still gated by
  `goods-receipt.edit-price` at post time (audit columns stamped when applied).
- **GRN at post:** `receipt_number` becomes nullable; unique index rewritten
  `UNIQUE (company_id, receipt_number) WHERE receipt_number IS NOT NULL`; drafts display
  `BROUILLON-<short-id>`. No sequence burn on abandoned drafts.
- **Matcher isolation (BLOCKER fix):** `ReceiptLineConsumptionPlanner`,
  `SupplierInvoiceMatcher` aggregates, `SupplierInvoicePostingService` FIFO consumption,
  and PO-counter derivations all gain explicit `goods_receipts.status = 'posted'`
  filtering (join or subquery). Pinned by red/green test: a Draft receipt creates NO
  matchable window and never satisfies posting.

### 5.3 post()
`GoodsReceiptLifecycleService::post(GoodsReceipt $draft, actor)`: one transaction —
cost locks (sorted, existing `ProductCostLock`), GRN assignment, stock movements +
WAC `recordPurchase`, line `movement_id` backfill, PO counters + status, GR-IR per §2.2
(fail-closed when the receipt's PO is auto-generated; listener path otherwise), audit.
Semantically identical to today's `receiveGoods` effects — `receiveGoods` is refactored
to create-Draft + post() internally so PO-first receiving behavior is unchanged
(single-call, same transaction, regression-pinned by existing Wave 3-6 tests).

### 5.4 UI
Receipt surfaces get "Enregistrer brouillon" and "Enregistrer et valider". Draft list
badge on the goods-receipt workbench. Invoice-first auto-receipts are created directly
Posted (never draft).

---

## 6. Receipt-first flow (BL arrives, no PO)

### 6.1 Surface
"Nouvelle réception" (web; entry from the goods-receipt workbench + sidebar):
supplier picker → lines (product/variant picker, qty, free qty, BL unit price string) →
BL identity: `external_reference` (BL number), `external_date` → location → save draft or
post.

### 6.2 Schema
`goods_receipts` + nullable `external_reference varchar(100)`, `external_date date`;
model fillable/casts updated; surfaced in Wave 7 receipt-line picker columns and the SI
match preview. No backfill (nullable, new-flow-only). Non-unique; duplicate
`(partner, external_reference)` produces a WARNING (soft — suppliers reuse BL numbers
across years).

### 6.3 Orchestration — `StandaloneReceiptService` (Procurement/Application)
Input DTO: supplier id, company, location, lines[{product_id, variant_id?, qty, free_qty,
unit_price(string), batch?}], external_reference?, external_date?, actor (explicit — no
ambient auth), post_immediately flag.
1. **t1:** create PO Document (`DocumentType::PurchaseOrder`) with lines mirroring the
   input at BL prices; `payload->auto_generated = {source:'standalone_receipt',
   actor, created_at}`; confirm via `PurchaseOrderService::confirm` with explicit actor.
2. **t2:** create receipt (Draft or direct post per flag) against the auto-PO via §5
   machinery with `received_unit_prices` = BL prices; fail-closed GR-IR (§2.2).
3. **Compensation:** t2 terminal failure → orchestrator cancels the auto-PO (inert
   otherwise); idempotency key on the request to prevent double-submit duplicates.
Module boundary: Procurement orchestrates; calls Inventory's public `GoodsReceiptService`
(existing edge); deptrac ratchet must not regress — introduce a Shared/Contracts receipt
interface only if the ratchet flags it.

### 6.4 Auto-PO consumer matrix (review-driven)
| Surface | Behavior |
|---|---|
| `GET /purchase-orders` | EXCLUDES auto-generated by default; `include_auto_generated=1` opt-in |
| FE PO list | toggle "Afficher les BC auto-générés"; badge "Auto" |
| PO detail / action bar / print | visible, badged; receive/SI/payment actions normal |
| Goods-receipt workbench, SI creation pickers | INCLUDED (receipt-driven surfaces) |
| AgedPayables | INCLUDED (receipt-backed auto-PO = real payable) |
| RFQ award guard | unaffected (auto-POs never RFQ-sourced; test pins) |
| Document chain readers | included (chain integrity) |
`DocumentData` serializer gains `is_auto_generated: bool` (payload-derived — the payload
itself persists at rest; serializer currently drops it).

### 6.5 Month-end
Accumulated posted receipts (auto-PO or normal) are invoiced through the SHIPPED Wave 7
"Facturer les réceptions" (receipt-line selection, BL number/date now visible per §6.2)
and Wave 8 multi-PO SI (same supplier/currency/company). No new consolidation engine.
France note: line-per-delivery + execution date satisfied by receipt-line grain +
`external_date`.

---

## 7. Invoice-first flow

### 7.1 Delivery-status fork (Wave 7 SI-creation surface)
When composing a standalone SI (no receipt lines picked), the form asks:
**"Les marchandises ont-elles été livrées ?"**
- **Oui (already delivered)** → §7.2. — **Pas encore (in transit / not delivered)** → §7.3.
If receipt lines WERE picked, the existing Wave 7/8 flow applies untouched.

### 7.2 Delivered → implicit receipt
Orchestrated (same saga model as §6.3): auto-PO (`{source:'invoice_first'}`) at BILLED
prices → receipt posted directly at billed prices (`accrual = billed` → **zero PPV by
construction**; expert-comptable sign-off item) → SI created with
`source_document_ids=[autoPO]`, lines linked `source_line_id` → normal match preview →
postable (subject to §7.4 approval). One failure mid-saga leaves earlier artifacts
consistent (receipt without SI is real goods; user retries SI creation from the receipt).

### 7.3 Not delivered → parked pending SI
- `CreateSupplierInvoiceRequest` relaxed ONLY when policy `allow_invoice_first` AND
  request `pending_receipt=true`: `source_document_ids` may be absent; lines may omit
  `source_line_id`; **document is created Draft** with
  `payload->supplier_invoice->pending_receipt=true`.
- Posting stays blocked while pending (matcher's null-source hard exception is now
  load-bearing — pinned by test).
- **Linking (user-driven v1):** Draft SI action "Associer des réceptions" opens the Wave 7
  receipt-line selector filtered to the supplier; selection writes
  `source_document_ids` + per-line `source_line_id`, clears `pending_receipt`; then
  match preview / `procurement:rematch-drafts` refresh applies (the command re-prices,
  it does NOT link — verified). Auto-suggest by BL number = Scan-to-Document spec.
- Pending SIs appear in the SI list with an "En attente de réception" badge + filter.

### 7.4 Controls
- Duplicate `external_document_number` warning (shipped, unchanged).
- `invoice_first_requires_approval` (default ON): posting an SI whose chain includes an
  `invoice_first` auto-PO requires `supplier-invoices.approve-invoice-first` (second
  person or elevated role) — approval gates POSTING, not creation.
- Provenance audit: auto-PO payload + receipt `external_*` + SI linkage give the full
  who/when trail.

---

## 8. Entry/exit notes (bons d'entrée/sortie)

Generic concept = **read-model projection over `stock_movements`** grouped by polymorphic
reference (GoodsReceipt, DeliveryNote Document, StockTransfer, adjustment batch):
direction (`MovementType.isInbound()`), source label, location, lines, actor, timestamp.
- **v1:** `GET /entry-exit-notes` read endpoint (paginated, filters: direction, location,
  date range, source type) + simple list page (Inventaire section) + **GRN print template**
  `goods_receipt.blade.php` (bon de réception: GRN, supplier, BL ref/date, lines,
  location, signatures) wired into the existing document-PDF pipeline.
- **Deferred:** transfer-note print, mobile exit/picking surface, scannable note UX,
  POS-batch grouping. Mobile receiving already exists (`erp-mobile` receiving surfaces)
  and gains nothing here yet.
No new Document types (owner-ratified: "GoodsReceipt is not a document type").

---

## 9. Document lifecycle amendments

1. **Canonical axes declared:** lifecycle (Draft/Confirmed/Posted/Cancelled) vs computed
   payment/fulfillment axes vs fiscal seal. Stored `Paid`/`Received` cases frozen (no new
   writers; existing writers documented); normalization = Phase 2 migration.
2. **revert() (Confirmed→Draft), v1 scope only:**
   - Quote: always. — SalesOrder: releases auto-created reservations (verified: confirm
     reserves when `autoReserveOnSalesOrder`). — PurchaseOrder: only if zero receipt
     lines AND not RFQ-awarded AND not referenced by any SI (`source_document_id` or
     payload `source_document_ids`).
   - DeliveryNote/ReturnNote/fiscal docs/RFQ: NO revert (corrections via return/credit
     note/reopen flows).
   - Implemented centrally in `DocumentPostingService::revert()` with per-type guard
     table; controller endpoint + FE action gated `documents.update`.
3. **cancel() unpaid-guard:** Posted→Cancelled requires zero allocated payments (per the
   canonical lifecycle doc); the 3 scattered cancel implementations converge on
   `DocumentPostingService::cancel()`.
4. Enum truth: `GoodsReceiptStatus::Draft` reachable (§5); `Cancelled` reachable Phase 2;
   any case still unreachable at the end of v1 is removed.

---

## 10. GL treatment (summary; no new accounts)

| Event | Entry |
|---|---|
| Receipt post (any flow) | Dr Inventory / Cr 408 at accrual basis (BL price for receipt-first; billed price for invoice-first) — fail-closed for auto-PO flows, listener for legacy |
| SI post | existing Wave 5 receipt-line FIFO clearing: Dr 408 (accrued) + PPV split on billed-vs-accrued delta / Cr 401 etc.; WAC==GL invariant untouched |
| Invoice-first delivered | PPV = 0 by construction (accrual == billed) |
`procurement:grir-drift` (new artisan command): per-tenant report of posted receipts
lacking a GR-IR journal entry (source-type-scoped lookup — journal_entries
(source_type, source_id) is not globally unique).

---

## 11. API surface (new/changed)

```
POST   /api/v1/goods-receipts/standalone          (policy allow_receipt_first; perm goods-receipt.create-standalone)
POST   /api/v1/goods-receipts                     (draft create — PO-backed draft path)
POST   /api/v1/goods-receipts/{id}/post
PATCH  /api/v1/goods-receipts/{id}                (draft only)
DELETE /api/v1/goods-receipts/{id}                (draft only)
GET    /api/v1/entry-exit-notes
POST   /api/v1/supplier-invoices                  (extended: pending_receipt + invoice-first fork)
POST   /api/v1/supplier-invoices/{id}/link-receipts
POST   /api/v1/documents/{id}/revert
GET/PUT /api/v1/procurement-policies              (extended payload)
GET    /api/v1/purchase-orders?include_auto_generated=1
```
All tenant-scoped; FE queries via `tenantScopedKey`; types regenerated from DTOs
(`typescript:transform`).

---

## 12. Testing strategy (laptop = by-path only)

- TDD per task; each plan task names its failing test first.
- Feature tests per entry point with GL assertions (journal legs, 408 net-zero after SI
  post, PPV zero on invoice-first) and stock assertions (WAC, movement rows).
- Matcher isolation red/green: Draft receipt ⇒ no matchable window (BLOCKER pin).
- receiveGoods refactor pinned by the existing Wave 3-6 suites (paths:
  `tests/Feature/Procurement`, `tests/Feature/Inventory`, `tests/Feature/Accounting`).
- Policy fail-closed: missing row / missing column (pre-migration simulation) ⇒ 403.
- PO integrity guard: receipted-PO line mutation ⇒ 422.
- revert(): per-type guard matrix incl. SO reservation release; RFQ-awarded PO blocked.
- Saga compensation: forced t2 failure ⇒ auto-PO cancelled; idempotent resubmit.
- Serializer: `is_auto_generated` exposure; PO list default exclusion.
- Precision: BL/billed price regex ceilings; `bcformatStrict` at rest (existing PHPStan
  guards apply). SQLite caveat: FOR-UPDATE/partial-index behaviors verified on pgsql
  where the suite allows; note in tests.
- Reviewer gates (factory Stage 4): **inventory-costing-reviewer + treasury-reviewer**
  mandatory (receipts/WAC/408/PPV); **tenancy-authz-reviewer** for the policy/permission
  wave; fiscal-pos-reviewer not implicated (no POS/fiscal-chain surface).

---

## 13. Delivery phasing (waves — each Codex-built, TDD, reviewer-gated)

| Wave | Content | Depends on |
|---|---|---|
| W1 | PO integrity guard (§4) + `procurement:grir-drift` (§10) | — |
| W2 | Policy columns + fail-closed resolver + preset mapping + settings UI + permissions (§3) | — |
| W3 | Receipt lifecycle split: Draft receipts, GRN-at-post, matcher `Posted` filters, receiveGoods refactor (§5) | — |
| W4 | Receipt-first: StandaloneReceiptService + auto-PO semantics + serializer + consumer matrix + "Nouvelle réception" UI + BL identity columns (§6) | W2, W3 |
| W5 | Invoice-first: delivery fork, saga, parked-SI model + linking UI + approval gate (§7) | W2, W3, W4 |
| W6 | Entry/exit read endpoint + list + GRN print template (§8) | W3 |
| W7 | revert() + cancel() unpaid-guard consolidation (§9) | — |
**Phase 2 (recorded, not built):** corrective receipts; FK hardening; legacy 408
fail-closed; stored-status normalization; per-line stricter-only overrides;
bill-before-receipt (`ordered`) GL; mobile exit surface; auto-suggest linking +
Scan-to-Document capture (own spec); El Fatoora emission.

---

## 14. Superseded / retired artifacts

- `feat/supplier-invoice-ocr` branch code commits (`1c1bc2a77` DocumentType::GoodsReceipt,
  `f66448d43`+`d340ded36` GoodsReceiptDocumentService): superseded — receipts are ledger
  rows, not Documents. The branch's validation/precision patterns (scoped FK lookups,
  bcformatStrict, accrual-basis carriage) are re-expressed in §5/§6. Its design DOCS
  remain input to the Scan-to-Document spec. Branch to be archived (not merged, not
  rebased).
- Procurement spec Phase-2 backlog entries "receipt-first entry" and "PO-less service
  invoices — S5": superseded by this spec (service invoices variant folded into
  invoice-first pending model; true PO-less receipts remain NOT adopted — auto-PO is the
  v1 mechanism).
- The 15-task plan `2026-06-28-po-less-supplier-invoice-grn.md`: retired.

## 15. Open items

- Expert-comptable: billed-price 408 accrual basis (invoice-first), PPV account numbers
  (6585/7585 placeholders — carried from procurement v1), Tunisia consolidated-invoicing stance.
- Owner: pilot-customer name/spelling before any seeder/doc naming.
- Factory orchestrator: merge sequencing — this branch lands only after procurement v1
  (`post-demo`) is promoted to dev (owner stability call pending).
