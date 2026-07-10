# Design-System Sweep Progress

> Branch: `feat/design-system-unification` in `/Users/houssamr/Projects/syneriva/apps/erp.design-sweep`.
> Source handoff: `docs/handoff/CODEX-design-system-unification-2026-07-10.md`.

## Gate 1 Fixlist — Blockers

Status: blockers complete; MJ-1 and MJ-2 complete.

- BL-1 `PartnerPicker` empty-string value:
  - RED: `pnpm --filter @autoerp/web test -- src/components/molecules/pickers/PartnerPicker.test.tsx` failed because `value=""` triggered a `/partners/` fetch and hid the search input behind a blank selected chip.
  - GREEN: `PartnerPicker` now normalizes empty string values to no selection and gates ID rehydration on a non-empty id. RHF callers touched by the partner consolidation now write `null` for no partner selection.
- BL-2 service-line document identity:
  - Correction to Wave 1 D3: the prior GREEN only proved `DocumentLineEditor` could add a service-shaped row; it did not prove `DocumentForm` preserved that service identity through save/reload.
  - RED: `pnpm --filter @autoerp/web test -- src/features/documents/DocumentForm.test.tsx` failed because service lines submitted `product_id: ""` with no `service_id`, and loaded server lines rendered as product lines instead of service lines.
  - GREEN: `buildLinePayload` now sends `service_id` and omits `product_id` for service lines; `DocumentForm` restores `service_id`/`is_service` from loaded document lines; draft autosave accepts the same product/service polymorphic line shape.
- Verification:
  - `pnpm --filter @autoerp/web test -- src/components/molecules/pickers/PartnerPicker.test.tsx src/features/documents/DocumentForm.test.tsx src/features/documents/__tests__/DocumentForm.tenantScope.test.tsx src/features/documents/__tests__/ReturnCreditNotePages.tenantScope.test.tsx src/features/crm/__tests__/tenantScope.test.tsx src/features/document-ingestions/__tests__/ReviewIngestionPage.test.tsx src/features/purchases/quote-requests/QuoteRequestCreatePage.test.tsx` passed: 58 tests.
  - `pnpm --filter @autoerp/web test -- src/features/crm/pages/__tests__/ContactFormPage.test.tsx` passed: 7 tests.
  - `pnpm --filter @autoerp/web typecheck` passed.

## Gate 1 Fixlist — Majors

Status: in progress.

- MJ-1 design-system scanner JSX tag regex:
  - RED: `pnpm --filter @autoerp/web test -- tools/__tests__/audit-design-system.test.mjs` failed because an `<input>` with `onChange={(event) => ...}` and `tokens.input.base` produced zero C2 violations.
  - GREEN: all JSX tag regexes now tolerate `=>` inside attributes before the real closing `>`.
  - Honest baseline rewrite: scanner now sees 535 C1-C6 violations; `node apps/web/tools/audit-design-system.mjs --write-baseline` wrote the current baseline, and `node apps/web/tools/audit-design-system.mjs` passes with 535 acknowledged, 0 new, 0 stale.
- MJ-2 design-system baseline duplicate disambiguation:
  - RED: `pnpm --filter @autoerp/web test -- tools/__tests__/audit-design-system.test.mjs` failed because two identical raw-token `<input>` violations in the same file produced the same baseline key.
  - GREEN: `violationBaselineKey` now appends a per-file duplicate ordinal (`#1`, `#2`, etc.) assigned during `scanCode`, so copy-pasted identical violations cannot ride one baseline entry.
  - Baseline rewrite: `node apps/web/tools/audit-design-system.mjs --write-baseline` now produces 535 baseline entries for the 535 current C1-C6 occurrences.
  - Verification: `pnpm --filter @autoerp/web test -- tools/__tests__/audit-design-system.test.mjs` passed: 6 tests; `node apps/web/tools/audit-design-system.mjs` passed with 535 acknowledged, 0 new, 0 stale.
