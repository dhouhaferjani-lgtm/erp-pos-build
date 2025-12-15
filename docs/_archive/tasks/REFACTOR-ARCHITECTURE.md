# AutoERP - Architecture Refactor: Tenant → Company → Location

> **CRITICAL: This refactor must be completed before any new features.**
> The current system incorrectly scopes data to tenant. It must be scoped to company/location.

---

## Why This Refactor is Necessary

### Current (WRONG) Architecture
```
Tenant
├── Users
├── Partners (customers, suppliers)
├── Products
├── Documents (invoices, quotes)
├── Stock Levels
├── Accounts (chart of accounts)
├── Journal Entries
└── Payments
```

### Correct Architecture
```
Tenant (Subscription/Account Level)
├── Subscription
├── Billing
└── Companies[]

Company (Legal Entity - has Tax ID, can invoice)
├── Users (via membership, with roles)
├── Partners (customers, suppliers)
├── Products (catalog can be shared or per-company)
├── Documents (invoices, quotes - MUST be company-scoped)
├── Accounts (chart of accounts - per company)
├── Journal Entries (per company)
├── Payments (per company)
├── Fiscal Settings
└── Locations[]

Location (Physical Place)
├── Type (shop, warehouse, office)
├── Stock Levels (inventory is per-location)
├── Stock Movements
└── POS Transactions (if shop)
```

---

## Complete Database Schema

### Tenant Level (Subscription/Account - Personal Info Only)

**IMPORTANT:** Tenant is NOT a legal entity. It's a person/organization that subscribes.
Country, tax ID, and all legal info belong to COMPANY, not tenant.

```sql
-- Tenants: Account/subscription level only (PERSONAL info, not company info)
CREATE TABLE tenants (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    
    -- Personal Info (the person who created the account)
    first_name VARCHAR(100) NOT NULL,
    last_name VARCHAR(100) NOT NULL,
    email VARCHAR(255) NOT NULL UNIQUE, -- Account email (for billing, notifications)
    phone VARCHAR(30), -- Personal phone (optional)
    
    -- Preferences (personal, not company)
    preferred_locale VARCHAR(10) NOT NULL DEFAULT 'fr', -- Interface language
    preferred_timezone VARCHAR(50) NOT NULL DEFAULT 'Africa/Tunis',
    
    -- Account identifier
    slug VARCHAR(100) UNIQUE NOT NULL, -- URL-friendly identifier (auto-generated)
    
    -- Owner user (usually same as tenant creator)
    owner_user_id UUID, -- First user, tenant admin
    
    -- Status
    status VARCHAR(20) NOT NULL DEFAULT 'active', -- active, suspended, cancelled
    
    -- Timestamps
    created_at TIMESTAMP NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMP NOT NULL DEFAULT NOW(),
    deleted_at TIMESTAMP -- Soft delete
    
    -- NOTE: NO country_code here! Country belongs to Company.
    -- NOTE: NO verification_tier here! Verification is per Company.
);

-- Tenant verification is NOW per COMPANY (see company_verification below)
-- Removed tenant_documents table - documents are per company
```

### Company Level (Legal Entity)

**IMPORTANT:** Company is the legal entity. ALL compliance, tax, and legal info belongs here.
A tenant can have companies in different countries, each following its own legal system.

