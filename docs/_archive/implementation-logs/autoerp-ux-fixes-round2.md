# AutoERP Critical UX Fixes — Round 2

> **For Claude Code (Opus 4.5)**
> **Priority:** Complete these BEFORE forking. These are user-facing bugs that affect both AutoERP and BossCloud.
> **Principle:** Context Preservation — users should never lose their place or re-enter data they were just looking at.

---

## Pre-Flight: Verify Current State

Before implementing, check what exists:

```bash
# Payment modal - does it exist?
find apps/web/src -name "*Payment*Modal*" -o -name "*PaymentModal*"

# Credit note conversion - is it implemented?
grep -rn "creditNote\|CreditNote\|credit_note" apps/api/app/Modules/Document --include="*.php" | head -20

# Search implementation - how does it work?
grep -rn "search\|filter\|query" apps/web/src/features/partners --include="*.tsx" | head -10
grep -rn "search\|filter\|query" apps/web/src/features/products --include="*.tsx" | head -10

# i18n completeness check
grep -rn "Add Quote\|Issue Date\|Success" apps/web/src --include="*.tsx" | head -20
```

---

## Issue 1: Payment Context Loss (HIGH PRIORITY)

### Problem
Clicking "Record Payment" on an Invoice navigates to a blank Treasury form. User loses: Partner, Amount, Reference, and must copy-paste from another tab.

### Root Cause (Likely)
Either:
- A: Navigation happens instead of modal
- B: Modal exists but doesn't receive/use prefill props
- C: URL state is passed but PaymentForm doesn't read it

### Files to Check

```bash
# Find where "Record Payment" is triggered
grep -rn "Record.*Payment\|recordPayment\|payment" apps/web/src/features/documents --include="*.tsx" | head -20

# Find payment form/modal
ls -la apps/web/src/features/treasury/components/
cat apps/web/src/features/treasury/components/PaymentForm.tsx | head -100
```

### Required Implementation

#### Step 1: Create or Fix PaymentModal

Location: `apps/web/src/features/treasury/components/PaymentModal.tsx`

```tsx
import { useTranslation } from 'react-i18next';
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { PaymentForm } from './PaymentForm';

interface PaymentModalProps {
  open: boolean;
  onClose: () => void;
  onSuccess: () => void;
  prefill: {
    partner_id: number;
    partner_name: string;
    amount: number;          // Should be amount_residual, not total
    reference: string;       // Invoice number
    document_id: number;     // For linking
    document_type: 'invoice' | 'sales_order';
  };
}

export function PaymentModal({ open, onClose, onSuccess, prefill }: PaymentModalProps) {
  const { t } = useTranslation();

  const handleSuccess = () => {
    onSuccess();
    onClose();
  };

  return (
    <Dialog open={open} onOpenChange={(isOpen) => !isOpen && onClose()}>
      <DialogContent className="max-w-lg">
        <DialogHeader>
          <DialogTitle>{t('treasury.recordPayment')}</DialogTitle>
        </DialogHeader>
        <PaymentForm
          prefill={prefill}
          onSuccess={handleSuccess}
          onCancel={onClose}
          lockPartner={true}  // Partner should not be changeable
        />
      </DialogContent>
    </Dialog>
  );
}
```

#### Step 2: Update PaymentForm to Accept Prefill

Location: `apps/web/src/features/treasury/components/PaymentForm.tsx`

Ensure the form:
1. Accepts `prefill` prop
2. Uses `useEffect` or `defaultValues` to populate fields
3. Respects `lockPartner` prop to disable partner field
4. Sends `document_id` and `document_type` to backend for linking

```tsx
interface PaymentFormProps {
  prefill?: {
    partner_id: number;
    partner_name: string;
    amount: number;
    reference: string;
    document_id: number;
    document_type: string;
  };
  lockPartner?: boolean;
  onSuccess: () => void;
  onCancel: () => void;
}

export function PaymentForm({ prefill, lockPartner, onSuccess, onCancel }: PaymentFormProps) {
  const form = useForm({
    defaultValues: {
      partner_id: prefill?.partner_id ?? '',
      amount: prefill?.amount ?? '',
      reference: prefill?.reference ?? '',
      document_id: prefill?.document_id ?? null,
      document_type: prefill?.document_type ?? null,
      // ... other fields
    },
  });

  // Partner field should be disabled if lockPartner is true
  // ...
}
```

