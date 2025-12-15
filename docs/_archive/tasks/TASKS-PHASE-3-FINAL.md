# AutoERP - Phase 3: Common Platform Features

> **Goal:** Complete all ERP functionality needed by BOTH automotive AND retail/QSR verticals. After Phase 3, we fork and add vertical-specific features separately.

---

## What's Common vs Vertical-Specific

### Phase 3: Common (Build Now)

| Feature | Why Common |
|---------|------------|
| Finance Reports | Every business needs P&L, Balance Sheet |
| Country Adaptation | Tunisia setup needed for all |
| Subscription Tracking | Platform-level, applies to all |
| Super Admin | Manage all tenants regardless of vertical |
| Full Sale Lifecycle | Quote → Invoice → Payment is universal |
| Refunds & Cancellations | Every business handles returns |
| Multi-payment Options | Split payments, deposits are universal |
| Pricing Rules & Discounts | Every business has pricing strategies |
| Advanced Permissions | Access control is universal |

### Post-Fork: Automotive (Phase 4A)

| Feature | Why Automotive-Specific |
|---------|------------------------|
| Work Orders | Repair job tracking |
| Workshop Calendar | Scheduling repairs |
| Vehicle Catalog | Car parts database |
| TecDoc Integration | Parts cross-reference |
| VIN Lookup | Vehicle identification |
| Labor Rates | Service pricing |

### Post-Fork: Retail/QSR (Phase 4B)

| Feature | Why Retail/QSR-Specific |
|---------|------------------------|
| POS Interface | Quick-service checkout |
| Variations/Modifiers | "Large, no sugar, extra shot" |
| Kitchen Display | Order routing |
| Table Management | Restaurant seating |
| Reservations | Booking tables |

---

## Phase 3 Sections

| Section | Focus | Est. Hours |
|---------|-------|------------|
| 3.1 | Finance Reports UI | 12-16 |
| 3.2 | Country Adaptation (Tunisia) | 8-10 |
| 3.3 | Subscription Tracking | 6-8 |
| 3.4 | Super Admin Dashboard | 10-14 |
| 3.5 | Full Sale Lifecycle | 10-14 |
| 3.6 | Refunds & Cancellations | 6-8 |
| 3.7 | Multi-Payment Options | 8-10 |
| 3.8 | Pricing Rules & Discounts | 10-12 |
| 3.9 | Advanced Permissions | 8-10 |
| 3.10 | Final QA & Polish | 8-12 |
| **Total** | | **86-114 hours** |

---

## 3.1 Finance Reports UI

### Overview

Build frontend for financial reporting. Backend accounting exists; we need user-facing reports.

### 3.1.1 Chart of Accounts Page

**Route:** `/settings/chart-of-accounts`

**Features:**
- Hierarchical tree view of accounts
- Account types: Assets, Liabilities, Equity, Revenue, Expenses
- Expandable/collapsible groups
- Search/filter accounts
- Add/edit account (modal)
- View account transactions (link to ledger)

**Tasks:**
- [ ] Create ChartOfAccountsPage component
- [ ] Create AccountTreeView component
- [ ] Create AddAccountModal component
- [ ] Create EditAccountModal component
- [ ] API integration: GET/POST/PATCH /accounts

### 3.1.2 General Ledger Page

**Route:** `/finance/ledger`

**Features:**
- Filter by: Account, Date range, Amount range
- Columns: Date, Reference, Description, Debit, Credit, Balance
- Running balance per account
- Export to Excel/CSV
- Drill-down to source document

**Tasks:**
- [ ] Create GeneralLedgerPage component
- [ ] Create LedgerFilters component
- [ ] Create LedgerTable component
- [ ] API: GET /ledger with filters
- [ ] Export functionality

### 3.1.3 Trial Balance Report

**Route:** `/finance/reports/trial-balance`

**Features:**
- As-of date selector
- Show/hide zero balances
- Table: Account Code, Name, Debit, Credit
- Totals (must balance)
- Export to PDF and Excel

**Tasks:**
- [ ] Create TrialBalancePage component
- [ ] API: GET /reports/trial-balance
- [ ] PDF generation
- [ ] Excel export

