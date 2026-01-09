# Web POS UI Specification

**Module:** POS (Point of Sale)
**Date:** 2026-01-08
**Status:** Planning Document
**Target:** Web application (React + TypeScript)

---

## Executive Summary

This document specifies the web-based POS interface for IziPOS. The web POS is designed for:
- Quick service operations (mechanics, service centers)
- Manager oversight and reporting
- Shift management and cash drawer operations
- X and Z report generation

**Key Design Principles:**
- Speed-optimized for quick transactions
- Keyboard shortcuts for common operations
- Clear visual hierarchy for cashier workflow
- Real-time feedback on cash drawer status
- Mobile-responsive for tablet use

---

## Overall Layout

### Main Container Structure

```
┌─────────────────────────────────────────────────────────────────┐
│  Top Bar: Logo | Terminal Status | Shift Info | User Menu       │
├─────────────────────────────────────────────────────────────────┤
│                                                                   │
│  ┌───────────────────────┐  ┌─────────────────────────────────┐ │
│  │                       │  │                                   │ │
│  │   Product Selection   │  │      Current Transaction Cart    │ │
│  │   (Left Panel 60%)    │  │      (Right Panel 40%)           │ │
│  │                       │  │                                   │ │
│  │  - Search bar         │  │  - Line items list               │ │
│  │  - Category filter    │  │  - Subtotal / Tax / Total        │ │
│  │  - Product grid       │  │  - Payment methods               │ │
│  │  - Quick actions      │  │  - Checkout button               │ │
│  │                       │  │                                   │ │
│  └───────────────────────┘  └─────────────────────────────────┘ │
│                                                                   │
├─────────────────────────────────────────────────────────────────┤
│  Bottom Bar: Cash Drawer Balance | Quick Actions | Help          │
└─────────────────────────────────────────────────────────────────┘
```

---

## Screen Breakdown

### 1. Shift Management Dashboard (Entry Point)

**Route:** `/pos/shift`

**Purpose:** Before cashier can use POS, they must open a shift.

**Layout:**

```
┌─────────────────────────────────────────────────────────────────┐
│                    POS Shift Management                          │
├─────────────────────────────────────────────────────────────────┤
│                                                                   │
│   ┌─────────────────────────────────────────────────────────┐   │
│   │  No Active Shift                                        │   │
│   │                                                         │   │
│   │  Terminal: SHOP01-POS01                                │   │
│   │  Cashier: John Doe                                     │   │
│   │                                                         │   │
│   │  ┌────────────────────────────────────────────────┐    │   │
│   │  │  Opening Cash Balance                          │    │   │
│   │  │  [____________] TND                            │    │   │
│   │  └────────────────────────────────────────────────┘    │   │
│   │                                                         │   │
│   │  [Open Shift]  [View Shift History]                   │   │
│   │                                                         │   │
│   └─────────────────────────────────────────────────────────┘   │
│                                                                   │
│  OR (if shift is already open)                                   │
│                                                                   │
│   ┌─────────────────────────────────────────────────────────┐   │
│   │  ✓ Shift #42 is Open                                   │   │
│   │                                                         │   │
│   │  Opened: 2026-01-08 09:00 AM (3h 42m ago)              │   │
│   │  Opening Cash: 200.00 TND                              │   │
│   │  Current Balance: 1,450.50 TND                         │   │
│   │  Transactions: 18 sales, 2 refunds                     │   │
│   │                                                         │   │
│   │  [Continue to POS]  [Generate X Report]  [Close Shift] │   │
│   │                                                         │   │
│   └─────────────────────────────────────────────────────────┘   │
│                                                                   │
└─────────────────────────────────────────────────────────────────┘
```

**Components:**
- `ShiftStatusCard` - Shows current shift status
- `OpenShiftForm` - Opening balance input
- `ShiftActionsPanel` - Continue/Report/Close buttons

**State Management:**
- Fetch current shift on mount: `GET /api/v1/pos/shifts/current/{terminalId}`
- React Query key: `['pos', 'shift', 'current', terminalId]`
- Optimistic update on shift open/close

**Business Rules:**
- Cannot access POS without open shift
- Opening balance must be >= 0
- Only one open shift per terminal at a time
- Shift close requires cash count

---

### 2. Main POS Screen (Transaction Entry)

**Route:** `/pos`

**Purpose:** Primary interface for entering sales transactions.

**Left Panel - Product Selection (60%)**

