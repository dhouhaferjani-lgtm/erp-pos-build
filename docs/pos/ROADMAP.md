# POS Go-Live Roadmap

> **Last updated:** 2026-03-12
> **Goal:** Ship a production-ready POS for both Retail and F&B verticals.

---

## Architecture Decisions

### Customer Model
- **Single `partners` table** — no separate customer tables.
- Add `customer_category` enum: `individual` (B2C) | `business` (B2B).
- POS has a lightweight customer panel (quick-add with name + phone).
- Sales > Customers is the full back-office view.
- CRM will be a future top-level module reading the same data.
- Loyalty members link to partners via `customer_id` FK.

### Receipt ↔ Customer Link
- Add nullable `partner_id` FK to `pos_receipts` (for queryability).
- Keep denormalized `customer_name` + `customer_identifier` (for fiscal immutability).
- Both fields coexist: FK for queries, snapshot for compliance.

---

## Phases

### Phase 1 — Retail MVP ✅ COMPLETE

> **Goal:** Close the gaps blocking retail go-live.
> **Status:** All items done. Camera scanning and GL reversal deferred to later phases.

#### 1.1 Customer Management in POS ✅ DONE
**Completed** — Migrated and deployed

- [x] Add `customer_category` enum to Partner model (`individual` | `business`)
- [x] Add migration: `partner_id` nullable FK on `pos_receipts`
- [x] Update `ReceiptCreationService` to store `partner_id` alongside denormalized fields
- [x] Fix critical bug: customer info was set via UPDATE after receipt creation (blocked by NF525 immutability trigger). Now set at INSERT time.
- [x] Update `ReceiptController@index` to support `?customer_id=` filter
- [x] Build QuickAddCustomerModal (name, phone, email — 3 fields)
- [x] Wire `PartnerSearchSelect.onAddNew` to quick-add modal in POS
- [x] Extract `selectCustomer()` helper with automatic loyalty lookup
- [x] Add translations (en/fr) for customer management keys

#### 1.2 Z-Report Frontend ✅ DONE
**Completed** — All items implemented

- [x] Create `ZReportListPage` under `/pos/z-reports`
- [x] Z-Report detail view with:
  - Sales summary (gross, net, tax, voids)
  - VAT breakdown by rate
  - Payment method distribution
  - Receipt count and average ticket
  - Hash chain verification status
- [x] Z-Report PDF download (real backend PDF endpoint via Browsershot)
- [x] Wire sidebar link (already in navigation as `zReports`)
- [x] Add translations (en/fr)

#### 1.3 Barcode Scanner Support ✅ DONE
**Completed** — Camera scanning deferred to Tauri POS

- [x] Add barcode/SKU search input in ProductGrid header
- [x] Backend: `GET /pos/products?barcode={code}` endpoint
- [x] Frontend: Listen for scanner input (rapid keystrokes → barcode pattern detection)
- [x] Auto-add to cart when single product matches barcode
- [x] Handle no-match and multi-match cases (toast / selection modal)
- [x] Support USB scanners (keyboard wedge)
- [ ] ~~Camera scanning~~ — **Deferred to Tauri POS** (requires native camera access)

#### 1.4 Return/Exchange Workflow ✅ DONE
**Completed** — All items except GL reversal (deferred to Phase 5.4)

- [x] Add `ReceiptType` enum: `sale` | `return`
- [x] Add `original_receipt_id` nullable FK on `pos_receipts`
- [x] `POST /pos/receipts/{id}/return` — creates return receipt (negative amounts)
- [x] Partial returns: select which lines to return + quantities
- [x] Return reason enum: `defective`, `wrong_item`, `customer_changed_mind`, `other`
- [x] Stock restoration on return (reverse inventory deduction via `StockMovement`)
- [ ] ~~GL reversal entries for returned items~~ — **Deferred to Phase 5.4.** POS receipts don't post GL on sale; returns naturally offset when consolidated via Z-Report.
- [x] Frontend: "Return" button on receipt search → `ReturnItemsModal` (line selection, quantities, reason, notes)
- [x] Return receipt prints with "RETURN" banner, original receipt number, and return reason
- [x] Receipt type filter on receipt search page (All Types / Sales Only / Returns Only)
- [x] Already-returned quantity tracking: `returned_quantity` per line in receipt detail, prevents over-return in UI
- [x] Cash drawer refund recorded on return
- [x] Fiscal hash chain maintained for return receipts
- [x] Backend + frontend tests (10 tests, 28 assertions)
- [x] Translations (en/fr) for frontend and backend PDF