### 3.1.4 Profit & Loss Statement

**Route:** `/finance/reports/profit-loss`

**Features:**
- Date range selector
- Comparison option (vs previous period)
- Sections: Revenue, COGS, Gross Profit, Expenses, Net Profit
- Expandable line items
- Export to PDF and Excel

**Tasks:**
- [ ] Create ProfitLossPage component
- [ ] API: GET /reports/profit-loss
- [ ] Comparison logic
- [ ] PDF and Excel export

### 3.1.5 Balance Sheet

**Route:** `/finance/reports/balance-sheet`

**Features:**
- As-of date selector
- Sections: Assets, Liabilities, Equity
- Must balance: Assets = Liabilities + Equity
- Expandable line items
- Export to PDF and Excel

**Tasks:**
- [ ] Create BalanceSheetPage component
- [ ] API: GET /reports/balance-sheet
- [ ] PDF and Excel export

### 3.1.6 Aged Receivables Report

**Route:** `/finance/reports/aged-receivables`

**Features:**
- As-of date selector
- Aging buckets: Current, 1-30, 31-60, 61-90, 90+ days
- Group by customer
- Drill-down to unpaid invoices
- Export to PDF and Excel

**Tasks:**
- [ ] Create AgedReceivablesPage component
- [ ] API: GET /reports/aged-receivables
- [ ] Customer drill-down
- [ ] Export functionality

### 3.1.7 Aged Payables Report

**Route:** `/finance/reports/aged-payables`

**Features:**
- Same as receivables but for suppliers
- What we owe, by aging bucket

**Tasks:**
- [ ] Create AgedPayablesPage component
- [ ] API: GET /reports/aged-payables
- [ ] Export functionality

### 3.1.8 Finance Dashboard Widget

**Route:** `/dashboard` (widget)

**Features:**
- Revenue MTD, Expenses MTD, Net Profit MTD
- Outstanding receivables
- Quick links to reports

**Tasks:**
- [ ] Create FinanceDashboardWidget
- [ ] API: GET /dashboard/finance-summary
- [ ] Integrate into main dashboard

---

## 3.2 Country Adaptation (Tunisia)

### Overview

Configure Tunisia as launch market with full localization.

### 3.2.1 Countries Table

**Database:**

```sql
CREATE TABLE countries (
    code CHAR(2) PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    native_name VARCHAR(100),
    currency_code CHAR(3) NOT NULL,
    currency_symbol VARCHAR(10),
    phone_prefix VARCHAR(5),
    date_format VARCHAR(20) DEFAULT 'DD/MM/YYYY',
    default_locale VARCHAR(10),
    default_timezone VARCHAR(50),
    is_active BOOLEAN DEFAULT false,
    tax_id_label VARCHAR(50),
    tax_id_regex VARCHAR(255),
    created_at TIMESTAMP DEFAULT NOW()
);
```

**Tasks:**
- [ ] Create countries migration
- [ ] Create Country model
- [ ] Seed Tunisia configuration

### 3.2.2 Tax Rates

**Database:**

```sql
CREATE TABLE country_tax_rates (
    id UUID PRIMARY KEY,
    country_code CHAR(2) REFERENCES countries(code),
    name VARCHAR(100) NOT NULL,
    rate DECIMAL(5,2) NOT NULL,
    code VARCHAR(20),
    is_default BOOLEAN DEFAULT false,
    is_active BOOLEAN DEFAULT true,
    created_at TIMESTAMP DEFAULT NOW()
);
```

**Tunisia rates:**
- TVA 19% (default)
- TVA 13%
- TVA 7%
- Exonéré 0%

**Tasks:**
- [ ] Create tax_rates migration
- [ ] Create CountryTaxRate model
- [ ] Seed Tunisia tax rates
- [ ] Update product/invoice to use country rates

### 3.2.3 Tunisia Chart of Accounts

Seed standard Tunisian chart (Plan Comptable):
- 1xxx: Capitaux
- 2xxx: Immobilisations
- 3xxx: Stocks
- 4xxx: Tiers
- 5xxx: Financiers
- 6xxx: Charges
- 7xxx: Produits

