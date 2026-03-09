# Backend Audit - Current State for F&B POS

> Audit of all backend modules relevant to the Tauri POS, performed 2026-03-05.

## Module Overview

### POS Module (`Modules/POS/`)

**Status: Production-ready for basic retail, partially ready for F&B**

#### Implemented
- Receipt creation with fiscal hash chaining (NF525)
- Receipt lines with product/composite item support
- Receipt voiding with stock and cash reversal
- Split payments (multiple payment methods per receipt)
- Consumption mode: `SUR_PLACE` (dine-in), `A_EMPORTER` (takeout)
- Discount orchestration: Manual, Promotion, Coupon, Loyalty sources
- Discount stacking with exclusive/non-exclusive rules
- Terminal management (Web and Physical types)
- Shift management (open/close with cash counting)
- Cash drawer operations (deposits, payouts, audit trail)
- X-Reports (mid-shift snapshots)
- Z-Reports (end-of-day closing with hash chain)
- Grand total event tracking
- Stock decrement with pessimistic locking
- FEFO batch allocation for perishables
- PDF receipt generation

#### Models
| Model | Table | Purpose |
|-------|-------|---------|
| Receipt | `pos_receipts` | Immutable fiscal receipt |
| ReceiptLine | `pos_receipt_lines` | Line items (product or composite item) |
| ReceiptPayment | `pos_receipt_payments` | Payment methods applied |
| ReceiptVatDetail | `pos_receipt_vat_details` | VAT breakdown by rate |
| Shift | `pos_shifts` | Cashier work session |
| Terminal | `pos_terminals` | POS device with hash chain |
| CashDrawerOperation | `pos_cash_drawer_operations` | Cash movement audit trail |
| XReport | `pos_x_reports` | Mid-shift snapshot |
| ZReport | `pos_z_reports` | Fiscally sealed closing report |
| GrandtotalEvent | `pos_grandtotal_events` | Running grand totals |
| ReceiptLineBatchAllocation | `pos_receipt_line_batch_allocations` | FEFO batch tracking |

#### API Endpoints
```
# Terminals
GET    /api/v1/pos/terminals
POST   /api/v1/pos/terminals/web
POST   /api/v1/pos/terminals
GET    /api/v1/pos/terminals/{id}
PATCH  /api/v1/pos/terminals/{id}
DELETE /api/v1/pos/terminals/{id}
PATCH  /api/v1/pos/terminals/{id}/activate
PATCH  /api/v1/pos/terminals/{id}/deactivate

# Shifts
POST   /api/v1/pos/shifts/open
POST   /api/v1/pos/shifts/{id}/close
GET    /api/v1/pos/shifts/current/{terminalId}
GET    /api/v1/pos/shifts/{id}
GET    /api/v1/pos/shifts
GET    /api/v1/pos/shifts/{id}/receipts

# Receipts
GET    /api/v1/pos/receipts
POST   /api/v1/pos/receipts
GET    /api/v1/pos/receipts/{id}
POST   /api/v1/pos/receipts/{id}/void
POST   /api/v1/pos/receipts/{id}/payments
GET    /api/v1/pos/receipts/{id}/pdf
GET    /api/v1/pos/receipts/{id}/pdf/download

# Cash Drawer
POST   /api/v1/pos/cash-drawer/deposit
POST   /api/v1/pos/cash-drawer/payout
GET    /api/v1/pos/cash-drawer/{shiftId}/operations
GET    /api/v1/pos/cash-drawer/{shiftId}/balance

# Reports
POST   /api/v1/pos/reports/x
POST   /api/v1/pos/reports/z
GET    /api/v1/pos/reports/z/{zNumber}
GET    /api/v1/pos/reports/z
POST   /api/v1/pos/reports/z/verify-chain

# Discounts
GET    /api/v1/pos/discount-permissions
POST   /api/v1/pos/cart/preview-discounts
```

---

### Menu Module (`Modules/Menu/`)

**Status: Functional, covers core F&B menu needs**

