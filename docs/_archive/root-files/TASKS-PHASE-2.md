# AutoERP - Phase 2 Task Tracker

> **Claude Code: This file continues from TASKS.md (Phases 1-10 complete)**
> Complete tasks in order. Run verification after each task. Never skip verification.

---

## Current Status

**Phase:** 14 - Products & Inventory (Frontend)
**Prerequisites Complete:** Phases 1-13 complete
**Last Updated:** 2025-11-30
**Last Audit:** 2025-11-30

### Audit Summary (2025-11-30)
- Phase 11: ✅ Complete - Navigation restructured, routes working
- Phase 12: ✅ Complete - Sales module (Customers, Quotes, Orders, Invoices, Credit Notes)
- Phase 13: ⚠️ Partial - Suppliers/PO work, supplier invoices share document system
- Phase 14: ✅ Partial - Products CRUD, Stock Levels view implemented
- Phase 15-19: ❌ Not Started (placeholders)

### Fixed Bugs (2025-11-30)
- **FIXED: Document creation (quotes/invoices/orders)** - Backend bcmul() type error when quantity is integer instead of string (DocumentController.php:226) - Fixed by casting to string

---

## Verification Protocol

Same as TASKS.md — TDD is mandatory:

1. ✅ Write test FIRST
2. ✅ Run test — confirm FAIL (red)
3. ✅ Implement
4. ✅ Run test — confirm PASS (green)
5. ✅ Run `./scripts/preflight.sh`
6. ✅ Mark task `[x]`
7. ✅ Commit with conventional message
8. ✅ Next task

---

## Phase 11: Navigation & Module Structure ✅ COMPLETE

### 11.1 Sidebar Restructure
- [x] Replace flat navigation with module-based structure
- [x] Implement collapsible menu sections
- [x] Add icons for each module (use lucide-react)
- [x] Persist expanded/collapsed state to localStorage
- [x] Ensure RTL support (sidebar flips to right)

### 11.2 Route Structure
- [x] Create route structure matching navigation
- [x] Implement lazy loading for route modules
- [x] Add breadcrumb navigation

### 11.3 Migrate Existing Views
- [x] Move Partners list/detail → Customers (filter: type=customer)
- [x] Move Partners list/detail → Suppliers (filter: type=supplier)
- [x] Move Documents list/detail → appropriate Sales/Purchases routes
- [x] Update all internal links to use new routes
- [x] Remove old generic routes (/partners, /documents) - redirects in place

### 11.4 Permission-Based Navigation
- [x] Hide menu items user doesn't have permission for
- [x] Redirect unauthorized route access to dashboard with message
- [x] Show permission denied state for partial access

**Verification:** ✅ Passed

---

## Phase 12: Sales Module (Frontend) ✅ COMPLETE

### 12.1 Customers View
- [x] Customer list with filters (active, inactive, all)
- [x] Customer search (name, email, VAT number)
- [x] Customer detail view with tabs:
  - Overview (contact info, balance)
  - Documents (quotes, orders, invoices)
  - Payments history
  - Vehicles (linked vehicles)
- [x] Customer create/edit form
- [x] Quick actions: New Quote, New Invoice

### 12.2 Quotes View
- [x] Quote list with status filters (draft, sent, accepted, rejected, expired)
- [x] Quote detail view with:
  - Header info (customer, dates, validity)
  - Line items (editable)
  - Totals (subtotal, tax, total)
  - Status actions (send, accept, reject)
- [x] Quote create/edit form
- [x] Convert to Sales Order action
- [x] Duplicate quote action

### 12.3 Document Line Editor (Previously Deferred)
- [x] Inline line editing in document detail view
- [x] Add line: product search → auto-fill price, description
- [x] Edit line: quantity, unit price, discount, tax rate
- [x] Remove line with confirmation
- [x] Drag-and-drop line reordering
- [x] Real-time total recalculation
- [x] Keyboard navigation (Tab between fields, Enter to save)

### 12.4 Sales Orders View
- [x] Order list with status filters (draft, confirmed, partially_delivered, delivered, invoiced)
- [x] Order detail view with line items
- [x] Order create/edit form
- [x] Convert to Invoice action
- [x] Generate Delivery Note action
- [x] Partial delivery tracking

### 12.5 Invoices View (Sales)
- [x] Invoice list with status filters (draft, posted, partial, paid, overdue, cancelled)
- [x] Invoice detail view with:
  - Header info
  - Line items (read-only after posting)
  - Payment status and history
  - Linked documents (order, delivery notes)