- MJ-3 service-line tax:
  - RED: `pnpm --filter @autoerp/web test -- src/features/documents/components/__tests__/DocumentLineEditor.test.tsx` failed because a Workshop service carrying `tax_rate: "19.00"` still created a line with `tax_rate: "0"`, `tax_configuration_id: null`, and untaxed total.
  - GREEN: `ServicePicker` now carries service `tax_rate` and `default_tax_configuration_id`; `DocumentLineEditor` uses those values when adding service lines.
  - Verification: `pnpm --filter @autoerp/web test -- src/features/documents/components/__tests__/DocumentLineEditor.test.tsx src/components/molecules/pickers/ServicePicker.test.tsx` passed: 28 tests; `pnpm --filter @autoerp/web typecheck` passed.
- MJ-4 line-entry overlay close:
  - RED: `pnpm --filter @autoerp/web test -- src/components/molecules/line-items/LineItemEntryBar.test.tsx` failed because product suggestions stayed open after pointerdown outside the entry bar.
  - GREEN: `LineItemEntryBar` now closes the listbox on outside `pointerdown` while preserving inside selection behavior.
  - Verification: `pnpm --filter @autoerp/web test -- src/components/molecules/line-items/LineItemEntryBar.test.tsx` passed: 8 tests; `pnpm --filter @autoerp/web typecheck` passed.
- MJ-5 empty-query keyboard commit:
  - RED: `pnpm --filter @autoerp/web test -- src/components/molecules/line-items/LineItemEntryBar.test.tsx` failed because Enter on a highlighted focus suggestion did nothing when the query was empty.
  - GREEN: `LineItemEntryBar` now commits highlighted open suggestions before the empty-query scanner bailout.
  - Verification: `pnpm --filter @autoerp/web test -- src/components/molecules/line-items/LineItemEntryBar.test.tsx` passed: 9 tests; `pnpm --filter @autoerp/web typecheck` passed.
- MJ-6 read-only quantity formatting:
  - RED: `pnpm --filter @autoerp/web test -- src/features/documents/components/__tests__/DocumentLines.test.tsx` failed because `DocumentLines` rendered backend quantity scale (`2.0000`) directly.
  - GREEN: `DocumentLines` now formats quantities with `formatQuantity(line.quantity, line.quantity_decimals ?? 4)`.
  - Verification: `pnpm --filter @autoerp/web test -- src/features/documents/components/__tests__/DocumentLines.test.tsx src/features/documents/quotes/__tests__/QuoteDetailPage.tenantScope.test.tsx` passed: 5 tests; `pnpm --filter @autoerp/web typecheck` passed.

## Wave 0 — Tooling & Guardrails

Status: complete.

- Worktree: created from `origin/dev` as required by the handoff.
- Companion manifests copied into the branch because they were untracked in the main checkout.
- 0.1 TanStack audit shorthand-property blind spot:
  - RED: `pnpm vitest run tools/__tests__/audit-tanstack-keys.test.mjs` failed on `flags shorthand queryKey declarations with no tenant scope`.
  - GREEN: same command passed after resolving shorthand `queryKey` declarations.
- 0.2 Tenant-scoped picker keys:
  - RED: `pnpm audit:keys` reported `PartnerPicker`, `VehiclePicker`, `ServicePicker`, plus an additional real `UserPicker` violation exposed by the scanner fix.
  - GREEN: `pnpm audit:keys` reports 0 violations after wrapping all four picker keys with `tenantScopedKey`.
- 0.3 `AddPartnerModal` invalidation:
  - RED: `pnpm vitest run src/components/organisms/AddPartnerModal/AddPartnerModal.test.tsx` failed on live partner search/detail cache invalidation.
  - GREEN: same command passed after replacing the stale exact key with a tenant/company-aware predicate for `partners`, `partners-search`, `pickers/partner`, and the created `partner` detail.
- 0.4 Design-system audit:
  - RED: `pnpm vitest run tools/__tests__/audit-design-system.test.mjs` failed because `tools/audit-design-system.mjs` did not exist.
  - GREEN: same command passed after adding the C1-C6 scanner.
  - Baseline seeded with 509 current C1-C6 entries using `node tools/audit-design-system.mjs --write-baseline`.
  - `node tools/audit-design-system.mjs` passes with 509 acknowledged, 0 new, 0 stale.
  - Wired into `apps/web/package.json`, `scripts/preflight.sh`, and `.github/workflows/ci.yml`.
