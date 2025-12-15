# AutoERP - Phase 0: Architecture Refactor

> **CRITICAL: This phase must be completed before any new features.**
> **Goal:** Restructure the system from tenant-scoped to company-scoped architecture.

---

## Executive Summary

### What's Changing

| Before (Wrong) | After (Correct) |
|----------------|-----------------|
| Tenant = Company | Tenant = Account/Person (subscription) |
| Data scoped to tenant | Data scoped to company |
| One tenant = one business | One tenant = multiple companies |
| No location concept | Locations for shops/warehouses |
| Verification per tenant | Verification per company |
| Country at tenant level | Country at company level |

### Why This Matters

1. **Legal compliance**: Invoices, taxes, audits are per legal entity (company), not per account
2. **Multi-country support**: One tenant can have companies in Tunisia, France, Italy
3. **Chain/franchise support**: Multiple shops under one account
4. **Future-proof**: Inter-company transactions, consolidated reporting

---

## Phase 0 Sections

| Section | Focus | Est. Hours |
|---------|-------|------------|
| 0.1 | Database Schema Changes | 8-10 |
| 0.2 | Data Migration | 4-6 |
| 0.3 | Model Updates | 6-8 |
| 0.4 | Compliance Core Refactor | 6-8 |
| 0.5 | API & Middleware Updates | 6-8 |
| 0.6 | Frontend Context & Switcher | 6-8 |
| 0.7 | Signup Flow Refactor | 4-6 |
| 0.8 | Testing & Verification | 4-6 |
| **Total** | | **44-60 hours** |

---

## 0.1 Database Schema Changes

### 0.1.1 Update Tenants Table (Personal Info Only)

```sql
-- Tenants: Account/subscription level only (PERSONAL info, not company info)
-- Remove: country_code, tax_id, legal_name, verification fields
-- Add: first_name, last_name, personal contact info

ALTER TABLE tenants 
  ADD COLUMN first_name VARCHAR(100),
  ADD COLUMN last_name VARCHAR(100),
  ADD COLUMN preferred_locale VARCHAR(10) DEFAULT 'fr';

-- Migrate existing data: split name into first/last, or use name as first_name
UPDATE tenants SET first_name = name WHERE first_name IS NULL;

-- Remove columns that belong to company (do this AFTER data migration to companies)
-- ALTER TABLE tenants DROP COLUMN country_code; -- Later
-- ALTER TABLE tenants DROP COLUMN verification_tier; -- Later
```

- [ ] Create migration to add personal info columns to tenants
- [ ] Create migration to remove company-specific columns (run after data migration)

### 0.1.2 Create Companies Table

```sql
CREATE TABLE companies (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    tenant_id UUID NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
    
    -- Basic Info
    name VARCHAR(255) NOT NULL,
    legal_name VARCHAR(255),
    code VARCHAR(20),
    
    -- COUNTRY (determines legal/fiscal system) - CRITICAL!
    country_code CHAR(2) NOT NULL,
    
    -- Legal/Tax Info (validated per country rules)
    tax_id VARCHAR(50),
    registration_number VARCHAR(100),
    vat_number VARCHAR(50),
    legal_identifiers JSONB DEFAULT '{}',
    
    -- Contact
    email VARCHAR(255),
    phone VARCHAR(30),
    website VARCHAR(255),
    
    -- Address
    address_street VARCHAR(255),
    address_street_2 VARCHAR(255),
    address_city VARCHAR(100),
    address_state VARCHAR(100),
    address_postal_code VARCHAR(20),
    
    -- Branding
    logo_path VARCHAR(500),
    primary_color VARCHAR(7) DEFAULT '#2563EB',
    
    -- Regional Settings (derived from country, can override)
    currency CHAR(3) NOT NULL,
    locale VARCHAR(10) NOT NULL,
    timezone VARCHAR(50) NOT NULL,
    date_format VARCHAR(20) DEFAULT 'DD/MM/YYYY',
    
    -- Fiscal Settings
    fiscal_year_start_month SMALLINT NOT NULL DEFAULT 1,
    
    -- Document Sequences
    invoice_prefix VARCHAR(20) DEFAULT 'FAC-',
    invoice_next_number INTEGER NOT NULL DEFAULT 1,
    quote_prefix VARCHAR(20) DEFAULT 'DEV-',
    quote_next_number INTEGER NOT NULL DEFAULT 1,
    sales_order_prefix VARCHAR(20) DEFAULT 'BC-',
    sales_order_next_number INTEGER NOT NULL DEFAULT 1,
    purchase_order_prefix VARCHAR(20) DEFAULT 'CF-',
    purchase_order_next_number INTEGER NOT NULL DEFAULT 1,
    delivery_note_prefix VARCHAR(20) DEFAULT 'BL-',
    delivery_note_next_number INTEGER NOT NULL DEFAULT 1,
    receipt_prefix VARCHAR(20) DEFAULT 'REC-',
    receipt_next_number INTEGER NOT NULL DEFAULT 1,
    
    -- Verification (per company, because requirements differ by country)
    verification_tier VARCHAR(20) NOT NULL DEFAULT 'basic',
    verification_status VARCHAR(20) NOT NULL DEFAULT 'pending',
    verification_submitted_at TIMESTAMP,
    verified_at TIMESTAMP,
    verified_by UUID,
    verification_notes TEXT,
    
    -- Compliance profile
    compliance_profile VARCHAR(50),
    
    -- Hierarchy (for chains/franchises)
    parent_company_id UUID REFERENCES companies(id),
    is_headquarters BOOLEAN NOT NULL DEFAULT false,
    
    -- Status
    status VARCHAR(20) NOT NULL DEFAULT 'active',
    closed_at TIMESTAMP,
    
    -- Timestamps
    created_at TIMESTAMP NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMP NOT NULL DEFAULT NOW(),
    deleted_at TIMESTAMP,
    
    -- Constraints
    UNIQUE(tenant_id, tax_id),
    UNIQUE(tenant_id, code)
);

CREATE INDEX idx_companies_tenant ON companies(tenant_id);
CREATE INDEX idx_companies_country ON companies(country_code);
CREATE INDEX idx_companies_status ON companies(status);
```

- [ ] Create companies migration
- [ ] Run migration

### 0.1.3 Create Locations Table

```sql
CREATE TABLE locations (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    company_id UUID NOT NULL REFERENCES companies(id) ON DELETE CASCADE,
    
    -- Basic Info
    name VARCHAR(100) NOT NULL,
    code VARCHAR(20),
    type VARCHAR(20) NOT NULL, -- 'shop', 'warehouse', 'office', 'mobile'
    
    -- Contact
    phone VARCHAR(30),
    email VARCHAR(255),
    
    -- Address (can differ from company)
    address_street VARCHAR(255),
    address_city VARCHAR(100),
    address_postal_code VARCHAR(20),
    address_country CHAR(2),
    
    -- Geo (for mobile/delivery)
    latitude DECIMAL(10, 8),
    longitude DECIMAL(11, 8),
    
    -- Settings
    is_default BOOLEAN NOT NULL DEFAULT false,
    is_active BOOLEAN NOT NULL DEFAULT true,
    
    -- For shops: POS settings
    pos_enabled BOOLEAN NOT NULL DEFAULT false,
    receipt_header TEXT,
    receipt_footer TEXT,
    
    -- Timestamps
    created_at TIMESTAMP NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMP NOT NULL DEFAULT NOW(),
    
    -- Constraints
    UNIQUE(company_id, code)
);

CREATE INDEX idx_locations_company ON locations(company_id);
CREATE INDEX idx_locations_type ON locations(type);
```

- [ ] Create locations migration
- [ ] Run migration

### 0.1.4 Create User-Company Memberships Table

```sql
CREATE TABLE user_company_memberships (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    user_id UUID NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    company_id UUID NOT NULL REFERENCES companies(id) ON DELETE CASCADE,
    
    -- Role within this company
    role VARCHAR(50) NOT NULL, -- 'owner', 'admin', 'manager', 'accountant', 'cashier', 'technician', 'viewer'
    
    -- Location restrictions (NULL = all locations)
    allowed_location_ids UUID[],
    
    -- Flags
    is_primary BOOLEAN NOT NULL DEFAULT false,
    
    -- Invitation tracking
    invited_by UUID REFERENCES users(id),
    invited_at TIMESTAMP,
    accepted_at TIMESTAMP,
    
    -- Status
    status VARCHAR(20) NOT NULL DEFAULT 'active',
    
    -- Timestamps
    created_at TIMESTAMP NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMP NOT NULL DEFAULT NOW(),
    
    -- Constraints
    UNIQUE(user_id, company_id)
);

CREATE INDEX idx_memberships_user ON user_company_memberships(user_id);
CREATE INDEX idx_memberships_company ON user_company_memberships(company_id);
```