**Follow-up items (all resolved):**
- [x] **Duplicate product line matching** — Added `original_line_id` FK to `pos_receipt_lines`. Return lines now reference their original sale line directly. Falls back to product-attribute matching for legacy data.
- [x] **Return receipt `discount_amount` always zero** — Now sums line-level proportional discounts instead of hardcoding `'0.00'`.
- [x] **Silent stock restore skip** — `restoreStock()` now logs a warning when no `StockLevel` record exists.
- [x] **Return flow test coverage** — 6 integration tests added: happy path, over-return rejection, voided receipt rejection, return-of-return rejection, cumulative quantities, and stock restoration.

#### 1.5 ~~POS PIN Auth Frontend~~ — MOVED TO TAURI POS
> PIN auth is for shared physical terminals (Tauri POS) where multiple
> cashiers switch via PIN. The web POS uses standard Sanctum auth and
> already knows the cashier identity. PIN management (set/clear) for
> staff is already available in Settings > Users.
> This item belongs to the Tauri POS workstream, not the web POS.

---

### Phase 2 — B2B/B2C Customer Model ✅ COMPLETE

> **Goal:** Proper differentiation for business vs individual customers.
> **Depends on:** Phase 1.1 (customer_category field)
> **Status:** All items done. Address management deferred (structured addresses not yet needed for POS flow).

#### 2.1 Backend Customer Enrichment ✅ DONE
**Completed** — Migration, enums, services, and model updates deployed

- [x] Add to `partners` table:
  - ~~`customer_category` enum~~ — DONE (migrated in Phase 1.1)
  - `company_legal_name` (nullable, for B2B)
  - `business_registration_number` (nullable)
  - `payment_terms` enum: `immediate`, `net_15`, `net_30`, `net_60`, `net_90`, `custom`
  - `payment_terms_days` (nullable int, for custom terms)
  - `credit_limit` (nullable decimal)
  - `discount_percentage` (nullable decimal, default trade discount)
- [ ] Add structured address fields:
  - `billing_address_line1`, `billing_address_line2`, `billing_city`, `billing_state`, `billing_postal_code`, `billing_country_code`
  - `shipping_address_line1`, `shipping_address_line2`, `shipping_city`, `shipping_state`, `shipping_postal_code`, `shipping_country_code`
  - OR: `partner_addresses` table with `type` enum (`billing` | `shipping` | `default`)
  - **Deferred** — not yet required for POS or B2B invoice flow
- [x] Add contact role fields to `party_contacts`:
  - `is_invoice_contact` boolean
  - `is_delivery_contact` boolean
- [x] Country-specific tax ID validation:
  - France: SIRET (14 digits with Luhn checksum)
  - Tunisia: matricule fiscale (7 digits + 1 letter + 3 chars)
  - Italy: Codice Fiscale (16 chars) / Partita IVA (11 digits)
  - UK: Company Registration Number (8 digits)
  - `TaxIdValidationService` with `TaxIdValidationResult` DTO
- [x] Monthly invoice consolidation for B2B:
  - `partner.invoice_consolidation` boolean
  - `partner.consolidation_frequency` enum: `weekly` | `monthly`
  - `InvoiceConsolidationService` with `shouldConsolidate()` and `getNextConsolidationDate()`

#### 2.2 Frontend Customer Forms ✅ DONE
**Completed** — B2B form sections, contact sub-form, credit limit warning

- [x] Conditional form layout based on `customer_category`:
  - **Individual:** name, phone, email, loyalty badge
  - **Business:** + company legal name, tax ID, VAT, registration #, payment terms, credit limit, contact persons
  - `B2BFieldsSection` component with tax ID validation integration
- [x] Contact persons sub-form (add/edit/remove contacts under a B2B partner)
  - `ContactPersonsSubForm` with role toggles (primary, invoice, delivery)
- [ ] Address management (billing vs shipping, with copy button) — **Deferred** with backend addresses
- [x] Credit limit warning in document creation (when approaching/exceeding limit)
  - `CreditLimitWarning` component with threshold display
