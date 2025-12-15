
# Opening Balances & Initial State Import  
**Functional & Technical Specification**

## 0. Context & Goals

When onboarding a new customer mid-year, we must:

- Import **opening financial balances** (GL accounts, AR/AP, banks, equity).
- Import **initial inventory** (per product quantities and costs).
- Import **open customer and supplier items** (unpaid invoices, credit notes, etc.).
- Do this in a way that is:
  - **Compliant** (NF525 / KassenSichV / ZATCA-friendly).
  - **Traceable & immutable** (strong audit trail).
  - **Simple for users** (wizard, friendly UI, no “fake” sales/purchases).

**Key principle:**  
Opening balances and historical data are treated as **technical entries**, not as normal operational sales/purchase flows.

---

## 1. Core Concepts

### 1.1 Cut-over Date

- The **cut-over date** is the date on which the client switches from their old system to ours.
- Our system is **authoritative from the cut-over date forward**.
- Everything before that date is summarized as opening balances + imported historical open items.

### 1.2 Opening Balance Batch

We handle onboarding via **Opening Balance Batches**:

- A batch has:
  - A **type** (ACCOUNTING, INVENTORY, AR_OPEN_ITEMS, AP_OPEN_ITEMS, or COMBINED).
  - A **cut-over date**.
  - A **status** (DRAFT → VALIDATED → LOCKED).
  - Links to detailed records (GL journal lines, stock movements, AR/AP items).
  - A **hash/signature** and **locking timestamp** once finalized.

After a batch is LOCKED, **no edits or deletions** are allowed. Any correction must go through normal accounting adjustments.

### 1.3 Historical vs Operational

- **Historical / Imported** items:
  - Come from the old system.
  - Are marked as `is_historical = true`.
  - Do **not** use our fiscal invoice numbering sequences.
  - Do **not** generate e-invoices.
- **Operational** items:
  - Are created in our system after cut-over.
  - Use standard sequences (invoice numbers, stock moves, etc.).
  - Are subject to all normal fiscal rules.

---

## 2. Compliance & Audit Requirements

To be aligned with NF525 / KassenSichV / ZATCA-style standards, the system must:

1. **Separate technical entries from normal operations**
   - Opening balances must not masquerade as sales or purchases.
   - Use dedicated journal types and movement types.

2. **Lock and sign opening balance batches**
   - Once validated, change is only possible via **new corrective entries**, not edits.

3. **Maintain an audit trail**
   - Who imported (user/role), when, what file, which records.
   - Store:
     - Source system name.
     - Imported fields.
     - Mapping status.

4. **Preserve numbering integrity**
   - Historical invoices must not pollute our fiscal invoice sequence.
   - Use external IDs or prefixed IDs (`HIST-xxxxx`).

---

## 3. Data Model Design

### 3.1 High-Level Answer to “Extra Tables?”

We do **not strictly need** separate “opening_balance_*” tables for each domain.  

We can:

- Use **existing core tables** (GL journals, stock movements, AR/AP invoices) with:
  - A `source_type` / `origin_type` (e.g., `OPENING_BALANCE`, `HISTORICAL_IMPORT`).
  - A foreign key to `opening_balance_batches`.
  - Flags like `is_historical`, `is_opening`.

BUT…

To keep things clean and auditable, we **do want** one generic “header” table for batches:

- `opening_balance_batches`
- Optional: some staging/import tables for CSV uploads.

---

### 3.2 New Core Table: `opening_balance_batches`

**Purpose:** Represent one import event for a specific domain or combined domains.

```text
opening_balance_batches
- id (PK)
- tenant_id (FK tenants)
- type (ENUM: 'ACCOUNTING', 'INVENTORY', 'AR_OPEN_ITEMS', 'AP_OPEN_ITEMS', 'COMBINED')
- name (string)                // “Initial Opening - 2025-01-01”
- cutover_date (date)
- status (ENUM: 'DRAFT', 'VALIDATED', 'LOCKED')
- source_system (string)       // e.g., “Sage 100”, “Excel”
- import_file_reference (jsonb/string) // path/id to uploaded file(s)
- hash (string)                // hash chain for certification
- previous_hash (string)       // to chain batches
- locked_at (datetime, nullable)
- created_at, created_by
- validated_at, validated_by
- locked_by (nullable)
```

---

### 3.3 GL / Accounting Tables Integration

We assume core tables similar to:

```text
gl_journals
- id
- tenant_id
- code
- name
- journal_type (ENUM: 'GENERAL', 'SALE', 'PURCHASE', 'OPENING', 'ADJUSTMENT', ...)
- ...

gl_journal_entries
- id
- tenant_id
- journal_id (FK gl_journals)
- date
- description
- source_type (ENUM: 'MANUAL', 'DOCUMENT', 'OPENING_BATCH', ...)
- source_id (nullable)         // e.g., references opening_balance_batches.id
- is_historical (bool)
- created_at, created_by
- hash, previous_hash (optional, depending on signing model)

gl_journal_lines
- id
- entry_id (FK gl_journal_entries)
- account_id (FK accounts)
- partner_id (nullable, FK customers/suppliers)
- debit (decimal)
- credit (decimal)
- product_id (nullable)
- cost_center_id (nullable)
- notes (text)
```

