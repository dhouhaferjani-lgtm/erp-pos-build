# Design-System Violation Inventory (Sweep Manifest) — 2026-07-10

> Complete occurrence inventory for the design-system unification sweep. Scope: `apps/web/src` (React 19 / Tailwind 4). Read-only audit; exclusions: `*.test.tsx`, `__tests__/`, `*.stories.tsx`; atoms/molecules implementation files exempt. **This file is the tracking manifest: the sweep is DONE only when every verification command at the bottom returns zero for swept directories.** Companion docs: `2026-07-10-procurement-ui-and-document-configurator-audit.md` (findings) and `docs/handoff/CODEX-design-system-unification-2026-07-10.md` (execution plan).

## Canonical system (verified to exist)

| Component | Export path |
|---|---|
| `PageHeader` | `components/molecules/PageHeader/` (re-exported from `components/molecules/index.ts`) |
| `FormField`, `Input`, `Select`, `Textarea`, `Button`, `StatusBadge` | `components/atoms/` (`index.ts`) |
| `DataTable` | `components/molecules/DataTable/` |
| `LineItemsTable` (+ `LineItemEntryBar`, `ProductCell`, `ProductLineSelect`) | `components/molecules/line-items/` |
| `StickyFormFooter` | `components/molecules/StickyFormFooter/StickyFormFooter.tsx` — **no index.ts; consumers import full nested path (cleanup candidate)** |
| `SaveSplitButton` | `components/molecules/SaveSplitButton/` |
| `EmptyState` | `components/molecules/EmptyState/` |
| Design tokens | `lib/designTokens.ts` (`tokens`, `textColors`, `borderColors`, `colors`, `focusRing`) |
| `tenantScopedKey` | `lib/tenantScopedKey.ts` |
| Pickers: `BundlePicker`, `PartnerPicker`, `ProductPicker`, `ServicePicker`, `VehiclePicker` | `components/molecules/pickers/` |

## Summary

| # | Category | Files | Occurrences | Confidence |
|---|---|---|---|---|
| C1 | Bespoke `<h1>` instead of `PageHeader` | 120 | 123 | High |
| C2 | Raw `<input>`/`<select>`/`<textarea>` with `tokens.*` | 26 | 98 | High |
| C3 | Raw `<button>` with `tokens.button` | 49 | 98 | High |
| C4 | Create/Edit/Form pages missing react-hook-form | 28 | file-level | Medium (heuristic, not exhaustive) |
| C5 | Hand-rolled `<table>` vs `DataTable`/`LineItemsTable` | 116 | 127 | High (triage best-effort) |
| C6 | Local status→class maps vs `StatusBadge` | 26 | ~33 | High (7 full duplicate badge components) |
| C7 | Hardcoded Tailwind color literals | 231 | 4,719 | High — largest category by 5× |
| C8 | Missing `StickyFormFooter` on create/edit pages | 25 of C4's 28 | file-level | Medium (modal forms may be exempt) |
| C9 | Duplicated picker/search/select components | 27 components | ~80 consumer sites | High |
| C10 | Existing enforcement coverage | — | — | High |

**Grand total C1–C7: ~688 distinct files, ~5,198 occurrences** (files overlap across categories).

Denominators (already-compliant): PageHeader 47 files; DataTable 19 files; LineItemsTable 2 files; canonical StatusBadge 105 files.

---

## C1 — Bespoke `<h1>` page headers (120 files, 123 occ)

Multi-hit files: `batches/pages/EditBatchPage.tsx` (3), `auth/ResetPasswordPage.tsx` (2).

