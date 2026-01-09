# POS Module - Database Schema Specification

**Version:** 1.0
**Date:** 2026-01-08
**Status:** Ready for Implementation
**Compliance:** NF525 (France), Extensible for ZATCA (Saudi Arabia)

---

## Overview

This document defines the complete database schema for the POS (Point of Sale) module. The schema is designed for:

- **NF525 Compliance**: Hash-chained receipts with immutability guarantees
- **Multi-Terminal Support**: Each physical terminal maintains independent receipt sequences
- **Offline-First Ready**: Schema supports future Tauri app with local SQLite sync
- **Audit Trail**: Complete traceability for fiscal compliance and fraud detection

---

## Architecture Principles

1. **Separate from Documents**: POS receipts are NOT stored in `documents` table
2. **Terminal-Level Hash Chains**: Each terminal has independent hash chain with genesis seed
3. **Immutable by Design**: PostgreSQL triggers prevent modification/deletion after creation
4. **Annual Sequence Reset**: Receipt numbering resets every January 1st per terminal
5. **Rich Metadata**: VAT breakdown and payment methods tracked for compliance

---

## Entity Relationship Diagram

```
┌─────────────────┐
│   tenants       │
└────────┬────────┘
         │
         ├──────────────────────────────────┐
         │                                  │
┌────────▼────────┐                ┌───────▼────────┐
│   companies     │                │     users      │
└────────┬────────┘                └───────┬────────┘
         │                                  │
         ├─────────┐                        │
         │         │                        │
┌────────▼────┐ ┌──▼───────────┐           │
│  locations  │ │ payment_     │           │
└────────┬────┘ │ methods      │           │
         │      └──┬───────────┘           │
         │         │                       │
┌────────▼─────────▼───┐                   │
│   pos_terminals      │                   │
│ - genesis_seed       │                   │
│ - current_sequence   │                   │
│ - last_hash          │                   │
└──────────┬───────────┘                   │
           │                               │
           │      ┌────────────────────────┘
           │      │
┌──────────▼──────▼──────┐
│    pos_receipts        │
│ - receipt_number (UK)  │
│ - fiscal_hash          │
│ - previous_hash        │
│ - chain_sequence       │
└──┬────────┬────────┬───┘
   │        │        │
   │        │        └──────────────────┐
   │        │                           │
┌──▼────────▼───────┐      ┌────────────▼────────────┐
│ pos_receipt_lines │      │ pos_receipt_payments    │
└───────────────────┘      └─────────────────────────┘
           │
┌──────────▼─────────────┐
│ pos_receipt_vat_details│
└────────────────────────┘
```

---

## Core Tables

### 1. pos_terminals

Physical POS devices (cash registers, tablets, kiosks). Each terminal maintains its own receipt sequence and hash chain.

```sql
CREATE TABLE pos_terminals (
    -- Primary Key
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),

    -- Multi-tenancy
    tenant_id UUID NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
    company_id UUID NOT NULL REFERENCES companies(id) ON DELETE CASCADE,
    location_id UUID NOT NULL REFERENCES locations(id) ON DELETE CASCADE,

    -- Terminal Identity
    code VARCHAR(10) NOT NULL,              -- POS01, POS02, etc.
    name VARCHAR(100) NOT NULL,             -- "Front Counter Terminal"
    description TEXT,

    -- Hash Chain State (NF525 Compliance)
    genesis_seed VARCHAR(64) NOT NULL,      -- 256-bit random hex (generated on creation)
    current_sequence INTEGER NOT NULL DEFAULT 0,
    current_year INTEGER NOT NULL,          -- Year for sequence reset
    last_hash VARCHAR(64),                  -- Most recent receipt hash (NULL if no receipts)

    -- Lifecycle
    is_active BOOLEAN NOT NULL DEFAULT true,
    activated_at TIMESTAMP,
    deactivated_at TIMESTAMP,
    deactivation_reason TEXT,

    -- Metadata
    hardware_identifier VARCHAR(100),       -- MAC address, serial number, etc.
    pos_software_version VARCHAR(20),       -- Tauri app version (Phase 2)

    -- Timestamps
    created_at TIMESTAMP NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMP NOT NULL DEFAULT NOW(),
    deleted_at TIMESTAMP,                   -- Soft delete

    -- Constraints
    CONSTRAINT pos_terminals_code_format CHECK (code ~ '^POS[0-9]{2}$'),
    CONSTRAINT pos_terminals_unique_code UNIQUE(tenant_id, company_id, location_id, code),
    CONSTRAINT pos_terminals_genesis_length CHECK (length(genesis_seed) = 64)
);

-- Indexes
CREATE INDEX idx_pos_terminals_tenant ON pos_terminals(tenant_id);
CREATE INDEX idx_pos_terminals_company ON pos_terminals(company_id);
CREATE INDEX idx_pos_terminals_location ON pos_terminals(location_id);
CREATE INDEX idx_pos_terminals_active ON pos_terminals(is_active) WHERE is_active = true;

-- Comments
COMMENT ON TABLE pos_terminals IS 'Physical POS devices with independent receipt sequences';
COMMENT ON COLUMN pos_terminals.genesis_seed IS 'Random 256-bit seed for hash chain initialization (hex string)';
COMMENT ON COLUMN pos_terminals.current_sequence IS 'Next receipt sequence number for current year';
COMMENT ON COLUMN pos_terminals.last_hash IS 'Hash of most recent receipt (for chain continuity)';
```

