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

**GL legs resolve via `SystemAccountPurpose` enums, NOT hardcoded account numbers** (the codebase pattern — `Account::findByPurposeOrFail`). The seeded Tunisia chart's actual numbers are shown for reference, but the spec/code must use the purpose. (Review H-3/H-4.)

| Purpose (`SystemAccountPurpose`) | Seeded TN account | Role | Status |
|---|---|---|---|
| `Inventory` | class 3 | Inventory asset (perpetual) | exists |
| `SupplierPayable` | 401 Fournisseurs | Accounts Payable | exists |
| `VatDeductible` | **4456** TVA déductible (NOT 4366 — see §13) | Deductible input VAT — booked only at invoice | exists (`SystemAccountPurpose.php:28`) |
| **`GoodsReceivedNotInvoiced`** *(NEW)* | **408** Fournisseurs – Factures non parvenues (seeded `:140`) | **GR-IR accrual** (goods received, not yet invoiced) | **ADD purpose + seed mapping** (mirror of `UninvoicedRevenue`=418 on the sales side) |
| **`PurchaseStampDuty`** *(NEW, non-recoverable)* | class-6 expense (exact account to seed/confirm) | **Timbre fiscal** on purchase invoices — non-recoverable charge | **ADD purpose + seed account/mapping** |
| `SupplierAdvance` (4091) *(Phase 2)* | 4091 Avances et acomptes | Supplier advances (import) | exists |

**New work this introduces (Phase 1):** add two `SystemAccountPurpose` cases (`GoodsReceivedNotInvoiced` → 408; `PurchaseStampDuty` → a non-recoverable class-6 account), seed their account mappings in every relevant chart seeder (Tunisia confirmed has 408 at `:140`; the timbre expense account may need seeding), with a real-PG verification that the mappings resolve.

**Double-entry, domestic receipt-first:**

(accounts referenced by purpose; seeded numbers in parentheses)

1. **Goods receipt** (`GoodsReceiptService::receiveGoods`, partial-aware, received-qty × unit cost), **VAT-excluded**:
   - `Dr Inventory` (class 3) = received qty × cost (HT)
   - `Cr GoodsReceivedNotInvoiced` (408) = same (HT)
   - Partial receipts post incrementally (each receipt posts its received delta). No VAT, no AP yet.

2. **Supplier invoice posted** (clears the accrual into AP, books VAT, books non-recoverable timbre):
   - `Dr GoodsReceivedNotInvoiced` (408) = invoiced goods HT (clears the matched accrual)
   - `Dr VatDeductible` (4456) = recoverable VAT
   - `Dr PurchaseStampDuty` (class-6, non-recoverable) = fixed stamp duty per invoice
   - `Cr SupplierPayable` (401, partner-tagged) = gross TTC = HT + recoverable VAT + timbre
   - **Invariant:** debits == credits at storage scale (decimal(15,3)); timbre is NOT added to `VatDeductible`.

3. **Payment** (existing treasury path, against a *real* payable):
   - `Dr SupplierPayable` (401, partner-tagged) / `Cr Treasury/Repository` — reduces `payable_balance` toward 0.

**Stamp duty (timbre):** a fixed per-invoice amount (e.g. 0.600 / 1.000 TND), **non-recoverable** → it increases the gross payable to `SupplierPayable` (401) and is expensed via `PurchaseStampDuty` (class-6 non-recoverable charge), never posted to `VatDeductible`. It is invoice-level, not per-line.

**Posting requirements (reviews B2-3 / B2-4 / B2-7):**
- **New GR-IR posting path — do NOT reuse `createSupplierInvoiceJournalEntry`** (`GeneralLedgerService.php:459`): that helper debits an arbitrary expense/asset + VAT and credits AP (the H-3.2 model being reverted in R-1). Phase 1 adds a **new** supplier-invoice GR-IR posting method (Dr 408 + Dr VAT + Dr timbre / Cr 401) and a new goods-receipt posting method (Dr Inventory / Cr 408), both through the corrected canonical hash-chained path (R-2).
- **Recoverable-VAT source:** book `VatDeductible` from the **recoverable line tax** (`document_lines.line_tax_amount` / recoverable-tax detail), **NOT** `documents.tax_amount` — the latter = line tax **+ stamp duty** (`DocumentTotalsCalculator.php:45`), so using it would wrongly recover the timbre. Timbre comes from the document's `stamp_duty_amount` → `PurchaseStampDuty`.
- **New `SystemAccountPurpose` cases require the full enum surface:** `label()` and `expectedAccountType()` are exhaustive `match`es — `GoodsReceivedNotInvoiced` must be classified **`AccountType::Liability`** (408 is a liability; do NOT copy the sales-side `UninvoicedRevenue`, which is an asset), `PurchaseStampDuty` = `Expense`. Update `label()`, `expectedAccountType()`, and any `requiredPurposes()`/validation surface; seed both account mappings across all charts with a real-PG resolution check.

