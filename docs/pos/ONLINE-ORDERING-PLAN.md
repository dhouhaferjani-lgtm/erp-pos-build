# Online Ordering & Unified Order Infrastructure

> **Status**: Planning
> **Depends on**: Phase 3 F&B completion (Tables + KDS wiring), Hybrid Sync Infrastructure
> **Impacts**: POS module, new Storefront module, new Marketplace module, Catalog sync, Notification system
> **Related**: [STOREFRONT-WEBSITE.md](STOREFRONT-WEBSITE.md) — Detailed storefront architecture

---

## Decisions Log

| Topic | Decision | Date |
|-------|----------|------|
| Storefront hosting | Separate infrastructure per country domain (e.g., Cloudflare Pages) — NOT within ERP | 2026-03-15 |
| Storefront architecture | Astro (SSG/SSR for SEO) + React islands for interactive ordering | 2026-03-15 |
| Storefront domains | Standalone branded domains per country: `easyorders.tn`, `commanderapid.fr`, etc. | 2026-03-15 |
| Tenant routing | Subdomains: `restaurant-name.easyorders.tn` | 2026-03-15 |
| Separate verticals | IziPOS (F&B + retail) and Otospex (automotive services + retail) are separate apps | 2026-03-15 |
| Custom domains | Deferred to V2 | 2026-03-15 |
| WooCommerce plugin | One-way product sync (ERP→Woo), payments in Woo, only paid orders pushed to ERP | 2026-03-15 |
| Payment providers | Tunisia: pickup-first. International: Stripe + PayPal + local providers per country | 2026-03-15 |
| SMS providers | Local provider for Tunisia, Twilio for international | 2026-03-15 |
| Revenue model | Storefront is a gated add-on (free/paid toggle TBD) | 2026-03-15 |
| Fleet/delivery mgmt | Separate application, integrated with ERP later | 2026-03-15 |
| Multi-location menu | Clone-based system; central franchise management UI deferred | 2026-03-15 |
| Marketplace accounts | Support both user-provided credentials and future direct partnerships | 2026-03-15 |

---

## Table of Contents

