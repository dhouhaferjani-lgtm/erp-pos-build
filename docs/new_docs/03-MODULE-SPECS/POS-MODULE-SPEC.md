# POS Module Specification

**Module:** POS  
**Product:** IziPOS only  
**Status:** Phase 1A - In Planning

---

## 1. Overview

Point-of-sale system for retail and F&B businesses. Handles:
- Terminal management and registration
- Cash register sessions (shifts)
- Transaction processing
- Receipt generation
- Fiscal compliance (hash chains)

---

## 2. Module Structure

```
App/Modules/POS/
├── Domain/
│   ├── Terminal.php
│   ├── Session.php              # Cash register session
│   ├── Transaction.php
│   ├── TransactionItem.php
│   ├── CashMovement.php         # Cash in/out
│   ├── Report.php               # X/Z reports
│   ├── Enums/
│   │   ├── TerminalStatus.php
│   │   ├── SessionStatus.php
│   │   ├── TransactionType.php
│   │   ├── PaymentMethod.php
│   │   └── CashMovementType.php
│   └── Repositories/
│       ├── TerminalRepositoryInterface.php
│       ├── SessionRepositoryInterface.php
│       └── TransactionRepositoryInterface.php
│
├── Application/
│   ├── DTOs/
│   │   ├── TerminalDTO.php
│   │   ├── SessionDTO.php
│   │   ├── TransactionDTO.php
│   │   └── ReportDTO.php
│   ├── Services/
│   │   ├── TerminalService.php
│   │   ├── SessionService.php
│   │   ├── TransactionService.php
│   │   ├── HashChainService.php
│   │   └── ReceiptService.php
│   └── Events/
│       ├── TerminalRegistered.php
│       ├── SessionOpened.php
│       ├── SessionClosed.php
│       └── TransactionCompleted.php
│
├── Infrastructure/
│   └── Persistence/
│       ├── TerminalRepository.php
│       ├── SessionRepository.php
│       └── TransactionRepository.php
│
├── Presentation/
│   ├── Controllers/
│   │   ├── TerminalController.php
│   │   ├── SessionController.php
│   │   ├── TransactionController.php
│   │   └── ReportController.php
│   └── routes.php
│
└── POSServiceProvider.php
```

---

## 3. Database Schema

### pos_terminals
```sql
CREATE TABLE pos_terminals (
    id BIGSERIAL PRIMARY KEY,
    uuid UUID NOT NULL UNIQUE,
    tenant_id BIGINT NOT NULL REFERENCES tenants(id),
    company_id BIGINT NOT NULL REFERENCES companies(id),
    
    -- Identity
    code VARCHAR(20) NOT NULL,           -- 'POS001', unique per company
    name VARCHAR(100) NOT NULL,          -- 'Main Counter'
    
    -- Device binding
    device_fingerprint VARCHAR(255),     -- Hardware identifier
    installation_id UUID,                -- Generated at first install
    
    -- Status
    status VARCHAR(20) NOT NULL DEFAULT 'pending',  -- pending, active, suspended, revoked
    
    -- Configuration
    receipt_header TEXT,
    receipt_footer TEXT,
    auto_print_receipt BOOLEAN DEFAULT true,
    
    -- Metadata
    registered_at TIMESTAMP,
    registered_by BIGINT REFERENCES users(id),
    last_activity_at TIMESTAMP,
    
    created_at TIMESTAMP DEFAULT NOW(),
    updated_at TIMESTAMP DEFAULT NOW(),
    deleted_at TIMESTAMP,
    
    UNIQUE(company_id, code)
);
```

### pos_sessions
```sql
CREATE TABLE pos_sessions (
    id BIGSERIAL PRIMARY KEY,
    uuid UUID NOT NULL UNIQUE,
    tenant_id BIGINT NOT NULL REFERENCES tenants(id),
    company_id BIGINT NOT NULL REFERENCES companies(id),
    terminal_id BIGINT NOT NULL REFERENCES pos_terminals(id),
    
    -- Session identity
    session_number INT NOT NULL,         -- Sequential per terminal
    
    -- Staff
    opened_by BIGINT NOT NULL REFERENCES users(id),
    closed_by BIGINT REFERENCES users(id),
    
    -- Cash tracking
    opening_balance DECIMAL(15,2) NOT NULL DEFAULT 0,
    expected_balance DECIMAL(15,2),       -- Calculated at close
    counted_balance DECIMAL(15,2),        -- Actual count
    discrepancy DECIMAL(15,2),            -- expected - counted
    
    -- Status
    status VARCHAR(20) NOT NULL DEFAULT 'open',  -- open, closed
    
    -- Timestamps
    opened_at TIMESTAMP NOT NULL DEFAULT NOW(),
    closed_at TIMESTAMP,
    
    created_at TIMESTAMP DEFAULT NOW(),
    updated_at TIMESTAMP DEFAULT NOW(),
    
    UNIQUE(terminal_id, session_number)
);
```