---

### 2. pos_receipts

Immutable transaction records (NF525 TICKET events). Each receipt is hash-chained to previous receipt.

```sql
CREATE TABLE pos_receipts (
    -- Primary Key
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),

    -- Multi-tenancy
    tenant_id UUID NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
    company_id UUID NOT NULL REFERENCES companies(id) ON DELETE CASCADE,
    location_id UUID NOT NULL REFERENCES locations(id) ON DELETE CASCADE,
    terminal_id UUID NOT NULL REFERENCES pos_terminals(id) ON DELETE RESTRICT,

    -- Receipt Identity (NF525 Compliant)
    receipt_number VARCHAR(50) NOT NULL UNIQUE,  -- T001-C042-L01-POS03-2026-00000001
    chain_sequence INTEGER NOT NULL,             -- Sequential per terminal
    receipt_year INTEGER NOT NULL,               -- Year for filtering/reset logic

    -- Hash Chain (NF525 Critical Fields)
    fiscal_hash VARCHAR(64) NOT NULL,            -- SHA-256 of this receipt
    previous_hash VARCHAR(64),                   -- Previous receipt hash (NULL for first)
    vat_breakdown_hash VARCHAR(64) NOT NULL,     -- SHA-256 of VAT details
    payment_methods_hash VARCHAR(64) NOT NULL,   -- SHA-256 of payment methods

    -- Transaction Timestamp
    posted_at TIMESTAMP NOT NULL,                -- Receipt creation time (fiscal timestamp)

    -- Cashier
    cashier_id UUID NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
    cashier_name VARCHAR(100) NOT NULL,          -- Snapshot for audit trail

    -- Financial Totals (NF525 Required)
    subtotal DECIMAL(12,2) NOT NULL,             -- Net amount before tax
    tax_amount DECIMAL(12,2) NOT NULL,           -- Total VAT/tax
    total DECIMAL(12,2) NOT NULL,                -- Gross total (subtotal + tax)
    currency VARCHAR(3) NOT NULL DEFAULT 'TND',

    -- Business Context
    consumption_mode VARCHAR(20),                -- SUR_PLACE, A_EMPORTER (France VAT)
    customer_name VARCHAR(100),                  -- Optional customer name
    customer_identifier VARCHAR(50),             -- Loyalty number, phone, etc.

    -- Void Handling (NF525 - voids create compensating entries)
    is_voided BOOLEAN NOT NULL DEFAULT false,
    voided_at TIMESTAMP,
    voided_by UUID REFERENCES users(id) ON DELETE RESTRICT,
    void_reason TEXT,
    void_receipt_id UUID REFERENCES pos_receipts(id), -- Reference to negative receipt

    -- Sync Tracking (Offline Terminals - Phase 2)
    synced_at TIMESTAMP,                         -- When terminal synced to server
    sync_error TEXT,                             -- Last sync error if any

    -- Notes
    notes TEXT,

    -- Timestamps
    created_at TIMESTAMP NOT NULL DEFAULT NOW(), -- Server creation time
    updated_at TIMESTAMP NOT NULL DEFAULT NOW(),

    -- Constraints
    CONSTRAINT pos_receipts_totals CHECK (total = subtotal + tax_amount),
    CONSTRAINT pos_receipts_sequence CHECK (chain_sequence > 0),
    CONSTRAINT pos_receipts_year_range CHECK (receipt_year BETWEEN 2020 AND 2100),
    CONSTRAINT pos_receipts_hash_length CHECK (
        length(fiscal_hash) = 64 AND
        length(vat_breakdown_hash) = 64 AND
        length(payment_methods_hash) = 64
    ),
    CONSTRAINT pos_receipts_void_logic CHECK (
        (is_voided = false) OR
        (is_voided = true AND voided_at IS NOT NULL AND voided_by IS NOT NULL)
    )
);

-- Indexes for Performance
CREATE INDEX idx_pos_receipts_tenant ON pos_receipts(tenant_id);
CREATE INDEX idx_pos_receipts_company ON pos_receipts(company_id);
CREATE INDEX idx_pos_receipts_terminal_date ON pos_receipts(terminal_id, posted_at DESC);
CREATE INDEX idx_pos_receipts_cashier_date ON pos_receipts(cashier_id, posted_at DESC);
CREATE INDEX idx_pos_receipts_posted_at ON pos_receipts(posted_at);
CREATE INDEX idx_pos_receipts_year_seq ON pos_receipts(receipt_year, chain_sequence);
CREATE INDEX idx_pos_receipts_not_synced ON pos_receipts(terminal_id, synced_at)
    WHERE synced_at IS NULL;

-- Unique constraint for terminal sequence
CREATE UNIQUE INDEX idx_pos_receipts_terminal_sequence
    ON pos_receipts(terminal_id, receipt_year, chain_sequence);

-- Comments
COMMENT ON TABLE pos_receipts IS 'Immutable POS transaction records (NF525 TICKET events)';
COMMENT ON COLUMN pos_receipts.fiscal_hash IS 'SHA-256 hash of receipt (receipt_number|timestamp|total|currency|vat_hash|payment_hash)';
COMMENT ON COLUMN pos_receipts.previous_hash IS 'Hash of previous receipt in terminal chain (NULL for first receipt)';
COMMENT ON COLUMN pos_receipts.consumption_mode IS 'Affects VAT rate in France: SUR_PLACE (dine-in) vs A_EMPORTER (takeaway)';
```

