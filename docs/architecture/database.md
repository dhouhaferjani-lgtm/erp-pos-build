# Database Schema

> Complete database schema documentation for AutoERP.

---

## Overview

- **Database**: PostgreSQL 16+
- **Multi-Tenancy**: **Database-per-tenant** (Stancl `PostgreSQLDatabaseManager`). One central DB + one physical DB per tenant. Flipped 2026-05-28 (T6 Phase 0b, PRs #141–#146 + #148).
- **Total Tables**: 85+ (counts below reflect post-flip state)
- **Migrations**: `apps/api/database/migrations/` (central) + `apps/api/database/migrations/tenant/` (per-tenant)

---

## Database Organization

```
synerivia_central       # Tenant directory + auth + backup metadata
tenant_<tenant-uuid>    # One physical database per tenant
                        # (created by Stancl CreateDatabase job on signup)
```

### Central DB (`synerivia_central`) tables
The Laravel `central` connection is pinned to this database and never swapped. Holds:

- `tenants` — tenant directory (uuid, slug, name, status, plan, etc.)
- `domains` — custom domains per tenant
- `central_identities` — email → tenant pointer index (T6 Phase 0a)
- `tenant_subscriptions` — billing rows
- `tenant_backups` — per-tenant backup attempt metadata (PR #148: status, file_path, sha256, started_at, completed_at)
- `super_admins` — platform administrators
- `personal_access_tokens` — Sanctum tokens (`CentralPersonalAccessToken` pins `$connection='central'` so `auth:sanctum` lookups work after the per-request DB swap)
- `plans` — subscription plans
- `countries` — country master data
- `country_tax_rates` — tax configurations
- `country_payment_settings` — payment rules

### Per-Tenant DB (`tenant_<uuid>`) tables
The Laravel default connection is swapped per-request to the tenant's database by `DatabaseTenancyBootstrapper`. Holds every tenant-scoped table (users, products, documents, stock_*, fiscal events, etc.). Many still carry a `tenant_id` column for defense-in-depth (`WHERE tenant_id = ?` scoping at every callsite from the pre-flip tenant-isolation sweep).

### Migration paths

- `apps/api/database/migrations/` — central migrations. Run via `php artisan migrate`.
- `apps/api/database/migrations/tenant/` — tenant migrations. Run per tenant DB via `tenants:migrate`; Stancl `MigrateDatabase` runs them during signup; `tenant:migrate-rolling` walks every tenant for ongoing migrations.

---

## Table Reference by Domain

### 1. Identity & Authentication

#### users
```sql
id UUID PRIMARY KEY
tenant_id UUID FK → tenants(id) CASCADE
name VARCHAR(255)
email VARCHAR(255)
phone VARCHAR(50) NULLABLE
password VARCHAR(255)
status ENUM('pending_verification', 'active', 'suspended')
locale VARCHAR(10)
timezone VARCHAR(50)
preferences JSONB DEFAULT '{}'
email_verified_at TIMESTAMP NULLABLE
last_login_at TIMESTAMP NULLABLE
last_login_ip VARCHAR(45) NULLABLE
remember_token VARCHAR(100) NULLABLE
created_at, updated_at TIMESTAMP

UNIQUE (tenant_id, email)
INDEX (tenant_id), (status), (email)
```

#### devices
```sql
id UUID PRIMARY KEY
user_id UUID FK → users(id) CASCADE
name VARCHAR(255)
type ENUM('mobile', 'desktop', 'tablet', 'pos')
device_id VARCHAR(255) NULLABLE
push_token TEXT NULLABLE
platform ENUM('ios', 'android', 'windows', 'macos', 'linux', 'web')
platform_version VARCHAR(50)
app_version VARCHAR(50)
is_trusted BOOLEAN DEFAULT false
is_active BOOLEAN DEFAULT true
last_used_at TIMESTAMP
created_at, updated_at TIMESTAMP

INDEX (user_id), (device_id), (user_id, is_active)
```

#### personal_access_tokens
```sql
id BIGINT PRIMARY KEY
tokenable_type VARCHAR(255)
tokenable_id UUID
name VARCHAR(255)
token VARCHAR(64) UNIQUE
abilities TEXT NULLABLE
last_used_at TIMESTAMP NULLABLE
expires_at TIMESTAMP NULLABLE INDEX
created_at, updated_at TIMESTAMP

INDEX (tokenable_type, tokenable_id)
```

#### super_admins
```sql
id UUID PRIMARY KEY
name VARCHAR(255)
email VARCHAR(255) UNIQUE
password VARCHAR(255)
role ENUM('super_admin')
is_active BOOLEAN DEFAULT true
last_login_at TIMESTAMP NULLABLE
last_login_ip VARCHAR(45) NULLABLE
notes TEXT NULLABLE
created_at, updated_at TIMESTAMP

INDEX (email), (is_active, email)
```

---

### 2. Tenant Directory (central DB)

> **Cross-DB FK note (post-flip):** the `tenant_id UUID FK → tenants(id) CASCADE` annotations shown on per-tenant tables (`users`, `companies`, etc.) below are accurate as columns but the FK constraint **does not exist post-flip** — `tenants` lives in `synerivia_central` and the column references it from a separate `tenant_<uuid>` database, which PostgreSQL doesn't support across databases. The column is kept for defense-in-depth tenant scoping (`WHERE tenant_id = ?` at every callsite from the pre-flip tenant-isolation sweep) and as an audit identifier.

#### tenants
```sql
id UUID PRIMARY KEY
name VARCHAR(255)
slug VARCHAR(100) UNIQUE
status ENUM('pending', 'active', 'suspended')
plan VARCHAR(50) DEFAULT 'trial'
country_code CHAR(2)
currency_code CHAR(3)
settings JSONB DEFAULT '{}'
data JSONB  -- Stancl tenancy
trial_ends_at TIMESTAMP NULLABLE
subscription_ends_at TIMESTAMP NULLABLE
-- Contact info
first_name, last_name, email, phone VARCHAR
-- Address fields
created_at, updated_at TIMESTAMP

INDEX (slug), (status), (plan), (country_code)
```

#### domains
```sql
id UUID PRIMARY KEY
domain VARCHAR(255) UNIQUE
tenant_id UUID FK → tenants(id) CASCADE
is_primary BOOLEAN DEFAULT false
is_verified BOOLEAN DEFAULT false
created_at, updated_at TIMESTAMP

INDEX (tenant_id, is_primary)
```

---

### 3. Company & Locations

#### companies
```sql
id UUID PRIMARY KEY
tenant_id UUID FK → tenants(id) CASCADE
name VARCHAR(255)
legal_name VARCHAR(255) NULLABLE
code VARCHAR(50) NULLABLE
country_code CHAR(2)  -- CRITICAL: determines compliance regime
tax_id VARCHAR(50) NULLABLE
vat_number VARCHAR(50) NULLABLE
legal_identifiers JSONB
-- Contact
email VARCHAR(255)
phone VARCHAR(50)
website VARCHAR(255)
-- Address fields
-- Branding
logo_path VARCHAR(500) NULLABLE
primary_color VARCHAR(7) NULLABLE
-- Regional
currency CHAR(3)
locale VARCHAR(10)
timezone VARCHAR(50)
date_format VARCHAR(20)
fiscal_year_start_month TINYINT  -- 1-12
-- Document sequences
invoice_prefix VARCHAR(10)
invoice_next_number BIGINT
quote_prefix VARCHAR(10)
quote_next_number BIGINT
sales_order_prefix VARCHAR(10)
sales_order_next_number BIGINT
purchase_order_prefix VARCHAR(10)
purchase_order_next_number BIGINT
delivery_note_prefix VARCHAR(10)
delivery_note_next_number BIGINT
-- Inventory costing (added)
inventory_costing_method ENUM('weighted_average', 'fifo', 'lifo')
default_target_margin DECIMAL(5,2)
default_minimum_margin DECIMAL(5,2)
allow_below_cost_sales BOOLEAN DEFAULT false
-- Verification
verification_tier, verification_status, verified_at, verified_by
-- Hierarchy
parent_company_id UUID FK → self NULL
is_headquarters BOOLEAN DEFAULT false
-- Status
status ENUM('active', 'closed')
closed_at TIMESTAMP NULLABLE
created_at, updated_at, deleted_at TIMESTAMP

UNIQUE (tenant_id, tax_id) WHERE tax_id NOT NULL
UNIQUE (tenant_id, code) WHERE code NOT NULL
INDEX (tenant_id), (country_code), (status)
```

#### locations
```sql
id UUID PRIMARY KEY
company_id UUID FK → companies(id) CASCADE
name VARCHAR(255)
code VARCHAR(50) NULLABLE
type ENUM('shop', 'warehouse', 'office', 'mobile')
-- Contact
phone VARCHAR(50)
email VARCHAR(255)
-- Address fields
latitude DECIMAL(10,8) NULLABLE
longitude DECIMAL(11,8) NULLABLE
-- Settings
is_default BOOLEAN DEFAULT false
is_active BOOLEAN DEFAULT true
-- POS
pos_enabled BOOLEAN DEFAULT false
receipt_header TEXT NULLABLE
receipt_footer TEXT NULLABLE
created_at, updated_at TIMESTAMP

UNIQUE (company_id, code) WHERE code NOT NULL
INDEX (company_id), (type)
```

#### user_company_memberships
```sql
id UUID PRIMARY KEY
user_id UUID FK → users(id) CASCADE
company_id UUID FK → companies(id) CASCADE
created_at, updated_at TIMESTAMP

UNIQUE (user_id, company_id)
```

---

### 4. Documents (Unified)

#### documents
```sql
id UUID PRIMARY KEY
tenant_id UUID FK → tenants(id) CASCADE
partner_id UUID FK → partners(id)
vehicle_id UUID FK → vehicles(id) NULL
-- Type & identification
type ENUM('quote', 'sales_order', 'invoice', 'credit_note',
          'delivery_note', 'purchase_order')
document_number VARCHAR(50)
-- Status
status ENUM('draft', 'confirmed', 'posted', 'cancelled')
-- Dates
document_date DATE
due_date DATE NULLABLE
valid_until DATE NULLABLE
-- Amounts
currency CHAR(3) DEFAULT 'EUR'
subtotal DECIMAL(15,2)
discount_amount DECIMAL(15,2) DEFAULT 0
tax_amount DECIMAL(15,2)
total DECIMAL(15,2)
balance_due DECIMAL(15,2)
-- References
reference VARCHAR(100) NULLABLE
source_document_id UUID FK → self NULLABLE
original_invoice_id UUID FK → self NULLABLE  -- For credit notes
credit_reason VARCHAR(255) NULLABLE
-- Fiscal chain
fiscal_number VARCHAR(50) NULLABLE
hash VARCHAR(64) NULLABLE
previous_hash VARCHAR(64) NULLABLE
chain_sequence BIGINT NULLABLE
-- Fiscal status
is_fiscal BOOLEAN DEFAULT false
fiscal_status ENUM('draft', 'approved', 'posted', 'cancelled') NULLABLE
-- Flexible data
payload JSONB DEFAULT '{}'
notes TEXT NULLABLE
internal_notes TEXT NULLABLE
-- Delivery tracking
external_document_date DATE NULLABLE
-- Audit
created_at, updated_at, deleted_at TIMESTAMP

UNIQUE (tenant_id, type, document_number)
INDEX (tenant_id, type, status)
INDEX (tenant_id, partner_id)
INDEX (tenant_id, document_date)
INDEX (fiscal_number)
```

#### document_lines
```sql
id UUID PRIMARY KEY
document_id UUID FK → documents(id) CASCADE
product_id UUID FK → products(id) NULL
service_id UUID FK → services(id) NULL
line_number SMALLINT
description TEXT
quantity DECIMAL(15,4)
unit_price DECIMAL(15,2)
discount_percent DECIMAL(5,2) DEFAULT 0
discount_amount DECIMAL(15,2) DEFAULT 0
tax_rate DECIMAL(5,2)
line_total DECIMAL(15,2)
-- Cost tracking (landed cost)
unit_cost DECIMAL(15,4) NULLABLE
total_cost DECIMAL(15,2) NULLABLE
cost_allocation_method VARCHAR(50) NULLABLE
-- Delivery tracking
quantity_received DECIMAL(15,4) DEFAULT 0
-- Notes
notes TEXT NULLABLE
created_at, updated_at TIMESTAMP

UNIQUE (document_id, line_number)
INDEX (document_id), (product_id)
```

#### document_sequences
```sql
id UUID PRIMARY KEY
company_id UUID FK → companies(id) CASCADE
tenant_id UUID FK → tenants(id) CASCADE
type VARCHAR(20)  -- invoice, quote, sales_order, etc.
year SMALLINT
last_number INT UNSIGNED DEFAULT 0
created_at, updated_at TIMESTAMP

UNIQUE (company_id, type, year)
```

#### document_additional_costs
```sql
id UUID PRIMARY KEY
document_id UUID FK → documents(id) CASCADE
cost_type VARCHAR(50)  -- freight, customs, insurance, handling
description TEXT NULLABLE
amount DECIMAL(12,2)
expense_document_id UUID FK → documents(id) SET NULL
created_at, updated_at TIMESTAMP

INDEX (document_id)
```

---

### 5. Accounting

#### accounts
```sql
id UUID PRIMARY KEY
tenant_id UUID FK → tenants(id) CASCADE
company_id UUID FK → companies(id) CASCADE
parent_id UUID FK → self NULL
code VARCHAR(20)
name VARCHAR(255)
type ENUM('asset', 'liability', 'equity', 'revenue', 'expense')
description TEXT NULLABLE
is_active BOOLEAN DEFAULT true
is_system BOOLEAN DEFAULT false
system_purpose VARCHAR(50) NULLABLE  -- accounts_receivable, sales_revenue, etc.
balance DECIMAL(19,2) DEFAULT 0
created_at, updated_at TIMESTAMP

UNIQUE (company_id, code)
INDEX (company_id, parent_id)
INDEX (company_id, type)
```

#### journal_entries
```sql
id UUID PRIMARY KEY
tenant_id UUID FK → tenants(id) CASCADE
entry_number VARCHAR(50)
entry_date DATE
description TEXT
status ENUM('draft', 'posted', 'reversed')
-- Source reference
source_type VARCHAR(50) NULLABLE
source_id UUID NULLABLE
-- Hash chain
hash VARCHAR(64) NULLABLE
previous_hash VARCHAR(64) NULLABLE
-- Posting audit
posted_at TIMESTAMP NULLABLE
posted_by UUID FK → users(id) NULL
-- Reversal
reversed_at TIMESTAMP NULLABLE
reversed_by UUID FK → users(id) NULL
reversal_entry_id UUID FK → self NULLABLE
created_at, updated_at TIMESTAMP

UNIQUE (tenant_id, entry_number)
INDEX (source_type, source_id)
```

#### journal_lines
```sql
id UUID PRIMARY KEY
journal_entry_id UUID FK → journal_entries(id) CASCADE
account_id UUID FK → accounts(id) RESTRICT
partner_id UUID FK → partners(id) NULLABLE
debit DECIMAL(15,2) DEFAULT 0
credit DECIMAL(15,2) DEFAULT 0
description TEXT NULLABLE
line_order INT UNSIGNED
created_at, updated_at TIMESTAMP

INDEX (account_id, created_at)
INDEX (partner_id, created_at)
```

---

### 6. Inventory

#### stock_levels
```sql
id UUID PRIMARY KEY
tenant_id UUID FK → tenants(id) CASCADE
company_id UUID FK → companies(id) CASCADE
product_id UUID FK → products(id) CASCADE
location_id UUID FK → locations(id) CASCADE
quantity DECIMAL(15,2) DEFAULT 0
reserved DECIMAL(15,2) DEFAULT 0
min_quantity DECIMAL(15,2) DEFAULT 0
max_quantity DECIMAL(15,2) NULLABLE
created_at, updated_at TIMESTAMP

UNIQUE (company_id, product_id, location_id)
INDEX (tenant_id, product_id)
INDEX (tenant_id, location_id)
```

#### stock_movements
```sql
id UUID PRIMARY KEY
tenant_id UUID FK → tenants(id) CASCADE
company_id UUID FK → companies(id) CASCADE
product_id UUID FK → products(id) CASCADE
location_id UUID FK → locations(id) CASCADE
movement_type ENUM('receipt', 'issue', 'adjustment', 'return',
                   'transfer', 'counting')
quantity DECIMAL(15,2)
quantity_before DECIMAL(15,2)
quantity_after DECIMAL(15,2)
-- Cost tracking
unit_cost DECIMAL(15,4) NULLABLE
total_cost DECIMAL(15,2) NULLABLE
cost_method VARCHAR(50) NULLABLE
-- Reference
reference VARCHAR(255) NULLABLE
notes TEXT NULLABLE
user_id UUID FK → users(id) NULL
created_at, updated_at TIMESTAMP

INDEX (tenant_id, product_id, created_at)
INDEX (tenant_id, location_id, created_at)
INDEX (tenant_id, movement_type, created_at)
INDEX (reference)
```

#### inventory_countings
```sql
id UUID PRIMARY KEY
company_id UUID FK → companies(id) CASCADE
created_by_user_id UUID FK → users(id) RESTRICT
-- Scope
scope_type ENUM('product_location', 'product', 'location',
                'category', 'full_inventory')
scope_filters JSONB
execution_mode ENUM('parallel', 'sequential')
-- Status
status ENUM('draft', 'scheduled', 'count_1_in_progress',
            'count_1_completed', 'count_2_in_progress',
            'count_2_completed', 'count_3_in_progress',
            'count_3_completed', 'pending_review',
            'finalized', 'cancelled')
-- Schedule
scheduled_start TIMESTAMP NULLABLE
scheduled_end TIMESTAMP NULLABLE
-- Counters
count_1_user_id UUID FK → users(id) NULL
count_2_user_id UUID FK → users(id) NULL
count_3_user_id UUID FK → users(id) NULL
requires_count_2 BOOLEAN DEFAULT true
requires_count_3 BOOLEAN DEFAULT false
-- Options
allow_unexpected_items BOOLEAN DEFAULT false
instructions TEXT NULLABLE
-- Mobile
initiated_from_mobile BOOLEAN DEFAULT false
mobile_device_id UUID NULLABLE
-- Timestamps
activated_at TIMESTAMP NULLABLE
finalized_at TIMESTAMP NULLABLE
cancelled_at TIMESTAMP NULLABLE
cancellation_reason TEXT NULLABLE
created_at, updated_at TIMESTAMP

INDEX (company_id, status)
INDEX (company_id, scheduled_start)
```

#### inventory_counting_items
```sql
id UUID PRIMARY KEY
counting_id UUID FK → inventory_countings(id) CASCADE
product_id UUID FK → products(id) RESTRICT
variant_id UUID NULLABLE
location_id UUID FK → locations(id) RESTRICT
theoretical_qty DECIMAL(15,4)
-- Count 1
count_1_qty DECIMAL(15,4) NULLABLE
count_1_at TIMESTAMP NULLABLE
count_1_notes TEXT NULLABLE
-- Count 2
count_2_qty DECIMAL(15,4) NULLABLE
count_2_at TIMESTAMP NULLABLE
count_2_notes TEXT NULLABLE
-- Count 3
count_3_qty DECIMAL(15,4) NULLABLE
count_3_at TIMESTAMP NULLABLE
count_3_notes TEXT NULLABLE
-- Resolution
final_qty DECIMAL(15,4) NULLABLE
resolution_method ENUM('pending', 'auto_all_match',
                       'auto_counters_agree', 'third_count_decisive',
                       'manual_override') DEFAULT 'pending'
resolution_notes TEXT NULLABLE
resolved_by_user_id UUID FK → users(id) NULL
resolved_at TIMESTAMP NULLABLE
-- Flags
is_flagged BOOLEAN DEFAULT false
flag_reason TEXT NULLABLE
is_unexpected_item BOOLEAN DEFAULT false
created_at, updated_at TIMESTAMP

INDEX (counting_id, is_flagged)
INDEX (counting_id, resolution_method)
INDEX (product_id, location_id)
```

#### stock_adjustments (DPA V7)

The manual stock-correction DOCUMENT that replaced the four raw
`POST /stock-movements/*` writers. Its own tables, deliberately NOT the unified
`documents` table: an adjustment has no partner, no tax and no monetary total,
and every consumer of `documents` would have to special-case it.

```sql
id UUID PRIMARY KEY
tenant_id UUID (plain indexed uuid — `tenants` lives in the CENTRAL DB)
company_id UUID FK → companies(id) CASCADE
adjustment_number VARCHAR(30) NULLABLE   -- stamped at POST (ADJ-YYYY-NNNN)
status VARCHAR(20) DEFAULT 'draft'       -- draft | posted | cancelled
note TEXT NULLABLE
location_id UUID FK → locations(id) RESTRICT   -- HEADER-level (one doc = one location)
occurred_at TIMESTAMPTZ                  -- server now(); backdating forbidden in v1
idempotency_key VARCHAR(128) NULLABLE
created_by_user_id UUID FK → users(id) RESTRICT
posted_by_user_id, cancelled_by_user_id UUID FK → users(id) NULL
stale_acknowledged_by_user_id, reservations_ignored_by_user_id UUID FK → users(id) NULL
posted_at, cancelled_at, stale_acknowledged_at, reservations_ignored_at TIMESTAMPTZ NULL
cancellation_reason TEXT NULLABLE
corrects_adjustment_id UUID FK → stock_adjustments(id) RESTRICT NULLABLE
created_at, updated_at TIMESTAMPTZ

INDEX (tenant_id, company_id, status)
INDEX (tenant_id, company_id, occurred_at)
INDEX (tenant_id, company_id, location_id)
UNIQUE (tenant_id, company_id, adjustment_number) WHERE adjustment_number IS NOT NULL
UNIQUE (tenant_id, company_id, idempotency_key)  WHERE idempotency_key IS NOT NULL
UNIQUE (corrects_adjustment_id)                  WHERE corrects_adjustment_id IS NOT NULL
```

The four override-audit columns are not decoration: overriding an integrity
guard (staleness, or the reserved-aware availability boundary) must never be
invisible.

#### stock_adjustment_lines (DPA V7)

```sql
id UUID PRIMARY KEY
adjustment_id UUID FK → stock_adjustments(id) CASCADE
tenant_id UUID (plain indexed uuid)
company_id UUID FK → companies(id) CASCADE
product_id UUID FK → products(id) RESTRICT
variant_id UUID FK → product_variants(id) RESTRICT NOT VALID, NULLABLE
batch_id BIGINT FK → product_batches(id) RESTRICT NULLABLE  -- int PK; HTTP speaks the uuid
reason_code VARCHAR(50)          -- MovementReason, LINE-level (mixed direction per doc)
delta_quantity DECIMAL(15,4)     -- SIGNED
observed_before DECIMAL(15,4)    -- authoring snapshot (staleness guard)
quantity_before DECIMAL(15,4) NULLABLE   -- as actually POSTED
quantity_after  DECIMAL(15,4) NULLABLE   -- as actually POSTED
movement_id UUID NULLABLE
line_note VARCHAR(255) NULLABLE
created_at, updated_at TIMESTAMPTZ

-- FOUR partial uniques: both variant_id and batch_id are nullable and PG treats
-- NULLs as distinct, so one index cannot express "one line per (SKU, lot)".
UNIQUE (adjustment_id, product_id)                          WHERE variant_id IS NULL     AND batch_id IS NULL
UNIQUE (adjustment_id, product_id, variant_id)              WHERE variant_id IS NOT NULL AND batch_id IS NULL
UNIQUE (adjustment_id, product_id, batch_id)                WHERE variant_id IS NULL     AND batch_id IS NOT NULL
UNIQUE (adjustment_id, product_id, variant_id, batch_id)    WHERE variant_id IS NOT NULL AND batch_id IS NOT NULL
UNIQUE (movement_id) WHERE movement_id IS NOT NULL   -- posted at most once, survives a job retry
INDEX (tenant_id, product_id)
INDEX (tenant_id, adjustment_id)
INDEX (batch_id)

CHECK (delta_quantity <> 0)
CHECK ((reason_code IN ('adjustment_positive') AND delta_quantity > 0)
    OR (reason_code IN ('adjustment_negative','damage','write_off') AND delta_quantity < 0))
```

The CHECK lists are FROZEN LITERALS, not derived from
`MovementReason::manualAdjustmentCases()`: `tenants:migrate` runs the migration
at each tenant's provisioning time, so a derived predicate would emit a
DIFFERENT constraint for tenants provisioned after a later enum change, with no
migration recording the divergence. `AdjustmentReasonSignPartitionTest` asserts
the enum and the frozen strings still agree, so a drift fails CI instead.

---

### 7. Treasury & Payments

#### payment_methods
```sql
id UUID PRIMARY KEY
tenant_id UUID FK → tenants(id) CASCADE
code VARCHAR(30)
name VARCHAR(100)
-- Universal switches
is_physical BOOLEAN DEFAULT false
has_maturity BOOLEAN DEFAULT false
requires_third_party BOOLEAN DEFAULT false
is_push BOOLEAN DEFAULT true
has_deducted_fees BOOLEAN DEFAULT false
is_restricted BOOLEAN DEFAULT false
-- Fees
fee_type ENUM('none', 'fixed', 'percentage', 'mixed') NULLABLE
fee_fixed DECIMAL(10,2) DEFAULT 0
fee_percent DECIMAL(5,2) DEFAULT 0
-- Restrictions
restriction_type VARCHAR(50) NULLABLE
-- Linked accounts
default_journal_id UUID NULLABLE
default_account_id UUID NULLABLE
fee_account_id UUID NULLABLE
-- Status
is_active BOOLEAN DEFAULT true
position INT DEFAULT 0
created_at, updated_at TIMESTAMP

UNIQUE (tenant_id, code)
INDEX (tenant_id, is_active)
```

#### payment_repositories
```sql
id UUID PRIMARY KEY
tenant_id UUID FK → tenants(id) CASCADE
code VARCHAR(30)
name VARCHAR(100)
type ENUM('cash_register', 'safe', 'bank_account', 'virtual')
-- Bank info
bank_name VARCHAR(100) NULLABLE
account_number VARCHAR(50) NULLABLE
iban VARCHAR(50) NULLABLE
bic VARCHAR(20) NULLABLE
-- Balance
balance DECIMAL(15,2) DEFAULT 0
last_reconciled_at TIMESTAMP NULLABLE
last_reconciled_balance DECIMAL(15,2) NULLABLE
-- Access
location_id UUID FK → locations(id) NULL
responsible_user_id UUID FK → users(id) NULL
account_id UUID FK → accounts(id) NULL
-- Status
is_active BOOLEAN DEFAULT true
created_at, updated_at TIMESTAMP

UNIQUE (tenant_id, code)
INDEX (tenant_id, type), (tenant_id, is_active)
```

#### payment_instruments
```sql
id UUID PRIMARY KEY
tenant_id UUID FK → tenants(id) CASCADE
payment_method_id UUID FK → payment_methods(id) RESTRICT
reference VARCHAR(100)
partner_id UUID FK → partners(id) NULL
drawer_name VARCHAR(150) NULLABLE
amount DECIMAL(15,2)
currency CHAR(3) DEFAULT 'TND'
-- Dates
received_date DATE
maturity_date DATE NULLABLE
expiry_date DATE NULLABLE
-- Status & location
status ENUM('received', 'used', 'deposited', 'cleared', 'bounced')
repository_id UUID FK → payment_repositories(id) RESTRICT
-- Bank info
bank_name VARCHAR(100) NULLABLE
bank_branch VARCHAR(100) NULLABLE
bank_account VARCHAR(50) NULLABLE
-- Lifecycle
deposited_at TIMESTAMP NULLABLE
deposited_to_id UUID NULLABLE
cleared_at TIMESTAMP NULLABLE
bounced_at TIMESTAMP NULLABLE
bounce_reason TEXT NULLABLE
-- Payment link
payment_id UUID FK → payments(id) NULL
-- Audit
created_by UUID FK → users(id)
created_at, updated_at TIMESTAMP

INDEX (tenant_id, status)
INDEX (tenant_id, partner_id)
INDEX (tenant_id, maturity_date)
INDEX (tenant_id, repository_id)
```

#### payments
```sql
id UUID PRIMARY KEY
tenant_id UUID FK → tenants(id) CASCADE
partner_id UUID FK → partners(id) RESTRICT
payment_method_id UUID FK → payment_methods(id) RESTRICT
instrument_id UUID FK → payment_instruments(id) NULL
repository_id UUID FK → payment_repositories(id) NULL
amount DECIMAL(15,2)
currency CHAR(3) DEFAULT 'TND'
payment_date DATE
payment_type ENUM('incoming', 'outgoing')
status ENUM('pending', 'recorded', 'partially_allocated',
            'allocated', 'reversed')
-- Allocation tracking
total_allocated DECIMAL(15,2) DEFAULT 0
remaining_unallocated DECIMAL(15,2)
-- Reference
reference VARCHAR(100) NULLABLE
notes TEXT NULLABLE
-- GL link
journal_entry_id UUID FK → journal_entries(id) NULL
-- Audit
created_by UUID FK → users(id)
created_at, updated_at TIMESTAMP

INDEX (tenant_id, partner_id)
INDEX (tenant_id, payment_date)
INDEX (tenant_id, status)
```

#### payment_allocations
```sql
id UUID PRIMARY KEY
payment_id UUID FK → payments(id) CASCADE
document_id UUID FK → documents(id) RESTRICT
amount DECIMAL(15,2)
-- Tolerance handling
tolerance_applied DECIMAL(10,2) DEFAULT 0
tolerance_reason VARCHAR(255) NULLABLE
created_at, updated_at TIMESTAMP

INDEX (payment_id), (document_id)
```

---

### 8. Products & Partners

#### products
```sql
id UUID PRIMARY KEY
tenant_id UUID FK → tenants(id) CASCADE
name VARCHAR(255)
sku VARCHAR(100)
type ENUM('part', 'service', 'consumable', 'labor')
description TEXT NULLABLE
sale_price DECIMAL(15,2)
purchase_price DECIMAL(15,2) NULLABLE
cost DECIMAL(15,4) NULLABLE
margin_percent DECIMAL(5,2) NULLABLE
tax_rate DECIMAL(5,2)
unit VARCHAR(50) DEFAULT 'unit'
barcode VARCHAR(100) NULLABLE
is_active BOOLEAN DEFAULT true
is_physical BOOLEAN DEFAULT true
-- Automotive
oem_numbers JSON DEFAULT '[]'
cross_references JSON DEFAULT '[]'
created_at, updated_at, deleted_at TIMESTAMP

UNIQUE (tenant_id, sku)
INDEX (tenant_id, type)
INDEX (tenant_id, is_active)
INDEX (tenant_id, name)
INDEX (tenant_id, barcode)
```

#### partners
```sql
id UUID PRIMARY KEY
tenant_id UUID FK → tenants(id) CASCADE
name VARCHAR(255)
type ENUM('customer', 'supplier', 'both')
code VARCHAR(50) NULLABLE
email VARCHAR(255) NULLABLE
phone VARCHAR(50) NULLABLE
country_code CHAR(2) NULLABLE
vat_number VARCHAR(50) NULLABLE
notes TEXT NULLABLE
is_active BOOLEAN DEFAULT true
-- Balance tracking
account_balance DECIMAL(15,2) DEFAULT 0
credit_limit DECIMAL(15,2) NULLABLE
days_payable_outstanding INT UNSIGNED DEFAULT 0
created_at, updated_at, deleted_at TIMESTAMP

UNIQUE (tenant_id, code)
UNIQUE (tenant_id, vat_number) WHERE vat_number NOT NULL
INDEX (tenant_id, type)
INDEX (tenant_id, name)
```

---

### 9. SaaS Billing

#### plans
```sql
id UUID PRIMARY KEY
code VARCHAR(30) UNIQUE
name VARCHAR(100)
description TEXT NULLABLE
limits JSON
price_monthly DECIMAL(10,2)
price_yearly DECIMAL(10,2)
currency CHAR(3) DEFAULT 'TND'
trial_days SMALLINT DEFAULT 14
-- Stripe
stripe_monthly_price_id VARCHAR(255) NULLABLE
stripe_yearly_price_id VARCHAR(255) NULLABLE
-- Status
is_active BOOLEAN DEFAULT true
is_public BOOLEAN DEFAULT true
display_order INT DEFAULT 0
created_at TIMESTAMP
```

#### tenant_subscriptions
```sql
id UUID PRIMARY KEY
tenant_id UUID FK → tenants(id) CASCADE
plan_id UUID FK → plans(id)
status ENUM('trial', 'active', 'expired', 'suspended')
billing_cycle ENUM('monthly', 'yearly')
price DECIMAL(10,2)
currency CHAR(3)
-- Trial
trial_ends_at TIMESTAMP NULLABLE
-- Period
current_period_start TIMESTAMP
current_period_end TIMESTAMP
-- Cancellation
cancelled_at TIMESTAMP NULLABLE
ends_at TIMESTAMP NULLABLE
-- Stripe
stripe_subscription_id VARCHAR(255) NULLABLE
stripe_customer_id VARCHAR(255) NULLABLE
-- Payment tracking
last_payment_at TIMESTAMP NULLABLE
next_payment_due TIMESTAMP NULLABLE
-- Notes
notes TEXT NULLABLE
metadata JSONB
created_at, updated_at, deleted_at TIMESTAMP

INDEX (tenant_id, status)
INDEX (next_payment_due)
```

#### billing_invoices
```sql
id UUID PRIMARY KEY
tenant_id UUID FK → tenants(id) CASCADE
subscription_id UUID FK → tenant_subscriptions(id) NULL
number VARCHAR(50) UNIQUE
status ENUM('draft', 'pending', 'sent', 'paid', 'partially_paid',
            'overdue', 'cancelled', 'refunded')
-- Amounts
subtotal DECIMAL(12,2)
tax_amount DECIMAL(12,2)
discount_amount DECIMAL(12,2) DEFAULT 0
total DECIMAL(12,2)
amount_paid DECIMAL(12,2) DEFAULT 0
amount_due DECIMAL(12,2)
currency CHAR(3)
-- Tax
tax_rate DECIMAL(5,2)
tax_number VARCHAR(50) NULLABLE
-- Billing snapshot
billing_address JSONB
billing_email VARCHAR(255)
billing_name VARCHAR(255)
-- Dates
invoice_date DATE
due_date DATE
paid_at TIMESTAMP NULLABLE
sent_at TIMESTAMP NULLABLE
-- Period
period_start DATE
period_end DATE
-- Files
pdf_path VARCHAR(500) NULLABLE
-- Notes
notes TEXT NULLABLE
footer_text TEXT NULLABLE
-- Stripe
stripe_invoice_id VARCHAR(255) UNIQUE NULLABLE
-- Metadata
metadata JSONB
created_at, updated_at, deleted_at TIMESTAMP

INDEX (tenant_id), (status), (due_date), (invoice_date)
```

#### billing_payments
```sql
id UUID PRIMARY KEY
tenant_id UUID FK → tenants(id) CASCADE
invoice_id UUID FK → billing_invoices(id) NULL
provider ENUM('stripe', 'paypal', 'manual', 'bank_transfer',
              'cash', 'check', 'flouci')
provider_payment_id VARCHAR(255) NULLABLE
status ENUM('pending', 'processing', 'requires_action',
            'succeeded', 'failed', 'cancelled',
            'refunded', 'partially_refunded')
amount DECIMAL(12,2)
fee DECIMAL(12,2) DEFAULT 0
net_amount DECIMAL(12,2)
currency CHAR(3)
refunded_amount DECIMAL(12,2) DEFAULT 0
-- Payment method
payment_method_type ENUM('card', 'bank_transfer', 'cash', 'check')
payment_method_details JSONB
-- Manual payments
reference_number VARCHAR(100) NULLABLE
payment_date DATE NULLABLE
recorded_by UUID FK → super_admins(id) NULL
-- 3DS
client_secret VARCHAR(255) NULLABLE
action_url VARCHAR(500) NULLABLE
-- Error
error_code VARCHAR(50) NULLABLE
error_message TEXT NULLABLE
-- Timestamps
paid_at TIMESTAMP NULLABLE
refunded_at TIMESTAMP NULLABLE
-- Metadata
metadata JSONB
created_at, updated_at, deleted_at TIMESTAMP

INDEX (tenant_id), (invoice_id), (provider), (status), (paid_at)
INDEX (provider, provider_payment_id)
```

---

### 10. Event Sourcing & Audit

#### stored_events
```sql
id BIGINT PRIMARY KEY AUTO_INCREMENT
aggregate_uuid UUID NULLABLE INDEX
aggregate_version BIGINT NULLABLE
event_version TINYINT DEFAULT 1
event_class VARCHAR(255)
event_properties JSONB
meta_data JSONB
created_at TIMESTAMP

INDEX (event_class), (aggregate_uuid)
UNIQUE (aggregate_uuid, aggregate_version)
```

#### audit_events
```sql
id UUID PRIMARY KEY
tenant_id UUID FK → tenants(id) INDEX
user_id UUID FK → users(id) NULL INDEX
event_type VARCHAR(100) INDEX
aggregate_type VARCHAR(100) INDEX
aggregate_id VARCHAR(100) INDEX
payload JSONB
metadata JSONB
event_hash VARCHAR(64)
occurred_at TIMESTAMP INDEX
created_at, updated_at TIMESTAMP

INDEX (tenant_id, event_type)
INDEX (tenant_id, occurred_at)
INDEX (aggregate_type, aggregate_id)
INDEX (tenant_id, user_id, occurred_at)
```

---

## Enum Reference

**CRITICAL: All status/type columns MUST use PHP Enums. No magic strings.**

| Column | Values |
|--------|--------|
| `documents.type` | quote, sales_order, invoice, credit_note, delivery_note, purchase_order |
| `documents.status` | draft, confirmed, posted, cancelled |
| `products.type` | part, service, consumable, labor |
| `partners.type` | customer, supplier, both |
| `locations.type` | shop, warehouse, office, mobile |
| `users.status` | pending_verification, active, suspended |
| `payments.status` | pending, recorded, partially_allocated, allocated, reversed |
| `payment_instruments.status` | received, used, deposited, cleared, bounced |
| `payment_repositories.type` | cash_register, safe, bank_account, virtual |
| `accounts.type` | asset, liability, equity, revenue, expense |
| `journal_entries.status` | draft, posted, reversed |
| `stock_movements.movement_type` | receipt, issue, adjustment, return, transfer, counting |

---

## JSONB Column DTOs

**CRITICAL: Every JSONB column must have a corresponding PHP DTO.**

| Column | DTO Class |
|--------|-----------|
| `documents.payload` | `App\Modules\Document\Application\DTOs\DocumentPayload` |
| `companies.legal_identifiers` | `App\Modules\Company\Application\DTOs\LegalIdentifiers` |
| `inventory_countings.scope_filters` | `App\Modules\Inventory\Application\DTOs\CountingScopeFilters` |

Access pattern:
```php
// WRONG - Forbidden
$doc->payload['legacy_reference'];

// RIGHT - Use typed DTO
$payload = DocumentPayload::fromArray($doc->payload);
$payload->legacyReference;
```

---

## Related Documentation

- [Architecture Overview](./overview.md)
- [Backend Architecture](./backend.md)
- [Module Reference](../modules/README.md)