```
┌─────────────────────────────────────────────────────┐
│  🔍 [Search products...]          [Filter: All ▼]   │
├─────────────────────────────────────────────────────┤
│                                                       │
│  Categories: [All] [Parts] [Labor] [Fluids] [Tires] │
│                                                       │
│  ┌───────────┐ ┌───────────┐ ┌───────────┐          │
│  │ Oil Filter│ │ Air Filter│ │ Spark Plug│          │
│  │ SKU: 1234 │ │ SKU: 5678 │ │ SKU: 9012 │          │
│  │ 15.50 TND │ │ 22.00 TND │ │ 8.75 TND  │          │
│  │ Stock: 45 │ │ Stock: 32 │ │ Stock: 120│          │
│  └───────────┘ └───────────┘ └───────────┘          │
│                                                       │
│  ┌───────────┐ ┌───────────┐ ┌───────────┐          │
│  │ Brake Pad │ │ Wiper Set │ │ Coolant   │          │
│  │ SKU: 3456 │ │ SKU: 7890 │ │ SKU: 2345 │          │
│  │ 45.00 TND │ │ 18.50 TND │ │ 12.00 TND │          │
│  │ Stock: 18 │ │ Stock: 55 │ │ Stock: 88 │          │
│  └───────────┘ └───────────┘ └───────────┘          │
│                                                       │
│  [Load More...]                  Showing 1-12 of 234 │
│                                                       │
└─────────────────────────────────────────────────────┘
```

**Right Panel - Transaction Cart (40%)**

```
┌─────────────────────────────────────────────────────┐
│  Transaction #1234                        [Clear]    │
├─────────────────────────────────────────────────────┤
│                                                       │
│  1x Oil Filter (SKU: 1234)          15.50 TND [x]    │
│                                                       │
│  2x Spark Plug (SKU: 9012)          17.50 TND [x]    │
│      [- 1 +]                                         │
│                                                       │
│  1x Brake Pad (SKU: 3456)           45.00 TND [x]    │
│                                                       │
│  ─────────────────────────────────────────────────   │
│                                                       │
│  Subtotal:                          78.00 TND        │
│  Tax (19%):                         14.82 TND        │
│  ─────────────────────────────────────────────────   │
│  Total:                             92.82 TND        │
│                                                       │
│  ┌─────────────────────────────────────────────────┐ │
│  │  Payment Method:                                │ │
│  │  ⚫ Cash   ○ Card   ○ Check   ○ Split          │ │
│  └─────────────────────────────────────────────────┘ │
│                                                       │
│  [Park Transaction]        [Checkout (F12)] 💰       │
│                                                       │
└─────────────────────────────────────────────────────┘
```

**Top Bar**

```
┌─────────────────────────────────────────────────────────────────┐
│ [IziPOS] | SHOP01-POS01 | Shift #42 | 💰 1,450.50 | John Doe ⚙️ │
└─────────────────────────────────────────────────────────────────┘
```

**Bottom Bar**

```
┌─────────────────────────────────────────────────────────────────┐
│ F1: Help | F2: Customer | F8: Refund | F9: Deposit | F10: X Report │
└─────────────────────────────────────────────────────────────────┘
```

**Components:**
- `ProductSearchBar` - Autocomplete search with keyboard navigation
- `CategoryFilter` - Quick filter by product category
- `ProductGrid` - Grid of clickable product cards
- `TransactionCart` - List of cart line items with qty adjustment
- `PaymentMethodSelector` - Radio buttons for payment type
- `CheckoutButton` - Large, prominent checkout button
- `QuickActionBar` - Function key shortcuts

**State Management:**
- Local cart state (Zustand): `useCartStore()`
- Product search (React Query): `['products', searchTerm, filters]`
- Current transaction: In-memory until checkout

**Business Rules:**
- Product click adds to cart (qty = 1)
- Duplicate product increments quantity
- Cannot checkout with empty cart
- Cannot sell items with insufficient stock
- Tax calculated in real-time on cart changes

---

### 3. Checkout Modal

**Triggered by:** Clicking "Checkout" button or pressing F12

**Layout:**

```
┌─────────────────────────────────────────────────────────────────┐
│                       Complete Transaction                       │
├─────────────────────────────────────────────────────────────────┤
│                                                                   │
│   Total Amount: 92.82 TND                                        │
│                                                                   │
│   Payment Method: ⚫ Cash                                         │
│                                                                   │
│   ┌─────────────────────────────────────────────────────────┐   │
│   │  Amount Received                                        │   │
│   │  [__________] TND                                       │   │
│   │                                                         │   │
│   │  Change: 7.18 TND                                       │   │
│   └─────────────────────────────────────────────────────────┘   │
│                                                                   │
│   Customer (optional):                                           │
│   [Search customer or leave blank...]                            │
│                                                                   │
│   Notes (optional):                                              │
│   [_____________________________________________]                │
│                                                                   │
│   [Cancel]                          [Complete Sale] 💰           │
│                                                                   │
└─────────────────────────────────────────────────────────────────┘
```

