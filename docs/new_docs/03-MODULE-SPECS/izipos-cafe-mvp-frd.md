# IziPOS Café - Functional Requirements Document

**MVP Version — Coffee Shop & Quick Service**

| Field | Value |
|-------|-------|
| Version | 1.0 – MVP |
| Date | January 2026 |
| Status | Draft for Review |
| Target Vertical | Coffee Shops, Cafés, Quick Service |

---

## 1. Executive Summary

### 1.1 Purpose

This document defines the functional requirements for IziPOS Café, a specialized point-of-sale configuration targeting coffee shops, cafés, and quick-service establishments. The MVP focuses on essential features for counter-service operations without table management or reservations.

### 1.2 Scope

This MVP includes:

- Menu Item management with recipes linking to ingredients
- Ingredient (raw material) inventory management
- Modifiers and customizations for menu items
- Consumption mode tracking (sur place / à emporter)
- Multiple payment methods including restaurant vouchers
- Basic loyalty program (digital stamps/points)
- Optional inventory tracking with automatic deduction

### 1.3 Out of Scope (Future Phases)

- Table plan and floor management
- Reservations and booking system
- Kitchen Display System (KDS) integration
- Advanced staff scheduling
- Delivery platform integrations

---

## 2. Terminology & Data Model

### 2.1 Core Terminology

The café vertical uses specialized terminology that differs from standard retail POS. This terminology should be configurable at the tenant level to ensure intuitive user experience.

| Standard Term | Café Term | Description |
|---------------|-----------|-------------|
| Product | **Ingredient** | Raw materials: coffee beans, milk, sugar, syrups, cups, lids |
| Sellable Item | **Menu Item** | What customers order: Cappuccino, Croissant, Americano |
| BOM / Formula | **Recipe** | Defines ingredients and quantities for a menu item |
| Category | **Menu Category** | Hot Drinks, Cold Drinks, Pastries, Sandwiches |
| Variant | **Size** | Small, Medium, Large – affects price and recipe quantities |

### 2.2 UI Label Configuration

The system should support tenant-level label overrides to change displayed terminology across the UI without affecting the underlying data model. This enables the same codebase to feel native to different business types.

**Implementation approach:** Store label mappings in tenant settings (JSON object mapping internal keys to display strings). Frontend components reference labels via a translation/label service that checks tenant overrides before falling back to defaults.

---

## 3. Functional Requirements

### 3.1 Ingredients (Raw Materials)

Ingredients represent the raw materials used to prepare menu items. Some ingredients may also be sold directly (e.g., bottled water, packaged snacks).

#### 3.1.1 Ingredient Attributes

| Attribute | Required | Description |
|-----------|----------|-------------|
| name | Yes | Display name (e.g., "Espresso Coffee Beans") |
| sku | No | Stock keeping unit for inventory tracking |
| unit_of_measure | Yes | Base unit: grams, ml, pieces, etc. |
| cost_price | No | Purchase cost per unit (for COGS calculation) |
| is_sellable | Yes | Whether this ingredient can be sold directly |
| sell_price | Conditional | Required if is_sellable = true |
| category | No | Grouping: Dairy, Coffee, Syrups, Packaging |
| reorder_point | No | Low stock alert threshold |
| is_perishable | No | Flag for items requiring expiry tracking |

#### 3.1.2 Functional Requirements

| ID | Requirement |
|----|-------------|
| FR-ING-001 | Users can create, edit, and deactivate ingredients |
| FR-ING-002 | Sellable ingredients appear in POS alongside menu items |
| FR-ING-003 | System supports unit conversions (e.g., kg → grams for recipes) |
| FR-ING-004 | Low stock alerts trigger when quantity falls below reorder_point |
| FR-ING-005 | Bulk import from CSV/Excel supported |

---

### 3.2 Menu Items

Menu items are what customers purchase. Each menu item can have a recipe that defines which ingredients are consumed, and sizes that affect both price and recipe quantities.

#### 3.2.1 Menu Item Attributes