---

### 3. pos_receipt_lines

Line items on receipts (menu items, products, or services).

```sql
CREATE TABLE pos_receipt_lines (
    -- Primary Key
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    receipt_id UUID NOT NULL REFERENCES pos_receipts(id) ON DELETE CASCADE,
    line_number INTEGER NOT NULL,

    -- Item Reference (menu item OR product - mutually exclusive)
    menu_item_id UUID REFERENCES menu_items(id) ON DELETE RESTRICT,
    product_id UUID REFERENCES products(id) ON DELETE RESTRICT,

    -- Item Details (snapshot for audit trail)
    description VARCHAR(255) NOT NULL,           -- "Large Cappuccino + Oat Milk"
    sku VARCHAR(50),                             -- Product SKU if applicable

    -- Quantity and Pricing
    quantity DECIMAL(10,3) NOT NULL,
    unit_price DECIMAL(12,2) NOT NULL,           -- Price per unit
    line_subtotal DECIMAL(12,2) NOT NULL,        -- quantity * unit_price

    -- Tax (NF525 - snapshot at transaction time)
    tax_category VARCHAR(50) NOT NULL,           -- TVA_20, TVA_10, TVA_5_5, etc.
    tax_rate DECIMAL(5,2) NOT NULL,              -- 20.00, 10.00, 5.50
    tax_amount DECIMAL(12,2) NOT NULL,
    line_total DECIMAL(12,2) NOT NULL,           -- line_subtotal + tax_amount

    -- Modifiers (café use case - stored as JSONB)
    modifiers JSONB,                             -- [{"name": "Oat Milk", "price": 0.50}, ...]

    -- Discount (if applied at line level)
    discount_percentage DECIMAL(5,2) DEFAULT 0.00,
    discount_amount DECIMAL(12,2) DEFAULT 0.00,

    -- Timestamps
    created_at TIMESTAMP NOT NULL DEFAULT NOW(),

    -- Constraints
    CONSTRAINT pos_receipt_lines_quantity CHECK (quantity > 0),
    CONSTRAINT pos_receipt_lines_subtotal CHECK (line_subtotal = quantity * unit_price),
    CONSTRAINT pos_receipt_lines_total CHECK (line_total = line_subtotal + tax_amount - discount_amount),
    CONSTRAINT pos_receipt_lines_item_xor CHECK (
        (menu_item_id IS NOT NULL AND product_id IS NULL) OR
        (menu_item_id IS NULL AND product_id IS NOT NULL)
    ),
    CONSTRAINT pos_receipt_lines_unique_line UNIQUE(receipt_id, line_number)
);

-- Indexes
CREATE INDEX idx_pos_receipt_lines_receipt ON pos_receipt_lines(receipt_id);
CREATE INDEX idx_pos_receipt_lines_menu_item ON pos_receipt_lines(menu_item_id)
    WHERE menu_item_id IS NOT NULL;
CREATE INDEX idx_pos_receipt_lines_product ON pos_receipt_lines(product_id)
    WHERE product_id IS NOT NULL;

-- Comments
COMMENT ON TABLE pos_receipt_lines IS 'Line items on POS receipts';
COMMENT ON COLUMN pos_receipt_lines.modifiers IS 'JSON array of modifiers for menu items: [{"name": "...", "price": ...}]';
```