- 0.5 ESLint hardcoded-color rule:
  - Global WARN and strict new-feature ERROR regexes widened to the full C7 palette/utility set.
- 0.6 Vehicles regression:
  - Replaced `hover:bg-gray-50` and `bg-gray-100` literals in `VehicleDetailPage` with existing design tokens.
- Verification:
  - `pnpm vitest run tools/__tests__/audit-tanstack-keys.test.mjs tools/__tests__/audit-design-system.test.mjs src/components/organisms/AddPartnerModal/AddPartnerModal.test.tsx` passed: 37 tests.
  - `pnpm typecheck` passed.
  - `pnpm lint` passed; existing warning count remains high, but 0 errors. The chained audits passed:
    - TanStack query key audit: 0 violations.
    - Design-system audit: 509 acknowledged, 0 new, 0 stale.
  - Vehicles C7 scoped check returned zero matches:
    `rg -n --pcre2 '(bg|text|border|ring|divide|from|to|via|placeholder|fill|stroke|outline|accent|caret|shadow|decoration)-(gray|red|green|blue|yellow|amber|orange|purple|pink|indigo|emerald|rose|slate|zinc|neutral|stone)-[0-9]{2,3}\b' src/features/vehicles -g '!**/*.test.tsx' -g '!**/__tests__/**' -g '!**/*.stories.tsx' -c`

New shared-shape components: none.

## Wave 4 — Procurement Family Rebuild

Status: in progress.

- 4.1 `SupplierInvoiceCreatePage` canonical shell/table slice:
  - RED: `pnpm --filter @autoerp/web test -- src/features/purchases/supplier-invoices/SupplierInvoiceCreatePage.test.tsx` failed on missing canonical cancel and save-variant footer actions.
  - GREEN: the create page now uses `PageHeader`, `StickyFormFooter`, `SaveSplitButton`, shared `DataTable` for manual and receipt lines, and `StatusBadge` for match preview state.
  - Existing invoice-first, receipt-prefill, duplicate-reference, and attachment-upload payload tests remain covered.
  - Baseline shrunk from 505 to 501 after removing stale C1/C3/C5 create-page fingerprints.
- 4.2 `SupplierInvoiceListPage` canonical table/status slice:
  - RED: `pnpm --filter @autoerp/web test -- src/features/purchases/supplier-invoices/SupplierInvoiceListPage.test.tsx` failed because invoice rows still rendered in a bespoke `divide-y` table instead of the shared `DataTable`.
  - GREEN: the list page now uses `PageHeader`, shared `DataTable`, and `StatusBadge` for invoice, match, and pending-receipt status pills.
  - Baseline shrunk from 501 to 496 after removing stale C1/C5/C6 list-page fingerprints.
- 4.3 `StandaloneReceiptPage` line-table slice:
  - RED: `pnpm --filter @autoerp/web test -- src/features/purchases/StandaloneReceiptPage.test.tsx` failed because receipt lines still rendered in a bespoke `divide-y` table.
  - GREEN: receipt lines now render through the shared `DataTable`, preserving the existing quantity, free-quantity, unit-price, product, and remove-line controls.
  - Baseline shrunk from 496 to 495 after removing the stale standalone receipt C5 fingerprint.
- 4.4 `QuoteRequestCreatePage` shell/action slice:
  - RED: `pnpm --filter @autoerp/web test -- src/features/purchases/quote-requests/QuoteRequestCreatePage.test.tsx` failed on missing canonical cancel and save-variant footer actions.
  - GREEN: the page now uses `PageHeader`, canonical `Button` controls for add/remove actions, and `StickyFormFooter` with `SaveSplitButton` for create/cancel actions.
  - Baseline shrunk from 495 to 490 after removing stale C1/C3 quote-request create fingerprints.
