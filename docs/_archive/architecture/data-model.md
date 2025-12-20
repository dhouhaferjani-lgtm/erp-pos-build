# AutoERP Database Schema

> Complete database schema documentation for AutoERP.
> All tables use UUID primary keys unless noted otherwise.

---

## Enum Reference

**CRITICAL: All status/type columns MUST use PHP Enums. No magic strings.**

| Column | Enum Class | Values |
|--------|------------|--------|
| `documents.type` | `App\Enums\DocumentType` | quote, sales_order, invoice, credit_note, delivery_note, purchase_order, purchase_invoice |
| `documents.status` | `App\Enums\DocumentStatus` | draft, confirmed, posted, partially_paid, paid, cancelled, archived |
| `payment_methods.code` | `App\Enums\PaymentMethodCode` | CASH, CHECK, PDC, TRANSFER, CARD, SEPA_DD, TRAITE, LCR, MOBILE_MONEY, GIFT_CARD, VOUCHER |
| `payment_instruments.status` | `App\Enums\InstrumentStatus` | received, in_transit, deposited, clearing, cleared, bounced, expired, cancelled, collected |
| `stock_movements.type` | `App\Enums\StockMovementType` | purchase, sale, transfer_in, transfer_out, adjustment, return, scrap |
| `journal_entries.state` | `App\Enums\JournalEntryState` | draft, posted |
| `import_jobs.status` | `App\Enums\ImportJobStatus` | pending, validating, processing, completed, failed, partially_completed |

---

## JSONB Column DTOs

**CRITICAL: Every JSONB column must have a corresponding PHP DTO.**

| Column | DTO Class |
|--------|-----------|
| `documents.payload` | `App\Modules\Sales\DTOs\DocumentPayload` |
| `payment_methods.config` | `App\Modules\Treasury\DTOs\PaymentMethodConfig` |
| `payment_instruments.metadata` | `App\Modules\Treasury\DTOs\InstrumentMetadata` |
| `import_staging.raw_data` | `App\Modules\Import\DTOs\StagingRowData` |
| `import_staging.validation_errors` | `App\Modules\Import\DTOs\ValidationErrorCollection` |

Access pattern:
```php
// WRONG - Forbidden
$doc->payload['legacy_reference'];

// RIGHT - Use typed DTO
$payload = DocumentPayload::fromArray($doc->payload);
$payload->legacyReference;
```

---

## Schema Organization

```
public                    # Shared data, tenant registry
tenant_{slug}            # Per-tenant schema (auto-created)
```

Each tenant schema contains identical table structures. The `public` schema contains:
- `tenants` - Tenant registry
- `users` - All users (tenant_id for association)
- `lookup_*` - Shared lookup tables (countries, currencies, etc.)

---

## Core Tables

### documents

The unified document table for all trade documents.