- [x] Invoice create from scratch
- [x] Invoice create from order
- [x] Post invoice action (with confirmation — irreversible)
- [x] Cancel invoice action (creates credit note)
- [x] Record payment quick action

### 12.6 Credit Notes View
- [x] Credit note list
- [x] Credit note detail view
- [x] Create from invoice (partial or full)
- [x] Apply to outstanding invoices

**Verification:** ✅ Passed

---

## Phase 13: Purchases Module (Frontend) ⚠️ PARTIAL

### 13.1 Suppliers View
- [x] Supplier list with filters
- [x] Supplier search
- [x] Supplier detail view with tabs:
  - Overview
  - Purchase Orders
  - Supplier Invoices
  - Payments made
- [x] Supplier create/edit form

### 13.2 Purchase Orders View
- [x] Purchase order list with status filters
- [x] Purchase order detail with line items (using shared line editor)
- [x] Purchase order create/edit
- [x] Receive goods action (updates stock)
- [x] Link to supplier invoice

### 13.3 Supplier Invoices View
- [ ] Supplier invoice list <!-- TODO: N/A - uses same document system as sales invoices -->
- [ ] Supplier invoice detail <!-- TODO: N/A - uses same document system -->
- [ ] Register supplier invoice (manual entry) <!-- TODO: N/A -->
- [ ] Match to purchase order <!-- TODO: N/A -->
- [ ] Record payment to supplier <!-- TODO: N/A -->

**Verification:** ⚠️ Partial (13.3 not applicable - shared document system)

---

## Phase 14: Products & Inventory (Frontend) ✅ MOSTLY COMPLETE

### 14.1 Products View
- [x] Product list with:
  - Grid and list view toggle
  - Category filter
  - Type filter (product, service, part)
  - Active/inactive filter
- [ ] Product search with Meilisearch (Previously Deferred): <!-- TODO: uses basic search, not Meilisearch -->
  - Real-time search as you type
  - Search by: name, SKU, OEM numbers, cross-references
  - Highlight matching terms
- [x] Product detail view:
  - Basic info (name, SKU, description)
  - Pricing (cost, sell price, margins)
  - Automotive fields (OEM numbers, cross-references, compatibility)
  - Stock levels across locations
  - Movement history
- [x] Product create/edit form
- [ ] Bulk import action (link to import wizard) <!-- TODO: Import wizard being implemented -->

### 14.2 Meilisearch Integration (Previously Deferred)
- [ ] Configure Meilisearch index for products <!-- TODO: not implemented - using basic API search -->
- [ ] Index: name, sku, description, oem_numbers, cross_references <!-- TODO: not implemented -->
- [ ] Implement search API endpoint <!-- TODO: not implemented -->
- [x] Frontend: debounced search input (basic implementation)
- [ ] Frontend: search results dropdown for line item entry <!-- TODO: not implemented -->

### 14.3 Stock Levels View
- [x] Stock level list by location
- [x] Low stock alerts highlighted
- [ ] Stock level adjustments (with reason codes): <!-- TODO: not implemented -->
  - Inventory count
  - Damage/loss
  - Correction
- [x] Stock movement history per product (via StockMovementsPage)

### 14.4 Stock Movements View ✅ IMPLEMENTED (2025-12-02 audit)
- [x] Movement history list (StockMovementsPage.tsx)
- [x] Filter by: type (receipt, issue, adjustment, transfer), location
- [x] Movement types: receipt, issue, adjustment, transfer_in, transfer_out
- [x] Linked to products (click to navigate)

### 14.5 Delivery Notes View
- [ ] Delivery note list <!-- TODO: placeholder page -->
- [ ] Delivery note detail: <!-- TODO: not implemented -->
  - Header (customer, address, date)
  - Line items
  - Status (draft, dispatched, delivered)
- [ ] Create from sales order <!-- TODO: not implemented -->
- [ ] Mark as dispatched/delivered <!-- TODO: not implemented -->

**Verification:** ✅ Mostly Complete - Products CRUD, Stock Levels, Stock Movements all working. Meilisearch/Delivery Notes deferred.

---

## Phase 15: Vehicles (Frontend) ✅ MOSTLY COMPLETE (2025-12-02 audit)

### 15.1 Vehicle Registry
- [x] Vehicle list with filters (VehicleListPage.tsx):
  - Search by license plate, VIN, brand, model
