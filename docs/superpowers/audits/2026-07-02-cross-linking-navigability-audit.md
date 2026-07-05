# Cross-Linking & Navigability Audit — "Linked Screens" Principle

**Date:** 2026-07-02 · **Scope:** `apps/web` (code audit, no browser) · **Status:** NOT committed (working audit)

**The principle (owner):** the user should never hunt for data they just touched. Every entity reference rendered anywhere must be a link to that entity's page, and every entity detail page must surface its related records in tabs/sections. Headline failure: after completing a stock transfer, the summary shows product names as plain text.

All paths below are relative to `apps/web/src/` unless absolute.

---

## A. WHERE THE PRINCIPLE SHOULD APPLY — the audit frame

Built from `routes/index.tsx` + `features/` directories. Every surface below renders references to at least one of: product/variant, partner (customer/supplier), document, payment/expense, stock transfer, goods receipt, stock movement, batch/lot, location, user.

### A.1 Entity detail pages (must have outbound links on references AND related-records tabs)

| # | Surface | Route | Component |
|---|---------|-------|-----------|
| 1 | Product detail | `/inventory/products/:id` | `features/inventory/ProductDetailPage.tsx` |
| 2 | Partner detail (customer & supplier share it) | `/sales/customers/:id`, `/purchases/suppliers/:id` | `features/partners/PartnerDetailPage.tsx` |
| 3 | Quote detail | `/sales/quotes/:id` | `features/documents/quotes/QuoteDetailPage.tsx` |
| 4 | Sales order detail | `/sales/orders/:id` | `features/documents/sales-orders/SalesOrderDetailPage.tsx` |
| 5 | Invoice detail | `/sales/invoices/:id` | `features/documents/invoices/InvoiceDetailPage.tsx` |
| 6 | Credit note detail | `/sales/credit-notes/:id` | `features/documents/credit-notes/CreditNoteDetailPage.tsx` |
| 7 | Return note detail | `/sales/return-notes/:id`, `/inventory/return-notes/:id` | `features/documents/return-notes/ReturnNoteDetailPage.tsx` |
| 8 | Purchase order detail | `/purchases/orders/:id` | `features/documents/purchase-orders/PurchaseOrderDetailPage.tsx` |
| 9 | Delivery note detail | `/inventory/delivery-notes/:id` | `features/documents/delivery-notes/DeliveryNoteDetailPage.tsx` |
| 10 | Supplier invoice detail | `/purchases/supplier-invoices/:id` | `features/purchases/supplier-invoices/SupplierInvoiceDetailPage.tsx` |
| 11 | Payment detail | `/treasury/payments/:id` | `features/treasury/PaymentDetailPage.tsx` |
| 12 | Instrument detail | `/treasury/instruments/:id` | `features/treasury/InstrumentDetailPage.tsx` |
| 13 | Repository detail | `/treasury/repositories/:id` | `features/treasury/RepositoryDetailPage.tsx` |
| 14 | Withholding certificate detail | `/treasury/withholding-certificates/:id` | `features/withholding/WithholdingCertificateDetail.tsx` |
| 15 | Expense detail | `/expenses/:id/view` | `features/expenses/pages/ExpenseDetailPage.tsx` |
| 16 | Journal entry detail | `/finance/journal-entries/:id` | `features/finance/pages/JournalEntryDetailPage.tsx` |
| 17 | Stock transfer detail | `/inventory/stock-transfers/:id` | `features/stock-transfers/pages/StockTransferDetailPage.tsx` |
| 18 | Batch detail | `/inventory/batches/:uuid` | `features/batches/pages/BatchDetailPage.tsx` |
| 19 | Counting detail / review / report | `/inventory/counting/:id[…]` | `features/inventory-counting/pages/CountingDetailPage.tsx`, `CountingReviewPage.tsx`, `DiscrepancyReportPage.tsx` |
| 20 | Vehicle detail | `/vehicles/:id` | `features/vehicles/VehicleDetailPage.tsx` |
| 21 | Service detail | `/services/:id` | `features/services/ServiceDetailPage.tsx` |
| 22 | Work order detail | `/workshop/work-orders/:id` | `features/workshop-work-orders/pages/WorkOrderDetailPage.tsx` |
| 23 | Technician detail | `/workshop/technicians/:id` | `features/workshop-technicians/pages/TechnicianDetailPage.tsx` |
| 24 | Appointment detail | `/scheduling/appointments/:id` | `features/scheduling/pages/AppointmentDetailPage.tsx` |
| 25 | Price list detail | `/pricing/price-lists/:id` | `features/pricing/PriceListDetailPage.tsx` |
| 26 | Loyalty program / member detail | `/pos/loyalty/programs/:id`, `/pos/loyalty/members/:id` | `features/loyalty` |
| 27 | Voucher detail | `/pos/vouchers/:id` | `features/vouchers/pages/VoucherDetailPage.tsx` |
| 28 | Z-report detail | `/pos/z-reports/:zNumber` | `features/pos/pages/ZReportDetailPage/` |
| 29 | CRM contact detail | `/crm/contacts/:id` | `features/crm/pages/ContactDetailPage.tsx` |
| 30 | Workshop bundle detail | `/workshop/bundles/:id` | `features/workshop-bundles/pages/BundleDetailPage.tsx` |

