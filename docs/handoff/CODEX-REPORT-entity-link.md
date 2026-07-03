# EntityLink Convention Handoff

Date: 2026-07-02  
Worktree: `/Users/houssamr/Projects/syneriva/apps/erp.entitylink`  
Branch: `feat/entity-link-convention`

## Built

- Added `entityRoutes` as the central entity-detail route map in `apps/web/src/lib/entityRoutes.ts:38`.
- Added `documentRouteTypeFromSource` for safe document/source type normalization in `apps/web/src/lib/entityRoutes.ts:87`.
- Added `EntityLink` in `apps/web/src/components/molecules/EntityLink.tsx:54`.
  - Supports product, variant-to-product, customer, supplier, partner, document, payment, expense, stock transfer, goods receipt-to-PO, batch, and journal entry routes.
  - Falls back to `<span>` when an id or required target id is missing.
  - Uses `textColors.brand` plus `hover:underline` by default.
- Added an ESLint warning rule in `apps/web/eslint-rules/no-hardcoded-entity-route.js:1`.
  - Registered as `local/no-hardcoded-entity-route` in `apps/web/eslint.config.js:81`.
  - Scope: JSX `<Link>` / `<NavLink>` `to` props with hardcoded entity-detail prefixes.
  - Excludes `apps/web/src/lib/entityRoutes.ts`.
  - Warning level, so legacy hardcoded detail paths are visible without breaking CI.
- Added route and component tests:
  - `apps/web/src/lib/entityRoutes.test.ts:4`
  - `apps/web/src/components/molecules/EntityLink.test.tsx:6`

## Surfaces Touched

Already-fixed sites refactored to the convention:

- `apps/web/src/features/documents/components/RelatedDocumentsTab.tsx:101` uses `EntityLink` and the corrected delivery-note route through `entityRoutes`.
- `apps/web/src/features/inventory/components/ProductDocumentsTab.tsx:272` links document rows through `EntityLink`.
- `apps/web/src/features/inventory/components/ProductDocumentsTab.tsx:296` links partners through partner-type-aware `EntityLink`.
- `apps/web/src/features/stock-transfers/pages/StockTransferDetailPage.tsx:204` links transfer line products through `EntityLink`.

Document detail pages:

- `apps/web/src/features/documents/invoices/InvoiceDetailPage.tsx:366` customer header link.
- `apps/web/src/features/documents/invoices/InvoiceDetailPage.tsx:427` line product link.
- `apps/web/src/features/documents/quotes/QuoteDetailPage.tsx:277` customer header link.
- `apps/web/src/features/documents/quotes/QuoteDetailPage.tsx:338` line product link.
- `apps/web/src/features/documents/sales-orders/SalesOrderDetailPage.tsx:314` customer header link.
- `apps/web/src/features/documents/sales-orders/SalesOrderDetailPage.tsx:380` line product link.
- `apps/web/src/features/documents/purchase-orders/PurchaseOrderDetailPage.tsx:321` supplier header link.
- `apps/web/src/features/documents/purchase-orders/PurchaseOrderDetailPage.tsx:372` line product link.
- `apps/web/src/features/documents/delivery-notes/DeliveryNoteDetailPage.tsx:168` customer header link.
- `apps/web/src/features/documents/delivery-notes/DeliveryNoteDetailPage.tsx:237` line product link.
- `apps/web/src/features/documents/credit-notes/CreditNoteDetailPage.tsx:187` source invoice link via document route.
- `apps/web/src/features/documents/credit-notes/CreditNoteDetailPage.tsx:206` customer header link.
- `apps/web/src/features/documents/credit-notes/CreditNoteDetailPage.tsx:275` line product link.
- `apps/web/src/features/documents/components/PaymentHistorySection.tsx:114` payment-history rows link to payment detail.

Treasury / finance:

- `apps/web/src/features/treasury/PaymentDetailPage.tsx:482` partner link.
- `apps/web/src/features/treasury/PaymentDetailPage.tsx:526` allocation document link; supplier payments route to supplier invoice detail.
- `apps/web/src/features/treasury/RepositoryDetailPage.tsx:433` transaction payment link.
- `apps/web/src/features/treasury/RepositoryDetailPage.tsx:442` transaction partner link using payment context.
- `apps/web/src/features/treasury/RepositoryDetailPage.tsx:470` transaction allocation document link.
- `apps/web/src/features/finance/pages/JournalEntryListPage.tsx:88` entry number link.
- `apps/web/src/features/finance/pages/JournalEntryListPage.tsx:108` source ref link when `source_id` and a known `source_type` exist.
- `apps/web/src/features/finance/pages/JournalEntryDetailPage.tsx:194` source ref link when `source_id` and a known `source_type` exist.
- `apps/web/src/features/finance/pages/AgedReceivablesPage.tsx:120` customer link.
- `apps/web/src/features/finance/pages/AgedPayablesPage.tsx:113` supplier link.
- `apps/web/src/features/purchases/supplier-invoices/SupplierInvoiceDetailPage.tsx:150` supplier link.
- `apps/web/src/features/purchases/supplier-invoices/SupplierInvoiceDetailPage.tsx:251` source PO link through `EntityLink`.