```sql
-- Companies: Legal entities within a tenant
CREATE TABLE companies (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    tenant_id UUID NOT NULL REFERENCES tenants(id),
    
    -- Basic Info
    name VARCHAR(255) NOT NULL, -- Display name
    legal_name VARCHAR(255), -- Official registered name
    code VARCHAR(20), -- Internal code "COMP-001"
    
    -- COUNTRY (determines legal/fiscal system)
    country_code CHAR(2) NOT NULL, -- 'TN', 'FR', 'IT' - THIS IS CRITICAL!
    
    -- Legal/Tax Info (country-specific, validated per country rules)
    tax_id VARCHAR(50), -- Tunisia: Matricule Fiscal, France: SIRET, Italy: Partita IVA
    registration_number VARCHAR(100), -- Tunisia: Registre de Commerce, France: RCS
    vat_number VARCHAR(50), -- EU VAT number if applicable
    
    -- Additional country-specific identifiers (stored as JSON for flexibility)
    legal_identifiers JSONB DEFAULT '{}', -- e.g., {"ape_code": "4520A"} for France
    
    -- Contact
    email VARCHAR(255),
    phone VARCHAR(30),
    website VARCHAR(255),
    
    -- Address
    address_street VARCHAR(255),
    address_street_2 VARCHAR(255),
    address_city VARCHAR(100),
    address_state VARCHAR(100),
    address_postal_code VARCHAR(20),
    
    -- Branding
    logo_path VARCHAR(500),
    primary_color VARCHAR(7) DEFAULT '#2563EB',
    
    -- Regional Settings (derived from country, can override)
    currency CHAR(3) NOT NULL, -- From country default, e.g., 'TND', 'EUR'
    locale VARCHAR(10) NOT NULL, -- e.g., 'fr-TN', 'fr-FR', 'it-IT'
    timezone VARCHAR(50) NOT NULL, -- e.g., 'Africa/Tunis', 'Europe/Paris'
    date_format VARCHAR(20) DEFAULT 'DD/MM/YYYY',
    
    -- Fiscal Settings
    fiscal_year_start_month SMALLINT NOT NULL DEFAULT 1, -- 1 = January
    
    -- Document Sequences (company-specific)
    invoice_prefix VARCHAR(20) DEFAULT 'INV-',
    invoice_next_number INTEGER NOT NULL DEFAULT 1,
    quote_prefix VARCHAR(20) DEFAULT 'QUO-',
    quote_next_number INTEGER NOT NULL DEFAULT 1,
    order_prefix VARCHAR(20) DEFAULT 'ORD-',
    order_next_number INTEGER NOT NULL DEFAULT 1,
    delivery_note_prefix VARCHAR(20) DEFAULT 'DDT-',
    delivery_note_next_number INTEGER NOT NULL DEFAULT 1,
    
    -- VERIFICATION (per company, because requirements differ by country!)
    verification_tier VARCHAR(20) NOT NULL DEFAULT 'basic', -- basic, verified, marketplace
    verification_status VARCHAR(20) NOT NULL DEFAULT 'pending', -- pending, under_review, approved, rejected
    verification_submitted_at TIMESTAMP,
    verified_at TIMESTAMP,
    verified_by UUID,
    verification_notes TEXT,
    
    -- Compliance profile (determines which rules apply)
    compliance_profile VARCHAR(50), -- 'tunisia_standard', 'france_nf525', 'france_standard', etc.
    
    -- Hierarchy (for chains/franchises)
    parent_company_id UUID REFERENCES companies(id), -- NULL = independent
    is_headquarters BOOLEAN NOT NULL DEFAULT false,
    
    -- Status
    status VARCHAR(20) NOT NULL DEFAULT 'active', -- active, inactive, closed
    closed_at TIMESTAMP,
    
    -- Timestamps
    created_at TIMESTAMP NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMP NOT NULL DEFAULT NOW(),
    deleted_at TIMESTAMP,
    
    -- Constraints
    UNIQUE(tenant_id, tax_id), -- Tax ID unique within tenant (same entity shouldn't be added twice)
    UNIQUE(tenant_id, code)
);

-- Company documents (for verification - country-specific requirements)
CREATE TABLE company_documents (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    company_id UUID NOT NULL REFERENCES companies(id),
    document_type_id UUID NOT NULL, -- References country's required document types
    file_path VARCHAR(500) NOT NULL,
    original_filename VARCHAR(255),
    file_size INTEGER,
    mime_type VARCHAR(100),
    status VARCHAR(20) NOT NULL DEFAULT 'pending', -- pending, approved, rejected
    reviewed_at TIMESTAMP,
    reviewed_by UUID,
    rejection_reason TEXT,
    expires_at DATE, -- Some documents expire
    uploaded_at TIMESTAMP NOT NULL DEFAULT NOW(),
    
    INDEX (company_id, status)
);

-- Company bank accounts
CREATE TABLE company_bank_accounts (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    company_id UUID NOT NULL REFERENCES companies(id),
    bank_name VARCHAR(100) NOT NULL,
    account_name VARCHAR(255),
    account_number VARCHAR(50), -- RIB for Tunisia
    iban VARCHAR(50),
    swift_bic VARCHAR(20),
    is_default BOOLEAN NOT NULL DEFAULT false,
    is_active BOOLEAN NOT NULL DEFAULT true,
    created_at TIMESTAMP NOT NULL DEFAULT NOW()
);

-- Company default settings/preferences
CREATE TABLE company_settings (
    company_id UUID PRIMARY KEY REFERENCES companies(id),
    
    -- Invoice Defaults
    default_payment_terms_days INTEGER DEFAULT 30,
    default_tax_rate_id UUID,
    invoice_footer_text TEXT,
    invoice_notes_template TEXT,
    
    -- Quote Defaults
    quote_validity_days INTEGER DEFAULT 30,
    quote_footer_text TEXT,
    
    -- Stock Settings
    default_location_id UUID,
    track_stock BOOLEAN NOT NULL DEFAULT true,
    allow_negative_stock BOOLEAN NOT NULL DEFAULT false,
    
    -- Accounting Settings
    default_sales_account_id UUID,
    default_purchase_account_id UUID,
    default_receivable_account_id UUID,
    default_payable_account_id UUID,
    
    updated_at TIMESTAMP NOT NULL DEFAULT NOW()
);
```

### Location Level