1. [Vision & Goals](#1-vision--goals)
2. [Architecture Overview](#2-architecture-overview)
3. [Hybrid Sync Infrastructure](#3-hybrid-sync-infrastructure)
4. [Unified Order Pipeline](#4-unified-order-pipeline)
5. [Feature: Own Website Ordering](#5-feature-own-website-ordering)
6. [Feature: QR Code Menu & Ordering](#6-feature-qr-code-menu--ordering)
7. [Feature: Marketplace Aggregation](#7-feature-marketplace-aggregation)
8. [Feature: Mobile App Ordering](#8-feature-mobile-app-ordering)
9. [Supporting Infrastructure](#9-supporting-infrastructure)
10. [Kitchen & Fulfillment Routing](#10-kitchen--fulfillment-routing)
11. [Capacity & Throttling](#11-capacity--throttling)
12. [Payments for Remote Orders](#12-payments-for-remote-orders)
13. [Notifications & Customer Communication](#13-notifications--customer-communication)
14. [Analytics & Reconciliation](#14-analytics--reconciliation)
15. [Compliance Implications](#15-compliance-implications)
16. [Additional Features to Consider](#16-additional-features-to-consider)
17. [Implementation Phases](#17-implementation-phases)
18. [Data Model Changes](#18-data-model-changes)
19. [API Design](#19-api-design)
20. [Risk & Open Questions](#20-risk--open-questions)

---

## 1. Vision & Goals

**One kitchen, many doors.** Every order — whether placed in-store, on a website, via QR code at a table, through a mobile app, or from Uber Eats — flows into a single unified pipeline. The POS terminal (desktop or web) is the operator's single pane of glass.

### Goals

1. **Unified order queue**: All order sources appear in one active orders board and one KDS
2. **Source-tagged orders**: Every order carries its origin (POS, website, QR, Uber Eats, etc.) for routing, analytics, and reconciliation
3. **Provider-agnostic aggregation**: Marketplace integrations share a common adapter pattern — adding a new platform is configuration, not code
4. **Operator control**: The POS operator accepts/rejects/throttles incoming orders. External orders don't silently create receipts.
5. **Customer autonomy**: End customers get real-time order status (accepted, preparing, ready, out for delivery)
6. **Offline resilience**: If the terminal is offline, external orders queue server-side and sync down when connectivity returns
7. **Compliance preservation**: All fiscal rules (hash chains, NF525, VAT per consumption mode) apply regardless of order source

---

## 2. Architecture Overview

```
                    ┌──────────────────────────────────────────────────┐
                    │                  ORDER SOURCES                    │
                    ├──────────┬──────────┬──────────┬─────────────────┤
                    │  POS     │  Website │  QR Code │  Marketplaces   │
                    │ Terminal │ Storefront│  Menu   │ Uber/Deliveroo/ │
                    │ (Tauri)  │ (React)  │ (React) │ Glovo/Custom    │
                    └────┬─────┴────┬─────┴────┬────┴───────┬─────────┘
                         │          │          │            │
                         │     ┌────▼──────────▼────┐  ┌───▼──────────────┐
                         │     │  Storefront API    │  │ Marketplace      │
                         │     │  (public, rate-    │  │ Adapter Service  │
                         │     │   limited, guest   │  │ (webhook intake) │
                         │     │   + auth checkout) │  │                  │
                         │     └────────┬───────────┘  └───────┬──────────┘
                         │              │                      │
                    ┌────▼──────────────▼──────────────────────▼──────────┐
                    │              UNIFIED ORDER PIPELINE                  │
                    │  ┌─────────────────────────────────────────────┐    │
                    │  │  OrderIntakeService                         │    │
                    │  │  - Validates & normalizes all order sources │    │
                    │  │  - Applies business rules (capacity, hours) │    │
                    │  │  - Tags with OrderSource + OrderChannel     │    │
                    │  │  - Dispatches OrderReceived event           │    │
                    │  └──────────────────┬──────────────────────────┘    │
                    │                     │                               │
                    │  ┌──────────────────▼──────────────────────────┐    │
                    │  │  Order State Machine                        │    │
                    │  │  Received → Accepted → Preparing →          │    │
                    │  │  Ready → Dispatched/PickedUp → Completed    │    │
                    │  └──────────────────┬──────────────────────────┘    │
                    │                     │                               │
                    │  ┌──────────────────▼──────────────────────────┐    │
                    │  │  Event Bus (Laravel Broadcasting)           │    │
                    │  │  - Data room: pos.orders.{companyId}        │    │
                    │  │  - Data room: pos.kitchen.{companyId}       │    │
                    │  │  - Data room: storefront.{orderId}          │    │
                    │  │  - Data room: marketplace.{providerId}      │    │
                    │  └─────────────────────────────────────────────┘    │
                    └─────────────────────────────────────────────────────┘
                                          │
                    ┌─────────────────────▼──────────────────────────────┐
                    │                  CONSUMERS                         │
                    ├─────────┬───────────┬──────────┬──────────────────┤
                    │ POS     │ KDS       │ Customer │ Marketplace      │
                    │ Terminal│ Screen    │ Tracker  │ Status Callback  │
                    │ (accept/│ (prep     │ (real-   │ (webhook out)    │
                    │  reject)│  workflow)│  time)   │                  │
                    └─────────┴───────────┴──────────┴──────────────────┘
```

---

## 3. Hybrid Sync Infrastructure

### Principle

Fiscal data (receipts, Z-reports) keeps the existing **polling + sequential push** model. Everything else moves to **real-time data rooms** via WebSocket.

### 3.1 Data Room Architecture

A **data room** is a scoped WebSocket channel that clients subscribe to. When server-side data changes, the change is broadcast to all subscribers of that room. Clients apply the change to local state (SQLite on Tauri, Zustand on web).

```
Data Rooms (Laravel Broadcasting Channels):

tenant.{tenantId}.company.{companyId}.catalog
  → Product created/updated/deleted, price changes, stock level changes
  → Image URLs changed (client fetches image separately)
  → Category changes

tenant.{tenantId}.company.{companyId}.config
  → Payment methods added/removed/updated
  → Tax rates changed
  → Terminal settings changed

tenant.{tenantId}.company.{companyId}.operators
  → New operator added, PIN changed, permissions changed
  → Operator deactivated

tenant.{tenantId}.company.{companyId}.pos.orders
  → New external order received (website, QR, marketplace)
  → Order status changes from any source
  → Order accepted/rejected by operator

tenant.{tenantId}.company.{companyId}.pos.kitchen
  → (Already exists) Order sent to kitchen, line status changes, order ready

tenant.{tenantId}.company.{companyId}.pos.tables
  → Table status changes (occupied, available, reserved)
  → Table assigned to order

storefront.order.{orderId}
  → Customer-facing: order accepted, preparing, ready, dispatched
  → (Public channel with signed token — no auth required)
```

### 3.2 Hybrid Model — What Goes Where

| Data Type | Direction | Transport | Reason |
|-----------|-----------|-----------|--------|
| Receipts (push) | Terminal → Server | Polling (sequential) | Hash chain requires strict ordering |
| Z-Reports (push) | Terminal → Server | Polling (sequential) | Hash chain requires strict ordering |
| Products (pull) | Server → Terminal | **Data room** + delta fallback | Price/stock changes need to be near-instant |
| Payment config | Server → Terminal | **Data room** + delta fallback | New methods should appear immediately |
| Operator PINs | Server → Terminal | **Data room** + delta fallback | Permission changes must propagate fast |
| External orders | Server → Terminal | **Data room** | Orders from website/QR/marketplace must appear in real-time |
| Order status | Bidirectional | **Data room** | KDS, customer tracker, marketplace callbacks all need real-time |
| Table status | Bidirectional | **Data room** | Multi-terminal environments need live table state |
| Kitchen events | Server → KDS | **Data room** (already exists) | Already implemented |

### 3.3 Offline Recovery (Gap Sync)

When a terminal reconnects after being offline:

1. **WebSocket reconnects** → client sends `last_event_id` per room
2. **Server replays missed events** from a short-lived event log (Redis Streams, 24h TTL)
3. **Fallback**: If too many events missed or replay unavailable, trigger a full delta pull (existing `updated_since` mechanism)
4. **Receipt push resumes**: Polling scheduler detects connectivity, pushes pending receipts in sequence

```
Online:   [WS connected] ──events──▶ apply to local state
Offline:  [WS disconnected] ──── local operations continue (receipts queue)
Reconnect: [WS reconnects]
              ├─ Replay missed events (last_event_id → now)
              ├─ If gap too large → full delta pull
              └─ Resume receipt push polling
```

### 3.4 Event Payload Format

```typescript
interface DataRoomEvent {
  id: string;             // Monotonic event ID (for replay)
  room: string;           // Channel name
  type: string;           // e.g., "product.updated", "order.received"
  payload: unknown;       // Entity data (full or partial)
  timestamp: string;      // ISO 8601
  company_id: string;     // For routing
}
```

### 3.5 Tauri POS Integration

```typescript
// On app startup
const echo = connectEcho(authToken);

// Subscribe to data rooms
echo.private(`tenant.${tenantId}.company.${companyId}.catalog`)
  .listen('.product.updated', (e) => upsertProduct(db, e.payload))
  .listen('.product.deleted', (e) => deleteProduct(db, e.payload.id));

echo.private(`tenant.${tenantId}.company.${companyId}.pos.orders`)
  .listen('.order.received', (e) => {
    insertExternalOrder(db, e.payload);
    showNewOrderNotification(e.payload);
    playAlertSound();
  });

// Keep polling for fiscal push only
receiptSyncScheduler.start();  // unchanged
```

---

## 4. Unified Order Pipeline

### 4.1 New Enums

```php
enum OrderSource: string {
    case POS = 'pos';               // In-store POS terminal
    case Website = 'website';       // Own storefront
    case QrCode = 'qr_code';       // QR code at table or counter
    case MobileApp = 'mobile_app';  // Own mobile application
    case Marketplace = 'marketplace'; // Third-party (Uber Eats, etc.)
    case Phone = 'phone';           // Phone order entered by operator
}

enum OrderChannel: string {
    case DineIn = 'dine_in';
    case Takeaway = 'takeaway';
    case Delivery = 'delivery';
    case DriveThrough = 'drive_through';
}

enum FulfillmentType: string {
    case Immediate = 'immediate';     // Walk-in, prepare now
    case Scheduled = 'scheduled';     // Prepare for future time
    case OnDemand = 'on_demand';      // Marketplace driver pickup
}

// Extended from current 5-state to support external orders
enum OrderStatus: string {
    // Existing
    case Open = 'open';
    case SentToKitchen = 'sent_to_kitchen';
    case Ready = 'ready';
    case Closed = 'closed';
    case Cancelled = 'cancelled';

    // New — for external orders
    case Received = 'received';       // Incoming, awaiting operator acceptance
    case Accepted = 'accepted';       // Operator confirmed, entering kitchen queue
    case Rejected = 'rejected';       // Operator declined (capacity, closed, etc.)
    case Dispatched = 'dispatched';   // Handed to delivery driver
    case PickedUp = 'picked_up';      // Customer collected takeaway/delivery
}
```

### 4.2 Order State Machine

```
                                    ┌──────────┐
                   POS orders ────▶ │   Open   │ ──▶ (existing flow unchanged)
                                    └──────────┘

                                    ┌──────────┐      ┌──────────┐
External orders (web/QR/mktpl) ──▶ │ Received │ ──▶ │ Accepted │
                                    └────┬─────┘      └─────┬────┘
                                         │                  │
                                    ┌────▼─────┐      ┌─────▼────────────┐
                                    │ Rejected │      │ SentToKitchen    │
                                    └──────────┘      └─────┬────────────┘
                                                            │
                                                      ┌─────▼────┐
                                                      │  Ready   │
                                                      └─────┬────┘
                                                   ┌────────┼────────┐
                                              ┌────▼───┐  ┌─▼──────┐ │
                                   delivery:  │Dispatch│  │PickedUp│ │ dine-in:
                                              │  -ed   │  │        │ │ direct close
                                              └────┬───┘  └───┬────┘ │
                                                   │          │      │
                                              ┌────▼──────────▼──────▼──┐
                                              │        Closed           │
                                              │   (receipt created)     │
                                              └─────────────────────────┘
```

### 4.3 OrderIntakeService

Central entry point for all non-POS orders. POS orders continue through the existing `OrderManagementService`.

```php
class OrderIntakeService
{
    public function __construct(
        private readonly OrderManagementService $orderManagement,
        private readonly CapacityService $capacityService,
        private readonly MenuAvailabilityService $menuAvailability,
        private readonly OrderNotificationService $notifications,
    ) {}

    /**
     * Receive an external order from any source.
     * Validates menu availability, checks capacity, creates order as Received.
     */
    public function receive(ExternalOrderData $data): Order
    {
        // 1. Validate all items are available for the requested channel
        // 2. Check store is open / accepting orders for this source
        // 3. Check capacity (throttle if at limit)
        // 4. Create order with status=Received, source, channel
        // 5. Broadcast OrderReceived to pos.orders room
        // 6. If auto-accept enabled → immediately transition to Accepted
        // 7. Return order with estimated prep time
    }

    /**
     * Operator accepts an incoming order (or auto-accept fires).
     */
    public function accept(Order $order, ?int $estimatedMinutes = null): Order
    {
        // 1. Transition Received → Accepted
        // 2. Assign to terminal (auto or operator-selected)
        // 3. Broadcast OrderAccepted to pos.orders + storefront.order.{id}
        // 4. If kitchen workflow → auto-send to kitchen
        // 5. Notify customer (push/SMS/email based on source)
        // 6. Notify marketplace callback (if applicable)
    }

    /**
     * Operator rejects an incoming order.
     */
    public function reject(Order $order, string $reason): Order
    {
        // 1. Transition Received → Rejected
        // 2. Broadcast OrderRejected to storefront.order.{id}
        // 3. Notify customer with reason
        // 4. Notify marketplace callback (if applicable)
        // 5. Handle refund if payment was pre-authorized
    }
}
```

### 4.4 Changes to `pos_orders` Table

```sql
-- New columns on pos_orders
ALTER TABLE pos_orders ADD COLUMN order_source VARCHAR(20) NOT NULL DEFAULT 'pos';
ALTER TABLE pos_orders ADD COLUMN order_channel VARCHAR(20) DEFAULT NULL;
ALTER TABLE pos_orders ADD COLUMN fulfillment_type VARCHAR(20) NOT NULL DEFAULT 'immediate';
ALTER TABLE pos_orders ADD COLUMN external_reference VARCHAR(255) DEFAULT NULL;
ALTER TABLE pos_orders ADD COLUMN marketplace_provider_id UUID DEFAULT NULL;
ALTER TABLE pos_orders ADD COLUMN marketplace_order_id VARCHAR(255) DEFAULT NULL;
ALTER TABLE pos_orders ADD COLUMN estimated_ready_at TIMESTAMPTZ DEFAULT NULL;
ALTER TABLE pos_orders ADD COLUMN scheduled_for TIMESTAMPTZ DEFAULT NULL;
ALTER TABLE pos_orders ADD COLUMN delivery_address JSONB DEFAULT NULL;
ALTER TABLE pos_orders ADD COLUMN delivery_fee DECIMAL(12,2) DEFAULT 0;
ALTER TABLE pos_orders ADD COLUMN platform_fee DECIMAL(12,2) DEFAULT 0;
ALTER TABLE pos_orders ADD COLUMN customer_phone VARCHAR(30) DEFAULT NULL;
ALTER TABLE pos_orders ADD COLUMN customer_email VARCHAR(255) DEFAULT NULL;
ALTER TABLE pos_orders ADD COLUMN auto_accepted BOOLEAN DEFAULT FALSE;

-- Index for external order lookups
CREATE INDEX idx_orders_source ON pos_orders(order_source, status);
CREATE INDEX idx_orders_marketplace ON pos_orders(marketplace_provider_id, marketplace_order_id);
CREATE INDEX idx_orders_scheduled ON pos_orders(scheduled_for) WHERE scheduled_for IS NOT NULL;
```

---

## 5. Feature: Online Ordering — Three Distribution Channels

Online ordering is delivered through three independent channels, all backed by the same **Storefront API**. See [STOREFRONT-WEBSITE.md](STOREFRONT-WEBSITE.md) for the full website architecture.

### 5.1 Channel Overview

```
┌─────────────────────────────────────────────────────────────────────┐
│                      STOREFRONT API                                  │
│  (Public REST API — menu, availability, order placement, tracking)   │
└──────┬──────────────────────┬────────────────────────┬──────────────┘
       │                      │                        │
┌──────▼──────────┐  ┌───────▼────────────┐  ┌───────▼────────────────┐
│ Channel 1:      │  │ Channel 2:         │  │ Channel 3:             │
│ MANAGED         │  │ PUBLIC API         │  │ WOOCOMMERCE PLUGIN     │
│ PLATFORM        │  │                    │  │                        │
│                 │  │ Tenant gets API    │  │ WP plugin installed    │
│ easyorders.tn   │  │ key + docs.        │  │ on tenant's own site.  │
│ commanderapid.fr│  │ Build any custom   │  │ Products sync from ERP.│
│                 │  │ frontend.          │  │ Paid orders pushed     │
│ Astro SSG/SSR   │  │                    │  │ back to ERP.           │
│ + React islands │  │ Same endpoints as  │  │                        │
│ SEO optimized   │  │ Channel 1 uses.    │  │ Payments handled by    │
│ Per-country     │  │                    │  │ WooCommerce natively.  │
└─────────────────┘  └────────────────────┘  └────────────────────────┘
```

### 5.2 Channel 1: Managed Multi-Tenant Platform

**Our hosted ordering websites**, one domain per country, one app per vertical.

| Vertical | Tunisia | France | Pattern |
|----------|---------|--------|---------|
| IziPOS (F&B + retail) | `easyorders.tn` | `commanderapid.fr` | `{tenant}.easyorders.tn` |
| Otospex (automotive) | `otospex-orders.tn` | `otospex-orders.fr` | `{tenant}.otospex-orders.fr` |

- **Architecture**: Astro (SSG for SEO pages, SSR for dynamic) + React islands (cart, checkout, tracking)
- **Hosting**: Cloudflare Pages (or equivalent edge hosting), separate from ERP infrastructure
- **Tenant routing**: Subdomain per tenant — `restaurant-name.easyorders.tn`
- **SEO**: Static pages for Google crawling (menu, location, about), schema.org markup for local SEO
- **V1**: Templated pages generated from catalog data, no tenant content editing
- **V2**: Content editing (CMS-like), custom domains (`order.mybusiness.com`)

**Two separate codebases** because IziPOS and Otospex have different:
- Data structures (F&B menus with modifiers vs automotive service catalogs)
- Ordering complexity (food ordering vs service booking + parts)
- Design language (different UX patterns for each vertical)

### 5.3 Channel 2: Public Storefront API

Each tenant gets API credentials from the ERP back-office. Fully documented REST API.

```
Authentication: X-Api-Key header (scoped to tenant + company)
Rate limiting: per-key, configurable
Endpoints: same as what Channel 1 consumes internally
```

Use cases:
- Tenant has their own developer / agency building a custom site
- Integration with other platforms (not WooCommerce)
- Mobile app (Channel 4, future)

### 5.4 Channel 3: WooCommerce Plugin

A WordPress/WooCommerce plugin for tenants who already have a WordPress site.

**Sync model: ERP is source of truth, WooCommerce is a storefront skin.**

| Data | Direction | Notes |
|------|-----------|-------|
| Products (name, price, images, modifiers) | ERP → Woo | One-way push. Overwrites Woo product data. |
| Product descriptions | ERP → Woo (initial) | If tenant edits description in Woo, local edit stays — NOT synced back |
| Stock / availability | ERP → Woo | Real-time or near-real-time via webhook |
| Categories | ERP → Woo | Mapped to WooCommerce categories |
| Orders | Woo → ERP | Only **paid/completed** orders pushed to ERP as external orders |
| Order status | ERP → Woo | Accepted, preparing, ready status reflected in WooCommerce |
| Payments | WooCommerce only | Handled entirely by Woo's payment gateway ecosystem |
| Customers | Not synced | WooCommerce manages its own customers |

**Plugin configuration (in WordPress admin):**
- API endpoint URL (auto-detected from ERP region)
- API key (generated in ERP back-office)
- Company ID
- Sync interval (default: every 15 min for products, real-time for orders via webhook)
- Category mapping (optional: map ERP categories to existing Woo categories)

**Plugin technical approach:**
- Registers a webhook endpoint in WordPress for order status callbacks
- Uses WP-Cron for periodic product sync
- Listens to `woocommerce_order_status_completed` hook to push orders
- Creates a custom WooCommerce order status "Preparing" / "Ready for Pickup"

**Future: Shopify App** — Same pattern, noted for V2.

### 5.5 Storefront Configuration (ERP Back-Office)

The business owner configures their online presence from the web admin panel:

- **Activation**: Enable/disable online ordering (gated add-on feature)
- **Channel selection**: Which channels are active (managed platform, API, WooCommerce)
- **API keys**: Generate/revoke API keys for Channel 2 and 3
- **Branding** (Channel 1): Logo, colors, banner images, business description
- **Menu selection**: Which categories/products appear online (separate from POS menu)
- **Pricing**: Same as POS or online-specific pricing (markup for delivery)
- **Hours**: Online ordering hours (can differ from store hours)
- **Delivery zones**: Radius/polygon-based delivery areas with distance-based fees
- **Minimum order**: Minimum cart value for delivery
- **Order settings**: Auto-accept vs manual accept, default prep time estimates

### 5.6 Multi-Location Menu Management

- Each location has its own online menu (subset of its catalog)
- **Clone**: Operator can clone a menu from Location A to Location B, then customize
- **Independent edits**: Each location's menu is independent after cloning
- **Future (franchise management)**: Central entity creates "master menus", assigns to locations, locations can override specific items/prices

### 5.7 Data Model

```php
class StorefrontConfig extends Model {
    // tenant_id, company_id, location_id
    // slug (unique, URL-safe — used as subdomain)
    // vertical (enum: izipos, otospex)
    // is_active
    // is_addon_enabled (gating flag)
    // branding (JSONB: logo_url, primary_color, banner_url, description)
    // ordering_hours (JSONB: per-day open/close times)
    // delivery_config (JSONB: zones, fees, minimum_order)
    // auto_accept_orders (boolean)
    // default_prep_minutes (int)
    // seo_metadata (JSONB: title, description, keywords, schema.org overrides)
}

class StorefrontApiKey extends Model {
    // tenant_id, company_id
    // key_hash (hashed API key)
    // channel (enum: api, woocommerce)
    // label (operator-given name, e.g., "My WordPress Site")
    // is_active
    // last_used_at
    // rate_limit_per_minute (default: 60)
    // scopes (JSONB: ['menu:read', 'orders:write', 'status:read'])
}

class OnlineMenuItem extends Model {
    // storefront_config_id
    // product_id or composite_item_id (polymorphic)
    // is_available (can be toggled independently of POS)
    // online_price (nullable — override POS price)
    // position (display order)
    // online_image_url (nullable — override default)
    // available_channels (JSONB: ['managed', 'api', 'woocommerce'] or null for all)
}
```

---

## 6. Feature: QR Code Menu & Ordering

### 6.1 Overview

QR codes placed on tables, at the counter, or on takeaway packaging. Scanning opens a mobile-optimized web menu. Two modes: **view-only** (digital menu) and **order-enabled** (place order from phone).

### 6.2 QR Code Types

| Type | Use Case | Behavior |
|------|----------|----------|
| **Table QR** | Dine-in, on each table | Opens menu → order attached to `table_id` |
| **Counter QR** | Takeaway counter, poster | Opens menu → order as takeaway pickup |
| **Receipt QR** | Printed on receipt | Opens order status / reorder page |
| **Marketing QR** | Flyers, social media | Opens storefront homepage |

### 6.3 Table QR Flow

```
Customer scans QR on Table #7
  └─ Opens: storefront.example.com/qr/t/{signed_table_token}
       ├─ Token decodes to: company_id + location_id + table_id
       ├─ Menu loads for that location
       ├─ Customer browses, adds items to cart
       ├─ Checkout: name + optional phone (no account needed)
       ├─ Payment: pay-at-table (card terminal) or online
       └─ POST /api/storefront/{slug}/orders
            body: { table_id, source: 'qr_code', channel: 'dine_in', ... }

Server receives order:
  ├─ Creates order with order_source='qr_code', table_id=Table#7
  ├─ Broadcasts to pos.orders room → POS terminal shows "New QR order on Table 7"
  ├─ Operator accepts (or auto-accept)
  ├─ Order enters kitchen queue
  └─ Customer sees real-time status on their phone
```

### 6.4 Multi-Round Ordering

Dine-in QR allows multiple rounds:
- First scan creates a session (cookie/localStorage on customer's phone)
- Subsequent scans on same table add to existing open order
- Operator can close the order and generate receipt when customer is done
- "Call waiter" button on the QR menu → notification to POS terminal

### 6.5 QR Code Generation

```php
class QrCodeService {
    /**
     * Generate a signed, non-guessable URL for a table QR code.
     * Token is HMAC-signed with company secret — cannot be forged.
     */
    public function generateTableQr(Table $table): string;

    /**
     * Generate a generic storefront QR (counter/marketing).
     */
    public function generateStorefrontQr(StorefrontConfig $config): string;

    /**
     * Validate and decode a QR token.
     */
    public function decodeToken(string $token): QrCodePayload;
}
```

---

## 7. Feature: Marketplace Aggregation

### 7.1 Overview

Connect to delivery platforms (Uber Eats, Deliveroo, Glovo, and future platforms) through a unified adapter pattern. The POS becomes the single control panel — operators don't need to use multiple tablets.

### 7.2 Provider Adapter Pattern

```php
interface MarketplaceAdapter
{
    /** Accept an incoming webhook payload and normalize to ExternalOrderData */
    public function normalizeOrder(array $webhookPayload): ExternalOrderData;

    /** Acknowledge order receipt to the marketplace */
    public function acknowledgeOrder(string $marketplaceOrderId): void;

    /** Update order status on the marketplace */
    public function updateStatus(string $marketplaceOrderId, OrderStatus $status): void;

    /** Reject order on the marketplace */
    public function rejectOrder(string $marketplaceOrderId, string $reason): void;

    /** Sync menu/availability to the marketplace */
    public function pushMenu(Collection $menuItems): void;

    /** Mark item as unavailable (86'd) on the marketplace */
    public function markItemUnavailable(string $externalItemId): void;

    /** Get provider-specific configuration schema */
    public static function configSchema(): array;
}

// Implementations
class UberEatsAdapter implements MarketplaceAdapter { ... }
class DeliverooAdapter implements MarketplaceAdapter { ... }
class GlovoAdapter implements MarketplaceAdapter { ... }
class GenericWebhookAdapter implements MarketplaceAdapter { ... }
```

### 7.3 Marketplace Provider Configuration

Operators configure marketplace connections from the web back-office:

```php
class MarketplaceProvider extends Model {
    // id, tenant_id, company_id, location_id
    // provider_type (enum: uber_eats, deliveroo, glovo, generic_webhook)
    // display_name (operator-chosen name, e.g., "Our Uber Eats Store")
    // is_active
    // credentials (JSONB, encrypted: api_key, secret, store_id, etc.)
    // webhook_secret (for validating incoming webhooks)
    // config (JSONB: auto_accept, prep_time_override, fee_structure)
    // menu_sync_enabled (boolean — push menu changes automatically)
    // last_synced_at
}
```

### 7.4 Webhook Intake

```
Uber Eats webhook → POST /api/webhooks/marketplace/{provider_id}
  ├─ Verify webhook signature (provider.webhook_secret)
  ├─ Route to correct adapter: UberEatsAdapter->normalizeOrder()
  ├─ Adapter normalizes → ExternalOrderData
  ├─ OrderIntakeService->receive(data)
  ├─ Acknowledge to marketplace: adapter->acknowledgeOrder()
  └─ Broadcast to POS terminal via pos.orders data room
```

### 7.5 Status Synchronization

When an operator changes order status on the POS, the change propagates back:

```
Operator marks order "Ready" on POS
  └─ OrderManagementService fires OrderReady event
       ├─ Broadcast to pos.orders room (other terminals)
       ├─ Broadcast to storefront.order.{id} room (customer)
       └─ MarketplaceStatusSyncListener:
            ├─ Check: order.marketplace_provider_id is not null?
            ├─ Load adapter for provider
            └─ adapter->updateStatus(marketplace_order_id, 'ready')
```

### 7.6 Menu Sync to Marketplaces

When the operator updates the product catalog:
1. Product updated event fires
2. `MarketplaceMenuSyncListener` checks which providers have `menu_sync_enabled`
3. For each provider, queues a `SyncMenuToMarketplace` job
4. Job calls `adapter->pushMenu()` with the updated items
5. Item availability (86'd) changes push immediately via `adapter->markItemUnavailable()`

---

## 8. Feature: Mobile App Ordering

### 8.1 Overview

A future React Native + Expo app (already in the tech stack) for customers. Uses the same Storefront API as the website — different client, same backend.

### 8.2 Mobile-Specific Additions

- **Push notifications**: Order status via Firebase Cloud Messaging / APNs
- **Saved addresses**: Delivery address management
- **Order history**: Past orders with reorder capability
- **Loyalty integration**: Points display, rewards redemption
- **Geolocation**: Auto-detect nearest location, delivery zone check
- **Biometric payment**: Face ID / fingerprint for saved payment methods
- **Offline menu caching**: Browse menu without connectivity, submit order when online

### 8.3 Implementation Note

The mobile app shares the Storefront API (`/api/storefront/`) — no separate backend needed. Mobile-specific features (push tokens, device registration) are handled by a thin `MobileDevice` model.

---

## 9. Supporting Infrastructure

### 9.1 Menu Availability Service

Online menu is separate from POS product catalog. Items can be:
- Available on POS but hidden online
- Available online but at a different price
- Temporarily unavailable (86'd) across all channels or per-channel
- Available only during certain hours (e.g., lunch specials)

```php
class MenuAvailabilityService {
    public function getAvailableItems(
        string $companyId,
        string $locationId,
        OrderSource $source,
        OrderChannel $channel,
        ?Carbon $requestedTime = null,
    ): Collection;

    public function markItem86(string $productId, ?OrderSource $source = null): void;
    public function restoreItem(string $productId): void;
    public function isStoreOpen(string $locationId, OrderSource $source): bool;
}
```

### 9.2 Estimated Prep Time Service

```php
class PrepTimeEstimationService {
    /**
     * Estimate preparation time based on:
     * - Item complexity (composite items take longer)
     * - Current kitchen load (pending orders count)
     * - Historical data (average prep time by item)
     * - Time of day / day of week patterns
     */
    public function estimate(Order $order): int; // minutes
}
```

### 9.3 Delivery Zone Service

```php
class DeliveryZoneService {
    /**
     * Check if an address is within delivery range.
     * Calculate delivery fee based on distance/zone.
     */
    public function checkDeliverability(
        string $locationId,
        array $coordinates, // [lat, lng]
    ): DeliveryZoneResult; // { deliverable, zone, fee, estimated_minutes }
}
```

---

## 10. Kitchen & Fulfillment Routing

### 10.1 Order Source Visibility on KDS

Kitchen display shows order source as a badge:

```
┌─────────────────────────┐
│ #042  🏪 POS  Table 7   │  ← In-store dine-in
│ Burger x2, Fries x2     │
│ ⏱ 3:42                  │
├─────────────────────────┤
│ #043  🌐 Website         │  ← Online takeaway
│ Pizza Margherita x1      │
│ Pickup at 12:30          │
│ ⏱ 1:15                  │
├─────────────────────────┤
│ #044  🚗 Uber Eats       │  ← Marketplace delivery
│ Chicken Wrap x3          │
│ Driver arriving ~10min   │
│ ⏱ 0:45                  │
└─────────────────────────┘
```

### 10.2 Fulfillment Routing

When an order reaches "Ready":

| Channel | Action |
|---------|--------|
| Dine-in | Notify server, mark table as served |
| Takeaway (POS) | Announce order number on display |
| Takeaway (online) | Notify customer "Order ready for pickup" |
| Delivery (own) | Assign to driver, trigger dispatch |
| Delivery (marketplace) | Notify marketplace "Ready for pickup", driver dispatched by platform |

### 10.3 Printer Routing by Source

Different order sources may route to different printers:

```php
class PrintRoutingService {
    /**
     * Determine which printer(s) should print a kitchen ticket.
     * Rules:
     * - Marketplace orders → loud alert + kitchen printer
     * - QR dine-in → kitchen printer only (no counter ticket)
     * - Takeaway → kitchen + counter pickup printer
     */
    public function route(Order $order): array; // PrinterTarget[]
}
```

---

## 11. Capacity & Throttling

### 11.1 Order Capacity Management

Prevent kitchen overload by limiting concurrent orders:

```php
class CapacityService {
    /**
     * Check if the location can accept a new order.
     *
     * Configurable per location:
     * - max_concurrent_orders: 20 (total active orders)
     * - max_online_orders_per_slot: 5 (per 15-min window)
     * - pause_online_orders: boolean (operator panic button)
     */
    public function canAcceptOrder(
        string $locationId,
        OrderSource $source,
    ): CapacityResult; // { accepted, reason, next_available_slot }
}
```

### 11.2 Operator Controls

- **Pause online orders**: One-click toggle to stop accepting external orders (rush hour)
- **Adjust prep time**: Increase estimated time during peak (customers see longer wait)
- **Mark items 86'd**: Instantly remove items from all online menus
- **Set max orders per slot**: Limit scheduled orders per time window

---

## 12. Payments for Remote Orders

### 12.1 Payment Flow by Source

| Source | Payment Timing | Methods | Who Handles Payment |
|--------|---------------|---------|---------------------|
| POS | At checkout | Cash, card terminal, voucher | POS terminal |
| Managed website (Ch1) | At order placement | Online (Stripe/PayPal/local), pay-on-pickup | Storefront (our infrastructure) |
| QR Code (dine-in) | At table or at counter | Online (phone), card terminal at table, cash | Storefront or POS |
| QR Code (takeaway) | At order placement | Online, pay-on-pickup | Storefront |
| WooCommerce (Ch3) | At order placement | Whatever Woo payment plugins tenant has | **WooCommerce** (not us) |
| Marketplace | Handled by platform | Platform collects, settles to merchant | **Marketplace platform** |
| Mobile App | At order placement | Online, saved cards, Apple/Google Pay | Mobile app (our SDK) |

**Key principle**: We only process payments for Channel 1 (managed platform), QR ordering, and the mobile app. WooCommerce and marketplace payments are handled externally — we only receive "paid order" notifications.

### 12.2 Online Payment Integration

```php
// New PaymentGateway adapter pattern (similar to MarketplaceAdapter)
interface PaymentGatewayAdapter {
    public function createPaymentIntent(Order $order): PaymentIntent;
    public function capturePayment(string $paymentIntentId): PaymentResult;
    public function refund(string $paymentIntentId, int $amountCents): RefundResult;
    public function handleWebhook(array $payload): WebhookResult;
}
```

### 12.3 Pre-Authorization vs Capture

For online orders: **authorize at order placement, capture after acceptance**.
If the operator rejects the order, the authorization is released (no charge).

---

## 13. Notifications & Customer Communication

### 13.1 Notification Channels

| Event | SMS | Email | Push (App) | In-Browser | Marketplace Callback |
|-------|-----|-------|------------|------------|---------------------|
| Order received | - | - | - | - | acknowledgeOrder() |
| Order accepted | ✓ | ✓ | ✓ | ✓ (WS) | updateStatus() |
| Order rejected | ✓ | ✓ | ✓ | ✓ (WS) | rejectOrder() |
| Preparing | - | - | ✓ | ✓ (WS) | - |
| Ready for pickup | ✓ | ✓ | ✓ | ✓ (WS) | updateStatus() |
| Out for delivery | ✓ | - | ✓ | ✓ (WS) | updateStatus() |
| Delivered | - | ✓ | ✓ | - | updateStatus() |

### 13.2 Implementation

Use Laravel Notifications with per-channel routing. Customer notification preferences stored in order metadata or customer profile.

---

## 14. Analytics & Reconciliation

### 14.1 Per-Channel Analytics

The existing analytics service (`PosAnalyticsService`) needs to be extended:

- **Sales by source**: POS vs Website vs QR vs each marketplace
- **Revenue by channel**: Dine-in vs Takeaway vs Delivery
- **Platform fees**: Track fees per marketplace for reconciliation
- **Conversion rate**: Menu views → orders (storefront/QR)
- **Average order value**: By source and channel
- **Prep time accuracy**: Estimated vs actual, by source
- **Rejection rate**: % of external orders rejected, by source
- **Peak hours by source**: When do online orders spike vs POS

### 14.2 Marketplace Reconciliation

```php
class MarketplaceReconciliationService {
    /**
     * Compare orders received from marketplace vs settlement reports.
     * Flag discrepancies: missing payments, fee mismatches, refund gaps.
     */
    public function reconcile(
        MarketplaceProvider $provider,
        Carbon $periodStart,
        Carbon $periodEnd,
    ): ReconciliationReport;
}
```

---

## 15. Compliance Implications

### 15.1 Fiscal Impact

| Concern | Impact | Resolution |
|---------|--------|------------|
| Online orders create receipts | Receipts must be in the hash chain | External orders are closed into receipts by the terminal, same chain |
| Marketplace orders | Same | Receipt created when order is completed on POS |
| VAT per consumption mode | Delivery = A_EMPORTER | Order channel maps to consumption mode automatically |
| Training mode | Must not create real receipts from external sources | Block external orders on training-mode terminals |
| Refunds from online orders | Must create return receipts | Use existing void/return flow, source tracked on receipt |

### 15.2 Key Rule

**External orders do NOT create receipts on arrival.** They create orders. Receipts are only created when the operator closes the order on the POS — preserving the hash chain and fiscal integrity. The terminal that closes the order owns the receipt in its chain.

---

## 16. Additional Features to Consider

### Must-Have (Before Launch)

| Feature | Reason |
|---------|--------|
| **Store hours management** | Prevent orders when closed; different hours per channel |
| **Item 86'ing (out of stock)** | Instant removal from all online menus |
| **Order throttling / capacity limits** | Prevent kitchen overload from online surge |
| **Customer order tracking page** | Signed URL, no auth required, real-time status |
| **Operator alert sounds** | Audio + visual notification for new external orders |
| **Auto-print kitchen tickets** | External orders auto-print on kitchen printer |
| **Prep time estimation** | Show customers expected wait time |

### Should-Have (V1.1)

| Feature | Reason |
|---------|--------|
| **Scheduled orders** | "Ready at 12:30" — queue management |
| **Delivery zone management** | Polygon-based zones with distance-based fees |
| **Customer accounts** | Saved addresses, order history, loyalty points |
| **Reorder from history** | One-tap reorder of previous orders |
| **Multi-language storefront** | Match ERP's existing i18n (en/fr/ar) |
| **SEO & social sharing** | Meta tags, Open Graph for storefront |
| **Coupon/promo codes online** | Extend existing discount system to storefront |
| **Tipping** | Optional tip at checkout (online) |
| **Customer reviews / ratings** | Post-order feedback collection |

### Nice-to-Have (V2)

| Feature | Reason |
|---------|--------|
| **Kitchen display per station** | Split orders by category to bar/grill/dessert screens |
| **Driver management** | Own delivery fleet tracking (not marketplace) |
| **Customer display (QR)** | Show order progress on in-store screen |
| **Group ordering** | Multiple people add to same table order via QR |
| **Allergen / dietary filters** | Filter menu by allergens, vegan, halal, etc. |
| **Upsell / cross-sell** | "Add fries for 2 EUR?" during online checkout |
| **A/B testing** | Storefront layout experiments |
| **Loyalty tiers** | Bronze/Silver/Gold with per-tier pricing |
| **Subscription orders** | Weekly recurring orders for regulars |
| **AI prep time** | ML model trained on historical order data |
| **Multi-brand storefronts** | One kitchen, multiple virtual brands (ghost kitchens) |
| **Catering / bulk orders** | Large orders with advance scheduling + custom pricing |

---

## 17. Implementation Phases

### Phase 0 — Prerequisites (1-2 weeks)

> Complete Phase 3 F&B wiring + build hybrid sync infrastructure

- [ ] Wire table management UI into POS checkout flow (Phase 3.1)
- [ ] Wire KDS into POS checkout flow (Phase 3.3)
- [ ] Implement data room infrastructure (Laravel Broadcasting channels)
- [ ] Add WebSocket subscription to Tauri POS for catalog/config/orders rooms
- [ ] Implement offline recovery (delta pull on reconnect as fallback)
- [ ] Add `order_source`, `order_channel`, `fulfillment_type` columns to `pos_orders`
- [ ] Extend `OrderStatus` enum with new states (Received, Accepted, Rejected, Dispatched, PickedUp)
- [ ] Build `OrderIntakeService` as the unified entry point for external orders

### Phase 1 — Storefront API + Managed Platform (4-5 weeks)

> The Storefront API (backbone for all channels) + first managed website

**Phase 1a — Storefront API (2-3 weeks)**
- [ ] **Backend**: Storefront API module (public menu, availability, order placement, order tracking)
- [ ] **Backend**: `StorefrontConfig` model + `StorefrontApiKey` model
- [ ] **Backend**: `OnlineMenuItem` model (online menu separate from POS catalog)
- [ ] **Backend**: Menu availability service (store hours, item 86'ing, capacity)
- [ ] **Backend**: API key authentication middleware (rate limiting, scoping)
- [ ] **Backend**: Order intake webhook (status callbacks for external integrations)
- [ ] **Frontend (POS/Web)**: External orders panel — accept/reject incoming orders
- [ ] **Frontend (POS/Web)**: Source badges on KDS and active orders board
- [ ] **Frontend (Admin)**: Storefront config page (branding, hours, menu, API keys)
- [ ] **Frontend (Admin)**: Online menu editor (select items, set online prices, reorder)
- [ ] **Notifications**: SMS/email for order accepted + ready

**Phase 1b — Managed IziPOS Website (2 weeks)**
- [ ] **Storefront**: Astro project scaffold (separate repo, Cloudflare Pages deployment)
- [ ] **Storefront**: SEO pages — homepage, menu browsing, location page (SSG)
- [ ] **Storefront**: React islands — cart, checkout (guest), order tracking (SSR)
- [ ] **Storefront**: Multi-tenant subdomain routing
- [ ] **Storefront**: Schema.org markup (LocalBusiness, Restaurant, Menu)
- [ ] **Payment**: Stripe integration for managed platform (Tunisia: defer, pickup-first)

### Phase 2 — QR Code Ordering (2-3 weeks)

> Tables get QR codes, customers order from their phones

- [ ] **Backend**: QR code generation service (signed table tokens)
- [ ] **Backend**: QR order flow (table assignment, multi-round ordering, session management)
- [ ] **Frontend (Storefront)**: QR mode — detect table context, mobile-optimized menu
- [ ] **Frontend (Storefront)**: Multi-round ordering (add to existing open order)
- [ ] **Frontend (Storefront)**: "Call waiter" button
- [ ] **Frontend (POS/Web)**: QR orders appear on active orders board with table badge
- [ ] **Frontend (Admin)**: QR code generator + printer (per-table, counter, marketing)
- [ ] **Payment**: Pay-at-table flow (waiter brings terminal after QR order)

### Phase 3 — WooCommerce Plugin + Marketplace Aggregation (4-5 weeks)

> WooCommerce integration + marketplace connections

**Phase 3a — WooCommerce Plugin (2 weeks)**
- [ ] **Plugin**: WordPress/WooCommerce plugin scaffold (PHP)
- [ ] **Plugin**: Settings page (API key, endpoint URL, company ID)
- [ ] **Plugin**: Product sync — pull from Storefront API, create/update WooCommerce products
- [ ] **Plugin**: Category mapping (ERP categories → Woo categories)
- [ ] **Plugin**: Image sync (download product images, attach to Woo products)
- [ ] **Plugin**: Order push — listen to `woocommerce_order_status_completed`, POST to Storefront API
- [ ] **Plugin**: Status callback — register webhook endpoint, update Woo order status
- [ ] **Plugin**: Custom order statuses in WooCommerce (Preparing, Ready for Pickup)
- [ ] **Backend**: Webhook endpoint for WooCommerce order intake
- [ ] **Documentation**: Plugin installation guide + API docs

**Phase 3b — Marketplace Aggregation (3 weeks)**
- [ ] **Backend**: `MarketplaceAdapter` interface + `GenericWebhookAdapter`
- [ ] **Backend**: `MarketplaceProvider` model + admin CRUD
- [ ] **Backend**: Webhook intake controller (signature verification, adapter routing)
- [ ] **Backend**: Status sync listener (POS status changes → marketplace callback)
- [ ] **Backend**: Menu sync to marketplace (push catalog changes)
- [ ] **Backend**: Uber Eats adapter (first real integration)
- [ ] **Backend**: Deliveroo adapter
- [ ] **Backend**: Glovo adapter
- [ ] **Frontend (Admin)**: Marketplace connections page (add provider, enter credentials)
- [ ] **Frontend (POS/Web)**: Marketplace order badges + driver ETA display
- [ ] **Analytics**: Revenue per marketplace, fee tracking, reconciliation reports

### Phase 4 — Mobile App (4-6 weeks)

> Customers get a native app for ordering

- [ ] **Mobile**: React Native + Expo app scaffold
- [ ] **Mobile**: Menu browsing with offline caching
- [ ] **Mobile**: Cart + checkout (guest + authenticated)
- [ ] **Mobile**: Push notifications (FCM/APNs)
- [ ] **Mobile**: Order history + reorder
- [ ] **Mobile**: Saved addresses + delivery zone check
- [ ] **Mobile**: Loyalty points display + redemption
- [ ] **Mobile**: Apple Pay / Google Pay integration
- [ ] **Backend**: Mobile device registration + push token management

### Phase 5 — Advanced Features (ongoing)

- [ ] Scheduled orders with time-slot management
- [ ] Delivery zone polygon editor
- [ ] Own fleet delivery management
- [ ] Kitchen station routing (multi-printer)
- [ ] Customer reviews + ratings
- [ ] Upsell/cross-sell engine
- [ ] Multi-brand / ghost kitchen support
- [ ] Marketplace reconciliation dashboard
- [ ] AI-powered prep time estimation

---

## 18. Data Model Changes

### New Tables

```
storefront_configs          — Per-location storefront settings (slug, branding, hours)
storefront_api_keys         — API keys for Channel 2 (public API) and Channel 3 (WooCommerce)
online_menu_items           — Online menu items (subset of catalog, with price overrides)
marketplace_providers       — Connected delivery platforms per location
marketplace_order_events    — Webhook event log (audit trail for marketplace communication)
customer_devices            — Mobile app push tokens (Phase 4)
delivery_zones              — Polygon-based delivery areas with fee tiers
order_notifications         — Notification delivery log (SMS/email/push)
online_payments             — Payment intent tracking (Stripe/PayPal — managed platform only)
qr_codes                    — Generated QR codes with signed tokens
```

### Modified Tables

```
pos_orders                  — +order_source (enum: pos, website, qr_code, mobile_app, marketplace, phone, woocommerce)
                              +order_channel (enum: dine_in, takeaway, delivery, drive_through)
                              +fulfillment_type (enum: immediate, scheduled, on_demand)
                              +external_reference, +marketplace_provider_id,
                              +marketplace_order_id, +estimated_ready_at,
                              +scheduled_for, +delivery_address (JSONB),
                              +delivery_fee, +platform_fee,
                              +customer_phone, +customer_email, +auto_accepted
pos_receipts                — +order_source (denormalized for analytics)
```

---

## 19. API Design

### Public Storefront API (no auth, rate-limited)

```
GET    /api/storefront/{slug}/menu                    → Browse menu
GET    /api/storefront/{slug}/menu/{itemId}            → Item detail + modifiers
GET    /api/storefront/{slug}/availability             → Real-time stock
GET    /api/storefront/{slug}/config                   → Store hours, delivery zones, min order
POST   /api/storefront/{slug}/orders                   → Place order
GET    /api/storefront/{slug}/orders/{token}            → Track order (signed token)
POST   /api/storefront/{slug}/orders/{token}/add-items  → Multi-round add (QR dine-in)
POST   /api/storefront/{slug}/call-waiter              → QR table: call waiter
```

### Authenticated Customer API (for mobile app / logged-in web)

```
GET    /api/customer/orders                            → Order history
POST   /api/customer/orders/{id}/reorder               → Reorder from history
GET    /api/customer/addresses                         → Saved addresses
POST   /api/customer/addresses                         → Add address
GET    /api/customer/loyalty                            → Points balance
```

### Webhook Intake API (marketplace providers)

```
POST   /api/webhooks/marketplace/{providerId}          → Receive order webhook
POST   /api/webhooks/marketplace/{providerId}/status    → Status update from marketplace
POST   /api/webhooks/payments/{provider}                → Payment webhook (Stripe/PayPal)
```

### Internal API (operator, auth required)

```
POST   /api/v1/pos/orders/{id}/accept                  → Accept external order
POST   /api/v1/pos/orders/{id}/reject                  → Reject external order
POST   /api/v1/pos/orders/{id}/dispatch                 → Mark as dispatched
POST   /api/v1/pos/orders/{id}/mark-picked-up           → Mark as picked up
GET    /api/v1/pos/orders?source=website&status=received → Filter orders by source
PATCH  /api/v1/pos/capacity                             → Update capacity settings
POST   /api/v1/pos/items/{id}/86                        → Mark item unavailable
DELETE /api/v1/pos/items/{id}/86                        → Restore item availability
```

---

## 20. Risk & Open Questions

### Technical Risks

| Risk | Mitigation |
|------|------------|
| WebSocket reliability on Tauri desktop | Fallback to polling; reconnect with gap recovery |
| Marketplace API changes | Adapter pattern isolates changes; webhook signature verification |
| Payment provider downtime | Queue order, retry payment; allow pay-on-pickup fallback |
| Kitchen overload from online surge | Capacity service with hard limits; operator pause button |
| Hash chain integrity with multiple order sources | External orders only create receipts when closed on POS — chain stays terminal-owned |

### Resolved Questions

| # | Question | Decision |
|---|----------|----------|
| 1 | Storefront hosting | Separate infrastructure per country domain (Cloudflare Pages or equivalent) |
| 2 | Custom domains | V2 — subdomain routing on our domains first |
| 3 | Delivery management | Separate application, integrated later |
| 4 | Multi-location menu | Clone-based: clone from existing location, then customize independently |
| 5 | Marketplace onboarding | Both: user credentials now, direct partnerships later |
| 6 | Payment provider | Tunisia: pickup-first. International: Stripe + PayPal + local providers |
| 7 | SMS provider | Local provider (Tunisia), Twilio (international) |
| 8 | Revenue model | Gated add-on (free/paid toggle TBD) |

### Remaining Open Questions

1. **Astro deployment per tenant vs multi-tenant app**: Single multi-tenant Astro app with subdomain routing (recommended for V1) or one build per tenant?
2. **Storefront API rate limits**: What defaults per plan tier? (60 req/min suggested for V1)
3. **WooCommerce plugin distribution**: WordPress.org plugin directory (public review process) or direct download from ERP?
4. **Franchise menu management**: When to build central menu management — driven by first multi-location F&B client?
5. **Otospex ordering complexity**: Service booking flow needs separate discovery/design — what's the MVP scope for automotive online ordering?
6. **Tunisia local payment provider**: Which provider? (Flouci, Konnect, Click-to-Pay, etc.)
7. **Tunisia SMS provider**: Which provider? (Ooredoo Business, Tunisie Telecom API, or third-party like Unifonic?)
8. **Shopify App**: Target for V2 — confirm priority relative to other V2 features
