# Core Readiness Verification Checklist

---

## Project Context

This platform consists of **two products** built on a shared core:

| Product | Target Market | Examples |
|---------|---------------|----------|
| **Otospex** | Automotive businesses | Mechanics, body shops, car glass specialists, service stations, parts retailers |
| **IziPOS** | Generic retail & services | Pharmacies, coffee shops, restaurants, retail stores |

Both products share the same core modules (Identity, Catalog, Sales, Inventory, Treasury, Accounting, etc.). The difference is in optional modules and UI customization:
- **Otospex** adds: Vehicles module, Workshop module, TecDoc integration, vehicle-document linking
- **IziPOS** adds: Table management (F&B), prescription handling (Pharmacy), etc.

This verification ensures the **shared core** is solid before building product-specific features.

---

**Purpose:** Validate that all core modules and flows are functional before building Otospex and IziPOS applications on top. Ensures clean dependency direction (modules → core, never reverse).

**Date:** _______________  
**Tester:** _______________  
**Environment:** _______________

---

## Part 1: Architecture Verification

### 1.1 Dependency Direction Check

Before functional testing, verify no circular dependencies exist.

| Check | Method | Status |
|-------|--------|--------|
| Core has no imports from Otospex modules | `grep -r "Otospex\|AutoERP" app/Modules/` should return nothing | ☐ |
| Core has no imports from IziPOS modules | `grep -r "IziPOS" app/Modules/` should return nothing | ☐ |
| Vehicles module is optional/decoupled | Catalog works without vehicle context | ☐ |
| No hardcoded automotive references in core | Search for "VIN", "mileage", "TecDoc" in core modules | ☐ |

### 1.2 Multi-Tenancy & Multi-Company

| Check | Expected | Status |
|-------|----------|--------|
| Tenant isolation (schema-based) | Data from Tenant A invisible to Tenant B | ☐ |
| Multiple companies per tenant | Can create Company A and Company B under same tenant | ☐ |
| Company-scoped data | Products, customers, documents scoped to company | ☐ |
| Cross-company reporting (future) | Architecture supports consolidated views | ☐ |
| Location management | Warehouses/shops belong to specific company | ☐ |

---

## Part 2: Core Module Verification

### 2.1 Identity Module

| Feature | Test Action | Expected Result | Status |
|---------|-------------|-----------------|--------|
| User registration | Create new user | User created, welcome email sent | ☐ |
| Login | Login with credentials | Session created, redirected to dashboard | ☐ |
| Password reset | Request reset | Reset email sent, link works | ☐ |
| Role assignment | Assign "Sales" role to user | User has sales permissions | ☐ |
| Permission check | Access restricted page without permission | 403 Forbidden | ☐ |
| Multi-company access | User with access to 2 companies | CompanySelector shows both | ☐ |
| Company switching | Switch from Company A to B | Context changes, data refreshes | ☐ |

### 2.2 Company Module

| Feature | Test Action | Expected Result | Status |
|---------|-------------|-----------------|--------|
| Create company | New company form | Company created with default settings | ☐ |
| Company settings | Update fiscal year, currency | Settings saved | ☐ |
| Create location | Add warehouse to company | Location created, appears in selectors | ☐ |
| Location types | Create warehouse vs shop | Different types distinguished | ☐ |
| Default location | Set default warehouse | Used as default in stock operations | ☐ |
| Country assignment | Assign Tunisia to company | Country-specific settings applied | ☐ |

### 2.3 Catalog Module (Decoupled)

| Feature | Test Action | Expected Result | Status |
|---------|-------------|-----------------|--------|
| Create product | Add "Generic Widget" | Product created, no vehicle fields required | ☐ |
| Product categories | Create category hierarchy | Parent/child categories work | ☐ |
| Product variants | Add size/color variants | Variants linked to parent | ☐ |
| Pricing | Set purchase/sale price | Prices saved, margins calculated | ☐ |
| Service product | Create service (is_physical=false) | Marked as non-physical | ☐ |
| Product search | Search by name/SKU | Results returned quickly | ☐ |
| Barcode | Assign barcode to product | Barcode scannable/searchable | ☐ |
| **No vehicle dependency** | Create product without vehicle context | Works without any vehicle data | ☐ |

