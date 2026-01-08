# Core Loyalty Module - Functional Requirements Document

**ERP Core Module**

| Field | Value |
|-------|-------|
| Version | 1.0 |
| Date | January 2026 |
| Status | Draft for Review |
| Module Type | Core (shared across all verticals) |

---

## 1. Executive Summary

### 1.1 Purpose

This document defines the functional requirements for the Core Loyalty Module, a foundational ERP component that provides customer loyalty functionality across all business verticals. The module is designed to be entity-agnostic, allowing different verticals (retail, F&B, automotive, pharmacy) to leverage the same loyalty infrastructure with their specific sellable entities.

### 1.2 Design Principles

1. **Entity-Agnostic:** The module references abstract "loyaltyable" entities, not concrete Products or Menu Items
2. **Vertical-Extensible:** Each vertical registers its sellable entity types with the loyalty system
3. **Multi-Program:** A company can run multiple loyalty programs simultaneously
4. **Configurable:** Earning rules, redemption rules, and rewards are fully configurable per program
5. **Tenant-Scoped:** Programs are scoped to tenant, with optional cross-company sharing

### 1.3 Supported Verticals

| Vertical | Primary Loyaltyable Entity | Secondary Entities |
|----------|---------------------------|-------------------|
| General Retail | Product | ProductCategory |
| Café / Restaurant | MenuItem | MenuCategory |
| Pharmacy | Product | ProductCategory, PrescriptionService |
| Automotive (Parts) | Product | ProductCategory, VehicleBrand |
| Automotive (Service) | Service | ServiceCategory |

---

## 2. Core Concepts

### 2.1 Loyaltyable Entity Abstraction

The loyalty system does not directly reference Products or Menu Items. Instead, it uses an abstract interface that any sellable entity can implement.

```
┌─────────────────────────────────────────────────────────────┐
│                    LoyaltyableContract                       │
├─────────────────────────────────────────────────────────────┤
│ + getLoyaltyableId(): UUID                                  │
│ + getLoyaltyableType(): string  (e.g., "product", "menu_item") │
│ + getLoyaltyableCategory(): ?LoyaltyableCategoryContract    │
│ + getLoyaltyablePrice(): Money                              │
│ + getLoyaltyableName(): string                              │
└─────────────────────────────────────────────────────────────┘
                              ▲
          ┌───────────────────┼───────────────────┐
          │                   │                   │
    ┌─────┴─────┐      ┌─────┴─────┐      ┌─────┴─────┐
    │  Product  │      │ MenuItem  │      │  Service  │
    │ (Retail)  │      │  (Café)   │      │  (Auto)   │
    └───────────┘      └───────────┘      └───────────┘
```

### 2.2 Entity Registration

Vertical modules register their loyaltyable entities during application bootstrap:

```php
// Example: Café vertical registration
LoyaltyRegistry::register('menu_item', MenuItem::class);
LoyaltyRegistry::register('menu_category', MenuCategory::class);

// Example: Retail vertical registration
LoyaltyRegistry::register('product', Product::class);
LoyaltyRegistry::register('product_category', ProductCategory::class);
```

### 2.3 Program Types

| Type | Description | Best For |
|------|-------------|----------|
| **Points-Based** | Earn points per spend or action. Redeem for rewards. | General loyalty, tiered programs |
| **Stamp Card** | Collect stamps per qualifying purchase. Complete card for reward. | Coffee shops, quick service |
| **Visit-Based** | Earn credit per visit regardless of spend | Service businesses |
| **Cashback** | Earn percentage back as store credit | Retail, high-value purchases |
| **Hybrid** | Combination of above | Complex programs |

---

## 3. Functional Requirements

### 3.1 Loyalty Programs

A Loyalty Program is the top-level configuration that defines how customers earn and redeem rewards.

#### 3.1.1 Program Attributes

| Attribute | Type | Required | Description |
|-----------|------|----------|-------------|
| id | UUID | Yes | Unique identifier |
| tenant_id | UUID | Yes | Owning tenant |
| name | String | Yes | Display name (e.g., "Coffee Rewards") |
| program_type | Enum | Yes | POINTS, STAMPS, VISITS, CASHBACK, HYBRID |
| status | Enum | Yes | DRAFT, ACTIVE, PAUSED, ARCHIVED |
| currency | String | Conditional | Points currency name (e.g., "Stars", "Points") |
| start_date | DateTime | No | Program activation date |
| end_date | DateTime | No | Program expiration date (null = no end) |
| terms_and_conditions | Text | No | Legal terms for display |
| companies | Array | No | Specific companies (null = all tenant companies) |