**For Split Payment:**

```
┌─────────────────────────────────────────────────────────────────┐
│                       Split Payment                              │
├─────────────────────────────────────────────────────────────────┤
│                                                                   │
│   Total Amount: 92.82 TND                                        │
│                                                                   │
│   ┌─────────────────────────────────────────────────────────┐   │
│   │  Payment 1: Cash                    [50.00] TND    [x] │   │
│   │  Payment 2: Card                    [42.82] TND    [x] │   │
│   └─────────────────────────────────────────────────────────┘   │
│                                                                   │
│   [+ Add Payment Method]                                         │
│                                                                   │
│   Remaining: 0.00 TND  ✓                                         │
│                                                                   │
│   [Cancel]                          [Complete Sale] 💰           │
│                                                                   │
└─────────────────────────────────────────────────────────────────┘
```

**Components:**
- `CheckoutModal` - Full-screen modal overlay
- `PaymentAmountInput` - Amount received with change calculation
- `SplitPaymentBuilder` - Multiple payment method inputs
- `CustomerSearchSelect` - Optional customer association

**State Management:**
- Payment amounts: Local state in modal
- Change calculation: Computed real-time
- Submit: Optimistic mutation to create receipt

**API Flow:**
1. User clicks "Complete Sale"
2. Frontend creates receipt: `POST /api/v1/pos/receipts`
3. Backend:
   - Creates receipt with hash chain
   - Creates receipt lines
   - Creates VAT details
   - Creates payment records
   - Records SALE cash drawer operation
   - Deducts stock
4. Frontend:
   - Shows success toast
   - Clears cart
   - Option to print receipt
   - Returns to main POS screen

---

### 4. Cash Drawer Operations Modal

**Triggered by:** F9 key or "Cash Operations" button

**Layout:**

```
┌─────────────────────────────────────────────────────────────────┐
│                    Cash Drawer Operations                        │
├─────────────────────────────────────────────────────────────────┤
│                                                                   │
│   Current Balance: 1,450.50 TND                                  │
│                                                                   │
│   ┌───────────────────────────┐  ┌────────────────────────────┐ │
│   │   💰 Deposit to Safe      │  │   💸 Payout                │ │
│   │                           │  │                            │ │
│   │   Move large bills to     │  │   Cash refund or petty    │ │
│   │   safe for security       │  │   cash disbursement       │ │
│   │                           │  │                            │ │
│   │   [Deposit]               │  │   [Payout]                │ │
│   └───────────────────────────┘  └────────────────────────────┘ │
│                                                                   │
│   Recent Operations:                                             │
│   ┌─────────────────────────────────────────────────────────┐   │
│   │ 09:45 AM  SALE       +50.00 TND   (Receipt #001)       │   │
│   │ 10:12 AM  DEPOSIT   -200.00 TND   (Safe drop)          │   │
│   │ 10:30 AM  SALE       +35.50 TND   (Receipt #002)       │   │
│   │ 11:05 AM  PAYOUT     -20.00 TND   (Refund)             │   │
│   └─────────────────────────────────────────────────────────┘   │
│                                                                   │
│   [Close]                                                        │
│                                                                   │
└─────────────────────────────────────────────────────────────────┘
```

**Deposit Form:**

```
┌─────────────────────────────────────────────────────────────────┐
│                    Deposit to Safe                               │
├─────────────────────────────────────────────────────────────────┤
│                                                                   │
│   Amount: [__________] TND                                       │
│                                                                   │
│   Reason: [Safe drop - large bills________________]              │
│                                                                   │
│   This will REMOVE cash from the drawer balance.                 │
│                                                                   │
│   [Cancel]                          [Confirm Deposit]            │
│                                                                   │
└─────────────────────────────────────────────────────────────────┘
```

**Components:**
- `CashDrawerModal` - Operations modal
- `DepositForm` - Record safe deposit
- `PayoutForm` - Record payout
- `OperationsHistory` - List recent operations

**API Calls:**
- `GET /api/v1/pos/cash-drawer/{shiftId}/operations` - List operations
- `GET /api/v1/pos/cash-drawer/{shiftId}/balance` - Get current balance
- `POST /api/v1/pos/cash-drawer/deposit` - Record deposit
- `POST /api/v1/pos/cash-drawer/payout` - Record payout

---

### 5. X Report Modal

**Triggered by:** F10 key or "Generate X Report" button

