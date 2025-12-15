# Phase 0: Compatibility Analysis

**Duration:** 2 hours  
**Priority:** CRITICAL - Must complete before schema changes  
**Prerequisites:** Smart payments frontend implementation complete

---

## Objective

Ensure that schema hardening changes will not break the smart payments frontend currently being implemented. Document all API contracts, identify dependencies, and create a compatibility matrix.

---

## Step 0.1: Document Current API Contracts (45 min)

### Task 0.1.1: Extract Document-Related Endpoints

```bash
# List all routes that return or modify documents
php artisan route:list --path=documents > /tmp/document-routes.txt
php artisan route:list --path=payments > /tmp/payment-routes.txt
php artisan route:list --path=invoices > /tmp/invoice-routes.txt

cat /tmp/document-routes.txt /tmp/payment-routes.txt /tmp/invoice-routes.txt
```

**Expected Output:**
```
GET    /api/companies/{company}/documents
GET    /api/companies/{company}/documents/{document}
POST   /api/companies/{company}/documents
PUT    /api/companies/{company}/documents/{document}
DELETE /api/companies/{company}/documents/{document}
GET    /api/companies/{company}/partners/{partner}/open-invoices
POST   /api/companies/{company}/partners/{partner}/payments
POST   /api/companies/{company}/partners/{partner}/payments/preview
POST   /api/companies/{company}/partners/{partner}/apply-credit
POST   /api/companies/{company}/partners/{partner}/refund
```

**Document each endpoint:**

| Endpoint | Returns Document? | Modifies Document? | Fields Used |
|----------|-------------------|-------------------|-------------|
| `GET .../open-invoices` | ✅ Yes (array) | ❌ No | `id`, `document_number`, `document_date`, `balance_due`, `total_amount` |
| `POST .../payments` | ❌ No | ✅ Yes (balance_due) | Updates `balance_due` field |
| `GET .../documents/{id}` | ✅ Yes (single) | ❌ No | All document fields |

---

### Task 0.1.2: Extract Current API Response Schemas

```bash
# Create a test to capture actual API responses
php artisan tinker --execute="
// Get a sample document
\$doc = App\Models\Document::first();
if (\$doc) {
    echo 'Sample Document JSON:' . PHP_EOL;
    echo json_encode(\$doc->toArray(), JSON_PRETTY_PRINT) . PHP_EOL;
}
"
```

**Sample Output (current schema):**
```json
{
  "id": "uuid-here",
  "tenant_id": "tenant-uuid",
  "company_id": "company-uuid",
  "location_id": "location-uuid",
  "document_type": "invoice",
  "document_number": "INV-2024-001",
  "document_date": "2024-12-01",
  "total_amount": "1500.00",
  "tax_amount": "300.00",
  "balance_due": "1500.00",
  "currency_code": "TND",
  "partner_id": "partner-uuid",
  "reference": "PO-123",
  "notes": "Sample invoice",
  "hash": "abc123...",
  "previous_hash": "def456...",
  "created_at": "2024-12-01T10:00:00Z",
  "updated_at": "2024-12-01T10:00:00Z"
}
```

**Document this as the "Current API Contract v1.0"**

---

### Task 0.1.3: Identify Frontend Dependencies

**Questions to answer:**

1. **What fields does the smart payments frontend read from documents?**
   - Check frontend code for `document.field_name` usage
   - Look for TypeScript interfaces defining Document type

2. **What validations does frontend perform?**
   - Look for client-side validation logic
   - Check for field requirements (required/optional)

3. **What user actions modify documents?**
   - Payment recording (updates `balance_due`)
   - Invoice creation
   - Credit application

**Create a dependency table:**

| Frontend Feature | Document Fields Read | Document Fields Written | Validation Logic |
|-----------------|---------------------|------------------------|------------------|
| Invoice List | `id`, `document_number`, `document_date`, `balance_due`, `total_amount` | None | None |
| Payment Recording | `id`, `balance_due` | `balance_due` (via API) | `amount <= balance_due` |
| Payment Preview | `id`, `document_number`, `balance_due`, `document_date` | None | None |
| Credit Application | `id`, `balance_due` | `balance_due` (via API) | `amount <= credit_balance` |

---

## Step 0.2: Create Compatibility Matrix (45 min)

### Schema Changes Impact Analysis