- [x] Vehicle search (VIN, license plate, brand, model)
- [x] Vehicle detail view (VehicleDetailPage.tsx):
  - Basic info (VIN, make, model, year, color)
  - Technical details (mileage, fuel type, transmission, engine code)
  - License plate display
  - Notes section
- [x] Vehicle create/edit form (VehicleForm.tsx)

### 15.2 Vehicle-Customer Linking
- [ ] Assign vehicle to customer <!-- TODO: partner_id exists in model but UI not implemented -->
- [ ] View customer's vehicles from customer detail <!-- TODO: not implemented -->
- [ ] Create quote/invoice with vehicle context <!-- TODO: not implemented -->

### 15.3 Vehicle History
- [ ] Timeline of all documents linked to vehicle <!-- TODO: not implemented -->
- [ ] Filter by document type <!-- TODO: not implemented -->
- [x] Mileage tracking field exists in vehicle detail

**Verification:** ✅ Mostly Complete - Core CRUD working, customer linking and history views pending

---

## Phase 16: Treasury Enhancements ⚠️ PARTIAL (2025-12-02 audit)

### 16.1 Payment Allocation Modal (Previously Deferred)
- [ ] Modal to allocate payment to multiple invoices <!-- TODO: not implemented -->
- [ ] Show open invoices for customer <!-- TODO: not implemented -->
- [ ] Auto-suggest allocation (oldest first) <!-- TODO: not implemented -->
- [ ] Manual allocation with remaining amount display <!-- TODO: not implemented -->
- [ ] Partial payment support <!-- TODO: not implemented -->
- [ ] Over-payment handling (create credit) <!-- TODO: not implemented -->

### 16.2 Instrument Lifecycle UI (Previously Deferred)
- [x] Instrument list page (InstrumentListPage.tsx)
- [x] Instrument detail page (InstrumentDetailPage.tsx)
- [ ] Visual status pipeline: Received → Deposited → Cleared (or Bounced) <!-- TODO: not implemented -->
- [ ] Batch actions for checks <!-- TODO: not implemented -->
- [ ] Maturity calendar view <!-- TODO: not implemented -->
- [ ] Bounced check workflow <!-- TODO: not implemented -->

### 16.3 Bank Reconciliation (Previously Deferred)
- [ ] Bank statement import (CSV/OFX) <!-- TODO: not implemented -->
- [ ] Matching interface <!-- TODO: not implemented -->
- [ ] Reconciliation summary <!-- TODO: not implemented -->
- [ ] Mark period as reconciled <!-- TODO: not implemented -->

### 16.4 Payment Repository Management ✅ IMPLEMENTED
- [x] Repository list with summary cards (RepositoryListPage.tsx):
  - Grouped by type (cash register, safe, bank account, virtual)
  - Balance display per type and total
- [x] Add repository modal with:
  - Code, name, type selection
  - Bank account fields (bank name, account number, IBAN, BIC)
- [ ] Repository detail view with transactions <!-- TODO: not implemented -->
- [ ] Transfer between repositories <!-- TODO: not implemented -->
- [ ] Cash count/adjustment <!-- TODO: not implemented -->

**Verification:** ⚠️ Partial - Repository list and instruments list working, advanced features pending

---

## Phase 17: PDF Generation (Previously Deferred) ❌ NOT STARTED

### 17.1 PDF Infrastructure
- [ ] Install PDF library (recommend: `barryvdh/laravel-dompdf` or `spatie/laravel-pdf`) <!-- TODO: not implemented -->
- [ ] Create base PDF template with: <!-- TODO: not implemented -->
  - Company header (logo, name, address, VAT)
  - Document styling (fonts, colors)
  - Footer (page numbers, generated date)
- [ ] Tenant-specific customization (logo, colors) <!-- TODO: not implemented -->

### 17.2 Invoice PDF
- [ ] Invoice PDF template: <!-- TODO: not implemented -->
  - Header: Invoice number, date, due date
  - Customer info block
  - Line items table (description, qty, price, tax, total)
  - Totals section (subtotal, tax breakdown, total)
  - Payment terms
  - Bank details
- [ ] Download PDF action <!-- TODO: not implemented -->
- [ ] Email PDF action <!-- TODO: not implemented -->
- [ ] Batch PDF generation <!-- TODO: not implemented -->