**Layout:**

```
┌─────────────────────────────────────────────────────────────────┐
│                        X Report (Mid-Shift)                      │
│                     Generated: 2026-01-08 12:35 PM               │
├─────────────────────────────────────────────────────────────────┤
│                                                                   │
│   Shift #42 - Cashier: John Doe                                  │
│   Terminal: SHOP01-POS01                                         │
│   Opened: 09:00 AM (3h 35m ago)                                  │
│                                                                   │
│   ┌─────────────────────────────────────────────────────────┐   │
│   │  SALES SUMMARY                                          │   │
│   │  ─────────────────────────────────────────────────────  │   │
│   │  Sales Count:                    18 transactions       │   │
│   │  Gross Sales:                    1,250.50 TND          │   │
│   │  Net Sales:                      1,050.84 TND          │   │
│   │  Tax Amount:                       199.66 TND          │   │
│   │                                                         │   │
│   │  Refunds:                          2 transactions       │   │
│   │  Refund Amount:                    -45.00 TND          │   │
│   │                                                         │   │
│   │  Voided:                           1 transaction        │   │
│   └─────────────────────────────────────────────────────────┘   │
│                                                                   │
│   ┌─────────────────────────────────────────────────────────┐   │
│   │  VAT BREAKDOWN                                          │   │
│   │  ─────────────────────────────────────────────────────  │   │
│   │  19% VAT: Net 850.00 | Tax 161.50 | Gross 1,011.50    │   │
│   │   7% VAT: Net 200.84 | Tax  14.06 | Gross   214.90    │   │
│   └─────────────────────────────────────────────────────────┘   │
│                                                                   │
│   ┌─────────────────────────────────────────────────────────┐   │
│   │  PAYMENT METHODS                                        │   │
│   │  ─────────────────────────────────────────────────────  │   │
│   │  Cash:                           850.00 TND (12 trans) │   │
│   │  Card:                           400.50 TND  (6 trans) │   │
│   └─────────────────────────────────────────────────────────┘   │
│                                                                   │
│   [Print]  [Export PDF]  [Close]                                │
│                                                                   │
└─────────────────────────────────────────────────────────────────┘
```

**Components:**
- `XReportModal` - Report display modal
- `SalesSummaryCard` - Sales totals
- `VATBreakdownTable` - Tax details
- `PaymentMethodsTable` - Payment breakdown

**API Call:**
- `POST /api/v1/pos/reports/x` - Generate X report

**Business Rules:**
- Non-destructive - can be generated multiple times
- Shows cumulative totals since shift opened
- Does NOT close the shift
- Does NOT affect cash drawer balance

---

### 6. Close Shift / Z Report Flow

**Triggered by:** "Close Shift" button on Shift Dashboard

**Step 1: Cash Count**

```
┌─────────────────────────────────────────────────────────────────┐
│                          Close Shift #42                         │
├─────────────────────────────────────────────────────────────────┤
│                                                                   │
│   Count the physical cash in the drawer:                         │
│                                                                   │
│   ┌─────────────────────────────────────────────────────────┐   │
│   │  Actual Cash Count                                      │   │
│   │  [__________] TND                                       │   │
│   └─────────────────────────────────────────────────────────┘   │
│                                                                   │
│   Expected Cash: 1,250.50 TND                                    │
│                                                                   │
│   ⚠️ This will:                                                  │
│   - Generate Z Report (end-of-day closing)                       │
│   - Create GRANDTOTAL_DAILY event (NF525)                        │
│   - Close the shift (cannot be reopened)                         │
│                                                                   │
│   [Cancel]                          [Generate Z Report & Close]  │
│                                                                   │
└─────────────────────────────────────────────────────────────────┘
```

**Step 2: Z Report Display**