```sql
CREATE TABLE documents (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    tenant_id UUID NOT NULL REFERENCES tenants(id),
    
    -- Type and identification
    type VARCHAR(50) NOT NULL,  -- quote, sales_order, invoice, credit_note, delivery_note, purchase_order, purchase_invoice
    number VARCHAR(50),         -- Sequential per type: QUO-2025-0001, INV-2025-0001
    reference VARCHAR(100),     -- External/customer reference
    
    -- Status and workflow
    status VARCHAR(30) NOT NULL DEFAULT 'draft',  -- draft, confirmed, posted, partially_paid, paid, cancelled, archived
    
    -- Parties
    partner_id UUID NOT NULL REFERENCES partners(id),
    partner_address_id UUID REFERENCES partner_addresses(id),
    contact_id UUID REFERENCES partner_contacts(id),
    
    -- Dates
    date DATE NOT NULL DEFAULT CURRENT_DATE,
    due_date DATE,
    delivery_date DATE,
    
    -- Amounts (stored in document currency)
    currency CHAR(3) NOT NULL DEFAULT 'EUR',
    exchange_rate DECIMAL(12,6) NOT NULL DEFAULT 1.0,
    total_ht DECIMAL(15,2) NOT NULL DEFAULT 0,      -- Before tax
    total_tax DECIMAL(15,2) NOT NULL DEFAULT 0,
    total_ttc DECIMAL(15,2) NOT NULL DEFAULT 0,     -- After tax
    amount_paid DECIMAL(15,2) NOT NULL DEFAULT 0,
    amount_due DECIMAL(15,2) NOT NULL DEFAULT 0,
    
    -- Source/conversion tracking
    source_document_id UUID REFERENCES documents(id),
    source_document_type VARCHAR(50),
    
    -- Compliance (fiscal chain)
    hash VARCHAR(64),           -- SHA-256 hash (for posted fiscal docs)
    previous_hash VARCHAR(64),  -- Previous doc in chain
    chain_sequence INTEGER,     -- Position in chain
    
    -- Flexible data
    payload JSONB DEFAULT '{}',  -- Type-specific optional fields
    notes TEXT,
    internal_notes TEXT,
    
    -- Audit
    created_by UUID REFERENCES users(id),
    updated_by UUID REFERENCES users(id),
    posted_at TIMESTAMPTZ,
    posted_by UUID REFERENCES users(id),
    cancelled_at TIMESTAMPTZ,
    cancelled_by UUID REFERENCES users(id),
    cancellation_reason TEXT,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    
    -- Constraints
    CONSTRAINT documents_number_unique UNIQUE (tenant_id, type, number),
    CONSTRAINT documents_status_check CHECK (status IN ('draft', 'confirmed', 'posted', 'partially_paid', 'paid', 'cancelled', 'archived')),
    CONSTRAINT documents_type_check CHECK (type IN ('quote', 'sales_order', 'invoice', 'credit_note', 'delivery_note', 'purchase_order', 'purchase_invoice'))
);

-- Indexes
CREATE INDEX idx_documents_tenant_type ON documents(tenant_id, type);
CREATE INDEX idx_documents_partner ON documents(partner_id);
CREATE INDEX idx_documents_status ON documents(status) WHERE status NOT IN ('cancelled', 'archived');
CREATE INDEX idx_documents_date ON documents(date DESC);
CREATE INDEX idx_documents_due_date ON documents(due_date) WHERE status IN ('posted', 'partially_paid');
```

### document_lines

```sql
CREATE TABLE document_lines (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    document_id UUID NOT NULL REFERENCES documents(id) ON DELETE CASCADE,
    sequence INTEGER NOT NULL DEFAULT 0,
    
    -- Product reference (nullable for text-only lines)
    product_id UUID REFERENCES products(id),
    
    -- Line content
    description TEXT NOT NULL,
    
    -- Quantities
    quantity DECIMAL(15,4) NOT NULL DEFAULT 1,
    unit VARCHAR(20) DEFAULT 'unit',
    
    -- Pricing
    unit_price DECIMAL(15,4) NOT NULL DEFAULT 0,
    discount_percent DECIMAL(5,2) DEFAULT 0,
    discount_amount DECIMAL(15,2) DEFAULT 0,
    
    -- Tax
    tax_id UUID REFERENCES taxes(id),
    tax_rate DECIMAL(5,2) DEFAULT 0,
    tax_amount DECIMAL(15,2) DEFAULT 0,
    
    -- Totals
    line_total_ht DECIMAL(15,2) NOT NULL DEFAULT 0,
    line_total_ttc DECIMAL(15,2) NOT NULL DEFAULT 0,
    
    -- Vehicle reference (automotive specific)
    vehicle_id UUID REFERENCES vehicles(id),
    
    -- Flexible data
    metadata JSONB DEFAULT '{}',  -- Serial numbers, lot numbers, etc.
    
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    
    CONSTRAINT document_lines_sequence_unique UNIQUE (document_id, sequence)
);

CREATE INDEX idx_document_lines_document ON document_lines(document_id);
CREATE INDEX idx_document_lines_product ON document_lines(product_id);
```

### invoice_metadata

Compliance-critical fields for invoices.

```sql
CREATE TABLE invoice_metadata (
    document_id UUID PRIMARY KEY REFERENCES documents(id) ON DELETE CASCADE,
    
    -- Payment terms
    payment_terms_id UUID REFERENCES payment_terms(id),
    payment_method_id UUID REFERENCES payment_methods(id),
    bank_account_id UUID REFERENCES bank_accounts(id),
    
    -- E-invoicing (Factur-X)
    facturx_xml TEXT,                    -- Generated XML
    facturx_profile VARCHAR(20),         -- minimum, basic, en16931
    pdp_submission_id VARCHAR(100),      -- PDP reference
    pdp_submitted_at TIMESTAMPTZ,
    pdp_status VARCHAR(30),              -- pending, accepted, rejected
    pdp_response JSONB,
    
    -- Communication
    sent_at TIMESTAMPTZ,
    sent_via VARCHAR(20),                -- email, post, pdp
    reminder_count INTEGER DEFAULT 0,
    last_reminder_at TIMESTAMPTZ,
    
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
```

