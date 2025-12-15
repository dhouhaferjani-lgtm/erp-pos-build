# Payment System Pre-Flight Audit

> **For Claude Code**
> **Purpose:** Assess current state before implementing Smart Payment Modal
> **Output:** Create `docs/audits/PAYMENT-SYSTEM-AUDIT.md` with findings

---

## Instructions

Run each section in order. Document findings in the output file. Do NOT implement anything yet — this is reconnaissance only.

**Create output file first:**

```bash
mkdir -p docs/audits
cat > docs/audits/PAYMENT-SYSTEM-AUDIT.md << 'EOF'
# Payment System Audit Report

Generated: $(date)

## Executive Summary

| Area | Status | Notes |
|------|--------|-------|
| Payment Model | ⬜ | |
| Payment Allocation | ⬜ | |
| Customer Balance/Ledger | ⬜ | |
| Document Payment Status | ⬜ | |
| Payment Methods | ⬜ | |
| Payment Repositories | ⬜ | |
| Event Sourcing | ⬜ | |

---

EOF
```

---

## Part 1: Core Models Assessment

### 1.1 Payment Model

```bash
echo "## Part 1: Core Models" >> docs/audits/PAYMENT-SYSTEM-AUDIT.md
echo "" >> docs/audits/PAYMENT-SYSTEM-AUDIT.md
echo "### 1.1 Payment Model" >> docs/audits/PAYMENT-SYSTEM-AUDIT.md
echo "" >> docs/audits/PAYMENT-SYSTEM-AUDIT.md

# Find Payment model
find apps/api -name "Payment.php" -type f | head -5

# If found, show structure
if [ -f "apps/api/app/Modules/Treasury/Domain/Models/Payment.php" ]; then
    echo "**Location:** \`apps/api/app/Modules/Treasury/Domain/Models/Payment.php\`" >> docs/audits/PAYMENT-SYSTEM-AUDIT.md
    echo "" >> docs/audits/PAYMENT-SYSTEM-AUDIT.md
    echo '```php' >> docs/audits/PAYMENT-SYSTEM-AUDIT.md
    cat apps/api/app/Modules/Treasury/Domain/Models/Payment.php >> docs/audits/PAYMENT-SYSTEM-AUDIT.md
    echo '```' >> docs/audits/PAYMENT-SYSTEM-AUDIT.md
else
    echo "⚠️ Payment model not found at expected location" >> docs/audits/PAYMENT-SYSTEM-AUDIT.md
    # Search elsewhere
    find apps/api -name "Payment.php" -type f 2>/dev/null
fi
```

**Document these fields if they exist:**
- [ ] `id`, `company_id`, `partner_id`
- [ ] `payment_type` (enum: document_payment, advance, refund, credit_application?)
- [ ] `payment_method_id` (FK to PaymentMethod)
- [ ] `payment_repository_id` (FK to bank/cash register)
- [ ] `amount`, `currency`
- [ ] `payment_date`, `due_date`
- [ ] `reference`, `notes`
- [ ] `status` (pending, completed, voided?)
- [ ] Timestamps, soft deletes

### 1.2 Payment Allocation Model

```bash
echo "" >> docs/audits/PAYMENT-SYSTEM-AUDIT.md
echo "### 1.2 Payment Allocation Model" >> docs/audits/PAYMENT-SYSTEM-AUDIT.md
echo "" >> docs/audits/PAYMENT-SYSTEM-AUDIT.md

# Search for allocation model
find apps/api -name "*Allocation*.php" -type f | grep -i payment
find apps/api -name "*PaymentLine*.php" -type f

# Check migrations for allocation table
find apps/api/database/migrations -name "*payment*" -type f | xargs grep -l "allocation\|document_id" 2>/dev/null
```

**Key questions:**
- [ ] Does `PaymentAllocation` model exist?
- [ ] Does it link payments to documents (invoices/orders)?
- [ ] Can one payment have multiple allocations (split across invoices)?
- [ ] Can one document have multiple allocations (partial payments)?
- [ ] Is allocation amount stored (not calculated)?

### 1.3 Customer Balance / Credit Model

```bash
echo "" >> docs/audits/PAYMENT-SYSTEM-AUDIT.md
echo "### 1.3 Customer Balance / Credit" >> docs/audits/PAYMENT-SYSTEM-AUDIT.md
echo "" >> docs/audits/PAYMENT-SYSTEM-AUDIT.md

# Search for customer balance tracking
grep -r "balance" apps/api/app/Modules/Partner --include="*.php" -l 2>/dev/null
grep -r "credit" apps/api/app/Modules/Partner --include="*.php" -l 2>/dev/null
grep -r "CustomerCredit\|PartnerCredit\|CustomerBalance" apps/api --include="*.php" -l 2>/dev/null