- [ ] Partner detail page tabs:
  - Overview (balance, recent activity)
  - Documents (invoices, quotes, orders)
  - POS Receipts (now queryable with Phase 1.1)
  - Contacts (B2B only)
  - Loyalty (if enrolled)
  - Payment history

---

### Phase 3 — F&B MVP

> **Goal:** Table management, orders, and kitchen display for food & beverage.
> **Parallelism:** 3.1 + 3.2 can run in parallel. 3.3 depends on 3.2. 3.4 is independent.
> **Status:** 3.2 and 3.4 complete (backend + frontend). 3.1 and 3.3 remain.

#### 3.1 Table Management ⬜
**Can run in parallel with 3.3**

- [ ] Create `pos_tables` table:
  - `id`, `tenant_id`, `company_id`, `location_id`
  - `table_number` (string, e.g., "T1", "Bar-3")
  - `section` (string, e.g., "Main Floor", "Terrace", "Bar")
  - `seats` (int)
  - `status` enum: `available`, `occupied`, `reserved`, `cleaning`
  - `current_order_id` (nullable FK)
- [ ] CRUD API for tables
- [ ] Table layout view in POS (grid of table cards with status colors)
- [ ] Tap table → open order for that table
- [ ] Table status auto-updates (available → occupied on order, occupied → available on close)

#### 3.2 Order Management (Pre-Receipt) ✅ DONE
**Completed** — Full backend + frontend with tests

- [x] Create `pos_orders` table:
  - `id`, `tenant_id`, `company_id`, `terminal_id`, `shift_id`
  - `order_number` (sequential per terminal per day)
  - `table_id` (nullable FK)
  - `status` enum: `open`, `sent_to_kitchen`, `ready`, `closed`, `cancelled`
  - `consumption_mode` enum
  - `customer_name`, `customer_identifier`, `partner_id`
  - `notes` (text)
  - `opened_at`, `sent_at`, `closed_at`, `cancelled_at`
- [x] Create `pos_order_lines` table:
  - `order_id` FK, `product_id`, `composite_item_id`
  - `quantity`, `unit_price`, `discount_amount`, `tax_amount`
  - `status` enum: `pending`, `sent`, `preparing`, `ready`, `served`, `cancelled`
  - `modifiers` JSONB
  - `special_instructions` (text)
  - `sent_at`, `prepared_at` (timestamps)
- [x] Order API:
  - `POST /pos/orders` — create order (assign table optional)
  - `GET /pos/orders` — list orders (filter by status, terminal, table)
  - `POST /pos/orders/{id}/lines` — add line
  - `PATCH /pos/orders/{id}/lines/{lineId}` — modify line
  - `DELETE /pos/orders/{id}/lines/{lineId}` — remove line
  - `POST /pos/orders/{id}/send-to-kitchen` — fire order
  - `POST /pos/orders/{id}/close` — convert to receipt
  - `POST /pos/orders/{id}/cancel`
- [x] Order ↔ Receipt conversion: `OrderToReceiptService` closes order and creates receipt via `ReceiptCreationService`
- [ ] Multiple orders per table (seat-based ordering) — **Deferred** to after 3.1 Table Management
- [x] Frontend: `OrderPanel`, `ActiveOrdersBoard` (kanban columns), `OrdersPage`, `OrderLineItem`, `OrderStatusBadge`
- [x] Frontend hooks: `useOrders` (10s polling), `useCreateOrder`, `useAddOrderLine`, `useModifyOrderLine`, `useRemoveOrderLine`, `useSendToKitchen`, `useCloseOrder`, `useCancelOrder`
- [x] Domain events: `OrderSentToKitchen`, `OrderClosed`
- [x] Tests: `OrderManagementTest` (feature), `OrderManagementServiceTest` (unit)

#### 3.3 Kitchen Display System (KDS) ⬜
**Depends on 3.2** (order management) — now unblocked

- [ ] Create `pos_kitchen_stations` table:
  - `id`, `name` (e.g., "Kitchen", "Bar", "Desserts")
  - `product_category_ids` JSONB (which categories route to this station)
- [ ] KDS page (`/pos/kitchen`):
  - Column board layout (like kanban): Pending → Preparing → Ready
  - Each card shows: order #, table #, line items, elapsed time
  - Color coding by age (green < 5min, yellow < 10min, red > 10min)
  - Tap card to advance status
  - Audio/visual alert on new orders