```sql
-- Locations: Physical places (shops, warehouses, etc.)
CREATE TABLE locations (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    company_id UUID NOT NULL REFERENCES companies(id),
    
    -- Basic Info
    name VARCHAR(100) NOT NULL, -- "Main Shop", "Central Warehouse"
    code VARCHAR(20), -- "LOC-001" for stock movements
    type VARCHAR(20) NOT NULL, -- 'shop', 'warehouse', 'office', 'mobile'
    
    -- Contact
    phone VARCHAR(30),
    email VARCHAR(255),
    
    -- Address (can differ from company)
    address_street VARCHAR(255),
    address_city VARCHAR(100),
    address_postal_code VARCHAR(20),
    address_country CHAR(2),
    
    -- Geo (for mobile/delivery)
    latitude DECIMAL(10, 8),
    longitude DECIMAL(11, 8),
    
    -- Settings
    is_default BOOLEAN NOT NULL DEFAULT false, -- Default location for this company
    is_active BOOLEAN NOT NULL DEFAULT true,
    
    -- For shops: POS settings
    pos_enabled BOOLEAN NOT NULL DEFAULT false,
    receipt_header TEXT,
    receipt_footer TEXT,
    
    -- Timestamps
    created_at TIMESTAMP NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMP NOT NULL DEFAULT NOW(),
    
    -- Constraints
    UNIQUE(company_id, code)
);
```

### User & Access Control

```sql
-- Users: Authentication records (tenant-independent)
CREATE TABLE users (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    
    -- Authentication
    email VARCHAR(255) NOT NULL UNIQUE,
    email_verified_at TIMESTAMP,
    password VARCHAR(255) NOT NULL,
    
    -- Profile
    name VARCHAR(255) NOT NULL,
    phone VARCHAR(30),
    avatar_path VARCHAR(500),
    
    -- Preferences
    locale VARCHAR(10) DEFAULT 'fr',
    timezone VARCHAR(50) DEFAULT 'Africa/Tunis',
    
    -- Session
    last_login_at TIMESTAMP,
    last_login_ip VARCHAR(45),
    
    -- Status
    status VARCHAR(20) NOT NULL DEFAULT 'active',
    
    -- Timestamps
    created_at TIMESTAMP NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMP NOT NULL DEFAULT NOW(),
    deleted_at TIMESTAMP
);

-- User membership in COMPANIES (not tenants!)
CREATE TABLE user_company_memberships (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    user_id UUID NOT NULL REFERENCES users(id),
    company_id UUID NOT NULL REFERENCES companies(id),
    
    -- Role within this company
    role VARCHAR(50) NOT NULL, -- 'owner', 'admin', 'manager', 'accountant', 'cashier', 'technician', 'viewer'
    
    -- Location restrictions (NULL = all locations)
    allowed_location_ids UUID[], -- Array of location IDs user can access
    
    -- Flags
    is_primary BOOLEAN NOT NULL DEFAULT false, -- User's default company
    can_switch_companies BOOLEAN NOT NULL DEFAULT true, -- Can switch to other companies in same tenant
    
    -- Invitation tracking
    invited_by UUID REFERENCES users(id),
    invited_at TIMESTAMP,
    accepted_at TIMESTAMP,
    
    -- Status
    status VARCHAR(20) NOT NULL DEFAULT 'active', -- active, pending, suspended
    
    -- Timestamps
    created_at TIMESTAMP NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMP NOT NULL DEFAULT NOW(),
    
    -- Constraints
    UNIQUE(user_id, company_id)
);

-- User's current session context
CREATE TABLE user_sessions (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    user_id UUID NOT NULL REFERENCES users(id),
    
    -- Current working context
    current_company_id UUID REFERENCES companies(id),
    current_location_id UUID REFERENCES locations(id),
    
    -- Session info
    token VARCHAR(255) NOT NULL UNIQUE,
    ip_address VARCHAR(45),
    user_agent TEXT,
    
    -- Timestamps
    last_activity_at TIMESTAMP NOT NULL DEFAULT NOW(),
    expires_at TIMESTAMP NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT NOW()
);
```

### Core Business Entities (Company-Scoped)

