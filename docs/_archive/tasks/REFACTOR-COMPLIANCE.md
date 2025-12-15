# Compliance Core Refactor: Tenant → Company Scope

> **This document is an addendum to REFACTOR-ARCHITECTURE.md**
> All compliance features must be scoped to Company (legal entity), not Tenant.

---

## Multi-Country Franchise/Chain Support

### The Principle

A **Tenant** can have companies in **different countries**. Each company follows its **own country's legal and fiscal system**.

```
TENANT: "Mohamed's Auto Group" (person, not legal entity)
│
├── COMPANY: Auto Service Tunis (Tunisia)
│   ├── Country: TN
│   ├── Compliance: Tunisia rules
│   ├── Tax ID format: Matricule Fiscal (7 digits + letter + 3 digits)
│   ├── Tax rates: 19%, 13%, 7%, 0%
│   ├── Currency: TND
│   ├── Document language: French/Arabic
│   └── Verification docs: Registre de Commerce, Patente
│
├── COMPANY: Auto Service Paris (France)
│   ├── Country: FR
│   ├── Compliance: French rules
│   ├── Tax ID format: SIRET (14 digits)
│   ├── Tax rates: 20%, 10%, 5.5%, 2.1%, 0%
│   ├── Currency: EUR
│   ├── Document language: French
│   ├── E-invoicing: Factur-X required
│   └── POS: NF525 certification required
│
└── COMPANY: Auto Service Milano (Italy)
    ├── Country: IT
    ├── Compliance: Italian rules
    ├── Tax ID format: Partita IVA (11 digits)
    ├── Tax rates: 22%, 10%, 5%, 4%, 0%
    ├── Currency: EUR
    ├── Document language: Italian
    └── E-invoicing: Fattura Elettronica via SDI
```

### Country Determines Everything

When a company is created with a specific country:

| Aspect | Determined By |
|--------|---------------|
| Tax ID format & validation | Country |
| Required onboarding fields | Country |
| Required verification documents | Country |
| Tax rates | Country |
| Invoice format requirements | Country |
| E-invoicing rules | Country |
| Fiscal hash requirements | Country |
| Retention periods | Country |
| Default currency | Country |
| Default locale | Country |
| POS certification (if applicable) | Country |

### Compliance Profile Resolution

```php
class ComplianceService
{
    public function getComplianceProfile(Company $company): ComplianceProfile
    {
        // Get country's compliance rules
        $countryProfile = CountryComplianceProfile::where('country_code', $company->country_code)
            ->where('is_active', true)
            ->first();
        
        // Company can have specific override (e.g., NF525 vs standard French)
        if ($company->compliance_profile) {
            return $countryProfile->getVariant($company->compliance_profile);
        }
        
        return $countryProfile->getDefault();
    }
    
    public function validateTaxId(Company $company, string $taxId): ValidationResult
    {
        $validator = TaxIdValidatorFactory::create($company->country_code);
        return $validator->validate($taxId);
    }
    
    public function getRequiredDocuments(Company $company): Collection
    {
        return DocumentType::where('country_code', $company->country_code)
            ->where('required_for_tier', '<=', $company->verification_tier)
            ->get();
    }
}
```

### Tax Rates Per Company's Country

```php
class TaxRateService
{
    public function getApplicableRates(Company $company): Collection
    {
        // Get rates for the company's country, not tenant's country
        return CountryTaxRate::where('country_code', $company->country_code)
            ->where('is_active', true)
            ->whereDate('effective_from', '<=', now())
            ->where(function ($q) {
                $q->whereNull('effective_until')
                  ->orWhereDate('effective_until', '>=', now());
            })
            ->get();
    }
}
```

---

## Why Compliance Must Be Company-Scoped

A **Tenant** is a subscription/account — it's a billing concept.
A **Company** is a legal entity — it's what the law cares about.

Tax authorities audit **companies**, not subscription accounts. Therefore:
- Hash chains must be per company
- Audit trails must be per company
- Document sequences must be per company
- Fiscal years must be per company
- Retention policies apply per company

---

## 1. Hash Chain Refactor

### Current (Incorrect) Schema
```sql
documents
├── tenant_id
├── hash
├── previous_hash  -- Links to previous document in TENANT
└── ...
```

### Correct Schema
```sql
documents
├── company_id
├── hash
├── previous_hash  -- Links to previous document in COMPANY
├── chain_sequence -- Sequence number within company's chain
└── ...

-- Separate table to track chain state per company
company_hash_chains
├── company_id (PK)
├── document_type  -- 'invoice', 'credit_note', 'receipt'
├── last_hash
├── last_sequence
├── last_document_id
├── updated_at
```

