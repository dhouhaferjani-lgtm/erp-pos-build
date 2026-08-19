# Component-graph-aware listing re-census

**Pinned base:** `d682b38ec9761a917b9716428091a482745795f6`

**Scope:** `apps/web/src`

**Finding:** CX-4; supersedes the usable numbers for UI-28, UI-29, and UI-34.

## Reproduce

```bash
cd apps/web && node tools/audit-listing-census.mjs
```

The scan is local-only, completed in 1–5 seconds across recorded executor and bridge runs, and always exits 0 because it is analysis tooling rather than a CI ratchet. Its stdout contains the complete 46-page table and all 271 registered route records.

## Method

For comparability, listing discovery intentionally inherits the filename rule from the original audit (`02-design-system-consistency.md:66`): every production TSX file under `src/features/` whose basename ends in `ListPage`, `ListView`, `QueuePage`, or `IndexPage`. This is not an independent proof of the complete listing-page population. The rule discovers 46 pages at this base (44 `ListPage`, two `QueuePage`, and zero `ListView` or `IndexPage` matches); the number is an output, not an allowlist.

For each page the scanner parses TypeScript/JSX, follows rendered feature-local imports whose component role is a list, table, grid, queue, result, filter, or view, and classifies the combined rendered graph. Shared primitives are recorded at their call site but not traversed internally, preventing every `DataTable` caller from inheriting optional behavior it did not request. Every positive verdict records the source file that supplied it.

The component-role filter is deliberately narrow: 43 of 46 rows remain file-local, while only Enrichment Queue, Expenses, and Workshop Bundles traverse one rendered child. A differently named body component is therefore outside this scanner's graph. A conservative secondary-surface cross-check finds 33 additional pages: take route-mounted component names from `src/routes/index.tsx`, intersect them with production feature files containing `<DataTable`, then exclude the inherited cohort and basenames matching `*Detail*`, `*Form*`, or `Create*`. Examples include `StockLevelsPage`, `StockMovementsPage`, `UsersPage`, `RolesPage`, `TenantsPage`, `AuditLogsPage`, `ShiftHistoryPage`, `WithholdingRulesPage`, `EntryExitNotesPage`, `ImportHistoryPage`, and `PaymentMethodsPage`. Wave 3 planning must include that secondary surface or first broaden the population rule; the 46-row distributions below are decision-grade only for the inherited cohort.

Reachability is derived from the JSX route tree in `src/routes/index.tsx` and navigation references in production source: `to`, `href`, `navigate(...)`, breadcrumb maps, and the central `entityRoutes` builders. Route parameters and dynamic links are segment-matched; an unconstrained template such as `/${section}/${slug}` cannot make unrelated literal paths reachable. Literal `basePath` props are also unioned by JSX component name and applied to the same-named component file. That bounded approximation found seven real links at this base, but it can over-mark reachability if callers supply different paths to the same component; no live false positive was found. It does not resolve object-map element access or paths returned from local pure functions, so all raw action/form candidates receive the manual call-flow check below. Redirects, layout shells, and external auth/legal entry routes are reported but excluded from the orphan set.

## Decision-grade summary for the inherited cohort

```json
{
  "listing_pages": 46,
  "pagination": {
    "OffsetPagination": 18,
    "bespoke_controls": 3,
    "cursor_controls": 1,
    "none": 24
  },
  "empty_state": {
    "EmptyState": 17,
    "DataTable_emptyTitle": 2,
    "bespoke": 26,
    "none": 1
  },
  "filter_pattern": {
    "FilterPanel": 1,
    "SearchInput_or_FilterTabs": 16,
    "inline_atoms": 13,
    "raw_DOM": 3,
    "bespoke_chips_or_tabs": 2,
    "none": 11
  },
  "ListPageLayout": 12,
  "DataTable": 35,
  "registered_route_records": 271,
  "raw_scanner_orphan_candidates": {
    "view": 15,
    "parameterized_view": 4,
    "action_or_form": 8,
    "total": 27
  },
  "after_manual_call_flow_check": {
    "confirmed_reachable_action_or_form": 5,
    "remaining_view": 15,
    "remaining_parameterized_view": 4,
    "remaining_action_or_form": 3,
    "remaining_total": 22
  }
}
```

