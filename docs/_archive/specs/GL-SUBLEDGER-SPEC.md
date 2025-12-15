# GL Subledger Implementation Spec (Option C)

**Project:** AutoERP Payment System Enhancement  
**Date:** 2025-12-05  
**Status:** Ready for Implementation  
**Location:** Place this file at `docs/specs/GL-SUBLEDGER-SPEC.md`

---

## 1. Objective

Transform the existing disconnected General Ledger into a fully integrated subledger system where customer balance is calculated FROM the GL (single source of truth).

## 2. Current State vs Target State

```
CURRENT STATE:
┌─────────────────────────────────────────────────────────────────┐
│  Customer Balance = SUM(documents.balance_due)                  │
│  GL exists but is NEVER CALLED                                  │
│  journal_lines has NO partner_id                                │
└─────────────────────────────────────────────────────────────────┘

TARGET STATE:
┌─────────────────────────────────────────────────────────────────┐
│  Customer Balance = SUM(debit-credit) on GL where partner_id=X │
│  Every invoice/payment creates journal entry                    │
│  Partner.receivable_balance = cached from GL                    │
│  Document.balance_due = cached, verified against GL             │
└─────────────────────────────────────────────────────────────────┘
```

## 3. Success Criteria

- [ ] `journal_lines.partner_id` exists and is populated
- [ ] Posting an invoice creates a journal entry with correct partner_id
- [ ] Recording a payment creates a journal entry with correct partner_id
- [ ] Customer balance can be queried from GL
- [ ] Partner.receivable_balance matches GL calculation
- [ ] Document.balance_due matches GL-derived balance
- [ ] All existing tests pass
- [ ] New tests cover GL integration

## 4. Schema Changes

### 4.1 journal_lines table
```sql
ALTER TABLE journal_lines 
ADD COLUMN partner_id UUID REFERENCES partners(id) ON DELETE SET NULL;

CREATE INDEX journal_lines_subledger_idx ON journal_lines(account_id, partner_id);
```

### 4.2 partners table
```sql
ALTER TABLE partners
ADD COLUMN receivable_balance DECIMAL(15,2) DEFAULT 0,
ADD COLUMN credit_balance DECIMAL(15,2) DEFAULT 0,
ADD COLUMN balance_updated_at TIMESTAMP NULL;
```

### 4.3 payments table
```sql
ALTER TABLE payments
ADD COLUMN payment_type VARCHAR(30) DEFAULT 'document_payment';

CREATE INDEX payments_payment_type_idx ON payments(payment_type);
```

### 4.4 payment_allocations table
```sql
ALTER TABLE payment_allocations
ALTER COLUMN document_id DROP NOT NULL;

ALTER TABLE payment_allocations
ADD COLUMN allocation_type VARCHAR(30) DEFAULT 'invoice_payment';
```

## 5. New Enums

### PaymentType
```
document_payment  - Payment applied to invoice(s)
advance           - Advance payment (creates customer credit)
refund            - Refund to customer
credit_application - Applying existing credit to invoice
```

### AllocationType
```
invoice_payment    - Standard payment against invoice
credit_addition    - Adding to customer credit balance
credit_application - Using credit to pay invoice
```

## 6. Service Changes

### 6.1 GeneralLedgerService Updates

**createFromInvoice()** - Add partner_id to AR line:
```php
JournalLine::create([
    'journal_entry_id' => $entry->id,
    'account_id' => $receivablesAccount->id,
    'partner_id' => $invoice->partner_id,  // NEW
    'debit' => $invoice->total_ttc,
    'credit' => '0.00',
]);
```

**createPaymentEntry()** - Add partner_id to AR credit line:
```php
JournalLine::create([
    'journal_entry_id' => $entry->id,
    'account_id' => $receivablesAccount->id,
    'partner_id' => $payment->partner_id,  // NEW
    'debit' => '0.00',
    'credit' => $amount,
]);
```

### 6.2 New CustomerBalanceService

Location: `app/Modules/Partner/Domain/Services/CustomerBalanceService.php`

Methods:
- `calculateReceivableFromGL(Partner): string` - Sum AR account for partner
- `calculateCreditFromGL(Partner): string` - Sum advances account for partner
- `getCustomerLedger(Partner, from?, to?): Collection` - Full statement
- `refreshPartnerBalance(Partner): void` - Update cached balances

