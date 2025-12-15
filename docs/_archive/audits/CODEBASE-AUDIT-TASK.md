# AutoERP Codebase Audit

## Objective

Before implementing new features, audit the current codebase to establish what **actually exists**. There's a mismatch between documentation/specs and reality.

**Output:** Create `docs/CODEBASE-AUDIT-REPORT.md` with findings.

---

## Phase 1: Module Structure

### 1.1 What Modules Exist?

```bash
# List all modules
ls -la app/Modules/

# Get structure of each module
for dir in app/Modules/*/; do
    echo "=== $dir ==="
    find "$dir" -type f -name "*.php" | head -20
done
```

### 1.2 Document Each Module

For each module found, record:
- Module name
- Subfolders (Domain/, Application/, Presentation/, Infrastructure/)
- Key models
- Key services
- Key enums

---

## Phase 2: Core Models Location

### 2.1 Find Key Models

```bash
# Country
find app -name "Country.php" -type f 2>/dev/null

# Company
find app -name "Company.php" -type f 2>/dev/null

# Partner
find app -name "Partner.php" -type f 2>/dev/null

# Document
find app -name "Document.php" -type f 2>/dev/null

# Account
find app -name "Account.php" -type f 2>/dev/null

# Payment
find app -name "Payment.php" -type f 2>/dev/null

# JournalEntry
find app -name "JournalEntry.php" -type f 2>/dev/null
```

### 2.2 Check Global Models

```bash
# Are there models in app/Models/?
ls -la app/Models/ 2>/dev/null
```

---

## Phase 3: Enums Inventory

### 3.1 Find All Enums

```bash
# Find all enum files
find app -name "*.php" -exec grep -l "^enum " {} \; 2>/dev/null

# Or search for enum declarations
grep -r "^enum " app/ --include="*.php" -l
```

### 3.2 Document Key Enums

For each enum, record its cases:

```bash
# DocumentType
grep -A 30 "enum DocumentType" app/ -r --include="*.php"

# SystemAccountPurpose
grep -A 50 "enum SystemAccountPurpose" app/ -r --include="*.php"

# PaymentType
grep -A 20 "enum PaymentType" app/ -r --include="*.php"

# AllocationType
grep -A 20 "enum AllocationType" app/ -r --include="*.php"
```

---

## Phase 4: Document Types & Credit Notes

### 4.1 What Document Types Exist?

```bash
# Find DocumentType enum
grep -A 30 "enum DocumentType" app/ -r --include="*.php"

# Search for credit note references
grep -ri "credit.note\|creditnote\|credit_note" app/ --include="*.php" | head -30

# Search for credit memo references  
grep -ri "credit.memo\|creditmemo\|credit_memo" app/ --include="*.php" | head -30
```

### 4.2 Document Model Structure

```bash
# Check Document model fields
grep -A 100 "class Document" app/Modules/Document/Domain/Document.php 2>/dev/null | head -120

# Check documents table migration
find database/migrations -name "*document*" -exec cat {} \;
```

### 4.3 Existing Credit Note Fields?

Check if these fields already exist on Document:
- `related_document_id`
- `credit_note_reason` or similar
- `return_comment`

```bash
# Check migration for these columns
grep -r "related_document\|credit.*reason\|return_comment" database/migrations/
```

---

## Phase 5: Country & Company Structure

### 5.1 Country Model

```bash
# Find and examine Country model
find app -name "Country.php" -type f -exec cat {} \;

# Check for country relationships
grep -r "country" app/Modules/*/Domain/*.php --include="*.php" | grep -i "function\|belongsTo\|hasMany"
```

### 5.2 Company Model

```bash
# Find and examine Company model
find app -name "Company.php" -type f -exec cat {} \;

# Check company relationships
grep -r "company" app/Modules/*/Domain/*.php --include="*.php" | grep -i "function\|belongsTo\|hasMany" | head -20
```

### 5.3 Country Adaptation

```bash
# Does CountryAdaptation module exist?
ls -la app/Modules/CountryAdaptation/ 2>/dev/null

# Any country-specific config tables?
grep -r "country.*setting\|country.*config" database/migrations/

# SystemAccountPurpose - how are accounts mapped to countries?
grep -r "SystemAccountPurpose\|account.*purpose" app/ --include="*.php" | head -20
```

---

## Phase 6: Accounting & GL Structure

### 6.1 GL Services

```bash
# Find GeneralLedgerService
find app -name "GeneralLedgerService.php" -type f

# List its methods
grep -E "public function" app/Modules/Accounting/Domain/Services/GeneralLedgerService.php 2>/dev/null
```

### 6.2 Account Lookup Pattern

```bash
# How are accounts looked up?
grep -r "findByPurpose\|findByCode\|Account::where" app/ --include="*.php" | head -20
```

### 6.3 Partner Balance

```bash
# Find PartnerBalanceService
find app -name "PartnerBalanceService.php" -type f

# How is balance refreshed?
grep -r "refreshPartnerBalance\|updateBalance\|recalculateBalance" app/ --include="*.php"
```

---

## Phase 7: Treasury Module

### 7.1 Current Payment Structure

```bash
# What's in Treasury module?
ls -la app/Modules/Treasury/ 2>/dev/null
find app/Modules/Treasury -name "*.php" 2>/dev/null

# Payment model
cat app/Modules/Treasury/Domain/Payment.php 2>/dev/null | head -80

# PaymentAllocation model
cat app/Modules/Treasury/Domain/PaymentAllocation.php 2>/dev/null | head -80
```