#### 3.1.2 Functional Requirements

| ID | Requirement |
|----|-------------|
| FR-PROG-001 | Users can create multiple loyalty programs per tenant |
| FR-PROG-002 | Programs can be scoped to specific companies or all companies |
| FR-PROG-003 | Programs can be scheduled with start/end dates |
| FR-PROG-004 | Programs can be paused without losing member data |
| FR-PROG-005 | Archived programs retain historical data but accept no new transactions |

---

### 3.2 Loyalty Members

Members are customers enrolled in one or more loyalty programs.

#### 3.2.1 Member Attributes

| Attribute | Type | Required | Description |
|-----------|------|----------|-------------|
| id | UUID | Yes | Unique identifier |
| tenant_id | UUID | Yes | Owning tenant |
| customer_id | UUID | No | Link to Customer entity (if exists) |
| phone | String | Conditional | Primary identifier (required if no customer_id) |
| email | String | No | For digital communications |
| first_name | String | No | Display name |
| last_name | String | No | Display name |
| date_of_birth | Date | No | For birthday rewards |
| enrollment_date | DateTime | Yes | When member joined |
| status | Enum | Yes | ACTIVE, SUSPENDED, BLOCKED |
| external_id | String | No | For integration with external systems |

#### 3.2.2 Member Identification Methods

| Method | Description | Implementation |
|--------|-------------|----------------|
| **Phone Number** | Primary identifier, entered at POS | Lookup by normalized phone |
| **Member Card** | Physical card with barcode/NFC | Lookup by card number |
| **QR Code** | Digital card in mobile app | Encoded member ID |
| **Customer Account** | Linked to customer login | Automatic via authentication |

#### 3.2.3 Functional Requirements

| ID | Requirement |
|----|-------------|
| FR-MEM-001 | Members can be created at POS during checkout |
| FR-MEM-002 | Members can self-register via customer portal (future) |
| FR-MEM-003 | Phone number is normalized and validated for uniqueness per tenant |
| FR-MEM-004 | Members can be linked to existing Customer records |
| FR-MEM-005 | Members can participate in multiple programs simultaneously |
| FR-MEM-006 | Member lookup supports partial phone match for quick search |

---

### 3.3 Program Enrollment

Tracks which members are enrolled in which programs, with program-specific balances.

#### 3.3.1 Enrollment Attributes

| Attribute | Type | Required | Description |
|-----------|------|----------|-------------|
| id | UUID | Yes | Unique identifier |
| program_id | UUID | Yes | Reference to loyalty program |
| member_id | UUID | Yes | Reference to loyalty member |
| enrolled_at | DateTime | Yes | Enrollment timestamp |
| current_balance | Decimal | Yes | Current points/stamps balance |
| lifetime_earned | Decimal | Yes | Total ever earned (for tier calculation) |
| lifetime_redeemed | Decimal | Yes | Total ever redeemed |
| current_tier_id | UUID | No | Current tier (if tiered program) |
| tier_qualified_at | DateTime | No | When current tier was achieved |
| status | Enum | Yes | ACTIVE, SUSPENDED, OPTED_OUT |

#### 3.3.2 Functional Requirements

| ID | Requirement |
|----|-------------|
| FR-ENR-001 | Members are automatically enrolled on first qualifying transaction (configurable) |
| FR-ENR-002 | Welcome bonus applied on enrollment (if configured) |
| FR-ENR-003 | Members can opt-out while retaining transaction history |
| FR-ENR-004 | Balance cannot go negative |
| FR-ENR-005 | Lifetime values are immutable (only increment) |

---

### 3.4 Earning Rules

Earning rules define how members accumulate points, stamps, or credits.

#### 3.4.1 Rule Types

