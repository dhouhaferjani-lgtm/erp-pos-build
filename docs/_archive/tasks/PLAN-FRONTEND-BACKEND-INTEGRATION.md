# Frontend-Backend Integration Plan

> **Objective**: Implement missing frontend integrations for existing backend functionality
> **Approach**: Test-Driven Development (TDD) with Atomic Design and Hexagonal Architecture
> **Exclusion**: Inventory Counting (being worked on separately)

---

## Overview

This plan addresses the gaps identified in the frontend-backend integration audit. Each feature will follow TDD:
1. Write failing tests (red)
2. Implement minimum code to pass (green)
3. Refactor if needed

---

## Phase 1: Navigation & Finance Module Access

### 1.1 Add Finance Module to Sidebar Navigation

**Problem**: Finance pages exist but are inaccessible from navigation

**Implementation**:
- Add Finance section to `Sidebar.tsx` navigation array
- Include: Chart of Accounts, General Ledger, Trial Balance, P&L, Balance Sheet, Aged Reports
- Add Pricing sub-section with Price Lists

**Files to modify**:
- `apps/web/src/components/organisms/Sidebar/Sidebar.tsx`
- `apps/web/src/locales/en/common.json` (add navigation keys)
- `apps/web/src/locales/fr/common.json`

**Tests**:
- Sidebar renders Finance menu item
- Finance submenu shows all report links
- Permission-based visibility works

---

## Phase 2: Price List Management (HIGH PRIORITY)

### 2.1 Backend Tests (Already exist, verify)
- `apps/api/tests/Feature/Pricing/` - verify coverage

### 2.2 Frontend Implementation

**New Files**:
```
apps/web/src/features/pricing/
├── index.ts
├── api.ts                    # API functions
├── types.ts                  # TypeScript interfaces
├── hooks/
│   ├── usePriceLists.ts      # List query
│   ├── usePriceList.ts       # Single item query
│   └── usePriceListMutations.ts
├── PriceListListPage.tsx     # List view
├── PriceListDetailPage.tsx   # Detail with items
├── PriceListForm.tsx         # Create/Edit form
├── components/
│   ├── PriceListItemTable.tsx
│   ├── AddPriceListItemModal.tsx
│   └── AssignPartnerModal.tsx
└── pricing.test.tsx          # Feature tests
```

**Routes to add**:
```tsx
/pricing/price-lists          # List
/pricing/price-lists/new      # Create
/pricing/price-lists/:id      # Detail
/pricing/price-lists/:id/edit # Edit
```

**TDD Order**:
1. Write `pricing.test.tsx` with tests for:
   - List page renders with title
   - Shows loading state
   - Displays price lists
   - Create button navigates to form
   - Form validation works
   - Item management works
2. Implement `api.ts` and `types.ts`
3. Implement hooks
4. Implement pages and components

---

## Phase 3: Treasury Enhancements (HIGH PRIORITY)

### 3.1 Payment Instrument Detail Page & Operations

**Problem**: InstrumentListPage links to detail page that doesn't exist

**New Files**:
```
apps/web/src/features/treasury/
├── InstrumentDetailPage.tsx
├── components/
│   ├── DepositInstrumentModal.tsx
│   ├── ClearInstrumentModal.tsx
│   ├── BounceInstrumentModal.tsx
│   └── TransferInstrumentModal.tsx
```

**Backend tests to add**:
```
apps/api/tests/Feature/Treasury/
├── InstrumentDepositTest.php
├── InstrumentClearTest.php
├── InstrumentBounceTest.php
└── InstrumentTransferTest.php
```

**TDD Order**:
1. Backend: Write tests for deposit/clear/bounce/transfer endpoints
2. Backend: Verify implementations pass
3. Frontend: Write tests for InstrumentDetailPage
4. Frontend: Implement detail page with action buttons
5. Frontend: Write tests for each modal
6. Frontend: Implement modals

### 3.2 Payment Refunds

**New Files**:
```
apps/web/src/features/treasury/
├── components/
│   ├── RefundPaymentModal.tsx
│   ├── PartialRefundModal.tsx
│   └── RefundHistorySection.tsx
```

**Backend tests to add**:
```
apps/api/tests/Feature/Treasury/
├── PaymentRefundTest.php
├── PaymentPartialRefundTest.php
└── PaymentReverseTest.php
```

**Modifications**:
- Add refund buttons to `PaymentDetailPage.tsx`
- Check `can-refund` before showing button

### 3.3 Advanced Payment Operations

**New Files**:
```
apps/web/src/features/treasury/
├── SplitPaymentForm.tsx
├── OnAccountPaymentForm.tsx
├── DepositPaymentForm.tsx
└── ApplyDepositModal.tsx
```

**Backend tests**:
- Split payment allocation validation
- On-account payment creation
- Deposit application to invoices

---

## Phase 4: Manual Journal Entries (HIGH PRIORITY)

### 4.1 Journal Entry Creation Form

**Problem**: Can view ledger but cannot create manual journal entries

**New Files**:
```
apps/web/src/features/finance/
├── JournalEntryForm.tsx
├── components/
│   └── JournalLineEditor.tsx
├── hooks/
│   └── useJournalEntryMutations.ts
```

