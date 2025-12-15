# Schema Hardening Compatibility Report

**Date:** 2025-12-10
**Analyzed by:** Claude Code Opus
**Status:** REQUIRES PLAN ADAPTATION

---

## Executive Summary

The schema hardening plan needs **one critical adaptation** before proceeding:

**The `status` column already exists** with different enum values than planned. The plan must be adjusted to either:
1. Add a separate `fiscal_status` column (Recommended)
2. Map existing statuses to fiscal equivalents

---

## Current State Analysis

### Documents Table Schema

| Column | Exists | Notes |
|--------|--------|-------|
| `status` | YES | Values: Draft, Confirmed, Posted, Paid, Received, Cancelled |
| `fiscal_category` | **NO** | Needs to be added |
| `fiscal_hash` | YES | Already present |
| `previous_hash` | YES | Already present |
| `chain_sequence` | YES | Already present |

### Current Status Enum Values

```php
// app/Modules/Document/Domain/Enums/DocumentStatus.php
enum DocumentStatus: string
{
    case Draft = 'draft';
    case Confirmed = 'confirmed';
    case Posted = 'posted';      // Used to find open invoices
    case Paid = 'paid';          // Set when balance_due = 0
    case Received = 'received';
    case Cancelled = 'cancelled';
}
```

### Planned Status Values (from PHASE-1-SCHEMA-HARDENING.md)

```php
// Planned but CONFLICTS with existing
enum DocumentStatus: string
{
    case DRAFT = 'DRAFT';
    case SEALED = 'SEALED';
    case VOIDED = 'VOIDED';
}
```

---

## Critical Finding: Status Column Conflict

### The Problem

The schema hardening plan assumes a new `status` column with values `DRAFT`, `SEALED`, `VOIDED`. However:

1. **The column already exists** with different values
2. **Payment system depends on it**:
   - `PaymentAllocationService.php:217`: Queries `status = 'posted'` to find open invoices
   - `PaymentAllocationService.php:136`: Sets `status = Paid` when balance_due reaches zero
3. **Changing values would break existing functionality**

### Evidence

```php
// PaymentAllocationService.php line 217
$query = Document::where('company_id', $companyId)
    ->where('partner_id', $partnerId)
    ->where('type', DocumentType::Invoice)
    ->where('status', 'posted')  // <-- Depends on existing status values
    ->whereRaw('total > COALESCE(...)');

// PaymentAllocationService.php line 135-137
if (bccomp($document->balance_due, '0.00', 2) === 0) {
    $document->status = DocumentStatus::Paid;  // <-- Uses existing enum
}
```

---

## Recommended Adaptation

### Option A: Add Separate `fiscal_status` Column (RECOMMENDED)

**Rationale:**
- Non-breaking: Existing `status` flow unchanged
- Clean separation: Operational status vs. Fiscal status
- Future-proof: Can have documents that are "Posted" operationally but "Draft" fiscally

**Implementation:**
```php
// New column
$table->string('fiscal_status', 20)->default('DRAFT')->after('status');

// New enum
enum FiscalStatus: string
{
    case DRAFT = 'DRAFT';
    case SEALED = 'SEALED';
    case VOIDED = 'VOIDED';
}
```

**Mapping Logic:**
| Operational Status | Can Be Fiscal Status |
|-------------------|---------------------|
| Draft, Confirmed | DRAFT |
| Posted, Paid, Received | SEALED |
| Cancelled | VOIDED |

### Option B: Map Existing Statuses (NOT RECOMMENDED)

This would require modifying triggers to understand the mapping:
- `Posted`, `Paid`, `Received` → Treated as "SEALED"
- `Cancelled` → Treated as "VOIDED"
- `Draft`, `Confirmed` → Treated as "DRAFT"

**Problems:**
- Complex trigger logic
- Semantic confusion
- Harder to maintain

---

## Compatibility Matrix

### Schema Changes (With Adaptation)