```sql
-- Partners: Customers and Suppliers (company-scoped)
CREATE TABLE partners (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    company_id UUID NOT NULL REFERENCES companies(id), -- CRITICAL: company, not tenant!
    
    -- Type
    type VARCHAR(20) NOT NULL, -- 'customer', 'supplier', 'both'
    
    -- Basic Info
    name VARCHAR(255) NOT NULL,
    legal_name VARCHAR(255),
    code VARCHAR(50), -- Customer/supplier code
    
    -- Tax Info
    tax_id VARCHAR(50),
    vat_number VARCHAR(50),
    
    -- Contact
    email VARCHAR(255),
    phone VARCHAR(30),
    website VARCHAR(255),
    
    -- Address
    address_street VARCHAR(255),
    address_city VARCHAR(100),
    address_postal_code VARCHAR(20),
    address_country CHAR(2),
    
    -- Billing
    payment_terms_days INTEGER,
    credit_limit DECIMAL(15, 2),
    
    -- Default accounts (for accounting)
    receivable_account_id UUID,
    payable_account_id UUID,
    
    -- Status
    is_active BOOLEAN NOT NULL DEFAULT true,
    
    -- Timestamps
    created_at TIMESTAMP NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMP NOT NULL DEFAULT NOW(),
    deleted_at TIMESTAMP,
    
    -- Constraints
    UNIQUE(company_id, code)
);

-- Products: Catalog (company-scoped, or shared via tenant setting)
CREATE TABLE products (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    company_id UUID NOT NULL REFERENCES companies(id), -- Products belong to company
    
    -- Identification
    sku VARCHAR(100),
    barcode VARCHAR(50),
    name VARCHAR(255) NOT NULL,
    description TEXT,
    
    -- Type
    type VARCHAR(20) NOT NULL DEFAULT 'product', -- 'product', 'service', 'part'
    
    -- Categorization
    category_id UUID,
    
    -- Pricing
    cost_price DECIMAL(15, 4),
    sell_price DECIMAL(15, 4) NOT NULL,
    currency CHAR(3),
    
    -- Tax
    tax_rate_id UUID,
    
    -- Stock (for products, not services)
    track_stock BOOLEAN NOT NULL DEFAULT true,
    min_stock_level DECIMAL(15, 4),
    reorder_quantity DECIMAL(15, 4),
    
    -- Automotive specific
    oem_numbers JSONB, -- Array of OEM part numbers
    cross_references JSONB, -- Compatible part numbers
    
    -- Accounting
    sales_account_id UUID,
    purchase_account_id UUID,
    inventory_account_id UUID,
    
    -- Status
    is_active BOOLEAN NOT NULL DEFAULT true,
    
    -- Timestamps
    created_at TIMESTAMP NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMP NOT NULL DEFAULT NOW(),
    deleted_at TIMESTAMP,
    
    -- Constraints
    UNIQUE(company_id, sku)
);

-- Documents: Invoices, Quotes, Orders (company-scoped)
CREATE TABLE documents (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    company_id UUID NOT NULL REFERENCES companies(id), -- CRITICAL!
    location_id UUID REFERENCES locations(id), -- Which shop (for POS)
    
    -- Type
    type VARCHAR(20) NOT NULL, -- 'quote', 'sales_order', 'invoice', 'credit_note', 'purchase_order'
    
    -- Numbering
    document_number VARCHAR(50) NOT NULL,
    
    -- Parties
    partner_id UUID NOT NULL REFERENCES partners(id),
    
    -- Dates
    document_date DATE NOT NULL,
    due_date DATE,
    
    -- Amounts (company currency)
    subtotal DECIMAL(15, 2) NOT NULL DEFAULT 0,
    tax_amount DECIMAL(15, 2) NOT NULL DEFAULT 0,
    total DECIMAL(15, 2) NOT NULL DEFAULT 0,
    amount_paid DECIMAL(15, 2) NOT NULL DEFAULT 0,
    balance_due DECIMAL(15, 2) NOT NULL DEFAULT 0,
    currency CHAR(3) NOT NULL,
    
    -- Status
    status VARCHAR(20) NOT NULL DEFAULT 'draft',
    
    -- Relationships
    source_document_id UUID REFERENCES documents(id), -- Quote → Order → Invoice chain
    
    -- Compliance
    hash VARCHAR(64), -- Fiscal hash
    previous_hash VARCHAR(64),
    
    -- Metadata
    notes TEXT,
    internal_notes TEXT,
    
    -- Timestamps
    created_by UUID REFERENCES users(id),
    posted_at TIMESTAMP,
    posted_by UUID REFERENCES users(id),
    cancelled_at TIMESTAMP,
    cancelled_by UUID REFERENCES users(id),
    created_at TIMESTAMP NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMP NOT NULL DEFAULT NOW(),
    
    -- Constraints
    UNIQUE(company_id, type, document_number)
);

-- Document Lines
CREATE TABLE document_lines (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    document_id UUID NOT NULL REFERENCES documents(id),
    
    -- Line details
    line_number INTEGER NOT NULL,
    product_id UUID REFERENCES products(id),
    description TEXT NOT NULL,
    
    -- Quantities
    quantity DECIMAL(15, 4) NOT NULL,
    unit_price DECIMAL(15, 4) NOT NULL,
    discount_percent DECIMAL(5, 2) DEFAULT 0,
    
    -- Tax
    tax_rate_id UUID,
    tax_amount DECIMAL(15, 2) NOT NULL DEFAULT 0,
    
    -- Totals
    line_total DECIMAL(15, 2) NOT NULL,
    
    -- Stock tracking
    location_id UUID REFERENCES locations(id), -- Where stock is taken from
    
    -- Timestamps
    created_at TIMESTAMP NOT NULL DEFAULT NOW()
);
```