**Tasks:**
- [ ] Create Tunisia chart of accounts seeder
- [ ] Auto-seed on company creation (country = TN)

### 3.2.4 Localization

**Tasks:**
- [ ] Complete French translations
- [ ] Add Arabic translations
- [ ] RTL support for Arabic
- [ ] Language switcher in settings
- [ ] Currency formatting (TND)
- [ ] Date formatting (DD/MM/YYYY)

### 3.2.5 Document Templates

Tunisia-compliant invoice template:
- Company header with Matricule Fiscal
- Customer Matricule Fiscal (B2B)
- TVA breakdown by rate
- Timbre fiscal line (if applicable)
- Legal mentions

**Tasks:**
- [ ] Create Tunisia invoice PDF template
- [ ] Create Tunisia quote PDF template
- [ ] Create Tunisia delivery note template

---

## 3.3 Subscription Tracking

### Overview

Simple plan management - admin manually controls subscription status. No payment processing.

### 3.3.1 Plans Table

**Database:**

```sql
CREATE TABLE plans (
    id UUID PRIMARY KEY,
    code VARCHAR(30) UNIQUE NOT NULL,
    name VARCHAR(100) NOT NULL,
    description TEXT,
    limits JSONB NOT NULL,
    price_monthly DECIMAL(10,2),
    currency CHAR(3) DEFAULT 'TND',
    is_active BOOLEAN DEFAULT true,
    display_order INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT NOW()
);
```

**Starter Plan:**
```json
{
    "code": "starter",
    "name": "Starter",
    "limits": {
        "max_companies": 1,
        "max_locations": 1,
        "max_users": 3
    },
    "price_monthly": 29
}
```

**Tasks:**
- [ ] Create plans migration
- [ ] Create Plan model
- [ ] Seed Starter plan

### 3.3.2 Tenant Subscription

**Database:**

```sql
CREATE TABLE tenant_subscriptions (
    id UUID PRIMARY KEY,
    tenant_id UUID REFERENCES tenants(id),
    plan_id UUID REFERENCES plans(id),
    status VARCHAR(20) NOT NULL DEFAULT 'trial',
    -- 'trial', 'active', 'expired', 'suspended'
    trial_ends_at TIMESTAMP,
    current_period_end DATE,
    notes TEXT,
    created_at TIMESTAMP DEFAULT NOW(),
    updated_at TIMESTAMP DEFAULT NOW()
);
```

**Tasks:**
- [ ] Create tenant_subscriptions migration
- [ ] Create TenantSubscription model
- [ ] Auto-create trial subscription on signup (14 days)

### 3.3.3 Plan Limits Service

```php
class PlanLimitsService
{
    public function checkLimit(Tenant $tenant, string $resource): bool;
    public function getUsage(Tenant $tenant): array;
    public function enforceLimit(Tenant $tenant, string $resource): void;
}
```

**Tasks:**
- [ ] Create PlanLimitsService
- [ ] Add limit checks to company creation
- [ ] Add limit checks to location creation
- [ ] Add limit checks to user creation
- [ ] Show usage in settings

### 3.3.4 Subscription Status UI

**Route:** `/settings/subscription`

**Features:**
- Current plan display
- Status (Trial, Active, Expired)
- Trial days remaining
- Usage stats (X of Y users, etc.)
- Contact info for upgrades

**Tasks:**
- [ ] Create SubscriptionPage component
- [ ] Create UsageStats component
- [ ] API: GET /subscription

---

## 3.4 Super Admin Dashboard

### Overview

Internal dashboard to manage tenants and subscriptions.

### 3.4.1 Super Admin Auth

**Database:**

```sql
CREATE TABLE super_admins (
    id UUID PRIMARY KEY,
    email VARCHAR(255) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    name VARCHAR(100) NOT NULL,
    role VARCHAR(30) DEFAULT 'admin',
    is_active BOOLEAN DEFAULT true,
    last_login_at TIMESTAMP,
    created_at TIMESTAMP DEFAULT NOW()
);
```

