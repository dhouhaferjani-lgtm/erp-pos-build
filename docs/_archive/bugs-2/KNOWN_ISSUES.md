# Known Issues & Bugs

> Last Updated: 2025-12-11
> Status: Under Investigation

This document tracks known bugs and issues that need to be addressed.

---

## BUG-001: Partner Context Not Preserved When Creating Documents

### Summary
When navigating from a Partner detail page to create a new invoice/quote, the partner information is not pre-populated in the document form.

### Severity
**Medium** - UX issue, requires extra clicks

### Steps to Reproduce
1. Navigate to Partners > Select a customer
2. Click "New Invoice" button
3. Document form opens with empty partner selector
4. User must manually search and select the same partner

### Expected Behavior
Partner should be pre-selected in the document form.

### Current State
- **PartnerDetailPage.tsx**: CORRECTLY passes partner context via URL query params
  - For customers: `/sales/invoices/new?customer={partner.id}`
  - For suppliers: `/purchases/orders/new?supplier={partner.id}`
- **DocumentForm.tsx**: DOES NOT read or use the query parameters
  - Form initializes with empty `partner_id`
  - No `useSearchParams()` hook to read URL params

### Root Cause
The `DocumentForm` component ignores the URL query parameters. The infrastructure is in place (links have the right params), but the receiving component doesn't implement the expected behavior.

### Files Affected
| File | Status | Action Needed |
|------|--------|---------------|
| `apps/web/src/features/partners/PartnerDetailPage.tsx` | OK | No changes |
| `apps/web/src/features/documents/DocumentForm.tsx` | BUG | Read query params and pre-populate |

### Fix Implementation
```typescript
// In DocumentForm.tsx
import { useSearchParams } from 'react-router-dom'

// Inside component:
const [searchParams] = useSearchParams()
const customerIdFromUrl = searchParams.get('customer')
const supplierIdFromUrl = searchParams.get('supplier')
const partnerIdFromUrl = customerIdFromUrl || supplierIdFromUrl

// In useEffect after form reset or on mount:
useEffect(() => {
  if (partnerIdFromUrl) {
    setValue('partner_id', partnerIdFromUrl)
  }
}, [partnerIdFromUrl, setValue])
```

### Effort Estimate
Small - Single file change, ~15 lines of code

---

## BUG-002: Payment Method Repository Filtering Not Implemented

### Summary
When selecting a payment method (e.g., "Cash"), all repositories are displayed instead of only compatible ones (e.g., cash registers, safes).

### Severity
**Medium** - Data integrity risk, UX confusion

### Steps to Reproduce
1. Create a new payment
2. Select "Cash" as payment method
3. Repository dropdown shows ALL repositories including bank accounts
4. User can mistakenly select a bank account for a cash payment

### Expected Behavior
- Cash payment → Show only: Cash Registers, Safes
- Card payment → Show only: Bank Accounts, Virtual
- Check payment → Show only: Safes (checks need custody tracking)
- Bank transfer → Show only: Bank Accounts

### Current State

**Database Structure:**
- No direct FK between `payment_methods` and `payment_repositories`
- Implicit relationship via `is_physical` flag and `RepositoryType` enum

**Backend:**
- `PaymentRepositoryController::index()` returns ALL repositories
- No query parameter support for filtering by payment method

**Frontend:**
- `PaymentForm.tsx` fetches all repositories without filtering
- `SplitPaymentForm.tsx` has the same issue

### Root Cause
Filtering logic was never implemented. The data model supports it via:
- `payment_methods.is_physical` (true for cash, checks)
- `payment_methods.has_maturity` (true for checks, bonds)
- `payment_repositories.type` (cash_register, safe, bank_account, virtual)