---

### 4. pos_receipt_vat_details

VAT/tax breakdown per receipt (NF525 requirement).

```sql
CREATE TABLE pos_receipt_vat_details (
    -- Primary Key
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    receipt_id UUID NOT NULL REFERENCES pos_receipts(id) ON DELETE CASCADE,

    -- Tax Category and Rate
    tax_category VARCHAR(50) NOT NULL,           -- TVA_20, TVA_10, TVA_5_5
    tax_rate DECIMAL(5,2) NOT NULL,              -- 20.00, 10.00, 5.50

    -- Amounts (NF525 Required Fields)
    net_amount DECIMAL(12,2) NOT NULL,           -- Subtotal before tax
    vat_amount DECIMAL(12,2) NOT NULL,           -- Tax amount
    gross_amount DECIMAL(12,2) NOT NULL,         -- Total including tax

    -- Timestamps
    created_at TIMESTAMP NOT NULL DEFAULT NOW(),

    -- Constraints
    CONSTRAINT pos_receipt_vat_totals CHECK (gross_amount = net_amount + vat_amount),
    CONSTRAINT pos_receipt_vat_rate_range CHECK (tax_rate >= 0 AND tax_rate <= 100)
);

-- Indexes
CREATE INDEX idx_pos_receipt_vat_receipt ON pos_receipt_vat_details(receipt_id);

-- Comments
COMMENT ON TABLE pos_receipt_vat_details IS 'VAT breakdown per receipt (NF525 compliance)';
COMMENT ON COLUMN pos_receipt_vat_details.tax_category IS 'Tax category code from tax configuration';
```

---

### 5. pos_receipt_payments

Payment method breakdown per receipt (supports split payments).

```sql
CREATE TABLE pos_receipt_payments (
    -- Primary Key
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    receipt_id UUID NOT NULL REFERENCES pos_receipts(id) ON DELETE CASCADE,

    -- Payment Method
    payment_method_id UUID NOT NULL REFERENCES payment_methods(id) ON DELETE RESTRICT,
    payment_type VARCHAR(50) NOT NULL,           -- CASH, CARD, VOUCHER, MOBILE
    payment_method_name VARCHAR(100) NOT NULL,   -- Snapshot for audit

    -- Amount
    amount DECIMAL(12,2) NOT NULL,
    currency VARCHAR(3) NOT NULL DEFAULT 'TND',

    -- Cash Specific
    amount_tendered DECIMAL(12,2),               -- For cash: amount given by customer
    change_given DECIMAL(12,2),                  -- For cash: change returned

    -- Card Specific
    card_type VARCHAR(20),                       -- VISA, MASTERCARD, AMEX
    card_last_four VARCHAR(4),
    transaction_reference VARCHAR(100),          -- Bank authorization code
    terminal_reference VARCHAR(50),              -- Payment terminal ID

    -- Voucher Specific (Ticket Restaurant, etc.)
    voucher_provider VARCHAR(50),                -- SWILE, EDENRED, etc.
    voucher_serial VARCHAR(50),                  -- Voucher serial number
    voucher_daily_usage DECIMAL(12,2),           -- Track against daily limit

    -- Mobile Payment Specific
    mobile_provider VARCHAR(50),                 -- APPLE_PAY, GOOGLE_PAY, etc.
    mobile_transaction_id VARCHAR(100),

    -- Timestamps
    created_at TIMESTAMP NOT NULL DEFAULT NOW(),

    -- Constraints
    CONSTRAINT pos_receipt_payments_amount CHECK (amount > 0),
    CONSTRAINT pos_receipt_payments_cash_logic CHECK (
        (payment_type != 'CASH') OR
        (amount_tendered >= amount AND change_given = amount_tendered - amount)
    )
);

-- Indexes
CREATE INDEX idx_pos_receipt_payments_receipt ON pos_receipt_payments(receipt_id);
CREATE INDEX idx_pos_receipt_payments_method ON pos_receipt_payments(payment_method_id);
CREATE INDEX idx_pos_receipt_payments_voucher ON pos_receipt_payments(voucher_serial)
    WHERE voucher_serial IS NOT NULL;

-- Comments
COMMENT ON TABLE pos_receipt_payments IS 'Payment breakdown per receipt (supports split payments)';
COMMENT ON COLUMN pos_receipt_payments.payment_type IS 'Payment type enum: CASH, CARD, VOUCHER, MOBILE';
```