### 2.4 Inventory Module

| Feature | Test Action | Expected Result | Status |
|---------|-------------|-----------------|--------|
| View stock levels | Open inventory dashboard | Shows quantities per location | ☐ |
| Stock receipt | Receive 10 units of product | Stock level +10, movement recorded | ☐ |
| Stock adjustment | Adjust quantity (damage/loss) | Level updated, reason logged | ☐ |
| Stock transfer | Move 5 units Location A → B | A decreases, B increases | ☐ |
| **Reservation create** | Reserve 3 units for SO | Reserved qty shown, available reduced | ☐ |
| **Reservation release** | Cancel SO | Reservation released, available restored | ☐ |
| **Reservation convert** | Confirm delivery | Reservation → actual decrement | ☐ |
| Reservation expiry | Wait past expiry time | Auto-released (or job run) | ☐ |
| Insufficient stock | Try to reserve more than available | Error: insufficient stock | ☐ |
| Movement audit trail | View stock movements | All movements with reasons/references | ☐ |
| Weighted average cost | Receive at different costs | Average cost recalculated | ☐ |

### 2.5 Customer Module

| Feature | Test Action | Expected Result | Status |
|---------|-------------|-----------------|--------|
| Create customer | Add new customer | Customer created | ☐ |
| Customer types | Individual vs Company | Different fields shown | ☐ |
| Contact persons | Add contacts to company customer | Contacts linked | ☐ |
| Addresses | Add billing/shipping addresses | Multiple addresses supported | ☐ |
| Tax ID | Enter tax identification | Validated and saved | ☐ |
| Credit limit | Set credit limit | Limit enforced on orders | ☐ |
| Customer search | Search by name/phone/tax ID | Results returned | ☐ |
| Customer history | View past transactions | Orders, invoices listed | ☐ |

### 2.6 Sales Module — Documents

| Feature | Test Action | Expected Result | Status |
|---------|-------------|-----------------|--------|
| **Quote** | Create quote for customer | Quote saved as draft | ☐ |
| Quote → SO | Convert quote to sales order | SO created, quote linked | ☐ |
| **Sales Order** | Create SO with 3 line items | SO saved, stock reserved | ☐ |
| SO partial delivery | Deliver 2 of 3 items | Partial delivery note created | ☐ |
| **Delivery Note** | Create from SO | DN with correct items/quantities | ☐ |
| DN confirmation | Confirm delivery note | Status: Confirmed, stock decremented | ☐ |
| **Invoice** | Create from DN | Invoice with correct totals | ☐ |
| Invoice posting | Post invoice | Fiscal hash created, GL entries | ☐ |
| Service-only invoice | Invoice for services | Skips delivery note requirement | ☐ |
| Mixed invoice | Products + services | Filters correctly for delivery | ☐ |
| **Credit Note** | Create from invoice | Correct context, items populated | ☐ |
| CN posting | Post credit note | Stock incremented, COGS reversed | ☐ |
| Batch invoicing | Multiple DNs → one invoice | Consolidation works | ☐ |
| Document numbering | Create multiple invoices | Sequential numbers, no gaps | ☐ |

### 2.7 Treasury / Payments

| Feature | Test Action | Expected Result | Status |
|---------|-------------|-----------------|--------|
| Record payment | Payment against invoice | Payment recorded, invoice balance updated | ☐ |
| Partial payment | Pay 50% of invoice | Remaining balance shown | ☐ |
| Overpayment | Pay more than owed | Credit balance or refund option | ☐ |
| Payment methods | Cash, card, bank transfer | All methods work | ☐ |
| Payment → GL | Record payment | GL entries created (Bank ↔ AR) | ☐ |
| Customer balance | View customer statement | Shows all transactions, running balance | ☐ |
| Unallocated payments | Payment without invoice link | Sits as credit on account | ☐ |

