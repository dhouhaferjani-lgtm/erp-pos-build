# Smart Payment Features - Implementation Spec v2

## Overview

Build intelligent payment handling that leverages the GL subledger foundation:
- Advance payments (credit balance)
- Overpayment handling (excess → credit)
- Apply credit to invoices
- Refund credit to customer
- **Credit Notes** (distinct from credit balance - tied to invoice corrections)
- Allocation methods: **FIFO** (default), **Due Date Priority**, **Manual Override**
- **Payment Tolerance** (auto-write-off small differences)
- Return comments support

---

## What's New in v2

| Feature | Description |
|---------|-------------|
| **Payment Tolerance** | Auto-write-off small over/underpayments, integrated with Country Adaptation Module |
| **Credit Note Document Type** | Distinct from credit balance - for returns, price adjustments (uses existing `CreditNote` in DocumentType) |
| **Due Date Priority Allocation** | Allocate to most overdue invoices first |
| **Return Comments** | Support reason/comment on return operations |
| **Extensibility Hooks** | Prepared for FX Gain/Loss, Cash Discounts, Disputed Invoices |

---

## Prerequisites ✅

| Component | Status |
|-----------|--------|
| `PaymentType` enum | ✅ Exists |
| `partners.credit_balance` | ✅ Exists |
| `partners.receivable_balance` | ✅ Exists |
| `createCustomerAdvanceJournalEntry()` | ✅ Exists |
| `PartnerBalanceService` | ✅ Exists |
| `journal_lines.partner_id` | ✅ Exists |
| Country Adaptation Module | ✅ Exists |

---

## Part 1: Payment Tolerance (Country Adaptation Integration)

### Concept

Small payment differences (e.g., customer pays 99.95 instead of 100.00) should be auto-written off rather than leaving tiny open balances.

**Rules:**
- Tolerance is applied ONLY if amount is within BOTH percentage AND maximum amount thresholds
- Underpayment tolerance → expense (write-off)
- Overpayment tolerance → income (rounded to our favor)
- Thresholds are country-specific defaults, overridable at company level

### Country Defaults

| Country | Currency | Tolerance % | Max Amount | Underpayment Account | Overpayment Account |
|---------|----------|-------------|------------|---------------------|---------------------|
| TN | TND | 0.5% | 0.100 TND | 658 (Other Charges) | 758 (Other Income) |
| FR | EUR | 0.5% | 0.50 EUR | 658 | 758 |
| IT | EUR | 0.5% | 0.50 EUR | 658 | 758 |
| UK | GBP | 0.5% | 0.50 GBP | 658 | 758 |

### Database: Country Adaptation Extension

**File:** Migration for `country_payment_settings` table

```sql
CREATE TABLE country_payment_settings (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    country_code VARCHAR(2) NOT NULL REFERENCES countries(code),
    
    -- Payment Tolerance
    payment_tolerance_enabled BOOLEAN DEFAULT TRUE,
    payment_tolerance_percentage DECIMAL(5,4) DEFAULT 0.0050,  -- 0.5%
    max_payment_tolerance_amount DECIMAL(15,4) DEFAULT 0.50,
    underpayment_writeoff_purpose VARCHAR(50) DEFAULT 'payment_tolerance_expense',
    overpayment_writeoff_purpose VARCHAR(50) DEFAULT 'payment_tolerance_income',
    
    -- Extensibility: FX (Phase 2)
    realized_fx_gain_purpose VARCHAR(50) DEFAULT 'realized_fx_gain',
    realized_fx_loss_purpose VARCHAR(50) DEFAULT 'realized_fx_loss',
    
    -- Extensibility: Cash Discounts (Phase 2)
    cash_discount_enabled BOOLEAN DEFAULT FALSE,
    sales_discount_purpose VARCHAR(50) DEFAULT 'sales_discount',
    
    created_at TIMESTAMP DEFAULT NOW(),
    updated_at TIMESTAMP DEFAULT NOW(),
    
    UNIQUE(country_code)
);

-- Seed defaults
INSERT INTO country_payment_settings (country_code, max_payment_tolerance_amount) VALUES
('TN', 0.100),
('FR', 0.50),
('IT', 0.50),
('UK', 0.50);
```

### Database: Company Override

**Add to `companies` table or create `company_payment_settings`:**

```sql
ALTER TABLE companies
ADD COLUMN payment_tolerance_enabled BOOLEAN DEFAULT NULL,  -- NULL = use country default
ADD COLUMN payment_tolerance_percentage DECIMAL(5,4) DEFAULT NULL,
ADD COLUMN max_payment_tolerance_amount DECIMAL(15,4) DEFAULT NULL;
```

### SystemAccountPurpose Enum Additions

**File:** `app/Modules/Accounting/Domain/Enums/SystemAccountPurpose.php`

```php
// Add to existing enum:

// Payment Tolerance
case PAYMENT_TOLERANCE_EXPENSE = 'payment_tolerance_expense';   // 658
case PAYMENT_TOLERANCE_INCOME = 'payment_tolerance_income';     // 758

// Extensibility: FX (Phase 2)
case REALIZED_FX_GAIN = 'realized_fx_gain';   // 766
case REALIZED_FX_LOSS = 'realized_fx_loss';   // 666

// Extensibility: Cash Discounts (Phase 2)
case SALES_DISCOUNT = 'sales_discount';       // 709
```

### PaymentToleranceService

**File:** `app/Modules/Treasury/Application/Services/PaymentToleranceService.php`