| Schema Change | Type | Current State | After Change | Breaking? | Mitigation |
|--------------|------|---------------|--------------|-----------|------------|
| Add `fiscal_category` column | Additive | Column doesn't exist | Column exists with default 'NON_FISCAL' | ❌ No | Backend sets default, API can omit initially |
| Add `status` column | Additive | Column doesn't exist | Column exists with default 'DRAFT' | ❌ No | Backend sets default, API can omit initially |
| Add CHECK constraint | Constraint | No validation | DB validates fiscal docs | ❌ No | Only affects fiscal documents (properly filled) |
| Add immutability trigger | Constraint | No restriction | Cannot modify sealed docs | ⚠️ Caution | Payments only update `balance_due` (allowed) |
| Create extension tables | Additive | Tables don't exist | New tables created | ❌ No | No FK constraints on documents table |

---

### API Changes Impact Analysis

| API Change | Type | Current Response | After Change | Breaking? | Mitigation |
|-----------|------|-----------------|--------------|-----------|------------|
| Include `fiscal_category` in response | Additive | Field absent | Field present | ❌ No | Frontend can ignore unknown fields |
| Include `status` in response | Additive | Field absent | Field present | ❌ No | Frontend can ignore unknown fields |
| Include `is_sealed` computed field | Additive | Field absent | Field present | ❌ No | Frontend can ignore unknown fields |
| Include `can_receive_payment` computed field | Additive | Field absent | Field present | ❌ No | Helpful for UI logic |
| New validation errors | Error handling | Generic errors | Specific error codes | ⚠️ Caution | Frontend should handle gracefully |

---

### Frontend Impact Analysis

**CRITICAL: Payment Flow**

```typescript
// Current payment flow (simplified)
async function recordPayment(invoice: Document, amount: number) {
  const response = await api.post('/payments', {
    amount,
    document_id: invoice.id,
    // ...
  });
  
  // Refresh invoice list
  refetchInvoices();
}
```

**After schema hardening:**
```typescript
// Payment flow (UNCHANGED)
async function recordPayment(invoice: Document, amount: number) {
  try {
    const response = await api.post('/payments', {
      amount,
      document_id: invoice.id,
      // ...
    });
    
    // Refresh invoice list (now includes status badges)
    refetchInvoices();
  } catch (error) {
    // NEW: Handle specific error codes
    if (error.code === 'DOCUMENT_SEALED') {
      showError('Cannot modify sealed document');
    } else if (error.code === 'FISCAL_MANDATORY_FIELDS_MISSING') {
      showError('Missing required fields');
    } else {
      showError('Payment failed');
    }
  }
}
```

**Compatibility Assessment:** ✅ Non-breaking (payment flow unchanged, new error handling additive)

---

## Step 0.3: Test Current Payment System (30 min)

### Create a baseline test suite to validate current behavior

```bash
# Create test file
cat > tests/Feature/PaymentSystemBaselineTest.php << 'EOF'
<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Document;
use App\Models\Partner;
use Illuminate\Foundation\Testing\RefreshDatabase;

class PaymentSystemBaselineTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function can_fetch_open_invoices_for_partner(): void
    {
        $partner = Partner::factory()->create();
        $invoice = Document::factory()->create([
            'partner_id' => $partner->id,
            'document_type' => 'invoice',
            'balance_due' => '500.00',
            'status' => 'posted',
        ]);

        $response = $this->getJson("/api/companies/{$invoice->company_id}/partners/{$partner->id}/open-invoices");

        $response->assertOk();
        $response->assertJsonStructure([
            'data' => [
                '*' => [
                    'id',
                    'document_number',
                    'document_date',
                    'balance_due',
                    'total_amount',
                ]
            ]
        ]);
    }

    /** @test */
    public function can_record_payment_and_update_balance_due(): void
    {
        $partner = Partner::factory()->create();
        $invoice = Document::factory()->create([
            'partner_id' => $partner->id,
            'document_type' => 'invoice',
            'balance_due' => '500.00',
            'total_amount' => '500.00',
        ]);

        $response = $this->postJson("/api/companies/{$invoice->company_id}/partners/{$partner->id}/payments", [
            'amount' => '250.00',
            'payment_method_account_id' => 'bank-account-uuid',
            'payment_date' => now()->toDateString(),
            'use_fifo' => true,
        ]);

        $response->assertCreated();
        
        $invoice->refresh();
        $this->assertEquals('250.00', $invoice->balance_due);
    }

    /** @test */
    public function payment_preview_shows_allocation(): void
    {
        $partner = Partner::factory()->create();
        Document::factory()->create([
            'partner_id' => $partner->id,
            'balance_due' => '200.00',
            'document_date' => now()->subDays(2),
        ]);
        Document::factory()->create([
            'partner_id' => $partner->id,
            'balance_due' => '300.00',
            'document_date' => now()->subDays(1),
        ]);

        $response = $this->postJson("/api/companies/company-id/partners/{$partner->id}/payments/preview", [
            'amount' => '350.00',
        ]);

        $response->assertOk();
        $response->assertJsonStructure([
            'total_to_invoices',
            'excess_to_credit',
            'invoices_fully_paid',
            'invoices_partially_paid',
        ]);
    }
}
EOF

# Run baseline tests
php artisan test tests/Feature/PaymentSystemBaselineTest.php
```