```
┌─────────────────────────────────────────────────────────────────┐
│                  Z Report #42 (END OF DAY)                       │
│                   Generated: 2026-01-08 06:00 PM                 │
│                  ⚠️ FISCALLY CRITICAL - NF525                    │
├─────────────────────────────────────────────────────────────────┤
│                                                                   │
│   Shift #42 - Cashier: John Doe                                  │
│   Terminal: SHOP01-POS01                                         │
│   Opened: 09:00 AM | Closed: 06:00 PM (9h 0m)                    │
│                                                                   │
│   ┌─────────────────────────────────────────────────────────┐   │
│   │  CASH RECONCILIATION                                    │   │
│   │  ─────────────────────────────────────────────────────  │   │
│   │  Opening Cash:                     200.00 TND          │   │
│   │  Expected Cash:                  1,250.50 TND          │   │
│   │  Actual Cash:                    1,248.00 TND          │   │
│   │  ─────────────────────────────────────────────────────  │   │
│   │  Variance:                         -2.50 TND ⚠️        │   │
│   │  (Shortage)                                             │   │
│   └─────────────────────────────────────────────────────────┘   │
│                                                                   │
│   [Sales Summary - same as X Report...]                          │
│   [VAT Breakdown - same as X Report...]                          │
│   [Payment Methods - same as X Report...]                        │
│                                                                   │
│   ┌─────────────────────────────────────────────────────────┐   │
│   │  FISCAL INTEGRITY (NF525)                               │   │
│   │  ─────────────────────────────────────────────────────  │   │
│   │  Z Number: 42                                           │   │
│   │  Fiscal Hash: a3f9c8e2...                              │   │
│   │  Previous Hash: 9d2b1a5f...                            │   │
│   │  Chain: ✓ Valid                                         │   │
│   └─────────────────────────────────────────────────────────┘   │
│                                                                   │
│   ✓ Shift Closed | ✓ Z Report Generated | ✓ Grand Total Created │
│                                                                   │
│   [Print]  [Export PDF]  [Close]                                │
│                                                                   │
└─────────────────────────────────────────────────────────────────┘
```

**Components:**
- `CloseShiftModal` - Cash count form
- `ZReportModal` - Z report display
- `CashReconciliationCard` - Variance display with warning if != 0
- `FiscalIntegrityCard` - Hash chain verification badge

**API Flow:**
1. User enters actual cash count
2. User clicks "Generate Z Report & Close"
3. Frontend: `POST /api/v1/pos/reports/z` - Generate Z report
4. Backend:
   - Creates Z report with hash chain
   - Creates GRANDTOTAL_DAILY event
   - Returns Z report data
5. Frontend: `POST /api/v1/pos/shifts/{id}/close` - Close shift
6. Backend:
   - Updates shift status to CLOSED
   - Records variance
   - Records CLOSING cash drawer operation
7. Frontend:
   - Shows Z report modal
   - Redirects to shift dashboard after close

**Business Rules:**
- CANNOT be undone once generated
- Must count actual cash
- Z numbers are sequential and never reset
- Automatically closes the shift
- Creates GRANDTOTAL_DAILY event (NF525)

---

## Component Architecture

### Directory Structure

```
apps/web/src/features/pos/
├── api/
│   ├── shiftApi.ts              # Shift API calls
│   ├── cashDrawerApi.ts         # Cash drawer API calls
│   ├── reportApi.ts             # X/Z report API calls
│   └── receiptApi.ts            # Receipt creation API calls
├── components/
│   ├── ProductGrid.tsx          # Product selection grid
│   ├── ProductCard.tsx          # Individual product card
│   ├── ProductSearchBar.tsx     # Search with autocomplete
│   ├── CategoryFilter.tsx       # Category chips
│   ├── TransactionCart.tsx      # Cart line items
│   ├── CartLineItem.tsx         # Single line item with qty
│   ├── CartTotals.tsx           # Subtotal/Tax/Total display
│   ├── PaymentMethodSelector.tsx # Payment type selection
│   ├── CheckoutButton.tsx       # Large checkout CTA
│   ├── QuickActionBar.tsx       # Function key shortcuts
│   ├── ShiftStatusCard.tsx      # Current shift display
│   ├── OpenShiftForm.tsx        # Opening balance form
│   ├── CloseShiftModal.tsx      # Cash count form
│   ├── CheckoutModal.tsx        # Checkout flow modal
│   ├── SplitPaymentBuilder.tsx  # Multiple payment inputs
│   ├── CashDrawerModal.tsx      # Deposit/payout modal
│   ├── DepositForm.tsx          # Safe deposit form
│   ├── PayoutForm.tsx           # Payout form
│   ├── OperationsHistory.tsx    # Cash operations list
│   ├── XReportModal.tsx         # X report display
│   ├── ZReportModal.tsx         # Z report display
│   ├── SalesSummaryCard.tsx     # Sales totals card
│   ├── VATBreakdownTable.tsx    # VAT details table
│   └── PaymentMethodsTable.tsx  # Payment breakdown table
├── hooks/
│   ├── useCurrentShift.ts       # Get current shift
│   ├── useCartStore.ts          # Zustand cart state
│   ├── useProductSearch.ts      # Product search with React Query
│   ├── useCheckout.ts           # Checkout mutation
│   ├── useCashDrawer.ts         # Cash drawer operations
│   └── useKeyboardShortcuts.ts  # Global keyboard handlers
├── pages/
│   ├── ShiftDashboardPage.tsx   # /pos/shift
│   └── POSPage.tsx              # /pos
└── types.ts                     # TypeScript interfaces
```