#### Implemented
- Menu CRUD (create, update, delete)
- Menu categories with display ordering and active flag
- Menu category items (linking composite items to categories)
- Item availability toggle per category
- Override pricing per menu context
- Active menu resolution (POS-facing endpoint)
- Eager loading of modifier groups and modifiers per item

#### Models
| Model | Purpose |
|-------|---------|
| Menu | Named menu (e.g., "Lunch Menu", "Drinks") |
| MenuCategory | Category within a menu (e.g., "Hot Drinks", "Pastries") |
| MenuCategoryItem | Pivot: CompositeItem in a category with override_price, display_order, is_available |

#### API Endpoints
```
# Menus
GET    /api/v1/menus
POST   /api/v1/menus
GET    /api/v1/menus/{id}
PATCH  /api/v1/menus/{id}
DELETE /api/v1/menus/{id}

# Categories
POST   /api/v1/menus/{menuId}/categories
PATCH  /api/v1/menu-categories/{id}
DELETE /api/v1/menu-categories/{id}

# Category Items
PUT    /api/v1/menu-categories/{id}/items       (sync)
POST   /api/v1/menu-categories/{id}/items       (add)
DELETE /api/v1/menu-categories/{categoryId}/items/{compositeItemId}

# Active Menu (POS endpoint)
GET    /api/v1/active-menu
```

---

### Catalog Module (`Modules/Catalog/`)

**Status: Comprehensive, supports F&B composite items**

#### Implemented
- Product CRUD with variants
- Composite items (combos, meals, recipes)
- Modifier groups with min/max selection constraints
- Individual modifiers with pricing
- Recipes with recipe lines (ingredient tracking)
- Category management
- Tax rate assignment

#### Key Entities for F&B
- **CompositeItem**: A menu item (e.g., "Latte") with a base price, tax rate, and linked modifier groups
- **ModifierGroup**: A group of options (e.g., "Milk Choice") with min/max selection
- **Modifier**: An option within a group (e.g., "Oat Milk +0.50")
- **Recipe / RecipeLine**: Ingredient tracking for stock decrement

---

### Loyalty Module (`Modules/Loyalty/`)

**Status: Full-featured, integrated with POS**

#### Implemented
- Loyalty programs (Points, Stamp Card types)
- Earning rules (per-spend, per-visit, per-item, per-category)
- Tiers with benefits (discount percentage, point multiplier)
- Rewards (free item, discount, custom)
- Stamp card definitions
- Member management and enrollment
- Point earning on receipt completion (event listener)
- Point redemption at POS
- Tier evaluation and auto-upgrade/downgrade

---

### Promotion Module (`Modules/Promotion/`)

**Status: Implemented, integrated with POS discount orchestrator**

- Automatic promotions evaluated at checkout
- Cart context evaluation (items, quantities, totals)

---

### Coupon Module (`Modules/Coupon/`)

**Status: Implemented, integrated with POS discount orchestrator**

- Coupon validation and redemption
- Integration with discount orchestration

---

### Treasury Module (`Modules/Treasury/`)

**Status: Production-ready**

- Payment method configuration (Cash, Card, Voucher, etc.)
- Payment recording with GL integration
- Cash register management
- Payment reversal on receipt void

---

### Inventory Module (`Modules/Inventory/`)

**Status: Production-ready, recently enhanced**

- Stock levels with pessimistic locking
- Stock movements with audit trail
- Inventory counting (drafts, activation, blind counting)
- Warehouse management
- Integration with POS for stock decrement

---

## Vertical Configuration

The `Vertical` enum defines F&B verticals:

| Vertical | Product | Default Modules | Compatible Extras |
|----------|---------|----------------|-------------------|
| Restaurant | izipos | Identity, Tenant, Catalog, Menu, Partner, Sales, Inventory, Treasury, Accounting, Tables | Tables, Reservation |
| CoffeeShop | izipos | Identity, Tenant, Catalog, Menu, Partner, Sales, Inventory, Treasury, Accounting | Tables, Loyalty |

Note: `Tables` module is listed as default for Restaurant but does not exist yet as a module.
