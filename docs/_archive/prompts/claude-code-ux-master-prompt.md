# AutoERP Pre-Fork UX Fixes — Master Task

> **Model:** Claude Code (Opus 4.5)
> **Objective:** Fix all critical UX issues before forking into AutoERP and BossCloud repositories.
> **Working Style:** Systematic, one issue at a time, with progress tracking.

---

## Your First Action

Before doing anything else, create a progress tracking file:

```bash
cat > docs/UX-FIXES-PROGRESS.md << 'EOF'
# UX Fixes Progress Tracker

**Started:** $(date +%Y-%m-%d)
**Last Updated:** $(date +%Y-%m-%d %H:%M)

## Overall Status

| # | Issue | Status | Notes |
|---|-------|--------|-------|
| 1 | Payment Context Loss | ⬜ Not Started | |
| 2 | Credit Note Context Loss | ⬜ Not Started | |
| 3 | Search Improvements | ⬜ Not Started | |
| 4 | Context-Aware Creation | ⬜ Not Started | |
| 5 | Translation Completeness | ⬜ Not Started | |

## Status Legend
- ⬜ Not Started
- 🔍 Investigating
- 🔧 In Progress
- ✅ Complete
- ⚠️ Blocked

---

## Detailed Progress

### Issue 1: Payment Context Loss
- [ ] Pre-flight check complete
- [ ] Root cause identified
- [ ] PaymentModal exists/created
- [ ] PaymentForm accepts prefill
- [ ] InvoiceDetailPage wired up
- [ ] Manual test passed

### Issue 2: Credit Note Context Loss
- [ ] Pre-flight check complete
- [ ] Backend conversion method exists/created
- [ ] API endpoint exists/created
- [ ] Frontend triggers conversion
- [ ] Line items copied correctly
- [ ] Manual test passed

### Issue 3: Search Improvements
- [ ] Pre-flight check complete
- [ ] Partner combobox implemented
- [ ] Product search case-insensitive
- [ ] Manual test passed

### Issue 4: Context-Aware Creation
- [ ] Pre-flight check complete
- [ ] Partner type auto-detection working
- [ ] Auto-select after create working
- [ ] Manual test passed

### Issue 5: Translation Completeness
- [ ] Audit complete
- [ ] Missing keys identified
- [ ] Translations added
- [ ] No English strings in French mode

---

## Session Log

### $(date +%Y-%m-%d)
- Started UX fixes task
EOF
```

**Update this file as you complete each step.** Change status emojis and check boxes as you progress. Add notes to the session log.

---

## Guiding Principles

1. **Context Preservation:** Users should never lose their place or re-enter data they were just looking at.
2. **Optimistic UI:** Pre-fill data whenever possible.
3. **One Issue at a Time:** Complete and verify each issue before moving to the next.
4. **Update Progress:** After completing each sub-task, update `docs/UX-FIXES-PROGRESS.md`.

---

## Issue 1: Payment Context Loss

### Problem
Clicking "Record Payment" on an Invoice navigates away to a blank Treasury form. User loses Partner, Amount, Reference.

### Pre-Flight Check

```bash
echo "=== Issue 1: Payment Context Loss ==="
echo ""
echo "1. Finding payment modal components..."
find apps/web/src -name "*Payment*Modal*" -o -name "*PaymentModal*" 2>/dev/null

echo ""
echo "2. Finding where Record Payment is triggered..."
grep -rn "Record.*Payment\|recordPayment" apps/web/src/features/documents --include="*.tsx" 2>/dev/null | head -10

echo ""
echo "3. Checking PaymentForm for prefill support..."
grep -n "prefill\|defaultValues\|partner_id" apps/web/src/features/treasury/components/PaymentForm.tsx 2>/dev/null | head -10

echo ""
echo "4. Checking invoice detail page actions..."
ls -la apps/web/src/features/documents/pages/ 2>/dev/null
grep -n "Payment" apps/web/src/features/documents/pages/*Invoice* 2>/dev/null | head -10
```

**Document findings in the progress file before proceeding.**

### Required Implementation

Based on your findings, ensure:

#### A. PaymentModal Component Exists

Path: `apps/web/src/features/treasury/components/PaymentModal.tsx`

Must accept these props:
```typescript
interface PaymentModalProps {
  open: boolean;
  onClose: () => void;
  onSuccess: () => void;
  prefill: {
    partner_id: number;
    partner_name: string;
    amount: number;          // amount_residual, NOT total
    reference: string;       // Invoice number
    document_id: number;
    document_type: 'invoice' | 'sales_order';
  };
}
```

#### B. PaymentForm Accepts Prefill

The form must:
- Accept `prefill` prop and populate fields on mount
- Accept `lockPartner` prop to disable partner field when pre-filled
- Include hidden fields for `document_id` and `document_type`