```php
<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Application\Services;

use App\Modules\Company\Domain\Company;
use App\Modules\Treasury\Domain\CountryPaymentSettings;
use App\Modules\Accounting\Domain\Services\GeneralLedgerService;

class PaymentToleranceService
{
    public function __construct(
        private GeneralLedgerService $glService
    ) {}

    /**
     * Get effective tolerance settings for a company
     * Company settings override country defaults
     */
    public function getToleranceSettings(string $companyId): array
    {
        $company = Company::with('country.paymentSettings')->findOrFail($companyId);
        $countrySettings = $company->country->paymentSettings;
        
        return [
            'enabled' => $company->payment_tolerance_enabled 
                ?? $countrySettings?->payment_tolerance_enabled 
                ?? true,
            'percentage' => $company->payment_tolerance_percentage 
                ?? $countrySettings?->payment_tolerance_percentage 
                ?? '0.0050',
            'max_amount' => $company->max_payment_tolerance_amount 
                ?? $countrySettings?->max_payment_tolerance_amount 
                ?? '0.50',
        ];
    }
    
    /**
     * Check if a payment difference qualifies for auto-write-off
     * 
     * @param string $invoiceAmount Original invoice amount
     * @param string $paymentAmount Amount received
     * @param string $companyId Company ID for settings lookup
     * @return array ['qualifies' => bool, 'difference' => string, 'type' => 'underpayment'|'overpayment'|null]
     */
    public function checkTolerance(
        string $invoiceAmount,
        string $paymentAmount,
        string $companyId
    ): array {
        $settings = $this->getToleranceSettings($companyId);
        
        if (!$settings['enabled']) {
            return ['qualifies' => false, 'difference' => '0', 'type' => null];
        }
        
        $difference = bcsub($paymentAmount, $invoiceAmount, 4);
        $absDifference = bccomp($difference, '0', 4) < 0 
            ? bcmul($difference, '-1', 4) 
            : $difference;
        
        // Check percentage threshold
        $percentageThreshold = bcmul($invoiceAmount, $settings['percentage'], 4);
        
        // Must be within BOTH percentage AND max amount
        $withinPercentage = bccomp($absDifference, $percentageThreshold, 4) <= 0;
        $withinMaxAmount = bccomp($absDifference, $settings['max_amount'], 4) <= 0;
        
        if ($withinPercentage && $withinMaxAmount) {
            $type = bccomp($difference, '0', 4) < 0 ? 'underpayment' : 'overpayment';
            return [
                'qualifies' => true,
                'difference' => $absDifference,
                'type' => $type,
            ];
        }
        
        return ['qualifies' => false, 'difference' => $absDifference, 'type' => null];
    }
    
    /**
     * Apply payment tolerance write-off
     */
    public function applyTolerance(
        string $companyId,
        string $partnerId,
        string $documentId,
        string $amount,
        string $type,  // 'underpayment' or 'overpayment'
        \DateTimeInterface $date
    ): void {
        // Delegate to GL service (injected via constructor)
        $this->glService->createPaymentToleranceJournalEntry(
            companyId: $companyId,
            partnerId: $partnerId,
            documentId: $documentId,
            amount: $amount,
            type: $type,
            date: $date
        );
    }
}
```

---

## Part 2: Credit Note Document Type

### Concept

**Credit Balance (Advance):** Unapplied money sitting on customer account
- Source: Overpayment or advance payment
- GL: Customer Advances (419) - Liability
- Can be applied to ANY future invoice or refunded

**Credit Note:** Document that reduces/reverses a specific invoice
- Source: Returns, price adjustments, billing corrections
- GL: Contra-revenue (709) - reduces sales
- Applied to specific invoice or creates receivable credit

### Document Type Enum Addition

**File:** `app/Modules/Document/Domain/Enums/DocumentType.php`

```php
<?php

declare(strict_types=1);

namespace App\Modules\Document\Domain\Enums;

enum DocumentType: string
{
    case INVOICE = 'invoice';
    case QUOTE = 'quote';
    case DELIVERY_NOTE = 'delivery_note';
    case PURCHASE_ORDER = 'purchase_order';
    // CreditNote already exists - use it for credit notes
    // case CreditNote = 'credit_note';  // ALREADY EXISTS
    case PURCHASE_INVOICE = 'purchase_invoice';
    
    // Extensibility: Phase 3
    // case DEBIT_MEMO = 'debit_memo';
    
    public function label(): string
    {
        return match($this) {
            self::INVOICE => 'Invoice',
            self::QUOTE => 'Quote',
            self::DELIVERY_NOTE => 'Delivery Note',
            self::PURCHASE_ORDER => 'Purchase Order',
            self::CREDIT_NOTE => 'Credit Note',
            self::PURCHASE_INVOICE => 'Purchase Invoice',
        };
    }
    
    public function affectsReceivable(): bool
    {
        return match($this) {
            self::INVOICE => true,       // Increases AR
            self::CREDIT_NOTE => true,   // Decreases AR
            default => false,
        };
    }
    
    public function receivableDirection(): int
    {
        return match($this) {
            self::INVOICE => 1,          // +AR
            self::CREDIT_NOTE => -1,     // -AR
            default => 0,
        };
    }
}
```

### Credit Note Database Schema

**Add columns to `documents` table:**

```sql
ALTER TABLE documents
ADD COLUMN related_document_id UUID REFERENCES documents(id),  -- Links credit note to original invoice
ADD COLUMN credit_note_reason VARCHAR(100),                     -- 'return', 'price_adjustment', 'billing_error', 'other'
ADD COLUMN return_comment TEXT;                                 -- User comment explaining return/credit
```

### CreditNoteReason Enum

**File:** `app/Modules/Document/Domain/Enums/CreditNoteReason.php`

```php
<?php

declare(strict_types=1);

namespace App\Modules\Document\Domain\Enums;

enum CreditNoteReason: string
{
    case RETURN = 'return';
    case PRICE_ADJUSTMENT = 'price_adjustment';
    case BILLING_ERROR = 'billing_error';
    case DAMAGED_GOODS = 'damaged_goods';
    case SERVICE_ISSUE = 'service_issue';
    case OTHER = 'other';
    
    public function label(): string
    {
        return match($this) {
            self::RETURN => 'Product Return',
            self::PRICE_ADJUSTMENT => 'Price Adjustment',
            self::BILLING_ERROR => 'Billing Error',
            self::DAMAGED_GOODS => 'Damaged Goods',
            self::SERVICE_ISSUE => 'Service Issue',
            self::OTHER => 'Other',
        };
    }
    
    public function requiresComment(): bool
    {
        return $this === self::OTHER;
    }
}
```

