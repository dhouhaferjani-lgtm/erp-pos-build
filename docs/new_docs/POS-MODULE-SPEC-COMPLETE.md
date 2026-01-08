# POS Module - Complete Specification

**Version:** 2.0  
**Goal:** Specification detailed enough for 90%+ correct implementation in one pass

---

## Table of Contents

1. [Overview](#1-overview)
2. [Database Schema](#2-database-schema)
3. [Enums & Constants](#3-enums--constants)
4. [Domain Models](#4-domain-models)
5. [Services & Business Logic](#5-services--business-logic)
6. [API Endpoints](#6-api-endpoints)
7. [Frontend Pages & Components](#7-frontend-pages--components)
8. [Workflows](#8-workflows)
9. [Hash Chain Implementation](#9-hash-chain-implementation)
10. [Reports](#10-reports)
11. [Integration Points](#11-integration-points)
12. [Testing Requirements](#12-testing-requirements)

---

## 1. Overview

### What This Module Does

The POS module handles:
- **Terminal Management**: Register, configure, and manage POS devices
- **Cashier Management**: Assign users to terminals, track shifts
- **Session Management**: Opening/closing cash registers, cash reconciliation
- **Transaction Processing**: Sales, refunds, voids with fiscal compliance
- **Cash Movements**: Cash in/out, float management
- **Reporting**: X-reports, Z-reports, sales analysis
- **Hardware Integration**: Receipt printers, cash drawers, barcode scanners

### Module Boundaries

```
┌─────────────────────────────────────────────────────────────────────┐
│                          POS MODULE                                  │
│                                                                      │
│  ┌──────────────┐  ┌──────────────┐  ┌──────────────┐              │
│  │  Terminals   │  │   Cashiers   │  │   Sessions   │              │
│  │              │  │              │  │              │              │
│  │ - Register   │  │ - Assign     │  │ - Open       │              │
│  │ - Configure  │  │ - Permissions│  │ - Close      │              │
│  │ - Status     │  │ - Shifts     │  │ - Reconcile  │              │
│  └──────────────┘  └──────────────┘  └──────────────┘              │
│                                                                      │
│  ┌──────────────┐  ┌──────────────┐  ┌──────────────┐              │
│  │ Transactions │  │ Cash Moves   │  │   Reports    │              │
│  │              │  │              │  │              │              │
│  │ - Sales      │  │ - Cash In    │  │ - X-Report   │              │
│  │ - Refunds    │  │ - Cash Out   │  │ - Z-Report   │              │
│  │ - Voids      │  │ - Float      │  │ - Sales      │              │
│  └──────────────┘  └──────────────┘  └──────────────┘              │
│                                                                      │
│  DEPENDS ON: Document, Treasury, Product, Inventory, Identity       │
└─────────────────────────────────────────────────────────────────────┘
```

### Multi-App Behavior

| Feature | IziPOS | Otospex |
|---------|--------|---------|
| Basic POS | ✅ | ✅ |
| Vehicle selection | ❌ | ✅ |
| Workshop integration | ❌ | ✅ |
| Table management | Via Tables module | ❌ |
| Batch/Expiry tracking | Via Batch module | Via Batch module |

---

## 2. Database Schema

### 2.1 pos_terminals

```sql
CREATE TABLE pos_terminals (
    id BIGSERIAL PRIMARY KEY,
    uuid UUID NOT NULL DEFAULT gen_random_uuid(),
    tenant_id BIGINT NOT NULL REFERENCES tenants(id),
    company_id BIGINT NOT NULL REFERENCES companies(id),
    location_id BIGINT REFERENCES locations(id),
    
    -- Identification
    code VARCHAR(20) NOT NULL,           -- 'POS001', 'POS002'
    name VARCHAR(100) NOT NULL,          -- 'Main Counter', 'Drive-Through'
    
    -- Device Info
    device_fingerprint VARCHAR(255),     -- Hardware identifier
    device_type VARCHAR(50),             -- 'desktop', 'tablet', 'mobile'
    device_info JSONB,                   -- OS, browser, hardware details
    
    -- Configuration
    settings JSONB NOT NULL DEFAULT '{}',
    /*
    settings structure:
    {
        "receipt": {
            "header_lines": ["Company Name", "Address"],
            "footer_lines": ["Thank you!"],
            "show_logo": true,
            "paper_width": 80
        },
        "behavior": {
            "require_customer": false,
            "allow_negative_stock": false,
            "auto_print_receipt": true,
            "allow_price_override": false,
            "max_discount_percent": 20
        },
        "hardware": {
            "printer_type": "thermal",
            "printer_connection": "usb",
            "drawer_enabled": true,
            "scanner_enabled": true
        }
    }
    */
    
    -- Status
    status VARCHAR(20) NOT NULL DEFAULT 'pending',
    activated_at TIMESTAMP WITH TIME ZONE,
    last_seen_at TIMESTAMP WITH TIME ZONE,
    
    -- Hash Chain (per-terminal)
    last_transaction_hash VARCHAR(64),
    transaction_sequence BIGINT NOT NULL DEFAULT 0,
    
    -- Audit
    created_at TIMESTAMP WITH TIME ZONE DEFAULT NOW(),
    updated_at TIMESTAMP WITH TIME ZONE DEFAULT NOW(),
    deleted_at TIMESTAMP WITH TIME ZONE,
    
    CONSTRAINT uq_terminal_tenant_code UNIQUE (tenant_id, code),
    CONSTRAINT uq_terminal_uuid UNIQUE (uuid)
);

CREATE INDEX idx_terminals_tenant_company ON pos_terminals(tenant_id, company_id);
CREATE INDEX idx_terminals_status ON pos_terminals(status) WHERE deleted_at IS NULL;
```

### 2.2 pos_cashiers

```sql
CREATE TABLE pos_cashiers (
    id BIGSERIAL PRIMARY KEY,
    uuid UUID NOT NULL DEFAULT gen_random_uuid(),
    tenant_id BIGINT NOT NULL REFERENCES tenants(id),
    company_id BIGINT NOT NULL REFERENCES companies(id),
    user_id BIGINT NOT NULL REFERENCES users(id),
    
    -- Identification
    cashier_code VARCHAR(20) NOT NULL,   -- 'CSH001'
    pin_hash VARCHAR(255),               -- For quick login
    
    -- Permissions (in addition to role-based)
    permissions JSONB NOT NULL DEFAULT '{}',
    /*
    {
        "can_void": true,
        "can_refund": true,
        "can_discount": true,
        "max_discount_percent": 10,
        "can_open_drawer": true,
        "can_price_override": false,
        "can_view_reports": false,
        "allowed_terminal_ids": [1, 2, 3] // null = all terminals
    }
    */
    
    -- Status
    is_active BOOLEAN NOT NULL DEFAULT true,
    
    -- Audit
    created_at TIMESTAMP WITH TIME ZONE DEFAULT NOW(),
    updated_at TIMESTAMP WITH TIME ZONE DEFAULT NOW(),
    deleted_at TIMESTAMP WITH TIME ZONE,
    
    CONSTRAINT uq_cashier_tenant_code UNIQUE (tenant_id, cashier_code),
    CONSTRAINT uq_cashier_tenant_user UNIQUE (tenant_id, user_id)
);

CREATE INDEX idx_cashiers_tenant_company ON pos_cashiers(tenant_id, company_id);
CREATE INDEX idx_cashiers_user ON pos_cashiers(user_id);
```

### 2.3 pos_sessions

```sql
CREATE TABLE pos_sessions (
    id BIGSERIAL PRIMARY KEY,
    uuid UUID NOT NULL DEFAULT gen_random_uuid(),
    tenant_id BIGINT NOT NULL REFERENCES tenants(id),
    company_id BIGINT NOT NULL REFERENCES companies(id),
    terminal_id BIGINT NOT NULL REFERENCES pos_terminals(id),
    cashier_id BIGINT NOT NULL REFERENCES pos_cashiers(id),
    
    -- Session Info
    session_number VARCHAR(30) NOT NULL,  -- 'POS001-2025-001'
    
    -- Opening
    opened_at TIMESTAMP WITH TIME ZONE NOT NULL,
    opening_balance DECIMAL(15,2) NOT NULL,
    opening_balance_counted DECIMAL(15,2), -- Blind count
    opening_notes TEXT,
    
    -- Closing
    closed_at TIMESTAMP WITH TIME ZONE,
    closing_balance_expected DECIMAL(15,2),
    closing_balance_counted DECIMAL(15,2), -- Blind count
    closing_difference DECIMAL(15,2),      -- counted - expected
    closing_notes TEXT,
    
    -- Totals (updated in real-time)
    total_sales DECIMAL(15,2) NOT NULL DEFAULT 0,
    total_refunds DECIMAL(15,2) NOT NULL DEFAULT 0,
    total_voids DECIMAL(15,2) NOT NULL DEFAULT 0,
    total_discounts DECIMAL(15,2) NOT NULL DEFAULT 0,
    total_cash_in DECIMAL(15,2) NOT NULL DEFAULT 0,
    total_cash_out DECIMAL(15,2) NOT NULL DEFAULT 0,
    transaction_count INT NOT NULL DEFAULT 0,
    
    -- Status
    status VARCHAR(20) NOT NULL DEFAULT 'open',
    
    -- Audit
    created_at TIMESTAMP WITH TIME ZONE DEFAULT NOW(),
    updated_at TIMESTAMP WITH TIME ZONE DEFAULT NOW(),
    
    CONSTRAINT uq_session_number UNIQUE (tenant_id, session_number)
);

CREATE INDEX idx_sessions_terminal ON pos_sessions(terminal_id);
CREATE INDEX idx_sessions_cashier ON pos_sessions(cashier_id);
CREATE INDEX idx_sessions_status ON pos_sessions(status);
CREATE INDEX idx_sessions_date ON pos_sessions(opened_at);
```

### 2.4 pos_transactions

```sql
CREATE TABLE pos_transactions (
    id BIGSERIAL PRIMARY KEY,
    uuid UUID NOT NULL DEFAULT gen_random_uuid(),
    tenant_id BIGINT NOT NULL REFERENCES tenants(id),
    company_id BIGINT NOT NULL REFERENCES companies(id),
    session_id BIGINT NOT NULL REFERENCES pos_sessions(id),
    terminal_id BIGINT NOT NULL REFERENCES pos_terminals(id),
    cashier_id BIGINT NOT NULL REFERENCES pos_cashiers(id),
    
    -- Numbering (per terminal)
    receipt_number VARCHAR(30) NOT NULL,  -- 'POS001-00001'
    sequence_number BIGINT NOT NULL,
    
    -- Type
    transaction_type VARCHAR(20) NOT NULL, -- 'sale', 'refund', 'void'
    
    -- Reference (for refunds/voids)
    original_transaction_id BIGINT REFERENCES pos_transactions(id),
    
    -- Customer (optional)
    partner_id BIGINT REFERENCES partners(id),
    
    -- Document Link (created invoice/credit note)
    document_id BIGINT REFERENCES documents(id),
    
    -- Amounts
    subtotal DECIMAL(15,2) NOT NULL,
    discount_amount DECIMAL(15,2) NOT NULL DEFAULT 0,
    discount_percent DECIMAL(5,2),
    tax_amount DECIMAL(15,2) NOT NULL DEFAULT 0,
    total_amount DECIMAL(15,2) NOT NULL,
    
    -- Status
    status VARCHAR(20) NOT NULL DEFAULT 'completed',
    
    -- Hash Chain
    previous_hash VARCHAR(64),
    transaction_hash VARCHAR(64) NOT NULL,
    
    -- Timestamps
    transaction_at TIMESTAMP WITH TIME ZONE NOT NULL,
    created_at TIMESTAMP WITH TIME ZONE DEFAULT NOW(),
    updated_at TIMESTAMP WITH TIME ZONE DEFAULT NOW(),
    
    -- Metadata
    metadata JSONB DEFAULT '{}',
    
    CONSTRAINT uq_receipt_number UNIQUE (tenant_id, receipt_number)
);

CREATE INDEX idx_transactions_session ON pos_transactions(session_id);
CREATE INDEX idx_transactions_terminal ON pos_transactions(terminal_id);
CREATE INDEX idx_transactions_date ON pos_transactions(transaction_at);
CREATE INDEX idx_transactions_type ON pos_transactions(transaction_type);
CREATE INDEX idx_transactions_hash ON pos_transactions(transaction_hash);
```

### 2.5 pos_transaction_items

```sql
CREATE TABLE pos_transaction_items (
    id BIGSERIAL PRIMARY KEY,
    transaction_id BIGINT NOT NULL REFERENCES pos_transactions(id) ON DELETE CASCADE,
    
    -- Product
    product_id BIGINT NOT NULL REFERENCES products(id),
    product_variant_id BIGINT REFERENCES product_variants(id),
    
    -- Batch (if batch tracking enabled)
    batch_id BIGINT REFERENCES product_batches(id),
    
    -- Snapshot at time of sale
    product_name VARCHAR(255) NOT NULL,
    product_sku VARCHAR(100),
    
    -- Quantities
    quantity DECIMAL(15,4) NOT NULL,
    unit_price DECIMAL(15,4) NOT NULL,
    
    -- Discounts
    discount_amount DECIMAL(15,2) NOT NULL DEFAULT 0,
    discount_percent DECIMAL(5,2),
    
    -- Tax
    tax_rate DECIMAL(5,2) NOT NULL DEFAULT 0,
    tax_amount DECIMAL(15,2) NOT NULL DEFAULT 0,
    
    -- Totals
    line_total DECIMAL(15,2) NOT NULL,
    
    -- For refund/void tracking
    original_item_id BIGINT REFERENCES pos_transaction_items(id),
    
    created_at TIMESTAMP WITH TIME ZONE DEFAULT NOW()
);

CREATE INDEX idx_transaction_items_transaction ON pos_transaction_items(transaction_id);
CREATE INDEX idx_transaction_items_product ON pos_transaction_items(product_id);
CREATE INDEX idx_transaction_items_batch ON pos_transaction_items(batch_id);
```

### 2.6 pos_transaction_payments

```sql
CREATE TABLE pos_transaction_payments (
    id BIGSERIAL PRIMARY KEY,
    transaction_id BIGINT NOT NULL REFERENCES pos_transactions(id) ON DELETE CASCADE,
    
    -- Payment Method
    payment_method VARCHAR(50) NOT NULL, -- 'cash', 'card', 'mobile', 'voucher', 'credit'
    
    -- Amounts
    amount DECIMAL(15,2) NOT NULL,
    tendered DECIMAL(15,2),              -- For cash (what customer gave)
    change_amount DECIMAL(15,2),         -- For cash
    
    -- Reference
    reference VARCHAR(100),              -- Card auth code, voucher number, etc.
    
    -- Card Details (if applicable)
    card_type VARCHAR(20),               -- 'visa', 'mastercard'
    card_last_four VARCHAR(4),
    
    -- Treasury Link
    payment_id BIGINT REFERENCES payments(id),
    
    created_at TIMESTAMP WITH TIME ZONE DEFAULT NOW()
);

CREATE INDEX idx_payments_transaction ON pos_transaction_payments(transaction_id);
CREATE INDEX idx_payments_method ON pos_transaction_payments(payment_method);
```

### 2.7 pos_cash_movements

```sql
CREATE TABLE pos_cash_movements (
    id BIGSERIAL PRIMARY KEY,
    uuid UUID NOT NULL DEFAULT gen_random_uuid(),
    tenant_id BIGINT NOT NULL REFERENCES tenants(id),
    session_id BIGINT NOT NULL REFERENCES pos_sessions(id),
    cashier_id BIGINT NOT NULL REFERENCES pos_cashiers(id),
    
    -- Type
    movement_type VARCHAR(20) NOT NULL,  -- 'cash_in', 'cash_out', 'float'
    
    -- Amount
    amount DECIMAL(15,2) NOT NULL,
    
    -- Reason
    reason VARCHAR(100) NOT NULL,
    notes TEXT,
    
    -- Approval (for large amounts)
    approved_by BIGINT REFERENCES users(id),
    
    -- Treasury Link
    treasury_movement_id BIGINT,
    
    created_at TIMESTAMP WITH TIME ZONE DEFAULT NOW()
);

CREATE INDEX idx_cash_movements_session ON pos_cash_movements(session_id);
CREATE INDEX idx_cash_movements_type ON pos_cash_movements(movement_type);
```

### 2.8 pos_reports

```sql
CREATE TABLE pos_reports (
    id BIGSERIAL PRIMARY KEY,
    uuid UUID NOT NULL DEFAULT gen_random_uuid(),
    tenant_id BIGINT NOT NULL REFERENCES tenants(id),
    company_id BIGINT NOT NULL REFERENCES companies(id),
    terminal_id BIGINT NOT NULL REFERENCES pos_terminals(id),
    session_id BIGINT REFERENCES pos_sessions(id),
    
    -- Report Type
    report_type VARCHAR(20) NOT NULL,    -- 'x_report', 'z_report'
    report_number VARCHAR(30) NOT NULL,  -- 'X-POS001-2025-001'
    
    -- Period
    period_start TIMESTAMP WITH TIME ZONE NOT NULL,
    period_end TIMESTAMP WITH TIME ZONE NOT NULL,
    
    -- Totals
    report_data JSONB NOT NULL,
    /*
    {
        "sales": {
            "count": 45,
            "gross": 5420.00,
            "discounts": 120.00,
            "net": 5300.00,
            "tax": 954.00,
            "total": 6254.00
        },
        "refunds": {
            "count": 2,
            "total": 85.00
        },
        "voids": {
            "count": 1,
            "total": 45.00
        },
        "payments": {
            "cash": 3500.00,
            "card": 2669.00,
            "mobile": 85.00
        },
        "cash_movements": {
            "opening": 200.00,
            "in": 50.00,
            "out": 100.00,
            "expected": 3650.00,
            "counted": 3640.00,
            "difference": -10.00
        },
        "by_category": [...],
        "by_hour": [...],
        "by_cashier": [...]
    }
    */
    
    -- Hash (for Z-reports, ties to transaction chain)
    last_transaction_hash VARCHAR(64),
    report_hash VARCHAR(64),
    
    -- Generated by
    generated_by BIGINT NOT NULL REFERENCES users(id),
    
    created_at TIMESTAMP WITH TIME ZONE DEFAULT NOW(),
    
    CONSTRAINT uq_report_number UNIQUE (tenant_id, report_number)
);

CREATE INDEX idx_reports_terminal ON pos_reports(terminal_id);
CREATE INDEX idx_reports_type ON pos_reports(report_type);
CREATE INDEX idx_reports_date ON pos_reports(created_at);
```

---

## 3. Enums & Constants

```php
// app/Modules/POS/Domain/Enums/TerminalStatus.php
enum TerminalStatus: string
{
    case PENDING = 'pending';       // Registered, awaiting approval
    case ACTIVE = 'active';         // Approved and operational
    case SUSPENDED = 'suspended';   // Temporarily disabled
    case REVOKED = 'revoked';       // Permanently disabled
}

// app/Modules/POS/Domain/Enums/SessionStatus.php
enum SessionStatus: string
{
    case OPEN = 'open';
    case CLOSED = 'closed';
    case SUSPENDED = 'suspended';   // Mid-shift pause
}

// app/Modules/POS/Domain/Enums/TransactionType.php
enum TransactionType: string
{
    case SALE = 'sale';
    case REFUND = 'refund';
    case VOID = 'void';
}

// app/Modules/POS/Domain/Enums/TransactionStatus.php
enum TransactionStatus: string
{
    case DRAFT = 'draft';           // Being built
    case COMPLETED = 'completed';   // Finalized
    case VOIDED = 'voided';         // Cancelled
}

// app/Modules/POS/Domain/Enums/PaymentMethod.php
enum PaymentMethod: string
{
    case CASH = 'cash';
    case CARD = 'card';
    case MOBILE = 'mobile';         // Mobile money, Apple Pay, etc.
    case VOUCHER = 'voucher';
    case CREDIT = 'credit';         // On account
    case CHEQUE = 'cheque';
}

// app/Modules/POS/Domain/Enums/CashMovementType.php
enum CashMovementType: string
{
    case CASH_IN = 'cash_in';       // Adding cash
    case CASH_OUT = 'cash_out';     // Removing cash
    case FLOAT = 'float';           // Initial float
}

// app/Modules/POS/Domain/Enums/ReportType.php
enum ReportType: string
{
    case X_REPORT = 'x_report';     // Read-only, can generate multiple
    case Z_REPORT = 'z_report';     // Closes period, resets counters
}
```

---

## 4. Domain Models

### 4.1 Terminal Model

```php
// app/Modules/POS/Domain/Terminal.php
class Terminal extends Model
{
    use HasUuid, BelongsToTenant, BelongsToCompany, SoftDeletes;
    
    protected $table = 'pos_terminals';
    
    protected $fillable = [
        'company_id',
        'location_id',
        'code',
        'name',
        'device_fingerprint',
        'device_type',
        'device_info',
        'settings',
        'status',
        'activated_at',
        'last_seen_at',
    ];
    
    protected $casts = [
        'device_info' => 'array',
        'settings' => 'array',
        'status' => TerminalStatus::class,
        'activated_at' => 'datetime',
        'last_seen_at' => 'datetime',
    ];
    
    // Relationships
    public function company(): BelongsTo
    public function location(): BelongsTo
    public function sessions(): HasMany
    public function transactions(): HasMany
    public function cashiers(): BelongsToMany // via allowed_terminal_ids
    
    // Helpers
    public function isActive(): bool
    public function canAcceptTransactions(): bool
    public function getNextSequenceNumber(): int
    public function getNextReceiptNumber(): string
}
```

### 4.2 Cashier Model

```php
// app/Modules/POS/Domain/Cashier.php
class Cashier extends Model
{
    use HasUuid, BelongsToTenant, BelongsToCompany, SoftDeletes;
    
    protected $table = 'pos_cashiers';
    
    protected $fillable = [
        'company_id',
        'user_id',
        'cashier_code',
        'pin_hash',
        'permissions',
        'is_active',
    ];
    
    protected $casts = [
        'permissions' => 'array',
        'is_active' => 'boolean',
    ];
    
    protected $hidden = ['pin_hash'];
    
    // Relationships
    public function user(): BelongsTo
    public function sessions(): HasMany
    public function transactions(): HasMany
    
    // Permission Helpers
    public function canVoid(): bool
    public function canRefund(): bool
    public function canDiscount(): bool
    public function getMaxDiscountPercent(): float
    public function canAccessTerminal(Terminal $terminal): bool
    public function verifyPin(string $pin): bool
}
```

### 4.3 Session Model

```php
// app/Modules/POS/Domain/Session.php
class Session extends Model
{
    use HasUuid, BelongsToTenant, BelongsToCompany;
    
    protected $table = 'pos_sessions';
    
    protected $fillable = [
        'company_id',
        'terminal_id',
        'cashier_id',
        'session_number',
        'opened_at',
        'opening_balance',
        'opening_balance_counted',
        'opening_notes',
        'closed_at',
        'closing_balance_expected',
        'closing_balance_counted',
        'closing_difference',
        'closing_notes',
        'total_sales',
        'total_refunds',
        'total_voids',
        'total_discounts',
        'total_cash_in',
        'total_cash_out',
        'transaction_count',
        'status',
    ];
    
    protected $casts = [
        'status' => SessionStatus::class,
        'opened_at' => 'datetime',
        'closed_at' => 'datetime',
        'opening_balance' => 'decimal:2',
        'opening_balance_counted' => 'decimal:2',
        'closing_balance_expected' => 'decimal:2',
        'closing_balance_counted' => 'decimal:2',
        'closing_difference' => 'decimal:2',
        'total_sales' => 'decimal:2',
        'total_refunds' => 'decimal:2',
        'total_voids' => 'decimal:2',
        'total_discounts' => 'decimal:2',
        'total_cash_in' => 'decimal:2',
        'total_cash_out' => 'decimal:2',
    ];
    
    // Relationships
    public function terminal(): BelongsTo
    public function cashier(): BelongsTo
    public function transactions(): HasMany
    public function cashMovements(): HasMany
    
    // Calculated
    public function getExpectedCashBalance(): float
    public function isOpen(): bool
}
```

### 4.4 Transaction Model

```php
// app/Modules/POS/Domain/Transaction.php
class Transaction extends Model
{
    use HasUuid, BelongsToTenant, BelongsToCompany;
    
    protected $table = 'pos_transactions';
    
    protected $fillable = [
        'company_id',
        'session_id',
        'terminal_id',
        'cashier_id',
        'receipt_number',
        'sequence_number',
        'transaction_type',
        'original_transaction_id',
        'partner_id',
        'document_id',
        'subtotal',
        'discount_amount',
        'discount_percent',
        'tax_amount',
        'total_amount',
        'status',
        'previous_hash',
        'transaction_hash',
        'transaction_at',
        'metadata',
    ];
    
    protected $casts = [
        'transaction_type' => TransactionType::class,
        'status' => TransactionStatus::class,
        'subtotal' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'discount_percent' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'total_amount' => 'decimal:2',
        'transaction_at' => 'datetime',
        'metadata' => 'array',
    ];
    
    // Relationships
    public function session(): BelongsTo
    public function terminal(): BelongsTo
    public function cashier(): BelongsTo
    public function partner(): BelongsTo
    public function document(): BelongsTo
    public function originalTransaction(): BelongsTo
    public function items(): HasMany
    public function payments(): HasMany
    
    // Chain
    public function verifyHash(): bool
}
```

---

## 5. Services & Business Logic

### 5.1 TerminalService

```php
// app/Modules/POS/Application/Services/TerminalService.php
class TerminalService
{
    public function register(RegisterTerminalDTO $dto): Terminal
    {
        // 1. Validate company exists
        // 2. Generate unique code (POS001, POS002, etc.)
        // 3. Create terminal with PENDING status
        // 4. Log registration event
        // Returns: Terminal (status: pending)
    }
    
    public function activate(Terminal $terminal, ActivateTerminalDTO $dto): Terminal
    {
        // 1. Verify terminal is PENDING
        // 2. Store device fingerprint
        // 3. Set status to ACTIVE
        // 4. Set activated_at
        // 5. Initialize transaction_sequence to 0
        // Returns: Terminal (status: active)
    }
    
    public function suspend(Terminal $terminal, string $reason): Terminal
    {
        // 1. Verify no open sessions
        // 2. Set status to SUSPENDED
        // 3. Log suspension with reason
        // Returns: Terminal (status: suspended)
    }
    
    public function revoke(Terminal $terminal, string $reason): Terminal
    {
        // 1. Verify no open sessions
        // 2. Set status to REVOKED
        // 3. Log revocation
        // IRREVERSIBLE
        // Returns: Terminal (status: revoked)
    }
    
    public function updateSettings(Terminal $terminal, array $settings): Terminal
    {
        // Merge with existing settings
        // Validate settings structure
        // Returns: Updated terminal
    }
    
    public function heartbeat(Terminal $terminal): void
    {
        // Update last_seen_at
        // Called periodically from client
    }
}
```

### 5.2 CashierService

```php
// app/Modules/POS/Application/Services/CashierService.php
class CashierService
{
    public function create(CreateCashierDTO $dto): Cashier
    {
        // 1. Verify user exists and is in same company
        // 2. Generate cashier code
        // 3. Hash PIN if provided
        // 4. Create cashier with default permissions
        // Returns: Cashier
    }
    
    public function updatePermissions(Cashier $cashier, array $permissions): Cashier
    {
        // Validate permission structure
        // Merge with defaults
        // Returns: Updated cashier
    }
    
    public function setPin(Cashier $cashier, string $pin): void
    {
        // Hash PIN with bcrypt
        // Store hash
    }
    
    public function verifyPin(Cashier $cashier, string $pin): bool
    {
        // Verify against stored hash
        // Returns: bool
    }
    
    public function assignToTerminals(Cashier $cashier, array $terminalIds): Cashier
    {
        // Update permissions.allowed_terminal_ids
        // Validate terminals exist and belong to same company
        // Returns: Updated cashier
    }
    
    public function deactivate(Cashier $cashier): Cashier
    {
        // Verify no open sessions
        // Set is_active = false
        // Returns: Updated cashier
    }
}
```

### 5.3 SessionService

```php
// app/Modules/POS/Application/Services/SessionService.php
class SessionService
{
    public function open(OpenSessionDTO $dto): Session
    {
        // TRANSACTION REQUIRED
        
        // 1. Verify terminal is ACTIVE
        // 2. Verify cashier is active and can access terminal
        // 3. Verify no open session for this terminal
        // 4. Generate session number: {TERMINAL_CODE}-{YEAR}-{SEQUENCE}
        // 5. Create session with opening balance
        // 6. Emit SessionOpened event
        
        // Returns: Session (status: open)
    }
    
    public function recordCount(Session $session, RecordCountDTO $dto): Session
    {
        // For blind counting
        // If opening: set opening_balance_counted
        // If closing: set closing_balance_counted
        
        // Returns: Updated session
    }
    
    public function close(Session $session, CloseSessionDTO $dto): Session
    {
        // TRANSACTION REQUIRED
        
        // 1. Verify session is OPEN
        // 2. Calculate expected cash balance
        // 3. Record counted balance (blind count)
        // 4. Calculate difference
        // 5. Set status to CLOSED
        // 6. Set closed_at
        // 7. Emit SessionClosed event
        
        // Returns: Session (status: closed)
    }
    
    public function suspend(Session $session): Session
    {
        // For mid-shift breaks
        // Returns: Session (status: suspended)
    }
    
    public function resume(Session $session, Cashier $cashier): Session
    {
        // Resume suspended session
        // Can be different cashier (shift handover)
        // Returns: Session (status: open)
    }
    
    public function getOpenSession(Terminal $terminal): ?Session
    {
        // Find open session for terminal
        // Returns: Session or null
    }
}
```

### 5.4 TransactionService

```php
// app/Modules/POS/Application/Services/TransactionService.php
class TransactionService
{
    public function createSale(CreateSaleDTO $dto): Transaction
    {
        // TRANSACTION REQUIRED with PESSIMISTIC LOCKING
        
        // 1. Verify session is open
        // 2. Verify cashier can transact
        // 3. Lock terminal for sequence number
        // 4. Get next sequence number
        // 5. Generate receipt number: {TERMINAL_CODE}-{SEQUENCE:05d}
        // 6. Validate items (stock, prices, batches)
        // 7. Calculate totals, taxes, discounts
        // 8. Create transaction
        // 9. Create transaction items
        // 10. Create transaction payments
        // 11. Calculate and store hash
        // 12. Update terminal sequence
        // 13. Create Document (Invoice) via Document module
        // 14. Reserve/deduct inventory
        // 15. Update session totals
        // 16. Emit TransactionCompleted event
        
        // Returns: Transaction with items, payments, document
    }
    
    public function createRefund(CreateRefundDTO $dto): Transaction
    {
        // TRANSACTION REQUIRED with PESSIMISTIC LOCKING
        
        // 1. Load original transaction
        // 2. Verify original can be refunded
        // 3. Verify cashier can refund
        // 4. Create refund transaction (negative amounts)
        // 5. Create Document (Credit Note)
        // 6. Return inventory
        // 7. Process refund payment
        // 8. Update session totals
        // 9. Calculate hash (chains from original)
        
        // Returns: Transaction (type: refund)
    }
    
    public function voidTransaction(Transaction $transaction, string $reason): Transaction
    {
        // TRANSACTION REQUIRED
        
        // 1. Verify transaction can be voided (same session, within time limit)
        // 2. Verify cashier can void
        // 3. Create void transaction
        // 4. Void associated document
        // 5. Return inventory
        // 6. Update session totals
        // 7. Calculate hash
        
        // Returns: Transaction (type: void)
    }
    
    private function calculateHash(Transaction $transaction, ?string $previousHash): string
    {
        $data = json_encode([
            'receipt_number' => $transaction->receipt_number,
            'sequence' => $transaction->sequence_number,
            'type' => $transaction->transaction_type->value,
            'total' => $transaction->total_amount,
            'timestamp' => $transaction->transaction_at->toIso8601String(),
            'previous_hash' => $previousHash,
        ]);
        
        return hash('sha256', $data);
    }
}
```

### 5.5 CashMovementService

```php
// app/Modules/POS/Application/Services/CashMovementService.php
class CashMovementService
{
    public function recordCashIn(Session $session, CashInDTO $dto): CashMovement
    {
        // 1. Create cash movement
        // 2. Update session total_cash_in
        // 3. Create Treasury entry
        // Returns: CashMovement
    }
    
    public function recordCashOut(Session $session, CashOutDTO $dto): CashMovement
    {
        // 1. Verify sufficient cash in drawer
        // 2. Check if approval required (above threshold)
        // 3. Create cash movement
        // 4. Update session total_cash_out
        // 5. Create Treasury entry
        // Returns: CashMovement
    }
    
    public function setFloat(Session $session, float $amount): CashMovement
    {
        // Initial cash float
        // Returns: CashMovement
    }
}
```

### 5.6 ReportService

```php
// app/Modules/POS/Application/Services/ReportService.php
class ReportService
{
    public function generateXReport(Terminal $terminal, ?Session $session = null): Report
    {
        // X-Report: Read-only snapshot, can generate multiple times
        
        // 1. Gather transactions for period
        // 2. Calculate totals by category
        // 3. Calculate payment method breakdown
        // 4. Calculate hourly breakdown
        // 5. Store report
        
        // Returns: Report (type: x_report)
    }
    
    public function generateZReport(Terminal $terminal, Session $session): Report
    {
        // Z-Report: Closes fiscal period, resets counters
        // REQUIRES session to be closed first
        
        // 1. Verify session is closed
        // 2. Verify no Z-report exists for this session
        // 3. Gather all data
        // 4. Calculate comprehensive totals
        // 5. Get last transaction hash
        // 6. Calculate report hash
        // 7. Store report (immutable)
        // 8. Emit ZReportGenerated event
        
        // Returns: Report (type: z_report)
    }
    
    public function getSalesReport(SalesReportDTO $dto): array
    {
        // Flexible sales reporting
        // Group by: day, category, product, cashier, terminal
        // Returns: array of report data
    }
}
```

---

## 6. API Endpoints

### 6.1 Terminal Endpoints

```
POST   /api/pos/terminals              Create/register terminal
GET    /api/pos/terminals              List terminals
GET    /api/pos/terminals/{id}         Get terminal details
PUT    /api/pos/terminals/{id}         Update terminal
POST   /api/pos/terminals/{id}/activate    Activate terminal
POST   /api/pos/terminals/{id}/suspend     Suspend terminal
POST   /api/pos/terminals/{id}/revoke      Revoke terminal
POST   /api/pos/terminals/{id}/heartbeat   Terminal heartbeat
```

### 6.2 Cashier Endpoints

```
POST   /api/pos/cashiers               Create cashier
GET    /api/pos/cashiers               List cashiers
GET    /api/pos/cashiers/{id}          Get cashier details
PUT    /api/pos/cashiers/{id}          Update cashier
PUT    /api/pos/cashiers/{id}/permissions  Update permissions
POST   /api/pos/cashiers/{id}/pin      Set PIN
POST   /api/pos/cashiers/{id}/verify-pin   Verify PIN
DELETE /api/pos/cashiers/{id}          Deactivate cashier
```

### 6.3 Session Endpoints

```
POST   /api/pos/sessions               Open session
GET    /api/pos/sessions               List sessions
GET    /api/pos/sessions/{id}          Get session details
POST   /api/pos/sessions/{id}/count    Record cash count
POST   /api/pos/sessions/{id}/close    Close session
POST   /api/pos/sessions/{id}/suspend  Suspend session
POST   /api/pos/sessions/{id}/resume   Resume session
GET    /api/pos/terminals/{id}/current-session  Get current open session
```

### 6.4 Transaction Endpoints

```
POST   /api/pos/transactions           Create sale
GET    /api/pos/transactions           List transactions
GET    /api/pos/transactions/{id}      Get transaction details
POST   /api/pos/transactions/{id}/refund   Create refund
POST   /api/pos/transactions/{id}/void     Void transaction
GET    /api/pos/sessions/{id}/transactions List session transactions
```

### 6.5 Cash Movement Endpoints

```
POST   /api/pos/sessions/{id}/cash-in      Record cash in
POST   /api/pos/sessions/{id}/cash-out     Record cash out
GET    /api/pos/sessions/{id}/cash-movements   List cash movements
```

### 6.6 Report Endpoints

```
POST   /api/pos/terminals/{id}/x-report    Generate X-report
POST   /api/pos/sessions/{id}/z-report     Generate Z-report
GET    /api/pos/reports                    List reports
GET    /api/pos/reports/{id}               Get report details
GET    /api/pos/reports/sales              Sales report
```

---

## 7. Frontend Pages & Components

### 7.1 Pages to Create

| Page | Route | Description |
|------|-------|-------------|
| TerminalListPage | /pos/terminals | List all terminals |
| TerminalDetailPage | /pos/terminals/:id | Terminal config & status |
| TerminalCreatePage | /pos/terminals/create | Register new terminal |
| CashierListPage | /pos/cashiers | List all cashiers |
| CashierDetailPage | /pos/cashiers/:id | Cashier permissions |
| CashierCreatePage | /pos/cashiers/create | Create new cashier |
| SessionListPage | /pos/sessions | Session history |
| SessionDetailPage | /pos/sessions/:id | Session details & transactions |
| POSPage | /pos/sell | Main POS interface |
| ReportListPage | /pos/reports | Report history |
| XReportPage | /pos/reports/x | Generate/view X-report |
| ZReportPage | /pos/reports/z | Generate/view Z-report |

### 7.2 Components to Create

```
components/pos/
├── terminal/
│   ├── TerminalCard.tsx
│   ├── TerminalStatus.tsx
│   ├── TerminalSettingsForm.tsx
│   └── TerminalList.tsx
├── cashier/
│   ├── CashierCard.tsx
│   ├── CashierPermissionForm.tsx
│   ├── PinPad.tsx
│   └── CashierList.tsx
├── session/
│   ├── OpenSessionDialog.tsx
│   ├── CloseSessionDialog.tsx
│   ├── CashCountForm.tsx
│   ├── SessionSummary.tsx
│   └── SessionList.tsx
├── transaction/
│   ├── POSKeypad.tsx
│   ├── ProductSearch.tsx
│   ├── CartItem.tsx
│   ├── Cart.tsx
│   ├── PaymentPanel.tsx
│   ├── SplitPaymentDialog.tsx
│   ├── DiscountDialog.tsx
│   ├── RefundDialog.tsx
│   ├── Receipt.tsx
│   └── TransactionList.tsx
├── cash/
│   ├── CashInDialog.tsx
│   ├── CashOutDialog.tsx
│   └── CashMovementList.tsx
├── reports/
│   ├── XReportView.tsx
│   ├── ZReportView.tsx
│   └── SalesChart.tsx
└── shared/
    ├── NumericInput.tsx
    ├── BarcodeScanner.tsx
    └── ReceiptPrinter.tsx
```

### 7.3 Navigation Entry

```typescript
// Add to sidebar navigation
{
  name: 'POS',
  icon: ShoppingCart,
  children: [
    { name: 'Sell', href: '/pos/sell', permission: 'pos.sell' },
    { name: 'Terminals', href: '/pos/terminals', permission: 'pos.terminals.view' },
    { name: 'Cashiers', href: '/pos/cashiers', permission: 'pos.cashiers.view' },
    { name: 'Sessions', href: '/pos/sessions', permission: 'pos.sessions.view' },
    { name: 'Reports', href: '/pos/reports', permission: 'pos.reports.view' },
  ],
}
```

### 7.4 TypeScript Types

```typescript
// types/pos.ts

// Enums
type TerminalStatus = 'pending' | 'active' | 'suspended' | 'revoked';
type SessionStatus = 'open' | 'closed' | 'suspended';
type TransactionType = 'sale' | 'refund' | 'void';
type TransactionStatus = 'draft' | 'completed' | 'voided';
type PaymentMethod = 'cash' | 'card' | 'mobile' | 'voucher' | 'credit' | 'cheque';
type CashMovementType = 'cash_in' | 'cash_out' | 'float';
type ReportType = 'x_report' | 'z_report';

// Models
interface Terminal {
  id: number;
  uuid: string;
  code: string;
  name: string;
  device_type: string | null;
  device_info: Record<string, any> | null;
  settings: TerminalSettings;
  status: TerminalStatus;
  activated_at: string | null;
  last_seen_at: string | null;
  transaction_sequence: number;
  created_at: string;
  updated_at: string;
}

interface TerminalSettings {
  receipt: {
    header_lines: string[];
    footer_lines: string[];
    show_logo: boolean;
    paper_width: number;
  };
  behavior: {
    require_customer: boolean;
    allow_negative_stock: boolean;
    auto_print_receipt: boolean;
    allow_price_override: boolean;
    max_discount_percent: number;
  };
  hardware: {
    printer_type: string;
    printer_connection: string;
    drawer_enabled: boolean;
    scanner_enabled: boolean;
  };
}

interface Cashier {
  id: number;
  uuid: string;
  user_id: number;
  user: User;
  cashier_code: string;
  permissions: CashierPermissions;
  is_active: boolean;
  created_at: string;
}

interface CashierPermissions {
  can_void: boolean;
  can_refund: boolean;
  can_discount: boolean;
  max_discount_percent: number;
  can_open_drawer: boolean;
  can_price_override: boolean;
  can_view_reports: boolean;
  allowed_terminal_ids: number[] | null;
}

interface Session {
  id: number;
  uuid: string;
  terminal_id: number;
  terminal: Terminal;
  cashier_id: number;
  cashier: Cashier;
  session_number: string;
  opened_at: string;
  opening_balance: string; // decimal
  opening_balance_counted: string | null;
  closed_at: string | null;
  closing_balance_expected: string | null;
  closing_balance_counted: string | null;
  closing_difference: string | null;
  total_sales: string;
  total_refunds: string;
  total_voids: string;
  total_discounts: string;
  total_cash_in: string;
  total_cash_out: string;
  transaction_count: number;
  status: SessionStatus;
}

interface Transaction {
  id: number;
  uuid: string;
  session_id: number;
  terminal_id: number;
  cashier_id: number;
  receipt_number: string;
  sequence_number: number;
  transaction_type: TransactionType;
  original_transaction_id: number | null;
  partner_id: number | null;
  partner: Partner | null;
  document_id: number | null;
  subtotal: string;
  discount_amount: string;
  discount_percent: string | null;
  tax_amount: string;
  total_amount: string;
  status: TransactionStatus;
  transaction_at: string;
  items: TransactionItem[];
  payments: TransactionPayment[];
}

interface TransactionItem {
  id: number;
  product_id: number;
  product_variant_id: number | null;
  batch_id: number | null;
  product_name: string;
  product_sku: string | null;
  quantity: string;
  unit_price: string;
  discount_amount: string;
  discount_percent: string | null;
  tax_rate: string;
  tax_amount: string;
  line_total: string;
}

interface TransactionPayment {
  id: number;
  payment_method: PaymentMethod;
  amount: string;
  tendered: string | null;
  change_amount: string | null;
  reference: string | null;
  card_type: string | null;
  card_last_four: string | null;
}

interface CashMovement {
  id: number;
  uuid: string;
  session_id: number;
  cashier_id: number;
  movement_type: CashMovementType;
  amount: string;
  reason: string;
  notes: string | null;
  approved_by: number | null;
  created_at: string;
}

interface Report {
  id: number;
  uuid: string;
  terminal_id: number;
  session_id: number | null;
  report_type: ReportType;
  report_number: string;
  period_start: string;
  period_end: string;
  report_data: ReportData;
  created_at: string;
}

interface ReportData {
  sales: {
    count: number;
    gross: number;
    discounts: number;
    net: number;
    tax: number;
    total: number;
  };
  refunds: {
    count: number;
    total: number;
  };
  voids: {
    count: number;
    total: number;
  };
  payments: Record<PaymentMethod, number>;
  cash_movements: {
    opening: number;
    in: number;
    out: number;
    expected: number;
    counted: number;
    difference: number;
  };
}

// DTOs
interface CreateTerminalDto {
  name: string;
  location_id?: number;
  settings?: Partial<TerminalSettings>;
}

interface CreateCashierDto {
  user_id: number;
  pin?: string;
  permissions?: Partial<CashierPermissions>;
}

interface OpenSessionDto {
  terminal_id: number;
  opening_balance: number;
  opening_notes?: string;
}

interface CloseSessionDto {
  closing_balance_counted: number;
  closing_notes?: string;
}

interface CreateSaleDto {
  session_id: number;
  partner_id?: number;
  items: CreateSaleItemDto[];
  payments: CreatePaymentDto[];
  discount_amount?: number;
  discount_percent?: number;
}

interface CreateSaleItemDto {
  product_id: number;
  product_variant_id?: number;
  batch_id?: number;
  quantity: number;
  unit_price?: number; // Override
  discount_amount?: number;
  discount_percent?: number;
}

interface CreatePaymentDto {
  payment_method: PaymentMethod;
  amount: number;
  tendered?: number;
  reference?: string;
}

interface CreateRefundDto {
  original_transaction_id: number;
  items: RefundItemDto[];
  reason: string;
  payment_method: PaymentMethod;
}

interface RefundItemDto {
  original_item_id: number;
  quantity: number;
}

interface CashMovementDto {
  amount: number;
  reason: string;
  notes?: string;
}
```

---

## 8. Workflows

### 8.1 Terminal Registration Flow

```
1. Admin creates terminal (name, location)
   → Terminal created with status: PENDING
   → Code auto-generated: POS001

2. Device connects with registration request
   → Sends device fingerprint, type, info
   
3. Admin reviews and approves
   → API: POST /terminals/{id}/activate
   → Status changes to: ACTIVE
   → Terminal can now accept sessions

4. If issues, admin can:
   → Suspend: Temporarily disable
   → Revoke: Permanently disable (cannot undo)
```

### 8.2 Session Flow

```
1. OPEN SESSION
   - Cashier selects terminal
   - System verifies: terminal active, no open session
   - Cashier enters opening cash (blind count optional)
   - Session created: status = open
   
2. TRANSACT
   - Process sales, refunds, voids
   - Cash in/out as needed
   - Session totals updated in real-time

3. CLOSE SESSION
   - Cashier initiates close
   - System calculates expected cash
   - Cashier counts and enters actual cash (blind)
   - Difference calculated
   - Session closed: status = closed
   - X-Report generated automatically

4. Z-REPORT (optional, for fiscal compliance)
   - After session close
   - Closes fiscal period
   - Cannot be undone
```

### 8.3 Sale Transaction Flow

```
1. BUILD CART
   - Scan/search products
   - Add to cart with quantities
   - System checks stock availability
   - Apply line-item discounts if needed

2. APPLY DISCOUNTS
   - Overall discount (% or fixed)
   - System checks cashier permission
   - Recalculate totals

3. SELECT CUSTOMER (optional)
   - Quick search or create
   - Links transaction to partner

4. PAYMENT
   - Select payment method(s)
   - For cash: enter tendered, calculate change
   - For card: integrate with terminal (future)
   - Split payment: multiple methods

5. COMPLETE
   - Generate receipt number (hash chain)
   - Create invoice document
   - Reserve/deduct inventory
   - Print receipt
   - Open cash drawer (if cash payment)
   - Update session totals
```

### 8.4 Refund Flow

```
1. FIND ORIGINAL
   - Search by receipt number
   - Or from transaction list
   - Load original transaction

2. SELECT ITEMS
   - Choose items to refund
   - Enter quantities (cannot exceed original)
   - System validates

3. PROCESS
   - Verify cashier can refund
   - Create refund transaction
   - Create credit note document
   - Return inventory
   - Process refund payment

4. COMPLETE
   - Generate receipt (chains to original)
   - Print refund receipt
   - Update session totals
```

---

## 9. Hash Chain Implementation

### 9.1 Hash Calculation

```php
class HashChainService
{
    public function calculateTransactionHash(Transaction $transaction, ?string $previousHash): string
    {
        // Create deterministic data payload
        $payload = [
            'terminal_code' => $transaction->terminal->code,
            'receipt_number' => $transaction->receipt_number,
            'sequence' => $transaction->sequence_number,
            'type' => $transaction->transaction_type->value,
            'subtotal' => $transaction->subtotal,
            'discount' => $transaction->discount_amount,
            'tax' => $transaction->tax_amount,
            'total' => $transaction->total_amount,
            'timestamp' => $transaction->transaction_at->toIso8601String(),
            'previous_hash' => $previousHash ?? 'GENESIS',
        ];
        
        // Sort keys for consistency
        ksort($payload);
        
        // Calculate hash
        return hash('sha256', json_encode($payload));
    }
    
    public function verifyChain(Terminal $terminal): ChainVerificationResult
    {
        $transactions = $terminal->transactions()
            ->orderBy('sequence_number')
            ->get();
        
        $previousHash = null;
        $brokenAt = null;
        
        foreach ($transactions as $transaction) {
            $expectedHash = $this->calculateTransactionHash($transaction, $previousHash);
            
            if ($expectedHash !== $transaction->transaction_hash) {
                $brokenAt = $transaction->receipt_number;
                break;
            }
            
            $previousHash = $transaction->transaction_hash;
        }
        
        return new ChainVerificationResult(
            isValid: $brokenAt === null,
            transactionCount: $transactions->count(),
            brokenAtReceipt: $brokenAt
        );
    }
}
```

### 9.2 Key Points

- Each terminal maintains its own independent hash chain
- Genesis transaction has previous_hash = 'GENESIS'
- Hash includes: receipt number, sequence, amounts, timestamp, previous hash
- Z-Report captures and locks the chain at that point
- Refunds chain from the original transaction they reference
- Voids chain normally in sequence

---

## 10. Reports

### 10.1 X-Report Content

```
================================
         X-REPORT
================================
Terminal: POS001 - Main Counter
Report #: X-POS001-2025-001
Generated: 2025-12-30 14:30:00
Period: 2025-12-30 08:00 - 14:30
--------------------------------

SALES SUMMARY
Transactions: 45
Gross Sales: 5,420.00
Discounts: -120.00
Net Sales: 5,300.00
Tax (19%): 954.00
Total: 6,254.00

REFUNDS
Count: 2
Total: -85.00

VOIDS
Count: 1
Total: -45.00

PAYMENT BREAKDOWN
Cash: 3,500.00
Card: 2,669.00
Mobile: 85.00

CASH DRAWER
Opening: 200.00
Cash In: 50.00
Cash Out: -100.00
Sales Cash: 3,500.00
Expected: 3,650.00

================================
     * NOT A FISCAL CLOSE *
================================
```

### 10.2 Z-Report Content

```
================================
         Z-REPORT
     FISCAL DAY CLOSE
================================
Terminal: POS001 - Main Counter
Report #: Z-POS001-2025-365
Generated: 2025-12-30 22:00:00
Period: 2025-12-30 08:00 - 22:00
Session: POS001-2025-365
--------------------------------

[Same content as X-Report plus:]

HASH VERIFICATION
First Transaction: POS001-04521
Last Transaction: POS001-04566
Transactions in Period: 46
Chain Valid: YES
Report Hash: a3f2...8d91

CASHIER BREAKDOWN
CSH001 (John): 25 transactions
CSH002 (Jane): 21 transactions

CATEGORY BREAKDOWN
Electronics: 3,200.00
Accessories: 1,500.00
Other: 554.00

HOURLY BREAKDOWN
08:00-09:00: 450.00 (5 trans)
09:00-10:00: 890.00 (8 trans)
...

================================
   FISCAL PERIOD CLOSED
================================
```

---

## 11. Integration Points

### 11.1 Document Module

```php
// When completing a sale, create Invoice
$invoice = $this->documentService->createFromPOS(
    type: DocumentType::INVOICE,
    transaction: $transaction,
    partner: $partner,
    items: $items
);

// When processing refund, create Credit Note
$creditNote = $this->documentService->createFromPOS(
    type: DocumentType::CREDIT_NOTE,
    transaction: $refundTransaction,
    partner: $partner,
    items: $refundItems,
    reference: $originalInvoice
);
```

### 11.2 Inventory Module

```php
// On sale: Reserve/deduct stock
foreach ($items as $item) {
    $this->inventoryService->deductStock(
        product: $item->product,
        quantity: $item->quantity,
        warehouse: $terminal->location->warehouse,
        batch: $item->batch, // If batch tracking
        reference: $transaction
    );
}

// On refund: Return stock
foreach ($items as $item) {
    $this->inventoryService->addStock(
        product: $item->product,
        quantity: $item->quantity,
        warehouse: $terminal->location->warehouse,
        batch: $item->batch,
        reference: $refundTransaction
    );
}
```

### 11.3 Treasury Module

```php
// On cash payment
$this->treasuryService->recordPayment(
    type: PaymentType::RECEIPT,
    method: PaymentMethod::CASH,
    amount: $payment->amount,
    reference: $transaction,
    account: $terminal->cashAccount
);

// On cash movement
$this->treasuryService->recordMovement(
    type: $movement->movement_type,
    amount: $movement->amount,
    reference: $movement,
    from: $movement->movement_type === 'cash_out' ? $terminal->cashAccount : null,
    to: $movement->movement_type === 'cash_in' ? $terminal->cashAccount : null
);
```

### 11.4 Batch/Expiry Module (when implemented)

```php
// On sale with batch tracking
$batch = $this->batchService->selectBatchFEFO(
    product: $item->product,
    quantity: $item->quantity,
    warehouse: $terminal->location->warehouse
);

// Validate not expired
if ($batch->isExpired()) {
    throw new ExpiredBatchException($batch);
}

// Warn if near expiry
if ($batch->isNearExpiry()) {
    $warnings[] = new NearExpiryWarning($batch);
}
```

---

## 12. Testing Requirements

### 12.1 Unit Tests

```
Tests/Unit/POS/
├── Services/
│   ├── TerminalServiceTest.php
│   ├── CashierServiceTest.php
│   ├── SessionServiceTest.php
│   ├── TransactionServiceTest.php
│   ├── CashMovementServiceTest.php
│   ├── ReportServiceTest.php
│   └── HashChainServiceTest.php
├── Models/
│   ├── TerminalTest.php
│   ├── CashierTest.php
│   ├── SessionTest.php
│   └── TransactionTest.php
└── DTOs/
    └── ValidationTest.php
```

### 12.2 Feature Tests

```
Tests/Feature/POS/
├── TerminalManagementTest.php
├── CashierManagementTest.php
├── SessionWorkflowTest.php
├── SaleTransactionTest.php
├── RefundWorkflowTest.php
├── VoidWorkflowTest.php
├── CashMovementTest.php
├── XReportTest.php
├── ZReportTest.php
├── HashChainIntegrityTest.php
└── AuthorizationTest.php
```

### 12.3 Critical Test Scenarios

1. **Session cannot open if terminal inactive**
2. **Transaction fails if session closed**
3. **Refund cannot exceed original quantity**
4. **Void only allowed within same session**
5. **Hash chain breaks if data modified**
6. **Cashier permissions respected**
7. **Stock deducted on sale, returned on refund**
8. **Z-Report cannot be generated twice for same session**
9. **Split payment totals match transaction total**
10. **Blind count does not reveal expected amount**

---

## Implementation Order

1. **Week 1: Foundation**
   - Migrations (all tables)
   - Models with relationships
   - Enums
   - Basic CRUD for Terminal, Cashier

2. **Week 2: Session & Transaction Core**
   - SessionService (open, close)
   - TransactionService (create sale)
   - Hash chain implementation
   - Basic API endpoints

3. **Week 3: Refunds, Voids, Cash**
   - Refund workflow
   - Void workflow
   - Cash movements
   - Document integration

4. **Week 4: Reports & Frontend**
   - X-Report, Z-Report
   - All frontend pages
   - Navigation integration

5. **Week 5: Polish**
   - Receipt printing
   - Testing
   - Bug fixes
   - Documentation