Inventory / purchasing / dashboard:

- `apps/web/src/features/inventory/StockMovementsPage.tsx:226` product column uses `EntityLink`.
- `apps/web/src/features/purchases/GoodsReceiptListPage.tsx:311` PO row link.
- `apps/web/src/features/purchases/GoodsReceiptListPage.tsx:339` supplier link.
- `apps/web/src/features/purchases/GoodsReceiptListPage.tsx:387` product preview link.
- `apps/web/src/features/purchases/GoodsReceiptListPage.tsx:422` row view action routes through `EntityLink`.
- `apps/web/src/features/dashboard/Dashboard.tsx:339` recent document row link; delivery notes now route to `/inventory/delivery-notes/:id`.
- `apps/web/src/features/dashboard/Dashboard.tsx:376` recent payment row link.
- `apps/web/src/features/owner-dashboard/components/TopSkusWidget.tsx:50` top SKU product link.
- `apps/web/src/features/owner-dashboard/components/LowStockAlertsList.tsx:24` low-stock product link.

Create-flow quick wins:

- `apps/web/src/features/documents/CreateCreditNotePage.tsx:247` navigates to the created credit note detail route when the API returns an id.
- `apps/web/src/features/documents/CreateReturnNotePage.tsx:243` navigates to the created return note detail route when the API returns an id.

## Tests Added / Extended

- `apps/web/src/lib/entityRoutes.test.ts:4` covers every route builder.
- `apps/web/src/components/molecules/EntityLink.test.tsx:6` covers link rendering and missing-id fallback.
- `apps/web/src/features/treasury/PaymentDetailPage.test.tsx:142` asserts supplier allocation href.
- `apps/web/src/features/purchases/GoodsReceiptListPage.tenantScope.test.tsx:232` asserts PO, supplier, and product hrefs.
- `apps/web/src/features/dashboard/__tests__/Dashboard.tenantScope.test.tsx:168` asserts recent document/payment hrefs.
- `apps/web/src/features/finance/pages/AgedReceivablesPage.test.tsx:96` asserts customer href.
- `apps/web/src/features/finance/pages/AgedPayablesPage.test.tsx:96` asserts supplier href.
- `apps/web/src/features/owner-dashboard/__tests__/OwnerDashboardPage.test.tsx:117` asserts owner-dashboard product hrefs.
- `apps/web/src/features/documents/__tests__/ReturnCreditNotePages.tenantScope.test.tsx:246` and `:281` assert create-flow detail navigation.

Existing href assertions still cover:

- `apps/web/src/features/documents/components/__tests__/RelatedDocumentsTab.test.tsx:36`
- `apps/web/src/features/inventory/components/__tests__/ProductDocumentsTab.test.tsx:196`
- `apps/web/src/features/stock-transfers/__tests__/StockTransferDetailPage.test.tsx:60`

## Skipped / Follow-Up Payload Gaps

- `apps/web/src/features/inventory/StockMovementsPage.tsx`: source document reference is still plain text because the row payload exposes `reference` only, not `source_document_id` and `source_document_type`.
- `apps/web/src/features/inventory/components/ProductMovementsTab.tsx`: same source-document limitation as stock movements; existing search-link behavior was not changed because the structural API fix is out of scope.
- `apps/web/src/features/treasury/PaymentDetailPage.tsx`: allocation payload lacks explicit `document_type`; implementation uses `payment_type` fallback (`supplier_payment` -> supplier invoice, `advance` -> sales order, otherwise invoice). Follow-up should add `allocations[].document_type`.
- `apps/web/src/features/treasury/RepositoryDetailPage.tsx`: transaction allocation payload lacks explicit `document_type`; same fallback as payment detail. Transaction partner payload also lacks explicit `partner_type`; implementation uses `payment_type` fallback.
- `apps/web/src/features/treasury/InstrumentDetailPage.tsx`: partner has `partner_id` but no `partner.type` / `partner_type`; skipped to avoid guessing customer vs supplier.
- `apps/web/src/features/purchases/supplier-invoices/SupplierInvoiceDetailPage.tsx`: invoice lines expose no `product_id`; 3-way-match rows expose `po_line_id` only, not a goods-receipt id. Supplier and source PO were linked.
- `apps/web/src/features/expenses/pages/ExpenseDetailPage.tsx` and `apps/web/src/features/expenses/components/organisms/ExpenseCard.tsx`: vendor/category are metadata/category labels with no partner/detail target in the current payload.
- Locations and users remain plain text where present because the audit states there is no Location detail page or User activity/detail page in scope.
- Structural tier not done: tab deep-linking, stock movement source ids, Location page, goods receipt canonical URL, batch movements ledger, pagination changes, Partner/Product tab URL state.