Single-hit files (all under `src/features/` unless noted): `pages/legal/TermsOfServicePage.tsx`*, `pages/legal/PrivacyPolicyPage.tsx`*, `pages/POS/Terminals.tsx`, workshop-technicians/pages/PayrollExportPage, workshop-bundles/pages/{BundleDetailPage,BundleCreatePage}, workshop-bundles/components/organisms/BundleList, vouchers/pages/{VoucherListPage,VoucherDetailPage}, vehicles/{VehicleListPage,VehicleForm,VehicleDetailPage}, vat-reporting/pages/{VatReportPage,VatPeriodsPage}, uom/pages/UnitsSettingsPage, treasury/PaymentForm, stock-transfers/pages/{StockTransferListPage,StockTransferDetailPage,CreateStockTransferPage}, settings/{pages/PosRefundPoliciesPage,RolesPage,LocationsPage}, services/{ServiceListPage,ServiceForm,ServiceDetailPage,ServiceCategoryListPage}, scheduling/pages/{SchedulerPage,CapacityReportPage,AppointmentDetailPage}, reports/pages/AgedReceivablesPage†, purchases/supplier-invoices/{SupplierInvoiceListPage,SupplierInvoiceCreatePage}, purchases/quote-requests/{QuoteRequestListPage,QuoteRequestCreatePage,QuoteRequestComparisonPage}, purchases/GoodsReceiptListPage, promotions/pages/{PromotionListPage,PromotionFormPage}, progression/components/{ModulesCatalog,GrowthDashboard}, pricing/{PriceListListPage,PriceListForm,PriceListDetailPage}, pos/pages/{ZReportListPage,ZReportDetailPage,TableManagementPage,ShiftHistoryPage,AnalyticsDashboardPage}, pos/components/TerminalSelector, parts-catalog/pages/PartsCatalogPage, partners/{PartnerListPage,PartnerForm,PartnerDetailPage}, parapharmacy/pages/{KeyComponentListPage,KeyComponentFormPage,IngredientListPage,IngredientFormPage,HealthClaimListPage,HealthClaimFormPage,CertificationListPage,CertificationFormPage}, opening-balances/pages/{OpeningBalancesPage,OpeningBalanceWizardPage}, loyalty/pages/{ProgramListPage,ProgramFormPage,ProgramDetailPage,MemberListPage,MemberFormPage,MemberDetailPage}, inventory-counting/pages/{DiscrepancyReportPage,CreateCountingPage,CountingReviewPage,CountingListPage,CountingDetailPage,CountingDashboardPage}, import/pages/{ImportWizardPage,ImportHistoryPage,ImportDashboardPage}, expenses/pages/ExpenseCategoryPage, enrichment/pages/EnrichmentQueuePage, documents/return-notes/ReturnNoteDetailPage, documents/delivery-notes/DeliveryNoteDetailPage, documents/credit-notes/CreditNoteDetailPage, documents/components/DocumentHeader, documents/ReturnNoteListPage‡, documents/ReturnNoteDetailPage‡, documents/DeliveryNoteConsolidationPage, documents/CreateReturnNotePage, documents/CreateCreditNotePage, dashboard/Dashboard, customer-history-audit/pages/CustomerHistoryAuditPage, crm/pages/{ContactListPage,ContactFormPage,ContactDetailPage}, coupons/pages/{CouponListPage,CouponFormPage}, compliance/pages/{QuarantineResolveAssistPage,FraudSettingsPage,FraudAlertsPage,ComplianceExportPage}, categories/CategoriesPage, catalog/pages/AttributeListPage, batches/pages/{CreateBatchPage,BatchListPage,BatchDetailPage}, auth/components/RegisterBrandPanel*, auth/{LoginPage,ForgotPasswordPage}*, admin/pages/{VerticalsPage,TenantsPage,SubscriptionsPage,PaymentsPage,MonitoringPage,InvoicesPage,CompanyOwnersPage,BillingDashboardPage,AuditLogsPage,AdminDashboardPage}.

\* unauthenticated/legal pages — confirm scope (PageHeader is an app-shell pattern; colors still apply).
† duplicate page: `finance/pages/AgedReceivablesPage.tsx` exists and DOES use PageHeader — dedup before sweeping.
‡ `documents/` and `documents/return-notes/` both contain ReturnNote pages — dedup-check before sweeping.

## C2 — Raw form elements with `tokens.*` (26 files, 98 occ)

`<input>` (19 files, 72): settings/pages/PosRefundPoliciesPage 16; purchases/supplier-invoices/SupplierInvoiceCreatePage 12; scheduling/components/organisms/AppointmentFormDrawer 8; vehicles/VehicleForm 8; purchases/quote-requests/QuoteRequestDetailPage 3; purchases/quote-requests/QuoteRequestCreatePage 3; scheduling/components/organisms/AvailabilityFinderPanel 3; inventory/components/EnrichmentCapturePanel 3; purchases/supplier-invoices/SupplierInvoiceListPage 2; catalog/components/AttributeForm 2; workshop-technicians/pages/PayrollExportPage 2; pos/components/TerminalForm 2; document-ingestions/components/LineMappingTable 2; products/components/ParapharmacyMetadataFields 1; scheduling/pages/AppointmentDetailPage 1; scheduling/pages/CapacityReportPage 1; admin/components/VerticalConfigModal 1; vouchers/components/IssueGoodwillVoucherModal 1; document-ingestions/ReviewIngestionPage 1.