#### Step 3: Wire Up in InvoiceDetailPage

Location: `apps/web/src/features/documents/pages/InvoiceDetailPage.tsx` (or similar)

```tsx
const [showPaymentModal, setShowPaymentModal] = useState(false);

// In the actions area
<Button onClick={() => setShowPaymentModal(true)}>
  {t('invoices.actions.recordPayment')}
</Button>

// Modal
<PaymentModal
  open={showPaymentModal}
  onClose={() => setShowPaymentModal(false)}
  onSuccess={() => {
    queryClient.invalidateQueries(['documents', invoice.id]);
    toast.success(t('payments.recorded'));
  }}
  prefill={{
    partner_id: invoice.partner_id,
    partner_name: invoice.partner?.name ?? '',
    amount: invoice.amount_residual,  // NOT invoice.total_amount
    reference: invoice.number,
    document_id: invoice.id,
    document_type: 'invoice',
  }}
/>
```

### Acceptance Criteria
- [ ] "Record Payment" opens modal overlay (no page navigation)
- [ ] Partner field is pre-filled AND locked (not editable)
- [ ] Amount shows remaining balance (amount_residual), not total
- [ ] Reference shows invoice number (e.g., INV-2025-001)
- [ ] After save, modal closes and invoice page refreshes
- [ ] Invoice status updates to "Paid" or "Partial"

---

## Issue 2: Credit Note Context Loss (HIGH PRIORITY)

### Problem
"Create Credit Note" from an Invoice opens a blank form. User must manually re-select customer and re-add every line item.

### Required Behavior
Credit Note should be a **mirror copy** of the source invoice. User deletes/adjusts what they don't want to refund.

### Files to Check

```bash
# Find credit note creation logic
grep -rn "CreditNote\|credit.*note\|createCredit\|refund" apps/api/app/Modules/Document --include="*.php"

# Check if conversion service handles this
cat apps/api/app/Modules/Document/Application/Services/DocumentConversionService.php | head -150

# Frontend credit note handling
grep -rn "creditNote\|CreditNote" apps/web/src/features/documents --include="*.tsx"
```

### Backend: Add/Fix Credit Note Conversion

Location: `apps/api/app/Modules/Document/Application/Services/DocumentConversionService.php`

```php
public function createCreditNoteFromInvoice(Document $invoice): Document
{
    // Validate source is a posted invoice
    if ($invoice->type !== DocumentType::Invoice) {
        throw new DomainException('Can only create credit note from an invoice');
    }

    if ($invoice->status !== DocumentStatus::Posted) {
        throw new DomainException('Invoice must be posted before creating credit note');
    }

    // Create credit note as mirror of invoice
    $creditNote = Document::create([
        'company_id' => $invoice->company_id,
        'type' => DocumentType::CreditNote,
        'status' => DocumentStatus::Draft,
        'partner_id' => $invoice->partner_id,
        'source_document_id' => $invoice->id,
        'currency' => $invoice->currency,
        'notes' => "Credit note for {$invoice->number}",
        // ... other fields from invoice
    ]);

    // Copy ALL line items (user will delete what they don't want)
    foreach ($invoice->lineItems as $item) {
        $creditNote->lineItems()->create([
            'product_id' => $item->product_id,
            'description' => $item->description,
            'quantity' => $item->quantity,  // Same quantity, user adjusts
            'unit_price' => $item->unit_price,
            'tax_rate' => $item->tax_rate,
            'discount' => $item->discount,
            // ... other fields
        ]);
    }

    $creditNote->calculateTotals();
    $creditNote->save();

    return $creditNote;
}
```

### Frontend: Navigate with Pre-loaded Data

Location: `apps/web/src/features/documents/pages/InvoiceDetailPage.tsx`

```tsx
const handleCreateCreditNote = async () => {
  try {
    // Call backend to create draft credit note from invoice
    const response = await api.post(`/documents/${invoice.id}/credit-note`);
    
    // Navigate to the newly created credit note for editing
    navigate(`/documents/${response.data.id}/edit`);
    
    toast.success(t('creditNotes.draftCreated'));
  } catch (error) {
    toast.error(error.message ?? t('errors.creditNoteCreationFailed'));
  }
};
```