### Inventory (Location-Scoped)

```sql
-- Stock Levels: Per product per LOCATION (not company!)
CREATE TABLE stock_levels (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    product_id UUID NOT NULL REFERENCES products(id),
    location_id UUID NOT NULL REFERENCES locations(id), -- CRITICAL!
    
    -- Quantities
    quantity_on_hand DECIMAL(15, 4) NOT NULL DEFAULT 0,
    quantity_reserved DECIMAL(15, 4) NOT NULL DEFAULT 0, -- For orders not yet fulfilled
    quantity_available DECIMAL(15, 4) GENERATED ALWAYS AS (quantity_on_hand - quantity_reserved) STORED,
    
    -- Valuation
    average_cost DECIMAL(15, 4),
    
    -- Timestamps
    last_counted_at TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT NOW(),
    
    -- Constraints
    PRIMARY KEY (product_id, location_id),
    UNIQUE(product_id, location_id)
);

-- Stock Movements: All inventory changes
CREATE TABLE stock_movements (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    
    -- Location context
    location_id UUID NOT NULL REFERENCES locations(id),
    product_id UUID NOT NULL REFERENCES products(id),
    
    -- Movement type
    type VARCHAR(30) NOT NULL, -- 'purchase', 'sale', 'transfer_in', 'transfer_out', 'adjustment', 'count'
    
    -- Quantities
    quantity DECIMAL(15, 4) NOT NULL, -- Positive = in, Negative = out
    quantity_before DECIMAL(15, 4) NOT NULL,
    quantity_after DECIMAL(15, 4) NOT NULL,
    
    -- Cost
    unit_cost DECIMAL(15, 4),
    total_cost DECIMAL(15, 2),
    
    -- Reference
    reference_type VARCHAR(50), -- 'document', 'transfer', 'adjustment'
    reference_id UUID, -- document_id, transfer_id, etc.
    
    -- For transfers
    related_location_id UUID REFERENCES locations(id), -- Source/destination
    transfer_id UUID, -- Groups transfer_in and transfer_out
    
    -- Reason (for adjustments)
    reason VARCHAR(100),
    notes TEXT,
    
    -- Audit
    created_by UUID REFERENCES users(id),
    created_at TIMESTAMP NOT NULL DEFAULT NOW()
);

-- Stock Transfers between locations
CREATE TABLE stock_transfers (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    
    -- Locations (within same company, or between companies in same tenant)
    from_location_id UUID NOT NULL REFERENCES locations(id),
    to_location_id UUID NOT NULL REFERENCES locations(id),
    
    -- Status
    status VARCHAR(20) NOT NULL DEFAULT 'draft', -- draft, in_transit, completed, cancelled
    
    -- Dates
    transfer_date DATE NOT NULL,
    shipped_at TIMESTAMP,
    received_at TIMESTAMP,
    
    -- Notes
    notes TEXT,
    
    -- Audit
    created_by UUID REFERENCES users(id),
    shipped_by UUID REFERENCES users(id),
    received_by UUID REFERENCES users(id),
    created_at TIMESTAMP NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMP NOT NULL DEFAULT NOW()
);

CREATE TABLE stock_transfer_lines (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    transfer_id UUID NOT NULL REFERENCES stock_transfers(id),
    product_id UUID NOT NULL REFERENCES products(id),
    quantity_sent DECIMAL(15, 4) NOT NULL,
    quantity_received DECIMAL(15, 4),
    notes TEXT
);
```

### Accounting (Company-Scoped)