- [ ] Create user_company_memberships migration
- [ ] Run migration

### 0.1.5 Create Company Documents Table

```sql
CREATE TABLE company_documents (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    company_id UUID NOT NULL REFERENCES companies(id) ON DELETE CASCADE,
    document_type_id UUID NOT NULL,
    file_path VARCHAR(500) NOT NULL,
    original_filename VARCHAR(255),
    file_size INTEGER,
    mime_type VARCHAR(100),
    status VARCHAR(20) NOT NULL DEFAULT 'pending',
    reviewed_at TIMESTAMP,
    reviewed_by UUID,
    rejection_reason TEXT,
    expires_at DATE,
    uploaded_at TIMESTAMP NOT NULL DEFAULT NOW(),
    
    INDEX idx_company_docs_company (company_id),
    INDEX idx_company_docs_status (status)
);
```

- [ ] Create company_documents migration
- [ ] Run migration

### 0.1.6 Add company_id to Existing Tables

Add `company_id` (nullable initially) to all business tables:

```sql
-- Partners (customers, suppliers)
ALTER TABLE partners ADD COLUMN company_id UUID REFERENCES companies(id);
CREATE INDEX idx_partners_company ON partners(company_id);

-- Products
ALTER TABLE products ADD COLUMN company_id UUID REFERENCES companies(id);
CREATE INDEX idx_products_company ON products(company_id);

-- Documents (invoices, quotes, orders)
ALTER TABLE documents ADD COLUMN company_id UUID REFERENCES companies(id);
ALTER TABLE documents ADD COLUMN location_id UUID REFERENCES locations(id);
CREATE INDEX idx_documents_company ON documents(company_id);

-- Accounts (chart of accounts)
ALTER TABLE accounts ADD COLUMN company_id UUID REFERENCES companies(id);
CREATE INDEX idx_accounts_company ON accounts(company_id);

-- Journal Entries
ALTER TABLE journal_entries ADD COLUMN company_id UUID REFERENCES companies(id);
CREATE INDEX idx_journal_entries_company ON journal_entries(company_id);

-- Payments
ALTER TABLE payments ADD COLUMN company_id UUID REFERENCES companies(id);
CREATE INDEX idx_payments_company ON payments(company_id);

-- Payment Methods
ALTER TABLE payment_methods ADD COLUMN company_id UUID REFERENCES companies(id);
CREATE INDEX idx_payment_methods_company ON payment_methods(company_id);

-- Payment Repositories
ALTER TABLE payment_repositories ADD COLUMN company_id UUID REFERENCES companies(id);
CREATE INDEX idx_payment_repositories_company ON payment_repositories(company_id);

-- Payment Instruments
ALTER TABLE payment_instruments ADD COLUMN company_id UUID REFERENCES companies(id);
CREATE INDEX idx_payment_instruments_company ON payment_instruments(company_id);
```

- [ ] Create migration to add company_id to all business tables
- [ ] Create migration to add location_id to documents table
- [ ] Run migrations

### 0.1.7 Add location_id to Stock Tables

```sql
-- Stock Levels: Change from tenant to location scope
ALTER TABLE stock_levels ADD COLUMN location_id UUID REFERENCES locations(id);
CREATE INDEX idx_stock_levels_location ON stock_levels(location_id);

-- Stock Movements
ALTER TABLE stock_movements ADD COLUMN location_id UUID REFERENCES locations(id);
CREATE INDEX idx_stock_movements_location ON stock_movements(location_id);
```

- [ ] Create migration to add location_id to stock tables
- [ ] Run migration

### 0.1.8 Compliance Tables Updates

```sql
-- Hash chain tracking per company
CREATE TABLE company_hash_chains (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    company_id UUID NOT NULL REFERENCES companies(id) ON DELETE CASCADE,
    document_type VARCHAR(30) NOT NULL, -- 'invoice', 'credit_note', 'receipt'
    last_hash VARCHAR(64) NOT NULL,
    last_sequence BIGINT NOT NULL DEFAULT 0,
    last_document_id UUID,
    updated_at TIMESTAMP NOT NULL DEFAULT NOW(),
    
    UNIQUE(company_id, document_type)
);

-- Add chain_sequence to documents for ordering within company chain
ALTER TABLE documents ADD COLUMN chain_sequence BIGINT;
CREATE INDEX idx_documents_chain ON documents(company_id, type, chain_sequence);

-- Document sequences per company (replaces tenant-level sequences)
CREATE TABLE company_sequences (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    company_id UUID NOT NULL REFERENCES companies(id) ON DELETE CASCADE,
    sequence_type VARCHAR(30) NOT NULL, -- 'invoice', 'quote', 'sales_order', etc.
    prefix VARCHAR(20) NOT NULL,
    current_number BIGINT NOT NULL DEFAULT 0,
    fiscal_year VARCHAR(10), -- Optional, for yearly reset
    updated_at TIMESTAMP NOT NULL DEFAULT NOW(),
    
    UNIQUE(company_id, sequence_type, fiscal_year)
);

-- Fiscal years per company
CREATE TABLE fiscal_years (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    company_id UUID NOT NULL REFERENCES companies(id) ON DELETE CASCADE,
    name VARCHAR(50) NOT NULL,
    start_date DATE NOT NULL,
    end_date DATE NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'open',
    closed_at TIMESTAMP,
    closed_by UUID,
    created_at TIMESTAMP NOT NULL DEFAULT NOW()
);

-- Fiscal periods per company
CREATE TABLE fiscal_periods (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    fiscal_year_id UUID NOT NULL REFERENCES fiscal_years(id) ON DELETE CASCADE,
    company_id UUID NOT NULL REFERENCES companies(id),
    name VARCHAR(50) NOT NULL,
    period_number INTEGER NOT NULL,
    start_date DATE NOT NULL,
    end_date DATE NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'open',
    closed_at TIMESTAMP,
    closed_by UUID,
    
    UNIQUE(fiscal_year_id, period_number)
);

-- Update stored_events with company_id
ALTER TABLE stored_events ADD COLUMN company_id UUID;
CREATE INDEX idx_stored_events_company ON stored_events(company_id);

-- Update audit_logs with company_id
ALTER TABLE audit_logs ADD COLUMN company_id UUID;
CREATE INDEX idx_audit_logs_company ON audit_logs(company_id);
```

- [ ] Create migration for compliance tables
- [ ] Run migration

**Verification:**
```bash
php artisan migrate
php artisan migrate:status
# Verify all migrations completed successfully
```

---

## 0.2 Data Migration

### 0.2.1 Migrate Tenants to Companies

For each existing tenant, create a corresponding company:

```php
// Migration script
class MigrateTenantDataToCompanies extends Migration
{
    public function up()
    {
        $tenants = DB::table('tenants')->get();
        
        foreach ($tenants as $tenant) {
            // 1. Create company from tenant data
            $companyId = Str::uuid();
            
            DB::table('companies')->insert([
                'id' => $companyId,
                'tenant_id' => $tenant->id,
                'name' => $tenant->name,
                'legal_name' => $tenant->legal_name ?? $tenant->name,
                'country_code' => $tenant->country_code ?? 'TN',
                'tax_id' => $tenant->tax_id,
                'email' => $tenant->email,
                'phone' => $tenant->phone,
                'address_street' => $tenant->address_street,
                'address_city' => $tenant->address_city,
                'address_postal_code' => $tenant->address_postal_code,
                'currency' => $tenant->currency ?? 'TND',
                'locale' => $tenant->locale ?? 'fr-TN',
                'timezone' => $tenant->timezone ?? 'Africa/Tunis',
                'verification_tier' => $tenant->verification_tier ?? 'basic',
                'verification_status' => $tenant->verification_status ?? 'pending',
                'invoice_prefix' => $tenant->invoice_prefix ?? 'FAC-',
                'invoice_next_number' => $tenant->invoice_next_number ?? 1,
                // ... other sequences
                'created_at' => $tenant->created_at,
                'updated_at' => now(),
            ]);
            
            // 2. Create default location
            $locationId = Str::uuid();
            
            DB::table('locations')->insert([
                'id' => $locationId,
                'company_id' => $companyId,
                'name' => 'Siège principal',
                'code' => 'LOC-001',
                'type' => 'shop',
                'address_street' => $tenant->address_street,
                'address_city' => $tenant->address_city,
                'address_postal_code' => $tenant->address_postal_code,
                'address_country' => $tenant->country_code ?? 'TN',
                'is_default' => true,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            
            // 3. Create owner membership for existing users
            $users = DB::table('users')->where('tenant_id', $tenant->id)->get();
            
            foreach ($users as $index => $user) {
                DB::table('user_company_memberships')->insert([
                    'id' => Str::uuid(),
                    'user_id' => $user->id,
                    'company_id' => $companyId,
                    'role' => $index === 0 ? 'owner' : 'admin', // First user is owner
                    'is_primary' => true,
                    'status' => 'active',
                    'accepted_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
            
            // 4. Update all business records with company_id
            DB::table('partners')->where('tenant_id', $tenant->id)
                ->update(['company_id' => $companyId]);
            
            DB::table('products')->where('tenant_id', $tenant->id)
                ->update(['company_id' => $companyId]);
            
            DB::table('documents')->where('tenant_id', $tenant->id)
                ->update(['company_id' => $companyId, 'location_id' => $locationId]);
            
            DB::table('accounts')->where('tenant_id', $tenant->id)
                ->update(['company_id' => $companyId]);
            
            DB::table('journal_entries')->where('tenant_id', $tenant->id)
                ->update(['company_id' => $companyId]);
            
            DB::table('payments')->where('tenant_id', $tenant->id)
                ->update(['company_id' => $companyId]);
            
            DB::table('payment_methods')->where('tenant_id', $tenant->id)
                ->update(['company_id' => $companyId]);
            
            DB::table('payment_repositories')->where('tenant_id', $tenant->id)
                ->update(['company_id' => $companyId, 'location_id' => $locationId]);
            
            DB::table('payment_instruments')->where('tenant_id', $tenant->id)
                ->update(['company_id' => $companyId]);
            
            // 5. Update stock tables with location_id
            DB::table('stock_levels')->where('tenant_id', $tenant->id)
                ->update(['location_id' => $locationId]);
            
            DB::table('stock_movements')->where('tenant_id', $tenant->id)
                ->update(['location_id' => $locationId]);
            
            // 6. Update compliance tables
            DB::table('stored_events')->where('tenant_id', $tenant->id)
                ->update(['company_id' => $companyId]);
            
            DB::table('audit_logs')->where('tenant_id', $tenant->id)
                ->update(['company_id' => $companyId]);
            
            // 7. Initialize hash chains for company
            foreach (['invoice', 'credit_note', 'receipt'] as $docType) {
                $genesisHash = hash('sha256', $companyId . $docType . 'GENESIS');
                
                // Get last document hash if exists
                $lastDoc = DB::table('documents')
                    ->where('company_id', $companyId)
                    ->where('type', $docType)
                    ->whereNotNull('hash')
                    ->orderBy('created_at', 'desc')
                    ->first();
                
                DB::table('company_hash_chains')->insert([
                    'id' => Str::uuid(),
                    'company_id' => $companyId,
                    'document_type' => $docType,
                    'last_hash' => $lastDoc->hash ?? $genesisHash,
                    'last_sequence' => $lastDoc ? DB::table('documents')
                        ->where('company_id', $companyId)
                        ->where('type', $docType)
                        ->whereNotNull('hash')
                        ->count() : 0,
                    'last_document_id' => $lastDoc->id ?? null,
                    'updated_at' => now(),
                ]);
            }
            
            // 8. Update tenant record (remove company-specific data)
            // Split name into first/last (simple approach)
            $nameParts = explode(' ', $tenant->name, 2);
            
            DB::table('tenants')->where('id', $tenant->id)->update([
                'first_name' => $nameParts[0] ?? $tenant->name,
                'last_name' => $nameParts[1] ?? '',
                'preferred_locale' => $tenant->locale ?? 'fr',
            ]);
        }
    }
}
```

- [ ] Create data migration script
- [ ] Run migration on test data first
- [ ] Verify data integrity
- [ ] Run on production data

### 0.2.2 Make Foreign Keys Required

After data migration is verified:

```sql
-- Make company_id NOT NULL on all tables
ALTER TABLE partners ALTER COLUMN company_id SET NOT NULL;
ALTER TABLE products ALTER COLUMN company_id SET NOT NULL;
ALTER TABLE documents ALTER COLUMN company_id SET NOT NULL;
ALTER TABLE accounts ALTER COLUMN company_id SET NOT NULL;
ALTER TABLE journal_entries ALTER COLUMN company_id SET NOT NULL;
ALTER TABLE payments ALTER COLUMN company_id SET NOT NULL;
ALTER TABLE payment_methods ALTER COLUMN company_id SET NOT NULL;
ALTER TABLE payment_repositories ALTER COLUMN company_id SET NOT NULL;
ALTER TABLE payment_instruments ALTER COLUMN company_id SET NOT NULL;

-- Make location_id NOT NULL on stock tables
ALTER TABLE stock_levels ALTER COLUMN location_id SET NOT NULL;
ALTER TABLE stock_movements ALTER COLUMN location_id SET NOT NULL;
```

- [ ] Create migration to make company_id NOT NULL
- [ ] Verify no NULL values exist before running
- [ ] Run migration

**Verification:**
```bash
# Check for any NULL company_id values
php artisan tinker
>>> DB::table('partners')->whereNull('company_id')->count();
# Should be 0

>>> DB::table('documents')->whereNull('company_id')->count();
# Should be 0

# etc. for all tables
```

---

## 0.3 Model Updates

### 0.3.1 Create Company Model

```php
// app/Models/Company.php
class Company extends Model
{
    use HasFactory, SoftDeletes;
    
    protected $keyType = 'string';
    public $incrementing = false;
    
    protected $fillable = [
        'tenant_id', 'name', 'legal_name', 'code', 'country_code',
        'tax_id', 'registration_number', 'vat_number', 'legal_identifiers',
        'email', 'phone', 'website',
        'address_street', 'address_street_2', 'address_city', 
        'address_state', 'address_postal_code',
        'logo_path', 'primary_color',
        'currency', 'locale', 'timezone', 'date_format',
        'fiscal_year_start_month',
        'invoice_prefix', 'invoice_next_number',
        // ... other sequences
        'verification_tier', 'verification_status',
        'compliance_profile', 'parent_company_id', 'is_headquarters',
        'status',
    ];
    
    protected $casts = [
        'legal_identifiers' => 'array',
        'is_headquarters' => 'boolean',
        'fiscal_year_start_month' => 'integer',
    ];
    
    // Relationships
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
    
    public function locations(): HasMany
    {
        return $this->hasMany(Location::class);
    }
    
    public function defaultLocation(): HasOne
    {
        return $this->hasOne(Location::class)->where('is_default', true);
    }
    
    public function memberships(): HasMany
    {
        return $this->hasMany(UserCompanyMembership::class);
    }
    
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'user_company_memberships')
            ->withPivot('role', 'is_primary', 'status', 'allowed_location_ids')
            ->wherePivot('status', 'active');
    }
    
    public function partners(): HasMany
    {
        return $this->hasMany(Partner::class);
    }
    
    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }
    
    public function documents(): HasMany
    {
        return $this->hasMany(Document::class);
    }
    
    public function accounts(): HasMany
    {
        return $this->hasMany(Account::class);
    }
    
    public function journalEntries(): HasMany
    {
        return $this->hasMany(JournalEntry::class);
    }
    
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }
    
    public function hashChains(): HasMany
    {
        return $this->hasMany(CompanyHashChain::class);
    }
    
    public function fiscalYears(): HasMany
    {
        return $this->hasMany(FiscalYear::class);
    }
    
    public function documents(): HasMany
    {
        return $this->hasMany(CompanyDocument::class);
    }
    
    public function bankAccounts(): HasMany
    {
        return $this->hasMany(CompanyBankAccount::class);
    }
    
    // Parent/child for chains
    public function parentCompany(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'parent_company_id');
    }
    
    public function childCompanies(): HasMany
    {
        return $this->hasMany(Company::class, 'parent_company_id');
    }
    
    // Sister companies (same tenant)
    public function sisterCompanies(): HasMany
    {
        return $this->tenant->companies()->where('id', '!=', $this->id);
    }
    
    // Helpers
    public function isVerified(): bool
    {
        return $this->verification_tier !== 'basic';
    }
    
    public function getCountry(): Country
    {
        return Country::find($this->country_code);
    }
    
    public function currentFiscalYear(): ?FiscalYear
    {
        return $this->fiscalYears()
            ->where('status', 'open')
            ->where('start_date', '<=', now())
            ->where('end_date', '>=', now())
            ->first();
    }
}
```

- [ ] Create Company model
- [ ] Add all relationships

### 0.3.2 Create Location Model