### pos_transactions
```sql
CREATE TABLE pos_transactions (
    id BIGSERIAL PRIMARY KEY,
    uuid UUID NOT NULL UNIQUE,
    tenant_id BIGINT NOT NULL REFERENCES tenants(id),
    company_id BIGINT NOT NULL REFERENCES companies(id),
    terminal_id BIGINT NOT NULL REFERENCES pos_terminals(id),
    session_id BIGINT NOT NULL REFERENCES pos_sessions(id),
    
    -- Transaction identity
    sequence_number INT NOT NULL,         -- Per-terminal, never resets
    receipt_number VARCHAR(50) NOT NULL,  -- 'POS001-00042'
    
    -- Type
    transaction_type VARCHAR(20) NOT NULL, -- sale, refund, void
    
    -- Customer (optional)
    partner_id BIGINT REFERENCES partners(id),
    
    -- Amounts
    subtotal DECIMAL(15,2) NOT NULL,
    discount_amount DECIMAL(15,2) DEFAULT 0,
    tax_amount DECIMAL(15,2) DEFAULT 0,
    total DECIMAL(15,2) NOT NULL,
    
    -- Document link (invoice/credit note created)
    document_id BIGINT REFERENCES documents(id),
    
    -- Hash chain (fiscal compliance)
    previous_hash VARCHAR(64),            -- SHA-256 of previous transaction
    transaction_hash VARCHAR(64) NOT NULL,-- SHA-256 of this transaction
    
    -- Status
    status VARCHAR(20) NOT NULL DEFAULT 'completed', -- draft, completed, voided
    voided_at TIMESTAMP,
    void_reason VARCHAR(255),
    
    created_at TIMESTAMP NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMP DEFAULT NOW(),
    
    UNIQUE(terminal_id, sequence_number)
);
```

### pos_transaction_items
```sql
CREATE TABLE pos_transaction_items (
    id BIGSERIAL PRIMARY KEY,
    transaction_id BIGINT NOT NULL REFERENCES pos_transactions(id),
    
    -- Product reference
    product_id BIGINT NOT NULL REFERENCES products(id),
    product_variant_id BIGINT REFERENCES product_variants(id),
    
    -- Batch tracking (for pharmacy)
    batch_id BIGINT REFERENCES product_batches(id),
    
    -- Snapshot at time of sale
    product_name VARCHAR(255) NOT NULL,
    product_sku VARCHAR(100),
    
    -- Pricing
    quantity DECIMAL(15,4) NOT NULL,
    unit_price DECIMAL(15,2) NOT NULL,
    discount_amount DECIMAL(15,2) DEFAULT 0,
    tax_rate DECIMAL(5,2) DEFAULT 0,
    tax_amount DECIMAL(15,2) DEFAULT 0,
    line_total DECIMAL(15,2) NOT NULL,
    
    created_at TIMESTAMP DEFAULT NOW()
);
```

### pos_transaction_payments
```sql
CREATE TABLE pos_transaction_payments (
    id BIGSERIAL PRIMARY KEY,
    transaction_id BIGINT NOT NULL REFERENCES pos_transactions(id),
    
    payment_method VARCHAR(50) NOT NULL,  -- cash, card, mobile, check, credit
    amount DECIMAL(15,2) NOT NULL,
    
    -- For card payments
    card_last_four VARCHAR(4),
    authorization_code VARCHAR(50),
    
    -- For change
    tendered DECIMAL(15,2),               -- Amount given (for cash)
    change_given DECIMAL(15,2),           -- Change returned
    
    created_at TIMESTAMP DEFAULT NOW()
);
```

### pos_cash_movements
```sql
CREATE TABLE pos_cash_movements (
    id BIGSERIAL PRIMARY KEY,
    uuid UUID NOT NULL UNIQUE,
    tenant_id BIGINT NOT NULL REFERENCES tenants(id),
    session_id BIGINT NOT NULL REFERENCES pos_sessions(id),
    
    -- Type: cash_in, cash_out, drop, float
    movement_type VARCHAR(20) NOT NULL,
    amount DECIMAL(15,2) NOT NULL,
    
    reason VARCHAR(255),
    
    performed_by BIGINT NOT NULL REFERENCES users(id),
    created_at TIMESTAMP NOT NULL DEFAULT NOW()
);
```