### 2.8 Accounting / GL Module

| Feature | Test Action | Expected Result | Status |
|---------|-------------|-----------------|--------|
| Chart of accounts | View COA | Country-appropriate accounts shown | ☐ |
| Manual journal entry | Create JE | Entry saved, balanced | ☐ |
| Unbalanced entry | Try to save unbalanced | Error: must balance | ☐ |
| **Auto-posting invoice** | Post invoice | AR + Revenue entries auto-created | ☐ |
| **Auto-posting payment** | Record payment | Bank + AR entries auto-created | ☐ |
| **COGS on delivery** | Confirm delivery note | COGS + Inventory entries created | ☐ |
| **COGS reversal** | Post credit note | COGS reversed, inventory credited | ☐ |
| Trial balance | Generate trial balance | Debits = Credits | ☐ |
| Account ledger | View single account activity | All entries shown with running balance | ☐ |
| Period closing | Close month | Prevents backdated entries | ☐ |
| **Fiscal hash chain** | Post multiple documents | Each has hash linking to previous | ☐ |
| Hash verification | Run integrity check | Chain validates without breaks | ☐ |

### 2.9 Media Module

| Feature | Test Action | Expected Result | Status |
|---------|-------------|-----------------|--------|
| Image upload | Upload product image | Stored, thumbnail generated | ☐ |
| Multiple images | Add 3 images to product | All displayed, order maintained | ☐ |
| Document attachment | Attach PDF to invoice | Attachment accessible | ☐ |
| Image deletion | Remove image | Soft deleted, storage cleaned (or scheduled) | ☐ |

### 2.10 Communication Module

| Feature | Test Action | Expected Result | Status |
|---------|-------------|-----------------|--------|
| Email invoice | Send invoice to customer | Email delivered with PDF | ☐ |
| SMS notification | Send SMS (if configured) | SMS delivered | ☐ |
| Notification log | View sent communications | History displayed | ☐ |

---

## Part 3: End-to-End Flow Tests

### 3.1 Complete Sales Cycle (Happy Path)

**Scenario:** Sell 5 units of "Widget A" to "Customer X"

| Step | Action | Verification | Status |
|------|--------|--------------|--------|
| 1 | Check initial stock | Note starting quantity (e.g., 20) | ☐ |
| 2 | Create Sales Order | SO created, 5 units reserved | ☐ |
| 3 | Verify reservation | Available = 15, Reserved = 5 | ☐ |
| 4 | Create Delivery Note from SO | DN created with 5 units | ☐ |
| 5 | Confirm Delivery Note | Stock decremented: Total = 15 | ☐ |
| 6 | Verify COGS entry | GL shows COGS debit, Inventory credit | ☐ |
| 7 | Create Invoice from DN | Invoice with correct total | ☐ |
| 8 | Post Invoice | Fiscal hash created | ☐ |
| 9 | Verify AR entry | GL shows AR debit, Revenue credit | ☐ |
| 10 | Record Payment | Payment linked to invoice | ☐ |
| 11 | Verify payment GL | Bank debit, AR credit | ☐ |
| 12 | Check invoice status | Status: Paid | ☐ |
| 13 | Check customer balance | Balance = 0 | ☐ |

### 3.2 Sales Return Cycle

**Scenario:** Customer returns 2 of the 5 widgets sold above

| Step | Action | Verification | Status |
|------|--------|--------------|--------|
| 1 | Note current stock | Should be 15 | ☐ |
| 2 | Create Credit Note from Invoice | CN with 2 units, correct pricing | ☐ |
| 3 | Post Credit Note | Fiscal hash created | ☐ |
| 4 | Verify stock increment | Stock now 17 | ☐ |
| 5 | Verify COGS reversal | GL shows COGS credit, Inventory debit | ☐ |
| 6 | Verify AR reversal | AR credit, Revenue debit | ☐ |
| 7 | Check customer balance | Shows credit or reduced balance | ☐ |

### 3.3 Partial Delivery Flow

**Scenario:** SO for 10 units, deliver in 2 batches

