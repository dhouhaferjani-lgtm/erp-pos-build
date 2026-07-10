# Procurement UI & Document Configurator Audit — 2026-07-10

> Orchestrated audit (4 parallel read-only subagents). Scope: (1) design-system divergence between `/purchases/supplier-invoices/new` and `/purchases/orders/new`; (2) product picker showing no suggestions on focus; (3) line-items table density/description rendering; (4) feasibility of editable line designation + document configurator. **No code was modified.** Implementation waves to be dispatched separately after owner review.

---

## Executive summary

| # | Question | Verdict | Size |
|---|---|---|---|
| 1 | Did supplier-invoices/new disregard the design system? | **Yes — but the rot is wider**: the whole procurement-completeness family (`supplier-invoices`, `quote-requests`, `standalone receipts` under `src/features/purchases/`) never adopted the DocumentForm-era shared system (PageHeader, FormField atoms, react-hook-form+zod, DataTable, StatusBadge, StickyFormFooter). 10 violations catalogued (1 blocker, 5 major, 4 minor). | M–L remediation |
| 2 | Why no suggestions on click in the product picker? | **Root-caused, confirmed**: `LineItemEntryBar` (used by ALL document create pages incl. orders/new) hard-gates both the fetch and the dropdown on a non-empty query (`LineItemEntryBar.tsx:77`, `:247`). The other two pickers (`ProductPicker`, `ProductLineSelect`) show suggestions on focus correctly. Backend needs no change. Fix in the shared component benefits every consumer. | S |
| 3 | Table looks messy — description always shown, multi-line | **Confirmed**: description column has no width constraint, no truncation, in all 3 (!) line-table implementations. Recommendation: merge description into the article column as a muted `line-clamp-2` sub-line + reuse the existing `renderLineDetail` expand-row mechanism. No new primitives needed; PDF path is independent. | S–M |
| 4 | Editable designation + document configurator | **Editable designation is ALREADY BUILT end-to-end** (backend, DesignationCell UI, PDF), gated behind OFF-by-default env flag `FEATURE_DOCUMENT_LINE_DESIGNATION_OVERRIDE`. Shipping it = rollout decision (S). Full per-doc-type column show/hide+editability configurator = net-new feature, no scaffolding exists (L–XL). | S / L–XL |

Cross-cutting insight: findings 1–3 reinforce each other — the codebase has **three product pickers**, **two partner search components**, and **three line-item table implementations**. The supplier-invoice page is the biggest offender, but consolidation decisions are needed before any single page fix is "done".

---

## 1. Design-system divergence

### File map
- `/purchases/orders/new` → `src/routes/index.tsx:846-854` → `DocumentForm` (`src/features/documents/DocumentForm.tsx`, shared by quote/sales_order/invoice/purchase_order/credit_note/delivery_note). Uses `PageHeader`, `StickyFormFooter`, `SaveSplitButton`, `FormField`/`Input`/`Select`/`Textarea`/`Button` atoms, `AddPartnerModal`, `PartnerSearchSelect`, `DocumentLineEditor` (→ `LineItemsTable`/`LineItemEntryBar`/`ProductCell`), react-hook-form.
- `/purchases/supplier-invoices/new` → `src/routes/index.tsx:940-948` → `SupplierInvoiceCreatePage.tsx` (877 lines, bespoke). Only shared imports: `MoneyInput`, `QuantityInput`, `PartnerPicker`, `ProductPicker`. Everything else hand-rolled with raw `tokens.*` strings.

