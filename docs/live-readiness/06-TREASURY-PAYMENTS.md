# Treasury & Payments Module

> Payment cycles and allocation features

---

## Executive Summary

**Module Completeness: 75%**

Core payment recording and allocation working well. Several features need frontend integration and some advanced features aren't implemented.

---

## 1. Payment Methods

### Configuration Switches

The `PaymentMethod` entity supports 8 configurable switches:

| Switch | Description | Example |
|--------|-------------|---------|
| `is_physical` | Physical instrument required | Checks, vouchers |
| `has_maturity` | Has maturity date | Post-dated checks |
| `requires_third_party` | Third-party involvement | Card processors |
| `is_push` | Push payment (payer initiates) | Bank transfer |
| `has_deducted_fees` | Fees deducted from amount | PayPal |
| `is_restricted` | Restricted to specific use | Meal vouchers |

### Fee Types

- Fixed amount
- Percentage
- Mixed (fixed + percentage)

### Seeded Payment Methods

| Code | Name | Switches |
|------|------|----------|
| CASH | Cash | - |
| CHECK | Check | is_physical, has_maturity |
| TRANSFER | Bank Transfer | is_push |
| CARD | Credit/Debit Card | requires_third_party, has_deducted_fees |
| DIRECT_DEBIT | Direct Debit | is_push, has_maturity |
| PAYPAL | PayPal | requires_third_party, has_deducted_fees |
| LCR | Promissory Note | has_maturity |
| BILL_EXCHANGE | Bill of Exchange | has_maturity |
| MEAL_VOUCHER | Meal Voucher | is_restricted, requires_third_party |
| CRYPTO | Cryptocurrency | (disabled by default) |

---

## 2. Payment Flow

### Payment Types

| Type | Description |
|------|-------------|
| `DocumentPayment` | Allocated to invoices |
| `Advance` | Prepayment creating credit |
| `Refund` | Money returned |
| `CreditApplication` | Using existing credit |
| `SupplierPayment` | Paying suppliers |

### Allocation Methods

**Service**: `PaymentAllocationService`

| Method | Description |
|--------|-------------|
| **FIFO** | Oldest invoices by document date first |
| **Due Date** | Most overdue invoices first |
| **Manual** | User selects specific invoices |

### Features

- Allocation preview before applying
- Tolerance write-off support
- Automatic GL journal entries
- Handles both invoice and sales order allocations
- Excess amount handling as customer advance

### Partial Payments

- Full support via `PaymentAllocation` entity
- Documents track `balance_due`
- Auto-transition to `Paid` when fully allocated

### Prepayments

- `recordDeposit()` - Record unallocated payment
- `applyDepositToDocument()` - Apply to invoice
- `getUnallocatedDepositBalance()` - Check credit

---

## 3. Payment Instruments

### Check Tracking

| Field | Description |
|-------|-------------|
| reference_number | Check number |
| drawer_name | Who issued |
| maturity_date | When payable |
| expiry_date | When expires |
| bank_name, branch, account | Bank details |
| amount, currency | Value |
| status | Lifecycle state |

### Instrument Status Workflow

```
Received → Deposited → Clearing → Cleared
    ↓           ↓          ↓
    └──────────────────────→ Bounced
                           → Expired
                           → Cancelled
```

### Payment Repositories

| Type | Description |
|------|-------------|
| `cash_register` | POS cash drawer |
| `safe` | Office safe |
| `bank_account` | With IBAN/BIC |
| `virtual` | PayPal, etc. |

**Tracked Fields**:
- Current balance
- Last reconciliation date/balance
- Associated GL account

---

## 4. Accounting Integration

### GL Entries Created

| Operation | Debit | Credit |
|-----------|-------|--------|
| Payment received | Bank/Cash | AR |
| Customer advance | Bank/Cash | Customer Advances (419) |
| Prepayment clear | Customer Advances | AR |
| Tolerance (underpayment) | Tolerance Expense (658) | AR |
| Tolerance (overpayment) | AR | Tolerance Income (758) |

### Services

- `GeneralLedgerService::createPaymentReceivedJournalEntry()`
- `GeneralLedgerService::createCustomerAdvanceJournalEntry()`
- `GeneralLedgerService::createPaymentToleranceJournalEntry()`

---

## 5. Frontend Features

### Payment Forms

**PaymentForm.tsx**:
- Partner, payment method, repository selection
- Inline allocation during creation
- Reference and notes fields

**PaymentAllocationForm.tsx**:
- Allocation method selection
- Preview functionality
- Manual allocation UI

### Components

| Component | Purpose |
|-----------|---------|
| OpenInvoicesList | Available invoices |
| AllocationPreview | Breakdown display |
| ToleranceSettingsDisplay | Threshold info |

### Pages

| Page | Status |
|------|--------|
| PaymentListPage | ✅ Complete |
| PaymentDetailPage | ✅ Complete |
| InstrumentListPage | ⚠️ Read-only |
| InstrumentDetailPage | ⚠️ Read-only |
| RepositoryListPage | ⚠️ Read-only |
| RepositoryDetailPage | ⚠️ Limited |

---

## 6. Missing Features

### Critical Gaps

| Feature | Backend | Frontend | Priority |
|---------|---------|----------|----------|
| Payment refunds | ✅ Complete | ❌ Missing | High |
| Split payments | ✅ Complete | ❌ Missing | High |
| Bank reconciliation | ⚠️ Fields only | ❌ Missing | High |
| Supplier payments | ❌ Missing | ❌ Missing | High |

### Medium Gaps