**Expected Result:** All tests pass ✅

**Document results:**
```
PaymentSystemBaselineTest
  ✓ can fetch open invoices for partner
  ✓ can record payment and update balance due
  ✓ payment preview shows allocation

Tests: 3 passed
```

**These tests will be re-run after schema hardening to confirm non-breaking changes.**

---

## Step 0.4: Document Compatibility Conclusion (10 min)

### Compatibility Report

**Assessment Date:** [Fill in date]  
**Reviewed By:** Claude Code Opus  
**Status:** ✅ APPROVED for Phase 1

---

### Summary

| Category | Assessment | Confidence |
|----------|-----------|------------|
| **Schema Changes** | Non-breaking (additive only) | High |
| **API Changes** | Non-breaking (additive fields) | High |
| **Payment System** | Compatible (balance_due updates allowed) | High |
| **Frontend Impact** | Minimal (ignore new fields initially) | High |
| **Rollback Risk** | Low (atomic migrations) | High |

---

### Key Findings

1. **Schema changes are additive:**
   - New columns (`fiscal_category`, `status`) have defaults
   - No existing columns modified or removed
   - No breaking type changes

2. **Payment system remains functional:**
   - Trigger explicitly allows `balance_due` updates on sealed docs
   - Payment allocation service doesn't seal documents (GL posting does)
   - FIFO allocation logic unaffected

3. **API contract preserved:**
   - All existing fields remain in responses
   - New fields are optional additions
   - Frontend can ignore unknown fields initially

4. **Frontend integration straightforward:**
   - TypeScript interfaces need additive updates
   - Status badges can be added incrementally
   - Error handling additive (new error codes)

---

### Recommendations

✅ **Proceed with Phase 1 (Schema Hardening)**

**Precautions:**
1. Run baseline tests before Phase 1
2. Re-run baseline tests after Phase 1
3. Monitor payment API endpoints after deployment
4. Coordinate with frontend team for Phase 3 integration

---

### Sign-off

- [ ] Compatibility matrix completed
- [ ] Baseline tests pass
- [ ] API contracts documented
- [ ] Frontend dependencies identified
- [ ] Non-breaking strategy confirmed

**Approved by:** _________________ (Claude Code Opus)  
**Date:** _________________

---

## Verification Commands

```bash
# After completing Phase 0
echo "=== PHASE 0 VERIFICATION ==="

# 1. Confirm baseline tests exist
test -f tests/Feature/PaymentSystemBaselineTest.php && echo "✓ Baseline tests created" || echo "✗ Baseline tests missing"

# 2. Run baseline tests
php artisan test tests/Feature/PaymentSystemBaselineTest.php --stop-on-failure

# 3. Document current schema
php artisan tinker --execute="
use Illuminate\Support\Facades\Schema;
\$columns = Schema::getColumnListing('documents');
echo 'Current documents columns: ' . implode(', ', \$columns) . PHP_EOL;
"

# 4. Save schema snapshot for comparison
php artisan schema:dump > /tmp/schema-before-hardening.sql

echo "✓ Phase 0 complete - Ready for Phase 1"
```

---

**Next Phase:** Phase 1 - Schema Hardening

**Blockers:** None (all prerequisites met)

**Estimated Start:** After smart payments frontend completion + Opus approval