### Violations
| ID | Sev | Finding | Evidence |
|---|---|---|---|
| V1 | **Blocker** | No react-hook-form/zod — `useState` per field, zero inline validation errors (only toast + disabled submit) | `SupplierInvoiceCreatePage.tsx:116-144`, `:374-377`; convention `docs/conventions/06-FORMS.md:6-10` |
| V2 | Major | Bespoke `<h1 className="text-2xl font-bold">` header — the literal anti-pattern `PageHeader` documents itself as replacing | `SupplierInvoiceCreatePage.tsx:394-412`; `PageHeader.tsx:22-26` |
| V3 | Major | All inputs/selects/labels/buttons hand-built with pasted token classes, bypassing `Input`/`Select`/`FormField`/`Button` atoms (which add error/success props, forwardRef, disabled semantics) | e.g. `:468-474`, `:481-487`, `:582-593`, `:696-716` |
| V4 | Major | Hand-rolled status-badge color maps duplicated in 2 files, contradicting `StatusBadge`'s stated purpose ("the ONE sanctioned palette for status pills") | `SupplierInvoiceCreatePage.tsx:84-93`; `SupplierInvoiceListPage.tsx:28-86`; `StatusBadge.tsx:5-13` |
| V5 | Major | Two raw `<table>`s built from scratch in one file, ignoring `DataTable` molecule and the `line-items` package (which sibling `QuoteRequestCreatePage.tsx:8` already reuses) | `SupplierInvoiceCreatePage.tsx:658-760`, `:773-852` |
| V6 | Major | Two independent partner-search comboboxes codebase-wide: `PartnerSearchSelect` (DocumentForm + 4 pages) vs `PartnerPicker` (this page + 5 pages). Pre-existing duplication, but the direct cause of the visibly different supplier field | `components/ui/PartnerSearchSelect.tsx`; `components/molecules/pickers/PartnerPicker.tsx` |
| V7 | Minor | No `StickyFormFooter`/`SaveSplitButton`, no Cancel, no Save&Close, no `useUnsavedChangesGuard` | `:403-411` vs `DocumentForm.tsx:642-677`, `:303` |
| V8 | Minor | Sibling list page: hardcoded `divide-gray-200` literals + hand-rolled table instead of `DataTable` | `SupplierInvoiceListPage.tsx:274`, `:318` |
| V9 | Minor | No quick-create-product affordance (`ProductPicker` has no add-new hook; orders/new wires `AddQuickProductModal`) | `DocumentLineEditor.tsx:11` |
| V10 | Minor | Inline empty state hand-rolled; shared `EmptyState` is full-page-styled (`min-h-96`) — needs an inline variant decision | `:766-771`; `EmptyState.tsx` |

Both pages are i18n-compliant and (on the /new page itself) free of hardcoded color literals — the divergence is structural (component reuse), not token discipline.

### Scope finding — NOT one page, one implementor
The hand-rolled pattern spans the whole procurement family, which predates scan-to-document (`project_p2p_entry_points`, `project_procurement_completeness_v1`):
- `SupplierInvoiceListPage.tsx` — hand-rolled header/table/badges
- `StandaloneReceiptPage.tsx` (`/purchases/receipts/new`) — raw selects, useState-per-field
- `QuoteRequestCreatePage.tsx` — partial adopter (uses `PartnerSearchSelect` + `ProductLineSelect` correctly, but useState forms, no PageHeader)

Two competing conventions exist: the DocumentForm convention vs. the procurement-family convention. Remediation should be scoped as a family sweep, not a one-page fix.

### Remediation touch list (files only)
- `SupplierInvoiceCreatePage.tsx` (core rewrite: PageHeader, FormField/atoms, RHF+zod, DataTable/LineItemsTable, StatusBadge, StickyFormFooter)
- `SupplierInvoiceListPage.tsx`, `StandaloneReceiptPage.tsx`, `QuoteRequestCreatePage.tsx` (family alignment)
- `PartnerPicker.tsx` ⇄ `PartnerSearchSelect.tsx` — **consolidation decision required** (pick one, migrate ~5-6 call sites each)
- `EmptyState.tsx` — inline-variant decision

---

## 2. Product picker: no suggestions on focus

### Inventory (three pickers, one broken)
| Component | File | Suggestions on focus? | Used by |
|---|---|---|---|
| `ProductPicker` | `components/molecules/pickers/ProductPicker.tsx` | ✅ (`listEnabled = isOpen && !disabled`, line 138; tested at `ProductPicker.test.tsx:197-201`) | SupplierInvoiceCreatePage (manual mode), AppointmentFormDrawer, WorkOrderCreatePage, BundleComponentFormModal, LineMappingTable, TransferOwnershipModal |
| `ProductLineSelect` | `components/molecules/line-items/ProductLineSelect.tsx` | ✅ (enabled on `isOpen`, line 59) | QuoteRequestCreatePage, RecipeLineEditor, ModifierGroupFormPage, BatchForm |
| `LineItemEntryBar` | `components/molecules/line-items/LineItemEntryBar.tsx` | ❌ **fetch gated on `trimmedQuery !== ''` (line 77) AND dropdown render gated the same way (line 247)** | `DocumentLineEditor` → ALL document types (quotes, sales orders, invoices, POs, credit/delivery/return notes) + `CreateStockTransferPage`, `CreateCountingPage` |