**For opening balances:**

- Create a `gl_journals` row with `journal_type = 'OPENING'`.
- For each Accounting Opening Batch:
  - Create one or more `gl_journal_entries`:
    - `source_type = 'OPENING_BATCH'`
    - `source_id = opening_balance_batches.id`
    - `is_historical = true`  
  - Create `gl_journal_lines` for all accounts.

No extra accounting-specific tables needed; the **journal entry + link to batch** is enough.

---

### 3.4 Inventory (Stock) Integration

Assume core stock tables like:

```text
stock_movements
- id
- tenant_id
- product_id
- warehouse_id
- qty_change (decimal)
- movement_type (ENUM: 'SALE', 'PURCHASE', 'ADJUSTMENT', 'OPENING', 'TRANSFER', ...)
- source_type (ENUM: 'DOCUMENT', 'OPENING_BATCH', 'MANUAL_ADJUSTMENT', ...)
- source_id (nullable)               // opening_balance_batches.id
- unit_cost (decimal)
- total_cost (decimal)
- is_historical (bool)
- date
- created_at, created_by
- hash, previous_hash (optional)
```

For **initial inventory**:

- We do NOT create purchases.
- We create `stock_movements` records with:
  - `movement_type = 'OPENING'`
  - `source_type = 'OPENING_BATCH'`
  - `source_id = opening_balance_batches.id`
  - `is_historical = true`

The total valuation must reconcile with the Inventory GL account opening balance.

---

### 3.5 AR/AP Open Items Integration

Assume AR/AP tables:

```text
ar_invoices
- id
- tenant_id
- customer_id
- external_invoice_number (string)      // “F2025-001”, “12345” from old system
- internal_invoice_number (string, nullable) // null for historical
- issue_date
- due_date
- currency
- total_amount
- open_amount
- status (ENUM: 'OPEN', 'PAID', 'CANCELLED', 'WRITEOFF')
- is_historical (bool)
- source_type (ENUM: 'SYSTEM', 'OPENING_BATCH', ...)
- source_id (nullable)                 // opening_balance_batches.id
- created_at, created_by

ar_invoice_lines
- id
- invoice_id
- product_id (nullable)
- description
- qty
- unit_price
- tax_rate_id (nullable)
- line_total

ap_invoices    // similar structure for suppliers
ap_invoice_lines
```

For **historical open invoices**:

- Insert `ar_invoices` and `ap_invoices` rows with:
  - `is_historical = true`
  - `source_type = 'OPENING_BATCH'`
  - `source_id = batch.id`
  - `internal_invoice_number = NULL`
  - `external_invoice_number = the original number from the old system`
- Optionally:
  - `issue_date` and `due_date` = original dates (for ageing reports).
- GL entries:
  - Either:
    - You create GL journal entries during import to align AR/AP with GL opening balances (recommended).
    - Or assume GL opening balances already include them and just ensure totals match (simpler but requires reconciliation).

---

### 3.6 Optional Staging Tables (for Imports)

For ease of import & validation, you can add staging tables like:

```text
opening_balance_import_rows
- id
- batch_id
- row_type (ENUM: 'GL', 'INVENTORY', 'AR', 'AP')
- raw_data (jsonb)          // original CSV row mapped to JSON
- status (ENUM: 'PENDING', 'VALID', 'INVALID', 'SKIPPED')
- validation_errors (jsonb)
- mapped_entity_id (nullable) // id in final table once posted
```

These are **not required for compliance**, but very useful technically and for debugging.

---

## 4. Process Flows

### 4.1 Accounting Opening Balance Wizard

**Use Case:**  
Import GL balances at cut-over.

**Steps:**

1. **Start Wizard**
   - User selects:
     - Tenant / company
     - Cut-over date
     - Source system name
   - System creates `opening_balance_batches` row:
     - `type = 'ACCOUNTING'`
     - `status = 'DRAFT'`

2. **Upload File**
   - Supported format: CSV, XLSX, etc.
   - Columns: account code, account name, debit, credit, optional notes.

3. **Validation**
   - Map account code → internal account_id.
   - Check that total debit = total credit.
   - Check that accounts exist and are allowed for opening.
   - Store rows in `opening_balance_import_rows` with status.

4. **Preview & Edit**
   - Show aggregated trial balance.
   - Allow user to:
     - Fix mappings.
     - Exclude certain lines (if necessary).