### delivery_metadata

```sql
CREATE TABLE delivery_metadata (
    document_id UUID PRIMARY KEY REFERENCES documents(id) ON DELETE CASCADE,
    
    -- Delivery info
    carrier_id UUID REFERENCES carriers(id),
    tracking_number VARCHAR(100),
    shipping_method VARCHAR(50),
    
    -- DDT (Italy) / Bon de livraison (France)
    ddt_number VARCHAR(50),
    ddt_date DATE,
    transport_reason VARCHAR(100),       -- sale, repair, return, etc.
    transport_by VARCHAR(50),            -- sender, recipient, carrier
    
    -- Timestamps
    shipped_at TIMESTAMPTZ,
    delivered_at TIMESTAMPTZ,
    
    -- Signature
    recipient_name VARCHAR(200),
    signature_image_id UUID REFERENCES media(id),
    
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
```

### quote_metadata

```sql
CREATE TABLE quote_metadata (
    document_id UUID PRIMARY KEY REFERENCES documents(id) ON DELETE CASCADE,
    
    -- Validity
    valid_until DATE,
    
    -- Conversion tracking
    converted_to_id UUID REFERENCES documents(id),
    converted_at TIMESTAMPTZ,
    conversion_notes TEXT,
    
    -- Follow-up
    follow_up_date DATE,
    follow_up_notes TEXT,
    
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
```

---

## Accounting Tables

### journal_entries

The accounting truth - never edit, only reverse.

```sql
CREATE TABLE journal_entries (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    tenant_id UUID NOT NULL REFERENCES tenants(id),
    
    -- Identification
    number VARCHAR(50) NOT NULL,         -- JE-2025-0001
    reference VARCHAR(100),
    
    -- Source
    document_id UUID REFERENCES documents(id),
    document_type VARCHAR(50),
    
    -- Journal
    journal_id UUID NOT NULL REFERENCES journals(id),
    
    -- Dates
    date DATE NOT NULL,
    accounting_date DATE NOT NULL,       -- Can differ for period adjustments
    
    -- Status
    state VARCHAR(20) NOT NULL DEFAULT 'draft',  -- draft, posted
    
    -- Compliance
    hash VARCHAR(64),
    previous_hash VARCHAR(64),
    chain_sequence INTEGER,
    
    -- Auto-generated flag
    auto_generated BOOLEAN DEFAULT FALSE,
    
    -- Audit
    posted_at TIMESTAMPTZ,
    posted_by UUID REFERENCES users(id),
    created_by UUID REFERENCES users(id),
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    
    CONSTRAINT journal_entries_number_unique UNIQUE (tenant_id, number)
);

CREATE INDEX idx_journal_entries_date ON journal_entries(date DESC);
CREATE INDEX idx_journal_entries_document ON journal_entries(document_id);
CREATE INDEX idx_journal_entries_journal ON journal_entries(journal_id);
```

### journal_lines

```sql
CREATE TABLE journal_lines (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    entry_id UUID NOT NULL REFERENCES journal_entries(id) ON DELETE CASCADE,
    sequence INTEGER NOT NULL DEFAULT 0,
    
    -- Account
    account_id UUID NOT NULL REFERENCES accounts(id),
    
    -- Partner (for receivables/payables)
    partner_id UUID REFERENCES partners(id),
    
    -- Amounts (in company currency)
    debit DECIMAL(15,2) NOT NULL DEFAULT 0,
    credit DECIMAL(15,2) NOT NULL DEFAULT 0,
    
    -- Original currency (if different)
    currency CHAR(3),
    amount_currency DECIMAL(15,2),
    exchange_rate DECIMAL(12,6),
    
    -- Reconciliation
    reconciled BOOLEAN DEFAULT FALSE,
    reconcile_id UUID,
    reconcile_date DATE,
    
    -- Reference
    label TEXT,
    
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    
    CONSTRAINT journal_lines_balance CHECK (
        (debit = 0 AND credit > 0) OR (debit > 0 AND credit = 0) OR (debit = 0 AND credit = 0)
    )
);

CREATE INDEX idx_journal_lines_entry ON journal_lines(entry_id);
CREATE INDEX idx_journal_lines_account ON journal_lines(account_id);
CREATE INDEX idx_journal_lines_partner ON journal_lines(partner_id);
CREATE INDEX idx_journal_lines_unreconciled ON journal_lines(partner_id, account_id) 
    WHERE reconciled = FALSE;
```

