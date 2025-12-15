# GL Implementation Pre-Check Results

**Date:** 2025-12-05
**Purpose:** Verify current state before implementing GL Subledger (Option C)

---

## Pre-Check Summary

| # | Component | Status | Details |
|---|-----------|--------|---------|
| 1 | `journal_lines.partner_id` | ❌ MISSING | Not in model or migrations |
| 2 | `GeneralLedgerService` | ✅ EXISTS | Has `createFromInvoice()`, `createFromCreditNote()`, `createPaymentEntry()`, `postEntry()` |
| 3 | GL in DocumentController | ❌ NOT INTEGRATED | `post()` only updates status, no GL call |
| 4 | GL in PaymentController | ❌ NOT INTEGRATED | `store()` creates payment, no GL call |
| 5 | `Partner.balance` fields | ❌ MISSING | No `receivable_balance` or `credit_balance` |
| 6 | `Payment.payment_type` | ❌ MISSING | No `PaymentType` enum exists |
| 7 | `PaymentAllocation.document_id` | ❌ NOT NULLABLE | Uses `constrained()` without `nullable()` |
| 8 | `CustomerBalanceService` | ❌ MISSING | No such service exists |
| 9 | Chart of Accounts seeder | ✅ EXISTS | `TunisiaChartOfAccountsSeeder.php` |

---

## Detailed Findings

### Check 1: journal_lines.partner_id

**Status:** ❌ MISSING

**Current JournalLine fields:**
```php
$fillable = [
    'journal_entry_id',
    'account_id',
    'debit',
    'credit',
    'description',
    'line_order',
];
// NO partner_id
```

**Migration:** `2025_11_30_100000_create_journal_entries_table.php`
- Creates `journal_lines` table
- No `partner_id` column

**Action Required:** Add migration for `partner_id` column with index.

---

### Check 2: GeneralLedgerService

**Status:** ✅ EXISTS

**Location:** `apps/api/app/Modules/Accounting/Domain/Services/GeneralLedgerService.php`

**Available Methods:**
| Method | Purpose | Sets partner_id? |
|--------|---------|------------------|
| `createFromInvoice(Document $invoice, User $user)` | Creates JE for invoice | ❌ No |
| `createFromCreditNote(Document $creditNote, User $user)` | Creates JE for credit note | ❌ No |
| `createPaymentEntry($companyId, $amount, $debitAccountId, $creditAccountId, $description, User $user)` | Creates JE for payment | ❌ No (no partner param) |
| `postEntry(JournalEntry $entry, User $user)` | Adds hash chain | N/A |

**Action Required:** Update all methods to accept and set `partner_id` on AR/AP lines.

---

### Check 3: GL in DocumentController

**Status:** ❌ NOT INTEGRATED

**Current `post()` method (line ~524):**
```php
public function post(Request $request, DocumentType $type, string $document): JsonResponse
{
    // ... validation ...

    $documentModel->update(['status' => DocumentStatus::Posted]);  // <-- ONLY THIS

    // NO GL integration
}
```

**Action Required:** Add `GeneralLedgerService::createFromInvoice()` call after status update.

---

### Check 4: GL in PaymentController

**Status:** ❌ NOT INTEGRATED

**Current `store()` method:**
```php
$payment = DB::transaction(function () {
    $payment = Payment::create([...]);

    foreach ($allocations as $allocationData) {
        PaymentAllocation::create([...]);
        $document->balance_due = $newBalance;
        $document->save();
    }

    return $payment;
    // NO GL integration
});
```

**Action Required:** Add `GeneralLedgerService::createPaymentEntry()` call after allocations.

---

### Check 5: Partner Balance Fields

**Status:** ❌ MISSING

**Current Partner model fillable:**
```php
$fillable = [
    'tenant_id', 'company_id', 'name', 'type',
    'code', 'email', 'phone', 'country_code',
    'vat_number', 'notes', 'is_active'
];
// NO receivable_balance, credit_balance
```

**Action Required:** Add migration for balance fields.

---

### Check 6: Payment.payment_type

**Status:** ❌ MISSING

**Current Treasury Enums:**
- `FeeType.php`
- `InstrumentStatus.php`
- `PaymentStatus.php`
- `RepositoryType.php`
- ❌ No `PaymentType.php`

**Current Payment model:**
- No `payment_type` in fillable
- No PaymentType cast

**Action Required:** Create `PaymentType` enum and add to Payment model.

---

### Check 7: PaymentAllocation.document_id Nullable

**Status:** ❌ NOT NULLABLE