- 4.5 `GoodsReceiptListPage` shell/action slice:
  - RED: `pnpm --filter @autoerp/web audit:design-system` flagged stale baseline fingerprints after replacing the bespoke page title and invoice action button.
  - GREEN: the list page now uses `PageHeader` and canonical `Button` actions for scan, standalone receipt creation, and invoice-from-receipts while preserving the existing selection guardrails.
  - Baseline shrunk from 490 to 488 after removing stale C1/C3 goods-receipt list fingerprints.
- 4.6 `QuoteRequestListPage` shell/status slice:
  - RED: `pnpm --filter @autoerp/web audit:design-system` flagged the stale list-page C1 fingerprint after replacing the bespoke page title.
  - GREEN: the list page now uses `PageHeader` for title/subtitle/action layout and `StatusBadge` for group and document status pills while preserving existing grouped-row links.
  - Baseline shrunk from 488 to 487 after removing the stale C1 quote-request list fingerprint.
- 4.7 `QuoteRequestDetailPage` controls/status slice:
  - RED: `pnpm --filter @autoerp/web audit:design-system` flagged stale C2/C3 fingerprints after replacing bespoke response inputs and action buttons.
  - GREEN: the detail page now uses canonical `Input`, `Button`, and `StatusBadge` controls for response metadata, send/edit/convert/save actions, and document status.
  - Baseline shrunk from 487 to 480 after removing stale C2/C3 quote-request detail fingerprints.
- 4.8 `QuoteRequestComparisonPage` shell/action slice:
  - RED: `pnpm --filter @autoerp/web audit:design-system` flagged stale C1/C3 fingerprints after replacing the bespoke title and action buttons.
  - GREEN: the comparison page now uses `PageHeader` and canonical `Button` controls for reopen, per-supplier award, cancel, and confirm actions while preserving the comparison grid.
  - Baseline shrunk from 480 to 475 after removing stale C1/C3 quote-request comparison fingerprints.
- 4.9 `SupplierInvoiceDetailPage` controls/table slice:
  - RED: `pnpm --filter @autoerp/web audit:design-system` flagged stale C2/C3/C5/C6 fingerprints after replacing the receipt selector, action buttons, bespoke tables, and match-status switch.
  - GREEN: the detail page now uses canonical `Select`, `Button`, and `DataTable` controls for receipt linking, invoice actions, payment submission, invoice lines, and match rows while preserving payment and receipt-linking behavior.
  - Baseline shrunk from 475 to 467 after removing stale supplier-invoice detail fingerprints.
- 4.10 `SupplierInvoiceListPage` filter controls slice:
  - RED: `pnpm --filter @autoerp/web audit:design-system` flagged stale C2 filter fingerprints after replacing native filter inputs/selects.
  - GREEN: the list filters now use canonical `Input` and `Select` controls, and cursor pagination uses canonical `Button` controls while preserving existing filter API behavior.
  - Baseline shrunk from 467 to 462 after removing stale supplier-invoice list filter fingerprints.
- 4.11 `QuoteRequestCreatePage` input controls slice:
  - RED: `pnpm --filter @autoerp/web audit:design-system` flagged stale C2 fingerprints after replacing native line description, validity date, and notes inputs.
  - GREEN: the create page now uses canonical `Input` controls for those fields while preserving the existing fan-out payload behavior.
  - Baseline shrunk from 462 to 459 after removing stale quote-request create input fingerprints.
- 4.12 `SupplierInvoiceCreatePage` controls/status slice:
  - RED: `pnpm --filter @autoerp/web audit:design-system` flagged stale C2/C3/C6 fingerprints after replacing native controls and the match-preview switch.
  - GREEN: the create page now uses canonical `Input`, `Select`, and `Button` controls for invoice metadata, manual lines, entry modes, invoice-first delivery fields, attachments, and match-preview tone mapping while preserving create payload behavior.
  - Baseline shrunk from 459 to 439 after removing stale supplier-invoice create fingerprints.
- 4.13 Procurement create-page form-controller slice:
  - RED: `pnpm --filter @autoerp/web audit:design-system` reported stale C4 fingerprints after both procurement create pages gained `react-hook-form`.
  - GREEN: `QuoteRequestCreatePage` and `SupplierInvoiceCreatePage` now use `react-hook-form` with zod resolvers for top-level create metadata while preserving supplier, line, invoice-first, duplicate-reference, and upload behavior.
  - Baseline shrunk from 439 to 437 after removing stale procurement C4 fingerprints.