### Credit Note GL Treatment

**Credit Note for Return (with inventory impact):**
```
Dr. Sales Returns (709)           100.00   -- Contra-revenue
Dr. VAT Collected (4457)           19.00   -- Reverse VAT
    Cr. Accounts Receivable (411) 119.00   -- Reduce what customer owes

(Inventory side - separate entry)
Dr. Inventory (37)                 60.00   -- Goods back in stock
    Cr. COGS (607)                 60.00   -- Reverse cost
```

**Credit Note for Price Adjustment (no inventory):**
```
Dr. Sales Discounts/Allowances    50.00
Dr. VAT Collected                  9.50
    Cr. Accounts Receivable       59.50
```

### GeneralLedgerService Addition

```php
/**
 * Create journal entry for credit note
 */
public function createCreditNoteJournalEntry(
    string $companyId,
    string $partnerId,
    string $creditNoteId,
    string $amount,
    string $taxAmount,
    CreditNoteReason $reason,
    \DateTimeInterface $date,
    ?string $description = null
): JournalEntry {
    $receivableAccount = Account::findByPurposeOrFail($companyId, SystemAccountPurpose::CUSTOMER_RECEIVABLE);
    $salesReturnAccount = Account::findByPurposeOrFail($companyId, SystemAccountPurpose::SALES_RETURN);
    $vatAccount = Account::findByPurposeOrFail($companyId, SystemAccountPurpose::VAT_COLLECTED);
    
    return DB::transaction(function () use (
        $companyId, $partnerId, $creditNoteId, $amount, $taxAmount,
        $reason, $date, $description, $receivableAccount, $salesReturnAccount, $vatAccount
    ) {
        $entry = JournalEntry::create([
            'company_id' => $companyId,
            'entry_date' => $date,
            'reference' => "CM-{$creditNoteId}",
            'description' => $description ?? "Credit note - {$reason->label()}",
            'source_type' => 'credit_note',
            'source_id' => $creditNoteId,
            'status' => 'posted',
        ]);
        
        $totalAmount = bcadd($amount, $taxAmount, 4);
        
        // Debit: Sales Returns (contra-revenue)
        JournalLine::create([
            'journal_entry_id' => $entry->id,
            'account_id' => $salesReturnAccount->id,
            'partner_id' => $partnerId,
            'debit' => $amount,
            'credit' => '0',
            'description' => "Sales return - {$reason->label()}",
        ]);
        
        // Debit: VAT Collected (reverse)
        if (bccomp($taxAmount, '0', 4) > 0) {
            JournalLine::create([
                'journal_entry_id' => $entry->id,
                'account_id' => $vatAccount->id,
                'partner_id' => $partnerId,
                'debit' => $taxAmount,
                'credit' => '0',
                'description' => 'VAT reversed',
            ]);
        }
        
        // Credit: Accounts Receivable
        JournalLine::create([
            'journal_entry_id' => $entry->id,
            'account_id' => $receivableAccount->id,
            'partner_id' => $partnerId,
            'debit' => '0',
            'credit' => $totalAmount,
            'description' => 'Receivable reduced by credit note',
        ]);
        
        return $entry;
    });
}
```

---

## Part 3: Allocation Methods

### AllocationMethod Enum

**File:** `app/Modules/Treasury/Domain/Enums/AllocationMethod.php`

```php
<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Domain\Enums;

enum AllocationMethod: string
{
    case FIFO = 'fifo';                     // First In First Out (by invoice date)
    case DUE_DATE_PRIORITY = 'due_date';    // Most overdue first
    case MANUAL = 'manual';                  // User selects specific invoices
    
    // Extensibility: Could add these later
    // case LIFO = 'lifo';                  // Last In First Out
    // case HAFO = 'hafo';                  // Highest Amount First Out
    // case LAFO = 'lafo';                  // Lowest Amount First Out
    
    public function label(): string
    {
        return match($this) {
            self::FIFO => 'First In First Out',
            self::DUE_DATE_PRIORITY => 'Due Date Priority (Most Overdue First)',
            self::MANUAL => 'Manual Selection',
        };
    }
    
    public function description(): string
    {
        return match($this) {
            self::FIFO => 'Allocates payment to oldest invoices first based on invoice date',
            self::DUE_DATE_PRIORITY => 'Allocates payment to most overdue invoices first based on due date',
            self::MANUAL => 'User manually selects which invoices to pay',
        };
    }
}
```

### Updated PaymentAllocationService

