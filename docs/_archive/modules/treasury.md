# AutoERP Treasury Module

> Complete documentation for the Treasury module including Universal Payment Methods,
> Payment Instruments, and Repository management.

---

## Overview

The Treasury module handles all payment-related functionality in AutoERP:
- Payment method configuration (universal, country-agnostic)
- Payment repositories (cash registers, safes, bank accounts)
- Payment instruments (physical items: checks, vouchers, etc.)
- Payment recording and allocation
- Bank reconciliation

---

## Universal Payment Method System

Instead of hardcoding payment types, AutoERP uses a **configuration-driven approach** where every payment method is defined by a set of switches and behaviors.

### The Six Switches

| Switch | Description | Example: Yes | Example: No |
|--------|-------------|--------------|-------------|
| `is_physical` | Needs physical storage | Check, Voucher | Bank Transfer |
| `has_maturity` | Has a future cash date | Post-dated Check, Traite | Cash, Immediate Transfer |
| `requires_third_party` | Needs bank/gateway | Card, Direct Debit | Cash |
| `is_push` | Client sends money | Transfer, Cash | Direct Debit |
| `has_deducted_fees` | Fees taken from amount | Card (2%), Mobile Money | Transfer |
| `is_restricted` | Limited to specific goods | Meal Voucher, Fuel Card | Cash |

### Four Business Logic Categories

Based on the switches, payment methods fall into four categories:

#### A. Immediate Liquidity
**Examples:** Cash, Wire Transfer, Instant Mobile Money

**Behavior:**
- Money is "good" the moment transaction happens
- May have deducted fees (cards, mobile money)
- Directly increases repository balance

**Fee Handling:**
```
Customer pays: €100
Card fee (2%): €2
You receive: €98

Journal entries:
  Dr. Bank Account     €98
  Dr. Bank Fees        €2
  Cr. Customer         €100
```

#### B. Deferred Paper Instruments
**Examples:** Checks, Post-dated Checks (PDC), Traites, Bills of Exchange

**Behavior:**
- You have the paper, not the money yet
- Must track physical location (custody)
- Has a lifecycle through multiple statuses

**Lifecycle:**
```
received → in_transit → deposited → clearing → cleared
                                            ↘ bounced
```

**Status Definitions:**

| Status | Meaning | Location |
|--------|---------|----------|
| `received` | In our possession | Safe/cash register |
| `in_transit` | Being moved | Between repositories |
| `deposited` | Given to bank | Bank (awaiting processing) |
| `clearing` | Bank processing | Bank |
| `cleared` | Funds confirmed | N/A (closed) |
| `bounced` | Returned unpaid | Back in our possession |
| `expired` | Past validity | N/A (closed) |
| `cancelled` | Voided | N/A (closed) |

#### C. Mandate-Based Payments
**Examples:** SEPA Direct Debit, Prélèvement

**Behavior:**
- We "pull" money from customer's account
- Requires a signed mandate (permission)
- Reversible for 8 weeks (SEPA)

**Workflow:**
```
1. Customer signs mandate
2. Mandate ID stored on customer profile
3. Generate collection batch (list of customers to charge)
4. Send batch to bank
5. Bank processes and pays 3 days later
6. Keep funds "flagged" as reversible for 8 weeks
```

#### D. Restricted Value
**Examples:** Meal Vouchers (Ticket Restaurant), Fuel Cards, Gift Cards

**Behavior:**
- Counts as money for specific purchases only
- Must be redeemed with issuer
- Often has expiry date

**Workflow:**
```
1. Accept voucher for eligible purchase (food only, fuel only)
2. Store in virtual repository
3. Periodically send to issuer for reimbursement
4. Track expiry dates (alert 30 days before)
```

---

## Country Presets

The switch-based system allows one-click setup for different regions:

### Tunisia / Morocco / Algeria (Francophone)
```php
$presets = [
    'cash' => ['is_physical' => true],
    'bank_transfer' => ['requires_third_party' => true],
    'check' => ['is_physical' => true],
    'traite' => ['is_physical' => true, 'has_maturity' => true],
    'postal_check' => ['is_physical' => true],  // CCP
];
// Special: Withholding tax (Retenue à la source) active
```

### UAE / Saudi Arabia / Gulf (PDC Model)
```php
$presets = [
    'cash' => ['is_physical' => true],
    'bank_transfer' => ['requires_third_party' => true],
    'credit_card' => ['requires_third_party' => true, 'has_deducted_fees' => true],
    'pdc' => ['is_physical' => true, 'has_maturity' => true],  // Post-dated check
];
// Special: Strict PDC reporting (legal consequences for bouncing)
```

### France / Europe (SEPA Model)
```php
$presets = [
    'sepa_transfer' => ['requires_third_party' => true],
    'sepa_direct_debit' => ['requires_third_party' => true, 'is_push' => false],
    'card' => ['requires_third_party' => true, 'has_deducted_fees' => true],
    'check' => ['is_physical' => true, 'has_maturity' => false],  // Immediate in France
];
// Special: Mandate management ON for Direct Debits
```

### Sub-Saharan Africa (Mobile Model)
```php
$presets = [
    'cash' => ['is_physical' => true],
    'mobile_money' => ['requires_third_party' => true, 'has_deducted_fees' => true],
    // M-Pesa, Orange Money, Wave
];
// Special: API aggregator connections instead of traditional banks
```

---

## Data Model

### payment_methods

```php
// Example configurations

// Cash
PaymentMethod::create([
    'code' => 'CASH',
    'name' => 'Cash',
    'is_physical' => true,
    'has_maturity' => false,
    'requires_third_party' => false,
    'is_push' => true,
    'has_deducted_fees' => false,
    'is_restricted' => false,
]);

// Post-dated Check (Gulf)
PaymentMethod::create([
    'code' => 'PDC',
    'name' => 'Post-dated Check',
    'is_physical' => true,
    'has_maturity' => true,
    'requires_third_party' => false,
    'is_push' => true,
    'has_deducted_fees' => false,
    'is_restricted' => false,
]);

// Credit Card (with fees)
PaymentMethod::create([
    'code' => 'CARD',
    'name' => 'Credit/Debit Card',
    'is_physical' => false,
    'has_maturity' => false,
    'requires_third_party' => true,
    'is_push' => true,
    'has_deducted_fees' => true,
    'fee_type' => 'percentage',
    'fee_percent' => 1.5,
]);

// Meal Voucher
PaymentMethod::create([
    'code' => 'MEAL_VOUCHER',
    'name' => 'Ticket Restaurant',
    'is_physical' => true,
    'has_maturity' => false,
    'requires_third_party' => false,
    'is_push' => true,
    'has_deducted_fees' => false,
    'is_restricted' => true,
    'restriction_type' => 'food',
]);
```

### payment_repositories

```php
// Cash Register
PaymentRepository::create([
    'code' => 'CASH_REG_01',
    'name' => 'Main Cash Register',
    'type' => 'cash_register',
    'location_id' => $mainShopLocation,
    'responsible_user_id' => $cashier,
]);

// Safe for checks
PaymentRepository::create([
    'code' => 'CHECK_SAFE',
    'name' => 'Check Safe',
    'type' => 'safe',
    'location_id' => $backOffice,
]);

// Bank Account
PaymentRepository::create([
    'code' => 'BANK_MAIN',
    'name' => 'Main Operating Account',
    'type' => 'bank_account',
    'bank_name' => 'BNP Paribas',
    'iban' => 'FR76 1234 5678 9012 3456 7890 123',
    'bic' => 'BNPAFRPP',
]);

// Virtual box for meal vouchers
PaymentRepository::create([
    'code' => 'VOUCHER_BOX',
    'name' => 'Meal Voucher Collection',
    'type' => 'virtual',
]);
```