---

## Immutability Enforcement

### Trigger: Prevent Receipt Modification

This trigger enforces NF525 immutability requirements. Once a receipt is created, it cannot be modified or deleted (only voided).

```sql
CREATE OR REPLACE FUNCTION prevent_receipt_modification()
RETURNS TRIGGER AS $$
BEGIN
    -- Block all DELETE operations
    IF TG_OP = 'DELETE' THEN
        RAISE EXCEPTION 'Cannot delete fiscally sealed receipt %. Use void operation instead.',
            OLD.receipt_number
            USING ERRCODE = 'integrity_constraint_violation';
    END IF;

    -- Block UPDATE operations except void
    IF TG_OP = 'UPDATE' THEN
        -- Allow ONLY void operation
        IF NEW.is_voided = true AND OLD.is_voided = false THEN
            -- Verify only void-related fields changed
            IF NEW.fiscal_hash != OLD.fiscal_hash OR
               NEW.receipt_number != OLD.receipt_number OR
               NEW.total != OLD.total OR
               NEW.subtotal != OLD.subtotal OR
               NEW.tax_amount != OLD.tax_amount OR
               NEW.chain_sequence != OLD.chain_sequence OR
               NEW.posted_at != OLD.posted_at THEN
                RAISE EXCEPTION 'Cannot modify immutable fields when voiding receipt %',
                    OLD.receipt_number
                    USING ERRCODE = 'integrity_constraint_violation';
            END IF;

            -- Void operation allowed
            RETURN NEW;
        ELSE
            -- All other updates blocked
            RAISE EXCEPTION 'Receipt % is fiscally sealed and cannot be modified. Immutable fields: fiscal_hash, receipt_number, totals, timestamp, chain_sequence.',
                OLD.receipt_number
                USING ERRCODE = 'integrity_constraint_violation';
        END IF;
    END IF;

    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

-- Attach trigger
CREATE TRIGGER enforce_receipt_immutability
    BEFORE UPDATE OR DELETE ON pos_receipts
    FOR EACH ROW
    EXECUTE FUNCTION prevent_receipt_modification();

COMMENT ON FUNCTION prevent_receipt_modification() IS 'NF525 Immutability: Prevents modification/deletion of receipts (allows only void operation)';
```

---

## Sequence Management

### Function: Get Next Receipt Number

This function generates the next receipt number for a terminal, ensuring no gaps in sequence.