**Routes to add**:
```tsx
/finance/journal-entries          # List
/finance/journal-entries/new      # Create
/finance/journal-entries/:id      # Detail
```

**Backend tests to verify**:
- `apps/api/tests/Feature/Accounting/CreateJournalEntryTest.php` (exists)

**TDD Order**:
1. Write frontend tests for JournalEntryForm
2. Implement form with debit/credit line editor
3. Verify debits = credits validation
4. Implement posting action

---

## Phase 5: Stock Movement Forms (MEDIUM PRIORITY)

### 5.1 Stock Movement Creation

**Problem**: StockMovementsPage is read-only

**New Files**:
```
apps/web/src/features/inventory/
├── components/
│   ├── StockReceiptForm.tsx
│   ├── StockIssueForm.tsx
│   ├── StockTransferForm.tsx
│   └── StockAdjustmentForm.tsx
├── hooks/
│   └── useStockMovementMutations.ts
```

**Modifications**:
- Add action buttons to `StockMovementsPage.tsx`
- Add action buttons to `StockLevelsPage.tsx`

**Backend tests to verify**:
- `apps/api/tests/Feature/Inventory/StockMovementTest.php` (exists)

---

## Phase 6: Document Enhancements (MEDIUM PRIORITY)

### 6.1 Credit Notes List Page

**New Files**:
```
apps/web/src/features/documents/
├── CreditNoteListPage.tsx  # Dedicated list (optional, can use DocumentListPage)
```

**Routes**:
```tsx
/sales/credit-notes          # Already in routes, needs navigation link
```

### 6.2 Purchase Order Receipt

**Modifications**:
- Add "Receive Goods" button to PO detail page
- Create `ReceiveGoodsModal.tsx`

### 6.3 Order to Delivery Note Conversion

**Modifications**:
- Add "Convert to Delivery Note" button in `DocumentDetailPage.tsx`

### 6.4 Quote Expiry Warning

**Modifications**:
- Add expiry indicator to quote list/detail pages
- Call `check-expiry` endpoint on quote detail

---

## Phase 7: Location Management (LOWER PRIORITY)

### 7.1 Location Settings Page

**New Files**:
```
apps/web/src/features/settings/
├── LocationsPage.tsx
├── components/
│   └── EditLocationModal.tsx
```

**Routes**:
```tsx
/settings/locations
```

---

## Phase 8: Partner Balance Display (LOWER PRIORITY)

### 8.1 Partner Detail Enhancements

**Modifications**:
- Add balance section to `PartnerDetailPage.tsx`
- Show unallocated balance
- Show on-account balance

---

## Implementation Order (Priority-based)

### Week 1: Foundation
1. **Phase 1**: Navigation fixes (Finance, Pricing in sidebar)
2. **Phase 4**: Manual Journal Entries (high business value)

### Week 2: Pricing
3. **Phase 2**: Price List Management (full feature)

### Week 3: Treasury
4. **Phase 3.1**: Payment Instrument Operations
5. **Phase 3.2**: Payment Refunds

### Week 4: Advanced Treasury & Stock
6. **Phase 3.3**: Split/On-Account Payments
7. **Phase 5**: Stock Movement Forms

### Week 5: Document & Settings
8. **Phase 6**: Document Enhancements
9. **Phase 7**: Location Management
10. **Phase 8**: Partner Balance Display

---

## Testing Strategy

### Backend (PHPUnit)
```bash
# Run specific test file
php artisan test tests/Feature/Treasury/PaymentRefundTest.php

# Run with coverage
php artisan test --coverage
```

### Frontend (Vitest)
```bash
# Run specific test file
pnpm --filter @autoerp/web test src/features/pricing/pricing.test.tsx

# Run all tests
pnpm --filter @autoerp/web test
```

### Pre-commit Checklist
```bash
./scripts/preflight.sh
```

---

## Component Structure Guidelines

### Atomic Design
- **Atoms**: Basic inputs, buttons, labels (`components/atoms/`)
- **Molecules**: Form fields, search bars, filter tabs (`components/molecules/`)
- **Organisms**: Complex forms, data tables, modals (`components/organisms/`)
- **Features**: Page-level components with business logic (`features/`)

### File Naming
- Components: `PascalCase.tsx`
- Hooks: `useCamelCase.ts`
- Tests: `feature.test.tsx`
- Types: `types.ts`
- API: `api.ts`

### Translation Keys
All user-facing text must use translation keys:
```tsx
const { t } = useTranslation(['pricing', 'common'])
<h1>{t('pricing:priceLists.title')}</h1>
```

---

## Verification Commands

After each implementation:
```bash
# Backend
cd apps/api
composer test
./vendor/bin/phpstan
./vendor/bin/pint

# Frontend
cd apps/web
pnpm test
pnpm lint
pnpm typecheck
```

---

## Notes

- Each phase can be implemented independently
- Backend tests should be written/verified before frontend implementation
- Use existing patterns from similar features
- Keep components small and focused
- Document any architectural decisions in code comments