## 4. Procurement-AP policy (config — future-safe, minimal in Phase 1)

A small config object resolved per purchase, seeded per vertical:

- `bill_control_mode`: enum `received` | `ordered`. **Phase 1 uses/seeds `received` only**; `ordered` (invoice-first) is reserved for Phase 2 (enum value exists, code path unimplemented — fail loudly if selected).
- `match_mode`: enum `two_way` | `three_way`. Phase 1 default `three_way` (PO ↔ receipt ↔ invoice).
- `match_enforcement`: enum `warn` | `block`. **Default `warn`** (surface a variance, allow posting/payment); `block` prevents posting an invoice whose match status is `exception`. **Company/tenant-configurable** (per owner decision).
- VAT timing is fixed = at invoice (not configurable).

**Resolution seam (designed, not fully built):** the resolver signature supports `company/vertical default → vendor → item` precedence (the Dynamics/Odoo pattern), but Phase 1 implements **only the company/vertical default**. Vendor/item overrides are a thin future add — the resolver accepts them and falls through to the default today.

**Per-vertical seeded defaults (Phase 1):** retail / pharmacy / parapharmacy → `bill_control_mode=received`, `match_mode=three_way`, `match_enforcement=warn`. (Import/distribution defaults are Phase 2.)

**Persistence (review B2-9 — currently no such config exists in code):** store the policy in a **tenant-scoped `procurement_policy` table** (or a typed company column) — enum-backed columns (`bill_control_mode`, `match_mode`, `match_enforcement`) with **PG CHECK constraints** (this is accounting-control logic; PG-only constraints need real-PG verification). The resolver reads company/vertical default; the vertical defaults are seeded. Variance-tolerance thresholds (§6) live here too.

## 5. SupplierInvoice document

- A **new `supplier_invoice` `DocumentType`** in the **existing unified `documents` table** (alongside `purchase_order`, `quote`, `invoice`, `credit_note`, `delivery_note`). No new top-level table — reuse the unified document model + lines. **Exhaustive enum surface (review B2-10):** adding the case requires updating `DocumentType::getPrefix()` and `label()` (exhaustive `match`es throw otherwise), document numbering, and the web list/filter — implementation-checklist items.
- **Fiscal category (review B2-6):** a supplier invoice is a **captured third-party document → `FiscalCategory::NON_FISCAL`** (we don't author the supplier's facture). `fromDocumentType()` already defaults unknown types to `NON_FISCAL`, so no new PG fiscal-category-constraint migration is needed. Its **GL entries are still hash-chained** via the canonical path.
- **Links:** `source_document_id` → the PO; receipt linkage via the PO lines' cumulative `quantity_received`/`quantity_invoiced` (see §6). One invoice spanning multiple partial receipts of one PO is supported; one invoice across multiple POs is out of scope.
- **Status modeling (review B2-5 — `DocumentStatus` has NO `matched` case → hydration would break):** use `DocumentStatus` for `draft → posted → paid` only; carry the match result in a **separate `match_status` field** (own enum: `unmatched`/`matched`/`variance`/`exception`). Do NOT add `matched` to `DocumentStatus`. Enums only, no magic strings.
- **Attachment — use the unified `MediaAsset` system, NOT legacy `DocumentAttachment` (owner guidance 2026-06-24, supersedes review H-5):** attach the source PDF/scan via the unified **`MediaAsset`** system (`Catalog/Domain/Media/{MediaAsset,MediaAttachment,MediaRendition}`; `media_assets` file table + `media_attachments` link table; MinIO-backed). Add a **`MediaOwnerType::SupplierInvoice`** enum case; create a `MediaAsset` + a `MediaAttachment` linked by `(owner_type = SupplierInvoice, owner_id = <supplier_invoice document id>)`.
  - **Document upload port needed (review media-M2/M3):** the current `MediaUploadService::uploadForProduct` is **image/product-only** (image MIME allowlist). Phase 1 adds a **supplier-invoice document upload path** that stores `MediaAssetType::Document` (exists), accepts PDF/scan MIME types, skips image renditions, and adds a `MediaRole::SourceDocument` (current roles are product-oriented: PRIMARY/GALLERY/DATASHEET/MANUAL) — exposed behind the thin port.
  - **Do NOT extend the legacy `Media` module `DocumentAttachment` / `AttachmentService`** (local-disk, hard FK) — that system is being retired; adding supplier invoices to it would create a third consumer of the path we're removing.
  - **Coordination (a dedicated media-unification session is being kicked off):** MediaAsset is being promoted out of `Catalog` into a shared/first-class module and documents are being migrated onto it. Therefore (1) **coordinate the `SupplierInvoice` owner-type naming** with that session so we don't diverge, and (2) keep the SupplierInvoice→media integration **thin and isolated behind a small port** so it can be repointed when MediaAsset moves modules. Mirrors the stock-adjustment feature's choice.
  - Refs: `docs/superpowers/specs/2026-06-12-media-subsystem-architecture-design.md`; `docs/superpowers/plans/2026-06-23-stock-adjustment-writeoff-audit-and-plan.md` (§6-D + Media-unification note).