---

## State Management

### Cart State (Zustand)

**Store:** `useCartStore`

```typescript
interface CartStore {
  items: CartItem[]
  addItem: (product: Product) => void
  removeItem: (productId: string) => void
  updateQuantity: (productId: string, quantity: number) => void
  clearCart: () => void

  // Computed
  subtotal: number
  taxAmount: number
  total: number
}

interface CartItem {
  product: Product
  quantity: number
  unit_price: number
  line_total: number
  tax_rate: number
  tax_amount: number
}
```

**Why Zustand?**
- Cart is client-side only (not persisted until checkout)
- Needs to be accessed from multiple components
- Simple, no boilerplate

---

### Server State (React Query)

**Query Keys:**

```typescript
// Shift queries
['pos', 'shift', 'current', terminalId]
['pos', 'shift', shiftId]
['pos', 'shifts', filters]

// Product queries (reuse from existing)
['products', searchTerm, filters]

// Cash drawer queries
['pos', 'cash-drawer', shiftId, 'operations']
['pos', 'cash-drawer', shiftId, 'balance']

// Report queries
['pos', 'reports', 'x', terminalId]
['pos', 'reports', 'z', terminalId, filters]
```

**Mutations:**

```typescript
// Shift mutations
useMutation({ mutationFn: openShift })
useMutation({ mutationFn: closeShift })

// Checkout mutation
useMutation({ mutationFn: createReceipt })

// Cash drawer mutations
useMutation({ mutationFn: recordDeposit })
useMutation({ mutationFn: recordPayout })

// Report mutations
useMutation({ mutationFn: generateXReport })
useMutation({ mutationFn: generateZReport })
```

**Invalidation Strategy:**
- After checkout: Invalidate shift queries, cash drawer balance
- After deposit/payout: Invalidate cash drawer balance and operations
- After shift close: Invalidate all shift queries, redirect to dashboard

---

## Keyboard Shortcuts

**Global (available on main POS screen):**

| Key | Action | Description |
|-----|--------|-------------|
| F1 | Help | Show keyboard shortcuts |
| F2 | Customer | Open customer search |
| F8 | Refund | Start refund transaction |
| F9 | Deposit | Open cash drawer modal |
| F10 | X Report | Generate mid-shift report |
| F12 | Checkout | Open checkout modal |
| Esc | Cancel | Clear cart or close modal |
| / | Search | Focus product search |

**Product Grid:**
- Arrow keys: Navigate products
- Enter: Add selected product to cart

**Cart:**
- +/-: Adjust quantity
- Delete: Remove line item

---

## Design System

### Colors

**Status Colors:**
- Success: `green.500` - Shift open, transaction complete
- Warning: `yellow.500` - Variance warning, low stock
- Error: `red.500` - Shift closed, error states
- Info: `blue.500` - Informational notices

**Cash Drawer Balance:**
- Positive balance: `green.600`
- Zero balance: `gray.600`
- Negative variance: `red.600`

### Typography

**Headers:**
- Page title: `text-2xl font-bold`
- Section title: `text-xl font-semibold`
- Card title: `text-lg font-medium`

**Body:**
- Normal text: `text-base`
- Small text: `text-sm`
- Labels: `text-sm font-medium text-gray-700`

**Monetary Values:**
- Always right-aligned
- Always 2 decimal places
- Large amounts (totals): `text-2xl font-bold`
- Small amounts (line items): `text-base`

### Spacing

- Page padding: `p-6`
- Card padding: `p-4`
- Section gap: `space-y-6`
- Form gap: `space-y-4`

### Product Cards

```
┌───────────────┐
│   [Image]     │  - 150x150px image (or placeholder)
│               │  - Border: `border-2 border-gray-200`
│ Product Name  │  - Hover: `border-blue-500` + shadow
│ SKU: 1234     │  - Active (in cart): `border-green-500`
│ 15.50 TND     │  - Text: Truncate with ellipsis
│ Stock: 45     │  - Stock < 10: Red color
└───────────────┘
```

---

## Responsive Breakpoints

### Desktop (>= 1280px) - PRIMARY TARGET
- Full 60/40 split layout
- Product grid: 4 columns
- All features visible

### Tablet (768px - 1279px)
- 50/50 split layout
- Product grid: 3 columns
- Smaller cards

### Mobile (< 768px) - LIMITED SUPPORT
- Stack layout (product list above cart)
- Product list: 2 columns
- Cart: Collapsible panel at bottom
- Most cashiers will use desktop/tablet