```sql
CREATE OR REPLACE FUNCTION get_next_receipt_number(
    p_terminal_id UUID,
    p_tenant_prefix VARCHAR(10),
    p_company_prefix VARCHAR(10),
    p_location_prefix VARCHAR(10)
)
RETURNS TABLE(
    receipt_number VARCHAR(50),
    sequence_number INTEGER,
    year_value INTEGER
) AS $$
DECLARE
    v_terminal_code VARCHAR(10);
    v_current_year INTEGER;
    v_next_sequence INTEGER;
    v_terminal_year INTEGER;
BEGIN
    -- Get terminal details with row lock
    SELECT code, current_sequence, current_year
    INTO v_terminal_code, v_next_sequence, v_terminal_year
    FROM pos_terminals
    WHERE id = p_terminal_id
    FOR UPDATE;

    IF NOT FOUND THEN
        RAISE EXCEPTION 'Terminal % not found', p_terminal_id;
    END IF;

    -- Get current year
    v_current_year := EXTRACT(YEAR FROM NOW());

    -- Reset sequence if year changed
    IF v_terminal_year != v_current_year THEN
        v_next_sequence := 1;
        v_terminal_year := v_current_year;
    ELSE
        v_next_sequence := v_next_sequence + 1;
    END IF;

    -- Update terminal state
    UPDATE pos_terminals
    SET current_sequence = v_next_sequence,
        current_year = v_terminal_year,
        updated_at = NOW()
    WHERE id = p_terminal_id;

    -- Return formatted receipt number
    receipt_number := format(
        '%s-%s-%s-%s-%s-%s',
        p_tenant_prefix,
        p_company_prefix,
        p_location_prefix,
        v_terminal_code,
        v_terminal_year,
        lpad(v_next_sequence::TEXT, 8, '0')
    );
    sequence_number := v_next_sequence;
    year_value := v_terminal_year;

    RETURN NEXT;
END;
$$ LANGUAGE plpgsql;

COMMENT ON FUNCTION get_next_receipt_number IS 'Generate next sequential receipt number for terminal (NF525 compliant - no gaps)';
```

---

## Sample Data

### Seed Genesis Seeds for Terminals

```sql
-- Example: Create terminal with genesis seed
INSERT INTO pos_terminals (
    tenant_id,
    company_id,
    location_id,
    code,
    name,
    genesis_seed,
    current_sequence,
    current_year,
    is_active,
    activated_at
) VALUES (
    '01234567-89ab-cdef-0123-456789abcdef',  -- tenant_id
    'abcdef01-2345-6789-abcd-ef0123456789',  -- company_id
    'fedcba98-7654-3210-fedc-ba9876543210',  -- location_id
    'POS01',
    'Front Counter Terminal',
    encode(gen_random_bytes(32), 'hex'),     -- 256-bit random genesis seed
    0,                                        -- current_sequence (starts at 0)
    EXTRACT(YEAR FROM NOW())::INTEGER,       -- current_year
    true,
    NOW()
);
```

### Example Receipt Creation

```sql
BEGIN;

-- Get next receipt number
SELECT * FROM get_next_receipt_number(
    p_terminal_id := '01234567-89ab-cdef-0123-456789abcdef',
    p_tenant_prefix := 'T001',
    p_company_prefix := 'C042',
    p_location_prefix := 'L01'
);
-- Returns: T001-C042-L01-POS01-2026-00000001

-- Insert receipt (hash calculated by application service)
INSERT INTO pos_receipts (
    tenant_id,
    company_id,
    location_id,
    terminal_id,
    receipt_number,
    chain_sequence,
    receipt_year,
    fiscal_hash,
    previous_hash,
    vat_breakdown_hash,
    payment_methods_hash,
    posted_at,
    cashier_id,
    cashier_name,
    subtotal,
    tax_amount,
    total,
    currency,
    consumption_mode
) VALUES (
    '01234567-89ab-cdef-0123-456789abcdef',
    'abcdef01-2345-6789-abcd-ef0123456789',
    'fedcba98-7654-3210-fedc-ba9876543210',
    '01234567-89ab-cdef-0123-456789abcdef',
    'T001-C042-L01-POS01-2026-00000001',
    1,
    2026,
    'abc123...', -- Calculated by ReceiptHashService
    NULL,        -- First receipt - no previous hash
    'def456...',
    'ghi789...',
    NOW(),
    'cashier-user-id',
    'Marie Dupont',
    12.50,
    2.50,
    15.00,
    'EUR',
    'SUR_PLACE'
);

COMMIT;
```

---

## Performance Considerations

### Index Strategy

1. **Terminal Lookups**: Indexed by tenant/company/location for multi-tenancy
2. **Receipt Queries**: Compound index on `(terminal_id, posted_at DESC)` for chronological views
3. **Cashier Reports**: Index on `(cashier_id, posted_at DESC)` for shift reports
4. **Hash Chain Verification**: Index on `(receipt_year, chain_sequence)` for ordered traversal
5. **Offline Sync**: Partial index on `synced_at IS NULL` for pending sync queue