**Entities with NO detail page at all (reverse-frame gaps, see C.4):** Location (settings modal grid only, `routes/index.tsx:1959`), Goods receipt (list only — rows point at the PO), User (settings grid only), Stock movement (row-level, acceptable — but its source document must be linked), Category (settings-style page).

### A.2 List pages rendering entity references

- `features/documents/DocumentListPage.tsx` (shared by quotes/orders/invoices/credit-notes/POs/delivery-notes/return-notes) — doc number, partner
- `features/partners/PartnerListPage.tsx` — partner name
- `features/inventory/ProductListPage.tsx` — product, category
- `features/inventory/StockLevelsPage.tsx` — product, location
- `features/inventory/StockMovementsPage.tsx` — product, location, source document (reference), user
- `features/stock-transfers/pages/StockTransferListPage.tsx` — transfer #, source/dest locations
- `features/purchases/GoodsReceiptListPage.tsx` — PO #, supplier, product line previews
- `features/purchases/supplier-invoices/SupplierInvoiceListPage.tsx` — invoice #, supplier
- `features/treasury/PaymentListPage.tsx` — payment #, partner, method
- `features/treasury/InstrumentListPage.tsx`, `RepositoryListPage.tsx`, `BankReconciliationPage.tsx`
- `features/expenses/pages/ExpenseListPage.tsx` (via `ExpenseCard`) — expense #, vendor, category
- `features/finance/pages/JournalEntryListPage.tsx` — entry #, (source doc)
- `features/finance/pages/GeneralLedgerPage.tsx` (via `components/LedgerTable.tsx`) — account, entry #, source
- `features/finance/pages/AgedReceivablesPage.tsx` / `AgedPayablesPage.tsx` — partner, invoices
- `features/finance/pages/ChartOfAccountsPage.tsx` — account → ledger
- `features/batches/pages/BatchListPage.tsx` — batch #, product
- `features/inventory-counting/pages/*` — products, locations, users
- `features/withholding/WithholdingCertificatesList.tsx`, `pages/SalesWithholdingTrackingPage.tsx` — partner, invoice
- `features/vehicles/VehicleListPage.tsx` — vehicle, owner (partner)
- `features/pos/pages/*` (OrdersPage, ShiftHistoryPage, ZReportListPage, AnalyticsDashboardPage), `pages/POS/POSTransactions.tsx` — receipts, customers, users/cashiers, terminals
- `features/vouchers/components/LedgerHistoryTable.tsx`, `ProvenanceSection.tsx` — source receipts
- `features/channels/*` (ChannelOrdersPage, EcommerceOrdersPage, ChannelProductMappingPage) — orders, products
- `features/customer-history-audit/` — customer, receipts

### A.3 Summary / confirmation / dashboard surfaces