### accounts

Chart of accounts.

```sql
CREATE TABLE accounts (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    tenant_id UUID NOT NULL REFERENCES tenants(id),
    
    code VARCHAR(20) NOT NULL,           -- 411000, 701000, etc.
    name VARCHAR(200) NOT NULL,
    
    -- Classification
    type VARCHAR(30) NOT NULL,           -- asset, liability, equity, income, expense
    category VARCHAR(50),                -- receivable, payable, bank, cash, revenue, cogs, etc.
    
    -- Hierarchy
    parent_id UUID REFERENCES accounts(id),
    level INTEGER DEFAULT 0,
    
    -- Behavior
    reconcilable BOOLEAN DEFAULT FALSE,  -- For partner accounts
    deprecated BOOLEAN DEFAULT FALSE,
    
    -- Defaults
    default_tax_id UUID REFERENCES taxes(id),
    
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    
    CONSTRAINT accounts_code_unique UNIQUE (tenant_id, code)
);
```

---

## Treasury Tables

### payment_methods

Universal payment method configuration.

```sql
CREATE TABLE payment_methods (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    tenant_id UUID NOT NULL REFERENCES tenants(id),
    
    -- Identification
    code VARCHAR(30) NOT NULL,           -- CASH, CHECK, BANK_TRANSFER, etc.
    name VARCHAR(100) NOT NULL,
    
    -- Universal switches
    is_physical BOOLEAN DEFAULT FALSE,           -- Needs safe/box storage
    has_maturity BOOLEAN DEFAULT FALSE,          -- Has a due date (PDC, traite)
    requires_third_party BOOLEAN DEFAULT FALSE,  -- Bank/gateway processing
    is_push BOOLEAN DEFAULT TRUE,                -- Push (client sends) vs Pull (we take)
    has_deducted_fees BOOLEAN DEFAULT FALSE,     -- Fees taken from amount
    is_restricted BOOLEAN DEFAULT FALSE,         -- Limited use (meal vouchers)
    
    -- Fees
    fee_type VARCHAR(20),                -- none, fixed, percentage, mixed
    fee_fixed DECIMAL(10,2) DEFAULT 0,
    fee_percent DECIMAL(5,2) DEFAULT 0,
    
    -- Restrictions
    restriction_type VARCHAR(50),        -- food, fuel, etc.
    
    -- Linked accounts
    default_journal_id UUID REFERENCES journals(id),
    default_account_id UUID REFERENCES accounts(id),
    fee_account_id UUID REFERENCES accounts(id),
    
    -- Status
    active BOOLEAN DEFAULT TRUE,
    position INTEGER DEFAULT 0,
    
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    
    CONSTRAINT payment_methods_code_unique UNIQUE (tenant_id, code)
);
```

### payment_repositories

Physical and virtual storage for payment instruments.

```sql
CREATE TABLE payment_repositories (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    tenant_id UUID NOT NULL REFERENCES tenants(id),
    
    -- Identification
    code VARCHAR(30) NOT NULL,
    name VARCHAR(100) NOT NULL,
    type VARCHAR(30) NOT NULL,           -- cash_register, safe, bank_account, virtual
    
    -- For bank accounts
    bank_name VARCHAR(100),
    account_number VARCHAR(50),
    iban VARCHAR(50),
    bic VARCHAR(20),
    
    -- Balance tracking
    balance DECIMAL(15,2) DEFAULT 0,
    last_reconciled_at TIMESTAMPTZ,
    last_reconciled_balance DECIMAL(15,2),
    
    -- Access control
    location_id UUID REFERENCES locations(id),
    responsible_user_id UUID REFERENCES users(id),
    
    -- Linked account
    account_id UUID REFERENCES accounts(id),
    
    -- Status
    active BOOLEAN DEFAULT TRUE,
    
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    
    CONSTRAINT payment_repositories_code_unique UNIQUE (tenant_id, code)
);
```

