# Accounting Module

> Chart of accounts, journal entries, and general ledger.

---

## Purpose

The Accounting module implements double-entry bookkeeping with:
- Chart of accounts (Tunisia PCG-based by default)
- Journal entries with automatic GL posting
- Partner subledger for receivables/payables
- System account purposes for automated entries

---

## Chart of Accounts

### Account Structure (Tunisia PCG)

| Class | Range | Category | Description |
|-------|-------|----------|-------------|
| 1 | 10-19 | Equity | Capital, reserves |
| 2 | 20-29 | Fixed Assets | Immobilizations |
| 3 | 30-39 | Inventory | Stock accounts |
| 4 | 40-49 | Receivables/Payables | Partners, tax |
| 5 | 50-59 | Cash | Banks, cash |
| 6 | 60-69 | Expenses | Operating expenses |
| 7 | 70-79 | Revenue | Sales, income |

### System Account Purposes

Accounts can be tagged with a `system_purpose` for automated entry creation:

| Purpose | Account | Used For |
|---------|---------|----------|
| `accounts_receivable` | 411 | Customer invoices |
| `accounts_payable` | 401 | Supplier invoices |
| `sales_revenue` | 701 | Product sales |
| `cash` | 531 | Cash payments |
| `bank` | 512 | Bank transfers |
| `customer_advance` | 419 | Advance payments |
| `payment_tolerance_expense` | 658 | Write-off underpayments |
| `payment_tolerance_income` | 758 | Write-off overpayments |

---

## Journal Entries

### Structure

```php
JournalEntry {
    id: UUID
    company_id: UUID
    reference: string
    description: string
    entry_date: date
    state: JournalEntryState (draft, posted)
    source_type: string?  // 'invoice', 'payment', etc.
    source_id: UUID?
}

JournalLine {
    id: UUID
    journal_entry_id: UUID
    account_id: UUID
    partner_id: UUID?     // For subledger tracking
    debit: decimal
    credit: decimal
    description: string?
}
```

### Automatic Entry Creation

Journal entries are automatically created when:

| Event | Debit | Credit |
|-------|-------|--------|
| Invoice Posted | Accounts Receivable (411) | Sales Revenue (701) |
| Payment Received | Cash/Bank (5xx) | Accounts Receivable (411) |
| Advance Payment | Cash/Bank (5xx) | Customer Advance (419) |
| Credit Note Posted | Sales Revenue (701) | Accounts Receivable (411) |

---

## Services

### GeneralLedgerService

```php
// Create journal entry for invoice
$entry = $glService->createInvoiceEntry($invoice);

// Create journal entry for payment
$entry = $glService->createPaymentEntry($payment);

// Create customer advance entry
$entry = $glService->createCustomerAdvanceEntry($payment, $amount);
```

### ChartOfAccountsService

```php
// Get account by system purpose
$account = $chartService->getAccountByPurpose($companyId, 'accounts_receivable');

// Seed Tunisia chart of accounts
$chartService->seedTunisiaChart($company);
```

### PartnerBalanceService

```php
// Update partner balances from journal entries
$balanceService->recalculateBalance($partner);

// Get receivable balance for partner
$balance = $balanceService->getReceivableBalance($partnerId, $companyId);
```

---

## Partner Subledger

Journal lines can reference a `partner_id` for subledger tracking:

```sql
-- Get all entries for a customer
SELECT * FROM journal_lines
WHERE partner_id = :partnerId
  AND account_id IN (SELECT id FROM accounts WHERE system_purpose = 'accounts_receivable');
```

This enables:
- Partner statement generation
- Aging reports
- Balance reconciliation

---

## API Endpoints

```
GET    /api/accounts                    # List chart of accounts
GET    /api/journal-entries             # List journal entries
POST   /api/journal-entries             # Create manual entry
GET    /api/partners/{id}/ledger        # Partner subledger
GET    /api/partners/{id}/balance       # Partner balance summary
```