### Superseded findings

- **UI-28 — 22 paginated / 24 without pagination.** This is three fewer unpaginated pages than the original 27/46 claim and matches the later provisional correction, now with rendered-graph evidence. The old file-local scan missed Contact, Counting, and Z Reports’ bespoke controls plus Supplier Invoices’ cursor controls.
- **UI-29 — 45 pages have an empty-state mechanism; one does not.** The only `none` is `CompanyListPage`, a redirect stub rather than a list UI. The new no-empty-state count is 23 lower than the original “24 of 46” claim after following organisms and inspecting conditional render branches. The corrected mechanism split is 17 `EmptyState`, 2 `DataTable emptyTitle`, and 26 bespoke.
- **UI-34 — seven orphaned top-level views, unchanged from the corrected seven and two fewer than the original nine-view claim.** The current scan independently confirms `/pos/shifts`, `/growth`, `/growth/modules`, `/scheduling/capacity`, `/treasury/payment-methods`, `/treasury/sales-withholding-tracking`, and `/settings/chart-of-accounts`. `/inventory` remains reachable through `Breadcrumb.tsx` (and `EntryExitNotesPage.tsx`), while `/pos` remains reachable from the Terminals back-link. Parameterized routes were not excluded from the scan; they are separated below so they cannot distort the top-level UI-34 count.

The other previously decision-grade headline also holds: `ListPageLayout` remains 12/46. `DataTable` rises from 34 to 35 because the graph-aware method sees `EnrichmentQueuePage` delegating to `EnrichmentQueueTable`.

## Listing table

This compact table is machine-readable Markdown. `B` means the shared SearchInput/FilterTabs family; `D`, `E`, and `F` mean inline atoms, raw DOM controls, and bespoke chips/tabs respectively.