### payment_instruments

Physical payment instruments (checks, vouchers, etc.).

```sql
CREATE TABLE payment_instruments (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    tenant_id UUID NOT NULL REFERENCES tenants(id),
    
    -- Type and method
    payment_method_id UUID NOT NULL REFERENCES payment_methods(id),
    
    -- Identification
    reference VARCHAR(100) NOT NULL,     -- Check number, voucher code
    
    -- Party
    partner_id UUID REFERENCES partners(id),
    drawer_name VARCHAR(200),            -- Name on check
    
    -- Amount
    amount DECIMAL(15,2) NOT NULL,
    currency CHAR(3) DEFAULT 'EUR',
    
    -- Dates
    received_date DATE NOT NULL,
    maturity_date DATE,                  -- For PDC/traite
    expiry_date DATE,                    -- For vouchers
    
    -- Status and location
    status VARCHAR(30) NOT NULL DEFAULT 'received',
    -- received, in_transit, deposited, clearing, cleared, bounced, expired, cancelled, collected
    repository_id UUID REFERENCES payment_repositories(id),
    
    -- Bank info (for checks)
    bank_name VARCHAR(100),
    bank_branch VARCHAR(100),
    bank_account VARCHAR(50),
    
    -- Status history (denormalized for quick access)
    deposited_at TIMESTAMPTZ,
    deposited_to_id UUID REFERENCES payment_repositories(id),
    cleared_at TIMESTAMPTZ,
    bounced_at TIMESTAMPTZ,
    bounce_reason TEXT,
    
    -- Link to payment
    payment_id UUID REFERENCES payments(id),
    
    -- Audit
    created_by UUID REFERENCES users(id),
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX idx_payment_instruments_status ON payment_instruments(status) 
    WHERE status NOT IN ('cleared', 'bounced', 'expired', 'cancelled');
CREATE INDEX idx_payment_instruments_maturity ON payment_instruments(maturity_date) 
    WHERE status = 'received' AND maturity_date IS NOT NULL;
CREATE INDEX idx_payment_instruments_partner ON payment_instruments(partner_id);
```

### instrument_movements

Track custody changes for payment instruments.

```sql
CREATE TABLE instrument_movements (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    instrument_id UUID NOT NULL REFERENCES payment_instruments(id),
    
    -- Movement
    from_status VARCHAR(30) NOT NULL,
    to_status VARCHAR(30) NOT NULL,
    from_repository_id UUID REFERENCES payment_repositories(id),
    to_repository_id UUID REFERENCES payment_repositories(id),
    
    -- Reason
    reason VARCHAR(100),
    notes TEXT,
    
    -- Audit
    performed_by UUID REFERENCES users(id),
    performed_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX idx_instrument_movements_instrument ON instrument_movements(instrument_id);
```

### payments

Actual payment records.

```sql
CREATE TABLE payments (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    tenant_id UUID NOT NULL REFERENCES tenants(id),
    
    -- Identification
    number VARCHAR(50) NOT NULL,         -- PAY-2025-0001
    
    -- Type and direction
    type VARCHAR(20) NOT NULL,           -- inbound (from customer), outbound (to supplier)
    payment_method_id UUID NOT NULL REFERENCES payment_methods(id),
    
    -- Party
    partner_id UUID NOT NULL REFERENCES partners(id),
    
    -- Amount
    amount DECIMAL(15,2) NOT NULL,
    currency CHAR(3) DEFAULT 'EUR',
    exchange_rate DECIMAL(12,6) DEFAULT 1.0,
    
    -- Fees (for methods with deducted fees)
    fee_amount DECIMAL(10,2) DEFAULT 0,
    net_amount DECIMAL(15,2) NOT NULL,   -- amount - fee_amount
    
    -- Repository
    repository_id UUID NOT NULL REFERENCES payment_repositories(id),
    
    -- Dates
    date DATE NOT NULL,
    value_date DATE,                     -- Bank value date
    
    -- Reference
    reference VARCHAR(100),              -- Bank reference, transaction ID
    notes TEXT,
    
    -- Status
    status VARCHAR(20) NOT NULL DEFAULT 'draft',  -- draft, confirmed, reconciled
    
    -- Journal entry
    journal_entry_id UUID REFERENCES journal_entries(id),
    
    -- Audit
    created_by UUID REFERENCES users(id),
    confirmed_by UUID REFERENCES users(id),
    confirmed_at TIMESTAMPTZ,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    
    CONSTRAINT payments_number_unique UNIQUE (tenant_id, number)
);

CREATE INDEX idx_payments_partner ON payments(partner_id);
CREATE INDEX idx_payments_date ON payments(date DESC);
```