```php
<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Application\Services;

use App\Modules\Accounting\Application\Services\PartnerBalanceService;
use App\Modules\Accounting\Domain\Services\GeneralLedgerService;
use App\Modules\Document\Domain\Document;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Treasury\Domain\Enums\AllocationMethod;
use App\Modules\Treasury\Domain\Enums\AllocationType;
use App\Modules\Treasury\Domain\Enums\PaymentType;
use App\Modules\Treasury\Domain\Payment;
use App\Modules\Treasury\Domain\PaymentAllocation;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class PaymentAllocationService
{
    public function __construct(
        private GeneralLedgerService $glService,
        private PartnerBalanceService $balanceService,
        private PaymentToleranceService $toleranceService
    ) {}
    
    /**
     * Record a payment with smart allocation
     */
    public function recordPayment(
        string $companyId,
        string $partnerId,
        string $amount,
        string $paymentMethodAccountId,
        \DateTimeInterface $date,
        AllocationMethod $allocationMethod = AllocationMethod::FIFO,
        ?array $invoiceAllocations = null,  // For manual allocation
        ?string $reference = null,
        ?string $notes = null
    ): Payment {
        return DB::transaction(function () use (
            $companyId, $partnerId, $amount, $paymentMethodAccountId,
            $date, $allocationMethod, $invoiceAllocations, $reference, $notes
        ) {
            // Determine allocations based on method
            $allocations = match($allocationMethod) {
                AllocationMethod::FIFO => $this->calculateFifoAllocations($companyId, $partnerId, $amount),
                AllocationMethod::DUE_DATE_PRIORITY => $this->calculateDueDateAllocations($companyId, $partnerId, $amount),
                AllocationMethod::MANUAL => $this->validateManualAllocations($invoiceAllocations, $amount),
            };
            
            // Calculate totals using bcmath for money precision
            $totalToInvoices = array_reduce(
                $allocations,
                fn(string $sum, array $a) => bcadd($sum, $a['amount'], 4),
                '0.0000'
            );
            $excessAmount = bcsub($amount, $totalToInvoices, 4);
            
            // Determine payment type
            $paymentType = $this->determinePaymentType($allocations, $excessAmount);
            
            // Create payment record
            $payment = Payment::create([
                'company_id' => $companyId,
                'partner_id' => $partnerId,
                'amount' => $amount,
                'payment_method_account_id' => $paymentMethodAccountId,
                'payment_date' => $date,
                'payment_type' => $paymentType,
                'allocation_method' => $allocationMethod,
                'reference' => $reference,
                'notes' => $notes,
                'status' => 'completed',
            ]);
            
            // Process each allocation
            foreach ($allocations as $alloc) {
                $this->processInvoiceAllocation($payment, $alloc, $date, $companyId);
            }
            
            // Handle excess as advance/credit
            if (bccomp($excessAmount, '0', 4) > 0) {
                // Check if excess qualifies for tolerance write-off
                $toleranceCheck = $this->toleranceService->checkTolerance(
                    $totalToInvoices,
                    $amount,
                    $companyId
                );
                
                if ($toleranceCheck['qualifies'] && $toleranceCheck['type'] === 'overpayment') {
                    // Write off small overpayment
                    $this->toleranceService->applyTolerance(
                        $companyId,
                        $partnerId,
                        $payment->id,
                        $excessAmount,
                        'overpayment',
                        $date
                    );
                } else {
                    // Create credit balance
                    $this->createCreditAllocation($payment, $excessAmount, $paymentMethodAccountId, $date);
                }
            }
            
            // Update document balances
            $this->updateDocumentBalances($allocations);
            
            return $payment;
        });
    }
    
    /**
     * Calculate FIFO allocations - oldest invoices first by invoice date
     */
    public function calculateFifoAllocations(
        string $companyId,
        string $partnerId,
        string $availableAmount
    ): array {
        $openInvoices = Document::where('company_id', $companyId)
            ->where('partner_id', $partnerId)
            ->where('document_type', 'invoice')
            ->where('status', 'posted')
            ->where('balance_due', '>', 0)
            ->orderBy('document_date', 'asc')  // FIFO: oldest invoice date first
            ->orderBy('created_at', 'asc')
            ->get();
        
        return $this->allocateToInvoices($openInvoices, $availableAmount);
    }
    
    /**
     * Calculate Due Date Priority allocations - most overdue first
     */
    public function calculateDueDateAllocations(
        string $companyId,
        string $partnerId,
        string $availableAmount
    ): array {
        $openInvoices = Document::where('company_id', $companyId)
            ->where('partner_id', $partnerId)
            ->where('document_type', 'invoice')
            ->where('status', 'posted')
            ->where('balance_due', '>', 0)
            ->orderBy('due_date', 'asc')       // Most overdue first (earliest due date)
            ->orderBy('document_date', 'asc')  // Then by invoice date
            ->get();
        
        return $this->allocateToInvoices($openInvoices, $availableAmount);
    }
    
    /**
     * Common allocation logic for any sorted invoice list
     */
    private function allocateToInvoices(Collection $invoices, string $availableAmount): array
    {
        $allocations = [];
        $remaining = $availableAmount;
        
        foreach ($invoices as $invoice) {
            if (bccomp($remaining, '0', 4) <= 0) {
                break;
            }
            
            $allocAmount = bccomp($remaining, $invoice->balance_due, 4) >= 0
                ? $invoice->balance_due
                : $remaining;
            
            $allocations[] = [
                'document_id' => $invoice->id,
                'document_number' => $invoice->document_number,
                'document_date' => $invoice->document_date,
                'due_date' => $invoice->due_date,
                'amount' => $allocAmount,
                'original_balance' => $invoice->balance_due,
                'days_overdue' => $invoice->due_date 
                    ? now()->diffInDays($invoice->due_date, false) 
                    : 0,
            ];
            
            $remaining = bcsub($remaining, $allocAmount, 4);
        }
        
        return $allocations;
    }
    
    /**
     * Process single invoice allocation with tolerance check
     */
    private function processInvoiceAllocation(
        Payment $payment, 
        array $alloc, 
        \DateTimeInterface $date,
        string $companyId
    ): void {
        // Check if this creates a small remaining balance that should be written off
        $newBalance = bcsub($alloc['original_balance'], $alloc['amount'], 4);
        
        if (bccomp($newBalance, '0', 4) > 0) {
            // There's a remaining balance - check tolerance
            $toleranceCheck = $this->toleranceService->checkTolerance(
                $alloc['original_balance'],
                $alloc['amount'],
                $companyId
            );
            
            if ($toleranceCheck['qualifies'] && $toleranceCheck['type'] === 'underpayment') {
                // Write off the small underpayment
                $this->toleranceService->applyTolerance(
                    $companyId,
                    $payment->partner_id,
                    $alloc['document_id'],
                    $newBalance,
                    'underpayment',
                    $date
                );
                
                // Adjust allocation to mark as fully paid
                $alloc['amount'] = $alloc['original_balance'];
                $alloc['tolerance_applied'] = $newBalance;
            }
        }
        
        // Create allocation record
        PaymentAllocation::create([
            'payment_id' => $payment->id,
            'document_id' => $alloc['document_id'],
            'amount' => $alloc['amount'],
            'allocation_type' => AllocationType::INVOICE_PAYMENT,
            'tolerance_writeoff' => $alloc['tolerance_applied'] ?? null,
        ]);
        
        // GL entry for this portion
        $this->glService->createPaymentReceivedJournalEntry(
            companyId: $payment->company_id,
            partnerId: $payment->partner_id,
            paymentId: $payment->id,
            amount: $alloc['amount'],
            paymentMethodAccountId: $payment->payment_method_account_id,
            date: $date,
            description: "Payment for {$alloc['document_number']}"
        );
    }
    
    /**
     * Get open invoices for partner with sorting preview
     */
    public function getOpenInvoicesForPartner(
        string $companyId, 
        string $partnerId,
        AllocationMethod $sortMethod = AllocationMethod::FIFO
    ): Collection {
        $query = Document::where('company_id', $companyId)
            ->where('partner_id', $partnerId)
            ->where('document_type', 'invoice')
            ->where('status', 'posted')
            ->where('balance_due', '>', 0);
        
        $query = match($sortMethod) {
            AllocationMethod::FIFO => $query->orderBy('document_date', 'asc'),
            AllocationMethod::DUE_DATE_PRIORITY => $query->orderBy('due_date', 'asc'),
            AllocationMethod::MANUAL => $query->orderBy('document_number', 'asc'),
        };
        
        return $query->get([
            'id', 
            'document_number', 
            'document_date', 
            'due_date',
            'total_ttc', 
            'balance_due'
        ])->map(function ($invoice) {
            $invoice->days_overdue = $invoice->due_date 
                ? now()->diffInDays($invoice->due_date, false) 
                : 0;
            return $invoice;
        });
    }
    
    /**
     * Preview allocation without recording
     */
    public function previewAllocation(
        string $companyId,
        string $partnerId,
        string $amount,
        AllocationMethod $method = AllocationMethod::FIFO
    ): array {
        $allocations = match($method) {
            AllocationMethod::FIFO => $this->calculateFifoAllocations($companyId, $partnerId, $amount),
            AllocationMethod::DUE_DATE_PRIORITY => $this->calculateDueDateAllocations($companyId, $partnerId, $amount),
            AllocationMethod::MANUAL => [],
        };
        
        $totalToInvoices = array_reduce(
            $allocations,
            fn(string $sum, array $a) => bcadd($sum, $a['amount'], 4),
            '0.0000'
        );
        $excess = bcsub($amount, $totalToInvoices, 4);
        
        // Check tolerance on excess
        $toleranceSettings = $this->toleranceService->getToleranceSettings($companyId);
        $excessHandling = 'credit_balance';
        
        if (bccomp($excess, '0', 4) > 0 && $toleranceSettings['enabled']) {
            $withinTolerance = bccomp($excess, $toleranceSettings['max_amount'], 4) <= 0;
            if ($withinTolerance) {
                $excessHandling = 'tolerance_writeoff';
            }
        }
        
        return [
            'allocation_method' => $method->value,
            'allocations' => $allocations,
            'total_to_invoices' => $totalToInvoices,
            'excess_amount' => $excess,
            'excess_handling' => $excessHandling,
            'invoices_fully_paid' => count(array_filter(
                $allocations,
                fn($a) => bccomp($a['amount'], $a['original_balance'], 4) === 0
            )),
            'invoices_partially_paid' => count(array_filter(
                $allocations,
                fn($a) => bccomp($a['amount'], $a['original_balance'], 4) < 0
            )),
            'tolerance_settings' => $toleranceSettings,
        ];
    }
    
    // ... [rest of existing methods: recordAdvancePayment, applyCreditToInvoice, refundCredit, etc.]
}
```