| # | Page | Pagination | Empty state | Filter | ListPageLayout | DataTable | Rendered-graph note |
|---:|---|---|---|---|---|---|---|
| 1 | `batches/pages/BatchListPage.tsx` | none | bespoke | B | no | yes | page |
| 2 | `catalog/pages/AttributeListPage.tsx` | none | bespoke | none | no | no | page |
| 3 | `catalog/pages/CompositeItemListPage.tsx` | OffsetPagination | DataTable emptyTitle | B | yes | yes | page |
| 4 | `catalog/pages/ModifierGroupListPage.tsx` | OffsetPagination | DataTable emptyTitle | B | yes | yes | page |
| 5 | `channels/pages/ChannelListPage.tsx` | none | bespoke | none | no | yes | page |
| 6 | `coupons/pages/CouponListPage.tsx` | none | bespoke | D | no | yes | page |
| 7 | `crm/pages/CompanyListPage.tsx` | none | none | none | no | no | redirect stub |
| 8 | `crm/pages/ContactListPage.tsx` | bespoke | bespoke | D | no | yes | page |
| 9 | `document-ingestions/DocumentIngestionListPage.tsx` | none | bespoke | D | no | yes | page |
| 10 | `documents/DocumentListPage.tsx` | OffsetPagination | EmptyState | B | yes | yes | page |
| 11 | `documents/ReturnNoteListPage.tsx` | none | bespoke | E | no | yes | page |
| 12 | `enrichment/pages/EnrichmentQueuePage.tsx` | none | bespoke | D | no | yes | table via `EnrichmentQueueTable.tsx` |
| 13 | `expenses/pages/ExpenseListPage.tsx` | none | bespoke | B | yes | no | empty state via `components/organisms/ExpenseList.tsx` |
| 14 | `finance/pages/JournalEntryListPage.tsx` | OffsetPagination | EmptyState | none | yes | yes | page |
| 15 | `income/pages/IncomeListPage.tsx` | none | bespoke | B | yes | yes | page |
| 16 | `inventory-counting/pages/CountingListPage.tsx` | bespoke | bespoke | E | no | yes | page |
| 17 | `inventory/ProductListPage.tsx` | OffsetPagination | EmptyState | FilterPanel | no | yes | page |
| 18 | `loyalty/pages/MemberListPage.tsx` | OffsetPagination | bespoke | B | no | yes | page |
| 19 | `loyalty/pages/ProgramListPage.tsx` | none | bespoke | B | no | yes | page |
| 20 | `menu/pages/MenuListPage.tsx` | OffsetPagination | EmptyState | B | no | no | page |
| 21 | `parapharmacy/pages/CertificationListPage.tsx` | OffsetPagination | EmptyState | none | no | yes | page |
| 22 | `parapharmacy/pages/HealthClaimListPage.tsx` | OffsetPagination | EmptyState | none | no | yes | page |
| 23 | `parapharmacy/pages/IngredientListPage.tsx` | OffsetPagination | EmptyState | none | no | yes | page |
| 24 | `parapharmacy/pages/KeyComponentListPage.tsx` | OffsetPagination | EmptyState | none | no | yes | page |
| 25 | `partners/PartnerListPage.tsx` | OffsetPagination | bespoke | B | no | yes | page |
| 26 | `pos/pages/ZReportListPage/ZReportListPage.tsx` | bespoke | bespoke | D | no | yes | page |
| 27 | `pricing/PriceListListPage.tsx` | none | bespoke | B | no | yes | page |
| 28 | `promotions/pages/PromotionListPage.tsx` | none | bespoke | D | no | yes | page |
| 29 | `purchases/GoodsReceiptListPage.tsx` | none | bespoke | F | no | no | page |
| 30 | `purchases/quote-requests/QuoteRequestListPage.tsx` | none | bespoke | none | no | no | page |
| 31 | `purchases/supplier-invoices/SupplierInvoiceListPage.tsx` | cursor | bespoke | B | no | yes | page |
| 32 | `replenishment/pages/ReplenishmentQueuePage.tsx` | OffsetPagination | EmptyState | B | no | no | page |
| 33 | `services/ServiceCategoryListPage.tsx` | none | bespoke | E | no | no | page |
| 34 | `services/ServiceListPage.tsx` | none | bespoke | B | no | yes | page |
| 35 | `stock-adjustments/pages/StockAdjustmentListPage.tsx` | OffsetPagination | EmptyState | D | no | yes | page |
| 36 | `stock-transfers/pages/StockTransferListPage.tsx` | none | EmptyState | D | no | yes | page |
| 37 | `treasury/InstrumentListPage.tsx` | OffsetPagination | EmptyState | D | yes | yes | page |
| 38 | `treasury/PaymentListPage.tsx` | none | EmptyState | B | yes | yes | page |
| 39 | `treasury/RemittanceListPage.tsx` | OffsetPagination | EmptyState | D | yes | yes | page |
| 40 | `treasury/RepositoryListPage.tsx` | none | EmptyState | none | yes | yes | page |
| 41 | `treasury/statements/StatementListPage.tsx` | OffsetPagination | EmptyState | D | yes | yes | page |
| 42 | `vehicles/VehicleListPage.tsx` | none | bespoke | B | no | yes | page |
| 43 | `vouchers/pages/VoucherListPage.tsx` | OffsetPagination | bespoke | F | no | yes | page |
| 44 | `workshop-bundles/pages/BundleListPage.tsx` | none | bespoke | none | no | no | empty state via `components/organisms/BundleList.tsx` |
| 45 | `workshop-technicians/pages/TeamListPage.tsx` | none | bespoke | D | no | no | page |
| 46 | `workshop-work-orders/pages/WorkOrderListPage.tsx` | none | EmptyState | D | yes | no | page |

## Raw scanner reachability and manual follow-up

The scanner emits 27 raw candidates after removing redirects, layout shells, and external entry routes. Structural roles keep the result auditable. This corrects seven false action/form candidates from the first re-census draft through conditional partner base paths and literal `basePath` props passed into shared document action components. Manual call-flow review then falsifies five more raw action/form candidates that remain beyond the scanner's bounded expression model, leaving 22 candidates: 15 views, four parameterized views, and three action/forms.