| Rule Type | Description | Example |
|-----------|-------------|---------|
| **Spend-Based** | Points per currency unit spent | "1 point per 1 TND" |
| **Item-Based** | Points per specific item purchased | "50 bonus points for any Espresso" |
| **Category-Based** | Points multiplier for category | "2x points on Hot Drinks" |
| **Quantity-Based** | Stamp per item purchase (for stamp cards) | "1 stamp per coffee purchased" |
| **Visit-Based** | Fixed points per transaction | "10 points per visit" |
| **Threshold-Based** | Bonus at spend thresholds | "100 bonus points when spending 50+ TND" |
| **Time-Based** | Multiplier during specific periods | "Double points on weekends" |

#### 3.4.2 Earning Rule Attributes

| Attribute | Type | Required | Description |
|-----------|------|----------|-------------|
| id | UUID | Yes | Unique identifier |
| program_id | UUID | Yes | Parent program |
| name | String | Yes | Admin-friendly name |
| rule_type | Enum | Yes | SPEND, ITEM, CATEGORY, QUANTITY, VISIT, THRESHOLD, TIME |
| priority | Integer | Yes | Execution order (lower = first) |
| is_active | Boolean | Yes | Enable/disable without deletion |
| conditions | JSON | Yes | Rule conditions (see below) |
| reward_value | Decimal | Yes | Points/stamps to award |
| reward_type | Enum | Yes | FIXED, MULTIPLIER, PERCENTAGE |
| start_date | DateTime | No | Rule activation date |
| end_date | DateTime | No | Rule expiration date |
| max_earn_per_transaction | Decimal | No | Cap per transaction |
| max_earn_per_day | Decimal | No | Daily cap per member |

#### 3.4.3 Conditions Schema

```json
{
  "conditions": {
    "min_spend": 10.00,
    "max_spend": null,
    "item_types": ["menu_item", "product"],
    "item_ids": ["uuid-1", "uuid-2"],
    "category_ids": ["uuid-cat-1"],
    "excluded_item_ids": ["uuid-3"],
    "days_of_week": [1, 2, 3, 4, 5],
    "time_range": {
      "start": "09:00",
      "end": "11:00"
    },
    "tier_ids": ["uuid-gold", "uuid-platinum"],
    "first_purchase_only": false,
    "new_member_days": 30
  }
}
```

#### 3.4.4 Functional Requirements

| ID | Requirement |
|----|-------------|
| FR-EARN-001 | Multiple earning rules can apply to a single transaction |
| FR-EARN-002 | Rules execute in priority order; later rules can see earlier awards |
| FR-EARN-003 | Rules can target specific loyaltyable entity types and IDs |
| FR-EARN-004 | Rules can target categories through the LoyaltyableCategoryContract |
| FR-EARN-005 | Rules can be time-bound (date range, days of week, hours) |
| FR-EARN-006 | Rules support tier-specific multipliers |
| FR-EARN-007 | Earning caps prevent abuse (per transaction, per day) |
| FR-EARN-008 | Rules can exclude specific items (e.g., gift cards, tobacco) |

---

### 3.5 Stamp Cards (Quantity-Based Programs)

Stamp cards are a specialized earning mechanism where members collect stamps toward a specific reward.

#### 3.5.1 Stamp Card Definition Attributes

| Attribute | Type | Required | Description |
|-----------|------|----------|-------------|
| id | UUID | Yes | Unique identifier |
| program_id | UUID | Yes | Parent program |
| name | String | Yes | Card name (e.g., "Coffee Card") |
| stamps_required | Integer | Yes | Stamps needed to complete card |
| qualifying_items | JSON | Yes | Which items earn stamps |
| stamps_per_item | Integer | Yes | Stamps awarded per qualifying item (usually 1) |
| reward_id | UUID | Yes | Reward given on completion |
| max_active_cards | Integer | No | Max cards a member can have in progress |
| expiry_days | Integer | No | Days until incomplete card expires |

#### 3.5.2 Member Stamp Card Attributes

| Attribute | Type | Required | Description |
|-----------|------|----------|-------------|
| id | UUID | Yes | Unique identifier |
| card_definition_id | UUID | Yes | Reference to stamp card definition |
| enrollment_id | UUID | Yes | Reference to member's program enrollment |
| current_stamps | Integer | Yes | Stamps collected |
| started_at | DateTime | Yes | When card was started |
| expires_at | DateTime | No | When card expires |
| completed_at | DateTime | No | When card was completed |
| reward_claimed_at | DateTime | No | When reward was redeemed |

