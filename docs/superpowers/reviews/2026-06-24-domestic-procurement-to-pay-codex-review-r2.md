# Adversarial Review (Round 2) — Domestic Procurement-to-Pay GR-IR Spec
Date: 2026-06-24 | Reviewer: Codex (cross-model) | Verdict: NEEDS-REVISION

Round-1 findings all CLOSED (B-1, B-2, H-3, H-4, H-5) except M-6 PARTIAL. Round-2 found a BLOCKER + 5 HIGH + 4 MEDIUM + 1 LOW + media-approach notes. All grounded with file:line. Adjudication: all accepted (none refuted); spec revised accordingly.

## BLOCKER
- **B2-1 — Cumulative-received matching over-clears 408 across multiple invoices.** Rule `invoiced_qty <= quantity_received` ignores cumulative *already-invoiced* qty. Receive 10 → invoice A 6 → invoice B 6: each passes `6<=10` but 12 invoiced vs 10 received → 408 over-cleared. `document_lines` tracks `quantity_received` (`2025_12_13_081142...:20`) but NO cumulative `quantity_invoiced`. **Fix:** track cumulative invoiced/GR-IR-cleared qty per PO line (or a supplier-invoice→PO-line allocation table); matchable = `quantity_received - quantity_invoiced`.

## HIGH
- **B2-2 — Supplier credit-note path conflicts with customer-side GL.** `createFromCreditNote()` debits sales revenue/VAT-payable, credits AR (`GeneralLedgerService.php:183,237`) — customer-side. Spec must define a **supplier**-credit-note type/branch + GL matrix (reverse 401/408, reverse VatDeductible, timbre, inventory/price-variance).
- **B2-3 — Do not reuse the existing supplier-invoice GL helper.** `createSupplierInvoiceJournalEntry` (`:459/494/519`) debits arbitrary expense/asset + VAT, credits AP — conflicts with GR-IR clearing and is part of the R-1 revert. Spec must require a NEW GR-IR posting path.
- **B2-4 — VAT source must be recoverable line tax, not `documents.tax_amount`.** `DocumentTotalsCalculator` sets `tax_amount = line tax + stamp_duty` (`:45`). Posting recoverable VAT from `documents.tax_amount` would wrongly include timbre. Use `line_tax_amount` / recoverable-tax detail.
- **B2-5 — `matched` status has no home in `DocumentStatus`.** Enum (`DocumentStatus.php:7`) has no `matched`; `Document` casts status to it (`:157`) → hydration fails if stored. Use a **dedicated `match_status` field**, keep `DocumentStatus` for draft/posted/paid.

## MEDIUM
- **B2-6 — Supplier-invoice fiscal category unspecified.** `FiscalCategory::fromDocumentType` defaults unknown → NON_FISCAL (`:38`), PG-constrained (`2026_01_08_205902...:21`). State explicitly: supplier invoice = NON_FISCAL (captured third-party doc); GL stays hash-chained. A new category would need a PG-constraint migration + real-PG test.
- **B2-7 — New `SystemAccountPurpose` cases need full enum surface.** `label()` (`:72`) + `expectedAccountType()` (`:136`) are exhaustive matches; `GoodsReceivedNotInvoiced` must be **Liability** (408 is a liability — NOT mirror the sales-side `UninvoicedRevenue` which is classified asset at `:139`); `PurchaseStampDuty` = Expense. Update validation/`requiredPurposes()` (`:116`) as needed.
- **B2-8 — Receipt GL precision: compute from numeric strings via bcmath BEFORE the float WAC boundary.** `GoodsReceiptService:153` casts cost/qty to float; `WeightedAverageCostService:140` accepts floats. GR-IR amount must be bcmath from original strings, not derived after the float cast.
- **B2-9 — Procurement-policy persistence underspecified.** No `bill_control`/`match_mode`/`match_enforcement` exists in code. Name the tenant-scoped persistence (config table or company column) + PG constraints — it's accounting-control logic.

## LOW
- **B2-10 — `DocumentType::SupplierInvoice` needs all exhaustive enum methods** (`getPrefix()`, `label()`), numbering, and UI filters (`DocumentType.php:7,21,38`). Implementation-checklist item.

## Media-approach findings (new system is the right target)
- Adding `MediaOwnerType::SupplierInvoice` is structurally sufficient (string owner_type, UUID owner_id; no DB enum to migrate). (LOW)
- **MEDIUM — `MediaUploadService::uploadForProduct` is image/product-only** (`:45,80`); need a supplier-invoice **document upload port** storing `MediaAssetType::Document` (exists, `:10`), accepting PDF/scans, skipping image renditions.
- **MEDIUM — `media_attachments.role` is required** but no supplier-invoice source role exists (roles PRIMARY/GALLERY/DATASHEET/MANUAL, `MediaRole.php:7`); add a `SourceDocument` role (or map behind the port).
- **MEDIUM — cross-module coupling** acceptable only if the Purchases/Document port hides `Catalog\...\MediaOwnerType`/Catalog models behind its own interface.
- MinIO via `s3` disk confirmed (`MediaUploadService:109`, `filesystems.php:50`); owner_id→documents.id not FK-enforced (acceptable); tenant migration placement correct.
