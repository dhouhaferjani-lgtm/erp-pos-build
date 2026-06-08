# Branch Tax-ID Spec — Research 04: Document/Receipt Numbering & Per-Establishment Series

> **Dimension:** numbering series scope. Does AutoERP's document/receipt numbering support a
> per-establishment (per-location) invoice series, or is it company-wide? What changes for the
> per-branch tax-ID spec (Tunisia: distinct invoice series individualized by establishment)?

Source tree: `apps/erp.branch-tax-id` (branch `feat/branch-tax-id-spec`). All paths below are relative
to that worktree root.

---

## 1. Document numbering (quotes, orders, invoices, credit/delivery/return notes)

**Generator:** `apps/api/app/Modules/Document/Domain/Services/DocumentNumberingService.php:18`
(`generateNumber(string $tenantId, string $companyId, DocumentType $type): string`).

- Format: `PREFIX-YYYY-NNNN` (e.g. `INV-2025-0001`) — built at line 47:
  `sprintf('%s-%d-%04d', $prefix, $year, $nextNumber)`. Prefix from
  `DocumentType::getPrefix()` (`apps/api/app/Modules/Document/Domain/Enums/DocumentType.php:21`).
- **Scope key = (company_id, type, year).** The sequence row is located/locked at
  `DocumentNumberingService.php:25-29` by `company_id` + `type` + `year` (`lockForUpdate()` for
  concurrency safety inside a `DB::transaction`). There is **no location/establishment dimension** in
  the sequence key.

**Sequence model / table:**
- Model: `apps/api/app/Modules/Document/Domain/DocumentSequence.php` (table `document_sequences`,
  columns `tenant_id`, `company_id`, `type`, `year`, `last_number`).
- Migration: `apps/api/database/migrations/tenant/2025_11_30_080002_create_document_sequences_table.php`
  — original UNIQUE was `(tenant_id, type, year)`.
- Migration: `apps/api/database/migrations/tenant/2025_11_30_140002_add_company_id_to_document_sequences.php`
  — Phase 0.4 added `company_id` and **re-pointed the UNIQUE constraint to `(company_id, type, year)`**
  (line 26). The migration comment is explicit: "Document sequences should be scoped to company (legal
  entity) not tenant (account)."

**Uniqueness / scope key:** DB-level UNIQUE `(company_id, type, year)`. One monotonic counter per
legal entity (company) per document type per calendar year — **shared across all establishments**.

**Callers** (all pass `companyId` from `CompanyContext::requireCompanyId()`, never a location):
`InvoiceController.php:186`, plus `QuoteController`, `SalesOrderController`, `PurchaseOrderController`,
`DeliveryNoteController`, `ReturnNoteController`, `RefundController`, the conversion converters under
`Document/Domain/Services/Conversion/`, `Cart/.../CartConversionService.php`,
`Marketplace/.../MarketplaceOrderService.php`. Workshop work orders and Scheduling appointments have
their own parallel sequences, also **per (company, year)** — e.g.
`apps/api/app/Modules/Workshop/WorkOrder/Infrastructure/Persistence/EloquentWorkOrderSequence.php:34-53`
(`WO-%d-%06d`, keyed on `tenant_id`+`company_id`+`year`).

**Establishment IS recorded on the document, just not in the number.** The `documents` table carries a
`location_id` column (`apps/api/app/Modules/Document/Domain/Document.php:115`), so the issuing
establishment is captured per row — but the assigned `document_number` does not reflect it and the
series is shared.

---

## 2. Receipt numbering (POS tickets) + fiscal chain scope

**Generator:** `apps/api/app/Modules/POS/Application/Services/ReceiptCreationService.php`
- `generateReceiptNumber()` at `ReceiptCreationService.php:802-809`:
  Format `{location_code}-{terminal_code}-{year}-{sequence}` (e.g. `MAIN-POS01-2026-00000001`,
  8-digit zero-padded sequence). Training variant `generateTrainingReceiptNumber()` at line 817 prefixes
  `TRN-`.
- Docstring at line 798-800 already cites NF525: "the receipt number embeds both the store/location
  and terminal identifiers for multi-location uniqueness."

**Scope key = per terminal.** The receipt sequence counter is `current_sequence` / `current_year`
stored **on the Terminal row** (`apps/api/app/Modules/POS/Domain/Terminal.php:37-38, 99-100`), advanced
under a `lockForUpdate()` on the terminal (`ReceiptCreationService.php:138-141, 490, 634-635`). Annual
reset via `Terminal::needsSequenceReset()` (`Terminal.php:194`). Terminal `belongsTo` a Location and a
Company (`Terminal.php:30-31`); the receipt number string therefore already embeds the establishment via
`location_code`.

**POS fiscal hash chain = per terminal.** The receipt-level chain (`previous_hash`, `chain_sequence`,
terminal `last_hash`) advances per terminal in
`apps/api/app/Modules/POS/Application/Services/ReceiptFinalizationService.php:93-109`
(`$receipt->previous_hash = $terminal->last_hash; $receipt->chain_sequence = $terminal->current_sequence;`
… `$terminal->last_hash = $hash; $terminal->current_sequence++;`). So each terminal is its own append-only
chain.