### API Endpoint

Location: `apps/api/app/Modules/Document/Presentation/routes.php`

```php
Route::post('/documents/{document}/credit-note', [DocumentController::class, 'createCreditNote'])
    ->middleware('can:documents.create');
```

### Acceptance Criteria
- [ ] "Create Credit Note" from Invoice calls backend conversion
- [ ] New Credit Note has same partner as source invoice
- [ ] ALL line items are copied (quantities, prices, taxes)
- [ ] User is redirected to edit the draft credit note
- [ ] User can delete items they don't want to refund
- [ ] User can adjust quantities for partial refunds
- [ ] Source invoice is linked (source_document_id)

---

## Issue 3: Search & Auto-Complete Improvements

### Problem A: Customer dropdown is a scroll list, unusable with many customers
### Problem B: Product search is case-sensitive ("air" doesn't find "Air Filter")

### Files to Check

```bash
# Partner/Customer selector component
find apps/web/src -name "*Partner*" -name "*.tsx" | xargs grep -l "select\|Select\|combo\|Combo"
find apps/web/src -name "*Customer*" -name "*.tsx" | xargs grep -l "select\|Select"

# Product search component
find apps/web/src -name "*Product*" -name "*.tsx" | xargs grep -l "search\|Search\|combo\|Combo"

# Backend search endpoint
grep -rn "search\|filter" apps/api/app/Modules/Partner/Presentation --include="*.php"
grep -rn "search\|filter" apps/api/app/Modules/Product/Presentation --include="*.php"
```

### Fix A: Partner Combobox with Type-to-Search

Replace standard `<Select>` with a searchable Combobox:

```tsx
// Use your UI library's Combobox or build with Headless UI
import { Combobox } from '@headlessui/react';

function PartnerCombobox({ value, onChange, partnerType }: Props) {
  const [query, setQuery] = useState('');
  
  // Debounced search query
  const { data: partners, isLoading } = useQuery({
    queryKey: ['partners', 'search', query, partnerType],
    queryFn: () => api.get('/partners', {
      params: { 
        search: query,
        type: partnerType,  // 'customer' or 'supplier'
        limit: 20 
      }
    }),
    enabled: query.length > 0 || true, // Also fetch on empty for recent
  });

  return (
    <Combobox value={value} onChange={onChange}>
      <Combobox.Input
        onChange={(e) => setQuery(e.target.value)}
        displayValue={(partner) => partner?.name ?? ''}
        placeholder={t('partners.searchPlaceholder')}
      />
      <Combobox.Options>
        {isLoading && <div className="p-2">{t('common.loading')}</div>}
        {partners?.map((partner) => (
          <Combobox.Option key={partner.id} value={partner}>
            {partner.name}
          </Combobox.Option>
        ))}
      </Combobox.Options>
    </Combobox>
  );
}
```

### Fix B: Case-Insensitive Product Search (Backend)

Location: `apps/api/app/Modules/Product/Presentation/Controllers/ProductController.php`

```php
public function index(Request $request)
{
    $query = Product::query();

    if ($search = $request->input('search')) {
        // Case-insensitive search using ILIKE (PostgreSQL) or LOWER (MySQL)
        $query->where(function ($q) use ($search) {
            $q->whereRaw('LOWER(name) LIKE ?', ['%' . strtolower($search) . '%'])
              ->orWhereRaw('LOWER(sku) LIKE ?', ['%' . strtolower($search) . '%'])
              ->orWhereRaw('LOWER(description) LIKE ?', ['%' . strtolower($search) . '%']);
        });
    }

    return ProductResource::collection($query->limit(20)->get());
}
```