- `features/dashboard/Dashboard.tsx` — recent documents, recent payments, low stock
- `features/owner-dashboard/` widgets — `TopSkusWidget.tsx`, `LowStockAlertsList.tsx`, `SalesByLocationChart.tsx`
- `features/finance/components/FinanceWidget.tsx`
- Create-flow endings + toasts: `features/stock-transfers/pages/CreateStockTransferPage.tsx`, `features/documents/CreateCreditNotePage.tsx`, `CreateReturnNotePage.tsx`, `DeliveryNoteConsolidationPage.tsx`, `features/treasury/PaymentForm.tsx`, `DocumentForm.tsx` — "view created X" affordance
- Detail-page related panels: `features/documents/components/RelatedDocumentsTab.tsx`, `RelatedDocumentsPanel.tsx`, `PaymentHistorySection`, `features/inventory/components/ProductMovementsTab.tsx`, `ProductDocumentsTab.tsx`, `features/products/editor/components/RelatedOperationsRail.tsx`, `features/vehicles/components/organisms/VehiclesTab.tsx`

---

## B. WHERE IT IS RESPECTED TODAY

### B.1 Forward links (entity reference → entity page)

**List pages: primary identifier → own detail page is the established pattern.**
- Document number + partner (type-aware customer/supplier routing) — `features/documents/DocumentListPage.tsx:305-312` and `:346-361`
- Partner name — `features/partners/PartnerListPage.tsx:332-337` (plus row actions new quote/invoice/view `:387-408`)
- Product name (list `:270-275`, view action `:330-336`, whole grid card `:581-585`) — `features/inventory/ProductListPage.tsx`
- Product in stock levels — `features/inventory/StockLevelsPage.tsx:272-277`
- Product in stock movements — `features/inventory/StockMovementsPage.tsx:224-231`
- Transfer number — `features/stock-transfers/pages/StockTransferListPage.tsx:114-119`
- Batch number — `features/batches/pages/BatchListPage.tsx:173-178`
- PO number in goods receipts — `features/purchases/GoodsReceiptListPage.tsx:311-316`
- Payment number + type-aware partner — `features/treasury/PaymentListPage.tsx:97-104`, `:109-125`
- Expense number — `features/expenses/components/organisms/ExpenseCard.tsx:49-58`

**Detail pages with real cross-links:**
- Payment detail: allocations → `/sales/invoices/:id` (`features/treasury/PaymentDetailPage.tsx:517-522`), type-aware partner link (`:470-479`)
- Repository detail: transaction payment # (`features/treasury/RepositoryDetailPage.tsx:421-426`), partner (`:429-438`), allocations → invoices (`:457-466`)
- Credit note → original invoice (`features/documents/credit-notes/CreditNoteDetailPage.tsx:186-191`)
- Supplier invoice → source PO (`features/purchases/supplier-invoices/SupplierInvoiceDetailPage.tsx:246-255`, `data-testid="link-source-po"`)
- Batch detail → product (`features/batches/pages/BatchDetailPage.tsx:156-161`)
- Document chain: `features/documents/components/RelatedDocumentsTab.tsx:67-74` — every ancestor/descendant card is a `<Link>` (used by all 6 document detail pages)

**Dashboards:**
- Main dashboard recent documents → `getDocumentRoute(doc.type, doc.id)` (`features/dashboard/Dashboard.tsx:334-336`); recent payments → `/treasury/payments/:id` (`:374-376`)

**Create-flow done right:** `features/stock-transfers/pages/CreateStockTransferPage.tsx:480-481` — success toast then `navigate('/inventory/stock-transfers/${result.id}')` lands the user on the created transfer.

### B.2 Reverse direction (detail pages with related-records tabs)

- **ProductDetailPage** (`features/inventory/ProductDetailPage.tsx:224-434`): Details / Movements / Financial Operations tabs; `ProductMovementsTab` and `ProductDocumentsTab` both server-scoped by `product_id` (see D). `ProductDocumentsTab` rows link to document detail (`ProductDocumentsTab.tsx:275-280`) and partner (`:293-303`).
- **PartnerDetailPage** (`features/partners/PartnerDetailPage.tsx:295-315`): Overview / Documents / Payments / Vehicles (gated) / Deposits (gated). Documents tab rows → type-routed detail (`:527-548`); Payments tab rows → `/treasury/payments/:id` (`:616-621`).
- **Document detail pages**: shared `RelatedDocumentsTab` chain (quote→order→invoice→delivery/credit ancestry), e.g. wired at `InvoiceDetailPage.tsx:509`, `PurchaseOrderDetailPage.tsx:473`, `SalesOrderDetailPage.tsx:453`.
- **BatchDetailPage**: "Stock by Location" section (`features/batches/pages/BatchDetailPage.tsx:235-302`).
- **RepositoryDetailPage**: transactions list with links (above).
- **VehicleDetailPage**: Ownership timeline + mileage log (`features/vehicles/VehicleDetailPage.tsx:254`, `:336`) — but see C.4.