`<select>` (14 files, 22): SupplierInvoiceListPage 3; AppointmentFormDrawer 3; VehicleForm 3; SupplierInvoiceCreatePage 2; document-ingestions/DocumentIngestionListPage 2; PosRefundPoliciesPage 1; SupplierInvoiceDetailPage 1; AttributeForm 1; stock-transfers/pages/StockTransferListPage 1; stock-transfers/pages/CreateStockTransferPage 1; document-ingestions/UploadScanPage 1; document-ingestions/ReviewIngestionPage 1; document-ingestions/components/SupplierPicker 1; document-ingestions/components/LineMappingTable 1.

`<textarea>` (2 files, 4): AppointmentFormDrawer 3; VehicleForm 1.

## C3 — Raw `<button>` with `tokens.button` (49 files, 98 occ)

SupplierInvoiceCreatePage 6; SupplierInvoiceDetailPage 4; QuoteRequestDetailPage 4; QuoteRequestComparisonPage 4; QuoteRequestCreatePage 4; scheduling CalendarDayHeader 4; AppointmentDetailPage 4; AppointmentFormDrawer 3; admin VerticalConfigModal 3; compliance FraudSettingsPage 3; pos QuickAddCustomerModal 3; inventory EnrichmentCapturePanel 3; enrichment EnrichmentReviewPanel 3; document-ingestions UploadScanPage 3; document-ingestions CommitBar 3; PosRefundPoliciesPage 2; AttributeForm 2; QuarantineResolveAssistPage 2; HeldOrderCard 2; TableManagementPage 2; ProductInfoModal 2; OrderPanel 2; VehicleDetailPage 2; RecommendationCard 2; LineMappingTable 2; then 1 each: ProductCard, HeldOrdersList, CashTenderedModal, HoldOrderButton, POSLayout, POSPage, PartnerForm, StockLevelsPage, EnrichmentReadyCard, VehicleForm, TransferOwnershipModal, EnrichmentQueuePage, DocumentActionBar, ExpiryWriteOffPage, BundleForm, ReviewIngestionPage, SupplierPicker (document-ingestions), UnitDecimalSettings, GoodsReceiptListPage, AppointmentCard, AvailabilityFinderPanel, TimeEntriesTab, CertificationsTab, TimeOffTab.

## C4 — Forms without react-hook-form (28 files, heuristic)

settings/components/UserEditModal; purchases/supplier-invoices/SupplierInvoiceCreatePage; purchases/quote-requests/QuoteRequestCreatePage; withholding/components/WithholdingRuleFormModal; scheduling/components/organisms/AppointmentFormDrawer; expenses/pages/ExpenseFormPage; catalog/pages/CompositeItemFormPage†; catalog/pages/ModifierGroupFormPage†; income/pages/IncomeFormPage; stock-transfers/pages/CreateStockTransferPage; workshop-technicians/components/{TimeEntryFormModal,TimeOffFormModal,CertificationFormModal}; promotions/pages/PromotionFormPage†; menu/pages/MenuFormPage†; finance/components/EditAccountModal; finance/pages/JournalEntryForm†; treasury/SplitPaymentForm; workshop-work-orders/pages/WorkOrderCreatePage; batches/pages/{EditBatchPage,CreateBatchPage}; workshop-bundles/components/organisms/{BundleComponentFormModal,BundleForm}; inventory-counting/pages/CreateCountingPage; parapharmacy/pages/{IngredientFormPage,HealthClaimFormPage,CertificationFormPage,KeyComponentFormPage}.

† = has StickyFormFooter already (the 5-file "modernized cohort").
NOT exhaustive — non-Create/Edit/Form-named files also hand-roll form state (e.g. TransferOwnershipModal, IssueGoodwillVoucherModal). Regenerate with the C4 command below.