#### C. InvoiceDetailPage Uses Modal

The invoice page must:
- Have state: `const [showPaymentModal, setShowPaymentModal] = useState(false);`
- Button triggers: `onClick={() => setShowPaymentModal(true)}`
- Modal receives prefill with `amount_residual` (not `total_amount`)
- On success: invalidate queries, show toast, modal closes automatically

### Verification

```bash
# Type check
cd apps/web && npm run typecheck 2>&1 | tail -20

# Lint
cd apps/web && npm run lint 2>&1 | tail -20
```

**Manual Test:**
1. Navigate to a posted invoice with a balance
2. Click "Record Payment"
3. Verify: Modal opens (no page navigation)
4. Verify: Partner is pre-filled and locked
5. Verify: Amount shows remaining balance
6. Verify: Reference shows invoice number
7. Save payment
8. Verify: Modal closes, invoice refreshes, status updates

**Update progress file with results.**

---

## Issue 2: Credit Note Context Loss

### Problem
"Create Credit Note" from an Invoice opens a blank form. User must manually re-select customer and re-add every line item.

### Pre-Flight Check

```bash
echo "=== Issue 2: Credit Note Context Loss ==="
echo ""
echo "1. Checking for credit note in DocumentType enum..."
grep -rn "CreditNote\|credit_note" apps/api/app/Modules/Document/Domain --include="*.php" | head -10

echo ""
echo "2. Checking conversion service..."
grep -n "creditNote\|CreditNote" apps/api/app/Modules/Document/Application/Services/DocumentConversionService.php 2>/dev/null | head -10

echo ""
echo "3. Checking for credit note route..."
grep -rn "credit-note\|creditNote" apps/api/app/Modules/Document/Presentation/routes.php 2>/dev/null

echo ""
echo "4. Frontend credit note handling..."
grep -rn "creditNote\|CreditNote\|credit.*note" apps/web/src/features/documents --include="*.tsx" | head -10
```

**Document findings in the progress file before proceeding.**

### Required Implementation

#### A. Backend Conversion Method

Path: `apps/api/app/Modules/Document/Application/Services/DocumentConversionService.php`