### Partitioning Strategy (Future)

For high-volume deployments, consider partitioning `pos_receipts` by year:

```sql
-- Example: Partition by receipt_year (PostgreSQL 10+)
CREATE TABLE pos_receipts_2026 PARTITION OF pos_receipts
    FOR VALUES FROM (2026) TO (2027);

CREATE TABLE pos_receipts_2027 PARTITION OF pos_receipts
    FOR VALUES FROM (2027) TO (2028);
```

---

## Migration Order

Execute migrations in this order to satisfy foreign key dependencies:

1. `pos_terminals` (depends on: tenants, companies, locations)
2. `pos_receipts` (depends on: pos_terminals, users)
3. `pos_receipt_lines` (depends on: pos_receipts, menu_items, products)
4. `pos_receipt_vat_details` (depends on: pos_receipts)
5. `pos_receipt_payments` (depends on: pos_receipts, payment_methods)
6. Trigger: `prevent_receipt_modification()`
7. Function: `get_next_receipt_number()`

---

## Compliance Checklist

### NF525 Requirements

- ✅ **Inalterability**: Immutability enforced via triggers
- ✅ **Security**: Hash chain with SHA-256 and genesis seed
- ✅ **Conservation**: 6-7 year retention (soft delete, no purging)
- ✅ **Archival**: All fiscal data preserved in `pos_receipts` and child tables

### Hash Chain Verification

```sql
-- Verify terminal hash chain integrity
SELECT verify_terminal_hash_chain('terminal-uuid-here');
```

(Function implementation in `ReceiptHashService.php`)

---

## Future Extensions (Phase 2)

### Offline Sync Support

Add columns to `pos_receipts`:
- `local_id` (UUID from SQLite)
- `sync_conflict_resolution` (ENUM: SERVER_WINS, CLIENT_WINS, MANUAL)

### GRANDTOTAL Events (NF525)

Create additional table for daily/monthly/yearly closing events:

```sql
CREATE TABLE pos_grandtotal_events (
    id UUID PRIMARY KEY,
    terminal_id UUID REFERENCES pos_terminals(id),
    event_type VARCHAR(20),  -- DAY, MONTH, YEAR
    period_start TIMESTAMP,
    period_end TIMESTAMP,
    total_receipts INTEGER,
    total_gross DECIMAL(12,2),
    fiscal_hash VARCHAR(64),
    -- ...
);
```

---

## Rollback Plan

If migration fails or needs rollback:

```sql
-- Drop in reverse dependency order
DROP TRIGGER IF EXISTS enforce_receipt_immutability ON pos_receipts;
DROP FUNCTION IF EXISTS prevent_receipt_modification();
DROP FUNCTION IF EXISTS get_next_receipt_number(UUID, VARCHAR, VARCHAR, VARCHAR);

DROP TABLE IF EXISTS pos_receipt_payments CASCADE;
DROP TABLE IF EXISTS pos_receipt_vat_details CASCADE;
DROP TABLE IF EXISTS pos_receipt_lines CASCADE;
DROP TABLE IF EXISTS pos_receipts CASCADE;
DROP TABLE IF EXISTS pos_terminals CASCADE;
```

---

## Verification Queries

### Check Terminal State

```sql
SELECT
    code,
    current_sequence,
    current_year,
    last_hash IS NOT NULL as has_receipts,
    is_active
FROM pos_terminals
WHERE company_id = 'your-company-id';
```

### Receipt Count per Terminal

```sql
SELECT
    t.code,
    COUNT(r.id) as total_receipts,
    SUM(r.total) as total_sales,
    MAX(r.posted_at) as last_sale
FROM pos_terminals t
LEFT JOIN pos_receipts r ON r.terminal_id = t.id
GROUP BY t.id, t.code
ORDER BY t.code;
```

### Hash Chain Continuity Check

```sql
SELECT
    receipt_number,
    chain_sequence,
    previous_hash IS NOT NULL as has_previous,
    fiscal_hash
FROM pos_receipts
WHERE terminal_id = 'terminal-uuid'
ORDER BY chain_sequence;
```

---

*Document Version: 1.0*
*Last Updated: 2026-01-08*
*Schema: PostgreSQL 16+*
*Compliance: NF525 (France)*