### payment_allocations

Link payments to invoices.

```sql
CREATE TABLE payment_allocations (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    payment_id UUID NOT NULL REFERENCES payments(id) ON DELETE CASCADE,
    document_id UUID NOT NULL REFERENCES documents(id),
    
    amount DECIMAL(15,2) NOT NULL,
    
    -- For partial allocations
    allocated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    allocated_by UUID REFERENCES users(id),
    
    CONSTRAINT payment_allocations_unique UNIQUE (payment_id, document_id)
);

CREATE INDEX idx_payment_allocations_document ON payment_allocations(document_id);
```

---

## Inventory Tables

### products

```sql
CREATE TABLE products (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    tenant_id UUID NOT NULL REFERENCES tenants(id),
    
    -- Identification
    sku VARCHAR(50) NOT NULL,
    barcode VARCHAR(50),
    name VARCHAR(200) NOT NULL,
    description TEXT,
    
    -- Classification
    type VARCHAR(20) NOT NULL DEFAULT 'product',  -- product, service, kit
    category_id UUID REFERENCES categories(id),
    brand_id UUID REFERENCES brands(id),
    
    -- Pricing
    purchase_price DECIMAL(15,4) DEFAULT 0,
    sale_price DECIMAL(15,4) DEFAULT 0,
    
    -- Tax
    purchase_tax_id UUID REFERENCES taxes(id),
    sale_tax_id UUID REFERENCES taxes(id),
    
    -- Inventory
    track_inventory BOOLEAN DEFAULT TRUE,
    min_stock DECIMAL(15,4) DEFAULT 0,
    
    -- Automotive specific
    is_automotive_part BOOLEAN DEFAULT FALSE,
    oem_numbers JSONB DEFAULT '[]',      -- Original equipment numbers
    cross_references JSONB DEFAULT '[]', -- Compatible part numbers
    
    -- Status
    active BOOLEAN DEFAULT TRUE,
    
    -- Accounting
    income_account_id UUID REFERENCES accounts(id),
    expense_account_id UUID REFERENCES accounts(id),
    inventory_account_id UUID REFERENCES accounts(id),
    
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    
    CONSTRAINT products_sku_unique UNIQUE (tenant_id, sku)
);

CREATE INDEX idx_products_category ON products(category_id);
CREATE INDEX idx_products_barcode ON products(barcode);
CREATE INDEX idx_products_search ON products USING gin(to_tsvector('simple', name || ' ' || COALESCE(description, '')));
```

### stock_levels

Current stock by product and location.

```sql
CREATE TABLE stock_levels (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    tenant_id UUID NOT NULL REFERENCES tenants(id),
    product_id UUID NOT NULL REFERENCES products(id),
    location_id UUID NOT NULL REFERENCES locations(id),
    
    -- Quantities
    quantity DECIMAL(15,4) NOT NULL DEFAULT 0,
    reserved DECIMAL(15,4) NOT NULL DEFAULT 0,     -- Reserved for orders
    available DECIMAL(15,4) GENERATED ALWAYS AS (quantity - reserved) STORED,
    
    -- Valuation
    average_cost DECIMAL(15,4) DEFAULT 0,
    
    -- Last activity
    last_movement_at TIMESTAMPTZ,
    last_count_at TIMESTAMPTZ,
    last_count_quantity DECIMAL(15,4),
    
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    
    CONSTRAINT stock_levels_unique UNIQUE (tenant_id, product_id, location_id),
    CONSTRAINT stock_levels_quantity_positive CHECK (quantity >= 0),
    CONSTRAINT stock_levels_reserved_valid CHECK (reserved >= 0 AND reserved <= quantity)
);

CREATE INDEX idx_stock_levels_product ON stock_levels(product_id);
CREATE INDEX idx_stock_levels_location ON stock_levels(location_id);
CREATE INDEX idx_stock_levels_low_stock ON stock_levels(product_id) 
    WHERE available < 0;  -- Would need to join with products for min_stock
```

### stock_movements

Track all inventory changes.