### Files Affected
| File | Status | Action Needed |
|------|--------|---------------|
| `apps/api/app/Modules/Treasury/Presentation/Controllers/PaymentRepositoryController.php` | Missing | Add optional `?payment_method_id` filter |
| `apps/api/app/Modules/Treasury/Domain/PaymentRepository.php` | OK | Has `scopeOfType()` |
| `apps/api/app/Modules/Treasury/Domain/PaymentMethod.php` | OK | Has `is_physical`, etc. |
| `apps/web/src/features/treasury/PaymentForm.tsx` | Missing | Filter repos based on selected method |
| `apps/web/src/features/treasury/SplitPaymentForm.tsx` | Missing | Same fix needed |

### Proposed Filtering Rules

| Payment Method Type | Flags | Compatible Repositories |
|--------------------|-------|------------------------|
| Cash | `is_physical=true, has_maturity=false` | `cash_register`, `safe` |
| Check | `is_physical=true, has_maturity=true` | `safe` only |
| Bank Transfer | `is_physical=false` | `bank_account`, `virtual` |
| Card | `is_physical=false, requires_third_party=true` | `bank_account`, `virtual` |
| On-Account | `is_physical=false` | `virtual` only |

### Fix Implementation

**Backend (PaymentRepositoryController.php):**
```php
public function index(Request $request): JsonResponse
{
    $query = PaymentRepository::query()
        ->where('tenant_id', $tenantId)
        ->orderBy('name');

    // Optional filtering by payment method
    if ($methodId = $request->query('payment_method_id')) {
        $method = PaymentMethod::findOrFail($methodId);
        $compatibleTypes = $this->getCompatibleRepositoryTypes($method);
        $query->whereIn('type', $compatibleTypes);
    }

    return response()->json(['data' => $query->get()]);
}

private function getCompatibleRepositoryTypes(PaymentMethod $method): array
{
    if ($method->is_physical && $method->has_maturity) {
        return [RepositoryType::Safe]; // Checks need custody
    }
    if ($method->is_physical) {
        return [RepositoryType::CashRegister, RepositoryType::Safe];
    }
    return [RepositoryType::BankAccount, RepositoryType::Virtual];
}
```

**Frontend (PaymentForm.tsx):**
```typescript
const selectedMethodId = watch('payment_method_id')

const { data: repositoriesData } = useQuery({
  queryKey: ['payment-repositories', selectedMethodId],
  queryFn: async () => {
    const params = selectedMethodId ? `?payment_method_id=${selectedMethodId}` : ''
    const response = await api.get(`/payment-repositories${params}`)
    return response.data
  },
  enabled: !!selectedMethodId,
})
```

### Effort Estimate
Medium - Backend filter logic + Frontend reactive query

---

## BUG-003: Prepayments/Deposits on Orders Not Supported

### Summary
When a customer pays a deposit/advance on a sales order before it's converted to an invoice, the system has no proper mechanism to handle this. The current workaround is insufficient.

### Severity
**High** - Missing critical business feature

### Root Cause Analysis

After thorough research on accounting standards in France, Tunisia, and OHADA African countries (see [PREPAYMENT_RESEARCH.md](./PREPAYMENT_RESEARCH.md)), the issue is NOT about "allocating payments to orders."