```php
// app/Models/Location.php
class Location extends Model
{
    use HasFactory;
    
    protected $keyType = 'string';
    public $incrementing = false;
    
    protected $fillable = [
        'company_id', 'name', 'code', 'type',
        'phone', 'email',
        'address_street', 'address_city', 'address_postal_code', 'address_country',
        'latitude', 'longitude',
        'is_default', 'is_active',
        'pos_enabled', 'receipt_header', 'receipt_footer',
    ];
    
    protected $casts = [
        'is_default' => 'boolean',
        'is_active' => 'boolean',
        'pos_enabled' => 'boolean',
        'latitude' => 'decimal:8',
        'longitude' => 'decimal:8',
    ];
    
    // Relationships
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
    
    public function stockLevels(): HasMany
    {
        return $this->hasMany(StockLevel::class);
    }
    
    public function stockMovements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }
    
    public function documents(): HasMany
    {
        return $this->hasMany(Document::class);
    }
    
    // Helpers
    public function isShop(): bool
    {
        return $this->type === 'shop';
    }
    
    public function isWarehouse(): bool
    {
        return $this->type === 'warehouse';
    }
    
    // Get stock for a product at this location
    public function getStock(Product $product): ?StockLevel
    {
        return $this->stockLevels()->where('product_id', $product->id)->first();
    }
}
```

- [ ] Create Location model
- [ ] Add relationships

### 0.3.3 Create UserCompanyMembership Model

```php
// app/Models/UserCompanyMembership.php
class UserCompanyMembership extends Model
{
    use HasFactory;
    
    protected $keyType = 'string';
    public $incrementing = false;
    
    protected $fillable = [
        'user_id', 'company_id', 'role',
        'allowed_location_ids', 'is_primary',
        'invited_by', 'invited_at', 'accepted_at',
        'status',
    ];
    
    protected $casts = [
        'allowed_location_ids' => 'array',
        'is_primary' => 'boolean',
        'invited_at' => 'datetime',
        'accepted_at' => 'datetime',
    ];
    
    // Relationships
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
    
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
    
    public function invitedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }
    
    // Helpers
    public function canAccessLocation(Location $location): bool
    {
        // NULL means all locations
        if ($this->allowed_location_ids === null) {
            return true;
        }
        
        return in_array($location->id, $this->allowed_location_ids);
    }
    
    public function isOwner(): bool
    {
        return $this->role === 'owner';
    }
    
    public function isAdmin(): bool
    {
        return in_array($this->role, ['owner', 'admin']);
    }
}
```

- [ ] Create UserCompanyMembership model

### 0.3.4 Update User Model

```php
// app/Models/User.php - Add/update these methods
class User extends Authenticatable
{
    // ... existing code ...
    
    // New relationships
    public function companyMemberships(): HasMany
    {
        return $this->hasMany(UserCompanyMembership::class);
    }
    
    public function companies(): BelongsToMany
    {
        return $this->belongsToMany(Company::class, 'user_company_memberships')
            ->withPivot('role', 'is_primary', 'status', 'allowed_location_ids')
            ->wherePivot('status', 'active');
    }
    
    public function primaryCompany(): ?Company
    {
        $membership = $this->companyMemberships()
            ->where('is_primary', true)
            ->where('status', 'active')
            ->first();
        
        return $membership?->company ?? $this->companies()->first();
    }
    
    public function switchCompany(Company $company): void
    {
        // Verify user has access
        $membership = $this->companyMemberships()
            ->where('company_id', $company->id)
            ->where('status', 'active')
            ->firstOrFail();
        
        session(['current_company_id' => $company->id]);
        session(['current_location_id' => $company->defaultLocation?->id]);
    }
    
    public function hasAccessToCompany(Company $company): bool
    {
        return $this->companyMemberships()
            ->where('company_id', $company->id)
            ->where('status', 'active')
            ->exists();
    }
    
    public function getRoleInCompany(Company $company): ?string
    {
        return $this->companyMemberships()
            ->where('company_id', $company->id)
            ->value('role');
    }
    
    // Get all tenants user has access to (via their companies)
    public function accessibleTenants(): Collection
    {
        return $this->companies->pluck('tenant')->unique('id');
    }
}
```

- [ ] Update User model with new relationships and methods

### 0.3.5 Update Tenant Model

```php
// app/Models/Tenant.php - Simplified
class Tenant extends Model
{
    use HasFactory, SoftDeletes;
    
    protected $keyType = 'string';
    public $incrementing = false;
    
    protected $fillable = [
        'first_name', 'last_name', 'email', 'phone',
        'preferred_locale', 'preferred_timezone',
        'slug', 'owner_user_id', 'status',
    ];
    
    // Relationships
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }
    
    public function companies(): HasMany
    {
        return $this->hasMany(Company::class);
    }
    
    public function subscription(): HasOne
    {
        return $this->hasOne(TenantSubscription::class);
    }
    
    // Helpers
    public function getFullName(): string
    {
        return trim("{$this->first_name} {$this->last_name}");
    }
    
    // Get all users across all companies
    public function allUsers(): Collection
    {
        return User::whereHas('companyMemberships', function ($query) {
            $query->whereIn('company_id', $this->companies->pluck('id'));
        })->get();
    }
}
```

- [ ] Update Tenant model (remove company-specific fields/methods)

### 0.3.6 Update Business Models

Add `company_id` relationship to all business models:

```php
// Trait for company-scoped models
trait BelongsToCompany
{
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
    
    // Global scope to filter by current company
    protected static function bootBelongsToCompany(): void
    {
        static::addGlobalScope('company', function (Builder $builder) {
            if ($company = currentCompany()) {
                $builder->where('company_id', $company->id);
            }
        });
        
        static::creating(function (Model $model) {
            if (!$model->company_id && $company = currentCompany()) {
                $model->company_id = $company->id;
            }
        });
    }
}

// Apply to all business models:
class Partner extends Model
{
    use BelongsToCompany;
    // ...
}

class Product extends Model
{
    use BelongsToCompany;
    // ...
}

class Document extends Model
{
    use BelongsToCompany;
    
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }
    // ...
}

class Account extends Model
{
    use BelongsToCompany;
    // ...
}

// etc.
```

- [ ] Create BelongsToCompany trait
- [ ] Update Partner model
- [ ] Update Product model
- [ ] Update Document model
- [ ] Update Account model
- [ ] Update JournalEntry model
- [ ] Update Payment model
- [ ] Update PaymentMethod model
- [ ] Update PaymentRepository model
- [ ] Update PaymentInstrument model

### 0.3.7 Update Stock Models

```php
// StockLevel now belongs to Location
class StockLevel extends Model
{
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }
    
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
    
    // Company is accessed through location
    public function getCompanyAttribute(): Company
    {
        return $this->location->company;
    }
    
    // Global scope for location
    protected static function bootStockLevel(): void
    {
        static::addGlobalScope('location', function (Builder $builder) {
            if ($location = currentLocation()) {
                $builder->where('location_id', $location->id);
            }
        });
    }
}

class StockMovement extends Model
{
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }
    
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
    
    // For transfers
    public function relatedLocation(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'related_location_id');
    }
}
```

- [ ] Update StockLevel model
- [ ] Update StockMovement model

**Verification:**
```bash
php artisan test --filter=ModelRelationshipTest
# Create tests to verify all relationships work correctly
```

---

## 0.4 Compliance Core Refactor

### 0.4.1 Hash Chain Per Company

```php
// app/Services/HashChainService.php
class HashChainService
{
    public function initializeChain(Company $company, string $documentType): void
    {
        $genesisHash = hash('sha256', $company->id . $documentType . 'GENESIS');
        
        CompanyHashChain::create([
            'company_id' => $company->id,
            'document_type' => $documentType,
            'last_hash' => $genesisHash,
            'last_sequence' => 0,
            'last_document_id' => null,
        ]);
    }
    
    public function addToChain(Document $document): void
    {
        DB::transaction(function () use ($document) {
            // Lock the chain record
            $chain = CompanyHashChain::where('company_id', $document->company_id)
                ->where('document_type', $document->type)
                ->lockForUpdate()
                ->firstOrFail();
            
            // Calculate new hash
            $newSequence = $chain->last_sequence + 1;
            $newHash = $this->calculateHash($document, $chain->last_hash);
            
            // Update document
            $document->update([
                'previous_hash' => $chain->last_hash,
                'hash' => $newHash,
                'chain_sequence' => $newSequence,
            ]);
            
            // Update chain
            $chain->update([
                'last_hash' => $newHash,
                'last_sequence' => $newSequence,
                'last_document_id' => $document->id,
            ]);
        });
    }
    
    public function calculateHash(Document $document, string $previousHash): string
    {
        $data = implode('|', [
            $document->company->tax_id,
            $document->type,
            $document->document_number,
            $document->document_date->format('Y-m-d'),
            $document->total,
            $previousHash,
        ]);
        
        return hash('sha256', $data);
    }
    
    public function verifyChain(Company $company, string $documentType): ChainVerificationResult
    {
        $chain = CompanyHashChain::where('company_id', $company->id)
            ->where('document_type', $documentType)
            ->first();
        
        if (!$chain) {
            return ChainVerificationResult::notInitialized();
        }
        
        $genesisHash = hash('sha256', $company->id . $documentType . 'GENESIS');
        $expectedHash = $genesisHash;
        
        $documents = Document::where('company_id', $company->id)
            ->where('type', $documentType)
            ->whereNotNull('hash')
            ->orderBy('chain_sequence')
            ->get();
        
        foreach ($documents as $document) {
            if ($document->previous_hash !== $expectedHash) {
                return ChainVerificationResult::broken($document, $expectedHash);
            }
            
            $calculatedHash = $this->calculateHash($document, $expectedHash);
            if ($document->hash !== $calculatedHash) {
                return ChainVerificationResult::tampered($document);
            }
            
            $expectedHash = $document->hash;
        }
        
        return ChainVerificationResult::valid();
    }
}
```