| Feature | Status | Notes |
|---------|--------|-------|
| Multi-currency | Fields exist | Not integrated |
| Payment method fees | Logic exists | Not applied |
| Cash discounts | Flag exists | Not implemented |
| Instrument actions | Controllers exist | No UI |

### Low Gaps

| Feature | Status |
|---------|--------|
| Payment status workflow | Manual only |
| Audit trail display | Not shown |
| Cascade allocation | Not implemented |
| Recurring payments | Not implemented |

---

## 7. API Endpoints

### Payments

| Method | Endpoint | Purpose |
|--------|----------|---------|
| POST | `/payments` | Record payment |
| GET | `/payments` | List payments |
| GET | `/payments/{id}` | Payment details |
| POST | `/payments/{id}/allocate` | Allocate to invoices |

### Smart Allocation

| Method | Endpoint | Purpose |
|--------|----------|---------|
| POST | `/payments/smart-allocation/preview` | Preview allocation |
| POST | `/payments/smart-allocation/apply` | Apply allocation |

### Instruments

| Method | Endpoint | Purpose |
|--------|----------|---------|
| GET | `/instruments` | List instruments |
| GET | `/instruments/{id}` | Instrument details |
| POST | `/instruments/{id}/deposit` | Deposit instrument |
| POST | `/instruments/{id}/clear` | Mark as cleared |

### Repositories

| Method | Endpoint | Purpose |
|--------|----------|---------|
| GET | `/repositories` | List repositories |
| GET | `/repositories/{id}` | Repository details |

---

## 8. Database Schema

### payments

```sql
id UUID PRIMARY KEY
tenant_id, company_id, partner_id UUID
payment_date DATE
amount DECIMAL(15,2)
currency CHAR(3)
exchange_rate_at_payment DECIMAL(10,6)
payment_method_id, repository_id UUID
reference, notes VARCHAR
status ENUM (pending, completed, failed, reversed)
created_by UUID
journal_entry_id UUID
```

### payment_allocations

```sql
id UUID PRIMARY KEY
payment_id, document_id UUID
amount DECIMAL(15,2)
created_at TIMESTAMP
```

### payment_instruments

```sql
id UUID PRIMARY KEY
tenant_id, company_id UUID
payment_method_id, payment_id UUID
reference_number VARCHAR
drawer_name, bank_name, branch, account VARCHAR
amount DECIMAL(15,2)
currency CHAR(3)
maturity_date, expiry_date DATE
status ENUM (received, in_transit, deposited, clearing, cleared, bounced, expired, cancelled, collected)
current_repository_id UUID
received_at, deposited_at, cleared_at TIMESTAMP
```

### payment_repositories

```sql
id UUID PRIMARY KEY
tenant_id, company_id UUID
code, name VARCHAR
type ENUM (cash_register, safe, bank_account, virtual)
account_number, iban, bic VARCHAR
current_balance DECIMAL(15,2)
currency CHAR(3)
gl_account_id UUID
last_reconciled_at TIMESTAMP
last_reconciled_balance DECIMAL(15,2)
```

---

## 9. Testing Status

### Test Files

- `PaymentAllocationServiceTest.php` - Unit tests
- `PaymentToleranceServiceTest.php` - Tolerance logic
- `SmartPaymentIntegrationTest.php` - E2E flows
- `PaymentTest.php` - Feature tests
- `PaymentMethodTest.php` - Configuration
- `PaymentInstrumentTest.php` - Instrument lifecycle
- `PaymentRepositoryTest.php` - Repository operations

### Coverage

- Core allocation: ✅ Good
- Tolerance handling: ✅ Good
- Instrument lifecycle: ✅ Good
- Refunds: ⚠️ Unknown
- Split payments: ⚠️ Unknown

---

## 10. Recommendations

### Phase 1 - Critical (Before Launch)

| Task | Effort | Files |
|------|--------|-------|
| Payment refund UI | 8h | New component |
| Split payment UI | 6h | New component |
| Bank reconciliation page | 12h | New feature |

### Phase 2 - Important

| Task | Effort |
|------|--------|
| Multi-currency integration | 12h |
| Payment method fee deduction | 4h |
| Supplier payment workflow | 16h |

### Phase 3 - Enhancement

| Task | Effort |
|------|--------|
| Instrument action workflows | 8h |
| Cash discount implementation | 6h |
| Recurring payment scheduling | 12h |
| Payment audit trail display | 4h |

---

## 11. File Locations

### Backend

```
apps/api/app/Modules/Treasury/
├── Domain/
│   ├── Payment.php
│   ├── PaymentAllocation.php
│   ├── PaymentMethod.php
│   ├── PaymentInstrument.php
│   ├── PaymentRepository.php
│   └── Enums/
│       ├── PaymentStatus.php
│       ├── PaymentType.php
│       └── InstrumentStatus.php
├── Application/Services/
│   ├── PaymentAllocationService.php
│   ├── PaymentToleranceService.php
│   ├── MultiPaymentService.php
│   └── PaymentRefundService.php
└── Presentation/Controllers/
    ├── PaymentController.php
    ├── PaymentAllocationController.php
    └── PaymentInstrumentController.php
```

### Frontend

```
apps/web/src/features/treasury/
├── pages/
│   ├── PaymentListPage.tsx
│   ├── PaymentDetailPage.tsx
│   ├── InstrumentListPage.tsx
│   └── RepositoryListPage.tsx
├── components/
│   ├── PaymentForm.tsx
│   ├── PaymentAllocationForm.tsx
│   ├── OpenInvoicesList.tsx
│   └── AllocationPreview.tsx
└── api/
    ├── payments.ts
    └── smartPayment.ts
```

---

**File**: `docs/live-readiness/06-TREASURY-PAYMENTS.md`
**Generated**: 2025-12-13