## C5 — Hand-rolled `<table>` (116 files, 127 occ)

Triage: **List** → DataTable; **LineEditor** → LineItemsTable; **Detail** → either (read-only DataTable). Multi-hit: PartnerDetailPage 3 (Detail); opening-balances/BatchPreview 3 (Detail); SupplierInvoiceDetailPage 2 (Detail); SupplierInvoiceCreatePage 2 (LineEditor); ZReportDetailPage 2; ShiftDashboardPage 2; catalog/RecipeLineEditor 2 (LineEditor); admin/MonitoringPage 2 (List); admin/InvoicesPage 2 (List).

LineEditor targets (core): documents/components/DocumentLines (**core sweep target**), documents/components/{DeliveryNoteConsolidation,CreateReturnNoteForm,CreateCreditNoteForm,costing/LandedCostBreakdown}, documents/{CreateReturnNotePage,CreateCreditNotePage}, catalog/{VariantEditor,ProductVariantMatrixEditor,pages/ModifierGroupFormPage,pages/CompositeItemFormPage}, finance/pages/JournalEntryForm, import/components/{ValidationGrid,ImportPreviewTable,ColumnMapper}, opening-balances/components/FileUpload, channels/pages/ChannelProductMappingPage.

Detail targets: all 7 document detail pages (invoices/InvoiceDetailPage, quotes/QuoteDetailPage, purchase-orders/PurchaseOrderDetailPage, sales-orders/SalesOrderDetailPage, delivery-notes/DeliveryNoteDetailPage, credit-notes/CreditNoteDetailPage, return-notes/ReturnNoteDetailPage), treasury/{RepositoryDetailPage,PaymentDetailPage,components/AllocationPreview}, loyalty tabs ×4, inventory tabs ×2 + ProductDetailPage, pos Analytics ×4 + ProductInfoModal, compliance/{ReprintLogTable,ChainVerificationPanel}, vouchers/LedgerHistoryTable, vat-reporting/VatBreakdownTable, parts-catalog/SpecificationsTable, owner-dashboard ×2, inventory-counting/ReconciliationTable, StandaloneReceiptPage, stock-transfers/StockTransferDetailPage, finance/{ProfitLossPage,JournalEntryDetailPage,BalanceSheetPage}, loyalty/MemberDetailPage, batches/BatchDetailPage, PriceListDetailPage.

List targets (rest — full regeneration via command): vouchers/VoucherListPage, vehicles/VehicleListPage, vat-reporting/VatPeriodList, uom/UnitsSettingsPage, treasury/OpenInvoicesList, stock-transfers/StockTransferListPage, settings/{UnitDecimalSettings,TaxSettingsPage,RolesPage}, services/ServiceListPage, reports/AgedReceivablesPage, SupplierInvoiceListPage, promotions/PromotionListPage, pricing/PriceListListPage, pos/{ZReportListPage,TableManagementPage,ShiftHistoryPage,TerminalList,ShiftReceiptsList}, partners/PartnerListPage, parapharmacy ×4 list pages, loyalty/{ProgramListPage,MemberListPage}, inventory-counting/{DiscrepancyReportPage,CountingListPage}, income/IncomeListPage, import/ImportHistoryPage, finance/{TrialBalancePage,AgedReceivablesPage,AgedPayablesPage}, enrichment/EnrichmentQueueTable, documents/{ReturnNoteListPage,components/CreditNoteList}, document-ingestions/DocumentIngestionListPage, customer-history-audit/CustomerHistoryAuditPage, crm/ContactListPage, coupons/CouponListPage, compliance/FraudAlertsPage, channels ×4, batches/BatchListPage, admin ×6.

## C6 — Local status maps / duplicate badge components (26 files, ~33 occ)

**Seven full bespoke StatusBadge-shaped components (direct dupes of the atom, which already has `tone` prop + `statusTone(status)` helper):** vouchers/components/StatusBadge, pos/atoms/TableStatusBadge, pos/molecules/OrderStatusBadge, inventory-counting/components/CountingStatusBadge, batches/components/BatchStatusBadge, vat-reporting/components/VatPeriodStatusBadge, documents/components/PaymentStatusBadge. Also pos/atoms/StockBadge (statusConfig object).