### Root cause
- **Confirmed primary**: `LineItemEntryBar.tsx:77` + `:247`. `onFocus` only opens the dropdown state (`:215-217`); with empty input, the `GET /products` query never fires and the listbox JSX is not rendered at all. Purely client-side — the backend `ProductController::index` (`apps/api/.../ProductController.php:79-200`) treats `search` as optional and returns the first page sorted by name.
- **Supplier-invoice nuance**: its manual mode uses the *good* `ProductPicker`, but with default `productType='part'` (`ProductPicker.tsx:88`, not overridden at `SupplierInvoiceCreatePage.tsx:680-693`) — service/consumable products are silently hidden. And the receipt-reconciliation mode (`?entry=receipts`) has no picker at all (lines derive from the receipt). Either could read as "no suggestions" during testing.

### Fix shape (not implemented)
- Relax both gates in `LineItemEntryBar.tsx` to match the other two pickers (isOpen-only). One shared-component change; benefits every document page + stock transfer + counting.
- Design note: the bar also carries barcode-scan semantics (`useBarcodeScanner`) — if "type to search" was deliberate, this is a UX decision, not a pure bug fix. Recommendation: make it a combobox with initial suggestions like its siblings.
- Optional follow-up: pass/loosen `productType` on the supplier-invoice `ProductPicker` call site.

---

## 3. Line-items table density

### Current state — three implementations, zero truncation
1. **`DocumentLineEditor`** (`features/documents/components/DocumentLineEditor.tsx`) — POs + all sales docs. Description is a dedicated always-visible column (`:503-531`) with **no headerClassName/width constraint** (`:504-505`), no truncate/line-clamp; article column has `min-w-56` (`:483-485`); table is auto-layout (`LineItemsTable.tsx:86`), row height content-driven (`px-4 py-3`, `LineItemsTable.tsx:135`). Description + notes sub-row stack unbounded.
2. **`DocumentLines`** (`features/documents/components/DocumentLines.tsx:97-111`) — read-only duplicate used by detail pages (PO/Invoice/SalesOrder/ReturnNote). Structurally distinct markup (incl. hardcoded `bg-gray-500`). `QuoteDetailPage.tsx` renders yet another own table — a further fork.
3. **`SupplierInvoiceCreatePage`** receipt-mode table (`:774-851`) — bespoke `<td>` at `:805-810`, no width class.

**PDF is independent**: `apps/api/resources/views/documents/components/line_items.blade.php:5-12` has fixed percentage widths (Description 40%) and its own CSS — screen changes don't affect print.

### Existing primitives
- `truncate` widely used elsewhere (ProductSelector, CartLineItem, AdminLayout) — never in these tables
- `line-clamp-2` name+muted-description pattern is an established convention (ProductListPage:593, MenuListPage:154, BundleList:60, ServiceListPage:350)
- **Expandable row already built**: `LineItemsTable` `renderLineDetail` prop (`LineItemsTable.tsx:34,107,141-147`), currently used for purchase-bonus sub-row (`DocumentLineEditor.tsx:832-843`)
- **Missing**: Tooltip component, Popover, column-visibility mechanism, density toggle (none exist anywhere)

### Options matrix (industry: Odoo, QuickBooks, Xero, Zoho, SAP/Dynamics)
| # | Pattern | Fit | Effort |
|---|---|---|---|
| 1 | Name single-line, description behind expand icon/row | Best-fit — `renderLineDetail` already exists | M |
| 2 | Description as muted `line-clamp-2` sub-line under name (merge columns) | Matches existing codebase convention | S–M |
| 3 | Column hidden by default + column-config menu | No mechanism exists — net-new primitive | L |
| 4 | Fixed row height, `truncate` + tooltip | Native `title=` stopgap OK (precedent `MonitoringPage.tsx:129`); real Tooltip = new component | S / L |
| 5 | Global density toggle | Over-engineering for the stated problem | L |
| 6 | Description editable only in drawer | Regresses the fast inline click-to-edit UX | M–L |

**Recommendation: #2 + #1** — kill the separate description column, render description as a clamped muted sub-line under the product name (article column already capped at `min-w-56`), reuse `renderLineDetail` expansion for the long-description case. No new primitives, no backend/PDF change.

### Touch list
`DocumentLineEditor.tsx` (:479-531, :832-843), `DesignationCell.tsx`, `NotesCell.tsx`, `DocumentLines.tsx` (fix in parallel or unify first), `QuoteDetailPage.tsx` (fork — migrate to `DocumentLines`), `SupplierInvoiceCreatePage.tsx` (:774-851 independent), `LineItemsTable.tsx` (only if generic expand affordance added). PDF blade untouched.

---