---

## Part 4: Updated Enums

### AllocationType Enum (Updated)

```php
<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Domain\Enums;

enum AllocationType: string
{
    // Standard payment applied to invoice
    case INVOICE_PAYMENT = 'invoice_payment';
    
    // Portion of payment that becomes customer credit (overpayment)
    case CREDIT_ADDITION = 'credit_addition';
    
    // Using existing credit to pay invoice (no money moves)
    case CREDIT_APPLICATION = 'credit_application';
    
    // Credit note applied to invoice
    case CREDIT_NOTE_APPLICATION = 'credit_note_application';
    
    // Small difference written off (tolerance)
    case TOLERANCE_WRITEOFF = 'tolerance_writeoff';
    
    // Extensibility: Phase 2
    // case CASH_DISCOUNT = 'cash_discount';
    // case FX_ADJUSTMENT = 'fx_adjustment';
    
    public function label(): string
    {
        return match($this) {
            self::INVOICE_PAYMENT => 'Invoice Payment',
            self::CREDIT_ADDITION => 'Credit Addition',
            self::CREDIT_APPLICATION => 'Credit Application',
            self::CREDIT_NOTE_APPLICATION => 'Credit Note Application',
            self::TOLERANCE_WRITEOFF => 'Tolerance Write-off',
        };
    }
}
```

### PaymentType Enum (Updated)

```php
<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Domain\Enums;

enum PaymentType: string
{
    case DOCUMENT_PAYMENT = 'document_payment';
    case ADVANCE = 'advance';
    case REFUND = 'refund';
    case CREDIT_APPLICATION = 'credit_application';
    
    // Extensibility: Phase 2-3
    // case CASH_DISCOUNT = 'cash_discount';
    // case FX_ADJUSTMENT = 'fx_adjustment';
    // case DISPUTED_DEDUCTION = 'disputed_deduction';
    // case CLAIM_SETTLEMENT = 'claim_settlement';
    
    public function label(): string
    {
        return match($this) {
            self::DOCUMENT_PAYMENT => 'Document Payment',
            self::ADVANCE => 'Advance Payment',
            self::REFUND => 'Refund',
            self::CREDIT_APPLICATION => 'Credit Application',
        };
    }
}
```

---

## Part 5: Database Migration Summary

### New Tables

