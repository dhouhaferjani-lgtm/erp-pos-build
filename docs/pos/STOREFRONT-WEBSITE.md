# Storefront Website Architecture

> **Status**: Planning
> **Related**: [ONLINE-ORDERING-PLAN.md](ONLINE-ORDERING-PLAN.md) — Unified order pipeline & all channels
> **Scope**: Channel 1 (Managed Multi-Tenant Platform) + Storefront API (shared backbone)

---

## Table of Contents

1. [Overview](#1-overview)
2. [Domain & Tenant Strategy](#2-domain--tenant-strategy)
3. [Technology Stack](#3-technology-stack)
4. [Storefront API](#4-storefront-api)
5. [Astro Site Architecture](#5-astro-site-architecture)
6. [SEO Strategy](#6-seo-strategy)
7. [Ordering Flow](#7-ordering-flow)
8. [QR Code Integration](#8-qr-code-integration)
9. [Multi-Location Menu Management](#9-multi-location-menu-management)
10. [WooCommerce Plugin Spec](#10-woocommerce-plugin-spec)
11. [Deployment & Infrastructure](#11-deployment--infrastructure)
12. [Feature Gating (Add-On)](#12-feature-gating-add-on)

---

## 1. Overview

The storefront is **not part of the ERP monorepo**. It's a separate application (one per vertical) hosted on dedicated infrastructure. It consumes the **Storefront API** — a public API module within the ERP backend that exposes menu data, accepts orders, and provides order tracking.

Three consumers of the Storefront API:
1. **Managed platform** (this document) — our hosted Astro websites
2. **Public API** — third-party developers using API keys
3. **WooCommerce plugin** — WordPress integration

---

## 2. Domain & Tenant Strategy

### Per-Country, Per-Vertical Domains

| Vertical | Country | Domain | Example Tenant |
|----------|---------|--------|----------------|
| IziPOS (F&B + retail) | Tunisia | `easyorders.tn` | `cafe-central.easyorders.tn` |
| IziPOS (F&B + retail) | France | `commanderapid.fr` | `boulangerie-paul.commanderapid.fr` |
| IziPOS (F&B + retail) | UK | `quickorder.co.uk` | `joes-pizza.quickorder.co.uk` |
| Otospex (automotive) | Tunisia | TBD | TBD |
| Otospex (automotive) | France | TBD | TBD |

### Tenant Subdomain Routing

```
{tenant-slug}.{country-domain}
     │               │
     │               └─ Resolved to the correct Astro deployment
     └─ Mapped to StorefrontConfig.slug in the ERP database
```

**Slug rules:**
- Lowercase alphanumeric + hyphens
- Unique per country domain
- Generated from business name, editable by tenant
- Reserved slugs: `www`, `api`, `admin`, `app`, `help`, `support`

### V2: Custom Domains

In V2, tenants can map their own domain (e.g., `order.mybusiness.com`) via:
1. CNAME record pointing to our edge (Cloudflare)
2. SSL certificate auto-provisioned (Cloudflare for SaaS or Let's Encrypt)
3. `StorefrontConfig.custom_domain` column + domain verification flow

---

## 3. Technology Stack

| Layer | Technology | Why |
|-------|------------|-----|
| Framework | **Astro 5** | SSG for SEO pages, SSR for dynamic pages, React islands for interactivity |
| UI Islands | **React 19** | Same framework as ERP frontend — shared component knowledge |
| Styling | **Tailwind CSS 4** | Consistent with ERP design system, theme-able per tenant |
| Hosting | **Cloudflare Pages** | Edge-deployed, fast globally, Workers for SSR, KV for caching |
| State (client) | **Zustand** (minimal) | Cart state, order tracking — lightweight |
| API Client | **Fetch API** | No heavy client library needed for public API |
| i18n | **astro-i18n** or custom | Multi-language (fr, en, ar) per country domain |
| Analytics | **Plausible** or **Umami** | Privacy-friendly, self-hosted option, no cookie banner needed |

### Why Astro (Not Next.js, Remix, etc.)

1. **Islands architecture** — SEO pages ship zero JS. Only interactive components (cart, checkout) hydrate React.
2. **SSG + SSR hybrid** — Menu pages are static (rebuilt on catalog change), checkout is SSR.
3. **Performance** — Critical for mobile users on slow connections (Tunisia, delivery zones).
4. **Simplicity** — Less framework overhead than Next.js for what is essentially a content site with an embedded ordering app.

---

## 4. Storefront API

The Storefront API is a **new module within the ERP backend** (`app/Modules/Storefront/`). It's the single source of truth for all three channels.

### 4.1 Authentication

```
Public endpoints (menu browsing):     No auth, rate-limited by IP
Ordering endpoints:                    API key (X-Api-Key header) or session token
Managed platform (Channel 1):         Internal API key, higher rate limits
Public API (Channel 2):               Tenant-generated API key, configurable limits
WooCommerce (Channel 3):              Dedicated API key with 'woocommerce' scope
```

### 4.2 Endpoints

#### Public (No Auth)

```
GET  /api/storefront/{slug}/config
  → Store info: name, logo, description, hours, delivery zones, social links
  → Used by Astro at build time (SSG) and for SEO meta

GET  /api/storefront/{slug}/menu
  → Full menu: categories with nested items, prices, images, modifiers
  → Supports: ?category_id, ?search, ?dietary_filter
  → Cacheable (ETag, Cache-Control: public, max-age=300)

GET  /api/storefront/{slug}/menu/{itemId}
  → Item detail: description, images gallery, modifier groups, nutritional info
  → Includes: related items / upsells

GET  /api/storefront/{slug}/availability
  → Real-time: which items are in stock, which are 86'd
  → Short cache: max-age=30
  → Used by React island to overlay availability on cached menu
```

#### Order Management (API Key Required)

```
POST /api/storefront/{slug}/orders
  → Place an order (guest or authenticated)
  → Body: { items, customer_info, channel, fulfillment_type, delivery_address?,
            scheduled_for?, payment_method, notes }
  → Returns: { order_id, tracking_token, estimated_ready_at }

GET  /api/storefront/{slug}/orders/{token}
  → Track order status (signed token, no auth needed for customer)
  → Returns: { status, estimated_ready_at, items, total }
  → Supports: WebSocket upgrade for real-time (storefront.order.{orderId} channel)

POST /api/storefront/{slug}/orders/{token}/add-items
  → Multi-round ordering (QR dine-in only)
  → Adds items to existing open order on same table

POST /api/storefront/{slug}/call-waiter
  → QR table mode: sends notification to POS terminal
  → Body: { table_token }
```

#### Webhook Callbacks (Server-to-Server)

```
POST {tenant_webhook_url}/order-status
  → We call the tenant's webhook when order status changes
  → Body: { order_id, external_reference, status, timestamp }
  → Used by WooCommerce plugin and custom integrations
```

### 4.3 Data Flow

```
Astro SSG Build                              Runtime
─────────────                                ───────
At deploy / on catalog change:               On user interaction:

GET /storefront/{slug}/config  ──▶ _data/    React island:
GET /storefront/{slug}/menu    ──▶ _data/      GET /availability ──▶ overlay on menu
                                               POST /orders ──▶ order placed
Astro generates static HTML:                   WS: storefront.order.{id} ──▶ tracking
  /menu (SSG)
  /menu/{category} (SSG)
  /about (SSG)
  /location (SSG)
  /cart (SSR — React island)
  /checkout (SSR — React island)
  /track/{token} (SSR — React island)
```

---

## 5. Astro Site Architecture

### 5.1 Page Structure

```
src/
├── layouts/
│   ├── BaseLayout.astro          # HTML shell, meta, tenant theme
│   └── MenuLayout.astro          # Menu page with sidebar categories
├── pages/
│   ├── index.astro               # Homepage (hero, popular items, about)
│   ├── menu/
│   │   ├── index.astro           # Full menu (SSG)
│   │   └── [category].astro      # Category page (SSG)
│   ├── item/
│   │   └── [slug].astro          # Item detail (SSG)
│   ├── location.astro            # Map, address, hours (SSG)
│   ├── about.astro               # About the business (SSG)
│   ├── cart.astro                # Cart page (React island, client-side)
│   ├── checkout.astro            # Checkout flow (React island, SSR)
│   ├── track/
│   │   └── [token].astro         # Order tracking (React island, SSR)
│   └── qr/
│       └── t/[tableToken].astro  # QR table entry (redirects to menu with table context)
├── components/
│   ├── astro/                    # Static Astro components (no JS)
│   │   ├── MenuCard.astro
│   │   ├── CategoryNav.astro
│   │   ├── StoreHeader.astro
│   │   ├── Footer.astro
│   │   └── SEOHead.astro
│   └── react/                    # Interactive React islands
│       ├── CartButton.tsx        # Floating cart button with item count
│       ├── AddToCartButton.tsx   # On menu items — opens modifier modal
│       ├── CartDrawer.tsx        # Slide-out cart
│       ├── CheckoutForm.tsx      # Guest info, delivery/pickup, payment
│       ├── OrderTracker.tsx      # Real-time status with WebSocket
│       ├── AvailabilityOverlay.tsx # Marks 86'd items on static menu
│       └── ModifierSelector.tsx  # Modifier/topping selection modal
├── lib/
│   ├── api.ts                    # Storefront API client
│   ├── cart.ts                   # Cart state (Zustand, persisted to localStorage)
│   └── tenant.ts                 # Tenant config resolution from subdomain
└── styles/
    ├── base.css                  # Tailwind base
    └── theme.css                 # CSS custom properties from tenant branding
```

### 5.2 Tenant Theme System

Each tenant's branding (from `StorefrontConfig.branding`) is injected as CSS custom properties:

```css
/* Generated per-tenant at build time */
:root {
  --brand-primary: #E85D2C;        /* from branding.primary_color */
  --brand-primary-light: #F28C6A;  /* auto-generated lighter shade */
  --brand-surface: #FFF8F5;        /* auto-generated surface */
  --brand-radius: 0.75rem;         /* rounded by default */
}
```

Astro components use these variables via Tailwind's `theme()` function. The same static menu HTML looks different per tenant.

### 5.3 Multi-Tenant Resolution

```typescript
// src/lib/tenant.ts
// At build time: iterate all active StorefrontConfigs, generate per-tenant
// At runtime (SSR): resolve tenant from subdomain

export async function resolveTenant(request: Request): Promise<TenantConfig> {
  const host = request.headers.get('host'); // "cafe-central.easyorders.tn"
  const slug = host?.split('.')[0];         // "cafe-central"

  // Fetch from API (cached in Cloudflare KV with 5min TTL)
  return fetchTenantConfig(slug);
}
```

### 5.4 Build & Rebuild Strategy

**Initial build**: Astro SSG generates static pages for all active tenants.

**Incremental rebuild**: When a tenant updates their menu/branding in the ERP:
1. ERP dispatches `StorefrontCatalogChanged` event
2. Webhook hits Cloudflare deploy hook for that tenant's pages
3. Cloudflare Pages rebuilds only the affected tenant's routes (incremental)
4. Cache purge for the tenant's subdomain

**Fallback**: If static page is stale, SSR middleware fetches fresh data and serves it while triggering a background rebuild.

---

## 6. SEO Strategy

### 6.1 Local SEO (Primary Goal)

The managed platform's main value is **local search visibility**. When someone searches "pizza near me" or "restaurant tunisien Paris", tenant pages should rank.

**Schema.org markup** (on every tenant's pages):

```json
{
  "@context": "https://schema.org",
  "@type": "Restaurant",  // or "Store", "AutoRepair" for Otospex
  "name": "Cafe Central",
  "image": "https://cafe-central.easyorders.tn/images/hero.jpg",
  "address": {
    "@type": "PostalAddress",
    "streetAddress": "12 Avenue Habib Bourguiba",
    "addressLocality": "Tunis",
    "postalCode": "1000",
    "addressCountry": "TN"
  },
  "geo": { "@type": "GeoCoordinates", "latitude": 36.8, "longitude": 10.18 },
  "telephone": "+216-71-XXX-XXX",
  "url": "https://cafe-central.easyorders.tn",
  "menu": "https://cafe-central.easyorders.tn/menu",
  "servesCuisine": "Tunisian, Mediterranean",
  "openingHoursSpecification": [...],
  "hasMenu": {
    "@type": "Menu",
    "hasMenuSection": [...]
  },
  "potentialAction": {
    "@type": "OrderAction",
    "target": "https://cafe-central.easyorders.tn/menu"
  }
}
```

### 6.2 Page-Level SEO

| Page | Title Pattern | Meta Description | Crawlable |
|------|--------------|------------------|-----------|
| Homepage | `{Business Name} - Commander en ligne` | Auto-generated from business description | Yes (SSG) |
| Menu | `Menu - {Business Name}` | "Consultez le menu de {name} et commandez en ligne" | Yes (SSG) |
| Category | `{Category} - {Business Name}` | "Découvrez nos {category}: {top 3 item names}..." | Yes (SSG) |
| Item | `{Item Name} - {Business Name}` | Item description (first 160 chars) | Yes (SSG) |
| Location | `{Business Name} - Adresse et Horaires` | Address + hours summary | Yes (SSG) |
| Cart | `Votre panier - {Business Name}` | - | No (noindex) |
| Checkout | `Commander - {Business Name}` | - | No (noindex) |
| Tracking | `Suivi de commande` | - | No (noindex) |

### 6.3 Technical SEO

- **Sitemap**: Auto-generated per tenant, submitted to Google Search Console
- **robots.txt**: Per-subdomain, blocks cart/checkout/tracking
- **Canonical URLs**: Self-referencing canonicals on all pages
- **hreflang**: If tenant operates in multiple languages
- **Core Web Vitals**: Astro + SSG = excellent LCP/CLS/INP scores
- **Mobile-first**: Responsive design, all pages mobile-optimized
- **Open Graph / Twitter Cards**: For social sharing of menu items

### 6.4 Platform-Level SEO

The root domain (`easyorders.tn`) itself serves as a **directory**:
- Homepage: "Commandez en ligne auprès des meilleurs restaurants en Tunisie"
- City pages: `/tunis`, `/sfax`, `/sousse` — list tenants by city
- Category pages: `/pizza`, `/sushi`, `/cafe` — list tenants by cuisine type
- This creates internal linking that boosts individual tenant pages

---

## 7. Ordering Flow

### 7.1 Guest Checkout (Default)

```
Browse menu → Add items (with modifiers) → View cart
  → Checkout:
     ├─ Name (required)
     ├─ Phone (required) or Email
     ├─ Channel: Pickup or Delivery
     │   └─ If delivery: address + delivery zone validation
     ├─ Fulfillment: Now or Scheduled (date/time picker)
     ├─ Notes / Special instructions
     ├─ Payment: Online (Stripe) or Pay on pickup/delivery
     └─ Place order
  → Confirmation page with tracking link
  → Real-time tracking (WebSocket)
```

### 7.2 Authenticated Checkout (V1.1)

- Customer creates account (email + password or social login)
- Saved addresses, payment methods, order history
- Loyalty points display and redemption
- One-tap reorder from history

### 7.3 Cart Persistence

- Cart stored in `localStorage` (per subdomain = per tenant, no conflicts)
- Cart survives page refreshes and browser close
- Cart expires after 24 hours of inactivity
- Cart validates item availability on checkout (re-checks 86'd status)

---

## 8. QR Code Integration

### 8.1 QR Code Types & URLs

| Type | URL Pattern | Behavior |
|------|-------------|----------|
| Table QR | `/{slug}/qr/t/{signed_token}` | Opens menu with table context, dine-in mode |
| Counter QR | `/{slug}/qr/c/{signed_token}` | Opens menu in takeaway mode |
| Receipt QR | `/{slug}/track/{order_token}` | Opens order tracking page |
| Marketing QR | `/{slug}` | Opens storefront homepage |

### 8.2 Signed Token Format

```
token = base64url(
  HMAC-SHA256(
    key = company.storefront_secret,
    data = { table_id, location_id, type, expires_at }
  ) + '.' + base64url({ table_id, location_id, type, expires_at })
)
```

- Tokens are **long-lived** (table QR codes don't change daily)
- `expires_at` is optional — table QR codes can be permanent
- Token is verified server-side on first load, then session continues

### 8.3 Multi-Round QR Ordering

1. Customer scans table QR → opens menu → adds items → places order
2. Server creates order with `table_id` and `order_source='qr_code'`
3. Session cookie set: `qr_session={order_token}` (httpOnly, same-site)
4. Customer scans again (or refreshes) → session detected → "Add to existing order" mode
5. New items added via `POST /orders/{token}/add-items`
6. POS operator sees updated order on their terminal
7. When customer is done → operator closes order → receipt created

### 8.4 QR Code Generation (Back-Office)

The ERP admin panel provides:
- Bulk QR code generation (all tables for a floor)
- QR code download as PDF (print-ready, A6 table tents)
- Counter QR code for takeaway
- Marketing QR code with custom URL
- QR code design customization (logo in center, brand colors)

---

## 9. Multi-Location Menu Management

### 9.1 Menu Structure

```
Company (e.g., "Paul's Bakeries")
├── Location: Tunis Downtown
│   └── Online Menu (OnlineMenuItems)
│       ├── Category: Breads → [Baguette, Croissant, Pain au chocolat]
│       ├── Category: Pastries → [Tarte citron, Eclair]
│       └── Category: Drinks → [Espresso, Latte]
│
├── Location: Tunis La Marsa
│   └── Online Menu (cloned from Downtown, then customized)
│       ├── Category: Breads → [Baguette, Croissant] (removed Pain au chocolat)
│       ├── Category: Pastries → [Tarte citron, Eclair, Mille-feuille] (added item)
│       └── Category: Drinks → [Espresso, Latte, Jus d'orange] (added + price override)
│
└── Location: Sousse
    └── Online Menu (independent, different catalog)
```

### 9.2 Clone Workflow

1. Operator goes to Location B's storefront config
2. Clicks "Clone menu from..." → selects Location A
3. System copies all `OnlineMenuItem` records, linked to Location B's storefront
4. Operator edits: remove items, add items, change prices, reorder
5. Menus are now independent — changes to Location A don't propagate

### 9.3 Future: Central Menu Management (Franchise)

> Deferred — driven by first multi-location F&B client demand

- Central entity creates "Master Menus" (templates)
- Master Menu assigned to locations
- Locations can override: pricing, availability, position
- Locations cannot add/remove items from master (only toggle visibility)
- Central pushes master changes → propagate to all locations (respecting overrides)

---

## 10. WooCommerce Plugin Spec

### 10.1 Plugin Identity

- **Name**: AutoERP Connect for WooCommerce (or IziPOS Connect / Otospex Connect)
- **Slug**: `autoerp-connect`
- **Minimum requirements**: WordPress 6.0+, WooCommerce 8.0+, PHP 8.1+
- **Distribution**: Direct download from ERP admin panel (V1), WordPress.org (V2)

### 10.2 Settings Page

```
AutoERP Connect Settings
─────────────────────────
API Endpoint:    [https://api.autoerp.com          ] (auto-detected from region)
API Key:         [sk_live_xxxxxxxxxxxxxxxxxxxxx     ] (generated in ERP)
Company ID:      [uuid                              ] (from ERP)
Location ID:     [uuid                              ] (from ERP)

Sync Settings:
  Product Sync Interval:  [Every 15 minutes ▼]
  Auto-sync on save:      [✓] Push product changes immediately
  Image sync:             [✓] Download and attach product images

Order Settings:
  Push completed orders:  [✓]
  Push processing orders: [ ]  (only paid/completed by default)
  Order source label:     [woocommerce] (sent to ERP)

Status:
  Last product sync:      2026-03-15 14:32:00 (142 products synced)
  Last order pushed:      2026-03-15 14:28:00 (Order #1042)
  Connection:             ✅ Connected
  [Sync Now]  [Test Connection]
```

### 10.3 Data Mapping

```
ERP Product          →    WooCommerce Product
─────────────             ─────────────────────
name                 →    post_title
description          →    post_content (initial sync only — local edits preserved)
sale_price           →    _regular_price
online_price         →    _sale_price (if set)
sku                  →    _sku
images               →    Product gallery (downloaded to wp-content/uploads)
categories           →    Product categories (created if not mapped)
stock_quantity       →    _stock (if stock management enabled)
is_available         →    post_status (publish/draft)
modifiers            →    Product variations or custom fields
```

### 10.4 Order Push Payload

When a WooCommerce order completes, the plugin sends:

```json
{
  "external_reference": "woo_order_1042",
  "order_source": "woocommerce",
  "order_channel": "delivery",  // or "takeaway" based on shipping method
  "customer_name": "Ahmed Ben Ali",
  "customer_phone": "+216-98-XXX-XXX",
  "customer_email": "ahmed@example.com",
  "delivery_address": {
    "street": "12 Rue de la Liberte",
    "city": "Tunis",
    "postal_code": "1000",
    "country": "TN"
  },
  "items": [
    {
      "product_id": "erp-uuid-here",  // mapped during sync
      "name": "Margherita Pizza",
      "quantity": 2,
      "unit_price": "15.00",
      "modifiers": []
    }
  ],
  "subtotal": "30.00",
  "delivery_fee": "5.00",
  "total": "35.00",
  "currency": "TND",
  "payment_status": "paid",
  "payment_method": "credit_card",
  "notes": "Please ring doorbell twice"
}
```

### 10.5 Status Callback

The plugin registers a REST endpoint in WordPress:

```
POST /wp-json/autoerp/v1/order-status
Headers: X-Webhook-Signature: hmac_sha256(...)
Body: { "external_reference": "woo_order_1042", "status": "preparing" }
```

Plugin maps ERP statuses to WooCommerce:

| ERP Status | WooCommerce Status |
|------------|-------------------|
| received | wc-processing |
| accepted | wc-autoerp-accepted (custom) |
| preparing | wc-autoerp-preparing (custom) |
| ready | wc-autoerp-ready (custom) |
| dispatched | wc-autoerp-dispatched (custom) |
| completed | wc-completed |
| rejected | wc-cancelled (+ customer email with reason) |

---

## 11. Deployment & Infrastructure

### 11.1 Architecture

```
                    Cloudflare DNS
                    *.easyorders.tn → Cloudflare Pages
                    │
        ┌───────────▼────────────────┐
        │   Cloudflare Pages         │
        │   (Edge-deployed Astro)    │
        │                            │
        │   SSG pages served from    │
        │   edge cache (< 50ms)      │
        │                            │
        │   SSR pages (checkout,     │
        │   tracking) via Workers    │
        ├────────────────────────────┤
        │   Cloudflare KV            │
        │   - Tenant config cache    │
        │   - Menu data cache        │
        │   - Session data           │
        └───────────┬────────────────┘
                    │ API calls
                    ▼
        ┌────────────────────────────┐
        │   ERP API Server           │
        │   /api/storefront/*        │
        │   (Laravel, existing infra)│
        └────────────────────────────┘
```

### 11.2 Caching Strategy

| Data | Cache Location | TTL | Invalidation |
|------|---------------|-----|--------------|
| Tenant config | Cloudflare KV | 5 min | Webhook on config change |
| Menu (SSG pages) | Edge cache | Until rebuild | Deploy hook on catalog change |
| Availability | Cloudflare KV | 30 sec | Short TTL, near-real-time |
| Cart | Client localStorage | 24 hours | Client-side expiry |
| Order tracking | No cache | - | Real-time WebSocket |

### 11.3 Separate Codebases

```
repos/
├── storefront-izipos/       # Astro app for IziPOS vertical (F&B + retail)
│   ├── src/
│   ├── astro.config.ts
│   └── wrangler.toml        # Cloudflare Pages config
│
├── storefront-otospex/      # Astro app for Otospex vertical (automotive)
│   ├── src/
│   ├── astro.config.ts
│   └── wrangler.toml
│
├── woocommerce-plugin/      # WordPress/WooCommerce plugin
│   ├── autoerp-connect.php
│   ├── includes/
│   └── readme.txt
│
└── erp/                     # Existing ERP monorepo
    └── apps/api/app/Modules/Storefront/  # Storefront API module
```

---

## 12. Feature Gating (Add-On)

The storefront is a **gated add-on** — tenants must have it enabled to use any online ordering channel.

### 12.1 Gating Model

```php
// In StorefrontConfig
class StorefrontConfig extends Model {
    // is_addon_enabled: boolean (set by billing/admin)
    // enabled_channels: JSONB ['managed', 'api', 'woocommerce']
    // plan_tier: enum (basic, professional, enterprise)
}
```

### 12.2 Tier Structure (Placeholder — TBD)

| Capability | Basic | Professional | Enterprise |
|------------|-------|-------------|------------|
| Managed platform (subdomain) | 1 location | Multi-location | Multi-location |
| API access | - | ✓ | ✓ |
| WooCommerce plugin | - | ✓ | ✓ |
| Custom domain | - | - | ✓ |
| Marketplace integrations | - | 1 platform | Unlimited |
| QR code ordering | ✓ | ✓ | ✓ |
| Online payment processing | ✓ | ✓ | ✓ |
| Analytics | Basic | Advanced | Advanced + API |

### 12.3 Implementation

The `is_addon_enabled` flag is checked at:
1. **ERP admin panel**: Storefront config pages hidden if not enabled
2. **Storefront API**: Returns 403 if tenant's add-on is not active
3. **Managed platform**: Subdomain returns "Coming soon" / 404 if not active
4. **WooCommerce plugin**: Connection test fails with clear message