- Verification:
  - `pnpm --filter @autoerp/web test -- src/features/purchases/quote-requests/QuoteRequestCreatePage.test.tsx src/features/purchases/supplier-invoices/SupplierInvoiceCreatePage.test.tsx` passed: 20 tests.
  - `pnpm --filter @autoerp/web test -- src/features/purchases/supplier-invoices/SupplierInvoiceCreatePage.test.tsx` passed: 16 tests.
  - `pnpm --filter @autoerp/web test -- src/features/purchases/supplier-invoices/SupplierInvoiceListPage.test.tsx` passed: 14 tests.
  - `pnpm --filter @autoerp/web test -- src/features/purchases/StandaloneReceiptPage.test.tsx` passed: 5 tests.
  - `pnpm --filter @autoerp/web test -- src/features/purchases/quote-requests/QuoteRequestCreatePage.test.tsx` passed: 4 tests.
  - `pnpm --filter @autoerp/web test -- src/features/purchases/GoodsReceiptListPage.tenantScope.test.tsx` passed: 16 tests.
  - `pnpm --filter @autoerp/web test -- src/features/purchases/quote-requests/QuoteRequestListPage.test.tsx` passed: 2 tests.
  - `pnpm --filter @autoerp/web test -- src/features/purchases/quote-requests/QuoteRequestDetailPage.test.tsx` passed: 5 tests.
  - `pnpm --filter @autoerp/web test -- src/features/purchases/quote-requests/QuoteRequestComparisonPage.test.tsx` passed: 11 tests.
  - `pnpm --filter @autoerp/web test -- src/features/purchases/supplier-invoices/SupplierInvoiceDetailPage.test.tsx` passed: 18 tests.
  - `pnpm --filter @autoerp/web test -- src/features/purchases/supplier-invoices/SupplierInvoiceListPage.test.tsx` passed: 14 tests.
  - `pnpm --filter @autoerp/web typecheck` passed.
  - `pnpm --filter @autoerp/web lint` passed; existing warning count remains high, but 0 errors. The chained audits passed:
    - TanStack query key audit: 0 violations.
    - Design-system audit: 437 acknowledged, 0 new, 0 stale.
  - `npx react-doctor@latest --verbose --scope changed --base 5bc1d6b52` passed with no issues for the procurement create-page form-controller slice.
  - `npx react-doctor@latest --verbose --scope changed --base HEAD` passed with no issues for the create-page, list-page, standalone receipt, quote-request create, goods-receipt list, quote-request list, quote-request detail, quote-request comparison, supplier-invoice detail, supplier-invoice list filter, quote-request create input, and supplier-invoice create controls slices.

New shared-shape components: none.

## Wave 5 — Global Mechanical Sweep

Status: in progress.

- 5.1 `documents/` payment-status badge consolidation:
  - RED: `pnpm --filter @autoerp/web audit:design-system` reported a stale C6 fingerprint after removing the feature-local `PaymentStatusBadge` wrapper.
  - GREEN: invoice, sales-order, and purchase-order detail pages now render canonical `StatusBadge` directly with shared payment-status tone/icon metadata; `OutstandingAmountSection` imports the shared payment-status type.
  - Deleted `documents/components/PaymentStatusBadge.tsx` and its wrapper-only test.
  - Baseline shrunk from 437 to 436 after removing the stale documents C6 fingerprint.
- 5.2 `documents/` remaining C6 switches:
  - RED: `pnpm --filter @autoerp/web audit:design-system` reported stale C6 fingerprints after removing the document-list payment-status filter switch and the related-documents status-color switch.
  - GREEN: `DocumentListPage` now filters invoice payment statuses through a typed predicate map, and `RelatedDocumentsTab` renders document/current badges through canonical `StatusBadge` plus `statusTone`.
  - Baseline shrunk from 436 to 434 after removing the remaining documents C6 fingerprints.
