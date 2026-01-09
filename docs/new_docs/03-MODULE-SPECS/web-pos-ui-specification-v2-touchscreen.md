# Web POS UI Specification v2 - Touch Screen Optimized

**Date:** 2026-01-08
**Status:** Updated Requirements
**Based on:** web-pos-ui-specification.md

---

## Critical Updates from User Feedback

This document captures the updated requirements for touch-screen optimization and missing payment/customer features.

---

## 1. Touch Screen Optimization Strategy

### Approach: Single Adaptive UI

**Decision:** One interface that adapts to touch vs. mouse/keyboard rather than two separate views.

**Why:**
- Simpler maintenance (one codebase)
- Users may switch between devices
- Modern responsive design can handle both well

**Adaptive Design Rules:**

| Element | Mouse/Keyboard | Touch Screen |
|---------|----------------|--------------|
| Product Cards | Hover shows info | Always show info button |
| Buttons | Normal size (px-4 py-2) | Larger touch targets (px-6 py-4) |
| Inputs | Normal | Larger text, number pad overlay |
| Spacing | Compact | More generous (touch targets 44px+) |
| Modals | Standard size | Full-screen on mobile/tablet |

**Detection:**

```typescript
const isTouchDevice = window.matchMedia('(pointer: coarse)').matches
// OR detect by screen size for tablets
const isTablet = window.innerWidth >= 768 && window.innerWidth < 1280
```

---

## 2. Updated Product Card Design

### Product Card with Info Button

```
┌─────────────────────────┐
│   [Product Image]       │
│                         │  <- Click anywhere to add to cart
│   Oil Filter        [ℹ️] │  <- Info button (eye or "i" icon)
│   SKU: 1234              │
│   15.50 TND              │
│   ● Stock: 45            │  <- Green dot if in stock
│   ⚠️ Low Stock: 5        │  <- Yellow if < 10
│   ⛔ Out of Stock        │  <- Red if 0
└─────────────────────────┘
```

**Touch Interaction:**
- **Tap card body** → Add to cart (qty = 1)
- **Tap info button (ℹ️)** → Open ProductInfoModal

**Mouse Interaction:**
- **Click card body** → Add to cart
- **Click info button** → Open ProductInfoModal
- **Hover card** → Highlight border

---

## 3. Product Info Modal

**Triggered by:** Info button on product card

**Layout:**

```
┌─────────────────────────────────────────────────────────────────┐
│  Product Details                                          [✕]   │
├─────────────────────────────────────────────────────────────────┤
│                                                                   │
│  ┌──────────────┐  Oil Filter - Premium Grade                   │
│  │              │  SKU: OF-1234                                  │
│  │  [Product]   │  Category: Filters > Engine                   │
│  │  [Image]     │  Brand: Mann-Filter                           │
│  │              │  Price: 15.50 TND (excl. tax)                 │
│  └──────────────┘  Tax Rate: 19%                                │
│                   Price incl. tax: 18.45 TND                     │
│                                                                   │
│  ┌─────────────────────────────────────────────────────────┐   │
│  │  📦 Stock Information                                   │   │
│  │  ─────────────────────────────────────────────────────  │   │
│  │  Main Warehouse: 45 units                              │   │
│  │  Shop Floor: 12 units                                  │   │
│  │  Reserved: 3 units                                     │   │
│  │  Available: 54 units                                   │   │
│  └─────────────────────────────────────────────────────────┘   │
│                                                                   │
│  [Tabs: Details | Related Products | Suppliers]                 │
│                                                                   │
│  ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━  │
│                                                                   │
│  ⚠️ Out of Stock or Low Stock Actions:                          │
│                                                                   │
│  [🛒 Order from B2B Marketplace]  [📋 Add to Purchase Order]    │
│                                                                   │
│  ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━  │
│                                                                   │
│  [Close]                                     [Add to Cart (×1)]  │
│                                                                   │
└─────────────────────────────────────────────────────────────────┘
```

**Tab: Related Products**