**The real issue:** The system is missing a **Prepayment Invoice** (Facture d'acompte) document type.

### Legal Requirements

#### France (since January 1, 2023)
- Article 289 CGI: **Mandatory to issue a "Facture d'acompte"** for any prepayment received
- VAT is due immediately upon receipt of prepayment
- Must use account 4191 "Clients - Avances et acomptes reçus"
- Source: [Eurofiscalis](https://www.eurofiscalis.com/tva-sur-les-acomptes-2023-france/)

#### Tunisia
- Uses same PCG-based system as France
- Account 4191 for customer advances
- Similar requirements for prepayment documentation

#### OHADA Countries (17 African nations)
- SYSCOHADA Class 4 handles customer advances
- Treatment as liability until delivery/service completion

### Correct Business Flow

```
1. Quote (Devis)
2. Sales Order (Bon de Commande) - confirmed
3. Customer pays deposit
4. → CREATE PREPAYMENT INVOICE (Facture d'acompte) ← MISSING!
   - Generates fiscal number
   - Records VAT (mandatory in France)
   - GL: Dr 512 Bank, Cr 4191 Customer Advances
5. Work/Delivery happens
6. Final Invoice (Facture de solde)
   - References prepayment invoice
   - Deducts prepayment amount
   - Clears 4191 account
```

### What We Currently Have

The system currently only supports:
- Quote → Order → Invoice → Payment allocation
- No mechanism for deposits between Order and Invoice

### Recommended Solution: Prepayment Invoice Document Type

**Add new document type:** `DocumentType::PrepaymentInvoice` (or `deposit_invoice`)

**Characteristics:**
| Property | Value |
|----------|-------|
| `type` | `prepayment_invoice` |
| `generates_fiscal_number` | Yes |
| `affects_ar` | No (uses 4191, not 411) |
| `requires_vat` | Yes (France) / Country-dependent |
| `linked_to_order` | Yes (via `source_document_id`) |
| `status_flow` | draft → posted (no cancel, only credit note) |

**New GL Account needed:**
- 4191 - Customer Advances and Deposits (Liability)

**Flow:**
1. User creates confirmed Sales Order
2. User clicks "Create Prepayment Invoice" on order
3. System generates fiscal document with VAT
4. Payment is allocated to Prepayment Invoice
5. When creating Final Invoice from Order:
   - System automatically deducts prepayments
   - Clears 4191 against 411
   - Final Invoice shows net amount due

### Files to Create/Modify

| File | Action |
|------|--------|
| `DocumentType.php` | Add `PrepaymentInvoice` case |
| `PrepaymentInvoiceService.php` | New service for handling prepayment invoices |
| `DocumentCreationService.php` | Handle prepayment invoice creation |
| `FinalInvoiceService.php` | Deduct prepayments when creating final invoice |
| `DocumentForm.tsx` | Add prepayment invoice UI |
| `SalesOrderDetailPage.tsx` | Add "Create Prepayment Invoice" button |
| Chart of Accounts seeders | Add account 4191 |

### Why NOT Allocate Payments to Orders

1. **Legal compliance:** France requires a fiscal document (facture d'acompte)
2. **VAT tracking:** Prepayment VAT must be separate and trackable
3. **Audit trail:** Need clear document chain for tax authorities
4. **GL accuracy:** Must use 4191 (liability), not 411 (receivable)
5. **ERP standard:** SAP, Oracle all use separate prepayment documents

### Effort Estimate
**Large** - New document type, services, UI, GL entries

### Interim Workaround

Until implemented, users should:
1. Create the Invoice immediately (skip order confirmation)
2. Or record prepayment as "On Account" payment and manually track

### Related Documentation
- [Prepayment Research](./PREPAYMENT_RESEARCH.md) - Full research document
- [French PCG Account 4191](https://www.l-expert-comptable.com/plan-comptable/compte-4191-clients-avances-et-acomptes-recus-sur-commande)

---

## Summary Table

| Bug ID | Title | Severity | Status | Effort |
|--------|-------|----------|--------|--------|
| BUG-001 | Partner context not preserved | Medium | Ready to fix | Small |
| BUG-002 | Repository filtering missing | Medium | Ready to fix | Medium |
| BUG-003 | Prepayment Invoice missing | High | Researched - Needs new feature | Large |

---

## Next Steps

1. **BUG-001**: Implement query param reading in DocumentForm (quick win)
2. **BUG-002**: Add backend filter + frontend reactive query
3. **BUG-003**: Implement Prepayment Invoice document type (major feature)
   - See [PREPAYMENT_RESEARCH.md](./PREPAYMENT_RESEARCH.md) for full specification

---

## Related Documentation

- [Treasury Module](/docs/TREASURY.md)
- [Document Conversion Flow](/docs/DOCUMENTS.md)
- [Payment Allocation](/apps/api/app/Modules/Treasury/Application/Services/PaymentAllocationService.php)