---

## C. WHERE IT IS NOT RESPECTED / MISSING

### C.1 Priority gaps (owner-named surfaces)

1. **Stock transfer detail — the headline failure, confirmed.** `features/stock-transfers/pages/StockTransferDetailPage.tsx`
   - Line product name plain text: `:203` `{line.product_name ?? '—'}`; variant `:205`; SKU `:209`. No link to `/inventory/products/:id`.
   - Summary: source location `:124`, destination `:125-128`, initiated-by `:134`, completed-by `:141-144`, cancelled-by `:150-153` — all plain `SummaryRow` text (`:288`).
   - Only link on the page is the back-breadcrumb (`:74-80`). No batch/lot column on lines.
2. **Stock movements list — source document not linked.** `features/inventory/StockMovementsPage.tsx:270-272` renders `movement.reference` as a plain `<span title=…>`; location `:237` and user `:278` also plain. (Product IS linked, `:224-231`.)
3. **Goods receipts — supplier and product lines plain.** `features/purchases/GoodsReceiptListPage.tsx:336-339` (supplier `<Building2/>{po.partner_name}`), `:382` (`{line.product_name ?? line.description}`).
4. **Payment allocations** — actually linked (B.1) ✅, **but** route is hardcoded `/sales/invoices/:id` for ALL allocations (`PaymentDetailPage.tsx:518`, `RepositoryDetailPage.tsx:460`) — supplier-side documents would deep-link to the wrong module. `RepositoryDetailPage.tsx:431` also hardcodes `/sales/customers/` with no supplier branch.
5. **Journal entry detail — worst finance gap.** `features/finance/pages/JournalEntryDetailPage.tsx`: `source_type`/`source_id` exist on the type (`features/finance/types.ts:135-136,157-158`) but are **never rendered** — no link to the originating invoice/payment/pos_receipt. Account rows plain text (`:207-215`), no ledger link.
6. **Dashboard widgets (owner dashboard).** `features/owner-dashboard/TopSkusWidget.tsx:47-50` — `product_id` in hand (used as React key) yet `product_name` rendered as plain `<span>`; `LowStockAlertsList.tsx` — zero links.
7. **Toast deep links after create.** Only stock transfers navigate to the created record. `features/documents/CreateCreditNotePage.tsx:236,246` and `CreateReturnNotePage.tsx:237,242` toast then dump the user on the **list** page. All `toast.success` calls audited are plain strings (sonner) — no "View created X" action anywhere.

### C.2 Document detail pages — systemic plain-text lines & partner

Every document detail page renders **line items** and the **partner** as plain text:

| Page | Line items (plain) | Partner (plain) |
|---|---|---|
| InvoiceDetailPage | `:418-436` (desc `:420-424`) | `:364-366` |
| QuoteDetailPage | `:329-347` | `:275-277` |
| SalesOrderDetailPage | `:371-394` | `:312-314` |
| PurchaseOrderDetailPage | `:363-386` | `:319-321` |
| DeliveryNoteDetailPage | `:225-243` | `:166` |
| CreditNoteDetailPage | `:262-280` | `:203` |

Plus: `PaymentHistorySection` inside document detail pages (invoice `:517`) has **no links** to `/treasury/payments/:id` — inconsistent with PartnerDetailPage's payments tab which does link. `DocumentLineEditor.tsx:366,383,391` shows the picked product as plain text in forms (lower priority).

**Broken link found:** `features/documents/components/RelatedDocumentsTab.tsx:34` maps `delivery_note` → `/sales/delivery-notes`, but the registered route is `/inventory/delivery-notes/:id` (`routes/index.tsx:1035-1044`). Delivery-note chain links 404 to the catch-all redirect.

### C.3 Treasury / expenses / finance plain-text inventory