| Route | Component | Role | Finding/disposition |
|---|---|---|---|
| `/channels/:id/orders` | `ChannelOrdersPage` | parameterized view | CX-2 cluster |
| `/channels/:id/products` | `ChannelProductMappingPage` | parameterized view | CX-2 cluster |
| `/channels/:id/sync` | `ChannelSyncStatusDashboard` | parameterized view | CX-2 cluster |
| `/inventory/return-notes/:id` | `ReturnNoteDetailPage` | parameterized view | additional candidate; route call-flow follow-up |
| `/expenses/:id/edit` | `ExpenseFormPage` | action/form | static candidate; call-flow follow-up |
| `/income/:id/edit` | `IncomeFormPage` | action/form | confirmed reachable via `LinePanel.tsx` local `provenanceLink()` |
| `/inventory/delivery-notes/new` | `DocumentForm` | action/form | confirmed reachable via `DocumentListPage.tsx` Add link and `documentTypeToPath` |
| `/inventory/replenishment/new` | `ReplenishmentCapturePage` | action/form | static candidate; call-flow follow-up |
| `/inventory/return-notes/new` | `CreateReturnNotePage` | action/form | confirmed reachable via `DocumentListPage.tsx` Add link and `documentTypeToPath` |
| `/sales/credit-notes/create` | `CreateCreditNotePage` | action/form | confirmed reachable via `DocumentListPage.tsx` credit-note Add branch |
| `/sales/credit-notes/new` | `DocumentForm` | action/form | static candidate; call-flow follow-up |
| `/sales/orders/new` | `DocumentForm` | action/form | confirmed reachable via `DocumentListPage.tsx` Add link and `documentTypeToPath` |
| `/finance` | `FinanceHubPage` | view | UI-33 hub |
| `/marketing` | `MarketingHubPage` | view | UI-33 hub |
| `/pos/shifts` | `POSShiftsPage` | view | UI-34 |
| `/growth` | `GrowthPage` | view | UI-34 |
| `/growth/modules` | `ProgressionModulesPage` | view | UI-34 |
| `/scheduling/capacity` | `SchedulingCapacityReportPage` | view | UI-34 |
| `/treasury/payment-methods` | `PaymentMethodsPage` | view | UI-34 |
| `/treasury/sales-withholding-tracking` | `SalesWithholdingTrackingPage` | view | UI-34 |
| `/settings/chart-of-accounts` | `ChartOfAccountsPage` | view | UI-34 duplicate mount |
| `/inventory/delivery-notes/consolidate` | `DeliveryNoteConsolidationPage` | view | CX-1 |
| `/finance/lane-separation` | `LaneSeparationReportPage` | view | claimed by DN-consolidation lane |
| `/settings/compliance/export` | `ComplianceExportPage` | view | UI-09 compliance cluster |
| `/settings/compliance/fraud-alerts` | `FraudAlertsPage` | view | UI-09 compliance cluster |
| `/settings/compliance/fraud-settings` | `FraudSettingsPage` | view | UI-09 compliance cluster |
| `/settings/compliance/quarantine-resolution` | `QuarantineResolveAssistPage` | view | UI-09 compliance cluster |

Five action/form rows are scanner false negatives with confirmed production links: four shared-document Add targets built from the module-level `documentTypeToPath` map, plus the income edit target returned by `LinePanel.tsx`'s local pure function. The three remaining action/form rows (`/expenses/:id/edit`, `/inventory/replenishment/new`, `/sales/credit-notes/new`) have no production inbound reference found by either the scanner or manual review; they remain candidates rather than confirmed orphans. The seven UI-34 views, both UI-33 hubs, the CX-1 workflow, the three CX-2 parameterized views, the compliance cluster, and the claimed lane-separation page all reproduce with zero inbound UI references.

## Which prior rows are now decision-grade

- Pagination, empty-state, filter-pattern, `ListPageLayout`, and `DataTable` distributions are decision-grade for the inherited 46-page cohort at the pinned base because each row is graph-derived and source-attributed. They are not whole-product coverage figures because of the disclosed filename and component-role boundaries.
- The seven-view UI-34 set and the named hub/CX/compliance clusters are decision-grade because the tool includes parameterized routes and positive breadcrumb/back-link evidence.
- Five raw action/form candidates are confirmed reachable by manual call-flow review; the remaining three are intentionally not promoted to confirmed orphans without runtime or broader call-flow evidence.
- The withdrawn “~57 person-days” estimate remains withdrawn; this census measures scope and does not invent an estimation model.