- 5.3 `documents/` action-bar button atom:
  - RED: `pnpm --filter @autoerp/web audit:design-system` reported a stale C3 fingerprint after replacing the raw tokenized revert button.
  - GREEN: `DocumentActionBar` now renders the revert-to-draft action through the canonical `Button` atom.
  - Baseline shrunk from 434 to 433 after removing the stale documents C3 fingerprint.
- 5.4 `DeliveryNoteConsolidationPage` header slice:
  - RED: `pnpm --filter @autoerp/web audit:design-system` reported a stale C1 fingerprint after replacing the bespoke page title.
  - GREEN: the page now uses `PageHeader` with a canonical back `Button`, and imports `DeliveryNoteConsolidation` directly instead of the local barrel.
  - Baseline shrunk from 433 to 432 after removing the stale documents C1 fingerprint.
- 5.5 Credit/return note create-page headers:
  - RED: `pnpm --filter @autoerp/web audit:design-system` reported stale C1 fingerprints after replacing bespoke create-page titles.
  - GREEN: `CreateCreditNotePage` and `CreateReturnNotePage` now use `PageHeader` with canonical back `Button` actions while preserving existing form layouts.
  - Baseline shrunk from 432 to 430 after removing stale documents C1 fingerprints.
- Verification:
  - `pnpm --filter @autoerp/web test -- src/features/documents/__tests__/ReturnCreditNotePages.tenantScope.test.tsx` passed: 4 tests. Existing act-warning noise remains in the suite.
  - `pnpm --filter @autoerp/web test -- src/features/documents/components/__tests__/DocumentActionBar.test.tsx` passed: 2 tests. Existing act-warning noise remains in the component test.
  - `pnpm --filter @autoerp/web test -- src/features/documents/DocumentListPage.test.tsx src/features/documents/__tests__/DocumentTenantScope.test.tsx src/features/documents/components/__tests__/RelatedDocumentsTab.test.tsx` passed: 9 tests. Existing act-warning noise remains in `DocumentTenantScope`.
  - `pnpm --filter @autoerp/web test -- src/features/documents/invoices/__tests__/InvoiceDetailPage.tenantScope.test.tsx src/features/documents/sales-orders/__tests__/SalesOrderDetailPage.tenantScope.test.tsx src/features/documents/purchase-orders/__tests__/PurchaseOrderDetailPage.tenantScope.test.tsx` passed: 18 tests. Existing act-warning noise remains in those suites.
  - `pnpm --filter @autoerp/web typecheck` passed.
  - `pnpm --filter @autoerp/web lint` passed; existing warning count remains high, but 0 errors. The chained audits passed:
    - TanStack query key audit: 0 violations.
    - Design-system audit: 430 acknowledged, 0 new, 0 stale.
  - `npx react-doctor@latest --verbose --scope changed --base 87e1bb100` passed with no issues for the credit/return note create-page header slice.
  - `npx react-doctor@latest --verbose --scope changed --base 1a7f232ff` passed with no issues for the `DeliveryNoteConsolidationPage` header slice.
  - `npx react-doctor@latest --verbose --scope changed --base 6ca3fba0c` passed with no issues for the documents action-bar button slice.
  - `npx react-doctor@latest --verbose --scope changed --base bb480f355` passed with no issues for the documents remaining-C6 slice after replacing changed-page barrel imports with direct imports.
  - `npx react-doctor@latest --verbose --scope changed --base 744934164` passed with no issues for the documents payment-status slice.

New shared-shape components: none.

## Wave 3 — Partner Picker Consolidation

Status: complete.

- 3.1 `PartnerPicker` canonical behavior:
  - Added suggestions-on-focus, ID-only rehydration through `tenantScopedKey(['partner', id])`, `includeInactive`, and inline creation through `AddPartnerModal`.
  - Kept caller-owned creation flows via `onAddNew` for pages that need custom prefill.
  - Added focused coverage for focus suggestions, bare-ID rehydration, inactive inclusion, and inline creation.
