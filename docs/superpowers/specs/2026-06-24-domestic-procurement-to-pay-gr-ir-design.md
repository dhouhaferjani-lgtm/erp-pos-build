# Domestic Procurement-to-Pay (GR-IR) — Phase 1 Design Spec

**Date:** 2026-06-24
**Status:** Draft (awaiting adversarial review, then implementation plan)
**Scope:** Phase 1 = domestic, receipt-first procurement-to-pay for a Tunisia-based ERP (TND). Designed to be **future-safe** for an import/invoice-first Phase 2 without rework, but Phase 2 is explicitly out of scope (YAGNI).

---

## 1. Goal

Recognize supplier liabilities (Accounts Payable) and inventory at the **correct economic and fiscal moments** for domestic purchasing, and give supplier invoices a real home (document + attachment + 3-way matching). This replaces the reverted H-3.2/H-3.1 model, which wrongly recognized expense + AP at **PO confirmation** on ordered quantities (double-counting cost against perpetual COGS and booking non-recoverable tax as deductible VAT).

## 2. Why the old model was wrong (motivation, grounded)

- **Perpetual inventory:** the COGS engine credits Inventory on sale. If purchases never **debit** Inventory, cost is double-counted and Inventory drifts negative. Inventory must be debited at **goods receipt**.
- **Tunisian VAT law (Code de la TVA, Art. 9 + Art. 18):** input VAT (TVA déductible) is recoverable **only when the taxpayer holds a compliant *facture***. VAT therefore must be booked at the **supplier-invoice** step, never at goods receipt.
- **Uncertainty until receipt:** unlike a sale (inventory leaves at a known instant, so the amount is exact), a purchase's true quantity/value isn't known until goods arrive (damage, shortage, substitution). AP must be **receipt-driven and adjustable**.

## 3. The accounting model (Tunisian SCE = French PCG numbering)

GL accounts (seed/confirm in the Tunisia chart):

| Account | Name | Role |
|---|---|---|
| Class 3 (e.g. `3xx`) | Stocks | Inventory asset (perpetual) |
| **408** | Fournisseurs – Factures non parvenues | **GR-IR accrual** (goods received, not yet invoiced) |
| **401** | Fournisseurs | **Accounts Payable** |
| **4366** | Taxes sur le chiffre d'affaires déductibles (subdiv. 43666 récupérable) | **Deductible (input) VAT** — booked only at invoice |
| `6xx` non-recoverable | Droits de timbre (non-recoverable) — *exact account to confirm in chart* | **Timbre fiscal** on purchase invoices (non-recoverable charge) |
| 4091 *(Phase 2)* | Avances et acomptes versés sur commandes | Supplier advances (import/invoice-first) |

**Double-entry, domestic receipt-first:**

1. **Goods receipt** (`GoodsReceiptService::receiveGoods`, partial-aware, received-qty × unit cost), **VAT-excluded**:
   - `Dr Inventory (class 3)` = received qty × cost (HT)
   - `Cr 408` = same (HT)
   - Partial receipts post incrementally (each receipt posts its received delta). No VAT, no AP yet.

2. **Supplier invoice posted** (clears the accrual into AP, books VAT, books non-recoverable timbre):
   - `Dr 408` = invoiced goods HT (clears the matched accrual)
   - `Dr 4366` = recoverable VAT
   - `Dr 6xx timbre (non-recoverable)` = fixed stamp duty per invoice
   - `Cr 401 (partner-tagged supplier)` = gross TTC = HT + recoverable VAT + timbre
   - **Invariant:** debits == credits at storage scale (decimal(15,3)); timbre is NOT added to 4366.

3. **Payment** (existing treasury path, against a *real* payable):
   - `Dr 401 (partner-tagged)` / `Cr Treasury/Repository` — reduces `payable_balance` toward 0.

**Stamp duty (timbre):** a fixed per-invoice amount (e.g. 0.600 / 1.000 TND), **non-recoverable** → it increases the gross payable to 401 and is expensed (class-6 non-recoverable charge), never posted to 4366. It is invoice-level, not per-line.