```sql
CREATE TABLE stock_movements (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    tenant_id UUID NOT NULL REFERENCES tenants(id),
    
    -- Product and location
    product_id UUID NOT NULL REFERENCES products(id),
    from_location_id UUID REFERENCES locations(id),
    to_location_id UUID REFERENCES locations(id),
    
    -- Movement type
    type VARCHAR(30) NOT NULL,
    -- purchase, sale, transfer, adjustment, return_in, return_out, production_in, production_out
    
    -- Quantity (positive = in, negative = out)
    quantity DECIMAL(15,4) NOT NULL,
    
    -- Valuation
    unit_cost DECIMAL(15,4),
    total_cost DECIMAL(15,2),
    
    -- Source document
    document_id UUID REFERENCES documents(id),
    document_line_id UUID REFERENCES document_lines(id),
    
    -- Reference
    reference VARCHAR(100),
    notes TEXT,
    
    -- Audit
    created_by UUID REFERENCES users(id),
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX idx_stock_movements_product ON stock_movements(product_id);
CREATE INDEX idx_stock_movements_document ON stock_movements(document_id);
CREATE INDEX idx_stock_movements_date ON stock_movements(created_at DESC);
```

---

## Partner Tables

### partners

Customers and suppliers.

```sql
CREATE TABLE partners (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    tenant_id UUID NOT NULL REFERENCES tenants(id),
    
    -- Type
    is_customer BOOLEAN DEFAULT FALSE,
    is_supplier BOOLEAN DEFAULT FALSE,
    
    -- Identification
    code VARCHAR(30),
    name VARCHAR(200) NOT NULL,
    legal_name VARCHAR(200),
    
    -- Tax info
    vat_number VARCHAR(50),
    tax_id VARCHAR(50),                  -- National tax ID
    
    -- Classification
    category_id UUID REFERENCES partner_categories(id),
    
    -- Defaults
    payment_terms_id UUID REFERENCES payment_terms(id),
    payment_method_id UUID REFERENCES payment_methods(id),
    currency CHAR(3) DEFAULT 'EUR',
    
    -- Credit
    credit_limit DECIMAL(15,2),
    
    -- Accounting
    receivable_account_id UUID REFERENCES accounts(id),
    payable_account_id UUID REFERENCES accounts(id),
    
    -- Status
    active BOOLEAN DEFAULT TRUE,
    
    -- Flexible data
    metadata JSONB DEFAULT '{}',
    notes TEXT,
    
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    
    CONSTRAINT partners_code_unique UNIQUE (tenant_id, code)
);

CREATE INDEX idx_partners_name ON partners(tenant_id, name);
CREATE INDEX idx_partners_vat ON partners(vat_number) WHERE vat_number IS NOT NULL;
```

---

## Event Sourcing Tables

### domain_events

All domain events for audit trail.

```sql
CREATE TABLE domain_events (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    tenant_id UUID NOT NULL,
    
    -- Aggregate info
    aggregate_type VARCHAR(100) NOT NULL,   -- Document, Partner, Product, etc.
    aggregate_id UUID NOT NULL,
    
    -- Event info
    event_type VARCHAR(100) NOT NULL,       -- DocumentCreated, InvoicePosted, etc.
    event_version INTEGER DEFAULT 1,
    
    -- Payload
    payload JSONB NOT NULL,
    
    -- Metadata
    metadata JSONB DEFAULT '{}',            -- User agent, IP, etc.
    
    -- Hash (individual, not chained)
    hash VARCHAR(64) NOT NULL,
    
    -- Actor
    actor_id UUID REFERENCES users(id),
    actor_type VARCHAR(30) DEFAULT 'user',  -- user, system, api
    
    -- Timing
    occurred_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    recorded_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

-- TimescaleDB hypertable for time-series queries
SELECT create_hypertable('domain_events', 'occurred_at');

CREATE INDEX idx_domain_events_aggregate ON domain_events(aggregate_type, aggregate_id);
CREATE INDEX idx_domain_events_type ON domain_events(event_type);
CREATE INDEX idx_domain_events_actor ON domain_events(actor_id);
```

### fiscal_closings

Daily, monthly, annual closings for compliance.