## 7. Controller Integration

### 7.1 DocumentController::post()

After setting status to Posted:
1. Call `GeneralLedgerService::createFromInvoice()`
2. Call `GeneralLedgerService::postEntry()`
3. Link document to journal entry
4. Call `CustomerBalanceService::refreshPartnerBalance()`

### 7.2 PaymentController::store()

After creating allocations:
1. For each allocation, call `GeneralLedgerService::createPaymentEntry()`
2. Call `GeneralLedgerService::postEntry()`
3. Call `CustomerBalanceService::refreshPartnerBalance()`

## 8. Account Codes (French PCG)

| Code | Name | Type | Subledger |
|------|------|------|-----------|
| 411000 | Accounts Receivable | Asset | Yes (partner_id) |
| 419000 | Customer Advances | Liability | Yes (partner_id) |
| 401000 | Accounts Payable | Liability | Yes (partner_id) |
| 512000 | Bank Account | Asset | No |
| 530000 | Cash Register | Asset | No |
| 706000 | Service Revenue | Revenue | No |
| 707000 | Product Sales | Revenue | No |
| 445710 | VAT Collected | Liability | No |

## 9. Data Flow Examples

### Invoice Posted (€500 + €100 VAT = €600 TTC)

```
Document #INV-001 posted
└── JournalEntry created
    ├── Line 1: Debit 411000 (AR) €600, partner_id = CUST-001
    ├── Line 2: Credit 706000 (Revenue) €500, partner_id = NULL
    └── Line 3: Credit 445710 (VAT) €100, partner_id = NULL

Partner CUST-001 balance recalculated:
└── receivable_balance = SUM(debit-credit) on 411xxx where partner = CUST-001
```

### Payment Received (€600)

```
Payment #PAY-001 recorded
└── JournalEntry created
    ├── Line 1: Debit 512000 (Bank) €600, partner_id = NULL
    └── Line 2: Credit 411000 (AR) €600, partner_id = CUST-001

Document #INV-001 updated:
└── balance_due = €0, status = Paid

Partner CUST-001 balance recalculated:
└── receivable_balance = €0
```

## 10. Estimated Effort

| Task | Time |
|------|------|
| Migrations (4) | 1 hour |
| Model updates (4) | 30 min |
| GeneralLedgerService updates | 2 hours |
| CustomerBalanceService (new) | 2 hours |
| Controller integration | 2 hours |
| Chart of accounts seeder | 1 hour |
| Tests | 3 hours |
| **Total** | **~12 hours** |

## 11. Files to Modify/Create

### Migrations (Create)
- `add_partner_id_to_journal_lines.php`
- `add_balance_fields_to_partners.php`
- `add_payment_type_to_payments.php`
- `make_payment_allocation_document_nullable.php`

### Enums (Create)
- `app/Modules/Treasury/Domain/Enums/PaymentType.php`
- `app/Modules/Treasury/Domain/Enums/AllocationType.php`

### Models (Modify)
- `app/Modules/Accounting/Domain/JournalLine.php`
- `app/Modules/Partner/Domain/Partner.php`
- `app/Modules/Treasury/Domain/Payment.php`
- `app/Modules/Treasury/Domain/PaymentAllocation.php`

### Services (Modify/Create)
- `app/Modules/Accounting/Domain/Services/GeneralLedgerService.php` (modify)
- `app/Modules/Partner/Domain/Services/CustomerBalanceService.php` (create)

### Controllers (Modify)
- `app/Modules/Document/Presentation/Controllers/DocumentController.php`
- `app/Modules/Treasury/Presentation/Controllers/PaymentController.php`

### Seeders (Create/Modify)
- `database/seeders/ChartOfAccountsSeeder.php`

## 12. Risk Mitigation

| Risk | Mitigation |
|------|------------|
| Existing data inconsistency | Create opening balance JE per customer |
| Performance on large datasets | Index on (account_id, partner_id) |
| Breaking existing tests | Run tests after each step |
| Double-counting | Verify debit=credit on all entries |

## 13. Future Enhancements (Not in Scope)

- Supplier payables subledger (same pattern, 401xxx accounts)
- Aged receivables report
- Customer statement PDF generation
- Multi-currency subledger
- Credit limit enforcement