# Check Partner model for balance field
find apps/api -name "Partner.php" -path "*/Models/*" | xargs grep -A 50 "class Partner" | head -80

# Check for ledger entries
find apps/api -name "*Ledger*.php" -type f
find apps/api -name "*Entry*.php" -type f | grep -i "journal\|ledger\|account"
```

**Key questions:**
- [ ] Is customer balance stored on Partner model?
- [ ] Is it calculated on-the-fly (BAD) or persisted (GOOD)?
- [ ] Is there a CustomerLedger or similar for audit trail?
- [ ] Can customers have credit (positive balance)?
- [ ] Can customers have debt (negative balance = open invoices)?

### 1.4 Document Payment Status

```bash
echo "" >> docs/audits/PAYMENT-SYSTEM-AUDIT.md
echo "### 1.4 Document Payment Status" >> docs/audits/PAYMENT-SYSTEM-AUDIT.md
echo "" >> docs/audits/PAYMENT-SYSTEM-AUDIT.md

# Check Document model for payment fields
find apps/api -name "Document.php" -path "*/Models/*" | xargs grep -E "amount_paid|payment_status|residual|balance" 2>/dev/null

# Check for payment status enum
find apps/api -name "*PaymentStatus*.php" -type f
grep -r "PaymentStatus\|payment_status" apps/api/app/Modules/Document --include="*.php" -l 2>/dev/null
```

**Key questions:**
- [ ] Does Document have `amount_paid` field (persisted)?
- [ ] Does Document have `amount_residual` field (persisted or calculated)?
- [ ] Does Document have `payment_status` (unpaid, partial, paid)?
- [ ] How is payment status updated when payment is recorded?

---

## Part 2: Payment Method & Repository

### 2.1 Payment Methods

```bash
echo "" >> docs/audits/PAYMENT-SYSTEM-AUDIT.md
echo "## Part 2: Payment Methods & Repositories" >> docs/audits/PAYMENT-SYSTEM-AUDIT.md
echo "" >> docs/audits/PAYMENT-SYSTEM-AUDIT.md
echo "### 2.1 Payment Methods" >> docs/audits/PAYMENT-SYSTEM-AUDIT.md
echo "" >> docs/audits/PAYMENT-SYSTEM-AUDIT.md

# Find PaymentMethod model
find apps/api -name "PaymentMethod.php" -type f

# Check for type field
find apps/api -name "PaymentMethod.php" -type f | xargs grep -E "type|category" 2>/dev/null

# Check for seeder/enum
grep -r "cash\|check\|bank_transfer\|card" apps/api/database/seeders --include="*.php" | head -20
find apps/api -name "*PaymentMethodType*.php" -type f
```

**Document:**
- [ ] PaymentMethod model location
- [ ] Fields: id, name, type, is_active, etc.
- [ ] Does it have a `type` enum (cash, check, transfer, card, online)?
- [ ] Is it company-scoped or global?

### 2.2 Payment Repositories (Bank Accounts, Cash Registers)

```bash
echo "" >> docs/audits/PAYMENT-SYSTEM-AUDIT.md
echo "### 2.2 Payment Repositories" >> docs/audits/PAYMENT-SYSTEM-AUDIT.md
echo "" >> docs/audits/PAYMENT-SYSTEM-AUDIT.md

# Find repository/account models
find apps/api -name "*BankAccount*.php" -type f
find apps/api -name "*CashRegister*.php" -type f
find apps/api -name "*PaymentRepository*.php" -type f
find apps/api -name "*Safe*.php" -type f | grep -v "SafeString\|Unsafe"

# Check Treasury module structure
ls -la apps/api/app/Modules/Treasury/Domain/Models/ 2>/dev/null || echo "Treasury/Domain/Models not found"
```

**Document:**
- [ ] Is there a unified `PaymentRepository` model?
- [ ] Or separate `BankAccount` and `CashRegister` models?
- [ ] Do they have a `type` field?
- [ ] How are they linked to payments?

---

## Part 3: Current Payment Flow

### 3.1 Payment Controller & Routes

```bash
echo "" >> docs/audits/PAYMENT-SYSTEM-AUDIT.md
echo "## Part 3: Current Payment Flow" >> docs/audits/PAYMENT-SYSTEM-AUDIT.md
echo "" >> docs/audits/PAYMENT-SYSTEM-AUDIT.md
echo "### 3.1 Payment Routes" >> docs/audits/PAYMENT-SYSTEM-AUDIT.md
echo "" >> docs/audits/PAYMENT-SYSTEM-AUDIT.md

# Find payment routes
grep -r "payment" apps/api/app/Modules/Treasury/Presentation/routes.php 2>/dev/null || \
grep -r "payment" apps/api/routes/api.php 2>/dev/null | head -20