```
┌─────────────────────────────────────────────────────────────────┐
│  Related Products                                                │
├─────────────────────────────────────────────────────────────────┤
│                                                                   │
│  Frequently bought together:                                     │
│                                                                   │
│  ☑️ Oil Filter (this item)                     15.50 TND         │
│  □ Engine Oil 5W30 (5L)                        45.00 TND [+]     │
│  □ Drain Plug Washer                            1.50 TND [+]     │
│                                                                   │
│  [Add Selected to Cart]                                          │
│                                                                   │
│  ─────────────────────────────────────────────────────────────  │
│                                                                   │
│  Compatible products:                                            │
│                                                                   │
│  ┌───────────┐ ┌───────────┐ ┌───────────┐                     │
│  │ Air Filter│ │ Fuel Filter│ │ Cabin Filt│                     │
│  │ 22.00 TND │ │ 28.00 TND  │ │ 35.00 TND │                     │
│  │   [+]     │ │   [+]      │ │   [+]     │                     │
│  └───────────┘ └───────────┘ └───────────┘                     │
│                                                                   │
└─────────────────────────────────────────────────────────────────┘
```

**Tab: Suppliers**

```
┌─────────────────────────────────────────────────────────────────┐
│  Supplier Information                                            │
├─────────────────────────────────────────────────────────────────┤
│                                                                   │
│  Primary Supplier: AutoParts Tunisia                             │
│  Lead Time: 2-3 days                                             │
│  Minimum Order: 10 units                                         │
│  Last Purchase Price: 12.50 TND (2025-12-15)                     │
│                                                                   │
│  Alternative Suppliers:                                          │
│  - MechSupply SARL (Lead: 1 week, MOQ: 5)                       │
│  - EuroAuto Import (Lead: 2 weeks, MOQ: 20)                     │
│                                                                   │
└─────────────────────────────────────────────────────────────────┘
```

**Components:**
- `ProductInfoModal` - Full-screen modal on touch, large modal on desktop
- `ProductDetailsTab` - Stock info, pricing, specifications
- `RelatedProductsTab` - Frequently bought together, compatible items
- `SuppliersTab` - Supplier info, lead times, pricing history
- `B2BMarketplaceButton` - Order from external marketplace (future)
- `AddToPurchaseOrderButton` - Create PO for restock

**Future Features (Placeholders for Now):**
- B2B marketplace integration (API not yet available)
- Purchase order creation (link to existing PO module)
- Related products (requires product relationships in DB)

---

## 4. Updated Main POS Layout - Customer Selection

### New Top Section (Above Product Grid)

```
┌─────────────────────────────────────────────────────────────────┐
│  👤 Customer: [Walk-in Customer ▼]                    [Change]  │
│                                                                   │
│  OR (if customer selected)                                       │
│                                                                   │
│  👤 Customer: Ahmed Ben Ali (ID: 12345)               [Change]   │
│      Phone: +216 98 123 456 | Balance: -150.50 TND               │
└─────────────────────────────────────────────────────────────────┘
```

**Customer Selection Modal:**

```
┌─────────────────────────────────────────────────────────────────┐
│  Select Customer                                          [✕]   │
├─────────────────────────────────────────────────────────────────┤
│                                                                   │
│  [Search existing customer or add new...]                       │
│                                                                   │
│  ┌───────────────┐  ┌───────────────┐                          │
│  │  👤 Walk-in   │  │  ➕ Quick Add  │                          │
│  │  Customer     │  │  New Customer │                          │
│  │               │  │               │                          │
│  │  Anonymous    │  │  Name + Phone │                          │
│  │  [Select]     │  │  [Create]     │                          │
│  └───────────────┘  └───────────────┘                          │
│                                                                   │
│  ─────────────────────────────────────────────────────────────  │
│                                                                   │
│  Recent Customers:                                               │
│                                                                   │
│  ┌─────────────────────────────────────────────────────────┐   │
│  │ Ahmed Ben Ali                                           │   │
│  │ Phone: +216 98 123 456                                  │   │
│  │ Balance: -150.50 TND                      [Select]      │   │
│  └─────────────────────────────────────────────────────────┘   │
│                                                                   │
│  ┌─────────────────────────────────────────────────────────┐   │
│  │ Fatima Trabelsi                                         │   │
│  │ Phone: +216 22 987 654                                  │   │
│  │ Balance: 0.00 TND                         [Select]      │   │
│  └─────────────────────────────────────────────────────────┘   │
│                                                                   │
└─────────────────────────────────────────────────────────────────┘
```