- [ ] Create/update HashChainService
- [ ] Update document posting to use new hash chain
- [ ] Create chain verification command

### 0.4.2 Document Sequence Per Company

```php
// app/Services/SequenceService.php
class SequenceService
{
    public function getNextNumber(Company $company, string $type): string
    {
        return DB::transaction(function () use ($company, $type) {
            $sequence = CompanySequence::where('company_id', $company->id)
                ->where('sequence_type', $type)
                ->lockForUpdate()
                ->first();
            
            if (!$sequence) {
                $sequence = $this->initializeSequence($company, $type);
            }
            
            $number = $sequence->current_number + 1;
            $sequence->update(['current_number' => $number]);
            
            return $sequence->prefix . str_pad($number, 6, '0', STR_PAD_LEFT);
        });
    }
    
    private function initializeSequence(Company $company, string $type): CompanySequence
    {
        $prefixField = $type . '_prefix';
        $prefix = $company->{$prefixField} ?? strtoupper(substr($type, 0, 3)) . '-';
        
        return CompanySequence::create([
            'company_id' => $company->id,
            'sequence_type' => $type,
            'prefix' => $prefix,
            'current_number' => 0,
        ]);
    }
}
```

- [ ] Create/update SequenceService
- [ ] Update all document creation to use new sequence service

### 0.4.3 Audit Trail Per Company

```php
// app/Services/AuditService.php
class AuditService
{
    public function log(
        string $action,
        Model $auditable,
        ?array $oldValues = null,
        ?array $newValues = null
    ): void {
        $company = $auditable->company ?? currentCompany();
        
        AuditLog::create([
            'company_id' => $company?->id,
            'user_id' => auth()->id(),
            'action' => $action,
            'auditable_type' => get_class($auditable),
            'auditable_id' => $auditable->id,
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
        ]);
    }
}
```

- [ ] Update AuditService for company scoping
- [ ] Update audit log queries

### 0.4.4 Event Sourcing Per Company

```php
// Update all events to include company_id
abstract class CompanyEvent extends Event
{
    public string $companyId;
    
    public function __construct(string $companyId)
    {
        $this->companyId = $companyId;
    }
}

class InvoiceCreated extends CompanyEvent
{
    public string $invoiceId;
    public array $data;
    
    public function __construct(string $companyId, string $invoiceId, array $data)
    {
        parent::__construct($companyId);
        $this->invoiceId = $invoiceId;
        $this->data = $data;
    }
}
```

- [ ] Update base event class
- [ ] Update all event classes to include company_id
- [ ] Update event store queries

**Verification:**
```bash
php artisan test --filter=ComplianceTest
# Test hash chain creation and verification
# Test sequence generation per company
# Test audit logging per company
```

---

## 0.5 API & Middleware Updates

### 0.5.1 Company Context Middleware

```php
// app/Http/Middleware/CompanyContext.php
class CompanyContext
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();
        
        if (!$user) {
            return $next($request);
        }
        
        // Get company ID from header, session, or default
        $companyId = $request->header('X-Company-Id')
            ?? session('current_company_id')
            ?? $user->primaryCompany()?->id;
        
        if (!$companyId) {
            // User has no companies - likely needs to complete onboarding
            if ($request->expectsJson()) {
                return response()->json([
                    'error' => 'no_company',
                    'message' => 'Please complete company setup',
                ], 400);
            }
            return redirect()->route('onboarding.company');
        }
        
        // Verify user has access to this company
        $membership = $user->companyMemberships()
            ->where('company_id', $companyId)
            ->where('status', 'active')
            ->with('company.defaultLocation')
            ->first();
        
        if (!$membership) {
            if ($request->expectsJson()) {
                return response()->json([
                    'error' => 'access_denied',
                    'message' => 'You do not have access to this company',
                ], 403);
            }
            return redirect()->route('dashboard');
        }
        
        // Set context
        app()->instance('currentCompany', $membership->company);
        app()->instance('currentMembership', $membership);
        
        // Set location context
        $locationId = $request->header('X-Location-Id')
            ?? session('current_location_id');
        
        if ($locationId) {
            $location = $membership->company->locations()->find($locationId);
            if ($location && $membership->canAccessLocation($location)) {
                app()->instance('currentLocation', $location);
            }
        } else {
            app()->instance('currentLocation', $membership->company->defaultLocation);
        }
        
        // Store in session for subsequent requests
        session(['current_company_id' => $companyId]);
        
        return $next($request);
    }
}

// Helper functions (in helpers.php or a service provider)
function currentCompany(): ?Company
{
    return app()->bound('currentCompany') ? app('currentCompany') : null;
}

function currentLocation(): ?Location
{
    return app()->bound('currentLocation') ? app('currentLocation') : null;
}

function currentMembership(): ?UserCompanyMembership
{
    return app()->bound('currentMembership') ? app('currentMembership') : null;
}
```

- [ ] Create CompanyContext middleware
- [ ] Create helper functions
- [ ] Register middleware in kernel

### 0.5.2 Update Route Groups

```php
// routes/api.php
Route::middleware(['auth:sanctum', 'company.context'])->group(function () {
    // All company-scoped routes
    Route::apiResource('partners', PartnerController::class);
    Route::apiResource('products', ProductController::class);
    Route::apiResource('documents', DocumentController::class);
    Route::apiResource('accounts', AccountController::class);
    Route::apiResource('locations', LocationController::class);
    // ... etc
});

// Company management routes (outside company context)
Route::middleware(['auth:sanctum'])->prefix('companies')->group(function () {
    Route::get('/', [CompanyController::class, 'index']); // List user's companies
    Route::post('/', [CompanyController::class, 'store']); // Create new company
    Route::post('/switch', [CompanyController::class, 'switch']); // Switch company
    Route::get('/current', [CompanyController::class, 'current']); // Get current company
});
```

- [ ] Update route files with company context middleware
- [ ] Create company management routes

### 0.5.3 Create Company Controller

```php
// app/Http/Controllers/CompanyController.php
class CompanyController extends Controller
{
    // List companies user has access to
    public function index(Request $request)
    {
        $companies = $request->user()->companies()
            ->with('defaultLocation')
            ->get();
        
        return CompanyResource::collection($companies);
    }
    
    // Get current company with full details
    public function current(Request $request)
    {
        $company = currentCompany();
        
        return new CompanyResource($company->load([
            'locations',
            'bankAccounts',
            'settings',
        ]));
    }
    
    // Switch to another company
    public function switch(Request $request)
    {
        $request->validate([
            'company_id' => 'required|uuid',
        ]);
        
        $company = Company::findOrFail($request->company_id);
        
        // Verify access
        if (!$request->user()->hasAccessToCompany($company)) {
            return response()->json([
                'error' => 'Access denied',
            ], 403);
        }
        
        $request->user()->switchCompany($company);
        
        return new CompanyResource($company->load('defaultLocation'));
    }
    
    // Create new company (for existing tenant)
    public function store(CreateCompanyRequest $request)
    {
        // Get user's tenant
        $tenant = $request->user()->primaryCompany()?->tenant
            ?? $request->user()->accessibleTenants()->first();
        
        if (!$tenant) {
            return response()->json([
                'error' => 'No tenant found',
            ], 400);
        }
        
        $company = DB::transaction(function () use ($request, $tenant) {
            // Create company
            $company = Company::create([
                'tenant_id' => $tenant->id,
                'name' => $request->name,
                'legal_name' => $request->legal_name,
                'country_code' => $request->country_code,
                'tax_id' => $request->tax_id,
                // ... other fields from country-specific form
                'currency' => Country::find($request->country_code)->currency_code,
                'locale' => Country::find($request->country_code)->default_locale,
                'timezone' => Country::find($request->country_code)->default_timezone,
            ]);
            
            // Create default location
            $company->locations()->create([
                'name' => __('Headquarters'),
                'code' => 'LOC-001',
                'type' => 'shop',
                'address_street' => $company->address_street,
                'address_city' => $company->address_city,
                'address_postal_code' => $company->address_postal_code,
                'address_country' => $company->country_code,
                'is_default' => true,
                'is_active' => true,
            ]);
            
            // Create owner membership
            UserCompanyMembership::create([
                'user_id' => auth()->id(),
                'company_id' => $company->id,
                'role' => 'owner',
                'is_primary' => false, // Don't change their primary
                'status' => 'active',
                'accepted_at' => now(),
            ]);
            
            // Initialize hash chains
            $hashService = app(HashChainService::class);
            foreach (['invoice', 'credit_note', 'receipt'] as $type) {
                $hashService->initializeChain($company, $type);
            }
            
            // Seed chart of accounts for country
            app(ChartOfAccountsSeeder::class)->seedForCompany($company);
            
            return $company;
        });
        
        return new CompanyResource($company->load('defaultLocation'));
    }
}
```