```sql
-- Chart of Accounts: Per COMPANY
CREATE TABLE accounts (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    company_id UUID NOT NULL REFERENCES companies(id), -- CRITICAL!
    
    -- Account info
    code VARCHAR(20) NOT NULL, -- "1100", "4100"
    name VARCHAR(255) NOT NULL,
    
    -- Type
    type VARCHAR(20) NOT NULL, -- 'asset', 'liability', 'equity', 'revenue', 'expense'
    subtype VARCHAR(50), -- 'current_asset', 'fixed_asset', etc.
    
    -- Hierarchy
    parent_id UUID REFERENCES accounts(id),
    level INTEGER NOT NULL DEFAULT 1,
    
    -- Behavior
    is_header BOOLEAN NOT NULL DEFAULT false, -- Just a grouping, not for posting
    normal_balance VARCHAR(10) NOT NULL, -- 'debit', 'credit'
    
    -- System accounts (cannot delete)
    is_system BOOLEAN NOT NULL DEFAULT false,
    system_code VARCHAR(50), -- 'accounts_receivable', 'accounts_payable', etc.
    
    -- Status
    is_active BOOLEAN NOT NULL DEFAULT true,
    
    -- Timestamps
    created_at TIMESTAMP NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMP NOT NULL DEFAULT NOW(),
    
    -- Constraints
    UNIQUE(company_id, code)
);

-- Journal Entries: Per COMPANY
CREATE TABLE journal_entries (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    company_id UUID NOT NULL REFERENCES companies(id), -- CRITICAL!
    
    -- Entry info
    entry_number VARCHAR(50) NOT NULL,
    entry_date DATE NOT NULL,
    description TEXT,
    
    -- Source
    source_type VARCHAR(50), -- 'invoice', 'payment', 'manual', 'adjustment'
    source_id UUID,
    
    -- Status
    status VARCHAR(20) NOT NULL DEFAULT 'draft', -- draft, posted, reversed
    
    -- Compliance
    hash VARCHAR(64),
    previous_hash VARCHAR(64),
    
    -- Totals (must balance)
    total_debit DECIMAL(15, 2) NOT NULL DEFAULT 0,
    total_credit DECIMAL(15, 2) NOT NULL DEFAULT 0,
    
    -- Audit
    posted_at TIMESTAMP,
    posted_by UUID REFERENCES users(id),
    reversed_at TIMESTAMP,
    reversed_by UUID REFERENCES users(id),
    reversing_entry_id UUID REFERENCES journal_entries(id),
    created_by UUID REFERENCES users(id),
    created_at TIMESTAMP NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMP NOT NULL DEFAULT NOW(),
    
    -- Constraints
    UNIQUE(company_id, entry_number)
);

CREATE TABLE journal_entry_lines (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    journal_entry_id UUID NOT NULL REFERENCES journal_entries(id),
    
    -- Line info
    line_number INTEGER NOT NULL,
    account_id UUID NOT NULL REFERENCES accounts(id),
    description TEXT,
    
    -- Amounts (one must be zero)
    debit DECIMAL(15, 2) NOT NULL DEFAULT 0,
    credit DECIMAL(15, 2) NOT NULL DEFAULT 0,
    
    -- Reference
    partner_id UUID REFERENCES partners(id),
    document_id UUID REFERENCES documents(id),
    
    -- Timestamps
    created_at TIMESTAMP NOT NULL DEFAULT NOW()
);

-- Fiscal Years & Periods: Per COMPANY
CREATE TABLE fiscal_years (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    company_id UUID NOT NULL REFERENCES companies(id),
    
    name VARCHAR(50) NOT NULL, -- "2025"
    start_date DATE NOT NULL,
    end_date DATE NOT NULL,
    
    -- Status
    status VARCHAR(20) NOT NULL DEFAULT 'open', -- open, closed
    closed_at TIMESTAMP,
    closed_by UUID REFERENCES users(id),
    
    created_at TIMESTAMP NOT NULL DEFAULT NOW()
);

CREATE TABLE fiscal_periods (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    fiscal_year_id UUID NOT NULL REFERENCES fiscal_years(id),
    company_id UUID NOT NULL REFERENCES companies(id),
    
    name VARCHAR(50) NOT NULL, -- "January 2025"
    period_number INTEGER NOT NULL, -- 1-12
    start_date DATE NOT NULL,
    end_date DATE NOT NULL,
    
    -- Status
    status VARCHAR(20) NOT NULL DEFAULT 'open', -- open, closed
    closed_at TIMESTAMP,
    closed_by UUID REFERENCES users(id),
    
    UNIQUE(fiscal_year_id, period_number)
);
```

### Treasury (Company-Scoped)