### pos_reports
```sql
CREATE TABLE pos_reports (
    id BIGSERIAL PRIMARY KEY,
    uuid UUID NOT NULL UNIQUE,
    tenant_id BIGINT NOT NULL REFERENCES tenants(id),
    terminal_id BIGINT NOT NULL REFERENCES pos_terminals(id),
    session_id BIGINT REFERENCES pos_sessions(id),
    
    -- Report type: x_report, z_report
    report_type VARCHAR(20) NOT NULL,
    report_number INT NOT NULL,           -- Sequential per terminal per type
    
    -- Report data (JSON snapshot)
    report_data JSONB NOT NULL,
    
    -- Z-report specific
    resets_counters BOOLEAN DEFAULT false,
    
    -- Hash for integrity
    report_hash VARCHAR(64) NOT NULL,
    
    generated_by BIGINT NOT NULL REFERENCES users(id),
    generated_at TIMESTAMP NOT NULL DEFAULT NOW(),
    
    UNIQUE(terminal_id, report_type, report_number)
);
```

---

## 4. Enums

### TerminalStatus
```php
enum TerminalStatus: string
{
    case PENDING = 'pending';       // Awaiting admin approval
    case ACTIVE = 'active';         // Operational
    case SUSPENDED = 'suspended';   // Temporarily disabled
    case REVOKED = 'revoked';       // Permanently disabled
}
```

### SessionStatus
```php
enum SessionStatus: string
{
    case OPEN = 'open';
    case CLOSED = 'closed';
}
```

### TransactionType
```php
enum TransactionType: string
{
    case SALE = 'sale';
    case REFUND = 'refund';
    case VOID = 'void';
}
```

### PaymentMethod
```php
enum PaymentMethod: string
{
    case CASH = 'cash';
    case CARD = 'card';
    case MOBILE = 'mobile';      // Mobile payment apps
    case CHECK = 'check';
    case CREDIT = 'credit';      // Customer account
}
```

### CashMovementType
```php
enum CashMovementType: string
{
    case CASH_IN = 'cash_in';    // Add cash to drawer
    case CASH_OUT = 'cash_out';  // Remove cash from drawer
    case DROP = 'drop';          // Safe drop (large bills)
    case FLOAT = 'float';        // Opening float
}
```

---

## 5. API Endpoints

### Terminals
```
POST   /api/pos/terminals              # Request registration
GET    /api/pos/terminals              # List terminals
GET    /api/pos/terminals/{id}         # Get terminal
PATCH  /api/pos/terminals/{id}/approve # Admin: approve terminal
PATCH  /api/pos/terminals/{id}/suspend # Admin: suspend terminal
PATCH  /api/pos/terminals/{id}/revoke  # Admin: revoke terminal
```

### Sessions
```
POST   /api/pos/sessions               # Open session (with opening balance)
GET    /api/pos/sessions/current       # Get current open session
POST   /api/pos/sessions/{id}/close    # Close session (with counted balance)
GET    /api/pos/sessions/{id}          # Get session details
```

### Transactions
```
POST   /api/pos/transactions           # Create transaction (complete sale)
GET    /api/pos/transactions           # List transactions
GET    /api/pos/transactions/{id}      # Get transaction details
POST   /api/pos/transactions/{id}/void # Void transaction
```

### Cash Movements
```
POST   /api/pos/cash-movements         # Record cash in/out
GET    /api/pos/cash-movements         # List movements for session
```

### Reports
```
POST   /api/pos/reports/x              # Generate X-report
POST   /api/pos/reports/z              # Generate Z-report (closes session)
GET    /api/pos/reports                # List reports
GET    /api/pos/reports/{id}           # Get report details
```

---

## 6. Hash Chain Implementation

### HashChainService