- [ ] Create CompanyController
- [ ] Create CreateCompanyRequest with country-specific validation
- [ ] Create CompanyResource

### 0.5.4 Create Location Controller

```php
// app/Http/Controllers/LocationController.php
class LocationController extends Controller
{
    public function index()
    {
        $locations = currentCompany()->locations()
            ->orderBy('is_default', 'desc')
            ->orderBy('name')
            ->get();
        
        return LocationResource::collection($locations);
    }
    
    public function store(CreateLocationRequest $request)
    {
        $location = currentCompany()->locations()->create($request->validated());
        
        return new LocationResource($location);
    }
    
    public function switch(Request $request)
    {
        $request->validate([
            'location_id' => 'required|uuid',
        ]);
        
        $location = currentCompany()->locations()->findOrFail($request->location_id);
        
        // Verify user can access this location
        if (!currentMembership()->canAccessLocation($location)) {
            return response()->json([
                'error' => 'Access denied to this location',
            ], 403);
        }
        
        session(['current_location_id' => $location->id]);
        app()->instance('currentLocation', $location);
        
        return new LocationResource($location);
    }
}
```

- [ ] Create LocationController
- [ ] Create LocationResource

### 0.5.5 Update Existing Controllers

All controllers need to use company context:

```php
// Example: PartnerController
class PartnerController extends Controller
{
    public function index(Request $request)
    {
        // Global scope automatically filters by company
        $partners = Partner::query()
            ->when($request->type, fn($q, $type) => $q->where('type', $type))
            ->when($request->search, fn($q, $search) => $q->where('name', 'like', "%{$search}%"))
            ->paginate();
        
        return PartnerResource::collection($partners);
    }
    
    public function store(CreatePartnerRequest $request)
    {
        // company_id is automatically set by BelongsToCompany trait
        $partner = Partner::create($request->validated());
        
        return new PartnerResource($partner);
    }
}
```

- [ ] Update PartnerController
- [ ] Update ProductController
- [ ] Update DocumentController
- [ ] Update AccountController
- [ ] Update JournalEntryController
- [ ] Update PaymentController
- [ ] Update StockController (uses location context)
- [ ] Update all other controllers

**Verification:**
```bash
php artisan test --filter=ApiTest
# Test that all endpoints respect company context
# Test that switching companies changes data scope
```

---

## 0.6 Frontend Context & Switcher

### 0.6.1 Company Context Provider

```tsx
// src/contexts/CompanyContext.tsx
interface CompanyContextType {
    currentCompany: Company | null;
    currentLocation: Location | null;
    companies: Company[];
    locations: Location[];
    isLoading: boolean;
    error: Error | null;
    switchCompany: (companyId: string) => Promise<void>;
    switchLocation: (locationId: string) => void;
    refreshCompanies: () => Promise<void>;
    canManageCompanies: boolean;
}

const CompanyContext = createContext<CompanyContextType | null>(null);

export function CompanyProvider({ children }: { children: ReactNode }) {
    const [currentCompany, setCurrentCompany] = useState<Company | null>(null);
    const [currentLocation, setCurrentLocation] = useState<Location | null>(null);
    const [companies, setCompanies] = useState<Company[]>([]);
    const [isLoading, setIsLoading] = useState(true);
    const [error, setError] = useState<Error | null>(null);
    
    const queryClient = useQueryClient();
    
    // Fetch companies on mount
    useEffect(() => {
        fetchCompanies();
    }, []);
    
    const fetchCompanies = async () => {
        try {
            setIsLoading(true);
            const response = await api.get('/companies');
            const companiesList = response.data.data;
            setCompanies(companiesList);
            
            // Get current company
            const currentResponse = await api.get('/companies/current');
            const current = currentResponse.data.data;
            setCurrentCompany(current);
            setCurrentLocation(current.default_location);
            
            // Update API headers
            api.defaults.headers['X-Company-Id'] = current.id;
        } catch (err) {
            setError(err as Error);
        } finally {
            setIsLoading(false);
        }
    };
    
    const switchCompany = async (companyId: string) => {
        try {
            const response = await api.post('/companies/switch', { company_id: companyId });
            const company = response.data.data;
            
            setCurrentCompany(company);
            setCurrentLocation(company.default_location);
            
            // Update API headers
            api.defaults.headers['X-Company-Id'] = company.id;
            delete api.defaults.headers['X-Location-Id'];
            
            // Invalidate all queries to refetch with new company context
            queryClient.invalidateQueries();
        } catch (err) {
            throw err;
        }
    };
    
    const switchLocation = (locationId: string) => {
        const location = currentCompany?.locations?.find(l => l.id === locationId);
        if (location) {
            setCurrentLocation(location);
            api.defaults.headers['X-Location-Id'] = location.id;
            
            // Invalidate stock-related queries
            queryClient.invalidateQueries({ queryKey: ['stock'] });
        }
    };
    
    const value: CompanyContextType = {
        currentCompany,
        currentLocation,
        companies,
        locations: currentCompany?.locations ?? [],
        isLoading,
        error,
        switchCompany,
        switchLocation,
        refreshCompanies: fetchCompanies,
        canManageCompanies: companies.length > 0,
    };
    
    return (
        <CompanyContext.Provider value={value}>
            {children}
        </CompanyContext.Provider>
    );
}

export function useCompany() {
    const context = useContext(CompanyContext);
    if (!context) {
        throw new Error('useCompany must be used within CompanyProvider');
    }
    return context;
}
```

- [ ] Create CompanyContext provider
- [ ] Create useCompany hook
- [ ] Wrap app with CompanyProvider

### 0.6.2 Company Switcher Component

```tsx
// src/components/organisms/CompanySwitcher.tsx
export function CompanySwitcher() {
    const { currentCompany, companies, switchCompany, isLoading } = useCompany();
    const [open, setOpen] = useState(false);
    const navigate = useNavigate();
    const { t } = useTranslation();
    
    if (isLoading || !currentCompany) {
        return <Skeleton className="w-48 h-10" />;
    }
    
    return (
        <DropdownMenu open={open} onOpenChange={setOpen}>
            <DropdownMenuTrigger asChild>
                <Button variant="ghost" className="gap-2">
                    {currentCompany.logo_path ? (
                        <img 
                            src={currentCompany.logo_path} 
                            alt="" 
                            className="w-6 h-6 rounded"
                        />
                    ) : (
                        <Building2 className="w-5 h-5" />
                    )}
                    <span className="max-w-[150px] truncate">
                        {currentCompany.name}
                    </span>
                    <ChevronDown className="w-4 h-4" />
                </Button>
            </DropdownMenuTrigger>
            
            <DropdownMenuContent align="start" className="w-64">
                <DropdownMenuLabel>{t('companies.switch')}</DropdownMenuLabel>
                <DropdownMenuSeparator />
                
                {companies.map(company => (
                    <DropdownMenuItem
                        key={company.id}
                        onClick={() => {
                            switchCompany(company.id);
                            setOpen(false);
                        }}
                        className="gap-3"
                    >
                        <div className="flex items-center gap-3 flex-1">
                            {company.logo_path ? (
                                <img 
                                    src={company.logo_path} 
                                    alt="" 
                                    className="w-8 h-8 rounded"
                                />
                            ) : (
                                <div className="w-8 h-8 rounded bg-muted flex items-center justify-center">
                                    <Building2 className="w-4 h-4" />
                                </div>
                            )}
                            <div className="flex-1 min-w-0">
                                <div className="font-medium truncate">
                                    {company.name}
                                </div>
                                <div className="text-xs text-muted-foreground flex items-center gap-1">
                                    <span>{company.country_code}</span>
                                    <span>•</span>
                                    <span>{company.currency}</span>
                                </div>
                            </div>
                        </div>
                        {company.id === currentCompany.id && (
                            <Check className="w-4 h-4 text-primary" />
                        )}
                    </DropdownMenuItem>
                ))}
                
                <DropdownMenuSeparator />
                
                <DropdownMenuItem
                    onClick={() => {
                        navigate('/companies/new');
                        setOpen(false);
                    }}
                    className="gap-2"
                >
                    <Plus className="w-4 h-4" />
                    {t('companies.add')}
                </DropdownMenuItem>
            </DropdownMenuContent>
        </DropdownMenu>
    );
}
```