### Hash Calculation Must Include Company

```php
// WRONG: Hash without company context
$hash = hash('sha256', $documentNumber . $date . $total . $previousHash);

// CORRECT: Hash includes company identifier
$hash = hash('sha256', 
    $company->tax_id .        // Legal entity identifier
    $documentType .
    $documentNumber .
    $date .
    $total .
    $previousHash
);
```

### Chain Initialization Per Company

When a new company is created:
```php
// Initialize hash chain for each document type
foreach (['invoice', 'credit_note', 'receipt'] as $type) {
    CompanyHashChain::create([
        'company_id' => $company->id,
        'document_type' => $type,
        'last_hash' => hash('sha256', $company->id . $type . 'GENESIS'),
        'last_sequence' => 0,
        'last_document_id' => null,
    ]);
}
```

### Chain Verification Per Company

```php
class HashChainVerificationService
{
    public function verifyCompanyChain(Company $company, string $documentType): ChainVerificationResult
    {
        $documents = Document::where('company_id', $company->id)
            ->where('type', $documentType)
            ->where('status', 'posted')
            ->orderBy('chain_sequence')
            ->get();
        
        $expectedHash = hash('sha256', $company->id . $documentType . 'GENESIS');
        
        foreach ($documents as $document) {
            // Verify previous hash matches expected
            if ($document->previous_hash !== $expectedHash) {
                return ChainVerificationResult::broken($document, $expectedHash);
            }
            
            // Verify document's own hash is correct
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

---

## 2. Event Sourcing Refactor

### Current (Incorrect) Event Structure
```php
class InvoiceCreated extends Event
{
    public string $tenantId;
    public string $invoiceId;
    // ...
}
```

### Correct Event Structure
```php
class InvoiceCreated extends Event
{
    public string $companyId;    // Primary scope
    public string $invoiceId;
    public ?string $locationId;  // Optional, for POS
    // ...
    
    public function getAggregateId(): string
    {
        return $this->companyId; // Events aggregate by company
    }
}
```

### Event Store Schema

```sql
-- Events table with company partitioning
stored_events
├── id
├── company_id  -- CRITICAL: Added for company scoping
├── aggregate_id
├── aggregate_type
├── event_type
├── payload (JSONB)
├── metadata (JSONB)
│   ├── user_id
│   ├── location_id
│   ├── ip_address
│   └── user_agent
├── created_at
├── INDEX (company_id, aggregate_type, aggregate_id)
├── INDEX (company_id, created_at)