#### 3.5.3 Functional Requirements

| ID | Requirement |
|----|-------------|
| FR-STAMP-001 | New card automatically started when previous is completed |
| FR-STAMP-002 | Multiple stamp card types can exist in one program |
| FR-STAMP-003 | Expired cards can be optionally rolled over (configurable) |
| FR-STAMP-004 | POS displays current stamp progress during checkout |
| FR-STAMP-005 | Stamps cannot be partially awarded (whole numbers only) |

---

### 3.6 Tiers

Tiers provide status levels that unlock benefits and earning multipliers.

#### 3.6.1 Tier Attributes

| Attribute | Type | Required | Description |
|-----------|------|----------|-------------|
| id | UUID | Yes | Unique identifier |
| program_id | UUID | Yes | Parent program |
| name | String | Yes | Tier name (e.g., "Gold") |
| level | Integer | Yes | Tier rank (1 = lowest) |
| qualification_type | Enum | Yes | SPEND, POINTS_EARNED, VISITS, MANUAL |
| qualification_threshold | Decimal | Yes | Amount to reach this tier |
| qualification_period_months | Integer | No | Rolling period for qualification |
| earning_multiplier | Decimal | Yes | Points multiplier (e.g., 1.5 for 50% bonus) |
| benefits | JSON | No | Additional tier benefits |
| icon | String | No | Tier badge/icon identifier |
| color | String | No | Tier color for UI |

#### 3.6.2 Tier Evaluation

| Evaluation Type | Description |
|-----------------|-------------|
| **Rolling Period** | Based on spend/points in last N months |
| **Calendar Year** | Based on current calendar year activity |
| **Lifetime** | Based on all-time activity |
| **Manual** | Assigned by admin (VIP, staff, partners) |

#### 3.6.3 Functional Requirements

| ID | Requirement |
|----|-------------|
| FR-TIER-001 | Tier upgrades are evaluated after each transaction |
| FR-TIER-002 | Tier downgrades occur on schedule (monthly/yearly review) |
| FR-TIER-003 | Members receive notification on tier change |
| FR-TIER-004 | Tier can provide earning multiplier applied to all rules |
| FR-TIER-005 | Tier benefits can include exclusive rewards or discounts |
| FR-TIER-006 | Grace period before downgrade (configurable) |

---

### 3.7 Rewards

Rewards are what members can redeem their points/stamps for.

#### 3.7.1 Reward Types

| Type | Description | Example |
|------|-------------|---------|
| **Free Item** | Specific item at no cost | "Free Regular Coffee" |
| **Discount Amount** | Fixed currency discount | "5 TND off your order" |
| **Discount Percentage** | Percentage off order/item | "20% off any pastry" |
| **Free Item Choice** | Choice from qualifying items | "Free drink of your choice" |
| **Store Credit** | Balance added to customer account | "10 TND store credit" |
| **External** | Third-party reward (gift card, etc.) | "10 TND Amazon gift card" |

#### 3.7.2 Reward Attributes

| Attribute | Type | Required | Description |
|-----------|------|----------|-------------|
| id | UUID | Yes | Unique identifier |
| program_id | UUID | Yes | Parent program |
| name | String | Yes | Display name |
| description | String | No | Detailed description |
| reward_type | Enum | Yes | FREE_ITEM, DISCOUNT_AMOUNT, DISCOUNT_PERCENT, CHOICE, CREDIT, EXTERNAL |
| points_cost | Decimal | Yes | Points required to redeem |
| reward_value | Decimal | Conditional | Value in currency (for discounts/credit) |
| qualifying_items | JSON | Conditional | Eligible items (for FREE_ITEM, CHOICE, DISCOUNT_PERCENT) |
| max_discount | Decimal | No | Cap for percentage discounts |
| min_order_value | Decimal | No | Minimum order to redeem |
| is_active | Boolean | Yes | Enable/disable |
| tier_ids | Array | No | Restrict to specific tiers |
| quantity_available | Integer | No | Limited quantity (null = unlimited) |
| quantity_per_member | Integer | No | Max redemptions per member |
| start_date | DateTime | No | Availability start |
| end_date | DateTime | No | Availability end |