- [ ] Create CompanySwitcher component
- [ ] Add to TopBar/Header

### 0.6.3 Location Selector Component

```tsx
// src/components/organisms/LocationSelector.tsx
export function LocationSelector() {
    const { currentLocation, locations, switchLocation } = useCompany();
    const { t } = useTranslation();
    
    // Don't show if only one location
    if (locations.length <= 1) {
        return null;
    }
    
    return (
        <Select
            value={currentLocation?.id}
            onValueChange={switchLocation}
        >
            <SelectTrigger className="w-48">
                <SelectValue placeholder={t('locations.select')} />
            </SelectTrigger>
            <SelectContent>
                {locations.map(location => (
                    <SelectItem key={location.id} value={location.id}>
                        <div className="flex items-center gap-2">
                            <LocationIcon type={location.type} />
                            <span>{location.name}</span>
                        </div>
                    </SelectItem>
                ))}
            </SelectContent>
        </Select>
    );
}

function LocationIcon({ type }: { type: string }) {
    switch (type) {
        case 'shop':
            return <Store className="w-4 h-4" />;
        case 'warehouse':
            return <Warehouse className="w-4 h-4" />;
        case 'office':
            return <Building className="w-4 h-4" />;
        case 'mobile':
            return <Truck className="w-4 h-4" />;
        default:
            return <MapPin className="w-4 h-4" />;
    }
}
```

- [ ] Create LocationSelector component
- [ ] Add to TopBar where relevant (stock pages)

### 0.6.4 Update TopBar Layout

```tsx
// src/components/organisms/TopBar.tsx
export function TopBar() {
    return (
        <header className="border-b h-16 flex items-center px-4 gap-4">
            {/* Company Switcher */}
            <CompanySwitcher />
            
            {/* Location Selector (shows on relevant pages) */}
            <LocationSelector />
            
            {/* Spacer */}
            <div className="flex-1" />
            
            {/* Search, notifications, user menu, etc. */}
            <GlobalSearch />
            <NotificationBell />
            <UserMenu />
        </header>
    );
}
```

- [ ] Update TopBar with company/location selectors

### 0.6.5 Add Company Page (Create New Company)

```tsx
// src/pages/companies/NewCompanyPage.tsx
export function NewCompanyPage() {
    const { t } = useTranslation();
    const navigate = useNavigate();
    const { refreshCompanies } = useCompany();
    
    const [country, setCountry] = useState<string>('');
    const [fields, setFields] = useState<OnboardingField[]>([]);
    
    // Fetch country-specific fields when country changes
    useEffect(() => {
        if (country) {
            fetchCountryFields(country);
        }
    }, [country]);
    
    const fetchCountryFields = async (countryCode: string) => {
        const response = await api.get(`/onboarding/countries/${countryCode}/fields`);
        setFields(response.data.data);
    };
    
    const onSubmit = async (data: any) => {
        try {
            await api.post('/companies', {
                ...data,
                country_code: country,
            });
            
            await refreshCompanies();
            navigate('/dashboard');
        } catch (error) {
            // Handle error
        }
    };
    
    return (
        <div className="max-w-2xl mx-auto py-8">
            <h1 className="text-2xl font-bold mb-6">
                {t('companies.create.title')}
            </h1>
            
            {/* Step 1: Country Selection */}
            <Card className="mb-6">
                <CardHeader>
                    <CardTitle>{t('companies.create.country')}</CardTitle>
                </CardHeader>
                <CardContent>
                    <CountrySelector
                        value={country}
                        onChange={setCountry}
                    />
                </CardContent>
            </Card>
            
            {/* Step 2: Company Details (country-specific) */}
            {country && fields.length > 0 && (
                <Card>
                    <CardHeader>
                        <CardTitle>{t('companies.create.details')}</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <DynamicForm
                            fields={fields}
                            onSubmit={onSubmit}
                        />
                    </CardContent>
                </Card>
            )}
        </div>
    );
}
```

- [ ] Create NewCompanyPage
- [ ] Create CountrySelector component
- [ ] Create DynamicForm component for country-specific fields
- [ ] Add route for /companies/new

**Verification:**
```bash
pnpm test --grep Company
# Manual: Switch between companies, verify data changes
# Manual: Add new company, verify it appears in switcher
```

---

## 0.7 Signup Flow Refactor

### 0.7.1 Update Registration Controller

```php
// app/Http/Controllers/Auth/RegisterController.php
class RegisterController extends Controller
{
    public function register(RegisterRequest $request)
    {
        return DB::transaction(function () use ($request) {
            // 1. Create tenant (personal info only)
            $tenant = Tenant::create([
                'first_name' => $request->first_name,
                'last_name' => $request->last_name,
                'email' => $request->email,
                'phone' => $request->phone,
                'preferred_locale' => $request->locale ?? 'fr',
                'slug' => Str::slug($request->first_name . '-' . $request->last_name . '-' . Str::random(6)),
                'status' => 'active',
            ]);
            
            // 2. Create user
            $user = User::create([
                'name' => $request->first_name . ' ' . $request->last_name,
                'email' => $request->email,
                'password' => Hash::make($request->password),
                'locale' => $request->locale ?? 'fr',
            ]);
            
            // 3. Link user as tenant owner
            $tenant->update(['owner_user_id' => $user->id]);
            
            // 4. Send verification email
            $user->sendEmailVerificationNotification();
            
            // 5. Login user
            Auth::login($user);
            
            // 6. Return with redirect to company creation
            return response()->json([
                'user' => new UserResource($user),
                'tenant' => new TenantResource($tenant),
                'next' => '/onboarding/company', // Redirect to company creation
            ]);
        });
    }
}
```

- [ ] Update RegisterController
- [ ] Create RegisterRequest with validation

### 0.7.2 Company Onboarding Controller

```php
// app/Http/Controllers/Onboarding/CompanyOnboardingController.php
class CompanyOnboardingController extends Controller
{
    // Get available countries
    public function countries()
    {
        $countries = Country::where('is_active', true)
            ->orderBy('name')
            ->get();
        
        return CountryResource::collection($countries);
    }
    
    // Get fields for a country
    public function fields(string $countryCode)
    {
        $country = Country::findOrFail($countryCode);
        
        $steps = CompanyOnboardingStep::where('country_code', $countryCode)
            ->where('is_required', true)
            ->orderBy('step_number')
            ->with('fields')
            ->get();
        
        return OnboardingStepResource::collection($steps);
    }
    
    // Create company
    public function createCompany(CreateCompanyOnboardingRequest $request)
    {
        $country = Country::findOrFail($request->country_code);
        
        // Validate country-specific fields
        $this->validateCountryFields($country, $request->all());
        
        return DB::transaction(function () use ($request, $country) {
            $tenant = auth()->user()->accessibleTenants()->first();
            
            // Create company
            $company = Company::create([
                'tenant_id' => $tenant->id,
                'name' => $request->company_name,
                'legal_name' => $request->legal_name ?? $request->company_name,
                'country_code' => $country->code,
                'tax_id' => $request->tax_id,
                'email' => $request->email,
                'phone' => $request->phone,
                'address_street' => $request->address_street,
                'address_city' => $request->address_city,
                'address_postal_code' => $request->address_postal_code,
                'currency' => $country->currency_code,
                'locale' => $country->default_locale,
                'timezone' => $country->default_timezone,
            ]);
            
            // Create default location
            $location = $company->locations()->create([
                'name' => __('Headquarters'),
                'code' => 'LOC-001',
                'type' => 'shop',
                'address_street' => $company->address_street,
                'address_city' => $company->address_city,
                'address_postal_code' => $company->address_postal_code,
                'address_country' => $company->country_code,
                'is_default' => true,
                'is_active' => true,
            ]);
            
            // Create owner membership
            UserCompanyMembership::create([
                'user_id' => auth()->id(),
                'company_id' => $company->id,
                'role' => 'owner',
                'is_primary' => true,
                'status' => 'active',
                'accepted_at' => now(),
            ]);
            
            // Initialize compliance features
            $this->initializeCompanyCompliance($company);
            
            // Seed chart of accounts
            app(ChartOfAccountsSeeder::class)->seedForCompany($company);
            
            // Switch to new company
            auth()->user()->switchCompany($company);
            
            return new CompanyResource($company->load('defaultLocation'));
        });
    }
    
    private function initializeCompanyCompliance(Company $company): void
    {
        $hashService = app(HashChainService::class);
        
        foreach (['invoice', 'credit_note', 'receipt'] as $type) {
            $hashService->initializeChain($company, $type);
        }
    }
}
```