```sql
-- Payment Methods: Per COMPANY
CREATE TABLE payment_methods (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    company_id UUID NOT NULL REFERENCES companies(id),
    
    name VARCHAR(100) NOT NULL,
    type VARCHAR(30) NOT NULL, -- 'cash', 'bank_transfer', 'check', 'card', 'mobile'
    
    -- Settings
    is_active BOOLEAN NOT NULL DEFAULT true,
    requires_reference BOOLEAN NOT NULL DEFAULT false,
    
    -- Accounting
    account_id UUID REFERENCES accounts(id),
    
    created_at TIMESTAMP NOT NULL DEFAULT NOW()
);

-- Payment Repositories: Bank accounts, cash registers (per COMPANY)
CREATE TABLE payment_repositories (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    company_id UUID NOT NULL REFERENCES companies(id),
    location_id UUID REFERENCES locations(id), -- NULL = company-wide, or specific location
    
    name VARCHAR(100) NOT NULL,
    type VARCHAR(30) NOT NULL, -- 'bank', 'cash', 'mobile_wallet'
    
    -- For bank accounts
    bank_account_id UUID REFERENCES company_bank_accounts(id),
    
    -- Balance tracking
    current_balance DECIMAL(15, 2) NOT NULL DEFAULT 0,
    
    -- Accounting
    account_id UUID REFERENCES accounts(id),
    
    is_active BOOLEAN NOT NULL DEFAULT true,
    created_at TIMESTAMP NOT NULL DEFAULT NOW()
);

-- Payments: Per COMPANY
CREATE TABLE payments (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    company_id UUID NOT NULL REFERENCES companies(id),
    
    -- Type
    type VARCHAR(20) NOT NULL, -- 'receipt', 'disbursement'
    
    -- Reference
    payment_number VARCHAR(50) NOT NULL,
    payment_date DATE NOT NULL,
    
    -- Party
    partner_id UUID REFERENCES partners(id),
    
    -- Amount
    amount DECIMAL(15, 2) NOT NULL,
    currency CHAR(3) NOT NULL,
    
    -- Method
    payment_method_id UUID REFERENCES payment_methods(id),
    repository_id UUID REFERENCES payment_repositories(id),
    
    -- Reference
    reference VARCHAR(100),
    notes TEXT,
    
    -- Status
    status VARCHAR(20) NOT NULL DEFAULT 'pending', -- pending, completed, cancelled
    
    -- Audit
    created_by UUID REFERENCES users(id),
    created_at TIMESTAMP NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMP NOT NULL DEFAULT NOW(),
    
    UNIQUE(company_id, payment_number)
);

-- Payment Allocations: Link payments to invoices
CREATE TABLE payment_allocations (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    payment_id UUID NOT NULL REFERENCES payments(id),
    document_id UUID NOT NULL REFERENCES documents(id),
    amount DECIMAL(15, 2) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT NOW()
);

-- Payment Instruments: Checks, promissory notes
CREATE TABLE payment_instruments (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    company_id UUID NOT NULL REFERENCES companies(id),
    
    -- Type
    type VARCHAR(30) NOT NULL, -- 'check', 'promissory_note', 'draft'
    direction VARCHAR(20) NOT NULL, -- 'received', 'issued'
    
    -- Instrument details
    instrument_number VARCHAR(50) NOT NULL,
    bank_name VARCHAR(100),
    amount DECIMAL(15, 2) NOT NULL,
    currency CHAR(3) NOT NULL,
    issue_date DATE,
    maturity_date DATE,
    
    -- Party
    partner_id UUID REFERENCES partners(id),
    
    -- Status lifecycle
    status VARCHAR(30) NOT NULL DEFAULT 'received', -- received, deposited, cleared, bounced, cancelled
    
    -- Current custody
    repository_id UUID REFERENCES payment_repositories(id),
    
    -- Linked payment
    payment_id UUID REFERENCES payments(id),
    
    -- Audit
    created_by UUID REFERENCES users(id),
    created_at TIMESTAMP NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMP NOT NULL DEFAULT NOW()
);
```

---

## Refactoring Tasks

### Phase 0: Preparation
- [ ] Create full database backup
- [ ] Document current schema
- [ ] Create rollback plan
- [ ] Set up test environment for migration testing

### Phase 1: Create New Tables
- [ ] Create `companies` table
- [ ] Create `locations` table
- [ ] Create `user_company_memberships` table
- [ ] Create `company_settings` table
- [ ] Create `company_bank_accounts` table

### Phase 2: Add Foreign Keys to Existing Tables
- [ ] Add `company_id` to `partners` (nullable initially)
- [ ] Add `company_id` to `products` (nullable initially)
- [ ] Add `company_id` and `location_id` to `documents` (nullable initially)
- [ ] Add `company_id` to `accounts` (nullable initially)
- [ ] Add `company_id` to `journal_entries` (nullable initially)
- [ ] Add `company_id` to `payments` (nullable initially)
- [ ] Add `company_id` to `payment_methods` (nullable initially)
- [ ] Add `company_id` to `payment_repositories` (nullable initially)
- [ ] Add `company_id` to `payment_instruments` (nullable initially)
- [ ] Add `location_id` to `stock_levels` (nullable initially)
- [ ] Add `location_id` to `stock_movements` (nullable initially)

### Phase 3: Data Migration
- [ ] For each existing tenant:
  1. Create default company using tenant's info
  2. Create default location (type: 'shop', is_default: true)
  3. Migrate user to company membership as 'owner'
  4. Update all partners to link to company
  5. Update all products to link to company
  6. Update all documents to link to company + location
  7. Update all accounts to link to company
  8. Update all journal entries to link to company
  9. Update all payments to link to company
  10. Update all stock levels to link to location
  11. Update all stock movements to link to location

### Phase 4: Make Foreign Keys Required
- [ ] Make `company_id` NOT NULL on all tables
- [ ] Make `location_id` NOT NULL on stock tables
- [ ] Add proper indexes

### Phase 5: Update Models
- [ ] Update `Partner` model: belongs to Company
- [ ] Update `Product` model: belongs to Company
- [ ] Update `Document` model: belongs to Company, optionally Location
- [ ] Update `Account` model: belongs to Company
- [ ] Update `JournalEntry` model: belongs to Company
- [ ] Update `Payment` model: belongs to Company
- [ ] Update `StockLevel` model: belongs to Location (not Company directly)
- [ ] Create `Company` model with all relationships
- [ ] Create `Location` model with all relationships
- [ ] Create `UserCompanyMembership` model
- [ ] Update `User` model: companies() via membership, currentCompany(), switchCompany()