### 17.3 Quote PDF
- [ ] Quote PDF template (similar to invoice) <!-- TODO: not implemented -->
- [ ] Validity period prominent <!-- TODO: not implemented -->
- [ ] Terms and conditions section <!-- TODO: not implemented -->

### 17.4 Delivery Note (DDT) PDF
- [ ] DDT PDF template: <!-- TODO: not implemented -->
  - Sender/receiver addresses
  - Line items (no prices — just quantities)
  - Transport info
  - Signature capture area (Previously Deferred):
    - [ ] Digital signature pad component <!-- TODO: not implemented -->
    - [ ] Store signature as image <!-- TODO: not implemented -->
    - [ ] Embed signature in PDF <!-- TODO: not implemented -->
- [ ] QR code with document reference <!-- TODO: not implemented -->

### 17.5 Purchase Order PDF
- [ ] PO PDF template <!-- TODO: not implemented -->
- [ ] Send to supplier action <!-- TODO: not implemented -->

**Verification:** ❌ Not started

---

## Phase 18: Reports & Analytics ⚠️ PARTIAL (2025-12-02 audit)

### 18.1 Report Infrastructure
- [ ] Report generation service <!-- TODO: not implemented -->
- [ ] Date range picker component <!-- TODO: not implemented -->
- [ ] Export options: PDF, Excel, CSV <!-- TODO: not implemented -->
- [ ] Report caching for performance <!-- TODO: not implemented -->

### 18.2 Financial Reports ✅ IMPLEMENTED (pages exist in /finance)
- [x] Profit & Loss statement (ProfitLossPage.tsx)
- [x] Balance Sheet (BalanceSheetPage.tsx)
- [x] Trial Balance (TrialBalancePage.tsx)
- [x] General Ledger detail (GeneralLedgerPage.tsx)
- [x] Aged Receivables (AgedReceivablesPage.tsx)
- [x] Aged Payables (AgedPayablesPage.tsx)
- [x] Journal Entries (JournalEntryListPage.tsx, JournalEntryDetailPage.tsx, JournalEntryForm.tsx)
- [x] Chart of Accounts (ChartOfAccountsPage.tsx)

### 18.3 Sales Reports
- [ ] Sales by period (daily, weekly, monthly, yearly) <!-- TODO: not implemented -->
- [ ] Sales by customer (top customers) <!-- TODO: not implemented -->
- [ ] Sales by product (top products) <!-- TODO: not implemented -->
- [ ] Sales by category <!-- TODO: not implemented -->
- [ ] Quote conversion rate <!-- TODO: not implemented -->
- [ ] Average order value <!-- TODO: not implemented -->

### 18.4 Inventory Reports
- [ ] Current stock valuation <!-- TODO: not implemented -->
- [ ] Stock movement summary <!-- TODO: not implemented -->
- [x] Low stock report (in ReportsPage.tsx - low stock alerts section)
- [ ] Dead stock report (no movement in X days) <!-- TODO: not implemented -->
- [ ] Stock turnover <!-- TODO: not implemented -->

### 18.5 Dashboard Enhancements ✅ BASIC IMPLEMENTED (ReportsPage.tsx)
- [x] Revenue metrics: Total Invoiced, Total Collected, Outstanding, Quotes Pending
- [x] Document summary: Quotes, Sales Orders, Invoices counts
- [x] Partner summary: Customers, Suppliers counts
- [x] Low stock alert table with product list
- [ ] Customizable widgets <!-- TODO: not implemented -->
- [ ] Date range selector for KPIs <!-- TODO: not implemented -->
- [ ] Comparison to previous period <!-- TODO: not implemented -->
- [ ] Charts: revenue trend, top customers, top products <!-- TODO: not implemented -->

**Verification:** ⚠️ Partial - Basic dashboard metrics implemented, advanced reports not done

---

## Phase 19: Settings & User Management ✅ MOSTLY COMPLETE (2025-12-02 audit)

### 19.1 Company Settings ✅ IMPLEMENTED (CompanyPage.tsx)
- [x] Company profile:
  - Name, legal name
  - Address (street, city, postal code, country)
  - Tax ID / VAT number
  - Registration number
  - Logo upload with drag-and-drop, replace, delete
  - Contact info (phone, email, website)
- [x] Regional settings:
  - Currency selector (EUR, USD, GBP, TND, MAD, DZD)
  - Timezone selector
  - Date format selector
  - Language/locale selector
- [x] Primary color branding