- `InstrumentDetailPage.tsx:327-329` partner plain; `:363-374` repository plain; no back-link to originating payment.
- `PaymentDetailPage.tsx` — instrument (`:57`) and repository (`:58`) ids exist on the type but are not rendered/linked.
- `ExpenseDetailPage.tsx:171-173` vendor plain (vendor is metadata text, not a partner FK — needs data-model attention to link); `:187-189` category; no related payment or journal-entry link.
- `ExpenseCard.tsx:59-63` vendor, `:88-95` category plain.
- `JournalEntryListPage.tsx:44-48` entry number plain `<span class="font-mono">` (view via button `:74-83` exists, so number itself should also link).
- `finance/components/LedgerTable.tsx:31` entry number plain, `:37-42` account plain; no source column.
- `AgedReceivablesPage.tsx:117-120` / `AgedPayablesPage.tsx:110-113` — partner plain; **no react-router import at all** in either file.
- `ChartOfAccountsPage.tsx:53-58` — account rows open an edit modal only; no "view in ledger" link.
- `SupplierInvoiceDetailPage.tsx:148-150` supplier plain; 3-way-match table `:335-360` has no goods-receipt link; lines `:284-299` carry no product link; "Record Payment" is a placeholder dialog (`:443-475`).

### C.4 Reverse gaps — missing related-records tabs / missing pages