**Tasks:**
- [ ] Create super_admins migration
- [ ] Create SuperAdmin model
- [ ] Separate auth guard
- [ ] Login page
- [ ] Seed initial admin account

### 3.4.2 Admin Dashboard Home

**Widgets:**
- Total tenants
- Active vs Trial vs Expired
- Recent signups
- Tenants needing attention

**Tasks:**
- [ ] Create admin app structure
- [ ] Create AdminDashboardPage
- [ ] Create stats API

### 3.4.3 Tenant Management

**Route:** `/admin/tenants`

**Features:**
- List all tenants with search
- Columns: Name, Email, Plan, Status, Companies, Created
- View tenant details
- Edit subscription status
- Extend trial
- Change plan
- Suspend/activate
- Add notes

**Tasks:**
- [ ] Create TenantsListPage
- [ ] Create TenantDetailPage
- [ ] Edit subscription functionality
- [ ] Suspend/activate functionality

### 3.4.4 Admin Actions

- Mark as paid (activate)
- Extend trial
- Upgrade/downgrade plan
- Suspend tenant
- Impersonate (login as tenant for support)

**Tasks:**
- [ ] Implement each action
- [ ] Audit log for admin actions

### 3.4.5 Admin Audit Log

Track all admin actions - cannot be edited/deleted.

**Tasks:**
- [ ] Create admin_audit_logs table
- [ ] Log all admin actions
- [ ] Create AuditLogPage

---

## 3.5 Full Sale Lifecycle

### Overview

Ensure complete flow from quote to payment with all transitions working properly.

### 3.5.1 Document Flow

```
Quote (optional)
    ↓ [Accept]
Sales Order (optional)
    ↓ [Fulfill]
Delivery Note (optional)
    ↓ [Invoice]
Invoice
    ↓ [Pay]
Payment(s)
```

**Tasks:**
- [ ] Audit existing document types and statuses
- [ ] Verify all transitions work

### 3.5.2 Quote Flow

**Features:**
- Create quote with line items
- Quote expiry date
- Send to customer (print/email)
- Accept → Creates Sales Order or Invoice
- Reject with reason
- Revise (new version)

**Tasks:**
- [ ] Verify quote creation works
- [ ] Quote to Sales Order conversion
- [ ] Quote to Invoice conversion
- [ ] Quote PDF template
- [ ] Quote expiry handling

### 3.5.3 Sales Order Flow

**Features:**
- Order confirmation number
- Expected delivery date
- Partial fulfillment support
- Create Delivery Note
- Create Invoice (full or partial)

**Tasks:**
- [ ] Verify sales order creation
- [ ] Sales order to delivery note
- [ ] Sales order to invoice
- [ ] Partial invoicing support

### 3.5.4 Delivery Note Flow

**Features:**
- Delivery note number
- Items delivered (can be partial)
- Stock deducted on confirmation
- Link to order and invoice

**Tasks:**
- [ ] Verify delivery note creation
- [ ] Stock deduction on delivery confirmation
- [ ] Delivery note PDF template

### 3.5.5 Invoice Flow

**Features:**
- Create from quote/order/delivery
- Create standalone
- Payment tracking
- Payment status (Unpaid, Partial, Paid)
- Due date and overdue highlighting

**Tasks:**
- [ ] Verify invoice creation from all sources
- [ ] Payment status calculation
- [ ] Overdue highlighting
- [ ] Invoice PDF template

### 3.5.6 Purchase Flow

Same lifecycle for purchases:
- Purchase Order → Goods Receipt → Supplier Invoice → Payment

**Tasks:**
- [ ] Verify purchase order creation
- [ ] Goods receipt with stock increase
- [ ] Supplier invoice recording
- [ ] Supplier payment

### 3.5.7 Document List Improvements

**All document lists:**
- Tabs by status
- Search by number, customer/supplier
- Date range filter
- Quick actions (view, edit, convert, print)

**Tasks:**
- [ ] Standardize document list UI across all types
- [ ] Add consistent filters
- [ ] Add quick actions

---

## 3.6 Refunds & Cancellations

### Overview

Handle returns, refunds, and cancellations with proper accounting.

### 3.6.1 Credit Note