| Change | Type | Breaking? | Mitigation |
|--------|------|-----------|------------|
| Add `fiscal_category` column | Additive | NO | Default value 'NON_FISCAL' |
| Add `fiscal_status` column | Additive | NO | Default value 'DRAFT' |
| Add CHECK constraints | Constraint | NO | Validates only new insertions |
| Add immutability triggers | Constraint | CAUTION | Must check `fiscal_status` not `status` |
| Create extension tables | Additive | NO | No FK impact on documents |

### Payment System Compatibility

| Operation | Status | Notes |
|-----------|--------|-------|
| Find open invoices | SAFE | Uses `status = 'posted'` (unchanged) |
| Update balance_due | SAFE | Trigger must allow this on SEALED |
| Update status to Paid | SAFE | Operational status still works |
| GL entry creation | SAFE | No status dependency |

### API Compatibility

| Change | Type | Breaking? |
|--------|------|-----------|
| Add `fiscal_category` to response | Additive | NO |
| Add `fiscal_status` to response | Additive | NO |
| Add `is_sealed` computed property | Additive | NO |

---

## Smart Payment Tests Baseline

```
Tests\Feature\Treasury\SmartPaymentIntegrationTest
  PASS: it gets tolerance settings for company
  PASS: it previews fifo allocation for multiple invoices
  PASS: it previews due date allocation prioritizing overdue
  PASS: it previews manual allocation
  PASS: it handles overpayment within tolerance
  PASS: it handles underpayment within tolerance
  PASS: it applies allocation to payment
  PASS: it handles manual allocation with excess
  PASS: it creates customer advance gl entry for excess amount
  PASS: it creates pure advance payment when no open invoices
  PASS: it returns open invoices for partner
  PASS: it returns 404 for non existent partner

Tests: 12 passed (70 assertions)
Duration: 1.58s
```

---

## Updated Implementation Plan

### Phase 1 Modifications Required

1. **Migration 1**: Add `fiscal_category` and `fiscal_status` (not `status`)

2. **Migration 2**: CHECK constraints reference `fiscal_status` not `status`
   ```sql
   -- Original (would break)
   CHECK (status IN ('DRAFT', 'SEALED', 'VOIDED'))

   -- Adapted (works)
   CHECK (fiscal_status IN ('DRAFT', 'SEALED', 'VOIDED'))
   ```

3. **Migration 4**: Immutability triggers check `fiscal_status`
   ```sql
   -- Trigger checks OLD.fiscal_status = 'SEALED' instead of OLD.status
   IF OLD.fiscal_status = 'SEALED' THEN
       -- Check immutable fields
   END IF;
   ```

4. **Enum creation**: Create `FiscalStatus` enum, keep `DocumentStatus` unchanged

5. **Model updates**: Add `fiscal_status` cast, add `is_sealed` accessor
   ```php
   public function getIsSealedAttribute(): bool
   {
       return $this->fiscal_status === FiscalStatus::SEALED;
   }
   ```

---

## Verification Checklist

Before proceeding to Phase 1:

- [x] Current schema documented
- [x] Status column conflict identified
- [x] Adaptation plan proposed (use `fiscal_status`)
- [x] Payment system impact analyzed
- [x] Smart payment tests verified (12/12 passing)
- [x] API compatibility confirmed (additive only)
- [ ] User approval for adapted plan

---

## Decision Required

**Do you approve the adapted plan?**

Option A (Recommended): Add new `fiscal_status` column instead of using existing `status`

This keeps:
- All existing payment functionality unchanged
- Smart payment tests passing
- Clean separation between operational and fiscal status

**Please confirm before I proceed to Phase 1.**

---

## Files Analyzed

- `app/Modules/Document/Domain/Document.php` - Model with existing status
- `app/Modules/Document/Domain/Enums/DocumentStatus.php` - Existing enum
- `app/Modules/Treasury/Application/Services/PaymentAllocationService.php` - Uses status='posted'
- `tests/Feature/Treasury/SmartPaymentIntegrationTest.php` - Baseline tests
- Documents table schema via tinker

---

*Compatibility Report - December 10, 2025*