- **Navigation home:** a findable, searchable list under **Purchases → Supplier Invoices**, filterable by supplier, status, match status, date; opening one shows lines, the linked PO/receipt, the match result, and the attachment.

## 6. Three-way matching

- Compare, per matched line: **PO** (agreed unit price) ↔ **goods receipt** (received quantity) ↔ **supplier invoice** (billed qty × billed price).
- **Receipt quantity source + over-clear guard (reviews M-6 / B2-1, BLOCKER):** `GoodsReceiptService` tracks **cumulative `quantity_received` per PO line** but no first-class receipt-operation record. Matching against `quantity_received` ALONE is unsafe across multiple invoices: receive 10 → invoice A 6 → invoice B 6 each pass `6<=10`, but 12 > 10 received → **408 over-cleared**. **Phase 1 MUST track cumulative invoiced/GR-IR-cleared quantity per PO line** (add a `quantity_invoiced` column on `document_lines`, mirroring `quantity_received`, OR a supplier-invoice-line→PO-line allocation table). The matchable quantity for a new invoice line = `quantity_received − quantity_invoiced`; rule: `invoiced_qty <= (quantity_received − quantity_invoiced)`. On invoice post, increment `quantity_invoiced` and clear 408 only for that invoiced delta. (Tenant migration for the new column; PG.)
- Precise per-physical-receipt linkage (which receipt an invoice covers) is a separate **future extension** (durable goods-receipt record / stock-movement linkage); cumulative-quantity tracking above is the Phase 1 correctness requirement.
- Compute a **match status**: `matched` (within tolerance), `price_variance`, `quantity_variance`, or `exception` (beyond tolerance / unreceived).
- **Variance tolerance** reuses the precision contract; thresholds are config (small percentage + max-amount, mirroring the tender-tolerance dual-threshold pattern) — defaulted, not hand-typed constants.
- **Enforcement** per `match_enforcement`: `warn` surfaces the status and lets the user post anyway (advisory, the Odoo "Should be paid" pattern); `block` refuses to post an `exception`-status invoice.
- Matching operates on **structured data** (lines/quantities/amounts), independent of the PDF attachment — attachment is for human audit, not the match.

## 7. Adjustments (domestic Phase 1)

- **Short/over receipt:** handled by `GoodsReceiptService` partial receipts — the accrual (408) reflects actual received quantities; the invoice matches against received, so quantity variance surfaces.
- **Damaged goods / price disputes (review B2-2 — do NOT reuse the customer credit-note path):** the existing `credit_note` type + `createFromCreditNote()` is **customer-side** (debits sales revenue / VAT-payable, credits AR — `GeneralLedgerService.php:183,237`). A supplier credit note needs its **own type/branch + GL matrix**: reduce `SupplierPayable` (Dr 401), reverse `VatDeductible` (Cr), reverse `GoodsReceivedNotInvoiced`/inventory or book a price-variance as applicable, and handle timbre. Resolve adjustments by posting this supplier-credit-note (a new immutable entry) — never by editing a posted invoice (event-immutability / fiscal integrity). Scope the exact supplier-credit-note GL matrix in the plan.
- Landed cost, customs VAT, supplier advances → **Phase 2** (import).

## 8. Dependencies & sequencing

1. **R-1** (Codex remediation): revert the H-3.2 + H-3.1 supplier-AP pair, returning supplier AP to a clean zero baseline. **Precedes** Phase 1.
2. **R-2** (Codex remediation): fix `GeneralLedgerService::postEntry` to set `chain_sequence` so the new receipt/invoice GL posts verify under `GeneralLedgerHashService::verifyChain`. Phase 1's GL posting MUST go through the corrected canonical posting path.
3. Phase 1 builds on the corrected base.

**Hard gate (review B-1/B-2):** the Codex review confirmed BOTH are still unsatisfied on current dev — `PurchaseOrderConfirmedListener` still posts AP at PO confirmation, and `postEntry()` still omits `chain_sequence`. **Phase 1 implementation must not begin until R-1 and R-2 have landed** (else the new AP collides with the old, and every new GL post fails `verifyChain`).

## 9. Cross-cutting conventions (must hold)