### 7.2 Existing Payment Services

```bash
# What services exist?
ls -la app/Modules/Treasury/Application/Services/ 2>/dev/null
ls -la app/Modules/Treasury/Domain/Services/ 2>/dev/null

# PaymentAllocationService methods
grep -E "public function" app/Modules/Treasury/Application/Services/PaymentAllocationService.php 2>/dev/null
```

### 7.3 Payment Enums

```bash
# PaymentType cases
grep -A 20 "enum PaymentType" app/Modules/Treasury/Domain/Enums/PaymentType.php 2>/dev/null

# AllocationType cases
grep -A 20 "enum AllocationType" app/Modules/Treasury/Domain/Enums/AllocationType.php 2>/dev/null
```

---

## Phase 8: Database Schema

### 8.1 Key Tables

```bash
# List recent migrations
ls -la database/migrations/ | tail -30

# Check for key tables
grep -l "create.*companies\|create.*countries\|create.*documents\|create.*payments\|create.*journal" database/migrations/
```

### 8.2 Current Schema Check

```bash
# If possible, check actual DB schema
php artisan tinker --execute="
\$tables = ['companies', 'countries', 'documents', 'payments', 'payment_allocations', 'journal_entries', 'accounts'];
foreach (\$tables as \$t) {
    echo \$t . ': ' . (Schema::hasTable(\$t) ? '✓' : '✗') . PHP_EOL;
}
"
```

---

## Phase 9: Existing Tests

### 9.1 Test Structure

```bash
# What test directories exist?
ls -la tests/Feature/
ls -la tests/Unit/

# Key test files
find tests -name "*Payment*" -o -name "*Document*" -o -name "*GL*" -o -name "*Journal*" 2>/dev/null
```

### 9.2 Test Coverage Hints

```bash
# What's being tested?
grep -r "function test_\|/** @test" tests/ --include="*.php" | wc -l
```

---

## Phase 10: Routes & Controllers

### 10.1 Route Files

```bash
# Module routes
find app/Modules -name "routes.php" -exec echo "=== {} ===" \; -exec cat {} \;

# Main API routes
cat routes/api.php | head -100
```

### 10.2 Controllers Location

```bash
# Are controllers in Presentation/ or Http/?
find app/Modules -type d -name "Controllers"
find app/Modules -type d -name "Presentation"
find app/Modules -type d -name "Http"
```

---

## Output: CODEBASE-AUDIT-REPORT.md

Create `docs/CODEBASE-AUDIT-REPORT.md` with this structure:

```markdown
# AutoERP Codebase Audit Report

**Date:** [today]
**Audited by:** Claude Code (Opus)

## Executive Summary

[2-3 sentences on overall findings]

## Module Inventory

| Module | Location | Status |
|--------|----------|--------|
| Accounting | app/Modules/Accounting/ | ✓ Exists |
| Treasury | app/Modules/Treasury/ | ✓ Exists |
| CountryAdaptation | - | ✗ Does not exist |
| ... | ... | ... |

## Model Locations

| Model | Expected Location | Actual Location |
|-------|-------------------|-----------------|
| Country | Modules/CountryAdaptation/Domain/ | app/Models/Country.php |
| Company | Modules/Company/Domain/ | app/Models/Company.php |
| ... | ... | ... |

## Enum Inventory

### DocumentType
- Location: `app/Modules/Document/Domain/Enums/DocumentType.php`
- Cases: INVOICE, CREDIT_NOTE, QUOTE, ...

### SystemAccountPurpose
- Location: `...`
- Cases: CUSTOMER_RECEIVABLE, BANK, ...
- Missing for Smart Payment: PAYMENT_TOLERANCE_EXPENSE, PAYMENT_TOLERANCE_INCOME, ...

## Credit Note Analysis

- Document type exists: Yes/No
- Current enum value: `CREDIT_NOTE` / `credit_note`
- Has related_document_id: Yes/No
- Has reason field: Yes/No
- Has GL integration: Yes/No

## Country/Company Structure

- Country model location: `...`
- Company model location: `...`
- Company→Country relationship: Yes/No
- Country-specific settings table: Yes/No

## Gaps for Smart Payment Implementation

| Required | Status | Notes |
|----------|--------|-------|
| CountryPaymentSettings model | ✗ Missing | Need to create |
| AllocationMethod enum | ✗ Missing | Need to create |
| PaymentToleranceService | ✗ Missing | Need to create |
| CreditNoteReason enum | ? | Check if exists |
| ... | ... | ... |

## Recommendations

1. [Specific recommendation]
2. [Specific recommendation]
3. ...

## Spec Updates Required

The Smart Payment spec needs these corrections:

| In Spec | Should Be |
|---------|-----------|
| `Modules/CountryAdaptation/` | `app/Models/` or create module |
| `DocumentType::CREDIT_MEMO` | `DocumentType::CREDIT_NOTE` |
| ... | ... |
```

---

## After Audit

Once the audit is complete:

1. **Share the report** with me (Houssam can paste it or I can read it)
2. **I'll update the Smart Payment spec** to match reality
3. **Then proceed with implementation** using accurate information

---

## Execution Command

```bash
cd /path/to/autoerp
claude

> Read this audit task and execute it systematically. 
> Create docs/CODEBASE-AUDIT-REPORT.md with your findings.
> Do NOT make any changes to the codebase - this is read-only audit.
```