**Fiscal-events chain (the SoT event log) = per (tenant_id, terminal_id, chain_context).** Model
`apps/api/app/Modules/Fiscal/Domain/Models/FiscalEvent.php` carries `company_id`, `terminal_id`,
`previous_hash`, `sequence_number`. The verifier
`apps/api/app/Modules/Fiscal/Infrastructure/Commands/VerifyEventChainCommand.php:70-78` **requires
`--terminal` and `--chain-context`** to scope a single chain walk — confirming the canonical fiscal
chain is partitioned per terminal (within a tenant/context), not per company and not per establishment.

---

## 3. Per-establishment series implication (Tunisia)

Tunisian practice allows (and for multi-establishment taxpayers effectively expects) an invoice series
individualized per establishment — typically by embedding the establishment's secondary tax-ID /
establishment code in the number prefix or by maintaining an independent counter per establishment.

**Current state vs. that requirement:**

| Surface | Today's scope | Per-establishment ready? |
|---|---|---|
| POS receipts | per terminal; number already embeds `location_code` | **Yes, effectively.** Each establishment's terminals already produce distinct, location-prefixed series. The number is unique and traceable to the establishment. |
| POS fiscal chain | per terminal (and per tenant/context in FiscalEvent) | **Yes.** Each terminal/establishment is an independent append-only chain; no cross-establishment coupling. |
| Documents (invoices, credit notes, etc.) | **per company-wide `(company_id, type, year)`** — `location_id` recorded on the row but NOT in the sequence key or the number | **No.** One shared company-wide series spans all establishments. The number (`INV-2025-0001`) has no establishment dimension. |
| Work orders / appointments | per `(company, year)` | **No** (same gap as documents, lower compliance priority). |

So the **gap is the back-office document numbering** (`DocumentNumberingService` /
`document_sequences`), not POS. POS already satisfies per-establishment individualization.

---

## 4. Change surface to support per-establishment document series

If the spec decides invoices/credit notes must carry a per-establishment series, the minimal change set is:

1. **Add an establishment dimension to the sequence key.** Migration on `document_sequences` to add
   `location_id` (nullable for backward compat / company-level fallback) and change the UNIQUE from
   `(company_id, type, year)` to `(company_id, location_id, type, year)`. (Mirrors the Phase 0.4
   migration `2025_11_30_140002_add_company_id_to_document_sequences.php` that moved tenant→company.)
2. **`DocumentSequence` model** (`Document/Domain/DocumentSequence.php`): add `location_id` to
   `$fillable` + a `scopeForLocation`.
3. **`DocumentNumberingService::generateNumber()`** (`...Services/DocumentNumberingService.php:18`):
   add a `?string $locationId` parameter; include it in the `where(...)->lockForUpdate()` lookup
   (line 25-29) and in the `create([...])` (line 33-39). Optionally fold an establishment code into the
   number format string (line 47) so the printed number is individualized (e.g.
   `INV-{ESTAB}-2025-0001`) — required if Tunisian rule wants the series visible on the invoice, not
   just internally distinct. `getCurrentNumber()` (line 54) needs the same parameter.
4. **Update all callers** to pass the document's `location_id` (the establishment issuing the invoice).
   Call sites: `InvoiceController.php:186` and the other Document controllers, the conversion converters,
   `CartConversionService`, `MarketplaceOrderService` (full list in §1). The `documents` row already has
   `location_id` (`Document.php:115`), so the establishment is available at every call site.
5. **Establishment tax-ID source.** `tax_id` / `vat_number` today live on **Company**
   (`apps/api/app/Modules/Company/Domain/Company.php:41-43, 195-197`). The **Location** model
   (`apps/api/app/Modules/Company/Domain/Location.php`) has `code`, address, and receipt header/footer
   but **no tax-ID field**. If the per-branch tax-ID spec adds a per-establishment tax/registration ID,
   that column lands on `locations`, and the numbering format can derive the series identifier from it
   (or from `location.code`).
6. **(Optional) Work orders / appointments** — same pattern if those documents also need
   per-establishment series; lower priority (not fiscal-output documents).

**No change needed for POS receipts or either fiscal hash chain** — both are already per-terminal /
per-establishment. Per-establishment document numbering does **not** affect the fiscal chains: the POS
chain keys on terminal, the FiscalEvent chain keys on (tenant, terminal, context); neither references the
back-office `document_sequences` counter. The two numbering domains are independent.

---

## Bottom line

- **Does numbering need per-establishment scoping for compliance?** **Partly.** POS receipt numbering and
  both fiscal hash chains are already per-terminal/per-establishment and compliant. The **back-office
  document numbering (invoices/credit notes/etc.) is company-wide** — UNIQUE `(company_id, type, year)`
  with no establishment dimension. To meet the Tunisian "distinct series per establishment" rule for
  formal invoices, document numbering **does** need a per-establishment (`location_id`) dimension added.
- **Change surface:** one migration on `document_sequences` (add `location_id`, widen UNIQUE to
  `(company_id, location_id, type, year)`), `DocumentSequence` model, a new `?locationId` param threaded
  through `DocumentNumberingService::generateNumber()`/`getCurrentNumber()`, and an update to ~10
  Document call sites to pass the already-recorded `documents.location_id`. Establishment tax-ID likely
  becomes a new column on `locations` (today only Company carries `tax_id`/`vat_number`). Fiscal chains
  are untouched.