For PostgreSQL (which you're using), you can use `ILIKE` directly:

```php
$q->where('name', 'ILIKE', "%{$search}%")
  ->orWhere('sku', 'ILIKE', "%{$search}%");
```

### Acceptance Criteria
- [ ] Customer field is a type-to-search combobox
- [ ] Typing "John" filters to customers containing "John"
- [ ] Product search: "air", "Air", "AIR" all find "Air Filter"
- [ ] Search works on name, SKU, and description
- [ ] Results appear quickly (< 300ms perceived)

---

## Issue 4: Context-Aware Creation & Auto-Selection

### Problem A: Creating customer from Sales module still shows Type dropdown
### Problem B: After creating new customer/product in a modal, user must search for it manually

### Files to Check

```bash
# Partner form - where is Type field?
grep -rn "type\|Type" apps/web/src/features/partners/components --include="*.tsx" | head -20

# Product creation modal
find apps/web/src -name "*Product*Modal*" -o -name "*AddProduct*"

# Check for onSuccess callbacks
grep -rn "onSuccess\|onCreate" apps/web/src/features/partners --include="*.tsx"
grep -rn "onSuccess\|onCreate" apps/web/src/features/products --include="*.tsx"
```

### Fix A: Smart Type Defaults

Location: `apps/web/src/features/partners/components/PartnerForm.tsx`

```tsx
import { useLocation } from 'react-router-dom';

function PartnerForm({ onSuccess }: Props) {
  const location = useLocation();
  
  // Determine context from route or passed prop
  const inferredType = useMemo(() => {
    if (location.pathname.includes('/sales') || location.pathname.includes('/customers')) {
      return 'customer';
    }
    if (location.pathname.includes('/purchases') || location.pathname.includes('/suppliers')) {
      return 'supplier';
    }
    return null; // Show selector
  }, [location.pathname]);

  const form = useForm({
    defaultValues: {
      type: inferredType ?? '',
      // ...
    },
  });

  return (
    <form>
      {/* Only show Type field if we couldn't infer it */}
      {!inferredType && (
        <FormField name="type" label={t('partners.type')}>
          <Select {...form.register('type')}>
            <option value="customer">{t('partners.types.customer')}</option>
            <option value="supplier">{t('partners.types.supplier')}</option>
          </Select>
        </FormField>
      )}
      {/* ... rest of form */}
    </form>
  );
}
```

### Fix B: Auto-Select After Creation

When creating a new item from within a form (e.g., creating a customer while making a quote), the new item must be auto-selected.

```tsx
// In Quote form, partner selector with "Create New" option
function PartnerSelectorWithCreate({ value, onChange }: Props) {
  const [showCreateModal, setShowCreateModal] = useState(false);
  const queryClient = useQueryClient();

  const handlePartnerCreated = (newPartner: Partner) => {
    // 1. Close the modal
    setShowCreateModal(false);
    
    // 2. Invalidate the partners cache so new partner appears in list
    queryClient.invalidateQueries(['partners']);
    
    // 3. AUTO-SELECT the newly created partner
    onChange(newPartner);
    
    toast.success(t('partners.created'));
  };

  return (
    <>
      <PartnerCombobox value={value} onChange={onChange} />
      <Button variant="ghost" onClick={() => setShowCreateModal(true)}>
        {t('partners.createNew')}
      </Button>

      <PartnerFormModal
        open={showCreateModal}
        onClose={() => setShowCreateModal(false)}
        onSuccess={handlePartnerCreated}  // <-- Key: pass the callback
      />
    </>
  );
}
```

The modal/form must call `onSuccess(createdEntity)` with the created object:

```tsx
// In PartnerFormModal
const mutation = useMutation({
  mutationFn: (data) => api.post('/partners', data),
  onSuccess: (response) => {
    onSuccess(response.data);  // Pass created partner to parent
  },
});
```

### Acceptance Criteria
- [ ] Creating partner from /sales/customers → Type auto-set to Customer, field hidden
- [ ] Creating partner from /purchases/suppliers → Type auto-set to Supplier, field hidden
- [ ] Creating partner from generic /partners → Type field shown
- [ ] Creating customer while in Quote form → new customer auto-selected
- [ ] Creating product while in Quote line item → new product auto-selected
- [ ] User never has to search for something they just created

---

## Issue 5: Translation Completeness

### Problem
UI set to French but "Add Quote", "Issue Date", "Success", "Action.confirm" appear in English or as raw keys.

### Files to Check

```bash
# Find untranslated strings
grep -rn "\"Add \|\"Issue \|\"Success\"\|\"Error\"\|action\." apps/web/src --include="*.tsx" | grep -v "t(" | head -30

# Check locale file coverage
wc -l apps/web/src/locales/en/*.json
wc -l apps/web/src/locales/fr/*.json

# Find raw translation keys being displayed (indicates missing translations)
grep -rn "t\('" apps/web/src --include="*.tsx" | grep -o "t('[^']*'" | sort | uniq > /tmp/used_keys.txt
# Then compare with actual keys in JSON files
```

### Systematic Fix

#### Step 1: Audit for Hardcoded Strings

```bash
# Common English strings that should be translated
grep -rn "\"Add\|\"Edit\|\"Delete\|\"Save\|\"Cancel\|\"Submit\|\"Create\|\"Update" apps/web/src --include="*.tsx" | grep -v "t("
```

#### Step 2: Find Missing Translation Keys

Create a script to compare used keys vs. defined keys:

```bash
# Extract all t('...') calls
grep -roh "t('[^']*')" apps/web/src --include="*.tsx" | sed "s/t('//g" | sed "s/')//g" | sort | uniq > /tmp/used_keys.txt

# Check which are missing from French
node -e "
const fr = require('./apps/web/src/locales/fr/common.json');
const used = require('fs').readFileSync('/tmp/used_keys.txt', 'utf8').split('\n');
const flat = (obj, prefix = '') => Object.entries(obj).flatMap(([k,v]) => 
  typeof v === 'object' ? flat(v, prefix + k + '.') : [prefix + k]
);
const defined = flat(fr);
used.forEach(k => { if (k && !defined.includes(k)) console.log('MISSING:', k); });
"
```

#### Step 3: Add Missing Translations

Location: `apps/web/src/locales/fr/common.json` (and other namespace files)

Common missing ones based on the report:

```json
{
  "actions": {
    "add": "Ajouter",
    "edit": "Modifier",
    "delete": "Supprimer",
    "save": "Enregistrer",
    "cancel": "Annuler",
    "confirm": "Confirmer",
    "create": "Créer",
    "submit": "Soumettre"
  },
  "documents": {
    "addQuote": "Créer un devis",
    "issueDate": "Date d'émission",
    "dueDate": "Date d'échéance"
  },
  "messages": {
    "success": "Opération réussie",
    "error": "Une erreur s'est produite",
    "saved": "Enregistré avec succès",
    "deleted": "Supprimé avec succès"
  }
}
```

#### Step 4: Fix Toast Messages

Ensure all toast calls use translation:

```tsx
// BEFORE (bad)
toast.success('Success');
toast.error('Error');

// AFTER (good)
toast.success(t('messages.success'));
toast.error(t('messages.error'));

// Even better - specific messages
toast.success(t('quotes.created'));
toast.error(t('quotes.errors.saveFailed'));
```

### Acceptance Criteria
- [ ] No English strings visible when language is set to French
- [ ] No raw keys visible (like "action.confirm")
- [ ] All buttons translated: Add, Edit, Delete, Save, Cancel, etc.
- [ ] All form labels translated: Issue Date, Due Date, etc.
- [ ] All toast messages translated and human-readable
- [ ] Switching language updates all visible text

---

## Verification Checklist

After completing all fixes:

```bash
# Run type checking
cd apps/web && npm run typecheck

# Run linting
cd apps/web && npm run lint

# Run backend tests
cd apps/api && php artisan test

# Manual testing
# 1. Invoice → Record Payment → verify modal, prefill, save, refresh
# 2. Invoice → Create Credit Note → verify all lines copied
# 3. Quote → Customer search → type partial name
# 4. Quote → Product search → type lowercase, find capitalized
# 5. Quote → Create new customer → verify auto-selected
# 6. Switch language to French → no English visible
```

---

## Summary: Priority Order

| # | Issue | Impact | Effort |
|---|-------|--------|--------|
| 1 | Payment Context Loss | High | Medium |
| 2 | Credit Note Context Loss | High | Medium |
| 3 | Search Improvements | Medium | Low |
| 4 | Auto-Selection After Create | Medium | Low |
| 5 | Translation Completeness | Medium | Low |

**Recommendation:** Fix issues 1-4 first (they affect workflow). Issue 5 is polish but important for French users.