- 3.2 Legacy picker migrations:
  - Replaced `PartnerSearchSelect` in `DocumentForm`, `CreateCreditNotePage`, `QuoteRequestCreatePage`, and `CustomerHistoryAuditPage`.
  - Replaced CRM `PartnerSelect` usage in contact create/edit and detail flows.
  - Replaced document-ingestion `SupplierPicker` with `PartnerPicker` plus a page-level `Create supplier` action for the prefilled modal flow.
  - Kept explicit labels/placeholders at migrated call sites; nested form-field call sites pass `label=""` to avoid duplicate visible labels.
- 3.3 Dead component removal:
  - Deleted `components/ui/PartnerSearchSelect.tsx`.
  - Deleted `features/crm/components/PartnerSelect.tsx`.
  - Deleted `features/document-ingestions/components/SupplierPicker.tsx` and its obsolete test.
  - Baseline shrunk from 507 to 505 after removing stale `SupplierPicker` design-system fingerprints.
- 3.4 Tenant-scope regression found during verification:
  - `LocationSwitcher` previously invalidated every query on location switch.
  - Updated it to invalidate all active tenant/company-suffixed queries without invalidating another tenant/company cache, and updated component/shared selector tests.
- Verification:
  - Focused Wave 3 suite passed: `pnpm vitest run src/components/molecules/pickers/PartnerPicker.test.tsx src/components/__tests__/SharedSelectors.tenantScope.test.tsx src/components/organisms/LocationSwitcher/__tests__/LocationSwitcher.test.tsx src/features/documents/DocumentForm.test.tsx src/features/documents/__tests__/DocumentForm.tenantScope.test.tsx src/features/documents/__tests__/ReturnCreditNotePages.tenantScope.test.tsx src/features/purchases/quote-requests/QuoteRequestCreatePage.test.tsx src/features/customer-history-audit/pages/__tests__/CustomerHistoryAuditPage.test.tsx src/features/crm/pages/__tests__/ContactFormPage.test.tsx src/features/crm/__tests__/tenantScope.test.tsx src/features/document-ingestions/__tests__/ReviewIngestionPage.test.tsx` passed: 76 tests.
  - `pnpm typecheck` passed.
  - `pnpm lint` passed; existing warning count remains high, but 0 errors. The chained audits passed:
    - TanStack query key audit: 0 violations.
    - Design-system audit: 505 acknowledged, 0 new, 0 stale.
  - `npx react-doctor@latest --verbose --scope changed --base HEAD` passed with one residual warning: `ContactFormPage` is still a large component. Splitting it is unrelated to this picker consolidation and was left out of scope.

New shared-shape components: `PartnerPicker` is now the canonical partner/supplier/customer picker.

## Wave 1 — High-Impact UX Corrections

Status: complete.

- 1.1 `LineItemEntryBar` suggestions-on-focus:
  - RED: `pnpm vitest run src/components/molecules/line-items/LineItemEntryBar.test.tsx` failed on an empty-query focus suggestion test.
  - GREEN: the entry bar now opens and fetches first-page product suggestions on focus while keeping scanner Enter resolution unchanged.
- 1.2 Workshop service affordance:
  - RED: `pnpm vitest run src/features/documents/components/__tests__/DocumentLineEditor.test.tsx` failed because the Workshop-gated `Service` control was absent.
  - GREEN: `DocumentLineEditor` now exposes a `ServicePicker` when Workshop is enabled and adds `is_service` document lines with service ids and service pricing.
- 1.3 Supplier-invoice ProductPicker filter:
  - RED: `pnpm vitest run src/features/purchases/supplier-invoices/SupplierInvoiceCreatePage.test.tsx --testNamePattern "all-products picker"` failed with `productType: undefined`.
  - GREEN: manual supplier-invoice lines now pass `productType="all"` so the picker does not inherit the `part` default.
- 1.4 Table density:
  - RED: focused density tests failed while `DocumentLineEditor` and `QuoteDetailPage` still exposed separate description tables/columns.
  - GREEN: editable document lines, read-only `DocumentLines`, quote detail lines, and supplier-invoice receipt rows now use article-cell description/notes density with `line-clamp-2`; long text remains available through `LineItemsTable` detail rows.
  - Baseline shrunk from 509 to 507 after removing two stale bespoke table fingerprints for `DocumentLines` and `QuoteDetailPage`.