#### 3.7.3 Functional Requirements

| ID | Requirement |
|----|-------------|
| FR-REW-001 | Rewards can be redeemed during POS checkout |
| FR-REW-002 | System validates sufficient balance before redemption |
| FR-REW-003 | System validates reward eligibility (tier, items, dates) |
| FR-REW-004 | Multiple rewards can be redeemed in one transaction |
| FR-REW-005 | Rewards can target specific loyaltyable entities via qualifying_items |
| FR-REW-006 | Limited quantity rewards track remaining availability |
| FR-REW-007 | Rewards cannot be redeemed for excluded items (gift cards, etc.) |

---

### 3.8 Transactions

Loyalty transactions record all point movements.

#### 3.8.1 Transaction Types

| Type | Direction | Description |
|------|-----------|-------------|
| EARN | Credit | Points earned from purchase |
| REDEEM | Debit | Points spent on reward |
| ADJUST | Either | Manual adjustment by admin |
| EXPIRE | Debit | Points expired |
| TRANSFER_IN | Credit | Points received from another member |
| TRANSFER_OUT | Debit | Points sent to another member |
| BONUS | Credit | Promotional bonus (birthday, signup, etc.) |
| REFUND | Debit | Points clawed back due to order refund |

#### 3.8.2 Transaction Attributes

| Attribute | Type | Required | Description |
|-----------|------|----------|-------------|
| id | UUID | Yes | Unique identifier |
| enrollment_id | UUID | Yes | Member's program enrollment |
| transaction_type | Enum | Yes | See types above |
| amount | Decimal | Yes | Points/stamps amount (positive) |
| balance_before | Decimal | Yes | Balance before transaction |
| balance_after | Decimal | Yes | Balance after transaction |
| order_id | UUID | No | Related sales order |
| order_line_id | UUID | No | Specific line item (for item-based earning) |
| reward_id | UUID | No | Redeemed reward |
| earning_rule_id | UUID | No | Rule that triggered earning |
| description | String | No | Human-readable description |
| metadata | JSON | No | Additional context |
| created_by | UUID | No | User who created (for adjustments) |
| created_at | DateTime | Yes | Transaction timestamp |

#### 3.8.3 Functional Requirements

| ID | Requirement |
|----|-------------|
| FR-TXN-001 | All point movements are recorded as immutable transactions |
| FR-TXN-002 | Transactions reference source order when applicable |
| FR-TXN-003 | Transactions can be queried by member, program, date range |
| FR-TXN-004 | Manual adjustments require reason and are audit-logged |
| FR-TXN-005 | Order refunds trigger proportional point clawback |
| FR-TXN-006 | Transaction history viewable by member (customer portal) |

---

### 3.9 Point Expiration

Points can be configured to expire after a period of inactivity or from earn date.

#### 3.9.1 Expiration Policies

| Policy | Description |
|--------|-------------|
| **No Expiration** | Points never expire |
| **From Earn Date** | Each point batch expires N months after earning |
| **Activity-Based** | All points expire after N months of inactivity |
| **Calendar Year** | Points expire at end of calendar year + grace period |

#### 3.9.2 Expiration Configuration

| Attribute | Type | Description |
|-----------|------|-------------|
| expiration_policy | Enum | Policy type |
| expiration_months | Integer | Months until expiration |
| expiration_grace_days | Integer | Grace period before final expiration |
| notify_before_days | Array | Days before expiry to notify (e.g., [30, 7, 1]) |

#### 3.9.3 Functional Requirements

| ID | Requirement |
|----|-------------|
| FR-EXP-001 | Expiration policy configurable per program |
| FR-EXP-002 | FIFO (First In, First Out) for redemptions when points have dates |
| FR-EXP-003 | Members notified before points expire |
| FR-EXP-004 | Expired points recorded as EXPIRE transactions |
| FR-EXP-005 | Admin can extend expiration for specific members |

---

### 3.10 Promotions & Bonuses

Special earning events outside regular rules.

#### 3.10.1 Promotion Types