## 4. Procurement-AP policy (config — future-safe, minimal in Phase 1)

A small config object resolved per purchase, seeded per vertical:

- `bill_control_mode`: enum `received` | `ordered`. **Phase 1 uses/seeds `received` only**; `ordered` (invoice-first) is reserved for Phase 2 (enum value exists, code path unimplemented — fail loudly if selected).
- `match_mode`: enum `two_way` | `three_way`. Phase 1 default `three_way` (PO ↔ receipt ↔ invoice).
- `match_enforcement`: enum `warn` | `block`. **Default `warn`** (surface a variance, allow posting/payment); `block` prevents posting an invoice whose match status is `exception`. **Company/tenant-configurable** (per owner decision).
- VAT timing is fixed = at invoice (not configurable).

**Resolution seam (designed, not fully built):** the resolver signature supports `company/vertical default → vendor → item` precedence (the Dynamics/Odoo pattern), but Phase 1 implements **only the company/vertical default**. Vendor/item overrides are a thin future add — the resolver accepts them and falls through to the default today.

**Per-vertical seeded defaults (Phase 1):** retail / pharmacy / parapharmacy → `bill_control_mode=received`, `match_mode=three_way`, `match_enforcement=warn`. (Import/distribution defaults are Phase 2.)

## 5. SupplierInvoice document

- A **new `supplier_invoice` `DocumentType`** in the **existing unified `documents` table** (alongside `purchase_order`, `quote`, `invoice`, `credit_note`, `delivery_note`). No new top-level table — reuse the unified document model + lines.
- **Links:** `source_document_id` → the PO; a receipt linkage (the goods-receipt operation(s) it matches). Supports a supplier invoice spanning multiple partial receipts of one PO, and (future) one invoice across POs is out of scope.
- **Lifecycle (DocumentStatus / a dedicated supplier-invoice status):** `draft` → `matched` (3-way computed) → `posted` (GL posted, accrual cleared) → `paid`. Enums only, no magic strings.
- **Attachment:** the source PDF/scan via the **existing `Media` module `AttachmentService`**, stored on the **S3/MinIO disk** (NOT `local`). No media-subsystem refactor — Phase 1 simply sets `storage_disk` to the configured object-storage disk. (The disk/MinIO unification + S3/R2 swap is a separate session.)
- **Navigation home:** a findable, searchable list under **Purchases → Supplier Invoices**, filterable by supplier, status, match status, date; opening one shows lines, the linked PO/receipt, the match result, and the attachment.

## 6. Three-way matching

- Compare, per matched line: **PO** (agreed unit price) ↔ **goods receipt** (received quantity) ↔ **supplier invoice** (billed qty × billed price).
- Compute a **match status**: `matched` (within tolerance), `price_variance`, `quantity_variance`, or `exception` (beyond tolerance / unreceived).
- **Variance tolerance** reuses the precision contract; thresholds are config (small percentage + max-amount, mirroring the tender-tolerance dual-threshold pattern) — defaulted, not hand-typed constants.
- **Enforcement** per `match_enforcement`: `warn` surfaces the status and lets the user post anyway (advisory, the Odoo "Should be paid" pattern); `block` refuses to post an `exception`-status invoice.
- Matching operates on **structured data** (lines/quantities/amounts), independent of the PDF attachment — attachment is for human audit, not the match.

## 7. Adjustments (domestic Phase 1)

- **Short/over receipt:** handled by `GoodsReceiptService` partial receipts — the accrual (408) reflects actual received quantities; the invoice matches against received, so quantity variance surfaces.
- **Damaged goods / price disputes:** resolved by posting a **supplier credit note** (existing `credit_note` document type, supplier-side) that reverses the relevant 401/408 amounts — not by editing a posted invoice (event-immutability / fiscal integrity).
- Landed cost, customs VAT, supplier advances → **Phase 2** (import).

## 8. Dependencies & sequencing