- **Precision contract:** all money via `CurrencyScale::bcformatStrict` at TND scale 3; never float; constructor-inject `CurrencyScaleResolverInterface`; quantities scale 4. Timbre, VAT, and HT each rounded once at the boundary.
- **Receipt-GL amount from strings, before the float WAC boundary (review B2-8):** `GoodsReceiptService`/`WeightedAverageCostService` currently cast cost/qty to float for WAC. The GR-IR receipt amount (`Dr Inventory / Cr 408`) MUST be computed with bcmath from the original numeric strings (PO line unit cost × received qty), NOT derived from the post-float-cast WAC value.
- **Constructor injection only**; strict typing (no `mixed`/`any`); enums for every status/type; module boundaries via `Shared/Contracts`/events/public services.
- **Event-sourcing / immutability:** GL entries and any new domain events are immutable; corrections are new entries (credit notes), never mutations. Goods-receipt GL and supplier-invoice GL post through the canonical hash-chained path (R-2).
- **db-per-tenant:** all new tables/columns are tenant-scoped (`migrations/tenant/`); any CHECK constraint is PG-only and must have a real-PG verification (SQLite tests can't catch it).
- **TDD:** failing test first for each GL entry, the matcher, the policy resolver, and the document lifecycle.

## 10. Testing strategy

- **GL correctness (Feature, real entries):** receipt posts `Dr Inventory / Cr GoodsReceivedNotInvoiced` VAT-excluded; invoice posts `Dr GoodsReceivedNotInvoiced + Dr VatDeductible + Dr PurchaseStampDuty / Cr SupplierPayable` and debits==credits; timbre never hits `VatDeductible`; payment clears `SupplierPayable`. Resolve every leg by purpose (assert the resolved account, not a literal number). Verify `verifyChain()` passes after both posts (depends on R-2).
- **Partial receipts:** two partial receipts accrue 408 incrementally; an invoice matching both clears correctly.
- **Matcher:** matched / price-variance / quantity-variance / exception cases; `warn` allows post, `block` refuses an exception.
- **Policy resolver:** vertical default applied; unsupported `ordered` mode fails loudly.
- **Precision:** TND scale-3 amounts; a timbre + VAT + HT sum reconciles exactly with no float drift.
- **Document lifecycle + attachment:** draft→matched→posted→paid; attaching a PDF creates a `MediaAsset` + a `MediaAttachment` with `owner_type = SupplierInvoice` / `owner_id = <doc id>` (assert the link via the unified MediaAsset system — NOT a legacy `DocumentAttachment` row).
- **db-per-tenant:** GL/accounts/documents resolve in the tenant DB.

## 11. Explicitly out of scope (Phase 2 / other sessions)

- **Import / invoice-first:** bill-on-ordered, supplier advances (4091), landed-cost capitalization (CIF + customs duty), import VAT (TVA à l'importation) from the customs attestation. The policy enum + document model accommodate it; no code path in Phase 1.
- **Media-system unification:** promoting `MediaAsset` out of `Catalog` into a shared module, migrating legacy `DocumentAttachment` onto it, S3/R2 swappability (its own session). Phase 1 only **consumes** the existing `MediaAsset` system (adds one `MediaOwnerType` case) behind a thin port; it does not refactor or move the media module.
- **Vendor/item-level policy overrides** (resolver seam exists; unbuilt).

## 12. Fiscal-compliance cautions

- VAT booked **only** at a compliant invoice (Art. 9/18) — receipt/408 is strictly VAT-excluded.
- Timbre is **non-recoverable** — never to `VatDeductible`; confirm the exact class-6 stamp-duty account number in the seeded Tunisia chart.
- A compliant Tunisian *facture* carries mandatory mentions (supplier + client matricule fiscal, date, HT, VAT rates/amounts); the SupplierInvoice document should capture these fields for audit even though Phase 1 does not generate the supplier's facture (the supplier does).
- Confirm whether Tunisian e-invoicing (El Fatoora/TTN, 2026 expansion) imposes any inbound supplier-invoice capture obligation — flagged, not assumed.

## 13. Open items (non-blocking; parameterized)

- **Deductible-VAT account numbering (review H-3):** the seeded Tunisia chart uses `4456` (French-PCG), while the strict Tunisian SCE number is `4366/43666`. Phase 1 resolves via `SystemAccountPurpose::VatDeductible` (so it works regardless), but confirm with the accountant whether the seeded chart should be corrected to `4366` — a chart-correctness question separate from this build.
- Exact class-6 account number for non-recoverable timbre on purchases (chart seed detail; needs a `PurchaseStampDuty` purpose + seeded account).
- Default 3-way variance tolerance values (start from the existing tender-tolerance defaults: small % + max-amount).