| Type | Description | Example |
|------|-------------|---------|
| **Welcome Bonus** | On enrollment | "50 points on signup" |
| **Birthday Bonus** | On member's birthday | "100 bonus points" |
| **Anniversary Bonus** | On enrollment anniversary | "Double points all day" |
| **Referral Bonus** | When referred member joins | "200 points per referral" |
| **Campaign Bonus** | Limited-time promotion | "Triple points this weekend" |
| **Milestone Bonus** | At lifetime spend milestones | "500 points at 1000 TND lifetime" |

#### 3.10.2 Functional Requirements

| ID | Requirement |
|----|-------------|
| FR-PROMO-001 | Welcome bonus applied automatically on enrollment |
| FR-PROMO-002 | Birthday bonus applied on birthday (requires DOB) |
| FR-PROMO-003 | Campaigns can be scheduled with start/end dates |
| FR-PROMO-004 | Campaigns can stack with or replace regular earning rules |
| FR-PROMO-005 | Referral tracking links referrer to referred member |

---

## 4. Integration Points

### 4.1 POS Integration

| Integration Point | Direction | Description |
|-------------------|-----------|-------------|
| Member Lookup | POS → Loyalty | Search member by phone, card, scan |
| Balance Display | Loyalty → POS | Show current balance, tier, available rewards |
| Earning Preview | Loyalty → POS | Show points to be earned before checkout |
| Apply Earning | POS → Loyalty | Award points on order completion |
| Reward Redemption | POS → Loyalty | Apply reward, deduct points |
| Refund Handling | POS → Loyalty | Clawback points on order refund |

### 4.2 Order Module Integration

The Loyalty module listens to Order events:

| Event | Action |
|-------|--------|
| `OrderCompleted` | Evaluate earning rules, award points |
| `OrderRefunded` | Calculate and clawback earned points |
| `OrderVoided` | Full clawback of earned points |
| `OrderLineRefunded` | Partial clawback for specific items |

### 4.3 Customer Module Integration

| Integration | Description |
|-------------|-------------|
| Customer → Member Link | Loyalty member can be linked to Customer entity |
| Profile Sync | Name, phone, email synced from Customer if linked |
| Purchase History | Loyalty can access customer's order history for rules |

### 4.4 Notification Integration

| Trigger | Notification |
|---------|--------------|
| Points Earned | "You earned 50 points on your purchase!" |
| Points Expiring | "100 points expiring in 7 days" |
| Reward Unlocked | "You can now redeem a free coffee!" |
| Tier Achieved | "Congratulations! You've reached Gold status" |
| Birthday | "Happy Birthday! Here's a special bonus" |

---

## 5. Data Model Overview

### 5.1 Entity Relationship Summary

```
┌─────────────────┐       ┌─────────────────┐
│ LoyaltyProgram  │───────│   EarningRule   │
└────────┬────────┘       └─────────────────┘
         │
         │ 1:N
         ▼
┌─────────────────┐       ┌─────────────────┐
│   Enrollment    │───────│ LoyaltyMember   │
└────────┬────────┘       └─────────────────┘
         │
         │ 1:N
         ▼
┌─────────────────┐
│  Transaction    │
└─────────────────┘

┌─────────────────┐       ┌─────────────────┐
│ LoyaltyProgram  │───────│     Reward      │
└─────────────────┘       └─────────────────┘

┌─────────────────┐       ┌─────────────────┐
│ LoyaltyProgram  │───────│      Tier       │
└─────────────────┘       └─────────────────┘

┌─────────────────┐       ┌─────────────────┐
│ LoyaltyProgram  │───────│ StampCardDef    │
└─────────────────┘       └────────┬────────┘
                                   │ 1:N
                                   ▼
                          ┌─────────────────┐
                          │ MemberStampCard │
                          └─────────────────┘
```

### 5.2 Core Entities

| Entity | Description |
|--------|-------------|
| **LoyaltyProgram** | Program configuration and settings |
| **LoyaltyMember** | Customer enrolled in loyalty (tenant-level) |
| **Enrollment** | Member's participation in a specific program |
| **EarningRule** | How points are earned |
| **Reward** | What points can be redeemed for |
| **Tier** | Status levels with benefits |
| **Transaction** | Immutable record of all point movements |
| **StampCardDefinition** | Stamp card configuration |
| **MemberStampCard** | Member's stamp card progress |