# Find payment controller
find apps/api -name "*PaymentController*.php" -type f
```

### 3.2 Payment Service

```bash
echo "" >> docs/audits/PAYMENT-SYSTEM-AUDIT.md
echo "### 3.2 Payment Service" >> docs/audits/PAYMENT-SYSTEM-AUDIT.md
echo "" >> docs/audits/PAYMENT-SYSTEM-AUDIT.md

# Find payment service
find apps/api -name "*PaymentService*.php" -type f

# Show service methods if found
find apps/api -name "*PaymentService*.php" -type f | xargs grep -E "public function" 2>/dev/null
```

**Document existing methods:**
- [ ] `store()` / `create()` / `recordPayment()`
- [ ] `allocateToDocument()`
- [ ] `applyCredit()`
- [ ] `void()` / `cancel()`

### 3.3 Current Frontend Payment Flow

```bash
echo "" >> docs/audits/PAYMENT-SYSTEM-AUDIT.md
echo "### 3.3 Frontend Payment Components" >> docs/audits/PAYMENT-SYSTEM-AUDIT.md
echo "" >> docs/audits/PAYMENT-SYSTEM-AUDIT.md

# Find payment-related components
find apps/web/src -name "*Payment*.tsx" -type f
find apps/web/src -name "*payment*.ts" -type f

# Check invoice detail page for payment button
grep -r "payment\|Payment" apps/web/src/features/documents --include="*.tsx" -l 2>/dev/null
```

**Document:**
- [ ] Does PaymentModal exist?
- [ ] Does PaymentForm exist?
- [ ] How does "Record Payment" button work currently?
- [ ] Is it a page navigation or modal?

---

## Part 4: Event Sourcing & Audit Trail

### 4.1 Payment Events

```bash
echo "" >> docs/audits/PAYMENT-SYSTEM-AUDIT.md
echo "## Part 4: Event Sourcing & Audit Trail" >> docs/audits/PAYMENT-SYSTEM-AUDIT.md
echo "" >> docs/audits/PAYMENT-SYSTEM-AUDIT.md
echo "### 4.1 Payment Events" >> docs/audits/PAYMENT-SYSTEM-AUDIT.md
echo "" >> docs/audits/PAYMENT-SYSTEM-AUDIT.md

# Find payment-related events
find apps/api -name "*Payment*Event*.php" -type f
find apps/api -path "*Events*" -name "*.php" | xargs grep -l "Payment" 2>/dev/null

# Check for event store
find apps/api -name "*EventStore*.php" -type f
find apps/api -name "*StoredEvent*.php" -type f
```

**Document:**
- [ ] `PaymentRecorded` event exists?
- [ ] `PaymentAllocated` event exists?
- [ ] `PaymentVoided` event exists?
- [ ] Are events stored in event store?

### 4.2 Accounting Integration

```bash
echo "" >> docs/audits/PAYMENT-SYSTEM-AUDIT.md
echo "### 4.2 Accounting Integration" >> docs/audits/PAYMENT-SYSTEM-AUDIT.md
echo "" >> docs/audits/PAYMENT-SYSTEM-AUDIT.md

# Check for journal entries on payment
grep -r "JournalEntry\|journal\|ledger" apps/api/app/Modules/Treasury --include="*.php" -l 2>/dev/null
grep -r "AccountingService\|postToAccounting" apps/api/app/Modules/Treasury --include="*.php" -l 2>/dev/null
```

**Document:**
- [ ] Do payments create journal entries?
- [ ] Double-entry: Debit Cash/Bank, Credit Receivables?
- [ ] Is accounting module integrated?

---

## Part 5: Database Schema

### 5.1 Payment Tables

```bash
echo "" >> docs/audits/PAYMENT-SYSTEM-AUDIT.md
echo "## Part 5: Database Schema" >> docs/audits/PAYMENT-SYSTEM-AUDIT.md
echo "" >> docs/audits/PAYMENT-SYSTEM-AUDIT.md
echo "### 5.1 Payment Tables" >> docs/audits/PAYMENT-SYSTEM-AUDIT.md
echo "" >> docs/audits/PAYMENT-SYSTEM-AUDIT.md

# Find payment migrations
find apps/api/database/migrations -name "*payment*" -type f | sort

# Show migration content
for f in $(find apps/api/database/migrations -name "*payment*" -type f | sort); do
    echo "#### $f"
    cat "$f"
    echo ""
done
```

### 5.2 Partner/Customer Balance Fields

```bash
echo "" >> docs/audits/PAYMENT-SYSTEM-AUDIT.md
echo "### 5.2 Partner Balance Fields" >> docs/audits/PAYMENT-SYSTEM-AUDIT.md
echo "" >> docs/audits/PAYMENT-SYSTEM-AUDIT.md