| Step | Action | Verification | Status |
|------|--------|--------------|--------|
| 1 | Create SO for 10 units | 10 reserved | ☐ |
| 2 | Create DN for 6 units | Partial DN created | ☐ |
| 3 | Confirm DN | 6 decremented, 4 still reserved | ☐ |
| 4 | Check SO status | Status: Partially Delivered | ☐ |
| 5 | Create DN for remaining 4 | Second DN created | ☐ |
| 6 | Confirm second DN | All delivered, no reservations left | ☐ |
| 7 | Check SO status | Status: Fully Delivered | ☐ |

### 3.4 Service-Only Invoice Flow

**Scenario:** Invoice for consulting services (no physical goods)

| Step | Action | Verification | Status |
|------|--------|--------------|--------|
| 1 | Create service product | is_physical = false | ☐ |
| 2 | Create Invoice directly | No delivery note required | ☐ |
| 3 | Post Invoice | Posted successfully | ☐ |
| 4 | Verify no stock impact | Stock levels unchanged | ☐ |
| 5 | Verify GL entries | Revenue + AR only, no COGS | ☐ |

### 3.5 Insufficient Stock Handling

**Scenario:** Try to sell more than available

| Step | Action | Verification | Status |
|------|--------|--------------|--------|
| 1 | Set stock to 3 units | Available = 3 | ☐ |
| 2 | Create SO for 5 units | Error or warning shown | ☐ |
| 3 | **OR** Allow backorder | SO created with backorder flag | ☐ |
| 4 | Try to confirm DN for 5 | Error: insufficient stock | ☐ |

### 3.6 Multi-Company Isolation

**Scenario:** Verify data doesn't leak between companies

| Step | Action | Verification | Status |
|------|--------|--------------|--------|
| 1 | In Company A, create Product X | Product saved | ☐ |
| 2 | Switch to Company B | Context changes | ☐ |
| 3 | Search for Product X | Not found (correct) | ☐ |
| 4 | In Company B, create Customer Y | Customer saved | ☐ |
| 5 | Switch to Company A | Context changes | ☐ |
| 6 | Search for Customer Y | Not found (correct) | ☐ |

### 3.7 Fiscal Hash Chain Integrity

**Scenario:** Verify tamper-proof audit trail

| Step | Action | Verification | Status |
|------|--------|--------------|--------|
| 1 | Post Invoice 1 | Hash H1 created (links to genesis) | ☐ |
| 2 | Post Invoice 2 | Hash H2 created (links to H1) | ☐ |
| 3 | Post Invoice 3 | Hash H3 created (links to H2) | ☐ |
| 4 | Run hash verification | All hashes valid | ☐ |
| 5 | (Simulate tampering if possible) | Verification fails | ☐ |

---

## Part 4: UI/UX Verification

### 4.1 Navigation & Layout

| Check | Status |
|-------|--------|
| Sidebar navigation works for all modules | ☐ |
| Breadcrumbs show correct path | ☐ |
| CompanySelector visible and functional | ☐ |
| No duplicate buttons for same action | ☐ |
| Mobile responsive (if applicable) | ☐ |

### 4.2 Forms & Validation

| Check | Status |
|-------|--------|
| Required fields clearly marked | ☐ |
| Validation errors displayed inline | ☐ |
| Date pickers work correctly | ☐ |
| Dropdowns load options properly | ☐ |
| Search/autocomplete fields functional | ☐ |

### 4.3 Data Tables

| Check | Status |
|-------|--------|
| Pagination works | ☐ |
| Sorting works on columns | ☐ |
| Filtering/search works | ☐ |
| Row actions (edit, delete, view) work | ☐ |
| Bulk actions work (if applicable) | ☐ |

### 4.4 Known UI Issues to Fix

| Issue | Location | Priority |
|-------|----------|----------|
| Duplicate "Create Credit Note" button | Invoice page | High |
| _________________________ | _________ | _______ |
| _________________________ | _________ | _______ |
| _________________________ | _________ | _______ |

---