5. **Post**
   - Generate a `gl_journals` entry if not existing (`type = 'OPENING'`).
   - Create a `gl_journal_entries` record linked to the batch.
   - Insert all `gl_journal_lines`.
   - Mark batch status `VALIDATED`.

6. **Lock & Sign**
   - When user confirms:
     - Calculate batch `hash` (e.g. hash of concatenated sorted journal line data + previous_hash).
     - Set `locked_at`, `locked_by`, `status = 'LOCKED'`.
     - Mark `gl_journal_entries.is_historical = true`.

7. **Post-Locking Rules**
   - No edits or deletions allowed on:
     - Batch
     - Related journal entries/lines
   - Corrections must be posted via normal adjustment journal (`journal_type = 'ADJUSTMENT'`, `source_type = 'MANUAL'`).

---

### 4.2 Inventory Opening Wizard

Similar steps, with differences in validation.

**Data in file:**  
- Product code, warehouse, quantity, cost price.

**Posting:**

- For each row:
  - Create a `stock_movements` entry:
    - `movement_type = 'OPENING'`
    - `source_type = 'OPENING_BATCH'`
    - `source_id = batch.id`
    - `is_historical = true`
    - `qty_change = quantity`
    - `unit_cost = cost`
    - `total_cost = qty * cost`
- Aggregate total inventory valuation and ensure it matches GL inventory opening balance (or alert user if not).

Locking and signing logic same as accounting.

---

### 4.3 AR/AP Open Items Wizard

**Data in file:**

- For customers:
  - Customer external ID/name
  - Invoice number (external)
  - Issue date
  - Due date
  - Currency
  - Total amount
  - Open amount
  - Optionally: tax breakdown, line details.

**Posting:**

1. Map customers & suppliers (create them if not existing, if allowed).
2. For each row:
   - Create `ar_invoices` / `ap_invoices` with:
     - `is_historical = true`
     - `internal_invoice_number = NULL`
     - `external_invoice_number = original invoice number`
     - `status = 'OPEN'` if `open_amount > 0`
3. Optionally:
   - Create GL entries or ensure they reconcile with the GL opening balance.

Lock and sign as other batches.

**Later use:**

- When the client receives a payment in our system against a historical invoice:
  - Payment is linked to `ar_invoices` entry, reducing `open_amount`.
  - Operational GL entries are posted (normal behaviour).
  - We maintain an audit trail linking back to the historical invoice.

---

## 5. UI/UX Requirements (High Level)

- **Opening Balances Section** in Admin / Setup.
  - Sub-tabs:
    - Accounting
    - Inventory
    - Customers (AR)
    - Suppliers (AP)

- For each module:
  - Stepper UI: “Select date & options → Upload → Validate → Preview → Post → Lock”
  - Clear warnings:
    - “Once locked, this batch cannot be edited or deleted.”
    - “These entries will not create any sales or purchases, only the opening state.”

- Visual indicators:
  - “Historical” badge on imported invoices.
  - “Opening” badge on related journal entries and stock movements.

---

## 6. Security & Permissions

- Roles:
  - `ROLE_ONBOARDING_ADMIN` (can create/lock opening batches).
  - `ROLE_ACCOUNTANT` (view and reconcile).
  - `ROLE_AUDITOR` (read-only access to opening batches and logs).

- Only high-privilege roles can:
  - Create/validate opening batches.
  - Lock batches.

- Once locked:
  - Only a super-auditor might have access to view hash values and download audit dumps.

---

## 7. Edge Cases & Special Rules

- **Multiple opening batches**
  - If a mistake was made, we create a **new** batch (e.g. `ACCOUNTING_CORRECTION` or `INVENTORY_CORRECTION`).
  - The original batch stays intact.
  - Reconciliation is done through normal GL adjustments.

- **Partial onboarding**
  - Some customers may choose:
    - Only inventory opening, no legacy invoices.
    - Or only AR/AP without detailed GL.

  The system must handle missing parts but clearly show which modules have an opening batch and which don’t.

- **Multi-warehouse**
  - Inventory opening must be per warehouse.

- **Multi-currency**
  - AR/AP historical invoices must keep their original currency.
  - GL opening balances should be in base currency.

---

## 8. Summary

**Required (recommended) new table:**

- `opening_balance_batches`  
  → One row per import batch, central anchor for audit & linking.

**Optional (very useful) tables:**

- `opening_balance_import_rows` (staging)  
  → Helps validate and debug imports, not required for compliance.

**Not required:**

- You do **NOT** need separate tables like `initial_customer_balances`, `initial_inventory_balances`, etc.  
- Instead, you use your **existing domain tables (GL, stock movements, AR/AP invoices)** with:
  - Flags (`is_historical`, `source_type`, etc.)
  - FK to `opening_balance_batches.id`

This keeps the model **simple**, **normalized**, and **compliance-friendly**, while avoiding duplicated “balance tables” everywhere.