Status-map sites: SupplierInvoiceListPage (3× switch), PromotionListPage, PartnerDetailPage, ImportHistoryPage, CouponListPage, FraudAlertsPage, EcommerceOrdersPage, admin {SubscriptionsPage,PaymentsPage,MonitoringPage,InvoicesPage}, WithholdingCertificateDetail, SupplierInvoiceDetailPage, SupplierInvoiceCreatePage, progression/MilestoneItem, opening-balances/ValidationResults, documents/{RelatedDocumentsTab,DocumentListPage}, DocumentIngestionListPage.

Note: `pos/atoms/` mirrors the real atoms dir — decide whether to reclassify as a local atoms package or fold into canonical atoms (default: fold).

## C7 — Hardcoded Tailwind colors (231 files, 4,719 occ) — LARGEST

Directory rollup (files/occ): documents 43/1048; admin 12/513; import 11/290; inventory-counting 11/286; opening-balances 6/264; parts-catalog 19/252; partners 6/216; compliance 8/202; loyalty 12/189; auth 16/178; services 4/174; batches 6/141; parapharmacy 9/137; pricing 3/123; crm 4/69; vat-reporting 7/63; vouchers 6/62; categories 4/62; products 7/61; dashboard 1/57; purchases 3/45; promotions 2/44; coupons 2/44; expenses 3/42; reports 1/40; uom 3/34; pos 10/30; locations 1/23; inventory 1/8; company 1/6; stock-transfers 2/4; settings 2/4; catalog 3/4; vehicles 1/2; customer-history-audit 1/2.

Top offenders: MonitoringPage 121, PartnerDetailPage 117, BatchPreview 97, PaymentsPage 87, ImportWizardPage 80, FraudAlertsPage 71, InvoicesPage 67, PriceListDetailPage 65, CreateReturnNotePage 63, CreateCreditNotePage 58, OpeningBalanceWizardPage 57, Dashboard 57, BatchDetailPage 54, DiscrepancyReportPage 52, SubscriptionsPage 52, ServiceListPage 51, ServiceDetailPage 50, ReconciliationTable 50. **Full 231-file list regenerable via C7 command below** (the audit response contains the complete list; the command is authoritative).