## Part 5: Performance & Error Handling

### 5.1 Performance Checks

| Check | Threshold | Actual | Status |
|-------|-----------|--------|--------|
| Dashboard load time | < 2s | _____ | ☐ |
| Product list (1000 items) | < 3s | _____ | ☐ |
| Invoice creation | < 1s | _____ | ☐ |
| Stock level query | < 500ms | _____ | ☐ |
| Report generation | < 5s | _____ | ☐ |

### 5.2 Error Handling

| Scenario | Expected Behavior | Status |
|----------|-------------------|--------|
| Network timeout | Friendly error message, retry option | ☐ |
| Validation failure | Specific field errors shown | ☐ |
| Server error (500) | Generic error, logged for debugging | ☐ |
| Concurrent edit conflict | Warning shown, option to refresh | ☐ |
| Session expiry | Redirect to login | ☐ |

---

## Part 6: Sign-Off

### Core Readiness Determination

| Criteria | Met? |
|----------|------|
| All Part 1 (Architecture) checks pass | ☐ |
| All Part 2 (Module) critical features pass | ☐ |
| All Part 3 (E2E) flows complete successfully | ☐ |
| No blocking UI issues remain | ☐ |
| Performance within acceptable thresholds | ☐ |

### Decision

☐ **READY** — Core is stable. Proceed with module development (Otospex/IziPOS).

☐ **CONDITIONAL** — Core mostly stable. List blockers below. Can proceed with modules that don't depend on blocking features.

☐ **NOT READY** — Critical gaps remain. List below and remediate before proceeding.

### Blocking Issues (if any)

| Issue | Severity | Module Impact | Owner |
|-------|----------|---------------|-------|
| | | | |
| | | | |
| | | | |

### Non-Blocking Issues (can fix in parallel)

| Issue | Severity | Target Date |
|-------|----------|-------------|
| | | |
| | | |
| | | |

---

## Appendix: Module Dependency Map

```
┌─────────────────────────────────────────────────────────┐
│                     APPLICATIONS                         │
│  ┌─────────────────┐         ┌─────────────────┐        │
│  │    Otospex      │         │     IziPOS      │        │
│  │  (Automotive)   │         │    (Retail)     │        │
│  └────────┬────────┘         └────────┬────────┘        │
└───────────┼───────────────────────────┼─────────────────┘
            │                           │
            │         DEPENDS ON        │
            ▼                           ▼
┌─────────────────────────────────────────────────────────┐
│                   OPTIONAL MODULES                       │
│  ┌──────────┐ ┌──────────┐ ┌──────────┐ ┌──────────┐   │
│  │ Vehicles │ │ Workshop │ │  F&B     │ │ Pharmacy │   │
│  └────┬─────┘ └────┬─────┘ └────┬─────┘ └────┬─────┘   │
└───────┼────────────┼────────────┼────────────┼──────────┘
        │            │            │            │
        │            │ DEPENDS ON │            │
        ▼            ▼            ▼            ▼
┌─────────────────────────────────────────────────────────┐
│                      CORE MODULES                        │
│  ┌──────────┐ ┌──────────┐ ┌──────────┐ ┌──────────┐   │
│  │ Identity │ │ Catalog  │ │  Sales   │ │ Treasury │   │
│  └──────────┘ └──────────┘ └──────────┘ └──────────┘   │
│  ┌──────────┐ ┌──────────┐ ┌──────────┐ ┌──────────┐   │
│  │ Company  │ │Inventory │ │Accounting│ │  Media   │   │
│  └──────────┘ └──────────┘ └──────────┘ └──────────┘   │
│  ┌──────────┐ ┌──────────┐                              │
│  │ Customer │ │  Comms   │  ← NO UPWARD DEPENDENCIES    │
│  └──────────┘ └──────────┘                              │
└─────────────────────────────────────────────────────────┘
```

**Rule:** Arrows only point DOWN. Core never imports from Optional Modules or Applications.

---

*Document Version: 1.0*  
*Created: December 2024*  
*For: Otospex/IziPOS Core Platform*