# Check partner migrations for balance fields
find apps/api/database/migrations -name "*partner*" -type f | xargs grep -E "balance|credit|receivable" 2>/dev/null
```

---

## Part 6: Gap Analysis

Based on findings, identify what's missing for the requirements:

### Required Architecture

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                         CUSTOMER ACCOUNT                                     │
│  ┌─────────────────────────────────────────────────────────────────────────┐│
│  │  Partner                                                                 ││
│  │  - credit_balance (PERSISTED, updated via events)                       ││
│  │  - receivable_balance (PERSISTED, open invoice total)                   ││
│  └─────────────────────────────────────────────────────────────────────────┘│
│                                    │                                         │
│          ┌─────────────────────────┴─────────────────────────┐              │
│          ▼                                                   ▼              │
│  ┌───────────────────┐                           ┌───────────────────┐      │
│  │  CustomerLedger   │                           │    Documents      │      │
│  │  (Audit Trail)    │                           │  (Invoices, etc)  │      │
│  │  ─────────────────│                           │  ─────────────────│      │
│  │  - entry_type:    │                           │  - total_amount   │      │
│  │    advance_payment│                           │  - amount_paid    │      │
│  │    credit_used    │                           │  - amount_residual│      │
│  │    refund_given   │                           │  - payment_status │      │
│  │  - amount         │                           └───────────────────┘      │
│  │  - balance_after  │                                    │                 │
│  │  - reference      │                                    │                 │
│  └───────────────────┘                                    │                 │
│                                                           │                 │
└───────────────────────────────────────────────────────────┼─────────────────┘
                                                            │
                              ┌──────────────────────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────────────────────┐
│                            PAYMENTS                                          │
│  ┌─────────────────────────────────────────────────────────────────────────┐│
│  │  Payment                                                                 ││
│  │  - payment_type: document_payment | advance | refund | credit_use       ││
│  │  - partner_id                                                           ││
│  │  - payment_method_id                                                    ││
│  │  - payment_repository_id                                                ││
│  │  - amount, currency                                                     ││
│  │  - status: completed | voided                                           ││
│  └─────────────────────────────────────────────────────────────────────────┘│
│                                    │                                         │
│                                    ▼                                         │
│  ┌─────────────────────────────────────────────────────────────────────────┐│
│  │  PaymentAllocation                                                       ││
│  │  - payment_id                                                           ││
│  │  - document_id (nullable - null for advance payments)                   ││
│  │  - amount                                                               ││
│  │  - allocation_type: invoice_payment | credit_application                ││
│  └─────────────────────────────────────────────────────────────────────────┘│
└─────────────────────────────────────────────────────────────────────────────┘
```

### Checklist: What Exists vs What's Needed

```markdown
| Component | Exists? | Notes |
|-----------|---------|-------|
| **Payment Model** | | |
| - payment_type enum | ⬜ | Needed for advance vs document payment |
| - status with void | ⬜ | |
| **PaymentAllocation Model** | | |
| - Links payment → document | ⬜ | |
| - Supports multiple allocations | ⬜ | |
| **CustomerLedger Model** | | |
| - Audit trail for balance changes | ⬜ | CRITICAL for compliance |
| - entry_type enum | ⬜ | |
| **Partner Balance Fields** | | |
| - credit_balance (persisted) | ⬜ | |
| - receivable_balance (persisted) | ⬜ | |
| **Document Payment Fields** | | |
| - amount_paid (persisted) | ⬜ | |
| - amount_residual (persisted) | ⬜ | |
| - payment_status | ⬜ | |
| **Payment Methods** | | |
| - type enum (cash/check/etc) | ⬜ | |
| **Payment Repositories** | | |
| - Unified model | ⬜ | |
| - type enum | ⬜ | |
| - Method → Repository filter | ⬜ | |
| **Events** | | |
| - PaymentRecorded | ⬜ | |
| - PaymentAllocated | ⬜ | |
| - CreditApplied | ⬜ | |
| - PaymentVoided | ⬜ | |
```

---

## Part 7: Recommendations

Based on the audit, fill in:

### 7.1 Critical Gaps (Must Fix Before Modal)

List items that MUST exist before payment modal can work:

1. 
2. 
3. 

### 7.2 Missing Infrastructure

List infrastructure that needs to be built:

1. 
2. 
3. 

### 7.3 Suggested Implementation Order

1. 
2. 
3. 

---

## Final Output

After completing all sections, the audit file should be comprehensive.

**Commit the audit:**

```bash
git add docs/audits/PAYMENT-SYSTEM-AUDIT.md
git commit -m "docs: payment system pre-flight audit

Assess current state before implementing Smart Payment Modal"
```

**Then STOP and report findings to user.**

Do NOT proceed with implementation until audit is reviewed.