**Current migration (`2025_11_30_120000_create_treasury_tables.php`):**
```php
Schema::create('payment_allocations', function (Blueprint $table) {
    $table->uuid('id')->primary();
    $table->foreignUuid('payment_id')->constrained('payments')->onDelete('cascade');
    $table->foreignUuid('document_id')->constrained('documents')->onDelete('restrict');  // NOT nullable!
    $table->decimal('amount', 15, 2);
    $table->timestamps();
});
```

**Problem:** Cannot store advance payments (payments without a document).

**Action Required:** Migration to make `document_id` nullable.

---

### Check 8: CustomerBalanceService

**Status:** ❌ MISSING

**Search results:** No `CustomerBalanceService` or `PartnerBalanceService` found.

**Current workaround:** Balance calculated on-the-fly in `MultiPaymentService::getUnallocatedDepositBalance()`.

**Action Required:** Create `CustomerBalanceService` with GL subledger queries.

---

### Check 9: Chart of Accounts Seeder

**Status:** ✅ EXISTS

**Location:** `apps/api/database/seeders/TunisiaChartOfAccountsSeeder.php`

**Key accounts likely present:**
- Account 411xxx (Accounts Receivable)
- Account 419xxx (Customer Advances)
- Account 401xxx (Accounts Payable)
- Account 512xxx (Bank)
- Account 531xxx (Cash)

**Action Required:** Verify seeder creates required accounts with correct codes.

---

## What Needs Implementation

### Required Migrations (4)

1. **`add_partner_id_to_journal_lines`**
   - Add `partner_id` UUID nullable FK
   - Add composite index `(account_id, partner_id)`

2. **`add_balance_fields_to_partners`**
   - Add `receivable_balance` decimal(15,2) default 0
   - Add `credit_balance` decimal(15,2) default 0
   - Add `balance_updated_at` timestamp nullable

3. **`add_payment_type_to_payments`**
   - Add `payment_type` varchar(30) default 'document_payment'
   - Add index on `payment_type`

4. **`make_payment_allocation_document_nullable`**
   - Modify `document_id` to be nullable
   - Add `allocation_type` varchar(30)

### Required Enums (2)

1. **`PaymentType`** at `Treasury/Domain/Enums/PaymentType.php`
   - `DOCUMENT_PAYMENT`
   - `ADVANCE`
   - `REFUND`
   - `CREDIT_APPLICATION`

2. **`AllocationType`** at `Treasury/Domain/Enums/AllocationType.php`
   - `INVOICE_PAYMENT`
   - `CREDIT_ADDITION`
   - `CREDIT_APPLICATION`

### Required Services (1)

1. **`CustomerBalanceService`** at `Partner/Domain/Services/CustomerBalanceService.php`
   - `calculateReceivableFromGL(Partner $partner): string`
   - `calculateCreditFromGL(Partner $partner): string`
   - `getCustomerLedger(Partner $partner, ?Carbon $from, ?Carbon $to): Collection`
   - `refreshPartnerBalance(Partner $partner): void`

### Required Model Updates (4)

1. **JournalLine** - Add `partner_id` to fillable, add relationship
2. **Partner** - Add balance fields to fillable and casts
3. **Payment** - Add `payment_type` to fillable and cast
4. **PaymentAllocation** - Add `allocation_type` to fillable and cast

### Required Controller Integration (2)

1. **DocumentController::post()** - Call GL service for invoices
2. **PaymentController::store()** - Call GL service for payments

### Required GeneralLedgerService Updates

1. Update `createFromInvoice()` to set `partner_id` on AR line
2. Update `createFromCreditNote()` to set `partner_id` on AR line
3. Update `createPaymentEntry()` to accept partner and set `partner_id`

---

## Estimated Effort

| Task | Estimate |
|------|----------|
| Migrations + Enums | 1-2 hours |
| Model updates | 30 min |
| CustomerBalanceService | 1-2 hours |
| GeneralLedgerService updates | 1 hour |
| Controller integration | 1-2 hours |
| Testing | 2-3 hours |
| **Total** | **7-11 hours** |

---

## Risk Assessment

| Risk | Impact | Mitigation |
|------|--------|------------|
| Existing payments have no GL entries | Medium | Run backfill script or accept historical gap |
| Chart of accounts missing required codes | High | Verify seeder, add missing accounts |
| Breaking existing payment flow | High | Run full test suite after each change |
| Performance of GL queries | Medium | Ensure proper indexes on journal_lines |

---

## Next Steps

**STOP HERE** - Awaiting user confirmation to proceed with implementation.

Questions for user:
1. Should we backfill GL entries for existing posted invoices and payments?
2. Which chart of accounts codes should be used? (Tunisia default or custom?)
3. Should this be done in one PR or split into multiple?