### Phase 6: Update Services
- [ ] Update all services to accept `company_id` instead of `tenant_id`
- [ ] Update stock services to use `location_id`
- [ ] Update document numbering to be per-company
- [ ] Update accounting services to scope to company

### Phase 7: Update Controllers & Middleware
- [ ] Create company context middleware (sets current company from session)
- [ ] Update all controllers to use company context
- [ ] Update all authorization to check company membership

### Phase 8: Update Frontend
- [ ] Add company context to auth state
- [ ] Add company switcher to top bar
- [ ] Add location selector where applicable
- [ ] Update all API calls to include context

### Phase 9: Update Onboarding
- [ ] Signup creates tenant + user only
- [ ] Onboarding creates first company
- [ ] Option to create default location during onboarding
- [ ] "Add Company" flow for existing tenants

### Phase 10: Testing
- [ ] Test single-company tenant (most users)
- [ ] Test multi-company tenant
- [ ] Test multi-location company
- [ ] Test user with access to multiple companies
- [ ] Test stock transfer between locations
- [ ] Test reporting per company vs consolidated
- [ ] Test document numbering per company

### Phase 11: Cleanup
- [ ] Remove deprecated `tenant_id` references where replaced by `company_id`
- [ ] Update seeds and factories
- [ ] Update tests
- [ ] Update documentation

---

## Model Relationships Summary

```php
// Tenant
class Tenant {
    public function companies() { return $this->hasMany(Company::class); }
    public function owner() { return $this->belongsTo(User::class, 'owner_user_id'); }
    public function subscription() { return $this->hasOne(Subscription::class); }
}

// Company
class Company {
    public function tenant() { return $this->belongsTo(Tenant::class); }
    public function locations() { return $this->hasMany(Location::class); }
    public function users() { return $this->belongsToMany(User::class, 'user_company_memberships')->withPivot('role', 'status'); }
    public function partners() { return $this->hasMany(Partner::class); }
    public function products() { return $this->hasMany(Product::class); }
    public function documents() { return $this->hasMany(Document::class); }
    public function accounts() { return $this->hasMany(Account::class); }
    public function journalEntries() { return $this->hasMany(JournalEntry::class); }
    public function payments() { return $this->hasMany(Payment::class); }
    public function bankAccounts() { return $this->hasMany(CompanyBankAccount::class); }
    public function settings() { return $this->hasOne(CompanySettings::class); }
    public function defaultLocation() { return $this->hasOne(Location::class)->where('is_default', true); }
}

// Location
class Location {
    public function company() { return $this->belongsTo(Company::class); }
    public function stockLevels() { return $this->hasMany(StockLevel::class); }
    public function stockMovements() { return $this->hasMany(StockMovement::class); }
}

// User
class User {
    public function companyMemberships() { return $this->hasMany(UserCompanyMembership::class); }
    public function companies() { return $this->belongsToMany(Company::class, 'user_company_memberships')->withPivot('role', 'status'); }
    public function currentCompany() { /* From session */ }
    public function tenants() { return $this->companies()->with('tenant')->get()->pluck('tenant')->unique(); }
}

// StockLevel
class StockLevel {
    public function location() { return $this->belongsTo(Location::class); }
    public function product() { return $this->belongsTo(Product::class); }
    public function company() { return $this->location->company; } // Via location
}

// Document
class Document {
    public function company() { return $this->belongsTo(Company::class); }
    public function location() { return $this->belongsTo(Location::class); } // Optional, for POS
    public function partner() { return $this->belongsTo(Partner::class); }
}
```

---

## API Context Changes

Every API request must know: **Which company? Which location (if relevant)?**

```php
// Middleware sets context from session
$request->company(); // Current company
$request->location(); // Current location (for stock operations)

// Or explicit in request
POST /api/v1/invoices
{
    "company_id": "uuid", // Optional if using session context
    "location_id": "uuid", // Optional, for POS
    "partner_id": "uuid",
    "lines": [...]
}
```

---

## Verification Checklist

After refactor is complete:

- [ ] Existing data migrated correctly (spot check 10 records of each type)
- [ ] Single-company tenant works exactly as before
- [ ] Can create second company in same tenant
- [ ] Can switch between companies
- [ ] Documents show correct company info (header, tax ID)
- [ ] Stock is tracked per location
- [ ] Reports are per company
- [ ] Invoice numbering is per company (independent sequences)
- [ ] Accounting is per company (separate chart of accounts)
- [ ] User permissions work per company

---

*This refactor is the foundation for everything else. Do not proceed with new features until this is complete and verified.*