**Features:**
- Create credit note against invoice
- Partial or full credit
- Reason for credit
- Stock return (optional)
- Accounting entries auto-generated

**Tasks:**
- [ ] Verify/create credit note functionality
- [ ] Credit note from invoice UI
- [ ] Partial credit note support
- [ ] Credit note PDF template
- [ ] Accounting entries for credit

### 3.6.2 Invoice Cancellation

**Rules:**
- Can cancel only unpaid invoices
- Cannot cancel paid invoice (use credit note)
- Cancelled invoice keeps number (audit trail)

**Tasks:**
- [ ] Cancel invoice functionality
- [ ] Validation (block if paid)
- [ ] Audit trail

### 3.6.3 Payment Refund

**When customer paid but needs refund:**
- Create credit note
- Record refund payment (negative)
- Update customer balance

**Tasks:**
- [ ] Refund payment type
- [ ] Refund workflow
- [ ] Customer balance update

### 3.6.4 Stock Returns

**When goods returned:**
- Link to credit note
- Stock movement (increase)
- Reason tracking

**Tasks:**
- [ ] Stock return functionality
- [ ] Return reason field
- [ ] Inventory update

---

## 3.7 Multi-Payment Options

### Overview

Flexible payment handling for real-world scenarios.

### 3.7.1 Split Payments

**Scenario:** Customer pays 500 TND cash, 200 TND by card.

**Features:**
- Multiple payment methods on one invoice
- Each payment recorded separately
- Total payments tracked vs invoice amount

**Tasks:**
- [ ] Multiple payments per invoice
- [ ] Payment entry UI allows multiple methods
- [ ] Payment summary on invoice

### 3.7.2 Deposit / Advance Payment

**Scenario:** Customer pays 30% upfront, rest on delivery.

**Features:**
- Record deposit against order/quote
- Deposit reduces amount due on invoice
- Track deposit balance

**Tasks:**
- [ ] Deposit payment type
- [ ] Link deposit to order
- [ ] Apply deposit to invoice

### 3.7.3 Payment Methods

**Existing methods - verify:**
- Cash
- Bank Transfer
- Check
- Card

**Add if missing:**
- Mobile Payment (D17, Flouci, etc.)

**Tasks:**
- [ ] Verify payment methods exist
- [ ] Add mobile payment options
- [ ] Payment method management UI

### 3.7.4 Payment on Account

**Scenario:** Customer has credit balance from overpayment or credit note.

**Features:**
- Customer balance tracking
- Apply credit to new invoice
- View customer statement

**Tasks:**
- [ ] Customer balance calculation
- [ ] Apply balance to invoice
- [ ] Customer statement report

---

## 3.8 Pricing Rules & Discounts

### Overview

Flexible pricing system for all business types.

### 3.8.1 Product Pricing Enhancements

**Current:** Single price per product

**Add:**
- Cost price (for margin tracking)
- Tax-inclusive vs exclusive option

**Tasks:**
- [ ] Add cost_price to products
- [ ] Add price_includes_tax option
- [ ] Margin display in product list

### 3.8.2 Price Lists

**Scenario:** Retail price vs Wholesale price

**Database:**

```sql
CREATE TABLE price_lists (
    id UUID PRIMARY KEY,
    company_id UUID REFERENCES companies(id),
    name VARCHAR(100) NOT NULL,
    code VARCHAR(30),
    is_default BOOLEAN DEFAULT false,
    is_active BOOLEAN DEFAULT true,
    created_at TIMESTAMP DEFAULT NOW()
);

CREATE TABLE product_prices (
    id UUID PRIMARY KEY,
    product_id UUID REFERENCES products(id),
    price_list_id UUID REFERENCES price_lists(id),
    price DECIMAL(12,2) NOT NULL,
    min_quantity INT DEFAULT 1,
    UNIQUE(product_id, price_list_id, min_quantity)
);
```

**Tasks:**
- [ ] Create price_lists table
- [ ] Create product_prices table
- [ ] Price list management UI
- [ ] Assign product prices per list

### 3.8.3 Customer Price Lists

**Assign default price list to customer:**
- Auto-apply when creating documents
- Can override per line

