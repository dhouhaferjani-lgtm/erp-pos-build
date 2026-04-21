# Otospex POS — Roadmap for Garages & Repair Shops

> **Status**: Planning
> **Target**: Automotive garages, repair shops, tire shops, spare parts retailers
> **First market**: Tunisia

---

## Table of Contents

1. [Current State Audit](#1-current-state-audit)
2. [How Automotive POS Differs](#2-how-automotive-pos-differs)
3. [What Exists (85% Infrastructure)](#3-what-exists-85-infrastructure)
4. [What's Missing (Gap Analysis)](#4-whats-missing-gap-analysis)
5. [POS Screen Design](#5-pos-screen-design)
6. [Workshop Module Spec](#6-workshop-module-spec)
7. [Automotive POS Workflow](#7-automotive-pos-workflow)
8. [Core Charges](#8-core-charges)
9. [Implementation Phases](#9-implementation-phases)
10. [Go-Live Checklist](#10-go-live-checklist)

---

## 1. Current State Audit

### Infrastructure Readiness

| Module | Status | Notes |
|--------|--------|-------|
| Vehicle Management | ✅ Complete | VIN, license plate, mileage, brand/model/year, fuel type, engine code |
| Automotive Product Metadata | ✅ Complete | Fitment, cross-references, tire specs, glass specs, article numbers |
| Cross-Reference Lookup | ✅ Complete | OEM, TecDoc, equivalent brand part number mapping |
| Vehicle Fitment Matrix | ✅ Complete | `automotive_product_vehicles` with year ranges, vehicle types |
| Service Catalog (Labor) | ✅ Complete | Flat-rate, hourly, percentage pricing types |
| Document Pipeline | ✅ Complete | Quote → Sales Order → Invoice → Credit Note (unified `documents` table) |
| Document Vehicle Context | ✅ Complete | Immutable vehicle snapshot per document + mileage at service |
| POS Receipts | ✅ Complete | Hash-chained, NF525-compliant, offline-capable |
| POS Payments | ✅ Complete | Cash, card, multi-payment, returns |
| Inventory | ✅ Complete | Stock levels, movements, reservations, counting, FIFO/weighted avg |
| Multi-Tier Pricing | ✅ Complete | Price lists, partner price lists, customer categories (B2B/B2C) |
| Partner/Customer CRM | ✅ Complete | B2B accounts, credit limits, payment terms, discount tiers |
| Parts Catalog UI (Web) | ✅ Complete | Vehicle search, part number search, tire size, VIN lookup modes |
| Tauri Desktop App | ✅ Complete | Offline-first, SQLite, ESC/POS printing, barcode scanner |
| Fiscal Compliance | ✅ Complete | Two-tier hash chains, Z-reports, NF525 JET export |
| Type Generation | ✅ Complete | `php artisan typescript:transform` auto-generates TS types from PHP DTOs |
| Workshop Module | ⚠️ Empty scaffold | Directory structure exists (`Workshop/`), no code inside |
| Work Order Document Type | ❌ Missing | `DocumentType` enum has no `WorkOrder` value |
| Labor Hour Logging | ❌ Missing | No time tracking for technician work |
| Technician Assignment | ❌ Missing | No mechanic-to-job mapping |
| Core Charge Tracking | ❌ Missing | No deposit/return lifecycle for remanufacturable parts |
| Automotive POS UI | ❌ Missing | No table-based, search-first POS layout for parts |

### Database Tables Already In Place

```
vehicles                              — License plate, VIN, brand/model/year, mileage, partner FK
automotive_product_metadata           — Article numbers, tire/glass specs, fitment, quality tier
automotive_product_vehicles           — Fitment matrix (product → vehicle type + year range)
automotive_product_cross_references   — OEM/TecDoc/equivalent part number cross-refs
document_vehicle_contexts             — Immutable vehicle snapshot per document
services                              — Labor catalog (flat-rate, hourly, percentage pricing)
service_categories                    — Service grouping
price_lists                           — Multi-tier pricing definitions
price_list_items                      — Product-level price overrides
partner_price_lists                   — Customer-specific pricing assignments
```

---

## 2. How Automotive POS Differs

### Fundamental Paradigm Shift

| Dimension | IziPOS (F&B / Retail) | Otospex (Auto Parts / Garage) |
|-----------|----------------------|-------------------------------|
| Layout | Visual product grid with images | **Table/list-based**, data-dense, no images |
| Primary input | Touch/tap product tiles | **Keyboard search** (part#, description, VIN) |
| SKU count | 50-200 (F&B), hundreds (retail) | **Tens of thousands** of part numbers |
| Customer context | Optional, anonymous OK | **Required** — account, vehicle, price tier |
| Sale type | Immediate checkout | **Quote → Work Order → Invoice** pipeline |
| Cart columns | Name, Qty, Price, Total | **Part#, Desc, Qty, List, Sell, Core, Total** |
| Pricing | Fixed price | **Multi-tier**: List, Retail, Trade, Custom per customer |
| Line types | Product only | **Parts + Labor + Core Returns + Sublet + Fees** |
| Vehicle context | Never | **Always** — every transaction tied to a vehicle |
| Search | Barcode / browse | Part#, cross-reference, VIN decode, fitment filter |

### Industry Standard Screen Layout

Auto parts POS systems (NAPA TRACS, AutoFluent, MAM Autopart, ROWriter) all use a **spreadsheet-style layout** — not a product grid. The key elements:

1. **Vehicle context bar** in the header (always visible)
2. **Full-width search bar** as the primary interaction point
3. **Split view**: search results (left) + invoice/work order (right)
4. **Dense table columns**: Part#, Description, Qty, List Price, Sell Price, Core Charge, Line Total
5. **Customer account** with auto-applied price tier

```
┌──────────────────────────────────────────────────────────────┐
│ HEADER                                                        │
│ [Customer: Garage ABC ▼]  [Vehicle: 2019 Peugeot 308 1.6 ▼] │
│ Account #1045 | Price: Trade (-25%) | Balance: 1,250.000 TND │
├──────────────────────────────────────────────────────────────┤
│ [🔍 Search: part#, description, OEM ref, cross-ref, VIN...] │
├──────────────────────────┬───────────────────────────────────┤
│ SEARCH RESULTS           │  WORK ORDER / INVOICE             │
│ (filterable table)       │  (always visible)                 │
│                          │                                    │
│ Part#    Desc     Stock  │ Type│Part# │Desc │Qty│Price │Tot  │
│ FP-2104  Brake..  8      │ Part│FP2104│Brake│ 2 │22.500│45.0 │
│ FP-2105  Brake..  0 (BO) │ Part│OL-531│Oil  │ 6 │ 4.250│25.5 │
│ FP-2106  Brake..  3      │ Labr│------│Brake│1.5│35/hr │52.5 │
│                          │ Core│FP2104│Core │ 2 │ 8.000│16.0 │
│                          │                                    │
│                          │ Parts:         70.500 TND         │
│                          │ Labor:         52.500 TND         │
│                          │ Core Charges:  16.000 TND         │
│                          │ Tax (19%):     23.370 TND         │
│                          │ TOTAL:        162.370 TND         │
└──────────────────────────┴───────────────────────────────────┘
```

---

## 3. What Exists (85% Infrastructure)

### Vehicle Module — Production Ready

```php
// Vehicle.php — already built
class Vehicle {
    string $license_plate;    // Primary identifier (unique per tenant)
    string $vin;              // 17-char VIN (unique per tenant)
    string $brand;            // Peugeot, Renault, VW, etc.
    string $model;            // 308, Clio, Golf, etc.
    int $year;
    string $color;
    string $fuel_type;        // diesel, petrol, electric, hybrid
    string $transmission;     // manual, automatic
    string $engine_code;      // For precise fitment
    int $mileage;             // Odometer reading
    string $partner_id;       // FK — which customer owns this vehicle
}
```

### Automotive Product Data — Production Ready

```php
// AutomotiveProductMetadata.php — already built
class AutomotiveProductMetadata {
    string $article_number;           // Supplier's part number
    string $supplier_brand;           // Bosch, Valeo, Brembo, etc.
    string $brand_quality_tier;       // OEM, aftermarket, economy
    // Tire fields
    float $tire_width, $tire_aspect_ratio, $tire_rim_diameter;
    string $tire_speed_rating, $tire_season;
    int $tire_load_index;
    // Glass fields
    string $glass_type, $glass_tinting;
    // Fitment
    Collection $vehicles;             // HasMany AutomotiveProductVehicle
    Collection $crossReferences;      // HasMany AutomotiveProductCrossReference
    // Data quality
    float $confidence_score;
    bool $is_universal_fit;
}
```

### Service Catalog — Production Ready

```php
// Service.php — already built
class Service {
    string $code, $name, $description;
    PricingType $pricing_type;        // flat_rate | hourly | percentage
    float $base_price;                // Fixed price or hourly rate
    int $default_duration_minutes;    // Estimated time
    float $hourly_rate;               // Explicit hourly rate
    float $tax_rate;
}
```

### Document Pipeline — Production Ready

The existing document module already supports the Quote → Invoice flow. Every document can link to a vehicle via `DocumentVehicleContext` with an immutable snapshot.

### Multi-Tier Pricing — Production Ready

```php
// PriceList system — already built
Partner → PartnerPriceList → PriceList → PriceListItem → Product
    └─ customer_category: individual | business
    └─ discount_percentage: global discount
    └─ credit_limit, payment_terms
```

---

## 4. What's Missing (Gap Analysis)

### P0 — MVP Blockers

| Gap | Description | Effort |
|-----|-------------|--------|
| **Workshop Module** | Work order entity, status machine, parts usage tracking, technician assignment | 40-60h |
| **Work Order Document Type** | Add `WorkOrder` to `DocumentType` enum + specific workflow | 8h |
| **Vehicle Selection in POS** | Vehicle picker in receipt/work order creation, VIN quick-lookup | 10h |
| **Service Selection in POS** | Display labor services in POS, add to invoice with hours | 12h |
| **Automotive POS Layout** | Table-based, search-first UI (separate from IziPOS grid) | 30h |
| **Labor Hour Logging** | Track estimated vs actual hours per technician per job | 20h |
| **Work Order → Invoice Conversion** | One-click conversion with cost reconciliation | 15h |
| **Garage Receipt Template** | Print: vehicle info, work performed, parts list, labor breakdown, mileage | 10h |

### P1 — Shortly After MVP

| Gap | Description | Effort |
|-----|-------------|--------|
| **Core Charge Tracking** | Deposit on sale, credit on return, vendor return batches | 25h |
| **Technician Dashboard** | View assigned jobs, log time, update status | 25h |
| **Customer Vehicle History** | All vehicles + full service history per customer | 15h |
| **Parts Bin Locations** | Track warehouse bin per product, show on search results | 15h |
| **Appointment Scheduling** | Book service slots, calendar view, reminders | 30h |
| **Labor Pricing Override** | Manual hourly rate adjustment per job (warranty, goodwill) | 8h |
| **Z-Report Labor/Parts Split** | Breakdown revenue by parts vs labor vs core in Z-reports | 10h |

### P2 — Growth Features

| Gap | Description | Effort |
|-----|-------------|--------|
| **TecDoc Integration** | Live catalog search via TecDoc/TecAlliance API | 40h |
| **VIN Decoder Service** | Decode VIN → auto-fill vehicle fields | 15h |
| **Warranty Tracking** | Per-part, per-service warranty periods with alerts | 25h |
| **Special Orders / Backorders** | Order non-stock parts, alert on arrival | 20h |
| **Customer Reminders** | SMS/email for service due dates, seasonal maintenance | 20h |
| **Multi-Location Stock Transfers** | Transfer parts between garage locations | 20h |
| **Technician Productivity Analytics** | Hours billed vs hours worked, efficiency metrics | 20h |
| **Parts Margin Analysis** | Margin by brand, category, customer tier | 15h |
| **Mobile Technician App** | Tablet/phone app for mechanics on the floor | 60h |

---

## 5. POS Screen Design

### Otospex POS vs IziPOS — Two Different Apps

The Otospex POS should be a **separate UI mode** (detected via company vertical or module configuration), not a feature toggle on the IziPOS grid. The interaction model is fundamentally different.

### Web POS Layout (Otospex)

```
┌─────────────────────────────────────────────────────────────────┐
│ HEADER BAR                                                       │
│ ┌──────────────────┐ ┌──────────────────────────┐ ┌───────────┐│
│ │👤 Customer        │ │🚗 Vehicle                 │ │ Shift #3  ││
│ │ Garage ABC        │ │ 123-TUN-4567              │ │ Ahmed B.  ││
│ │ Trade (-25%)      │ │ 2019 Peugeot 308 1.6 HDi  │ │ ⚙ 🔒 ⏻  ││
│ │ Bal: 1,250.000    │ │ 85,420 km                  │ │           ││
│ └──────────────────┘ └──────────────────────────┘ └───────────┘│
├─────────────────────────────────────────────────────────────────┤
│ ┌─────────────────────────────────────────────────────────────┐ │
│ │🔍 Search by part#, description, OEM ref, cross-ref...      │ │
│ │   [Parts] [Services] [History]                    [VIN ⎘]  │ │
│ └─────────────────────────────────────────────────────────────┘ │
├───────────────────────────┬─────────────────────────────────────┤
│                           │                                      │
│  SEARCH RESULTS           │  CURRENT DOCUMENT                    │
│  ┌───────────────────┐    │  ┌────────────────────────────────┐  │
│  │ Tab: [All] [Fit✓]  │    │  │ Mode: [Quote] [Work Order]     │  │
│  │                     │    │  │       [Invoice] [Quick Sale]   │  │
│  │ Part#   Desc   Stk │    │  ├────────────────────────────────┤  │
│  │ FP2104  Brak..  8  │    │  │Type│Part# │Desc  │Qty│ Price│T│  │
│  │ FP2105  Brak..  0  │    │  │────│──────│──────│───│──────│─│  │
│  │ FP2106  Brak..  3  │    │  │Part│FP2104│Brake │ 2 │22.500│45│  │
│  │                     │    │  │Part│OL531 │Oil F │ 6 │ 4.250│26│  │
│  │ ─── Cross-refs ─── │    │  │Labr│      │Brake │1.5│35/hr │53│  │
│  │ OEM: 4251.68       │    │  │Core│FP2104│Core  │ 2 │ 8.000│16│  │
│  │ Bosch: 0986AB1234  │    │  │                                │  │
│  │ Valeo: 301234      │    │  │ Subtotal Parts:   70.500 TND  │  │
│  │                     │    │  │ Subtotal Labor:   52.500 TND  │  │
│  └───────────────────┘    │  │ Core Charges:      16.000 TND  │  │
│                           │  │ Tax (19%):          23.370 TND  │  │
│  VEHICLE FITMENT          │  │ ──────────────────────────────  │  │
│  ✅ Fits: Peugeot 308     │  │ TOTAL:            162.370 TND  │  │
│     2014-2021 1.6 HDi    │  │                                  │  │
│  ⚠️ Also fits: 3008, 508  │  │ [Hold] [Print Quote] [Pay ▶]   │  │
│                           │  └────────────────────────────────┘  │
└───────────────────────────┴─────────────────────────────────────┘
```

### Key UI Differences from IziPOS

| Element | IziPOS | Otospex |
|---------|--------|---------|
| Product display | Image grid (2-5 columns) | Table rows with part# prominent |
| Search bar | Optional, above grid | **Primary**, full-width, always focused |
| Cart | Right sidebar | Right panel with expanded columns |
| Customer | Optional quick-add | **Required** — must select before adding parts |
| Vehicle | N/A | **Required header element** |
| Line types | Product only | Parts, Labor, Core Return, Sublet, Fee |
| Document mode | Always "receipt" | Toggle: Quote / Work Order / Invoice / Quick Sale |
| Cross-references | N/A | Shown under search results |
| Fitment indicator | N/A | Green check / warning per part |
| Price columns | Single price | List Price + Sell Price (customer tier applied) |

### Desktop (Tauri) POS Layout

Same layout as web, adapted for:
- Keyboard-optimized (F-keys for actions: F2=Search, F5=Customer, F8=Pay, F12=Print)
- Barcode scanner integration (scan part barcode → auto-search)
- Offline mode (cached product catalog in SQLite, queue work orders for sync)
- Receipt printing (ESC/POS with vehicle info, labor breakdown, core charges)

---

## 6. Workshop Module Spec

### 6.1 Work Order Entity

```php
// Domain/Entities/WorkOrder.php
class WorkOrder {
    string $id;                         // UUID
    string $tenant_id, $company_id, $location_id;
    string $work_order_number;          // Sequential: WO-2026-00001
    WorkOrderStatus $status;            // received → approved → in_progress → completed → invoiced → cancelled
    WorkOrderType $type;                // repair, maintenance, inspection, bodywork, tire_service

    // Customer & Vehicle
    string $partner_id;                 // FK — customer
    string $vehicle_id;                 // FK — vehicle being serviced
    int $mileage_at_intake;             // Odometer at drop-off

    // Linked Documents
    ?string $quote_id;                  // FK — original quote (nullable)
    ?string $invoice_id;               // FK — generated invoice (nullable)

    // Scheduling
    ?Carbon $scheduled_date;            // Appointment date
    ?Carbon $promised_date;             // Promised completion date
    ?Carbon $started_at;                // Actual start
    ?Carbon $completed_at;              // Actual completion

    // Financials (calculated from lines)
    string $estimated_parts_total;
    string $estimated_labor_total;
    string $estimated_total;
    string $actual_parts_total;
    string $actual_labor_total;
    string $actual_total;

    // Notes
    string $customer_complaint;         // Customer's description of the issue
    string $diagnosis;                  // Technician's diagnosis
    string $internal_notes;             // Internal-only notes

    // Relations
    Collection $partLines;              // HasMany WorkOrderPartLine
    Collection $laborLines;             // HasMany WorkOrderLaborLine
    Collection $assignments;            // HasMany TechnicianAssignment
}
```

### 6.2 Work Order Status Machine

> **Canonical reference:** The state machine below is a pre-PR-8 planning
> draft and is **superseded** by the shipped implementation. For the
> authoritative list of states, transitions, events, and HTTP error
> envelopes, see [`docs/modules/workshop-work-orders.md`](../modules/workshop-work-orders.md).
> Notably, the shipped enum also includes `Diagnosed`, `Paused`, and
> `WaitingParts` states that are not shown in the diagram below.

```
                                ┌────────────┐
         Customer drops off ──▶ │  Received  │
                                └─────┬──────┘
                                      │ diagnose + quote
                                ┌─────▼──────┐
                                │  Quoted    │ ──── customer declines ──▶ Cancelled
                                └─────┬──────┘
                                      │ customer approves
                                ┌─────▼──────┐
                                │  Approved  │
                                └─────┬──────┘
                                      │ technician starts
                                ┌─────▼──────┐
                                │ In Progress│ ◄─── can add parts/labor during work
                                └─────┬──────┘
                                      │ work done
                                ┌─────▼──────┐
                                │ Completed  │
                                └─────┬──────┘
                                      │ generate invoice
                                ┌─────▼──────┐
                                │  Invoiced  │ ──── customer pays ──▶ Closed
                                └────────────┘
```

```php
enum WorkOrderStatus: string {
    case Received = 'received';
    case Quoted = 'quoted';
    case Approved = 'approved';
    case InProgress = 'in_progress';
    case Completed = 'completed';
    case Invoiced = 'invoiced';
    case Closed = 'closed';
    case Cancelled = 'cancelled';
}

enum WorkOrderType: string {
    case Repair = 'repair';
    case Maintenance = 'maintenance';
    case Inspection = 'inspection';
    case Bodywork = 'bodywork';
    case TireService = 'tire_service';
    case Electrical = 'electrical';
    case Diagnostic = 'diagnostic';
}
```

### 6.3 Work Order Lines

```php
// Parts used on a work order
class WorkOrderPartLine {
    string $work_order_id;
    string $product_id;               // FK — automotive part
    string $product_code;             // Snapshot: part number
    string $product_name;             // Snapshot: description
    float $quantity;
    float $unit_price;                // Price at customer's tier
    float $list_price;                // For reference
    float $line_total;
    float $core_charge;               // Per-unit core deposit (if applicable)
    float $core_total;                // quantity × core_charge
    ?string $bin_location;            // Where the part was picked from
    LineSource $source;               // from_stock, special_order, customer_supplied
}

// Labor performed on a work order
class WorkOrderLaborLine {
    string $work_order_id;
    string $service_id;               // FK — service catalog
    string $service_name;             // Snapshot
    PricingType $pricing_type;        // flat_rate | hourly
    float $estimated_hours;
    float $actual_hours;              // Logged by technician
    float $hourly_rate;
    float $flat_rate_price;           // If flat-rate
    float $line_total;                // Calculated: hours × rate or flat price
    ?string $technician_id;           // FK — who performed the work
    ?string $technician_name;         // Snapshot
}
```

### 6.4 Technician Assignment

```php
class TechnicianAssignment {
    string $work_order_id;
    string $technician_id;            // FK — User with 'technician' role
    string $technician_name;          // Snapshot
    bool $is_lead;                    // Primary technician on this job
    ?Carbon $started_at;
    ?Carbon $completed_at;
    float $hours_logged;              // Total hours this technician worked
}
```

### 6.5 Domain Events

```php
WorkOrderCreated           // Triggers notification to workshop manager
WorkOrderQuoted            // Triggers customer notification (SMS/email)
WorkOrderApproved          // Triggers parts reservation + technician assignment
WorkOrderStarted           // Starts labor time tracking
WorkOrderCompleted         // Triggers quality check + invoice preparation
WorkOrderInvoiced          // Links to document, triggers payment flow
WorkOrderCancelled         // Releases reserved parts, notifies customer
```

---

## 7. Automotive POS Workflow

### 7.1 Quick Sale (Counter Parts Retail)

For walk-in customers buying parts over the counter — closest to retail POS:

```
Customer walks in → Select/create customer → Select vehicle (optional)
  → Search parts → Add to cart → Apply customer pricing → Pay → Print receipt
```

This uses the existing POS receipt flow with automotive extensions:
- Vehicle context (optional for quick sale, required for service)
- Part number displayed on receipt
- Core charges as separate line items
- Customer's price tier auto-applied

### 7.2 Full Garage Workflow

```
1. INTAKE: Customer drops off vehicle
   → Create Work Order (vehicle, mileage, customer complaint)
   → Status: Received

2. DIAGNOSE: Technician inspects vehicle
   → Add diagnosis notes
   → Add estimated parts + labor lines
   → Status: Quoted
   → Print/send quote to customer

3. APPROVE: Customer approves quote
   → Status: Approved
   → Parts reserved from inventory
   → Technician assigned

4. WORK: Technician performs repairs
   → Status: In Progress
   → Log actual hours (start/stop timer or manual entry)
   → Add/remove parts as needed (actual may differ from estimate)
   → Mark individual lines as complete

5. COMPLETE: Work finished
   → Status: Completed
   → Review actual vs estimated costs
   → Quality check (optional)

6. INVOICE: Generate invoice from work order
   → One-click conversion: work order lines → document lines
   → Add core charges for remanufactured parts
   → Status: Invoiced

7. PAY: Customer pays and picks up vehicle
   → Process payment (cash/card/account credit)
   → Print receipt with full breakdown
   → Update vehicle mileage
   → Status: Closed
```

### 7.3 Line Types on Invoice

| Type | Example | Pricing | Core? |
|------|---------|---------|-------|
| **Part** | Brake pads FP-2104 | Customer tier price | Possible |
| **Labor** | Brake pad replacement | Flat-rate or hourly | No |
| **Core Charge** | Core deposit FP-2104 | Fixed per part | N/A (is core) |
| **Core Return** | Core credit FP-2104 | Negative amount (credit) | N/A |
| **Sublet** | Windshield calibration (outsourced) | Pass-through cost + markup | No |
| **Environmental Fee** | Oil disposal fee | Fixed | No |
| **Misc Fee** | Diagnostic scan fee | Fixed | No |

```php
enum InvoiceLineType: string {
    case Part = 'part';
    case Labor = 'labor';
    case CoreCharge = 'core_charge';
    case CoreReturn = 'core_return';
    case Sublet = 'sublet';
    case EnvironmentalFee = 'environmental_fee';
    case MiscFee = 'misc_fee';
}
```

---

## 8. Core Charges

### What They Are

Core charges are refundable deposits on remanufacturable parts (alternators, starters, brake calipers, water pumps, turbochargers). The customer pays the core charge when buying the new part and gets a credit when returning the old one.

### Lifecycle

```
SALE: Customer buys remanufactured alternator
  → Part price: 189.000 TND
  → Core charge: 45.000 TND
  → Invoice shows both as separate lines

RETURN: Customer returns old alternator within 30 days
  → Core credit: -45.000 TND (credit note or applied to balance)
  → Old alternator added to "core return" inventory

VENDOR: Garage accumulates old cores, returns to supplier
  → Batch core return to supplier
  → Supplier credits garage account
```

### Data Model

```php
class CoreCharge {
    string $product_id;               // Which product has a core charge
    float $core_amount;               // Deposit amount per unit
    int $return_period_days;          // 30 days default
    bool $is_active;
}

class CoreDeposit {
    string $receipt_line_id;          // Which sale line created this deposit
    string $partner_id;               // Customer who owes a core
    string $product_id;               // Which part
    float $amount;                    // Core charge amount
    CoreDepositStatus $status;        // outstanding, returned, expired, credited
    ?Carbon $returned_at;
    ?string $credit_note_id;          // FK to credit note when credited
}
```

### POS Behavior

- When a product with a core charge is added to cart, the core charge line is **auto-added** below it
- Core charges appear as separate line items with type `core_charge`
- On the receipt, core charges have their own subtotal line
- Core returns are processed via a dedicated "Core Return" flow (similar to product returns)

---

## 9. Implementation Phases

### Phase 0 — Prerequisites (1 week)

> Prepare the foundation before building automotive-specific features.

- [ ] Add `WorkOrder` to `DocumentType` enum (or create separate entity — TBD)
- [ ] Add `InvoiceLineType` enum (Part, Labor, CoreCharge, CoreReturn, Sublet, Fee)
- [ ] Add `line_type` column to `document_lines` and `pos_receipt_lines`
- [ ] Add `core_charge` column to `products` table (nullable decimal, for parts with cores)
- [ ] Add `vehicle_id` column to `pos_receipts` and `pos_orders` (nullable FK)
- [ ] Verify Vehicle module API endpoints are complete and tested
- [ ] Verify Service module API endpoints are complete and tested

### Phase 1 — Workshop Module MVP (3 weeks)

> Core work order lifecycle for garages.

**Week 1: Domain & API**
- [ ] Create `WorkOrder` entity with status machine
- [ ] Create `WorkOrderPartLine` and `WorkOrderLaborLine` entities
- [ ] Create `TechnicianAssignment` entity
- [ ] Create `WorkOrderService` (create, update status, add lines, assign technician)
- [ ] Create API routes + controllers (CRUD + status transitions)
- [ ] Create domain events (WorkOrderCreated, Approved, Completed, etc.)
- [ ] Database migrations for all workshop tables
- [ ] Permissions: `workshop.view`, `workshop.create`, `workshop.approve`, `workshop.invoice`

**Week 2: Web UI**
- [ ] Work Order list page (filterable by status, vehicle, technician, date)
- [ ] Work Order detail page (vehicle info, lines, status actions, notes)
- [ ] Work Order create form (select customer → select vehicle → add complaint)
- [ ] Add parts to work order (search by part#, show fitment, add line)
- [ ] Add labor to work order (select service, set hours, assign technician)
- [ ] Status transition buttons (Approve, Start, Complete, Invoice)
- [ ] Work Order → Invoice conversion (one-click with confirmation)

**Week 3: Integration & Testing**
- [ ] Work Order → Document conversion service (maps WO lines → document lines)
- [ ] Parts reservation on approval (reserve stock, release on cancel)
- [ ] Vehicle mileage update on completion
- [ ] Print work order / job card (for workshop wall)
- [ ] Feature tests for full lifecycle (create → approve → complete → invoice → pay)
- [ ] Seeder with sample work orders for demo

### Phase 2 — Automotive POS Layout (2 weeks)

> The search-first, table-based POS screen for Otospex.

**Week 4: Web POS**
- [ ] New POS layout component (table-based, split view)
- [ ] Vehicle context bar in header (customer + vehicle selector)
- [ ] Full-width search with modes: Parts, Services, History
- [ ] Search results table (Part#, Description, Stock, List Price, Sell Price, Fitment)
- [ ] Invoice panel with expanded columns (Part#, Desc, Qty, List, Sell, Core, Total)
- [ ] Line type indicators (Part/Labor/Core/Fee with icons)
- [ ] Cross-reference display under search results
- [ ] Fitment indicator (green check if fits selected vehicle)
- [ ] Document mode toggle (Quick Sale / Quote / Work Order / Invoice)
- [ ] Customer pricing auto-applied from partner price list

**Week 5: Desktop POS**
- [ ] Adapt Tauri POS for automotive layout (separate from IziPOS mode)
- [ ] Keyboard shortcuts (F2=Search, F5=Customer, F7=Vehicle, F8=Pay, F12=Print)
- [ ] Offline vehicle cache in SQLite
- [ ] Offline service catalog cache
- [ ] Garage-specific receipt template (vehicle info, labor breakdown, mileage, core charges)

### Phase 3 — Core Charges & Labor Tracking (2 weeks)

**Week 6: Core Charges**
- [ ] `CoreCharge` model on products (amount, return period)
- [ ] Auto-add core charge line when adding a product with core
- [ ] Core return flow (scan/search old part → issue credit)
- [ ] Core deposit tracking per customer (outstanding, returned, expired)
- [ ] Core deposits report (outstanding cores by customer)

**Week 7: Labor Time Tracking**
- [ ] Technician dashboard page (assigned jobs, status)
- [ ] Start/stop timer for labor (or manual hour entry)
- [ ] Actual vs estimated hours comparison on work order
- [ ] Technician assignment from work order detail page
- [ ] Labor hours reflected in invoice generation

### Phase 4 — Customer & Vehicle History (1 week)

- [ ] Customer detail page: list of vehicles owned
- [ ] Vehicle detail page: full service history (all work orders + invoices)
- [ ] "Last serviced" indicator on vehicle selector
- [ ] Mileage tracking over time (chart)
- [ ] Quick reorder: "Same as last service" button

### Phase 5 — Advanced Features (Ongoing)

- [ ] TecDoc API integration (live catalog search)
- [ ] VIN decoder service (auto-fill vehicle fields)
- [ ] Appointment scheduling (calendar, SMS reminders)
- [ ] Warranty tracking (per part, per service, expiry alerts)
- [ ] Special orders / backorder management
- [ ] Bin location tracking (warehouse management lite)
- [ ] Technician productivity analytics (hours billed vs worked, efficiency)
- [ ] Parts margin analysis (by brand, category, customer tier)
- [ ] Multi-location stock transfers between garage branches

---

## 10. Go-Live Checklist

### Minimum Viable Garage POS

- [ ] Work order lifecycle complete (create → approve → work → complete → invoice → pay)
- [ ] Vehicle linked to every work order and invoice
- [ ] Service catalog populated (common labor items: oil change, brake service, etc.)
- [ ] Customer pricing tiers configured (retail, trade, VIP)
- [ ] POS shows: customer name, vehicle, mileage, parts + labor breakdown
- [ ] Receipt prints: vehicle details, work performed, parts list, labor hours, totals
- [ ] Parts inventory decrements correctly on invoice
- [ ] Z-Report shows revenue split (parts vs labor)
- [ ] Offline sync works (Tauri desktop) for quick sales
- [ ] Barcode scanner works for part lookup
- [ ] At least one end-to-end test of full workflow

### Nice-to-Have for Launch

- [ ] Core charge tracking
- [ ] Cross-reference search
- [ ] Fitment verification indicator
- [ ] Technician time logging
- [ ] Customer vehicle history view
- [ ] Print job card for workshop wall

### Operational Setup (Per Garage)

- [ ] Create company with Otospex vertical
- [ ] Configure payment methods (cash, card, bank transfer for B2B)
- [ ] Import product catalog (parts with article numbers, prices)
- [ ] Create service catalog (labor items with rates)
- [ ] Set up price lists (retail, trade, custom per-customer)
- [ ] Configure receipt printer (80mm ESC/POS)
- [ ] Create terminal + activate
- [ ] Create operator PINs for counter staff
- [ ] Train operators on workflow