```php
class HashChainService
{
    public function calculateTransactionHash(
        int $terminalId,
        int $sequenceNumber,
        Carbon $timestamp,
        string $transactionData,
        ?string $previousHash
    ): string {
        $payload = implode('|', [
            $terminalId,
            $sequenceNumber,
            $timestamp->toIso8601String(),
            $transactionData,
            $previousHash ?? 'GENESIS',
        ]);
        
        return hash('sha256', $payload);
    }
    
    public function verifyChain(int $terminalId): ChainVerificationResult
    {
        $transactions = Transaction::where('terminal_id', $terminalId)
            ->orderBy('sequence_number')
            ->get();
        
        $previousHash = null;
        
        foreach ($transactions as $transaction) {
            $expectedHash = $this->calculateTransactionHash(
                $transaction->terminal_id,
                $transaction->sequence_number,
                $transaction->created_at,
                $transaction->getHashableData(),
                $previousHash
            );
            
            if ($expectedHash !== $transaction->transaction_hash) {
                return ChainVerificationResult::broken($transaction->sequence_number);
            }
            
            $previousHash = $transaction->transaction_hash;
        }
        
        return ChainVerificationResult::valid();
    }
}
```

---

## 7. Reports

### X-Report (Snapshot)
Mid-shift report that does NOT reset counters.

```json
{
  "report_type": "x_report",
  "generated_at": "2025-12-29T14:30:00Z",
  "terminal": "POS001",
  "session": {
    "opened_at": "2025-12-29T08:00:00Z",
    "opened_by": "John Doe"
  },
  "summary": {
    "transaction_count": 45,
    "gross_sales": 2340.00,
    "discounts": 120.00,
    "refunds": 50.00,
    "net_sales": 2170.00,
    "tax_collected": 217.00
  },
  "payments": {
    "cash": 1500.00,
    "card": 620.00,
    "mobile": 50.00
  },
  "cash_drawer": {
    "opening_balance": 200.00,
    "cash_sales": 1500.00,
    "cash_refunds": 30.00,
    "cash_in": 0.00,
    "cash_out": 100.00,
    "expected_balance": 1570.00
  }
}
```

### Z-Report (End of Day)
End-of-shift report that RESETS counters and creates permanent record.

Same structure as X-report, plus:
- `counted_balance`: Actual cash counted
- `discrepancy`: Expected vs counted difference
- `closed_at`: Timestamp
- `closed_by`: User who closed
- `report_hash`: SHA-256 of report data

---

## 8. Receipt Format

```
================================
        [COMPANY NAME]
     [Company Address Line 1]
     [Company Address Line 2]
     Tax ID: [Company Tax ID]
================================

Terminal: POS001
Receipt: POS001-00042
Date: 29/12/2025 14:30:45
Cashier: John D.

--------------------------------
QTY  ITEM                  PRICE
--------------------------------
 2   Paracetamol 500mg     4.800
 1   Vitamin C             7.500
 3   Hand Sanitizer       12.000
--------------------------------
                Subtotal: 24.300
                Discount: -2.000
                     Tax:  2.230
                ================
                  TOTAL: 24.530
================================
Payment: Cash           30.000
Change:                  5.470
================================

Customer: [Name if selected]

Thank you for your purchase!

[QR Code: Receipt verification]

Hash: abc123...
```

---

## 9. Implementation Order

### Phase 1A Tasks (Sequential)

1. **Database migrations** - Create all tables
2. **Enums** - Define all status/type enums  
3. **Domain models** - Terminal, Session, Transaction
4. **Repositories** - Interfaces and implementations
5. **HashChainService** - Core hash chain logic
6. **TerminalService** - Registration, approval flow
7. **SessionService** - Open/close workflow
8. **TransactionService** - Sale processing
9. **ReportService** - X/Z report generation
10. **Controllers** - API endpoints
11. **Frontend** - Terminal management UI
12. **Frontend** - Session open/close UI
13. **Frontend** - Basic checkout UI
14. **Receipt printing** - ESC/POS integration

---

## 10. Testing Requirements

```php
// Terminal tests
test_terminal_registration_creates_pending_terminal()
test_terminal_approval_activates_terminal()
test_duplicate_terminal_code_rejected()
test_revoked_terminal_cannot_be_reactivated()

// Session tests
test_session_open_requires_opening_balance()
test_only_one_session_open_per_terminal()
test_session_close_calculates_discrepancy()
test_session_close_generates_z_report()

// Transaction tests
test_transaction_creates_hash_chain()
test_first_transaction_has_genesis_previous_hash()
test_sequence_number_increments_per_terminal()
test_transaction_creates_inventory_movement()
test_void_transaction_creates_reverse_movement()

// Hash chain tests
test_hash_chain_verification_passes_for_valid_chain()
test_hash_chain_verification_detects_tampering()
test_hash_chain_verification_detects_missing_transaction()

// Report tests
test_x_report_does_not_reset_counters()
test_z_report_resets_counters()
test_z_report_closes_session()
test_report_hash_is_calculated()
```