---

## 6. Vertical Registration

### 6.1 Registration Interface

Each vertical module must register its loyaltyable entities:

```php
interface LoyaltyableRegistrarInterface
{
    /**
     * Register entity types that can participate in loyalty
     */
    public function register(LoyaltyRegistry $registry): void;
}
```

### 6.2 Example Registrations

**Retail Vertical:**
```php
class RetailLoyaltyRegistrar implements LoyaltyableRegistrarInterface
{
    public function register(LoyaltyRegistry $registry): void
    {
        $registry->registerEntity('product', Product::class);
        $registry->registerCategory('product_category', ProductCategory::class);
    }
}
```

**Café Vertical:**
```php
class CafeLoyaltyRegistrar implements LoyaltyableRegistrarInterface
{
    public function register(LoyaltyRegistry $registry): void
    {
        $registry->registerEntity('menu_item', MenuItem::class);
        $registry->registerCategory('menu_category', MenuCategory::class);
    }
}
```

### 6.3 Functional Requirements

| ID | Requirement |
|----|-------------|
| FR-REG-001 | Verticals register entities at application bootstrap |
| FR-REG-002 | Multiple entity types can be registered per vertical |
| FR-REG-003 | Earning rules use registered type identifiers |
| FR-REG-004 | System validates entity types against registry |

---

## 7. API Endpoints (High-Level)

### 7.1 Program Management

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/loyalty/programs` | List programs |
| POST | `/api/loyalty/programs` | Create program |
| GET | `/api/loyalty/programs/{id}` | Get program details |
| PUT | `/api/loyalty/programs/{id}` | Update program |
| POST | `/api/loyalty/programs/{id}/activate` | Activate program |
| POST | `/api/loyalty/programs/{id}/pause` | Pause program |

### 7.2 Member Management

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/loyalty/members` | List/search members |
| POST | `/api/loyalty/members` | Create member |
| GET | `/api/loyalty/members/{id}` | Get member with enrollments |
| GET | `/api/loyalty/members/lookup` | Lookup by phone/card |
| POST | `/api/loyalty/members/{id}/adjust` | Manual adjustment |

### 7.3 POS Operations

| Method | Endpoint | Description |
|--------|----------|-------------|
| POST | `/api/loyalty/pos/lookup` | Quick member lookup for POS |
| POST | `/api/loyalty/pos/preview` | Preview points for cart |
| POST | `/api/loyalty/pos/earn` | Award points for order |
| POST | `/api/loyalty/pos/redeem` | Redeem reward |
| GET | `/api/loyalty/pos/rewards` | Available rewards for member |

---

## 8. Acceptance Criteria

### 8.1 Must Have

- [ ] Entity-agnostic design with vertical registration
- [ ] Points-based program with configurable earning rules
- [ ] Stamp card program support
- [ ] Member management with phone-based identification
- [ ] Basic reward redemption (free item, discount)
- [ ] POS integration for lookup, earn, redeem
- [ ] Transaction history for audit trail
- [ ] Order refund triggers point clawback

### 8.2 Should Have

- [ ] Tier system with automatic upgrades
- [ ] Multiple earning rules per program
- [ ] Time-based and category-based rules
- [ ] Point expiration with notifications
- [ ] Welcome and birthday bonuses
- [ ] Member balance displayed on receipts

### 8.3 Nice to Have

- [ ] Referral program
- [ ] Campaigns with scheduled start/end
- [ ] Cross-company loyalty (shared programs)
- [ ] Customer portal for balance/history
- [ ] Point transfer between members
- [ ] External reward fulfillment

---

## 9. Open Questions

| # | Question | Status |
|---|----------|--------|
| 1 | Should loyalty member be separate from Customer entity or merged? | Recommend separate with optional link |
| 2 | Multi-currency support for points (TND vs EUR programs)? | Needs decision |
| 3 | Real-time tier evaluation vs batch processing? | Recommend real-time for upgrades, batch for downgrades |
| 4 | Member data ownership when customer deletes account (GDPR)? | Needs legal review |
| 5 | API rate limiting for POS lookup endpoints? | Needs performance analysis |

---

*— End of Document —*