### 19.2 User Management ✅ IMPLEMENTED (UsersPage.tsx)
- [x] User list with status filter (all, active, inactive, pending)
- [x] User search
- [x] User display with role badges, last login
- [x] User create/invite flow with modal:
  - Name, email, phone, role selection
  - Sends invitation email
- [x] Activate/deactivate user actions
- [x] Password reset (admin initiated)
- [x] Delete user

### 19.3 Role & Permission Management
- [x] Role list page exists (RolesPage.tsx)
- [ ] Role detail with permissions grid <!-- TODO: not implemented -->
- [ ] Create custom role <!-- TODO: not implemented -->
- [ ] Edit role permissions <!-- TODO: not implemented -->
- [ ] Permission matrix view (role × permission) <!-- TODO: not implemented -->

### 19.4 Audit Log View
- [ ] Searchable audit log <!-- TODO: not implemented -->
- [ ] Filter by: user, action type, entity, date range <!-- TODO: not implemented -->
- [ ] Detail view: what changed (before/after) <!-- TODO: not implemented -->
- [ ] Export audit log <!-- TODO: not implemented -->

### 19.5 Data Management
- [x] Import wizard access (Settings → Data Import, /settings/import)
- [ ] Export data (customers, products, transactions) <!-- TODO: not implemented -->
- [ ] Data retention settings <!-- TODO: not implemented -->

**Verification:** ✅ Mostly Complete - Company and User Management fully working, Roles/Audit/Import pending

---

## Phase 20: E-Invoicing - Factur-X (France) ❌ OPTIONAL - NOT STARTED

> **Note:** This phase is optional. Skip if not targeting France immediately.
> Standard PDFs from Phase 17 work for Tunisia and other markets.

### 20.1 Factur-X Infrastructure
- [ ] Install Factur-X library (`atgp/factur-x` or equivalent) <!-- TODO: optional - not implemented -->
- [ ] Create XML generation service <!-- TODO: optional - not implemented -->
- [ ] Implement Factur-X profiles: <!-- TODO: optional - not implemented -->
  - MINIMUM (basic compliance)
  - BASIC (recommended starting point)
  - EN16931 (full European standard — later)

### 20.2 XML Generation
- [ ] Map invoice data to Factur-X XML: <!-- TODO: optional - not implemented -->
  - Seller information
  - Buyer information
  - Invoice lines
  - Tax breakdown
  - Payment terms
  - Bank details
- [ ] Validate XML against schema <!-- TODO: optional - not implemented -->

### 20.3 PDF/A-3 Embedding
- [ ] Generate PDF/A-3 compliant invoice <!-- TODO: optional - not implemented -->
- [ ] Embed Factur-X XML as attachment <!-- TODO: optional - not implemented -->
- [ ] Add XMP metadata <!-- TODO: optional - not implemented -->
- [ ] Verify with Factur-X validator <!-- TODO: optional - not implemented -->

### 20.4 Compliance Verification
- [ ] Integration with FNFE-MPE validator (French validation service) <!-- TODO: optional - not implemented -->
- [ ] Store validation results <!-- TODO: optional - not implemented -->
- [ ] Handle validation errors <!-- TODO: optional - not implemented -->

**Verification:** ❌ Optional - Not started

---

## Deferred Items Tracking

Items from Phase 1 TASKS.md and where they're now addressed:

| Deferred Item | Now In Phase |
|---------------|--------------|
| Product search with Meilisearch | Phase 14.2 |
| Document line editing | Phase 12.3 |
| Document PDF generation | Phase 17 |
| DDT PDF template | Phase 17.4 |
| Signature capture | Phase 17.4 |
| Payment allocation modal | Phase 16.1 |
| Instrument lifecycle UI | Phase 16.2 |
| Reconciliation service | Phase 16.3 |
| Default chart of accounts seeder | Phase 19.1 (part of company setup) |

---

## Commit Convention

Same as TASKS.md:
- `feat(module): description`
- `fix(module): description`
- `refactor(module): description`
- `test(module): description`
- `docs(module): description`
- `chore: description`

---

## Quality Gates

Before marking any phase complete:

```bash
# Full verification
./scripts/preflight.sh

# E2E tests for the phase
pnpm playwright test --grep "Phase XX"

# No TypeScript errors
pnpm typecheck

# No lint errors
pnpm lint

# All backend tests pass
php artisan test --parallel
```

---

*Last Updated: 2025-11-30*