```sql
-- Country payment settings (Country Adaptation extension)
CREATE TABLE country_payment_settings (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    country_code VARCHAR(2) NOT NULL REFERENCES countries(code),
    payment_tolerance_enabled BOOLEAN DEFAULT TRUE,
    payment_tolerance_percentage DECIMAL(5,4) DEFAULT 0.0050,
    max_payment_tolerance_amount DECIMAL(15,4) DEFAULT 0.50,
    underpayment_writeoff_purpose VARCHAR(50) DEFAULT 'payment_tolerance_expense',
    overpayment_writeoff_purpose VARCHAR(50) DEFAULT 'payment_tolerance_income',
    -- Extensibility fields
    realized_fx_gain_purpose VARCHAR(50) DEFAULT 'realized_fx_gain',
    realized_fx_loss_purpose VARCHAR(50) DEFAULT 'realized_fx_loss',
    cash_discount_enabled BOOLEAN DEFAULT FALSE,
    sales_discount_purpose VARCHAR(50) DEFAULT 'sales_discount',
    created_at TIMESTAMP DEFAULT NOW(),
    updated_at TIMESTAMP DEFAULT NOW(),
    UNIQUE(country_code)
);
```

### Altered Tables

```sql
-- Companies: payment tolerance overrides
ALTER TABLE companies
ADD COLUMN payment_tolerance_enabled BOOLEAN DEFAULT NULL,
ADD COLUMN payment_tolerance_percentage DECIMAL(5,4) DEFAULT NULL,
ADD COLUMN max_payment_tolerance_amount DECIMAL(15,4) DEFAULT NULL;

-- Documents: credit note support
ALTER TABLE documents
ADD COLUMN related_document_id UUID REFERENCES documents(id),
ADD COLUMN credit_note_reason VARCHAR(100),
ADD COLUMN return_comment TEXT;

-- Documents: ensure due_date exists
ALTER TABLE documents
ADD COLUMN IF NOT EXISTS due_date DATE;

-- Payments: allocation method tracking
ALTER TABLE payments
ADD COLUMN allocation_method VARCHAR(30) DEFAULT 'fifo';

-- Payment allocations: tolerance tracking
ALTER TABLE payment_allocations
ADD COLUMN tolerance_writeoff DECIMAL(15,4);

-- Extensibility: Phase 2 fields (add now but leave null)
ALTER TABLE payments
ADD COLUMN exchange_rate_at_payment DECIMAL(15,6),
ADD COLUMN fx_gain_loss_amount DECIMAL(15,4),
ADD COLUMN discount_taken DECIMAL(15,4);
```

---

## Part 6: API Endpoints

### SmartPaymentController

```php
<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Presentation\Controllers;

use App\Modules\Treasury\Application\Services\PaymentAllocationService;
use App\Modules\Treasury\Application\Services\PaymentToleranceService;
use App\Modules\Treasury\Domain\Enums\AllocationMethod;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SmartPaymentController extends Controller
{
    public function __construct(
        private PaymentAllocationService $allocationService,
        private PaymentToleranceService $toleranceService
    ) {}
    
    /**
     * GET /api/partners/{partnerId}/open-invoices
     * Get open invoices for payment allocation
     */
    public function getOpenInvoices(Request $request, string $partnerId): JsonResponse
    {
        $method = AllocationMethod::tryFrom($request->get('sort', 'fifo')) 
            ?? AllocationMethod::FIFO;
        
        $invoices = $this->allocationService->getOpenInvoicesForPartner(
            companyId: $request->user()->company_id,
            partnerId: $partnerId,
            sortMethod: $method
        );
        
        return response()->json([
            'data' => $invoices,
            'sort_method' => $method->value,
            'available_methods' => collect(AllocationMethod::cases())
                ->map(fn($m) => ['value' => $m->value, 'label' => $m->label()])
        ]);
    }
    
    /**
     * POST /api/partners/{partnerId}/payments/preview
     * Preview payment allocation before confirming
     */
    public function previewPayment(Request $request, string $partnerId): JsonResponse
    {
        $validated = $request->validate([
            'amount' => 'required|numeric|min:0.01',
            'allocation_method' => 'sometimes|string|in:fifo,due_date,manual',
        ]);
        
        $method = AllocationMethod::tryFrom($validated['allocation_method'] ?? 'fifo') 
            ?? AllocationMethod::FIFO;
        
        $preview = $this->allocationService->previewAllocation(
            companyId: $request->user()->company_id,
            partnerId: $partnerId,
            amount: $validated['amount'],
            method: $method
        );
        
        return response()->json($preview);
    }
    
    /**
     * POST /api/partners/{partnerId}/payments
     * Record a payment with allocation
     */
    public function recordPayment(Request $request, string $partnerId): JsonResponse
    {
        $validated = $request->validate([
            'amount' => 'required|numeric|min:0.01',
            'payment_method_account_id' => 'required|uuid|exists:accounts,id',
            'payment_date' => 'required|date',
            'allocation_method' => 'sometimes|string|in:fifo,due_date,manual',
            'allocations' => 'required_if:allocation_method,manual|array',
            'allocations.*.document_id' => 'required_with:allocations|uuid',
            'allocations.*.amount' => 'required_with:allocations|numeric|min:0.01',
            'reference' => 'nullable|string|max:100',
            'notes' => 'nullable|string|max:500',
        ]);
        
        $method = AllocationMethod::tryFrom($validated['allocation_method'] ?? 'fifo') 
            ?? AllocationMethod::FIFO;
        
        $payment = $this->allocationService->recordPayment(
            companyId: $request->user()->company_id,
            partnerId: $partnerId,
            amount: $validated['amount'],
            paymentMethodAccountId: $validated['payment_method_account_id'],
            date: new \DateTime($validated['payment_date']),
            allocationMethod: $method,
            invoiceAllocations: $validated['allocations'] ?? null,
            reference: $validated['reference'] ?? null,
            notes: $validated['notes'] ?? null
        );
        
        return response()->json([
            'data' => $payment->load('allocations'),
            'message' => 'Payment recorded successfully'
        ], 201);
    }
    
    /**
     * GET /api/companies/{companyId}/payment-settings
     * Get payment tolerance settings
     */
    public function getPaymentSettings(Request $request): JsonResponse
    {
        $settings = $this->toleranceService->getToleranceSettings(
            $request->user()->company_id
        );
        
        return response()->json(['data' => $settings]);
    }
    
    // ... [existing endpoints: advance payment, apply credit, refund]
}
```