## ESLint Rule Behavior

Rule: `local/no-hardcoded-entity-route`

Flags examples like:

```tsx
<Link to={`/inventory/products/${product.id}`} />
<Link to={`/sales/invoices/${invoice.id}`} />
```

Does not flag:

- Non-JSX API paths.
- List/create paths like `/sales/invoices/new`.
- `entityRoutes.ts` itself.
- Dynamic routes produced by `EntityLink`.

Known legacy offenders will now warn, including product list/detail related links, withholding invoice/payment links, partner detail payment rows, return-note metadata/source links, and other audit-listed hardcoded detail paths. Warning level is intentional.

## Gate Outputs

Dependency setup:

```bash
pnpm install
```

Result: failed. `pnpm` could not download missing packages because the sandbox cannot resolve `registry.npmjs.org`:

```text
ERR_PNPM_NO_OFFLINE_TARBALL ... enhanced-resolve-5.18.3.tgz
ENOTFOUND request to https://registry.npmjs.org/jiti/-/jiti-2.6.1.tgz
```

Checks that ran:

```bash
git diff --check
node --check apps/web/eslint-rules/no-hardcoded-entity-route.js
```

Result: both passed with exit code 0.

Required Node gates attempted:

```bash
pnpm lint
pnpm typecheck
pnpm --filter @autoerp/web test -- src/lib/entityRoutes.test.ts src/components/molecules/EntityLink.test.tsx src/features/treasury/PaymentDetailPage.test.tsx src/features/purchases/GoodsReceiptListPage.tenantScope.test.tsx src/features/dashboard/__tests__/Dashboard.tenantScope.test.tsx src/features/finance/pages/AgedReceivablesPage.test.tsx src/features/finance/pages/AgedPayablesPage.test.tsx src/features/owner-dashboard/__tests__/OwnerDashboardPage.test.tsx src/features/documents/__tests__/ReturnCreditNotePages.tenantScope.test.tsx
```

Result: all failed before executing project checks because `node_modules` is incomplete:

```text
eslint: command not found
tsc: command not found
vitest: command not found
Local package.json exists, but node_modules missing
```

Re-run after dependency install succeeds:

```bash
pnpm install
pnpm lint
pnpm typecheck
pnpm --filter @autoerp/web test -- src/lib/entityRoutes.test.ts src/components/molecules/EntityLink.test.tsx src/features/treasury/PaymentDetailPage.test.tsx src/features/purchases/GoodsReceiptListPage.tenantScope.test.tsx src/features/dashboard/__tests__/Dashboard.tenantScope.test.tsx src/features/finance/pages/AgedReceivablesPage.test.tsx src/features/finance/pages/AgedPayablesPage.test.tsx src/features/owner-dashboard/__tests__/OwnerDashboardPage.test.tsx src/features/documents/__tests__/ReturnCreditNotePages.tenantScope.test.tsx
```

## Reviewer Notes

- The convention deliberately renders fallback text instead of a broken link when an id or required target id is absent.
- The treasury allocation route still needs backend `document_type` for full correctness. The current fallback is better than the old hardcoded sales invoice route, but explicit payload type should be added.
- The new ESLint warning will increase warning count until legacy hardcoded detail links are migrated.
- Commit was attempted but blocked by sandbox permissions. This linked worktree stores git metadata at `/Users/houssamr/Projects/syneriva/apps/erp/.git/worktrees/erp.entitylink`, outside the writable sandbox root, so `git commit` failed while creating `index.lock`:

```text
fatal: Unable to create '/Users/houssamr/Projects/syneriva/apps/erp/.git/worktrees/erp.entitylink/index.lock': Operation not permitted
```