- Verification:
  - `pnpm vitest run src/components/molecules/line-items/LineItemEntryBar.test.tsx src/features/documents/components/__tests__/DocumentLineEditor.test.tsx src/features/purchases/supplier-invoices/SupplierInvoiceCreatePage.test.tsx src/features/documents/quotes/__tests__/QuoteDetailPage.tenantScope.test.tsx` passed: 49 tests.
  - `pnpm typecheck` passed.
  - `pnpm lint` passed; existing warning count remains high, but 0 errors. The chained audits passed:
    - TanStack query key audit: 0 violations.
    - Design-system audit: 507 acknowledged, 0 new, 0 stale.
  - `npx react-doctor@latest --verbose --scope changed --base origin/dev` passed with no issues after replacing changed-page barrel imports in `QuoteDetailPage`.

New shared-shape components: `DocumentLines` now renders via `LineItemsTable`.

## Wave 2 — Company Designation Override Setting

Status: complete.

- 2.1 Company-backed feature source:
  - RED: `php artisan test tests/Feature/Api/CompanyConfigControllerTest.php --filter line_designation_override_comes_from_primary_company_setting` initially returned `false` while the company setting was `true`.
  - GREEN: added tenant migration `companies.line_designation_override_enabled boolean default false`, model fillable/cast metadata, and `CompanyConfigController` now reads the current company column for the existing `line_designation_override_enabled` payload key.
- 2.2 Settings update endpoint:
  - Added `line_designation_override_enabled` validation and update mapping in company settings.
  - Added a feature test proving true and false updates persist to the company row and return in the settings payload.
- 2.3 Settings UI:
  - RED: `pnpm vitest run src/features/settings/CompanyPage.test.tsx` failed because no line-designation switch existed.
  - GREEN: Company settings now include a Documents section with a `Toggle` for custom line designations; save submits the existing backend key.
  - Added EN/FR/AR copy. French helper text explicitly says an edited designation must remain a "dénomination précise" of the goods or services sold and is soft guidance, not automatic validation.
- 2.4 Env-flag removal:
  - Removed the old `FEATURE_DOCUMENT_LINE_DESIGNATION_OVERRIDE` deployment flag from `config/features.php`.
  - Document PDF line notes now receive `lineDesignationOverrideEnabled` from the document company instead of config. Factur-X was not touched.
  - Added PDF render coverage for notes hidden when the company setting is disabled.
- 2.5 Generated types:
  - `CompanySettingsData` is not annotated with `#[TypeScript]` and is not present in `packages/shared/types/generated.d.ts`; `php artisan typescript:transform` was not run to avoid unrelated generated-file churn.
- Verification:
  - `php artisan test tests/Feature/Api/CompanyConfigControllerTest.php --filter line_designation_override_comes_from_primary_company_setting` passed: 4 assertions.
  - `php artisan test tests/Feature/Tenant/CompanySettingsTest.php --filter line_designation_override_setting` passed: 6 assertions.
  - `php artisan test tests/Feature/Modules/Document/DocumentPdfRenderTest.php` passed: 5 tests, 15 assertions.
  - `./vendor/bin/phpstan analyse app/Http/Controllers/Api/CompanyConfigController.php app/Modules/Company/Domain/Company.php app/Modules/Tenant/Application/DTOs/CompanySettingsData.php app/Modules/Tenant/Presentation/Requests/UpdateCompanySettingsRequest.php app/Modules/Tenant/Presentation/Controllers/CompanySettingsController.php --memory-limit=1G` passed. Including the full touched `CompanySettingsTest.php` still reports pre-existing nullable/test fixture issues outside this wave.
  - `pnpm vitest run src/features/settings/CompanyPage.test.tsx` passed: 7 tests.
  - `pnpm typecheck` passed.
  - `pnpm lint` passed; existing warning count remains high, but 0 errors. The chained audits passed:
    - TanStack query key audit: 0 violations.
    - Design-system audit: 507 acknowledged, 0 new, 0 stale.
  - `npx react-doctor@latest --verbose --scope changed --base origin/dev` passed with no issues after replacing a changed-page barrel import in `CompanyPage`.

New shared-shape components: none.