1. **R-1** (Codex remediation): revert the H-3.2 + H-3.1 supplier-AP pair, returning supplier AP to a clean zero baseline. **Precedes** Phase 1.
2. **R-2** (Codex remediation): fix `GeneralLedgerService::postEntry` to set `chain_sequence` so the new receipt/invoice GL posts verify under `GeneralLedgerHashService::verifyChain`. Phase 1's GL posting MUST go through the corrected canonical posting path.
3. Phase 1 builds on the corrected base.

## 9. Cross-cutting conventions (must hold)

- **Precision contract:** all money via `CurrencyScale::bcformatStrict` at TND scale 3; never float; constructor-inject `CurrencyScaleResolverInterface`; quantities scale 4. Timbre, VAT, and HT each rounded once at the boundary.
- **Constructor injection only**; strict typing (no `mixed`/`any`); enums for every status/type; module boundaries via `Shared/Contracts`/events/public services.
- **Event-sourcing / immutability:** GL entries and any new domain events are immutable; corrections are new entries (credit notes), never mutations. Goods-receipt GL and supplier-invoice GL post through the canonical hash-chained path (R-2).
- **db-per-tenant:** all new tables/columns are tenant-scoped (`migrations/tenant/`); any CHECK constraint is PG-only and must have a real-PG verification (SQLite tests can't catch it).
- **TDD:** failing test first for each GL entry, the matcher, the policy resolver, and the document lifecycle.

## 10. Testing strategy

- **GL correctness (Feature, real entries):** receipt posts `Dr Inventory / Cr 408` VAT-excluded; invoice posts `Dr 408 + Dr 4366 + Dr timbre / Cr 401` and debits==credits; timbre never hits 4366; payment clears 401. Verify `verifyChain()` passes after both posts (depends on R-2).
- **Partial receipts:** two partial receipts accrue 408 incrementally; an invoice matching both clears correctly.
- **Matcher:** matched / price-variance / quantity-variance / exception cases; `warn` allows post, `block` refuses an exception.
- **Policy resolver:** vertical default applied; unsupported `ordered` mode fails loudly.
- **Precision:** TND scale-3 amounts; a timbre + VAT + HT sum reconciles exactly with no float drift.
- **Document lifecycle + attachment:** draft→matched→posted→paid; attachment stored to the S3/MinIO disk (asserts `storage_disk != 'local'`).
- **db-per-tenant:** GL/accounts/documents resolve in the tenant DB.

## 11. Explicitly out of scope (Phase 2 / other sessions)

- **Import / invoice-first:** bill-on-ordered, supplier advances (4091), landed-cost capitalization (CIF + customs duty), import VAT (TVA à l'importation) from the customs attestation. The policy enum + document model accommodate it; no code path in Phase 1.
- **Media-system unification:** consolidating disk + MinIO, defaulting object storage everywhere, S3/R2 swappability. Phase 1 rides the existing `AttachmentService` on the MinIO disk only.
- **Vendor/item-level policy overrides** (resolver seam exists; unbuilt).

## 12. Fiscal-compliance cautions

- VAT booked **only** at a compliant invoice (Art. 9/18) — receipt/408 is strictly VAT-excluded.
- Timbre is **non-recoverable** — never to 4366; confirm the exact class-6 stamp-duty account number in the seeded Tunisia chart.
- A compliant Tunisian *facture* carries mandatory mentions (supplier + client matricule fiscal, date, HT, VAT rates/amounts); the SupplierInvoice document should capture these fields for audit even though Phase 1 does not generate the supplier's facture (the supplier does).
- Confirm whether Tunisian e-invoicing (El Fatoora/TTN, 2026 expansion) imposes any inbound supplier-invoice capture obligation — flagged, not assumed.

## 13. Open items (non-blocking; parameterized)

- Exact class-6 account number for non-recoverable timbre on purchases (chart seed detail).
- Default 3-way variance tolerance values (start from the existing tender-tolerance defaults: small % + max-amount).