**Quick Add Customer Form:**

```
┌─────────────────────────────────────────────────────────────────┐
│  Quick Add Customer                                       [✕]   │
├─────────────────────────────────────────────────────────────────┤
│                                                                   │
│  Customer Name *                                                 │
│  [_____________________________________]                         │
│                                                                   │
│  Phone Number *                                                  │
│  [_____________________________________]                         │
│                                                                   │
│  Email (optional)                                                │
│  [_____________________________________]                         │
│                                                                   │
│  Create Full Account?                                            │
│  □ Yes, create full customer account with credit terms          │
│                                                                   │
│  [Cancel]                               [Create & Select]        │
│                                                                   │
└─────────────────────────────────────────────────────────────────┘
```

**Full Customer Account Form (if checkbox selected):**

```
┌─────────────────────────────────────────────────────────────────┐
│  Create Full Customer Account                             [✕]   │
├─────────────────────────────────────────────────────────────────┤
│                                                                   │
│  [Basic Info] [Credit Terms] [Tax Info] [Contacts]              │
│                                                                   │
│  Company Name *                                                  │
│  [_____________________________________]                         │
│                                                                   │
│  Tax ID (optional)                                               │
│  [_____________________________________]                         │
│                                                                   │
│  Credit Limit                                                    │
│  [__________] TND                                                │
│                                                                   │
│  Payment Terms                                                   │
│  [Net 30 Days ▼]                                                 │
│                                                                   │
│  Allow Loyalty Points?                                           │
│  ☑️ Yes, enable loyalty program                                  │
│                                                                   │
│  [Previous]  [Cancel]                          [Create Account]  │
│                                                                   │
└─────────────────────────────────────────────────────────────────┘
```

---

## 5. Updated Checkout Flow

### Quick Checkout vs. Advanced Payments

**Updated Cart Footer (Right Panel):**

```
┌─────────────────────────────────────────────────────────────────┐
│  Subtotal:                          78.00 TND                    │
│  Tax (19%):                         14.82 TND                    │
│  ─────────────────────────────────────────────────────────────  │
│  Total:                             92.82 TND                    │
│                                                                   │
│  ┌─────────────────────────────────────────────────────────┐   │
│  │                                                         │   │
│  │     [💰 Quick Checkout (Cash)]                          │   │
│  │                                                         │   │
│  │     Press F12 or tap for instant cash checkout         │   │
│  │                                                         │   │
│  └─────────────────────────────────────────────────────────┘   │
│                                                                   │
│  ┌─────────────────────────────────────────────────────────┐   │
│  │                                                         │   │
│  │     [⚙️ Advanced Payments]                              │   │
│  │                                                         │   │
│  │     Split payments, checks, balance, loyalty points    │   │
│  │                                                         │   │
│  └─────────────────────────────────────────────────────────┘   │
│                                                                   │
└─────────────────────────────────────────────────────────────────┘
```

**Quick Checkout (Cash Only):**

```
┌─────────────────────────────────────────────────────────────────┐
│  Quick Cash Checkout                                      [✕]   │
├─────────────────────────────────────────────────────────────────┤
│                                                                   │
│   Total: 92.82 TND                                               │
│                                                                   │
│   Customer: Ahmed Ben Ali (Balance: -150.50 TND)                │
│                                                                   │
│   ┌─────────────────────────────────────────────────────────┐   │
│   │  Amount Received (Cash)                                │   │
│   │  [__________] TND                                      │   │
│   │                                                        │   │
│   │  Change: 7.18 TND                                      │   │
│   └─────────────────────────────────────────────────────────┘   │
│                                                                   │
│   [Cancel]                          [Complete Sale] 💰           │
│                                                                   │
└─────────────────────────────────────────────────────────────────┘
```