## 4. Editable designation + document configurator

### Requirement 1: editable per-line designation — ALREADY BUILT ✅
- **Data model = snapshot, not join**: `document_lines` is a real table (`2025_11_30_080001_create_document_lines_table.php:13-33`) with its own `description text` column, copied from the product at creation. Plus `designation_default_snapshot` (string 500, `2026_04_24_212257_...php:14-16`) — the reference copy used to detect/undo an override. `DocumentLineData.php:20,31,54` passes both through.
- **Write path**: `lines.*.description` is already a required, client-supplied field (`CreateDocumentRequest.php:116`, `UpdateDocumentRequest.php:94`); server strips client `designation_default_snapshot` and recomputes it (`CreateDocumentRequest.php:174-176`). Nothing overwrites `description`. Conversions (quote→order→invoice/delivery-note, credit notes) copy the snapshot forward, never recompute (`CopiesDocumentData.php:128`, converter classes, `CreditNoteService.php:212,360,484`).
- **Read paths**: UI renders `line.description || line.product_name` with `DesignationCell` (editable + "overridden" indicator + reset-to-default) when flag on (`DocumentLineEditor.tsx:504-519`, `DesignationCell.tsx:38,119-120`); PDF renders `$line->description` directly (`line_items.blade.php:27`) — override flows automatically.
- **Flag**: `FEATURE_DOCUMENT_LINE_DESIGNATION_OVERRIDE`, default false (`config/features.php:32-35` → `CompanyConfigController.php:92` → `useLineDesignationFeature.ts:5`). Env-level, NOT per-company — no settings UI toggle exists.
- **Fiscal safety**: hash chain is header-level only — `SHA256(prev|number|date|total|currency)` (`DocumentPostingService.php:33`); lines not hashed. DB immutability trigger guards `documents` header columns only (`2025_12_11_054716_...php:32-49`) — **no DB guard on `document_lines`**; sealed-document protection is application-layer in every controller `update()` (`InvoiceController.php:344-350` et al., `Document.php:558-577`). ⚠️ Any future line-only PATCH endpoint must re-implement this check.
- **Remaining work (S)**: rollout decision (flip env flag vs promote to per-company DB setting); verify Factur-X line-item text reflects override (reviewed section only builds tax totals — `FacturXService.php:227-243`); confirm quote/order PDF templates use the shared `line_items.blade.php` partial.

### Requirement 2: full configurator — net-new, L–XL
- No column-visibility/editability precedent anywhere in FE (`grep columnVisibility/ColumnPreferences` → nothing). Closest precedent: POS Receipt Settings tab — flat booleans per company (`ReceiptSettingsTab.tsx:37-58`, `2026_03_24_400000_add_receipt_customization_columns_to_companies.php`).
- Would need: new company-scoped per-doc-type column-config entity (typed table, not JSONB blob, per repo rules); config-driven FormRequest validation (today static arrays across 7 doc-type controllers); data-driven `DocumentLineEditor` columns; new "Documents" settings section (none exists — `SettingsPage.tsx:30-99`); shared-types transform.
- Risk areas: dynamic validation architecture; guaranteeing `product_id` never editable regardless of config; 7-controller parity; **config-versioning** (documents issued under an old config — snapshot config at creation like designation, or live?).

### Open product questions for the owner
1. Ship requirement 1 by flipping the env flag, or first promote it to a real per-company Settings toggle?
2. Does the designation override need to reach Factur-X/e-invoicing XML, or is UI+PDF enough?
3. Configurator: should "editable" ever cover qty/price/tax (big validation-architecture impact) or designation/description/notes only?
4. Should column config be snapshotted at document creation so setting changes don't retroactively alter issued documents' reprints?
5. Scope per document *type* only, or per print *template*?

---

## Suggested implementation waves (pending owner approval — NOT started)

1. **Wave 1 (quick wins, independent)**: LineItemEntryBar focus-suggestions fix (S) + supplier-invoice ProductPicker `productType` check + table density option #2+#1 (S–M).
2. **Wave 2 (rollout)**: designation override — decide flag strategy, verify Factur-X + all PDF templates, enable (S).
3. **Wave 3 (family sweep)**: procurement pages design-system alignment (SupplierInvoiceCreate/List, StandaloneReceipt, QuoteRequest) — gated on the PartnerPicker⇄PartnerSearchSelect consolidation decision (M–L).
4. **Wave 4 (separate spec cycle)**: document configurator — needs brainstorm→spec→plan with the 5 open questions answered first (L–XL).