1. **Location detail page does not exist.** `settings/locations` is a modal-CRUD grid (`features/settings/LocationsPage.tsx`); `features/location*/` contain only selectors/providers. No "stock at this location" / "movements at this location" view, even though the endpoints already accept `location_id` (`ProductMovementsTab.tsx:128-130`). Every plain-text location name in C.1-C.3 has **nowhere to link to** until this exists.
2. **BatchDetailPage lacks a movements ledger** — stock-by-location only (`:235-302`); no batch movement history despite the batch being an inventory-critical lot entity (parapharmacy vertical).
3. **VehicleDetailPage** — ownership + mileage only; no work orders, no documents, no service history links (automotive vertical's core entity).
4. **PartnerDetailPage** — no statement tab, deposits tab rows all plain text (`:709-720`); supplier context has no goods-receipts/supplier-invoices tab (documents tab covers POs only via `getDocumentPath`, `:527-538`).
5. **Goods receipt has no detail page** — list rows resolve to the PO; a GR-IR-era receipt entity with no canonical URL means nothing else can link TO a receipt (supplier invoice 3-way match, movements).
6. **ProductDetailPage** — no Batches tab (batch list is only reachable via the global `/inventory/batches` filter-less list), no Transfers involvement view; stock levels are inline in Details (`:306-311`) which is fine.
7. **User activity** — no user detail/activity page (low priority; audit exists at `settings/audit/customer-history` for customers only).

---

## D. QUALITY OF EXISTING IMPLEMENTATIONS

### D.1 ProductMovementsTab (`features/inventory/components/ProductMovementsTab.tsx`)
- ✅ Server-side scoping: `product_id` appended to `/stock-movements` (`:121-134`, query key `:119`) — tenant/company scoping handled by the api client + backend `CompanyContext`, consistent with convention 01.
- ⚠️ Hybrid location filter: single location goes server-side (`:128-130`) but **multi-location selection is client-filtered** (`:141-147`, acknowledged in comments `:124-127`) — wrong counts if server ever paginates.
- ❌ **No pagination** — fetches full array, `movements.map` (`:272`). Unbounded for high-volume SKUs.
- ✅ Loading (`:169-175`), error (`:177-183`), empty (`:233-242`) states; i18n via `t()` throughout.
- ⚠️ **Source-document links are search-links, not detail links**: `getDocumentLink` (`:88-109`) parses the reference prefix (PO-/INV-/SO-/QT-/CN-/DN-) and links to `${listPath}?search=<number>` (`:104`) because movements store the document *number*, not the id (admitted `:101-102`). Root fix: API should return `source_document_id` + `source_document_type` on movement rows.

### D.2 ProductDocumentsTab (`features/inventory/components/ProductDocumentsTab.tsx`)
- ✅ Server-side scoping: `/documents?product_id=${productId}` (`:130-132`).
- ❌ **No pagination** — `meta.total` exists on the response type (`:48-50`) but is unused; renders all rows (`:268`).
- ✅ Loading (`:197-203`), error (`:205-211`), empty (`:226-235`), i18n.
- ✅ Proper detail links by id (`:275-280`) + partner link (`:293-303`). **But** the partner link targets `/partners/${doc.partner_id}` which only works via the legacy redirect (`routes/index.tsx:2777-2778` → `/sales/customers` **list**, not the detail page — the `/partners/*` wildcard redirect drops the id). Effectively a broken deep link; should be `/sales/customers/:id` (type-aware).

### D.3 ProductDetailPage tabs (`features/inventory/ProductDetailPage.tsx`)
- ❌ **No URL deep-linking of the active tab**: `<Tabs defaultValue="details">` (`:224`), no `useSearchParams`, no controlled `value`. `/inventory/products/:id?tab=movements` does not exist — so no other surface can deep-link "this product's movements", which is exactly the owner's stock-transfer verification journey.

### D.4 PartnerDetailPage tabs (`features/partners/PartnerDetailPage.tsx`)
- Tabs inline (`:295-315`); same **no `?tab=` deep-linking** issue.
- Documents tab `getDocumentPath` (`:527-538`) collapses unknown types to `/sales/invoices/:id` — delivery/return/credit notes routed wrong; hardcoded `text-blue-600` (`:543-548`) predates design tokens (rule 18 applies when touched).
- Deposits tab: no links (`:709-720`); pagination/empty states present for documents/payments tabs.

### D.5 RelatedDocumentsTab (`features/documents/components/RelatedDocumentsTab.tsx`)
- ✅ Chain fetched server-side per document; every node is a `<Link>` (`:67-74`).
- ❌ Route-map bug: `delivery_note → /sales/delivery-notes` (`:34`) vs registered `/inventory/delivery-notes` — broken navigation for the delivery-note leg of every chain.

### D.6 Allocation links (treasury)
- ✅ Applied server-side, loading states fine.
- ❌ Not type-aware: `/sales/invoices/` hardcoded for allocations (`PaymentDetailPage.tsx:518`, `RepositoryDetailPage.tsx:460`); `/sales/customers/` hardcoded for repository transaction partners (`RepositoryDetailPage.tsx:431`). Supplier payments mis-deep-link.

### D.7 Cross-cutting
- Link styling is inconsistent: `textColors.brand` (DocumentListPage) vs hardcoded `text-blue-600` (PartnerDetailPage `:543`, PartnerListPage `:332-337`) vs `font-medium hover:underline` — no shared component enforces token compliance or route correctness.
- Route strings are hand-built with template literals in ~40 files; the two broken links found (D.2, D.5) are the predictable result.

---

## Implementation plan

### Phase 1 — Quick wins (pure `<Link>` wraps on existing ids; ~1h batches)

Each batch is one PR, mechanical, testable with a Vitest render + `getByRole('link')` assertion:

1. **Stock transfer detail** (owner's headline): line product → `/inventory/products/:id` (id exists on line), locations/users to follow once targets exist. *~1h*
2. **Fix the two broken links**: `RelatedDocumentsTab.tsx:34` delivery-note route; `ProductDocumentsTab.tsx` partner link → `/sales/customers/:id`. *~0.5h*
3. **Type-aware allocation/partner routes** in `PaymentDetailPage`, `RepositoryDetailPage` (branch on partner/document type as `PaymentListPage.tsx:109-125` already does). *~1h*
4. **Document detail pages ×6**: partner name → partner detail; line product → product detail (line items carry `product_id`; where only description exists, skip). *~2-3h*
5. **Goods receipts list**: supplier → partner detail; line preview product → product detail. *~0.5h*
6. **Owner-dashboard widgets**: TopSkus + LowStock product links (`product_id` already present). *~0.5h*
7. **Finance links where ids exist**: `JournalEntryListPage` entry number → detail; `AgedReceivables/Payables` partner → detail; `SupplierInvoiceDetailPage` supplier → detail. *~1h*
8. **PaymentHistorySection** rows → `/treasury/payments/:id`. *~0.5h*
9. **Create-flow landings**: CreateCreditNotePage / CreateReturnNotePage navigate to the created record (copy CreateStockTransferPage `:480-481`); adopt sonner `action:` "View" button as the toast standard. *~1h*

### Phase 2 — Structural (new endpoints / tabs / pages)

1. **`?tab=` deep-linking** on ProductDetailPage + PartnerDetailPage (controlled Tabs + `useSearchParams`); then StockTransferDetail can link "product → movements" directly. *~0.5d*
2. **Movement → source document by id**: extend `/stock-movements` response with `source_document_type`/`source_document_id`; link in `StockMovementsPage` and `ProductMovementsTab` (replaces the search-link hack). Backend + FE. *~1d*
3. **Journal entry provenance**: render `source_type`/`source_id` as a typed link (invoice/payment/pos_receipt/expense map); account rows → ledger filtered by account (`/finance/ledger?account_id=…`). *~1d*
4. **Location detail page** `/settings/locations/:id` (or `/inventory/locations/:id`): stock at location + movements at location + transfers in/out — endpoints largely exist (`location_id` params). Unblocks every location plain-text gap. *~1.5-2d*
5. **Batch movements ledger** on BatchDetailPage (needs `batch_id` filter on movements endpoint). *~1d*
6. **Pagination** for ProductMovementsTab / ProductDocumentsTab (meta already returned) + push multi-location filter server-side. *~1d*
7. **Vehicle related records**: work orders + documents tabs on VehicleDetailPage (automotive vertical). *~1-1.5d*
8. **Partner supplier-side tabs**: goods receipts + supplier invoices tab when partner is supplier; statement tab later. *~1d*
9. **Goods receipt canonical URL** — depends on GR-IR umbrella; register `/purchases/receipts/:id` so supplier-invoice 3-way match and movements can link to it. *(size with GR-IR work)*

### Proposed reusable convention

1. **One `EntityLink` organism** — `components/molecules/EntityLink.tsx`:
   ```tsx
   <EntityLink type="product" id={line.product_id} tab="movements">{line.product_name}</EntityLink>
   ```
   - Central `entityRoutes: Record<EntityType, (id: string, opts?) => string>` map — the ONLY place route templates live (kills the `/sales/delivery-notes` class of bug; unit-test the map against `routes/index.tsx` paths).
   - Type-aware branching built in (`partner` resolves customer vs supplier from a `partnerType` prop; `document` resolves from `DocumentType`).
   - Renders plain `<span>` when `id` is null/permission-denied (no broken links); token-compliant styling (`textColors.brand`, hover underline) in one place; RTL-safe.
2. **Related-records tab pattern** — a `RelatedRecordsTab<T>` template composing the existing `DataTable` molecule (`components/molecules/DataTable/DataTable.tsx` — per-column `render` makes cell-level links natural): props = query hook (must accept the scoping id server-side), column defs using `EntityLink`, built-in pagination via `meta`, `EmptyState`, loading skeleton. ProductDocumentsTab is the closest existing shape — extract it.
3. **Tabs in the URL** — standardize controlled `Tabs value/onValueChange` bound to `useSearchParams` `tab=` on every detail page; `EntityLink`'s `tab` prop emits it.
4. **Create-flow rule** — after any create: `navigate` to the created record's detail, or sonner toast with `action: { label: t('common:actions.view'), onClick: () => navigate(...) }` when staying put is intentional. Add to `docs/conventions/02-NAVIGATION-ROUTING.md`.
5. **Enforcement** — ESLint restriction on `to={\`/sales/...` -style literal entity routes outside `entityRoutes.ts` (same guard style as `no-parsefloat-on-money`); new-frontend-feature scaffold emits `EntityLink` usage by default.

### Effort estimates (rough)

| Bucket | Effort |
|---|---|
| Phase 1 quick wins (9 batches) | ~2 dev-days total |
| EntityLink + entityRoutes + ESLint guard | ~1 dev-day |
| Phase 2 structural (items 1-8) | ~7-9 dev-days |
| Goods receipt page | with GR-IR umbrella |
| **Total to full principle compliance (ex-GR)** | **~10-12 dev-days** |

---

## Findings index (counts)

- **A:** 30 entity detail surfaces + ~25 list surfaces + 8 summary/dashboard surfaces in frame; 5 entities lack any detail page (location, goods receipt, user, category, movement-source).
- **B:** 10 list pages link primary identifiers; 6 detail pages have real cross-links; 6 related-records tab implementations exist.
- **C:** 7 priority forward gaps, 6 document detail pages with systemic plain-text lines/partner, ~14 treasury/finance plain-text sites, 7 reverse gaps, 2 broken links.
- **D:** reference tabs are server-scoped with loading/empty/i18n ✅ but no pagination, no `?tab=` deep-links, search-links instead of id-links for movement sources, non-type-aware allocation routes, inconsistent link styling.