**Advanced Payments Modal (Full Sales Module Integration):**

```
┌─────────────────────────────────────────────────────────────────┐
│  Advanced Payments                                        [✕]   │
├─────────────────────────────────────────────────────────────────┤
│                                                                   │
│   Total: 92.82 TND                                               │
│   Customer: Ahmed Ben Ali                                        │
│   Balance: -150.50 TND | Loyalty Points: 450 pts                │
│                                                                   │
│   ┌─────────────────────────────────────────────────────────┐   │
│   │  Payment Methods                                        │   │
│   │  ─────────────────────────────────────────────────────  │   │
│   │                                                         │   │
│   │  1. 💵 Cash                     50.00 TND      [x]      │   │
│   │     Received: [100.00] Change: 50.00                   │   │
│   │                                                         │   │
│   │  2. 💳 Card                     42.82 TND      [x]      │   │
│   │     Auth Code: [________]                              │   │
│   │                                                         │   │
│   │  [+ Add Payment Method ▼]                              │   │
│   │     - Check (split into multiple)                      │   │
│   │     - Bank Draft (split into multiple)                 │   │
│   │     - Customer Balance                                 │   │
│   │     - Loyalty Points                                   │   │
│   │                                                         │   │
│   └─────────────────────────────────────────────────────────┘   │
│                                                                   │
│   Remaining: 0.00 TND  ✓                                         │
│                                                                   │
│   ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━  │
│                                                                   │
│   ⚠️ Overpayment Options (if received > total):                 │
│                                                                   │
│   ○ Give Change (default)                                        │
│   ○ Add to Customer Balance                                      │
│   ○ Apply to Open Invoices (INV-1234, INV-1256)                 │
│                                                                   │
│   ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━  │
│                                                                   │
│   [Cancel]                          [Complete Sale] 💰           │
│                                                                   │
└─────────────────────────────────────────────────────────────────┘
```

**Payment from Customer Balance:**

```
┌─────────────────────────────────────────────────────────────────┐
│  Pay from Customer Balance                                       │
├─────────────────────────────────────────────────────────────────┤
│                                                                   │
│   Customer: Ahmed Ben Ali                                        │
│   Current Balance: -150.50 TND (credit)                          │
│                                                                   │
│   Amount to Apply:                                               │
│   [________] TND (max: 92.82 TND)                                │
│                                                                   │
│   New Balance: -243.32 TND                                       │
│                                                                   │
│   [Cancel]                                          [Apply]      │
│                                                                   │
└─────────────────────────────────────────────────────────────────┘
```

**Payment from Loyalty Points:**

```
┌─────────────────────────────────────────────────────────────────┐
│  Pay with Loyalty Points                                         │
├─────────────────────────────────────────────────────────────────┤
│                                                                   │
│   Customer: Ahmed Ben Ali                                        │
│   Available Points: 450 pts                                      │
│   Conversion Rate: 10 pts = 1 TND                                │
│                                                                   │
│   Points to Redeem:                                              │
│   [________] pts (max: 450 pts = 45.00 TND)                      │
│                                                                   │
│   Amount: 45.00 TND                                              │
│   Remaining Points: 0 pts                                        │
│                                                                   │
│   [Cancel]                                          [Redeem]     │
│                                                                   │
└─────────────────────────────────────────────────────────────────┘
```

**Split Check Payment:**