---

## API Integration Patterns

### Following CLAUDE.md Convention

**✅ CORRECT Pattern:**

```typescript
// apps/web/src/features/pos/api/shiftApi.ts

export interface ShiftResponse {
  id: string
  terminal_id: string
  shift_number: number
  opening_cash: string
  status: string
  opened_at: string
  // ... other fields
}

export async function openShift(data: {
  terminal_id: string
  opening_cash: string
}): Promise<ShiftResponse> {
  return apiPost<ShiftResponse>('/pos/shifts/open', data)
  // ✅ apiPost already unwraps response.data.data
}

export async function getCurrentShift(
  terminalId: string
): Promise<ShiftResponse | null> {
  return apiGet<ShiftResponse | null>(`/pos/shifts/current/${terminalId}`)
  // ✅ Returns null if no open shift
}
```

**❌ WRONG Pattern:**

```typescript
// DO NOT DO THIS!
export async function openShift(data: any): Promise<any> {
  const response = await apiPost<{ data: ShiftResponse }>('/pos/shifts/open', data)
  return response.data // ❌ Double unwrapping!
}
```

**Key Points:**
1. apiGet/apiPost/apiPut/apiDelete already unwrap `response.data.data`
2. Return the unwrapped type directly
3. Do NOT create wrapper interfaces like `{ data: T }`
4. Use strict types (no `any`)

---

## Internationalization (i18n)

### Translation Keys Structure

**File:** `apps/web/src/locales/en/pos.json`

```json
{
  "shift": {
    "title": "Shift Management",
    "noActiveShift": "No Active Shift",
    "shiftOpen": "Shift #{{number}} is Open",
    "openShift": "Open Shift",
    "closeShift": "Close Shift",
    "openingBalance": "Opening Cash Balance",
    "currentBalance": "Current Balance",
    "duration": "Duration",
    "transactions": "{{count}} sales, {{refunds}} refunds"
  },
  "pos": {
    "title": "Point of Sale",
    "searchProducts": "Search products...",
    "addToCart": "Add to Cart",
    "cart": "Transaction Cart",
    "clearCart": "Clear",
    "subtotal": "Subtotal",
    "tax": "Tax",
    "total": "Total",
    "checkout": "Checkout",
    "parkTransaction": "Park Transaction"
  },
  "checkout": {
    "title": "Complete Transaction",
    "amountReceived": "Amount Received",
    "change": "Change",
    "completeSale": "Complete Sale",
    "paymentMethod": "Payment Method",
    "cash": "Cash",
    "card": "Card",
    "check": "Check",
    "split": "Split Payment"
  },
  "cashDrawer": {
    "title": "Cash Drawer Operations",
    "currentBalance": "Current Balance",
    "deposit": "Deposit to Safe",
    "payout": "Payout",
    "amount": "Amount",
    "reason": "Reason",
    "operations": "Recent Operations"
  },
  "reports": {
    "xReport": "X Report (Mid-Shift)",
    "zReport": "Z Report (End of Day)",
    "salesSummary": "Sales Summary",
    "vatBreakdown": "VAT Breakdown",
    "paymentMethods": "Payment Methods",
    "fiscalIntegrity": "Fiscal Integrity (NF525)",
    "salesCount": "Sales Count",
    "grossSales": "Gross Sales",
    "netSales": "Net Sales",
    "taxAmount": "Tax Amount"
  },
  "errors": {
    "shiftNotOpen": "No open shift. Please open a shift first.",
    "shiftAlreadyOpen": "This terminal already has an open shift.",
    "emptyCart": "Cannot checkout with empty cart.",
    "insufficientStock": "Insufficient stock for {{product}}."
  }
}
```

**Usage:**

```tsx
import { useTranslation } from 'react-i18next'

function ShiftStatusCard() {
  const { t } = useTranslation('pos')

  return (
    <div>
      <h2>{t('shift.title')}</h2>
      <p>{t('shift.currentBalance')}: {balance} TND</p>
    </div>
  )
}
```

---

## Security Considerations

### Authorization

**Required Permissions:**
- `pos.use` - Access POS interface
- `pos.open_shift` - Open shifts
- `pos.close_shift` - Close shifts (may require manager)
- `pos.generate_reports` - Generate X/Z reports
- `pos.cash_operations` - Deposit/payout operations

**Middleware:**
- All POS routes require `auth:sanctum` middleware
- Company context set via `SetPermissionsTeam` middleware
- Terminal must belong to user's company

### Data Validation

**Frontend:**
- Cart validation before checkout
- Stock level checks
- Payment amount validation
- Opening/actual cash >= 0