- [ ] Station routing: order lines auto-route to correct station based on product category
- [ ] Bump bar support (keyboard shortcuts for KDS navigation)

#### 3.4 Order Parking / Hold ✅ DONE
**Completed** — Full backend + frontend with tests

- [x] Save current cart as held order (with optional label: "Table 5", "John")
  - `HeldOrder` domain model with `cart_snapshot` JSONB
  - `HeldOrderService` with `holdOrder()`, `recallOrder()`, `discardOrder()`
- [x] Held orders list panel (slide-out or sidebar)
  - `HeldOrdersList` slide-out panel, `HeldOrderCard` with expiry countdown
- [x] Recall held order → restore cart
  - `POST /pos/held-orders/{id}/recall` endpoint + `useRecallOrder` hook
- [x] Auto-expire held orders after configurable time (default 4 hours)
  - `HeldOrderStatus` enum: `held`, `recalled`, `expired`
  - Expiry validation on recall attempts
- [x] Visual indicator of held order count in POS header
  - `HeldOrdersBadge` component with count display
- [x] Tests: `HeldOrderTest` (feature), `HeldOrderServiceTest` (unit)

---

### Phase 4 — Engagement Features ✅ COMPLETE

> **Goal:** Wire existing promotions, coupons, and loyalty backend into the POS checkout flow.
> **Note:** Backend infrastructure (DiscountOrchestratorService, CartPromotionService, CouponApplicationService, Loyalty endpoints) was already built. This phase wired it all into the frontend checkout.

#### 4.1 Coupon Code Input in POS ✅ DONE
**Completed** — CouponCodeInput component with validation

- [x] Add coupon code input field in cart (text input + "Apply" button)
- [x] Validate via `POST /coupons/validate` with immediate feedback
- [x] Display applied coupon as a discount line in cart (green badge)
- [x] Allow removing applied coupon (X button)
- [x] Error states: invalid code, expired, minimum not met
- [x] Pass `coupon_code` through to `ReceiptCreationService` on checkout

#### 4.2 Auto-Apply Promotions ✅ DONE
**Completed** — useDiscountPreview hook + AppliedDiscountsBadge

- [x] Call `POST /pos/cart/preview-discounts` on cart changes (500ms debounced)
- [x] Display auto-applied promotions as colored discount badges (purple for promotions)
- [x] Show "Total savings" summary line in cart
- [x] Handle stacking via backend DiscountOrchestratorService

#### 4.3 Loyalty Integration in POS ✅ DONE
**Completed** — Full integration in main cart flow

- [x] Member lookup by phone in customer panel (already done in Phase 1.1)
- [x] Display loyalty badge in cart (LoyaltyMemberBadge — already done)
- [x] Preview points earned on current cart (EarnPointsPreview — already done)
- [x] Reward selection in main cart flow (LoyaltyRewardSelector moved from AdvancedPayments)
- [x] Points confirmation after payment (earnPoints call — already done)
- [x] Pass `loyalty_discount_amount` + `loyalty_reward_id` through to ReceiptCreationService

#### 4.4 Loyalty Program Management UI ⬜
**Deferred** — Not part of POS checkout wiring, belongs to back-office admin

- [ ] Program CRUD pages (create/edit programs, earning rules, rewards, tiers)
- [ ] Member enrollment page
- [ ] Member directory with search + filters
- [ ] Points adjustment tool (manual add/deduct)
- [ ] Transaction history per member
- [ ] Program analytics dashboard

#### 4.0 Manual Discount Visibility Fix ✅ DONE
**Completed** — Root cause: user `can_discount` defaulted false, terminal `max_discount_percent` defaulted 0

- [x] Set `can_discount => true`, `max_discount_percent => 25.00` on CoffeeShopSeeder test users
- [x] Set `allow_line_discounts`, `allow_transaction_discounts`, `max_discount_percent => 20.00` on web terminal creation

---

### Phase 5 — Production Hardening

> **Goal:** Reliability, compliance, and operational readiness.

#### 5.0 Tauri POS Desktop App — In Progress