```
┌─────────────────────────────────────────────────────────────────┐
│  Split Check Payment                                             │
├─────────────────────────────────────────────────────────────────┤
│                                                                   │
│   Total Amount: 92.82 TND                                        │
│                                                                   │
│   Split into how many checks?                                    │
│   [3] checks                                                     │
│                                                                   │
│   ┌─────────────────────────────────────────────────────────┐   │
│   │  Check 1: [30.00] TND   Maturity: [2026-02-08] [x]     │   │
│   │  Check 2: [30.00] TND   Maturity: [2026-03-08] [x]     │   │
│   │  Check 3: [32.82] TND   Maturity: [2026-04-08] [x]     │   │
│   └─────────────────────────────────────────────────────────┘   │
│                                                                   │
│   [- Remove] [+ Add Check]                                       │
│                                                                   │
│   Total: 92.82 TND  ✓                                            │
│                                                                   │
│   [Cancel]                                          [Apply]      │
│                                                                   │
└─────────────────────────────────────────────────────────────────┘
```

---

## 6. Overpayment Logic

### Scenario: Customer Pays More Than Total

**Example:**
- Total: 92.82 TND
- Customer gives: 150.00 TND
- Overpayment: 57.18 TND

**Options:**

1. **Give Change (Default)**
   - Cashier returns 57.18 TND
   - No balance change
   - Standard cash transaction

2. **Add to Customer Balance**
   - No change given
   - Customer balance credited: +57.18 TND
   - Customer can use credit on next visit

3. **Apply to Open Invoices**
   - No change given
   - Show list of customer's open invoices
   - Cashier selects which invoices to pay
   - Remaining credit added to balance

**Implementation:**

```typescript
interface OverpaymentOption {
  type: 'change' | 'balance' | 'invoices'
  invoiceIds?: string[] // If type === 'invoices'
}

// In checkout flow
if (amountReceived > total) {
  const overpayment = amountReceived - total

  // Show modal with options
  const option = await showOverpaymentModal(overpayment, customer)

  switch (option.type) {
    case 'change':
      // Normal flow - calculate change
      break

    case 'balance':
      // Credit customer balance
      await creditCustomerBalance(customer.id, overpayment)
      break

    case 'invoices':
      // Apply to selected invoices
      await applyToInvoices(customer.id, option.invoiceIds, overpayment)
      break
  }
}
```

**Customer Balance Integration:**

```typescript
// After checkout
if (customer && overpaymentOption.type === 'balance') {
  // Create payment allocation
  await apiPost('/treasury/payments/allocations', {
    customer_id: customer.id,
    amount: overpayment,
    type: 'prepayment',
    notes: 'Overpayment from POS receipt #' + receiptNumber
  })
}
```

---

## 7. Smart Payments Integration

### Reuse Existing Sales Module Logic

**Components to Reuse:**
- `MultiPaymentSelector` (from sales module)
- `PaymentAllocationService` (backend)
- `CheckSplitBuilder` (split checks)
- `BankDraftSplitBuilder` (split bank drafts)

**New POS-Specific Extensions:**

```typescript
// apps/web/src/features/pos/components/POSPaymentBuilder.tsx

import { MultiPaymentSelector } from '@/features/sales/components/MultiPaymentSelector'

export function POSPaymentBuilder({
  total,
  customer,
  onComplete
}: POSPaymentBuilderProps) {
  return (
    <MultiPaymentSelector
      total={total}
      customer={customer}
      availablePaymentTypes={[
        'cash',
        'card',
        'check',
        'bank_draft',
        'customer_balance',
        'loyalty_points'
      ]}
      onComplete={(payments) => {
        // Create receipt with multiple payments
        onComplete(payments)
      }}
    />
  )
}
```

**Backend Integration:**

```php
// Reuse existing MultiPaymentService
$payments = $this->multiPaymentService->processPayments($receipt, $paymentData);

// If overpayment and customer selected "balance"
if ($overpayment > 0 && $request->input('apply_to_balance')) {
    $this->customerBalanceService->credit(
        $customer->id,
        $overpayment,
        'Overpayment from receipt ' . $receipt->receipt_number
    );
}

// If overpayment and customer selected "invoices"
if ($overpayment > 0 && $request->input('apply_to_invoices')) {
    $this->paymentAllocationService->allocateToInvoices(
        $customer->id,
        $request->input('invoice_ids'),
        $overpayment
    );
}
```