---

## Payment Instrument Lifecycle

### Creating an Instrument (Receiving a Check)

```php
$instrument = PaymentInstrument::create([
    'payment_method_id' => $checkMethod->id,
    'reference' => 'CHK-123456',
    'partner_id' => $customer->id,
    'drawer_name' => 'ACME Corporation',
    'amount' => 1500.00,
    'received_date' => now(),
    'maturity_date' => now()->addDays(30),  // PDC
    'status' => 'received',
    'repository_id' => $checkSafe->id,
    'bank_name' => 'Société Générale',
]);

// Log the movement
InstrumentMovement::create([
    'instrument_id' => $instrument->id,
    'from_status' => null,
    'to_status' => 'received',
    'to_repository_id' => $checkSafe->id,
    'reason' => 'Customer payment for INV-2025-0042',
]);
```

### Depositing to Bank

```php
$instrument->update([
    'status' => 'deposited',
    'deposited_at' => now(),
    'deposited_to_id' => $bankAccount->id,
]);

InstrumentMovement::create([
    'instrument_id' => $instrument->id,
    'from_status' => 'received',
    'to_status' => 'deposited',
    'from_repository_id' => $checkSafe->id,
    'to_repository_id' => $bankAccount->id,
    'reason' => 'Bank deposit',
]);
```

### Clearing

```php
$instrument->update([
    'status' => 'cleared',
    'cleared_at' => now(),
]);

// Now create the actual payment that affects accounting
$payment = Payment::create([
    'type' => 'inbound',
    'payment_method_id' => $checkMethod->id,
    'partner_id' => $instrument->partner_id,
    'amount' => $instrument->amount,
    'date' => now(),
    'repository_id' => $bankAccount->id,
]);

// Allocate to invoice
PaymentAllocation::create([
    'payment_id' => $payment->id,
    'document_id' => $invoice->id,
    'amount' => $instrument->amount,
]);

// Link instrument to payment
$instrument->update(['payment_id' => $payment->id]);
```

### Bouncing

```php
$instrument->update([
    'status' => 'bounced',
    'bounced_at' => now(),
    'bounce_reason' => 'Insufficient funds',
    'repository_id' => $checkSafe->id,  // Back in our possession
]);

InstrumentMovement::create([
    'instrument_id' => $instrument->id,
    'from_status' => 'clearing',
    'to_status' => 'bounced',
    'from_repository_id' => $bankAccount->id,
    'to_repository_id' => $checkSafe->id,
    'reason' => 'Returned by bank: Insufficient funds',
]);

// Reverse any payment that was recorded
// Create negative payment or reversal entry
```

---

## Maturity Tracking

For deferred instruments (PDC, Traite), the system must:

1. **Track maturity dates**
   ```php
   // Daily job to find maturing instruments
   $maturingToday = PaymentInstrument::where('status', 'received')
       ->whereDate('maturity_date', today())
       ->get();
   ```

2. **Alert before maturity**
   ```php
   // Instruments maturing in next 7 days
   $upcoming = PaymentInstrument::where('status', 'received')
       ->whereBetween('maturity_date', [today(), today()->addDays(7)])
       ->get();
   ```

3. **Prevent early deposit (configurable)**
   ```php
   // Some businesses can deposit before maturity
   // Others must wait until maturity date
   if ($tenant->settings->enforce_maturity_date) {
       if ($instrument->maturity_date > today()) {
           throw new CannotDepositBeforeMaturityException();
       }
   }
   ```

---

## Reports

### Check Register
List all checks with their current status and location.

```php
PaymentInstrument::query()
    ->where('payment_method.code', 'CHECK')
    ->with(['repository', 'partner'])
    ->orderBy('received_date', 'desc')
    ->get();
```

### PDC Report (Gulf requirement)
Legal report of all post-dated checks.