- [ ] Create CompanyOnboardingController
- [ ] Create onboarding routes

### 0.7.3 Update Frontend Signup Flow

```tsx
// src/pages/auth/RegisterPage.tsx
export function RegisterPage() {
    const { t } = useTranslation();
    const navigate = useNavigate();
    
    const form = useForm<RegisterFormData>({
        resolver: zodResolver(registerSchema),
    });
    
    const onSubmit = async (data: RegisterFormData) => {
        try {
            const response = await api.post('/auth/register', data);
            
            // Redirect to company creation
            navigate(response.data.next);
        } catch (error) {
            // Handle error
        }
    };
    
    return (
        <div className="max-w-md mx-auto py-12">
            <h1 className="text-2xl font-bold text-center mb-8">
                {t('auth.register.title')}
            </h1>
            
            <form onSubmit={form.handleSubmit(onSubmit)} className="space-y-4">
                <div className="grid grid-cols-2 gap-4">
                    <FormField
                        control={form.control}
                        name="first_name"
                        render={({ field }) => (
                            <FormItem>
                                <FormLabel>{t('auth.register.firstName')}</FormLabel>
                                <FormControl>
                                    <Input {...field} />
                                </FormControl>
                                <FormMessage />
                            </FormItem>
                        )}
                    />
                    
                    <FormField
                        control={form.control}
                        name="last_name"
                        render={({ field }) => (
                            <FormItem>
                                <FormLabel>{t('auth.register.lastName')}</FormLabel>
                                <FormControl>
                                    <Input {...field} />
                                </FormControl>
                                <FormMessage />
                            </FormItem>
                        )}
                    />
                </div>
                
                <FormField
                    control={form.control}
                    name="email"
                    render={({ field }) => (
                        <FormItem>
                            <FormLabel>{t('auth.register.email')}</FormLabel>
                            <FormControl>
                                <Input type="email" {...field} />
                            </FormControl>
                            <FormMessage />
                        </FormItem>
                    )}
                />
                
                <FormField
                    control={form.control}
                    name="phone"
                    render={({ field }) => (
                        <FormItem>
                            <FormLabel>{t('auth.register.phone')}</FormLabel>
                            <FormControl>
                                <Input type="tel" {...field} />
                            </FormControl>
                            <FormMessage />
                        </FormItem>
                    )}
                />
                
                <FormField
                    control={form.control}
                    name="password"
                    render={({ field }) => (
                        <FormItem>
                            <FormLabel>{t('auth.register.password')}</FormLabel>
                            <FormControl>
                                <Input type="password" {...field} />
                            </FormControl>
                            <FormMessage />
                        </FormItem>
                    )}
                />
                
                <FormField
                    control={form.control}
                    name="locale"
                    render={({ field }) => (
                        <FormItem>
                            <FormLabel>{t('auth.register.language')}</FormLabel>
                            <Select value={field.value} onValueChange={field.onChange}>
                                <SelectTrigger>
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="fr">Français</SelectItem>
                                    <SelectItem value="en">English</SelectItem>
                                    <SelectItem value="ar">العربية</SelectItem>
                                </SelectContent>
                            </Select>
                            <FormMessage />
                        </FormItem>
                    )}
                />
                
                <Button type="submit" className="w-full">
                    {t('auth.register.submit')}
                </Button>
            </form>
        </div>
    );
}
```

- [ ] Update RegisterPage
- [ ] Create CompanyOnboardingPage
- [ ] Create company creation wizard components

**Verification:**
```bash
# Manual: Complete full signup flow
# 1. Register with personal info
# 2. Create first company
# 3. Verify landed on dashboard with company context
```

---

## 0.8 Testing & Verification

### 0.8.1 Data Migration Verification

```bash
# Run these checks after migration
php artisan tinker

# Check all tenants have at least one company
>>> Tenant::doesntHave('companies')->count()
# Should be 0

# Check all companies have at least one location
>>> Company::doesntHave('locations')->count()
# Should be 0

# Check all business records have company_id
>>> Partner::whereNull('company_id')->count()
>>> Product::whereNull('company_id')->count()
>>> Document::whereNull('company_id')->count()
>>> Account::whereNull('company_id')->count()
# All should be 0

# Check all stock records have location_id
>>> StockLevel::whereNull('location_id')->count()
>>> StockMovement::whereNull('location_id')->count()
# All should be 0

# Check hash chains initialized
>>> Company::doesntHave('hashChains')->count()
# Should be 0
```

- [ ] Run data verification queries
- [ ] Fix any data issues found

### 0.8.2 Functional Testing

```bash
# Run test suite
php artisan test

# Specific test groups
php artisan test --filter=CompanyTest
php artisan test --filter=LocationTest
php artisan test --filter=HashChainTest
php artisan test --filter=CompanyContextTest
```

- [ ] All existing tests pass
- [ ] New tests for company/location features pass

### 0.8.3 Manual Testing Checklist

- [ ] **Signup Flow**
  - [ ] Register new user (personal info only)
  - [ ] Redirected to company creation
  - [ ] Select country, fill country-specific fields
  - [ ] Company created with default location
  - [ ] Landed on dashboard with company context

- [ ] **Company Switching**
  - [ ] Company switcher shows in header
  - [ ] Can see all companies user has access to
  - [ ] Switching company changes all data
  - [ ] "Add Company" opens company creation

- [ ] **Location Selection**
  - [ ] Location selector shows when multiple locations
  - [ ] Switching location changes stock context
  - [ ] Stock levels are per location

- [ ] **Data Scoping**
  - [ ] Partners only show for current company
  - [ ] Products only show for current company
  - [ ] Documents only show for current company
  - [ ] Can't access other company's data via URL manipulation

- [ ] **Compliance**
  - [ ] Invoice numbers are per company
  - [ ] Hash chain is per company
  - [ ] Audit logs are per company

- [ ] **Multi-Company Tenant**
  - [ ] Create second company
  - [ ] Different country than first
  - [ ] Independent data in each company
  - [ ] Independent sequences in each company

### 0.8.4 Clean Up Deprecated Code

After verification is complete:

- [ ] Remove tenant-level fields that moved to company
- [ ] Remove deprecated tenant_ prefix on company-specific tables
- [ ] Update any remaining hard-coded tenant references
- [ ] Update API documentation
- [ ] Update CLAUDE.md with new architecture

---

## Future-Proofing: Cross-Company Features

This architecture supports future features:

### Cross-Location Inventory (Same Company)
```
User in Shop A can see: 
"Product X: 0 here, 5 in Shop B, 10 in Warehouse"

Already supported by:
- Stock per location
- User can query all locations in their company
```

### Cross-Company Inventory (Same Tenant)
```
User in Company A can see:
"Product X: 0 in Company A, 5 in Company B (sister company)"

Supported by:
- companies.tenant_id links sister companies
- Query: SELECT * FROM stock_levels WHERE location_id IN 
  (SELECT id FROM locations WHERE company_id IN 
    (SELECT id FROM companies WHERE tenant_id = ?))
```

### Inter-Company Transactions
```
Customer at Company A wants Product X from Company B.
Flow: B sells to A (inter-company invoice), A sells to customer

Supported by:
- Company B can have Company A as a partner (type: 'both')
- Standard document flow handles B → A transaction
- A marks up and sells to final customer
```

### Consolidated Reporting (Tenant Admin)
```
Tenant owner wants P&L across all companies

Supported by:
- Query journal entries across all tenant's companies
- Aggregate by account type
- Handle currency conversion if multi-currency
```

---

## Definition of Done

Phase 0 is complete when:

- [ ] All database migrations run successfully
- [ ] All existing data migrated to new structure
- [ ] No NULL company_id or location_id in required fields
- [ ] All tests pass
- [ ] Signup creates tenant (personal) then company (legal entity)
- [ ] Company switcher works in UI
- [ ] All data is scoped to current company
- [ ] Hash chains are per company
- [ ] Document sequences are per company
- [ ] Manual testing checklist complete
- [ ] No regressions in existing functionality

---

*After Phase 0 is complete, proceed to Phase 3 (features) or fix any remaining Phase 2 bugs.*