---

## 8. Touch Screen Specific Enhancements

### Large Touch Targets

**All buttons minimum 44x44px (Apple HIG) or 48x48px (Material Design):**

```tsx
// Touch-optimized button
<Button
  className={cn(
    'px-6 py-4', // Larger padding
    'text-lg',   // Larger text
    'min-h-[48px]', // Minimum touch target
    isTouchDevice && 'px-8 py-6 text-xl' // Even larger on touch
  )}
>
  Add to Cart
</Button>
```

### Number Pad for Cash Input

**On touch devices, show custom number pad:**

```
┌─────────────────────────────────────────────────────────────────┐
│  Amount Received                                                 │
│  ┌─────────────────────────────────────────────────────────┐   │
│  │                                             [150.00] TND │   │
│  └─────────────────────────────────────────────────────────┘   │
│                                                                   │
│  ┌─────────────────────────────────────────────────────────┐   │
│  │   [7]   [8]   [9]                             [Clear]   │   │
│  │   [4]   [5]   [6]                             [Back]    │   │
│  │   [1]   [2]   [3]                             [.00]     │   │
│  │   [0]   [00]  [.]                             [Enter]   │   │
│  └─────────────────────────────────────────────────────────┘   │
│                                                                   │
└─────────────────────────────────────────────────────────────────┘
```

**Component:**

```tsx
// apps/web/src/features/pos/components/TouchNumberPad.tsx

export function TouchNumberPad({
  value,
  onChange,
  onSubmit
}: TouchNumberPadProps) {
  const buttons = [
    ['7', '8', '9', 'clear'],
    ['4', '5', '6', 'back'],
    ['1', '2', '3', '.00'],
    ['0', '00', '.', 'enter']
  ]

  return (
    <div className="grid grid-cols-4 gap-2">
      {buttons.flat().map(btn => (
        <Button
          key={btn}
          onClick={() => handlePress(btn)}
          className="h-16 text-2xl font-bold"
        >
          {btn}
        </Button>
      ))}
    </div>
  )
}
```

### Swipe Gestures

**For cart items on touch:**
- Swipe left → Remove item
- Swipe right → Show quick actions (info, adjust qty)

```tsx
// Use react-swipeable
import { useSwipeable } from 'react-swipeable'

const handlers = useSwipeable({
  onSwipedLeft: () => removeItem(item.id),
  onSwipedRight: () => showQuickActions(item),
  preventDefaultTouchmoveEvent: true
})

<div {...handlers}>
  <CartLineItem item={item} />
</div>
```

---

## 9. Updated Component List

### New Components

| Component | Purpose | Priority |
|-----------|---------|----------|
| `ProductInfoModal` | Detailed product info with tabs | P0 |
| `RelatedProductsTab` | Show compatible/related products | P1 |
| `B2BMarketplaceButton` | Order from marketplace (future) | P2 |
| `AddToPurchaseOrderButton` | Create PO for out-of-stock | P1 |
| `CustomerSelector` | Select/search/add customer | P0 |
| `QuickAddCustomerForm` | Fast customer creation | P0 |
| `FullCustomerAccountForm` | Complete account setup | P1 |
| `QuickCheckoutButton` | One-click cash checkout | P0 |
| `AdvancedPaymentsButton` | Open smart payments modal | P0 |
| `POSPaymentBuilder` | Reuse MultiPaymentSelector | P0 |
| `OverpaymentOptionsModal` | Handle excess payment | P0 |
| `PayFromBalanceForm` | Use customer balance | P1 |
| `PayFromLoyaltyPointsForm` | Redeem loyalty points | P1 |
| `SplitCheckBuilder` | Split into multiple checks | P1 |
| `SplitBankDraftBuilder` | Split into multiple drafts | P1 |
| `TouchNumberPad` | Touch-friendly number input | P0 |
| `SwipeableCartItem` | Swipe to delete cart items | P1 |

---

## 10. Updated API Requirements

### Customer Balance Endpoints