`scheduling/` and `workshop-*` dirs are near-absent (already ESLint-ERROR'd) — but `vehicles/` shows 1 file / 2 occ despite nominally being an enforced dir: small regression to fix.

## C8 — Missing StickyFormFooter (25 of 28 C4 files)

Only the 5 † files in C4 have it. **Distinguish page-level forms (footer applies) from modal/drawer forms (`*Modal.tsx`, `*Drawer.tsx` — StickyFormFooter is NOT the right pattern there; use the modal-scoped footer).** Do not mechanically force it into modals.

## C9 — Duplicate picker/search/select map

Canonical: `components/molecules/pickers/{PartnerPicker,ProductPicker,ServicePicker,VehiclePicker,BundlePicker}`.

**Partner/supplier picking has FOUR implementations** (highest-value consolidation): `PartnerPicker` (canonical, 6 sites), `components/ui/PartnerSearchSelect` (4 sites: DocumentForm, CreateCreditNotePage, QuoteRequestCreatePage, CustomerHistoryAuditPage), `features/crm/components/PartnerSelect` (2 sites), `features/document-ingestions/components/SupplierPicker` (1 site).

Other dupes: `ProductPicker` vs `features/products/components/ProductSelector` (2 sites: CouponFormPage, PromotionFormPage); location picking split across `features/location/LocationSelector` (3 sites) and `features/locations/components/LocationSelectorMulti` (2 sites) — singular/plural dir split; `components/catalog/CategorySelect` (1 site) vs `features/categories/components/CategorySelector` (3 sites); `components/ui/UserPicker` (2 sites) vs `features/users/components/UserSelector` (1 site).

No canonical equivalent yet (keep, standardize style): `components/ui/{InvoiceSearchSelect,DeliveryNoteSearchSelect,DocumentSearchSelect(base)}`; feature-local enum selects (ReturnReasonSelect etc.) — low priority.

**Orphans (keep-or-delete decision needed):** `features/document/components/DocumentLineVariantSelector` (0 consumers; also note singular `document/` vs plural `documents/` dir split), `features/catalog/components/ProductDetailVariantPicker` (0), `features/pos/components/TerminalSelector` (0).

## C10 — Existing enforcement + gaps

Enforced today (`apps/web/eslint.config.js`): hardcoded-color `no-restricted-syntax` — WARN globally with only an **8-color subset** (missing slate/zinc/amber/emerald/etc.); ERROR narrow-palette in autospecs/scheduling/vehicles/workshop-*/document-ingestions; ERROR **full palette only in `scheduling/` and `workshop-*`** (this is the template to replicate). `local/no-untranslated-literal` WARN→ERROR per clean-dir allowlist (the ratchet-promotion mechanism to copy). `tools/audit-tanstack-keys.mjs` (in lint/preflight/CI) — **has a shorthand-property blind spot** (`useQuery({ queryKey })` form skipped at `:309`), which hides real violations in `PartnerPicker.tsx:99`, `VehiclePicker.tsx:95`, `ServicePicker.tsx:88`.

Not enforced anywhere: PageHeader usage, raw form elements, RHF requirement, raw tables, status maps, duplicate pickers.

---

## Verification commands (run from `apps/web/`; sweep is done when counts = 0 for swept dirs)

```bash
# C1
rg -n --pcre2 '<h1[^>]*(text-2xl|text-3xl)' src/features src/pages -g '!**/*.test.tsx' -g '!**/__tests__/**' -g '!**/*.stories.tsx' -c

# C2/C3 (tag-scoped; plain line-grep mismatches multiline JSX)
python3 - <<'PY'
import re, os
tag_re = {t: re.compile(rf'<{t}\b[^>]*?/?>', re.DOTALL) for t in ('input','select','textarea','button')}
for root, _, files in os.walk('src/features'):
    for f in files:
        if not f.endswith('.tsx') or '.test.' in f or '.stories.' in f or '__tests__' in root: continue
        p = os.path.join(root, f); content = open(p, encoding='utf-8', errors='ignore').read()
        for tag, rex in tag_re.items():
            n = sum(1 for m in rex.findall(content) if 'tokens.' in m)
            if n: print(f'{tag}\t{p}\t{n}')
PY

# C4
python3 - <<'PY'
import re, os
for root, _, files in os.walk('src/features'):
    for f in files:
        if not f.endswith('.tsx') or not re.search(r'(Create|Edit|Form)', f): continue
        if '.test.' in f or '.stories.' in f or '__tests__' in root: continue
        p = os.path.join(root, f); c = open(p, encoding='utf-8', errors='ignore').read()
        if ('<form' in c or re.search(r'onSubmit|handleSubmit', c)) and 'react-hook-form' not in c: print(p)
PY

# C5
rg -n '<table\b' src/features -g '!**/*.test.tsx' -g '!**/__tests__/**' -g '!**/*.stories.tsx' -c

# C6
rg -n --pcre2 '(status|state)\w*(Colors?|Classes?|Map|Styles?)\s*:\s*Record<' src/features -i -g '!**/*.test.tsx' -g '!**/__tests__/**'
rg -n --pcre2 'switch\s*\(\s*\w*[Ss]tatus\w*\s*\)' src/features -g '!**/*.test.tsx' -g '!**/__tests__/**'
rg -n --pcre2 'const\s+\w*(status|state)\w*(Colors?|Classes?|Styles?|Config|Badge\w*)\s*[:=]' src/features -i -g '!**/*.test.tsx' -g '!**/__tests__/**'

# C7 (full palette)
rg -n --pcre2 '(bg|text|border|ring|divide|from|to|via|placeholder|fill|stroke|outline|accent|caret|shadow|decoration)-(gray|red|green|blue|yellow|amber|orange|purple|pink|indigo|emerald|rose|slate|zinc|neutral|stone)-[0-9]{2,3}\b' src/features -g '!**/*.test.tsx' -g '!**/__tests__/**' -g '!**/*.stories.tsx' -c

# C9
find src/components src/features -iname "*Picker*.tsx" -o -iname "*Search*.tsx" -o -iname "*Select*.tsx" | grep -v -E '\.test\.tsx$|__tests__'
```

Exemption candidates to confirm with owner before declaring zero: `pages/legal/*` and `auth/` unauthenticated pages (PageHeader only — colors still in scope), `pos/atoms/` reclassification (default: fold into canonical atoms).