**Backend:**
- All monetary amounts validated to 2 decimals
- Business rule enforcement (shift constraints)
- Hash chain integrity on every Z report

---

## Performance Considerations

### Product Search Optimization

**Problem:** Searching 10,000+ products can be slow.

**Solution:**
- Use Meilisearch for instant product search
- Debounce search input (300ms)
- Limit results to 50 products
- Virtual scrolling for large product lists

**Implementation:**

```typescript
const { data: products } = useProductSearch(searchTerm, {
  limit: 50,
  debounce: 300,
  enabled: searchTerm.length >= 2
})
```

### Cart Performance

**Problem:** Cart updates cause re-renders.

**Solution:**
- Zustand selector optimization
- Memoize computed values (subtotal, tax, total)
- Avoid re-rendering entire cart on quantity change

```typescript
// Only subscribe to items array
const items = useCartStore(state => state.items)

// Memoized totals
const total = useCartStore(state => state.total)
```

### Real-time Updates

**Future Enhancement:**
- WebSocket connection for multi-terminal sync
- Server push for stock level updates
- Notify if another terminal closes shift (affects reports)

---

## Testing Strategy

### Unit Tests

**Components to Test:**
- `CartTotals.tsx` - Tax calculation accuracy
- `CartLineItem.tsx` - Quantity adjustment logic
- `PaymentMethodSelector.tsx` - Selection state
- `SplitPaymentBuilder.tsx` - Split payment validation

**Hooks to Test:**
- `useCartStore.ts` - Cart mutations
- `useCheckout.ts` - Checkout flow
- `useCashDrawer.ts` - Balance calculation

### Integration Tests

**Flows to Test:**
1. **Open Shift → Add Products → Checkout → Close Shift**
   - Verify shift opens correctly
   - Cart updates correctly
   - Checkout creates receipt
   - Shift closes with variance

2. **Cash Drawer Operations**
   - Deposit reduces balance
   - Payout reduces balance
   - Operations list updates

3. **X and Z Reports**
   - X report generated multiple times
   - Z report closes shift
   - Hash chain verification

### E2E Tests (Playwright)

**Critical Path:**
1. Login as cashier
2. Navigate to POS
3. Open shift with 200 TND
4. Search for product
5. Add 3 products to cart
6. Checkout with cash
7. Verify receipt created
8. Generate X report
9. Close shift
10. Verify Z report generated

---

## Implementation Phases

### Phase 1: Core POS (Week 1)
- [ ] Shift management (open/close)
- [ ] Product search and selection
- [ ] Cart management (add/remove/qty)
- [ ] Basic checkout (cash only)
- [ ] Receipt creation API integration

### Phase 2: Advanced Features (Week 2)
- [ ] Cash drawer operations (deposit/payout)
- [ ] Split payment support
- [ ] Customer association (optional)
- [ ] X report generation
- [ ] Z report generation

### Phase 3: Polish (Week 3)
- [ ] Keyboard shortcuts
- [ ] Print receipt functionality
- [ ] Error handling and validation
- [ ] Loading states and feedback
- [ ] Responsive design (tablet)

### Phase 4: Testing (Week 4)
- [ ] Unit tests
- [ ] Integration tests
- [ ] E2E tests
- [ ] Performance optimization
- [ ] Accessibility audit

---

## Open Questions

1. **Receipt Printing:**
   - Use browser print dialog?
   - Direct thermal printer integration (requires Tauri)?
   - PDF generation for download?

2. **Product Images:**
   - Show product images in grid?
   - If yes, need image upload in product management
   - Placeholder images for products without photos?

3. **Customer Association:**
   - Required or optional on checkout?
   - Create customer on-the-fly during transaction?
   - Loyalty points integration (future)?

4. **Parked Transactions:**
   - Allow cashier to "park" incomplete transactions?
   - Store in local storage or backend?
   - How many concurrent parked transactions?

5. **Refund Flow:**
   - Refund by original receipt number?
   - Partial refunds supported?
   - Stock return on refund?

6. **Multi-Terminal Sync:**
   - Real-time stock updates across terminals?
   - WebSocket for live updates?
   - Conflict resolution for concurrent sales?

---

## Next Steps

1. **Review and Approve** this specification document
2. **Answer open questions** above
3. **Create initial components** (Phase 1)
4. **Set up routing** (`/pos/shift` and `/pos`)
5. **Implement shift management** first (blocking for POS access)
6. **Build main POS screen** with cart
7. **Implement checkout flow**
8. **Add cash drawer operations**
9. **Add X/Z reports**
10. **Test and polish**

---

*Document End*