**Phase 1 — UI Foundation ✅ COMPLETE:**
- [x] Tauri desktop shell, Vite + React + TypeScript
- [x] IziPOS theme, branded layout, responsive header
- [x] Product grid with search, category tabs, grid/visual mode toggle
- [x] Transaction cart with quantity controls, line totals
- [x] Cash tendered modal, card payment modal, checkout success modal
- [x] Quick actions (discount, hold, recall, reports)
- [x] Hold/recall transactions, quantity numpad
- [x] Settings page, cash drawer ops, reports menu, void/return modals
- [x] PIN entry/setup, terminal setup, operator locking
- [x] Connectivity monitoring, sync status indicator

**Phase 2 — Atomic Design + Feature Completion ✅ COMPLETE:**
- [x] Component restructure: atoms/molecules/organisms hierarchy
- [x] Product card text overlap fix (flex-1 layout instead of mt-auto)
- [x] Cash checkout error display (error prop in CashTenderedModal)
- [x] Settings language: dropdown instead of toggle buttons
- [x] Modifier selection modal (single/multiple, required validation, price adjustments)
- [x] Dual data source: company config → F&B (active menu) vs Retail (products)
- [x] SQLite fallback: load products from local DB when API unreachable
- [x] Category navigation with product counts + popular items row
- [x] Discount UX split: transaction-only in QuickActions, per-item on cart lines
- [x] LineDiscountModal for per-item discounts
- [x] Fix "Composite item does not exist" sale flow bug — `flattenMenuToProducts()` was mapping pivot table IDs instead of `sellable_id`, wrong field names (`sku`→`code`, `sale_price`→`effective_price`, `position`→`display_order`), and defaulting to invalid `'menu_item'` sellable type

**Phase 3 — Not Started:**
- [ ] Receipt printing (ESC/POS thermal printer integration)
- [ ] Full offline checkout (receipt creation with fiscal hash chain)
- [ ] Order management integration (F&B table orders)
- [ ] Barcode scanner (camera via Tauri native)

#### 5.1 Offline Support (Tauri POS) — Partially Done

- [x] Local SQLite cache for products, categories (via productRepository)
- [x] Offline receipt queue (create receipts locally, sync when online)
- [x] Sync status indicator in POS header
- [x] Product fallback: load from SQLite when API fails
- [ ] Full offline checkout with fiscal hash chain (infrastructure ready)
- [ ] Conflict resolution strategy
- [ ] Graceful degradation (what works offline vs requires connection)

#### 5.2 Receipt Printing ⬜

- [ ] ESC/POS thermal printer driver (for Tauri)
- [ ] Network printer discovery and configuration
- [ ] Kitchen ticket format (different from customer receipt)
- [ ] Email receipt option
- [ ] QR code on receipt (link to digital copy)

#### 5.3 Reporting & Analytics ⬜

- [ ] Daily sales summary dashboard
- [ ] Sales by category, product, time period
- [ ] Cashier performance comparison
- [ ] Discount analysis (by source, frequency, amount)
- [ ] Customer analytics (returning customers, average ticket by customer)
- [ ] F&B metrics: average table time, items per order, peak hours

#### 5.4 Compliance Finalization ⬜

- [ ] NF525 audit trail export (XML)
- [ ] Z-Report chain verification tool
- [ ] Factur-X integration for B2B invoices from POS (France)
- [ ] SDI integration for Italy
- [ ] Receipt duplicate/reprint audit log

---

## Parallelism Guide

Use this to plan concurrent agent sessions:

```
Phase 1 (Retail MVP) ✅ COMPLETE:
┌──────────────────┐  ┌──────────────────┐  ┌──────────────────┐
│  Session A     ✅ │  │  Session B     ✅ │  │  Session C     ✅ │
│  1.1 Customer     │  │  1.2 Z-Report     │  │  1.3 Barcode      │
│  Management       │  │  Frontend + PDF   │  │  Scanner (USB)    │
│  DONE             │  │  DONE             │  │  DONE             │
└────────┬─────────┘  └──────────────────┘  └──────────────────┘
         │
         ▼
┌──────────────────┐
│  Session D     ✅ │
│  1.4 Returns      │
│  + follow-ups     │
│  DONE             │
└──────────────────┘

Phase 2 (B2B/B2C) ✅ COMPLETE:
┌──────────────────┐  ┌──────────────────┐
│  Session A     ✅ │  │  Session B     ✅ │
│  2.1 Backend      │  │  2.2 Frontend     │
│  B2B fields +     │  │  B2B forms +      │
│  validation       │  │  credit warning   │
│  DONE             │  │  DONE             │
└──────────────────┘  └──────────────────┘

Phase 3 (F&B) — 3.2 + 3.4 DONE, 3.1 + 3.3 remain:
┌──────────────────┐  ┌──────────────────┐  ┌──────────────────┐
│  Session A        │  │  Session B     ✅ │  │  Session C     ✅ │
│  3.1 Tables       │  │  3.2 Orders       │  │  3.4 Order Hold   │
│  NOT STARTED      │  │  DONE             │  │  DONE             │
└──────────────────┘  └──────────────────┘  └──────────────────┘
         │
         ▼
┌──────────────────┐
│  Session D        │
│  3.3 KDS          │
│  (needs 3.2 ✅)   │
│  NOW UNBLOCKED    │
└──────────────────┘

Phase 4 (Engagement) ✅ COMPLETE (except 4.4):
┌──────────────────┐  ┌──────────────────┐  ┌──────────────────┐  ┌──────────────────┐
│  4.0 Discount  ✅ │  │  4.1 Coupons   ✅ │  │  4.2 Promos    ✅ │  │  4.3 Loyalty   ✅ │
│  Visibility Fix   │  │  in POS          │  │  Auto-Apply      │  │  POS Integration │
│  DONE             │  │  DONE            │  │  DONE            │  │  DONE            │
└──────────────────┘  └──────────────────┘  └──────────────────┘  └──────────────────┘
                                                                   ┌──────────────────┐
                                                                   │  4.4 Loyalty UI  │
                                                                   │  Management      │
                                                                   │  DEFERRED        │
                                                                   └──────────────────┘
```

---

## Key Files Reference

| Area | Backend | Frontend |
|------|---------|----------|
| Partner model | `app/Modules/Partner/Domain/Partner.php` | `src/features/partners/` |
| B2B fields | `app/Modules/Partner/Domain/Enums/PaymentTerms.php` | `src/features/partners/components/B2BFieldsSection.tsx` |
| Tax validation | `app/Modules/Partner/Domain/Services/TaxIdValidationService.php` | `src/features/partners/hooks/useTaxIdValidation.ts` |
| Contact persons | `app/Modules/Contact/Domain/PartyContact.php` | `src/features/partners/components/ContactPersonsSubForm.tsx` |
| Invoice consolidation | `app/Modules/Partner/Application/Services/InvoiceConsolidationService.php` | — |
| POS receipts | `app/Modules/POS/Domain/Receipt.php` | `src/features/pos/api/receiptApi.ts` |
| Receipt creation | `app/Modules/POS/Application/Services/ReceiptCreationService.php` | `src/features/pos/pages/POSPage/` |
| Orders | `app/Modules/POS/Domain/Order.php` | `src/features/pos/api/orderApi.ts` |
| Order management | `app/Modules/POS/Application/Services/OrderManagementService.php` | `src/features/pos/organisms/OrderPanel/` |
| Order → Receipt | `app/Modules/POS/Application/Services/OrderToReceiptService.php` | — |
| Held orders | `app/Modules/POS/Domain/HeldOrder.php` | `src/features/pos/api/heldOrderApi.ts` |
| Held order UI | `app/Modules/POS/Application/Services/HeldOrderService.php` | `src/features/pos/molecules/HeldOrdersList/` |
| Shifts | `app/Modules/POS/Presentation/Controllers/ShiftController.php` | `src/features/pos/pages/ShiftDashboardPage/` |
| Payments | `app/Modules/POS/Presentation/Controllers/ReceiptController.php` | `src/features/pos/organisms/AdvancedPaymentsModal/` |
| Discounts | `app/Modules/POS/Application/Services/DiscountOrchestratorService.php` | `src/features/pos/molecules/DiscountInput/` |
| Promotions | `app/Modules/Promotion/` | `src/features/promotions/` |
| Coupons | `app/Modules/Coupon/` | `src/features/coupons/` |
| Loyalty | `app/Modules/Loyalty/` | `src/features/pos/components/LoyaltyMemberBadge.tsx` |
| Sidebar nav | — | `src/components/organisms/Sidebar/Sidebar.tsx` |
| Products (POS) | `app/Modules/Catalog/` | `src/features/pos/hooks/usePOSProducts.ts` |
| Modifiers | `app/Modules/Catalog/` | `src/features/pos/organisms/ModifierSelectionModal/` |