**Tasks:**
- [ ] Add price_list_id to partners
- [ ] Auto-apply on document creation
- [ ] Override option on line

### 3.8.4 Line Discounts

**Per-line discounts:**
- Percentage or fixed amount
- Display on document

**Tasks:**
- [ ] Verify line discount works
- [ ] Discount input on document line
- [ ] Discount display on PDF

### 3.8.5 Document Discount

**Discount on entire document:**
- Apply to subtotal
- Before tax calculation

**Tasks:**
- [ ] Add document-level discount field
- [ ] Apply to subtotal
- [ ] Show on PDF

### 3.8.6 Quantity Breaks

**Buy more, pay less:**
- Price varies by quantity ordered

**Tasks:**
- [ ] Quantity breaks in product_prices
- [ ] Auto-apply based on line quantity

---

## 3.9 Advanced Permissions

### Overview

Granular control over who can do what.

### 3.9.1 Permission Audit

**Review current state:**
- What roles exist?
- What can each role do?
- Is it enforced backend AND frontend?

**Tasks:**
- [ ] Document current roles and permissions
- [ ] Identify gaps in enforcement

### 3.9.2 Permission Matrix

| Permission | Owner | Admin | Manager | Accountant | Cashier | Viewer |
|------------|-------|-------|---------|------------|---------|--------|
| Manage users | ✓ | ✓ | - | - | - | - |
| View partners | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |
| Create partner | ✓ | ✓ | ✓ | - | ✓ | - |
| Delete partner | ✓ | ✓ | - | - | - | - |
| View products | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |
| Manage products | ✓ | ✓ | ✓ | - | - | - |
| Create invoice | ✓ | ✓ | ✓ | ✓ | ✓ | - |
| Void invoice | ✓ | ✓ | - | - | - | - |
| View reports | ✓ | ✓ | ✓ | ✓ | - | - |
| Manage settings | ✓ | ✓ | - | - | - | - |
| View cost prices | ✓ | ✓ | ✓ | ✓ | - | - |
| Apply discounts | ✓ | ✓ | ✓ | - | Limited | - |
| Process refunds | ✓ | ✓ | ✓ | - | - | - |

**Tasks:**
- [ ] Define complete permission matrix
- [ ] Create/update permissions seeder
- [ ] Implement all permission checks

### 3.9.3 Backend Enforcement

Every controller action must check permission.

**Tasks:**
- [ ] Add permission middleware/checks to all endpoints
- [ ] Return 403 for unauthorized actions
- [ ] Test permission enforcement

### 3.9.4 Frontend Enforcement

Hide/disable UI elements based on permissions.

**Tasks:**
- [ ] Create usePermission hook
- [ ] Hide buttons user can't use
- [ ] Disable fields user can't edit
- [ ] Hide menu items user can't access

### 3.9.5 Location-Based Access

Restrict users to specific locations.

**Already in membership:**
- allowed_location_ids array

**Tasks:**
- [ ] Verify location restriction works
- [ ] Filter data by allowed locations
- [ ] Show only allowed locations in selector

---

## 3.10 Final QA & Polish

### Overview

Testing and polish before fork.

### 3.10.1 Functional Testing

**Test complete flows:**
- [ ] Registration → Company setup → First invoice
- [ ] Quote → Order → Delivery → Invoice → Payment
- [ ] Purchase order → Receive → Pay supplier
- [ ] Refund/credit note flow
- [ ] Multi-payment scenario
- [ ] Discount application
- [ ] Permission restrictions
- [ ] Multi-company switching
- [ ] Multi-location switching

### 3.10.2 Bug Fixes

- [ ] Fix all bugs found during testing
- [ ] Address UI inconsistencies
- [ ] Fix mobile issues

### 3.10.3 Performance

- [ ] Page load < 3s
- [ ] API response < 500ms
- [ ] Fix N+1 queries
- [ ] Proper pagination

### 3.10.4 UI Consistency

- [ ] Consistent button styles
- [ ] Consistent form layouts
- [ ] Consistent table styles
- [ ] Loading states
- [ ] Error states
- [ ] Empty states

### 3.10.5 Code Quality