### Routes

```php
// app/Modules/Treasury/Presentation/routes.php
// (This file is already required by routes/api.php)

Route::prefix('v1')->middleware(['auth:sanctum', 'company'])->group(function () {
    
    // Payment settings
    Route::get('/payment-settings', [SmartPaymentController::class, 'getPaymentSettings']);
    
    // Partner payments
    Route::prefix('partners/{partnerId}')->group(function () {
        Route::get('/open-invoices', [SmartPaymentController::class, 'getOpenInvoices']);
        Route::post('/payments/preview', [SmartPaymentController::class, 'previewPayment']);
        Route::post('/payments', [SmartPaymentController::class, 'recordPayment']);
        Route::post('/payments/advance', [SmartPaymentController::class, 'recordAdvancePayment']);
        Route::post('/payments/apply-credit', [SmartPaymentController::class, 'applyCreditToInvoice']);
        Route::post('/payments/refund', [SmartPaymentController::class, 'refundCredit']);
    });
    
    // Credit notes
    Route::prefix('documents')->group(function () {
        Route::post('/credit-notes', [CreditNoteController::class, 'create']);
        Route::post('/credit-notes/{id}/apply', [CreditNoteController::class, 'applyToInvoice']);
    });
});
```

---

## Part 7: Feature Scenarios (Updated)

### Scenario 1: Payment with Tolerance Write-off (Underpayment)

```
Invoice INV-001 = 100.00 TND
Customer pays 99.95 TND
Tolerance settings: 0.5%, max 0.50 TND

Difference: 0.05 TND (0.05%)
→ Within tolerance (0.05 < 0.50 AND 0.05% < 0.5%)
→ Auto-write-off 0.05 TND

GL Entries:
1. Payment received:
   Dr. Bank                      99.95
       Cr. Accounts Receivable   99.95

2. Tolerance write-off:
   Dr. Payment Tolerance Expense  0.05
       Cr. Accounts Receivable    0.05

Result: INV-001 fully paid (balance = 0)
```

### Scenario 2: Payment with Tolerance Write-off (Overpayment)

```
Invoice INV-001 = 100.00 TND
Customer pays 100.08 TND
Tolerance settings: 0.5%, max 0.50 TND

Difference: 0.08 TND (0.08%)
→ Within tolerance
→ Auto-write-off 0.08 TND as income

GL Entries:
1. Payment for invoice:
   Dr. Bank                      100.00
       Cr. Accounts Receivable   100.00

2. Tolerance write-off (overpayment):
   Dr. Bank                        0.08
       Cr. Payment Tolerance Income 0.08

Result: INV-001 fully paid, no credit balance created
```

### Scenario 3: Due Date Priority Allocation

```
Open invoices:
  INV-001: 200 TND, due Jan 15 (30 days overdue)
  INV-002: 300 TND, due Jan 25 (20 days overdue)
  INV-003: 400 TND, due Feb 01 (13 days overdue)

Customer pays 500 TND with Due Date Priority:
→ INV-001: 200 TND (most overdue - paid first)
→ INV-002: 300 TND (second most overdue - paid)
→ INV-003: 0 TND (least overdue - skipped)
```

### Scenario 4: Credit Note for Return

```
Original Invoice INV-001 = 119.00 TND (100.00 + 19.00 VAT)
Customer returns product worth 59.50 TND (50.00 + 9.50 VAT)

Create Credit Note CM-001:
  Reason: return
  Comment: "Customer returned defective spark plugs"
  Amount: 50.00 TND + 9.50 VAT = 59.50 TND
  Related Document: INV-001

GL Entry:
Dr. Sales Returns (709)           50.00
Dr. VAT Collected (4457)           9.50
    Cr. Accounts Receivable (411) 59.50

Result: 
- INV-001 balance_due reduced from 119.00 to 59.50 TND
- CM-001 created with audit trail and comment
```

---

## Part 8: Extensibility Hooks (Phase 2-3)

### Reserved for FX Gain/Loss (Phase 2)

```php
// In payments table (columns already added)
'exchange_rate_at_payment' => null,  // Rate when payment received
'fx_gain_loss_amount' => null,       // Calculated gain/loss

// In PaymentAllocationService (method stub)
public function calculateFxGainLoss(Payment $payment, Document $invoice): array
{
    // TODO: Phase 2
    // Compare invoice exchange rate vs payment exchange rate
    // Return ['amount' => X, 'type' => 'gain'|'loss']
    throw new \RuntimeException('FX Gain/Loss not yet implemented');
}

// SystemAccountPurpose already has:
// case REALIZED_FX_GAIN = 'realized_fx_gain';
// case REALIZED_FX_LOSS = 'realized_fx_loss';
```

### Reserved for Cash Discounts (Phase 2)

```php
// Database schema ready to add:
// ALTER TABLE documents ADD COLUMN discount_terms VARCHAR(50);  -- e.g., "2/10 Net 30"
// ALTER TABLE documents ADD COLUMN discount_percentage DECIMAL(5,2);
// ALTER TABLE documents ADD COLUMN discount_days INTEGER;

// In PaymentAllocationService (method stub)
public function checkCashDiscountEligibility(Document $invoice, \DateTimeInterface $paymentDate): array
{
    // TODO: Phase 2
    // Check if payment is within discount window
    // Return ['eligible' => bool, 'discount_amount' => X]
    throw new \RuntimeException('Cash Discounts not yet implemented');
}

// SystemAccountPurpose already has:
// case SALES_DISCOUNT = 'sales_discount';
```

### Reserved for Disputed Invoices (Phase 3)

```php
// Future table: deduction_claims
// For now, use credit note with reason='dispute' + return_comment

// Alternative approach using existing structure:
// 1. Create credit note with reason = 'billing_error' or 'other'
// 2. Add detailed explanation in return_comment
// 3. Link to original invoice via related_document_id

// Full dispute workflow (Phase 3):
// - DeductionClaim model
// - Investigation workflow
// - Claim approval/denial
// - Settlement methods (credit, chargeback, write-off)
```