-- Event sequence per company (for ordering)
company_event_sequences
├── company_id (PK)
├── last_sequence BIGINT
├── updated_at
```

### Projections Per Company

```php
// Projector that builds read models per company
class InvoiceSummaryProjector extends Projector
{
    public function onInvoiceCreated(InvoiceCreated $event): void
    {
        // Read model is per company
        InvoiceSummary::updateOrCreate(
            ['company_id' => $event->companyId, 'month' => $event->month],
            [
                'count' => DB::raw('count + 1'),
                'total' => DB::raw("total + {$event->total}"),
            ]
        );
    }
}
```

---

## 3. Audit Trail Refactor

### Audit Log Schema

```sql
audit_logs
├── id
├── company_id  -- CRITICAL: Company scope
├── user_id
├── action  -- 'create', 'update', 'delete', 'post', 'void'
├── auditable_type  -- 'Document', 'Payment', 'JournalEntry'
├── auditable_id
├── old_values (JSONB)
├── new_values (JSONB)
├── ip_address
├── user_agent
├── created_at
├── INDEX (company_id, created_at)
├── INDEX (company_id, auditable_type, auditable_id)
```

### Audit Log Service

```php
class AuditService
{
    public function log(
        Company $company,
        User $user,
        string $action,
        Model $auditable,
        ?array $oldValues = null,
        ?array $newValues = null
    ): void {
        AuditLog::create([
            'company_id' => $company->id,
            'user_id' => $user->id,
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

### Audit Trait for Models

```php
trait Auditable
{
    public static function bootAuditable(): void
    {
        static::created(function (Model $model) {
            app(AuditService::class)->log(
                $model->company,
                auth()->user(),
                'create',
                $model,
                null,
                $model->getAttributes()
            );
        });
        
        static::updated(function (Model $model) {
            app(AuditService::class)->log(
                $model->company,
                auth()->user(),
                'update',
                $model,
                $model->getOriginal(),
                $model->getChanges()
            );
        });
        
        // ... delete, etc.
    }
    
    // Every auditable model must belong to a company
    abstract public function company(): BelongsTo;
}
```

---

## 4. Document Retention Refactor

### Retention Policy Per Company

```sql
-- Retention is tracked per company
company_retention_policies
├── id
├── company_id
├── document_type  -- 'invoice', 'journal_entry', 'payment'
├── retention_years  -- 6 for Tunisia, 10 for France
├── archive_after_years  -- When to move to cold storage
├── created_at

-- Archive tracking per company
archived_documents
├── id
├── company_id
├── document_type
├── document_id
├── archive_path  -- S3 path with Object Lock
├── archived_at
├── retention_until  -- Calculated: archived_at + retention_years
├── archive_hash  -- For verification
```

### Archive Service

```php
class DocumentArchiveService
{
    public function archiveCompanyDocuments(Company $company, Carbon $olderThan): void
    {
        $policy = $company->retentionPolicy;
        
        $documents = Document::where('company_id', $company->id)
            ->where('posted_at', '<', $olderThan)
            ->whereNotArchived()
            ->get();
            
        foreach ($documents as $document) {
            // Archive to S3 with Object Lock
            $path = $this->archiveToS3($document);
            
            // Record archive with retention date
            ArchivedDocument::create([
                'company_id' => $company->id,
                'document_type' => $document->type,
                'document_id' => $document->id,
                'archive_path' => $path,
                'archived_at' => now(),
                'retention_until' => now()->addYears($policy->retention_years),
                'archive_hash' => $document->hash,
            ]);
        }
    }
}
```

---

## 5. Sequential Numbering Per Company

### Sequence Table

```sql
-- Document sequences per company per type
company_sequences
├── company_id
├── sequence_type  -- 'invoice', 'quote', 'sales_order', 'purchase_order', 'payment'
├── prefix  -- 'INV-', 'QUO-'
├── current_number
├── fiscal_year  -- Optional, for yearly reset
├── updated_at
├── PRIMARY KEY (company_id, sequence_type, fiscal_year)
```

### Sequence Service with Locking

```php
class SequenceService
{
    public function getNextNumber(Company $company, string $type): string
    {
        return DB::transaction(function () use ($company, $type) {
            // Pessimistic lock to prevent race conditions
            $sequence = CompanySequence::where('company_id', $company->id)
                ->where('sequence_type', $type)
                ->where('fiscal_year', $company->currentFiscalYear())
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
        $settings = $company->settings;
        
        return CompanySequence::create([
            'company_id' => $company->id,
            'sequence_type' => $type,
            'prefix' => $settings->getPrefix($type),
            'current_number' => 0,
            'fiscal_year' => $company->currentFiscalYear(),
        ]);
    }
}
```

---

## 6. Tax Rates: Country + Company Override

### Schema

```sql
-- Country-level tax rates (defaults)
country_tax_rates
├── id
├── country_code
├── name  -- 'Standard', 'Reduced', 'Zero'
├── rate  -- 19.00, 13.00, 7.00, 0.00
├── applies_to  -- 'goods', 'services', 'both'
├── is_default
├── is_active
├── effective_from  -- For rate changes over time
├── effective_until

-- Company-level overrides (optional)
company_tax_rates
├── id
├── company_id
├── country_tax_rate_id  -- NULL if custom
├── name
├── rate
├── applies_to
├── is_default
├── is_active
```

### Tax Rate Resolution

```php
class TaxRateService
{
    public function getApplicableRates(Company $company): Collection
    {
        // Company overrides take precedence
        $companyRates = $company->taxRates()->where('is_active', true)->get();
        
        if ($companyRates->isNotEmpty()) {
            return $companyRates;
        }
        
        // Fall back to country defaults
        return CountryTaxRate::where('country_code', $company->address_country)
            ->where('is_active', true)
            ->whereDate('effective_from', '<=', now())
            ->where(function ($q) {
                $q->whereNull('effective_until')
                  ->orWhereDate('effective_until', '>=', now());
            })
            ->get();
    }
    
    public function getDefaultRate(Company $company, string $appliesTo = 'both'): ?TaxRate
    {
        return $this->getApplicableRates($company)
            ->where('is_default', true)
            ->whereIn('applies_to', [$appliesTo, 'both'])
            ->first();
    }
}
```

---

## 7. NF525 Considerations (Future - France)

NF525 certification applies to **cash registers**, which map to our **Location** concept.

### Future Schema (Not For Current Refactor)

```sql
-- POS terminal registration (NF525)
pos_terminals
├── id
├── location_id  -- Cash register at this location
├── terminal_code  -- Unique identifier
├── certification_id  -- NF525 certificate number
├── is_active
├── last_z_report_at  -- Last daily closure
├── last_z_report_number

-- Z-Reports (daily closures) per terminal
pos_z_reports
├── id
├── pos_terminal_id
├── location_id
├── report_number
├── report_date
├── opening_balance
├── closing_balance
├── total_sales
├── total_refunds
├── transaction_count
├── hash
├── previous_hash
├── generated_at
├── generated_by
```

**For now:** Build architecture that doesn't preclude this. Stock and POS transactions are location-scoped, which is correct.

---

## 8. Factur-X Readiness (Future - France)

Factur-X e-invoicing is per **company** (legal entity with SIRET).

### Schema Additions (Future)

```sql
-- Add to companies table
companies
├── ...existing fields...
├── facturx_enabled BOOLEAN DEFAULT false
├── siret VARCHAR(14)  -- French company ID
├── siren VARCHAR(9)   -- First 9 digits of SIRET
├── ape_code VARCHAR(6)  -- Activity code
├── peppol_id VARCHAR(50)  -- For EU e-invoicing network
```

**For now:** The company-scoped document model supports this. Just need to add fields later.

---

## 9. Company Closure / Sale Handling

When a company is closed or sold:

```php
class CompanyClosureService
{
    public function closeCompany(Company $company, Carbon $closureDate): void
    {
        DB::transaction(function () use ($company, $closureDate) {
            // 1. Verify all documents are finalized
            $this->verifyNoOpenDocuments($company);
            
            // 2. Close current fiscal period and year
            $this->closeFiscalPeriods($company, $closureDate);
            
            // 3. Generate final hash chain verification
            $verification = $this->verifyAllHashChains($company);
            
            // 4. Create closure certificate
            $certificate = ClosureCertificate::create([
                'company_id' => $company->id,
                'closure_date' => $closureDate,
                'final_invoice_number' => $company->invoices()->max('document_number'),
                'chain_verification_hash' => $verification->hash,
                'chain_verified' => $verification->isValid,
            ]);
            
            // 5. Archive all documents
            $this->archiveAllDocuments($company);
            
            // 6. Mark company as closed
            $company->update([
                'status' => 'closed',
                'closed_at' => $closureDate,
            ]);
            
            // 7. Revoke user access (except for read-only archive access)
            $this->revokeUserAccess($company);
        });
    }
}
```

---

## Migration Checklist for Compliance

### Phase 1: Hash Chain Migration
- [ ] Add `company_id` to documents table
- [ ] Add `chain_sequence` to documents table
- [ ] Create `company_hash_chains` table
- [ ] Migrate existing documents:
  - For each tenant, create company from tenant data
  - Assign all documents to the new company
  - Recalculate chain_sequence per company
  - Verify existing hashes still valid
  - Initialize company_hash_chains with last values

### Phase 2: Event Sourcing Migration
- [ ] Add `company_id` to stored_events table
- [ ] Create index on (company_id, created_at)
- [ ] Update all event classes to include company_id
- [ ] Update projectors to scope by company
- [ ] Backfill company_id on existing events (from aggregate data)

### Phase 3: Audit Trail Migration
- [ ] Add `company_id` to audit_logs table
- [ ] Create index on (company_id, created_at)
- [ ] Backfill company_id on existing logs
- [ ] Update AuditService to require company

### Phase 4: Sequences Migration
- [ ] Create `company_sequences` table
- [ ] Migrate current sequence values from companies/tenants table
- [ ] Update SequenceService to use new table

### Phase 5: Tax Rates Migration
- [ ] Create `country_tax_rates` table
- [ ] Create `company_tax_rates` table
- [ ] Migrate existing tax rates to country level
- [ ] Update TaxRateService

### Phase 6: Verification
- [ ] Run hash chain verification for all companies
- [ ] Verify audit logs are company-scoped
- [ ] Verify sequences are independent per company
- [ ] Test document creation in multi-company tenant

---

## Summary

| Component | Change Required | Complexity |
|-----------|-----------------|------------|
| Hash Chains | Scope to company, independent chains | HIGH |
| Event Sourcing | Add company_id, update all events | HIGH |
| Audit Trail | Scope to company | MEDIUM |
| Sequences | Already per company in main refactor | LOW |
| Tax Rates | Add country level + company override | MEDIUM |
| Retention | Track per company | MEDIUM |
| NF525 | Location-scoped (future) | N/A now |
| Factur-X | Company-scoped (future) | N/A now |

**Total additional effort for compliance refactor: ~8-12 hours on top of main architecture refactor**

---

*This compliance refactor must be done together with the main architecture refactor. They cannot be separated.*