- [ ] All tests passing
- [ ] PHPStan clean
- [ ] ESLint clean
- [ ] TypeScript clean

---

## Progress Tracking

Create: `docs/tasks/PHASE-3-PROGRESS.md`

```markdown
# Phase 3 Progress

Started: [DATE]

## 3.1 Finance Reports
- [ ] 3.1.1 Chart of Accounts
- [ ] 3.1.2 General Ledger
- [ ] 3.1.3 Trial Balance
- [ ] 3.1.4 Profit & Loss
- [ ] 3.1.5 Balance Sheet
- [ ] 3.1.6 Aged Receivables
- [ ] 3.1.7 Aged Payables
- [ ] 3.1.8 Finance Dashboard Widget

## 3.2 Country Adaptation
- [ ] 3.2.1 Countries table
- [ ] 3.2.2 Tax rates
- [ ] 3.2.3 Chart of accounts
- [ ] 3.2.4 Localization
- [ ] 3.2.5 Document templates

## 3.3 Subscription Tracking
- [ ] 3.3.1 Plans table
- [ ] 3.3.2 Tenant subscription
- [ ] 3.3.3 Plan limits service
- [ ] 3.3.4 Subscription UI

## 3.4 Super Admin
- [ ] 3.4.1 Admin auth
- [ ] 3.4.2 Dashboard
- [ ] 3.4.3 Tenant management
- [ ] 3.4.4 Admin actions
- [ ] 3.4.5 Audit log

## 3.5 Sale Lifecycle
- [ ] 3.5.1 Document flow audit
- [ ] 3.5.2 Quote flow
- [ ] 3.5.3 Sales order flow
- [ ] 3.5.4 Delivery note flow
- [ ] 3.5.5 Invoice flow
- [ ] 3.5.6 Purchase flow
- [ ] 3.5.7 Document list improvements

## 3.6 Refunds & Cancellations
- [ ] 3.6.1 Credit notes
- [ ] 3.6.2 Invoice cancellation
- [ ] 3.6.3 Payment refunds
- [ ] 3.6.4 Stock returns

## 3.7 Multi-Payment
- [ ] 3.7.1 Split payments
- [ ] 3.7.2 Deposits
- [ ] 3.7.3 Payment methods
- [ ] 3.7.4 Payment on account

## 3.8 Pricing & Discounts
- [ ] 3.8.1 Product pricing enhancements
- [ ] 3.8.2 Price lists
- [ ] 3.8.3 Customer price lists
- [ ] 3.8.4 Line discounts
- [ ] 3.8.5 Document discounts
- [ ] 3.8.6 Quantity breaks

## 3.9 Permissions
- [ ] 3.9.1 Permission audit
- [ ] 3.9.2 Permission matrix
- [ ] 3.9.3 Backend enforcement
- [ ] 3.9.4 Frontend enforcement
- [ ] 3.9.5 Location-based access

## 3.10 QA & Polish
- [ ] 3.10.1 Functional testing
- [ ] 3.10.2 Bug fixes
- [ ] 3.10.3 Performance
- [ ] 3.10.4 UI consistency
- [ ] 3.10.5 Code quality

Completed: [DATE]
```

---

## Definition of Done

Phase 3 is complete when:

- [ ] All financial reports working with export
- [ ] Tunisia fully configured (tax, locale, templates)
- [ ] Subscription tracking and limits working
- [ ] Super admin can manage tenants
- [ ] Full sale lifecycle works end-to-end
- [ ] Refunds and cancellations work
- [ ] Multi-payment scenarios work
- [ ] Pricing rules and discounts work
- [ ] Permissions properly enforced
- [ ] All tests passing
- [ ] No critical bugs

---

## After Phase 3: Fork Point

```bash
git tag v1.0.0-common
git push origin v1.0.0-common
```

Then:

**Automotive (continue in main repo):**
- Phase 4A: Work Orders, Workshop Calendar, Vehicle Catalog, TecDoc, VIN
- Automotive design theme

**Retail/QSR (fork to new repo):**
- Phase 4B: POS Interface, Variations/Modifiers, Kitchen Display
- Retail/QSR design theme