```sql
CREATE TABLE fiscal_closings (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    tenant_id UUID NOT NULL REFERENCES tenants(id),
    
    -- Period
    type VARCHAR(20) NOT NULL,           -- daily, monthly, annual
    period_start DATE NOT NULL,
    period_end DATE NOT NULL,
    
    -- Totals
    total_sales DECIMAL(15,2) NOT NULL,
    total_tax DECIMAL(15,2) NOT NULL,
    total_transactions INTEGER NOT NULL,
    
    -- Perpetual totals (cumulative)
    perpetual_sales DECIMAL(20,2) NOT NULL,
    perpetual_tax DECIMAL(20,2) NOT NULL,
    perpetual_transactions BIGINT NOT NULL,
    
    -- Hash chain
    hash VARCHAR(64) NOT NULL,
    previous_hash VARCHAR(64),
    chain_sequence INTEGER NOT NULL,
    
    -- Audit
    closed_by UUID REFERENCES users(id),
    closed_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    
    CONSTRAINT fiscal_closings_unique UNIQUE (tenant_id, type, period_start)
);
```

---

## Import Tables

### import_jobs

```sql
CREATE TABLE import_jobs (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    tenant_id UUID NOT NULL REFERENCES tenants(id),
    
    -- Type
    import_type VARCHAR(50) NOT NULL,    -- suppliers, customers, products, stock_levels, opening_balances
    
    -- Status
    status VARCHAR(30) NOT NULL DEFAULT 'pending',
    -- pending, validating, processing, completed, failed, partially_completed
    
    -- Counts
    total_rows INTEGER DEFAULT 0,
    processed_rows INTEGER DEFAULT 0,
    success_rows INTEGER DEFAULT 0,
    error_rows INTEGER DEFAULT 0,
    
    -- Files
    source_file_id UUID REFERENCES media(id),
    error_report_id UUID REFERENCES media(id),
    
    -- Mapping
    column_mapping JSONB,
    
    -- Timing
    started_at TIMESTAMPTZ,
    completed_at TIMESTAMPTZ,
    
    -- Audit
    created_by UUID REFERENCES users(id),
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
```

### import_staging

```sql
CREATE TABLE import_staging (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    job_id UUID NOT NULL REFERENCES import_jobs(id) ON DELETE CASCADE,
    row_number INTEGER NOT NULL,
    
    -- All data as strings initially
    raw_data JSONB NOT NULL,
    
    -- Validation
    is_valid BOOLEAN,
    validation_errors JSONB DEFAULT '[]',
    
    -- Processing
    processed BOOLEAN DEFAULT FALSE,
    created_record_id UUID,
    
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX idx_import_staging_job ON import_staging(job_id);
CREATE INDEX idx_import_staging_invalid ON import_staging(job_id) WHERE is_valid = FALSE;
```

---

## Performance Optimizations

### Partitioning Strategy

```sql
-- Partition documents by year
CREATE TABLE documents (
    ...
) PARTITION BY RANGE (date);

CREATE TABLE documents_2024 PARTITION OF documents
    FOR VALUES FROM ('2024-01-01') TO ('2025-01-01');
    
CREATE TABLE documents_2025 PARTITION OF documents
    FOR VALUES FROM ('2025-01-01') TO ('2026-01-01');

-- Partition domain_events by month (handled by TimescaleDB)
```

### Partial Indexes

```sql
-- Only index active/open documents
CREATE INDEX idx_documents_open ON documents(tenant_id, type, date)
    WHERE status NOT IN ('cancelled', 'archived', 'paid');

-- Only index unreconciled journal lines
CREATE INDEX idx_journal_lines_open ON journal_lines(account_id, partner_id)
    WHERE reconciled = FALSE;

-- Only index pending instruments
CREATE INDEX idx_instruments_pending ON payment_instruments(maturity_date)
    WHERE status IN ('received', 'deposited', 'clearing');
```

### Materialized Views

```sql
-- Partner balances (refreshed periodically)
CREATE MATERIALIZED VIEW partner_balances AS
SELECT 
    tenant_id,
    partner_id,
    SUM(debit) as total_debit,
    SUM(credit) as total_credit,
    SUM(debit - credit) as balance
FROM journal_lines jl
JOIN journal_entries je ON jl.entry_id = je.id
WHERE je.state = 'posted'
GROUP BY tenant_id, partner_id;

CREATE UNIQUE INDEX idx_partner_balances ON partner_balances(tenant_id, partner_id);
```

---

*Schema Version: 1.0*
*Last Updated: November 2025*