---

## Part 9: Testing Strategy

### Test Cases for Payment Tolerance

```php
/** @test */
public function underpayment_within_tolerance_is_auto_written_off(): void
{
    // Invoice: 100.00, Payment: 99.95, Tolerance: 0.5% / 0.50 max
    // Expected: Invoice fully paid, 0.05 written off to expense
}

/** @test */
public function underpayment_exceeding_tolerance_leaves_balance(): void
{
    // Invoice: 100.00, Payment: 98.00, Tolerance: 0.5% / 0.50 max
    // Expected: Invoice partially paid, balance_due = 2.00
}

/** @test */
public function overpayment_within_tolerance_is_auto_written_off(): void
{
    // Invoice: 100.00, Payment: 100.30, Tolerance: 0.5% / 0.50 max
    // Expected: Invoice fully paid, 0.30 written off to income
}

/** @test */
public function overpayment_exceeding_tolerance_creates_credit(): void
{
    // Invoice: 100.00, Payment: 110.00, Tolerance: 0.5% / 0.50 max
    // Expected: Invoice paid, 10.00 credit balance created
}

/** @test */
public function company_tolerance_override_takes_precedence(): void
{
    // Country default: 0.5%, Company override: 0.1%
    // Expected: Company setting used
}
```

### Test Cases for Allocation Methods

```php
/** @test */
public function fifo_allocates_by_invoice_date(): void
{
    // INV-001 (Jan 1), INV-002 (Jan 15), INV-003 (Feb 1)
    // Payment covers INV-001 + INV-002
    // Expected: INV-001 paid first, then INV-002
}

/** @test */
public function due_date_priority_allocates_by_due_date(): void
{
    // INV-001 (due Feb 15), INV-002 (due Jan 15), INV-003 (due Jan 30)
    // Payment covers two invoices
    // Expected: INV-002 paid first (most overdue), then INV-003
}

/** @test */
public function manual_allocation_respects_user_selection(): void
{
    // User selects INV-003 only, even though INV-001 is oldest
    // Expected: Only INV-003 paid
}
```

### Test Cases for Credit Notes

```php
/** @test */
public function credit_note_reduces_invoice_balance(): void
{
    // INV-001: 119.00, CM-001: 59.50 (linked to INV-001)
    // Expected: INV-001 balance_due = 59.50
}

/** @test */
public function credit_note_requires_reason(): void
{
    // Create credit note without reason
    // Expected: Validation error
}

/** @test */
public function credit_note_other_reason_requires_comment(): void
{
    // Create credit note with reason='other', no comment
    // Expected: Validation error
}

/** @test */
public function credit_note_creates_correct_gl_entries(): void
{
    // Verify Dr. Sales Returns, Dr. VAT, Cr. AR
}
```

---

## Implementation Phases

| Phase | Description |
|-------|-------------|
| 1 | Migrations + Enums |
| 2 | PaymentToleranceService + Country Adaptation |
| 3 | AllocationMethod + Updated PaymentAllocationService |
| 4 | Credit Note Enhancements + GL methods |
| 5 | SmartPaymentController + Routes |
| 6 | Tests |

---

## Verification Commands

```bash
echo "=== SMART PAYMENT FEATURES V2 VERIFICATION ==="

echo -e "\n1. Enums:"
php artisan tinker --execute="
use App\Modules\Treasury\Domain\Enums\AllocationMethod;
use App\Modules\Treasury\Domain\Enums\AllocationType;
use App\Modules\Document\Domain\Enums\CreditNoteReason;

echo 'AllocationMethod: ' . count(AllocationMethod::cases()) . ' cases' . PHP_EOL;
echo 'AllocationType: ' . count(AllocationType::cases()) . ' cases' . PHP_EOL;
echo 'CreditNoteReason: ' . count(CreditNoteReason::cases()) . ' cases' . PHP_EOL;
"

echo -e "\n2. Services:"
php artisan tinker --execute="
use App\Modules\Treasury\Application\Services\PaymentToleranceService;
use App\Modules\Treasury\Application\Services\PaymentAllocationService;
use ReflectionClass;

\$tol = new ReflectionClass(PaymentToleranceService::class);
echo 'PaymentToleranceService methods: ' . count(\$tol->getMethods()) . PHP_EOL;

\$alloc = new ReflectionClass(PaymentAllocationService::class);
\$methods = ['calculateFifoAllocations', 'calculateDueDateAllocations', 'previewAllocation'];
foreach (\$methods as \$m) {
    echo \$m . '(): ' . (\$alloc->hasMethod(\$m) ? '✓' : '✗') . PHP_EOL;
}
"

echo -e "\n3. Country Payment Settings:"
php artisan tinker --execute="
use App\Modules\Treasury\Domain\CountryPaymentSettings;
echo 'Countries with settings: ' . CountryPaymentSettings::count() . PHP_EOL;
"

echo -e "\n4. Routes:"
php artisan route:list | grep -E "open-invoices|payments|credit-notes"

echo -e "\n5. Tests:"
php artisan test --filter=PaymentTolerance
php artisan test --filter=PaymentAllocation
php artisan test --filter=CreditNote

echo -e "\n6. PHPStan:"
./vendor/bin/phpstan analyse app/Modules/Treasury --level=8
```

---

## Commit Strategy

```
feat(country-adaptation): add payment tolerance settings per country
feat(treasury): add PaymentToleranceService
feat(treasury): add AllocationMethod enum with FIFO/DueDate/Manual
feat(treasury): update PaymentAllocationService with tolerance and methods
feat(document): add CreditNote document type and CreditNoteReason enum
feat(accounting): add credit note and tolerance GL methods
feat(treasury): add SmartPaymentController v2 with allocation preview
test(treasury): add payment tolerance tests
test(treasury): add allocation method tests
test(document): add credit note tests
docs: update SMART-PAYMENT-FEATURES-SPEC to v2
```