| Attribute | Required | Description |
|-----------|----------|-------------|
| name | Yes | Display name (e.g., "Cappuccino") |
| category | Yes | Menu category for POS display grouping |
| base_price | Yes | Default price (smallest size if sizes exist) |
| tax_category | Yes | Links to tax rates (may vary by consumption mode) |
| has_recipe | Yes | Whether this item consumes ingredients |
| available_sizes | No | Array of sizes with price adjustments |
| modifier_groups | No | Available customization options |
| image | No | Product image for POS display |
| is_active | Yes | Controls visibility in POS |

#### 3.2.2 Sizes (Variants)

Each size defines a price adjustment and a recipe multiplier. For example, a Large Latte might be 1.5x the base recipe and +1.50 TND on price.

| Attribute | Type | Example |
|-----------|------|---------|
| name | String | "Small", "Medium", "Large" |
| price_adjustment | Decimal | +0.00, +1.00, +1.50 |
| recipe_multiplier | Decimal | 1.0, 1.25, 1.5 |
| sort_order | Integer | Display order in POS |

#### 3.2.3 Functional Requirements

| ID | Requirement |
|----|-------------|
| FR-MENU-001 | Users can create menu items with or without recipes |
| FR-MENU-002 | Menu items can have multiple sizes with independent pricing |
| FR-MENU-003 | POS displays menu items grouped by category |
| FR-MENU-004 | Menu items can be quickly toggled active/inactive (86'd) |
| FR-MENU-005 | System calculates theoretical cost based on recipe ingredients |

---

### 3.3 Recipes

Recipes define which ingredients are consumed when a menu item is sold. They enable automatic inventory deduction and cost of goods sold (COGS) calculation.

#### 3.3.1 Recipe Line Item Attributes

| Attribute | Type | Description |
|-----------|------|-------------|
| ingredient_id | UUID | Reference to ingredient |
| quantity | Decimal | Amount needed for base size |
| unit | String | Unit of measure (grams, ml, pieces) |
| is_optional | Boolean | If true, tied to a modifier selection |

#### 3.3.2 Example Recipe: Cappuccino

| Ingredient | Small (1x) | Medium (1.25x) | Large (1.5x) |
|------------|------------|----------------|--------------|
| Espresso Coffee Beans | 14g | 17.5g | 21g |
| Whole Milk | 150ml | 187.5ml | 225ml |
| Paper Cup (size-appropriate) | 1 pc | 1 pc | 1 pc |
| Lid | 1 pc | 1 pc | 1 pc |

#### 3.3.3 Functional Requirements

| ID | Requirement |
|----|-------------|
| FR-RECIPE-001 | Users can define recipes with multiple ingredients |
| FR-RECIPE-002 | Recipe quantities scale automatically based on size multiplier |
| FR-RECIPE-003 | System calculates theoretical COGS per menu item |
| FR-RECIPE-004 | Modifiers can add or substitute recipe ingredients |

---

### 3.4 Modifiers & Customizations

Modifiers allow customers to customize their orders. They are organized into Modifier Groups, which define the available options and selection rules.

#### 3.4.1 Modifier Group Attributes

| Attribute | Type | Description |
|-----------|------|-------------|
| name | String | "Milk Type", "Extras", "Sugar Level" |
| is_required | Boolean | Must select at least one option |
| min_selections | Integer | Minimum options to select (0 if optional) |
| max_selections | Integer | Maximum options (1 = single choice, >1 = multi) |
| selection_type | Enum | SINGLE (radio) or MULTIPLE (checkbox) |

#### 3.4.2 Modifier Option Attributes

| Attribute | Type | Description |
|-----------|------|-------------|
| name | String | "Oat Milk", "Extra Shot", "No Sugar" |
| price_adjustment | Decimal | Additional charge (+0.50) or discount (-0.20) |
| ingredient_effect | Object | Add, remove, or substitute ingredients |
| is_default | Boolean | Pre-selected option |

#### 3.4.3 Pre/Post Modifier Prefixes

The system should support prefix/suffix modifiers for common customization patterns:

- **No:** "No Sugar", "No Ice"
- **Extra:** "Extra Shot", "Extra Syrup"
- **Light:** "Light Ice", "Light Foam"
- **On the side:** "Cream on the side"

#### 3.4.4 Example Modifier Groups

| Group | Type | Options | Price Impact |
|-------|------|---------|--------------|
| Milk Type | Single, Required | Whole*, Skim, Oat, Almond, Soy | Alt milks: +0.50 |
| Sugar Level | Single, Optional | No Sugar, Half, Normal*, Extra | None |
| Extras | Multiple, Optional | Extra Shot, Vanilla, Caramel, Hazelnut | +0.50 to +0.80 each |
| Temperature | Single, Optional | Hot*, Iced, Extra Hot | None |

*\* indicates default option*

#### 3.4.5 Functional Requirements

| ID | Requirement |
|----|-------------|
| FR-MOD-001 | Users can create modifier groups with configurable selection rules |
| FR-MOD-002 | Modifiers can add, remove, or substitute recipe ingredients |
| FR-MOD-003 | POS enforces required modifiers before completing order |
| FR-MOD-004 | Modifier groups can be shared across multiple menu items |
| FR-MOD-005 | Price adjustments apply correctly with multiple modifiers |

---

### 3.5 Consumption Mode

Consumption mode tracks whether an order is for dine-in (sur place) or takeaway (à emporter). This affects VAT rates in some jurisdictions and helps with operational analytics.

#### 3.5.1 Configuration

| Setting | Description |
|---------|-------------|
| default_mode | Tenant-configurable: SUR_PLACE or A_EMPORTER |
| affects_vat | Country-specific: true for France, false for Tunisia |
| prompt_cashier | Whether to ask for mode on each order or use default |

#### 3.5.2 VAT Implications (France Example)

| Product Type | Sur Place | À Emporter |
|--------------|-----------|------------|
| Food (ready to eat) | 10% | 10% |
| Beverages (non-alcoholic) | 10% | 5.5% |
| Alcoholic beverages | 20% | 20% |

#### 3.5.3 Functional Requirements

| ID | Requirement |
|----|-------------|
| FR-MODE-001 | Each order records consumption mode |
| FR-MODE-002 | System applies correct VAT based on mode when country requires |
| FR-MODE-003 | POS provides quick toggle for consumption mode |
| FR-MODE-004 | Reports can filter/group by consumption mode |

---

### 3.6 Payment Methods

#### 3.6.1 Supported Payment Types (MVP)

| Method | Notes |
|--------|-------|
| **Cash** | Amount tendered, change calculation, cash drawer integration |
| **Card** | Credit/debit via payment terminal integration |
| **Restaurant Voucher** | Ticket Restaurant, Swile, etc. – daily limit tracking, eligible items only |

#### 3.6.2 Restaurant Vouchers (Titres-Restaurant)

Restaurant vouchers are employee meal benefits common in France and other countries. They have specific rules that must be enforced:

- **Daily limit:** Maximum usage per day (e.g., €25 in France)
- **Working days only:** Typically not usable on Sundays/holidays (configurable)
- **Eligible items:** Only food products, not merchandise
- **No change:** Paper vouchers cannot give change; excess becomes tip or donation

#### 3.6.3 Split Payments (Phase 2)

Support for splitting a single order across multiple payment methods (e.g., €10 voucher + €5 cash).

---

### 3.7 Inventory Tracking (Optional Feature)

Inventory tracking is an optional feature that can be enabled per tenant. When enabled, selling menu items automatically deducts ingredients based on their recipes.

#### 3.7.1 Feature Toggle

Tenant setting: **inventory_tracking_enabled** (boolean, default: false)

When disabled, the system still tracks menu item sales but does not deduct ingredients or generate stock alerts.

#### 3.7.2 Automatic Deduction Flow

1. Sale completed → System looks up menu item recipe
2. Recipe quantities multiplied by size multiplier
3. Modifier effects applied (add/remove/substitute)
4. Ingredient stock reduced by calculated amounts
5. Low stock alerts triggered if below reorder point

#### 3.7.3 Theoretical vs Actual Inventory

The system maintains both theoretical (calculated from sales) and actual (from physical counts) inventory. Variance reports help identify waste, theft, or recipe inaccuracies.

---

### 3.8 Loyalty Program

A simple loyalty program to encourage repeat visits and build customer relationships.

#### 3.8.1 Program Types Supported

| Type | Description |
|------|-------------|
| **Digital Stamps** | Buy X, get 1 free. Example: 10 coffees = 1 free coffee |
| **Points-Based** | Earn points per spend (1 point = 1 TND). Redeem for rewards |
| **Visit-Based** | Earn points per visit regardless of spend amount |

#### 3.8.2 Customer Identification

- **Phone number:** Primary identifier, entered at POS
- **QR code:** Customer shows QR from mobile app (future)
- **Physical card:** Optional NFC or barcode card

#### 3.8.3 Reward Configuration

- **Threshold rewards:** "At 50 points, get free pastry"
- **Tiered discounts:** "100 points = 10% off, 200 points = 20% off"
- **Birthday rewards:** Automatic bonus on customer's birthday
- **Welcome bonus:** Points/stamp on signup

#### 3.8.4 POS Integration

- Customer lookup shows current balance and available rewards
- Points/stamps awarded automatically on purchase completion
- Rewards can be redeemed during checkout
- Receipt shows points earned and current balance

---

## 4. POS Interface Requirements

### 4.1 Main Order Screen

- **Category tabs/grid:** Quick navigation between menu categories
- **Item buttons:** Large, touch-friendly buttons with images and prices
- **Order summary:** Running total with line items, modifiers, quantities
- **Quick actions:** Consumption mode toggle, customer lookup, hold order

### 4.2 Item Customization Flow

1. Tap item → Size selection (if applicable)
2. → Required modifiers presented first
3. → Optional modifiers available
4. → Add to order with "Add" or quick-tap for default

### 4.3 Payment Screen

- Order total with tax breakdown
- Payment method selection
- Cash: numeric keypad for tendered amount, change calculation
- Card: integration with payment terminal
- Voucher: daily limit check, eligible item validation
- Loyalty points display and redemption option

---

## 5. Data Model Overview

High-level entity relationships for the café module:

### Core Entities

- **Ingredient** – Raw materials with stock tracking
- **MenuItem** – Sellable items with pricing and recipes
- **MenuItemSize** – Size variants with price/recipe multipliers
- **Recipe** – Links MenuItem to Ingredients with quantities
- **ModifierGroup** – Customization category with rules
- **ModifierOption** – Individual modifier with price/ingredient effects
- **MenuCategory** – POS display grouping

### Transaction Entities

- **Order** – Transaction with consumption mode, customer, totals
- **OrderLine** – Individual item with size and modifiers
- **Payment** – Payment record by method

### Loyalty Entities

- **LoyaltyProgram** – Program configuration
- **LoyaltyMember** – Customer enrollment with balance
- **LoyaltyTransaction** – Points earned/redeemed
- **Reward** – Available rewards and thresholds

---

## 6. Acceptance Criteria Summary

### 6.1 Must Have (MVP)

- [ ] Create and manage ingredients with units of measure
- [ ] Create menu items with recipes linking to ingredients
- [ ] Support multiple sizes with price and recipe scaling
- [ ] Configure modifier groups and options with price adjustments
- [ ] Track consumption mode per order
- [ ] Accept cash and card payments
- [ ] Basic loyalty program (stamps or points)
- [ ] Tenant-configurable UI labels

### 6.2 Should Have

- [ ] Automatic inventory deduction (optional feature)
- [ ] Restaurant voucher support
- [ ] Low stock alerts
- [ ] COGS calculation per menu item

### 6.3 Nice to Have

- [ ] Split payments
- [ ] Tip handling
- [ ] Variance reporting (theoretical vs actual inventory)
- [ ] Customer-facing display

---

## 7. Open Questions & Decisions Needed

| # | Question | Status |
|---|----------|--------|
| 1 | Should UI labels be tenant-level or company-level setting? | Needs decision |
| 2 | Do we need receipt printer integration for MVP? | Likely yes |
| 3 | How to handle packaging items (cups, lids) – separate from ingredients? | Needs discussion |
| 4 | Which restaurant voucher providers to support first? | TBD by market |
| 5 | Loyalty program: single program per tenant or multiple? | Needs decision |

---

*— End of Document —*