Add method `createCreditNoteFromInvoice(Document $invoice): Document` that:
- Validates source is a posted invoice
- Creates new Document with type CreditNote, status Draft
- Copies partner_id, currency, etc. from source
- Sets source_document_id to link back to invoice
- Copies ALL line items (user will delete what they don't want)
- Calculates totals
- Returns the new credit note

#### B. API Endpoint

Path: `apps/api/app/Modules/Document/Presentation/routes.php`

Add route:
```php
Route::post('/documents/{document}/credit-note', [DocumentController::class, 'createCreditNote'])
    ->middleware('can:documents.create');
```

#### C. Controller Method

Path: `apps/api/app/Modules/Document/Presentation/Controllers/DocumentController.php`

Add method that:
- Calls conversion service
- Returns the new credit note data

#### D. Frontend Integration

In InvoiceDetailPage, the "Create Credit Note" button should:
- Call `POST /documents/{id}/credit-note`
- Navigate to the new credit note's edit page
- Show success toast

### Verification

```bash
# Run backend tests
cd apps/api && php artisan test --filter=CreditNote 2>&1 | tail -20

# If no specific tests, run document tests
cd apps/api && php artisan test --filter=Document 2>&1 | tail -20
```

**Manual Test:**
1. Navigate to a posted invoice with line items
2. Click "Create Credit Note"
3. Verify: Redirected to new credit note (draft status)
4. Verify: Same customer as source invoice
5. Verify: ALL line items are pre-loaded
6. Delete one line item, adjust quantity of another
7. Save credit note
8. Verify: Linked to source invoice

**Update progress file with results.**

---

## Issue 3: Search Improvements

### Problem A: Customer dropdown is a scroll list (unusable with many customers)
### Problem B: Product search is case-sensitive ("air" doesn't find "Air Filter")

### Pre-Flight Check

```bash
echo "=== Issue 3: Search Improvements ==="
echo ""
echo "1. Finding partner/customer selector..."
find apps/web/src -name "*.tsx" -exec grep -l "partner.*select\|Partner.*Select\|customer.*select" {} \; 2>/dev/null | head -5

echo ""
echo "2. Finding product selector..."
find apps/web/src -name "*.tsx" -exec grep -l "product.*select\|Product.*Combo\|ProductSearch" {} \; 2>/dev/null | head -5

echo ""
echo "3. Checking backend partner search..."
grep -n "search\|LIKE\|like\|where.*name" apps/api/app/Modules/Partner/Presentation/Controllers/*.php 2>/dev/null | head -10

echo ""
echo "4. Checking backend product search..."
grep -n "search\|LIKE\|like\|where.*name" apps/api/app/Modules/Product/Presentation/Controllers/*.php 2>/dev/null | head -10
```

**Document findings in the progress file before proceeding.**

### Required Implementation

#### A. Partner Combobox

Replace any `<Select>` for partners with a searchable Combobox that:
- Fetches partners as user types (debounced)
- Shows recent partners when opened (before typing)
- Filters results client-side for instant feedback
- Works with your existing React Query setup

#### B. Case-Insensitive Product Search (Backend)

In the Product controller's index/search method, ensure search uses:

For PostgreSQL:
```php
$query->where('name', 'ILIKE', "%{$search}%")
      ->orWhere('sku', 'ILIKE', "%{$search}%");
```

For MySQL:
```php
$query->whereRaw('LOWER(name) LIKE ?', ['%' . strtolower($search) . '%']);
```

#### C. Apply Same to Partner Search

Ensure partner search is also case-insensitive.

### Verification

```bash
# Test backend search directly
cd apps/api && php artisan tinker --execute="
  \App\Modules\Product\Domain\Product::where('name', 'ILIKE', '%air%')->get()->pluck('name');
"
```

**Manual Test:**
1. Go to Quote creation
2. Click customer field, type partial name (lowercase)
3. Verify: Results filter as you type
4. Search for product: type "air" (lowercase)
5. Verify: "Air Filter" appears in results
6. Search "AIR" (uppercase)
7. Verify: Same results

**Update progress file with results.**

---

## Issue 4: Context-Aware Creation & Auto-Selection

### Problem A: Creating customer from Sales module shows unnecessary Type dropdown
### Problem B: After creating new customer/product in modal, user must search for it manually

### Pre-Flight Check

```bash
echo "=== Issue 4: Context-Aware Creation ==="
echo ""
echo "1. Finding partner form..."
find apps/web/src -name "*PartnerForm*" -o -name "*CustomerForm*" 2>/dev/null

echo ""
echo "2. Checking for type field in partner form..."
grep -n "type\|Type" apps/web/src/features/partners/components/PartnerForm.tsx 2>/dev/null | head -10

echo ""
echo "3. Finding create modals..."
find apps/web/src -name "*Create*Modal*" -o -name "*FormModal*" 2>/dev/null | head -10

echo ""
echo "4. Checking for onSuccess callbacks..."
grep -n "onSuccess\|onCreate" apps/web/src/features/partners/components/*.tsx 2>/dev/null | head -10
grep -n "onSuccess\|onCreate" apps/web/src/features/products/components/*.tsx 2>/dev/null | head -10
```

**Document findings in the progress file before proceeding.**

### Required Implementation

#### A. Smart Type Defaults

In PartnerForm, detect context from route:
- `/sales/*` or `/customers/*` → default to 'customer', hide Type field
- `/purchases/*` or `/suppliers/*` → default to 'supplier', hide Type field
- Otherwise → show Type field

Use `useLocation()` from react-router to check pathname.

#### B. Auto-Select After Creation

When a "Create New" modal is used within another form (e.g., creating customer while making a quote):

1. The modal must call `onSuccess(createdEntity)` with the full created object
2. The parent component must use this to auto-select:
   ```tsx
   const handleCreated = (newEntity) => {
     setShowModal(false);
     onChange(newEntity);  // Auto-select in the form field
   };
   ```

Ensure this pattern is implemented for:
- Partner/Customer creation from Quote form
- Product creation from line item form

### Verification

**Manual Test:**
1. Navigate to /sales/customers
2. Click "Add Customer"
3. Verify: Type field is NOT visible (or auto-set to Customer)
4. Go to Quote creation
5. In customer field, click "Create New"
6. Fill customer form, save
7. Verify: Modal closes, new customer is AUTO-SELECTED
8. In line items, click product dropdown, then "Create New"
9. Fill product form, save
10. Verify: Modal closes, new product is AUTO-SELECTED

**Update progress file with results.**

---

## Issue 5: Translation Completeness

### Problem
UI set to French but "Add Quote", "Issue Date", "Success" appear in English or as raw keys like "action.confirm".

### Pre-Flight Check

```bash
echo "=== Issue 5: Translation Completeness ==="
echo ""
echo "1. Counting translation keys..."
echo "EN files:"
find apps/web/src/locales/en -name "*.json" -exec wc -l {} \; 2>/dev/null
echo "FR files:"
find apps/web/src/locales/fr -name "*.json" -exec wc -l {} \; 2>/dev/null

echo ""
echo "2. Finding hardcoded English strings..."
grep -rn '"Add \|"Edit \|"Delete \|"Save \|"Cancel ' apps/web/src --include="*.tsx" 2>/dev/null | grep -v "t(" | head -10

echo ""
echo "3. Finding potential missing translations (raw keys)..."
grep -rn "action\.\|error\.\|success\." apps/web/src --include="*.tsx" 2>/dev/null | head -10

echo ""
echo "4. Checking toast messages..."
grep -rn "toast\." apps/web/src --include="*.tsx" 2>/dev/null | grep -v "t(" | head -10
```

**Document findings in the progress file before proceeding.**

### Required Implementation

#### A. Create Translation Audit Script

```bash
# Extract all used translation keys
grep -roh "t('[^']*')" apps/web/src --include="*.tsx" | sed "s/t('//g" | sed "s/')//g" | sort | uniq > /tmp/used_keys.txt

# Count
echo "Total unique translation keys used: $(wc -l < /tmp/used_keys.txt)"
```

#### B. Add Missing Translations

For each missing key found, add to appropriate locale file.

Common ones to check:
- `apps/web/src/locales/fr/common.json` - common UI elements
- `apps/web/src/locales/fr/documents.json` - document-related
- `apps/web/src/locales/fr/treasury.json` - payment-related

#### C. Replace Hardcoded Strings

Find and replace patterns like:
```tsx
// BEFORE
<Button>Add Quote</Button>
toast.success('Success');

// AFTER
<Button>{t('documents.addQuote')}</Button>
toast.success(t('messages.success'));
```

#### D. Fix Raw Key Display

If keys like "action.confirm" display as-is, either:
- The key is missing from the JSON file (add it)
- The namespace isn't loaded (check i18n config)

### Verification

```bash
# Check for remaining hardcoded strings
grep -rn '"Add \|"Edit \|"Delete \|"Save ' apps/web/src --include="*.tsx" | grep -v "t(" | wc -l
# Should be 0
```

**Manual Test:**
1. Set language to French in settings
2. Navigate through entire app
3. Check: Sidebar items (all French)
4. Check: Page titles (all French)
5. Check: Buttons (Add, Edit, Delete, Save, Cancel - all French)
6. Check: Form labels (all French)
7. Create a quote successfully
8. Check: Toast message is in French, not "Success"

**Update progress file with results.**

---

## Final Verification

After all issues are complete:

```bash
echo "=== FINAL VERIFICATION ==="

# Type checking
echo "1. Type checking..."
cd apps/web && npm run typecheck 2>&1 | tail -5

# Linting
echo "2. Linting..."
cd apps/web && npm run lint 2>&1 | tail -5

# Backend tests
echo "3. Backend tests..."
cd apps/api && php artisan test 2>&1 | tail -10

# E2E tests if available
echo "4. E2E tests..."
cd apps/web && npm run test:e2e 2>&1 | tail -10 || echo "No E2E tests configured"
```

---

## Completion Checklist

Update the progress file with final status:

```markdown
## Final Status

| # | Issue | Status | Verified |
|---|-------|--------|----------|
| 1 | Payment Context Loss | ✅ Complete | ✅ Tested |
| 2 | Credit Note Context Loss | ✅ Complete | ✅ Tested |
| 3 | Search Improvements | ✅ Complete | ✅ Tested |
| 4 | Context-Aware Creation | ✅ Complete | ✅ Tested |
| 5 | Translation Completeness | ✅ Complete | ✅ Tested |

## Ready for Fork: ✅ YES

**Completed:** [DATE]
**Total Time:** [X hours]
```

---

## Commit Strategy

Make atomic commits as you complete each issue:

```bash
# After Issue 1
git add -A && git commit -m "fix(treasury): payment modal with context preservation

- PaymentModal accepts prefill props
- InvoiceDetailPage uses modal instead of navigation
- Partner locked, amount shows residual balance
- Closes #XXX"

# After Issue 2
git add -A && git commit -m "feat(documents): credit note creation from invoice

- Backend conversion copies all line items
- Frontend navigates to draft for editing
- Source document linked
- Closes #XXX"

# After Issue 3
git add -A && git commit -m "fix(search): case-insensitive search and combobox

- Partner selector now searchable combobox
- Product search uses ILIKE for case-insensitive
- Closes #XXX"

# After Issue 4
git add -A && git commit -m "fix(ux): context-aware creation and auto-selection

- Partner type inferred from route
- New entities auto-selected after creation
- Closes #XXX"

# After Issue 5
git add -A && git commit -m "fix(i18n): complete French translations

- Added missing translation keys
- Replaced hardcoded strings
- All UI elements translated
- Closes #XXX"

# Final
git add docs/UX-FIXES-PROGRESS.md && git commit -m "docs: UX fixes complete and verified"
```

---

## Important Reminders

1. **Update the progress file after each step** — this is your tracking document
2. **Run pre-flight checks before implementing** — understand what exists first
3. **Verify each issue before moving to the next** — don't accumulate untested changes
4. **Commit after each issue** — atomic, reversible changes
5. **If blocked, document why** in the progress file and move to the next issue

**Start with Issue 1. Good luck!**