**Get Customer Balance:**
```
GET /api/v1/partners/{id}/balance
Response: { balance: "150.50", currency: "TND" }
```

**Credit Customer Balance:**
```
POST /api/v1/treasury/payments/prepayment
Body: {
  customer_id: "uuid",
  amount: "57.18",
  notes: "Overpayment from receipt #..."
}
```

**Get Open Invoices:**
```
GET /api/v1/partners/{id}/open-invoices
Response: {
  data: [
    { id: "uuid", invoice_number: "INV-1234", balance: "100.00" },
    { id: "uuid", invoice_number: "INV-1256", balance: "50.50" }
  ]
}
```

**Apply Payment to Invoices:**
```
POST /api/v1/treasury/payments/allocations
Body: {
  customer_id: "uuid",
  allocations: [
    { invoice_id: "uuid", amount: "100.00" },
    { invoice_id: "uuid", amount: "50.50" }
  ]
}
```

### Loyalty Points Endpoints (Future)

```
GET /api/v1/partners/{id}/loyalty-points
POST /api/v1/loyalty/redeem
POST /api/v1/loyalty/earn
```

---

## 11. Implementation Priority

### Phase 1A: Core Functionality (Week 1)
- [x] Shift management (already done)
- [ ] Customer selection (walk-in, search, quick add)
- [ ] Product grid with info button
- [ ] Cart management
- [ ] Quick checkout (cash only)
- [ ] Touch-optimized UI (larger buttons)

### Phase 1B: Product Info (Week 2)
- [ ] ProductInfoModal with tabs
- [ ] Stock information display
- [ ] Basic related products (manual configuration)
- [ ] Placeholder for B2B marketplace
- [ ] Placeholder for PO creation

### Phase 2A: Advanced Payments (Week 3)
- [ ] Advanced payments modal
- [ ] Reuse MultiPaymentSelector
- [ ] Split payments (cash + card)
- [ ] Check splitting
- [ ] Bank draft splitting

### Phase 2B: Customer Integration (Week 3-4)
- [ ] Customer balance payment
- [ ] Overpayment options modal
- [ ] Apply to open invoices
- [ ] Loyalty points (if ready)

### Phase 3: Touch Optimization (Week 4)
- [ ] Touch number pad
- [ ] Swipe gestures
- [ ] Large touch targets
- [ ] Tablet layout optimization

### Phase 4: Future Enhancements (Post-MVP)
- [ ] B2B marketplace integration
- [ ] Purchase order creation from POS
- [ ] Related products engine
- [ ] Loyalty points system
- [ ] Receipt printer integration

---

## 12. Open Questions (Updated)

1. **B2B Marketplace:**
   - Which marketplace API are we integrating?
   - Authentication flow?
   - Order placement workflow?

2. **Purchase Order Creation:**
   - Create PO immediately or add to draft?
   - Auto-select supplier based on last purchase?
   - Send PO to supplier automatically?

3. **Related Products:**
   - How to define product relationships?
   - Manual configuration or ML-based recommendations?
   - Show in modal only or also in cart?

4. **Loyalty Points:**
   - Points earning rate? (e.g., 1 point per 10 TND spent)
   - Points redemption rate? (e.g., 10 points = 1 TND discount)
   - Expiration policy?
   - Minimum redemption threshold?

5. **Customer Balance:**
   - Maximum credit limit enforcement?
   - Alert if balance exceeds limit?
   - Require manager approval for large credits?

6. **Overpayment to Invoices:**
   - Oldest invoices first (FIFO)?
   - Let cashier choose which invoices?
   - Show invoice aging (30/60/90 days)?

---

## 13. Next Steps

1. **Answer open questions** above
2. **Review and approve** this updated specification
3. **Start Phase 1A implementation**:
   - Customer selection component
   - Touch-optimized buttons
   - Product info modal
   - Quick checkout flow
4. **Parallel work:**
   - Backend: Customer balance APIs
   - Backend: Payment allocation service
   - Frontend: Reuse MultiPaymentSelector

---

*Document End*