```php
PaymentInstrument::query()
    ->where('payment_method.has_maturity', true)
    ->whereIn('status', ['received', 'deposited', 'clearing'])
    ->orderBy('maturity_date')
    ->get();
```

### Cash Position
Current balance across all repositories.

```php
PaymentRepository::query()
    ->with('latestBalance')
    ->get()
    ->map(fn($repo) => [
        'name' => $repo->name,
        'type' => $repo->type,
        'balance' => $repo->balance,
    ]);
```

### Aged Receivables with Instruments
Outstanding invoices including instruments not yet cleared.

```php
// Complex query joining:
// - Documents (unpaid invoices)
// - Payment allocations (partial payments)
// - Payment instruments (pending checks/PDCs)
```

---

## Integration with Accounting

### Payment Recording Flow

```
┌─────────────────────────────────────────────────────────────┐
│                    Payment Received                         │
├─────────────────────────────────────────────────────────────┤
│ 1. Create Payment record                                    │
│ 2. Create PaymentAllocation(s) to invoice(s)                │
│ 3. Update invoice.amount_paid, invoice.amount_due           │
│ 4. Update invoice.status if fully paid                      │
│ 5. Create JournalEntry:                                     │
│    - Dr. Bank/Cash (repository account)                     │
│    - Dr. Bank Fees (if any)                                 │
│    - Cr. Customer Receivable                                │
│ 6. Mark journal lines as reconcilable                       │
└─────────────────────────────────────────────────────────────┘
```

### Deferred Instrument Accounting

For instruments with maturity (checks not yet cleared):

```
On Receipt (check received but not deposited):
  Dr. Checks Receivable (asset)
  Cr. Customer Receivable

On Deposit (sent to bank):
  No entry (still in Checks Receivable)

On Clearing (funds confirmed):
  Dr. Bank Account
  Cr. Checks Receivable

On Bounce:
  Dr. Customer Receivable (restore the debt)
  Cr. Checks Receivable
  + Create bounce fee invoice if applicable
```

---

## API Endpoints

```
# Payment Methods
GET    /api/v1/payment-methods
POST   /api/v1/payment-methods
PATCH  /api/v1/payment-methods/{id}

# Repositories
GET    /api/v1/payment-repositories
POST   /api/v1/payment-repositories
PATCH  /api/v1/payment-repositories/{id}
GET    /api/v1/payment-repositories/{id}/balance

# Instruments
GET    /api/v1/payment-instruments
POST   /api/v1/payment-instruments
GET    /api/v1/payment-instruments/{id}
POST   /api/v1/payment-instruments/{id}/deposit
POST   /api/v1/payment-instruments/{id}/clear
POST   /api/v1/payment-instruments/{id}/bounce
POST   /api/v1/payment-instruments/{id}/transfer  # Between repositories

# Payments
GET    /api/v1/payments
POST   /api/v1/payments
GET    /api/v1/payments/{id}
POST   /api/v1/payments/{id}/allocate
DELETE /api/v1/payments/{id}/allocations/{allocationId}

# Reports
GET    /api/v1/treasury/check-register
GET    /api/v1/treasury/pdc-report
GET    /api/v1/treasury/cash-position
GET    /api/v1/treasury/maturing-instruments
```

---

## UI Components

### Payment Method Setup
- Form with toggle switches for each behavior
- Country preset selector
- Fee configuration (type, amount/percentage)
- Linked accounts selection

### Check Register
- Table with status badges
- Filters: status, repository, date range, maturity range
- Actions: Deposit, Transfer, View history

### Payment Recording
- Amount input with currency
- Method selection (filtered by context)
- Repository selection (based on method type)
- Invoice selection for allocation
- Automatic fee calculation display

### Maturity Calendar
- Calendar view of maturing instruments
- Color coding by amount/risk
- Click to view/deposit

---

*Module Version: 1.0*
*Last Updated: November 2025*
