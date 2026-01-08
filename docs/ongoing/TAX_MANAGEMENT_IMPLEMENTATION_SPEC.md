# Tax Management Module - Implementation Specification

**Version:** 1.0  
**Date:** 2025-01-01  
**Status:** Ready for Implementation

---

## Executive Summary

This specification defines the enhancement of the tax management system to support:
- Unified tax configuration (merging stamp duties into tax configurations)
- Multiple taxes with stacking/compound calculation
- Document type applicability per tax
- Partner tax exemption status with certificate tracking
- Transaction-level tax confirmation with exemption notices
- Tunisia presets with flexibility for any country

### Design Principles

1. **User control**: System provides hints and defaults, user makes final decisions
2. **Immutability**: Finalized documents store actual values, not references
3. **Simplicity**: Only two company tax statuses (REGISTERED / NON_REGISTERED)
4. **Flexibility**: Support any country's tax structure through configuration

---

## Part 1: Current State Verification

Before implementing, verify the current state matches our understanding.

### 1.1 Run These Verification Commands

```bash
# Check tax_configurations table structure
php artisan tinker --execute="Schema::getColumnListing('tax_configurations')"

# Check stamp_duty_rules table structure  
php artisan tinker --execute="Schema::getColumnListing('stamp_duty_rules')"

# Check partners table for existing tax fields
php artisan tinker --execute="Schema::getColumnListing('partners')"

# Check documents table for tax fields
php artisan tinker --execute="Schema::getColumnListing('documents')"

# Check document_lines table
php artisan tinker --execute="Schema::getColumnListing('document_lines')"

# Check if document_tax_details table exists
php artisan tinker --execute="Schema::hasTable('document_tax_details')"

# List existing TaxType enum values
grep -r "enum TaxType" app/

# List existing DocumentType enum values
grep -r "enum DocumentType" app/

# Check TaxCalculationService location and methods
find app/ -name "*TaxCalculation*" -type f

# Check StampDutyService location
find app/ -name "*StampDuty*" -type f
```

### 1.2 Expected Current State

Based on prior analysis, we expect:

**Tables that exist:**
- `tax_configurations` - basic structure with percentage/fixed support
- `stamp_duty_rules` - separate table for stamp duties
- `country_tax_rates` - reference rates by country
- `document_tax_details` - schema exists but may not be populated
- `companies` - has `default_tax_rate` field
- `partners` - has `vat_number` field only

**What's missing (to be added):**
- `tax_configurations`: stacking fields, document type applicability
- `partners`: tax_status, exemption fields
- `documents`: tax_mention field
- Proper immutable storage of tax details on finalization

### 1.3 Document Current Findings

Create a file `storage/app/tax_implementation_verification.json` with:
```json
{
  "verified_at": "timestamp",
  "tax_configurations_columns": [],
  "stamp_duty_rules_columns": [],
  "partners_columns": [],
  "documents_columns": [],
  "document_lines_columns": [],
  "document_tax_details_exists": true/false,
  "enums": {
    "TaxType": [],
    "DocumentType": []
  },
  "services": {
    "TaxCalculationService": "path",
    "StampDutyService": "path"
  },
  "discrepancies": []
}
```

---

## Part 2: Target State Specification

### 2.1 Database Schema Changes

#### 2.1.1 Extend `tax_configurations` Table

**Migration:** `database/migrations/YYYY_MM_DD_HHMMSS_enhance_tax_configurations_table.php`

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tax_configurations', function (Blueprint $table) {
            // Stacking support
            $table->integer('sequence_order')->default(1)->after('is_active');
            $table->string('stacks_on', 50)->default('BASE_AMOUNT')->after('sequence_order');
            // Values: 'BASE_AMOUNT', 'SUBTOTAL_PLUS_PREVIOUS_TAXES'
            
            // Document type applicability
            $table->jsonb('applicable_document_types')->default('[]')->after('stacks_on');
            
            // Flag to identify migrated stamp duties
            $table->boolean('is_stamp_duty')->default(false)->after('applicable_document_types');
        });
    }

    public function down(): void
    {
        Schema::table('tax_configurations', function (Blueprint $table) {
            $table->dropColumn([
                'sequence_order',
                'stacks_on',
                'applicable_document_types', 
                'is_stamp_duty'
            ]);
        });
    }
};
```

#### 2.1.2 Extend `partners` Table

**Migration:** `database/migrations/YYYY_MM_DD_HHMMSS_add_tax_fields_to_partners.php`

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('partners', function (Blueprint $table) {
            $table->string('tax_status', 50)->default('REGISTERED')->after('vat_number');
            // Values: 'REGISTERED', 'NON_REGISTERED', 'EXEMPT'
            
            $table->text('tax_exemption_reason')->nullable()->after('tax_status');
            
            $table->uuid('tax_exemption_certificate_media_id')->nullable()->after('tax_exemption_reason');
            $table->foreign('tax_exemption_certificate_media_id')
                ->references('id')
                ->on('media')
                ->nullOnDelete();
            
            $table->date('tax_exemption_valid_until')->nullable()->after('tax_exemption_certificate_media_id');
        });
    }

    public function down(): void
    {
        Schema::table('partners', function (Blueprint $table) {
            $table->dropForeign(['tax_exemption_certificate_media_id']);
            $table->dropColumn([
                'tax_status',
                'tax_exemption_reason',
                'tax_exemption_certificate_media_id',
                'tax_exemption_valid_until'
            ]);
        });
    }
};
```

#### 2.1.3 Extend `companies` Table

**Migration:** `database/migrations/YYYY_MM_DD_HHMMSS_add_tax_status_to_companies.php`

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->string('tax_status', 50)->default('REGISTERED')->after('default_tax_rate');
            // Values: 'REGISTERED', 'NON_REGISTERED'
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('tax_status');
        });
    }
};
```

#### 2.1.4 Extend `documents` Table

**Migration:** `database/migrations/YYYY_MM_DD_HHMMSS_add_tax_mention_to_documents.php`

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->text('tax_mention')->nullable()->after('stamp_duty_amount');
            // Legal text like "Exonéré TVA - Art. 293 B du CGI" or "En suspension de TVA"
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropColumn('tax_mention');
        });
    }
};
```

#### 2.1.5 Ensure `document_tax_details` Has Correct Structure

**Migration:** `database/migrations/YYYY_MM_DD_HHMMSS_ensure_document_tax_details_structure.php`

This migration should verify/create the correct immutable structure:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // If table doesn't exist, create it
        if (!Schema::hasTable('document_tax_details')) {
            Schema::create('document_tax_details', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('document_id');
                $table->foreign('document_id')->references('id')->on('documents')->cascadeOnDelete();
                
                $table->integer('sequence_order');
                $table->string('tax_code', 50);
                $table->string('tax_name', 100);
                $table->string('tax_type', 20); // 'PERCENTAGE' or 'FIXED_AMOUNT'
                $table->decimal('tax_rate', 5, 2)->default(0); // e.g., 19.00
                $table->decimal('tax_fixed_amount', 10, 3)->default(0); // e.g., 1.000
                $table->decimal('tax_base', 15, 3); // Amount tax was calculated on
                $table->decimal('tax_amount', 15, 3); // Final calculated amount
                
                $table->timestamp('created_at');
                // NO updated_at - these records are immutable
                
                $table->index(['document_id', 'sequence_order']);
            });
        } else {
            // If exists, ensure all columns are present
            Schema::table('document_tax_details', function (Blueprint $table) {
                if (!Schema::hasColumn('document_tax_details', 'sequence_order')) {
                    $table->integer('sequence_order')->after('document_id');
                }
                if (!Schema::hasColumn('document_tax_details', 'tax_code')) {
                    $table->string('tax_code', 50)->after('sequence_order');
                }
                if (!Schema::hasColumn('document_tax_details', 'tax_fixed_amount')) {
                    $table->decimal('tax_fixed_amount', 10, 3)->default(0)->after('tax_rate');
                }
            });
        }
    }

    public function down(): void
    {
        // Only drop if we created it
        // Schema::dropIfExists('document_tax_details');
    }
};
```

#### 2.1.6 Migrate Stamp Duty Rules to Tax Configurations

**Migration:** `database/migrations/YYYY_MM_DD_HHMMSS_migrate_stamp_duties_to_tax_configurations.php`

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        // Get all stamp duty rules
        $stampDutyRules = DB::table('stamp_duty_rules')
            ->where('is_active', true)
            ->get();
        
        foreach ($stampDutyRules as $rule) {
            // Check if already migrated
            $exists = DB::table('tax_configurations')
                ->where('country_code', $rule->country_code)
                ->where('is_stamp_duty', true)
                ->whereJsonContains('applicable_document_types', $rule->document_type)
                ->exists();
            
            if (!$exists) {
                DB::table('tax_configurations')->insert([
                    'id' => Str::uuid(),
                    'country_code' => $rule->country_code,
                    'name' => 'Timbre Fiscal - ' . $this->getDocumentTypeLabel($rule->document_type),
                    'code' => 'STAMP_' . $rule->document_type,
                    'tax_type' => 'FIXED_AMOUNT',
                    'percentage_rate' => null,
                    'fixed_amount' => $rule->stamp_amount,
                    'applies_to' => 'DOCUMENT_TOTAL',
                    'is_default' => false,
                    'is_active' => true,
                    'sequence_order' => 99, // Stamps apply last
                    'stacks_on' => 'BASE_AMOUNT',
                    'applicable_document_types' => json_encode([$rule->document_type]),
                    'is_stamp_duty' => true,
                    'metadata' => $rule->metadata,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        // Remove migrated stamp duties
        DB::table('tax_configurations')
            ->where('is_stamp_duty', true)
            ->delete();
    }
    
    private function getDocumentTypeLabel(string $type): string
    {
        return match($type) {
            'TAX_INVOICE' => 'Facture',
            'FISCAL_RECEIPT' => 'Ticket',
            'CREDIT_NOTE' => 'Avoir',
            default => $type,
        };
    }
};
```

---

### 2.2 Enums

#### 2.2.1 CompanyTaxStatus Enum

**File:** `app/Modules/Taxation/Domain/Enums/CompanyTaxStatus.php`

```php
<?php

declare(strict_types=1);

namespace App\Modules\Taxation\Domain\Enums;

enum CompanyTaxStatus: string
{
    case REGISTERED = 'REGISTERED';         // Assujetti - can recover VAT on purchases
    case NON_REGISTERED = 'NON_REGISTERED'; // Non-assujetti/Forfaitaire - cannot recover VAT
    
    public function label(): string
    {
        return match($this) {
            self::REGISTERED => 'VAT Registered (Assujetti)',
            self::NON_REGISTERED => 'Not VAT Registered (Non-Assujetti)',
        };
    }
    
    public function canRecoverVAT(): bool
    {
        return $this === self::REGISTERED;
    }
}
```

#### 2.2.2 PartnerTaxStatus Enum

**File:** `app/Modules/Taxation/Domain/Enums/PartnerTaxStatus.php`

```php
<?php

declare(strict_types=1);

namespace App\Modules\Taxation\Domain\Enums;

enum PartnerTaxStatus: string
{
    case REGISTERED = 'REGISTERED';         // Normal - VAT applies
    case NON_REGISTERED = 'NON_REGISTERED'; // Normal - VAT applies (they can't recover)
    case EXEMPT = 'EXEMPT';                 // May be exempt - needs certificate verification
    
    public function label(): string
    {
        return match($this) {
            self::REGISTERED => 'VAT Registered',
            self::NON_REGISTERED => 'Not VAT Registered',
            self::EXEMPT => 'Tax Exempt',
        };
    }
    
    public function requiresExemptionCertificate(): bool
    {
        return $this === self::EXEMPT;
    }
}
```

#### 2.2.3 StackingBehavior Enum

**File:** `app/Modules/Taxation/Domain/Enums/StackingBehavior.php`

```php
<?php

declare(strict_types=1);

namespace App\Modules\Taxation\Domain\Enums;

enum StackingBehavior: string
{
    case BASE_AMOUNT = 'BASE_AMOUNT';
    // Tax calculated on original subtotal only
    
    case SUBTOTAL_PLUS_PREVIOUS_TAXES = 'SUBTOTAL_PLUS_PREVIOUS_TAXES';
    // Compound: Tax calculated on subtotal + all previous taxes (for future use)
    
    public function label(): string
    {
        return match($this) {
            self::BASE_AMOUNT => 'Calculate on base amount only',
            self::SUBTOTAL_PLUS_PREVIOUS_TAXES => 'Calculate on subtotal + previous taxes (compound)',
        };
    }
}
```

---

### 2.3 Model Updates

#### 2.3.1 TaxConfiguration Model

**File:** `app/Modules/Taxation/Domain/Entities/TaxConfiguration.php`

Add/update these attributes and methods:

```php
<?php

// Add to existing model

use App\Modules\Taxation\Domain\Enums\StackingBehavior;
use App\Modules\Taxation\Domain\Enums\TaxType;
use App\Modules\Taxation\Domain\Enums\TaxApplicationLevel;

// Add to $casts array:
protected $casts = [
    // ... existing casts
    'tax_type' => TaxType::class,
    'applies_to' => TaxApplicationLevel::class,
    'stacks_on' => StackingBehavior::class,
    'applicable_document_types' => 'array',
    'is_stamp_duty' => 'boolean',
    'sequence_order' => 'integer',
];

// Add these methods:

/**
 * Scope to filter taxes applicable to a specific document type
 */
public function scopeForDocumentType($query, string $documentType)
{
    return $query->whereJsonContains('applicable_document_types', $documentType);
}

/**
 * Scope to order by sequence
 */
public function scopeOrdered($query)
{
    return $query->orderBy('sequence_order', 'asc');
}

/**
 * Check if this tax applies to a document type
 */
public function appliesToDocumentType(string $documentType): bool
{
    $types = $this->applicable_document_types ?? [];
    return empty($types) || in_array($documentType, $types);
}

/**
 * Calculate tax amount
 */
public function calculateAmount(string $base, ?string $previousTaxesTotal = null): string
{
    if ($this->tax_type === TaxType::FIXED_AMOUNT) {
        return $this->fixed_amount ?? '0';
    }
    
    // Percentage calculation
    $calculationBase = $base;
    
    if ($this->stacks_on === StackingBehavior::SUBTOTAL_PLUS_PREVIOUS_TAXES && $previousTaxesTotal) {
        $calculationBase = bcadd($base, $previousTaxesTotal, 3);
    }
    
    $rate = bcdiv($this->percentage_rate ?? '0', '100', 6);
    return bcmul($calculationBase, $rate, 3);
}
```

#### 2.3.2 Partner Model

**File:** `app/Modules/Partner/Domain/Partner.php`

Add these attributes and methods:

```php
<?php

// Add imports
use App\Modules\Taxation\Domain\Enums\PartnerTaxStatus;
use App\Modules\Media\Domain\Media;

// Add to $casts:
protected $casts = [
    // ... existing
    'tax_status' => PartnerTaxStatus::class,
    'tax_exemption_valid_until' => 'date',
];

// Add relationship:
public function taxExemptionCertificate()
{
    return $this->belongsTo(Media::class, 'tax_exemption_certificate_media_id');
}

// Add methods:

/**
 * Check if partner has valid tax exemption
 */
public function hasValidTaxExemption(): bool
{
    if ($this->tax_status !== PartnerTaxStatus::EXEMPT) {
        return false;
    }
    
    // Must have certificate
    if (!$this->tax_exemption_certificate_media_id) {
        return false;
    }
    
    // Certificate must not be expired
    if ($this->tax_exemption_valid_until && $this->tax_exemption_valid_until->isPast()) {
        return false;
    }
    
    return true;
}

/**
 * Get exemption warnings for UI display
 */
public function getTaxExemptionWarnings(): array
{
    $warnings = [];
    
    if ($this->tax_status !== PartnerTaxStatus::EXEMPT) {
        return $warnings;
    }
    
    if (!$this->tax_exemption_certificate_media_id) {
        $warnings[] = [
            'type' => 'missing_certificate',
            'message' => 'No exemption certificate on file',
            'severity' => 'error',
        ];
    }
    
    if ($this->tax_exemption_valid_until) {
        if ($this->tax_exemption_valid_until->isPast()) {
            $warnings[] = [
                'type' => 'expired_certificate',
                'message' => 'Exemption certificate expired on ' . $this->tax_exemption_valid_until->format('Y-m-d'),
                'severity' => 'error',
            ];
        } elseif ($this->tax_exemption_valid_until->diffInDays(now()) <= 30) {
            $warnings[] = [
                'type' => 'expiring_soon',
                'message' => 'Exemption certificate expires on ' . $this->tax_exemption_valid_until->format('Y-m-d'),
                'severity' => 'warning',
            ];
        }
    }
    
    return $warnings;
}
```

#### 2.3.3 Company Model

**File:** `app/Modules/Company/Domain/Company.php`

Add:

```php
<?php

use App\Modules\Taxation\Domain\Enums\CompanyTaxStatus;

// Add to $casts:
protected $casts = [
    // ... existing
    'tax_status' => CompanyTaxStatus::class,
];

// Add method:
public function canRecoverVAT(): bool
{
    return $this->tax_status === CompanyTaxStatus::REGISTERED;
}
```

#### 2.3.4 DocumentTaxDetail Model

**File:** `app/Modules/Taxation/Domain/Entities/DocumentTaxDetail.php`

```php
<?php

declare(strict_types=1);

namespace App\Modules\Taxation\Domain\Entities;

use App\Modules\Document\Domain\Document;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentTaxDetail extends Model
{
    use HasUuids;
    
    protected $table = 'document_tax_details';
    
    // Immutable - no updated_at
    public $timestamps = false;
    
    protected $fillable = [
        'document_id',
        'sequence_order',
        'tax_code',
        'tax_name',
        'tax_type',
        'tax_rate',
        'tax_fixed_amount',
        'tax_base',
        'tax_amount',
        'created_at',
    ];
    
    protected $casts = [
        'sequence_order' => 'integer',
        'tax_rate' => 'decimal:2',
        'tax_fixed_amount' => 'decimal:3',
        'tax_base' => 'decimal:3',
        'tax_amount' => 'decimal:3',
        'created_at' => 'datetime',
    ];
    
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }
    
    /**
     * Prevent updates on finalized documents
     */
    protected static function booted(): void
    {
        static::updating(function (self $detail) {
            if ($detail->document && $detail->document->isFinalized()) {
                throw new \DomainException(
                    'Cannot modify tax details of a finalized document'
                );
            }
        });
        
        static::deleting(function (self $detail) {
            if ($detail->document && $detail->document->isFinalized()) {
                throw new \DomainException(
                    'Cannot delete tax details of a finalized document'
                );
            }
        });
    }
}
```

---

### 2.4 Service Updates

#### 2.4.1 TaxCalculationService

**File:** `app/Modules/Taxation/Domain/Services/TaxCalculationService.php`

Replace or update the main calculation method:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Taxation\Domain\Services;

use App\Modules\Document\Domain\Document;
use App\Modules\Taxation\Domain\Entities\TaxConfiguration;
use App\Modules\Taxation\Domain\Entities\DocumentTaxDetail;
use App\Modules\Taxation\Domain\Enums\TaxType;
use App\Modules\Taxation\Domain\Enums\StackingBehavior;
use App\Modules\Taxation\Domain\Enums\PartnerTaxStatus;
use App\Modules\Taxation\Domain\DTOs\TaxCalculationResult;
use App\Modules\Taxation\Domain\DTOs\CalculatedTax;

class TaxCalculationService
{
    /**
     * Calculate all applicable taxes for a document
     */
    public function calculateDocumentTaxes(Document $document): TaxCalculationResult
    {
        $company = $document->company;
        $partner = $document->partner;
        $documentType = $document->document_type->value;
        $countryCode = $company->country_code;
        
        // Get line items subtotal (before any taxes)
        $subtotal = $this->calculateSubtotal($document);
        
        // Check for partner exemption status
        $exemptionInfo = $this->getExemptionInfo($partner);
        
        // Get applicable taxes for this document type, ordered by sequence
        $applicableTaxes = TaxConfiguration::query()
            ->where('country_code', $countryCode)
            ->where('is_active', true)
            ->forDocumentType($documentType)
            ->ordered()
            ->get();
        
        // Calculate each tax
        $calculatedTaxes = [];
        $runningTaxTotal = '0';
        $lineItemsTaxTotal = '0';
        $documentTaxTotal = '0';
        
        foreach ($applicableTaxes as $taxConfig) {
            // Determine the base for this tax
            if ($taxConfig->applies_to->value === 'LINE_ITEMS') {
                // For line-item taxes, calculate per line then sum
                $taxAmount = $this->calculateLineItemsTax($document, $taxConfig, $runningTaxTotal);
                $taxBase = $subtotal;
                $lineItemsTaxTotal = bcadd($lineItemsTaxTotal, $taxAmount, 3);
            } else {
                // For document-level taxes (like stamps)
                $taxBase = $subtotal;
                $taxAmount = $taxConfig->calculateAmount($taxBase, $runningTaxTotal);
                $documentTaxTotal = bcadd($documentTaxTotal, $taxAmount, 3);
            }
            
            $calculatedTaxes[] = new CalculatedTax(
                configurationId: $taxConfig->id,
                code: $taxConfig->code,
                name: $taxConfig->name,
                type: $taxConfig->tax_type,
                rate: $taxConfig->percentage_rate,
                fixedAmount: $taxConfig->fixed_amount,
                base: $taxBase,
                amount: $taxAmount,
                sequenceOrder: $taxConfig->sequence_order,
                isStampDuty: $taxConfig->is_stamp_duty,
            );
            
            // Update running total for compound taxes
            $runningTaxTotal = bcadd($runningTaxTotal, $taxAmount, 3);
        }
        
        $totalTax = bcadd($lineItemsTaxTotal, $documentTaxTotal, 3);
        $total = bcadd($subtotal, $totalTax, 3);
        
        return new TaxCalculationResult(
            taxes: $calculatedTaxes,
            subtotal: $subtotal,
            lineItemsTaxTotal: $lineItemsTaxTotal,
            documentTaxTotal: $documentTaxTotal,
            totalTax: $totalTax,
            total: $total,
            exemptionInfo: $exemptionInfo,
        );
    }
    
    /**
     * Calculate line items subtotal
     */
    private function calculateSubtotal(Document $document): string
    {
        $subtotal = '0';
        
        foreach ($document->lines as $line) {
            $lineTotal = bcmul($line->quantity, $line->unit_price, 3);
            $subtotal = bcadd($subtotal, $lineTotal, 3);
        }
        
        // Apply document-level discount if any
        if ($document->discount_amount) {
            $subtotal = bcsub($subtotal, $document->discount_amount, 3);
        }
        
        return $subtotal;
    }
    
    /**
     * Calculate tax for line items (percentage-based taxes)
     */
    private function calculateLineItemsTax(
        Document $document, 
        TaxConfiguration $taxConfig,
        string $previousTaxesTotal
    ): string {
        $totalTax = '0';
        
        foreach ($document->lines as $line) {
            $lineSubtotal = bcmul($line->quantity, $line->unit_price, 3);
            
            // Use line-specific rate if set, otherwise use config rate
            $rate = $line->tax_rate ?? $taxConfig->percentage_rate ?? '0';
            
            // Calculate base depending on stacking behavior
            $base = $lineSubtotal;
            if ($taxConfig->stacks_on === StackingBehavior::SUBTOTAL_PLUS_PREVIOUS_TAXES) {
                // Proportionally add previous taxes to this line's base
                // (simplified - assumes even distribution)
                $documentSubtotal = $this->calculateSubtotal($document);
                if (bccomp($documentSubtotal, '0', 3) > 0) {
                    $lineProportion = bcdiv($lineSubtotal, $documentSubtotal, 6);
                    $linePreviousTax = bcmul($previousTaxesTotal, $lineProportion, 3);
                    $base = bcadd($lineSubtotal, $linePreviousTax, 3);
                }
            }
            
            $lineTax = bcmul($base, bcdiv($rate, '100', 6), 3);
            $totalTax = bcadd($totalTax, $lineTax, 3);
        }
        
        return $totalTax;
    }
    
    /**
     * Get partner exemption information for UI
     */
    private function getExemptionInfo($partner): ?array
    {
        if (!$partner || $partner->tax_status !== PartnerTaxStatus::EXEMPT) {
            return null;
        }
        
        return [
            'status' => 'EXEMPT',
            'reason' => $partner->tax_exemption_reason,
            'hasValidCertificate' => $partner->hasValidTaxExemption(),
            'warnings' => $partner->getTaxExemptionWarnings(),
        ];
    }
    
    /**
     * Store tax details when document is finalized (immutable snapshot)
     */
    public function snapshotTaxDetails(Document $document, TaxCalculationResult $result): void
    {
        // Remove any existing details (for drafts being re-finalized)
        DocumentTaxDetail::where('document_id', $document->id)->delete();
        
        // Create immutable snapshot
        foreach ($result->taxes as $tax) {
            DocumentTaxDetail::create([
                'document_id' => $document->id,
                'sequence_order' => $tax->sequenceOrder,
                'tax_code' => $tax->code,
                'tax_name' => $tax->name,
                'tax_type' => $tax->type->value,
                'tax_rate' => $tax->rate ?? 0,
                'tax_fixed_amount' => $tax->fixedAmount ?? 0,
                'tax_base' => $tax->base,
                'tax_amount' => $tax->amount,
                'created_at' => now(),
            ]);
        }
    }
}
```

#### 2.4.2 DTOs for Tax Calculation

**File:** `app/Modules/Taxation/Domain/DTOs/CalculatedTax.php`

```php
<?php

declare(strict_types=1);

namespace App\Modules\Taxation\Domain\DTOs;

use App\Modules\Taxation\Domain\Enums\TaxType;

readonly class CalculatedTax
{
    public function __construct(
        public string $configurationId,
        public string $code,
        public string $name,
        public TaxType $type,
        public ?string $rate,
        public ?string $fixedAmount,
        public string $base,
        public string $amount,
        public int $sequenceOrder,
        public bool $isStampDuty,
    ) {}
    
    public function toArray(): array
    {
        return [
            'configuration_id' => $this->configurationId,
            'code' => $this->code,
            'name' => $this->name,
            'type' => $this->type->value,
            'rate' => $this->rate,
            'fixed_amount' => $this->fixedAmount,
            'base' => $this->base,
            'amount' => $this->amount,
            'sequence_order' => $this->sequenceOrder,
            'is_stamp_duty' => $this->isStampDuty,
        ];
    }
}
```

**File:** `app/Modules/Taxation/Domain/DTOs/TaxCalculationResult.php`

```php
<?php

declare(strict_types=1);

namespace App\Modules\Taxation\Domain\DTOs;

readonly class TaxCalculationResult
{
    /**
     * @param CalculatedTax[] $taxes
     */
    public function __construct(
        public array $taxes,
        public string $subtotal,
        public string $lineItemsTaxTotal,
        public string $documentTaxTotal,
        public string $totalTax,
        public string $total,
        public ?array $exemptionInfo = null,
    ) {}
    
    public function toArray(): array
    {
        return [
            'taxes' => array_map(fn($t) => $t->toArray(), $this->taxes),
            'subtotal' => $this->subtotal,
            'line_items_tax_total' => $this->lineItemsTaxTotal,
            'document_tax_total' => $this->documentTaxTotal,
            'total_tax' => $this->totalTax,
            'total' => $this->total,
            'exemption_info' => $this->exemptionInfo,
        ];
    }
    
    public function hasExemptionWarnings(): bool
    {
        return $this->exemptionInfo !== null 
            && !empty($this->exemptionInfo['warnings']);
    }
}
```

---

### 2.5 API Endpoints

#### 2.5.1 Tax Configuration CRUD

**File:** `app/Modules/Taxation/Presentation/Controllers/TaxConfigurationController.php`

```php
<?php

declare(strict_types=1);

namespace App\Modules\Taxation\Presentation\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Taxation\Domain\Entities\TaxConfiguration;
use App\Modules\Taxation\Domain\Enums\TaxType;
use App\Modules\Taxation\Domain\Enums\TaxApplicationLevel;
use App\Modules\Taxation\Domain\Enums\StackingBehavior;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TaxConfigurationController extends Controller
{
    /**
     * List all tax configurations for the company's country
     */
    public function index(Request $request): JsonResponse
    {
        $company = $request->user()->currentCompany;
        
        $taxes = TaxConfiguration::query()
            ->where('country_code', $company->country_code)
            ->ordered()
            ->get();
        
        return response()->json([
            'data' => $taxes,
            'meta' => [
                'country_code' => $company->country_code,
            ],
        ]);
    }
    
    /**
     * Create a new tax configuration
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:100',
            'code' => 'nullable|string|max:50',
            'tax_type' => 'required|in:PERCENTAGE,FIXED_AMOUNT',
            'percentage_rate' => 'required_if:tax_type,PERCENTAGE|nullable|numeric|min:0|max:100',
            'fixed_amount' => 'required_if:tax_type,FIXED_AMOUNT|nullable|numeric|min:0',
            'applies_to' => 'required|in:LINE_ITEMS,DOCUMENT_TOTAL',
            'sequence_order' => 'nullable|integer|min:1',
            'stacks_on' => 'nullable|in:BASE_AMOUNT,SUBTOTAL_PLUS_PREVIOUS_TAXES',
            'applicable_document_types' => 'nullable|array',
            'applicable_document_types.*' => 'string',
            'is_active' => 'nullable|boolean',
        ]);
        
        $company = $request->user()->currentCompany;
        
        // Auto-generate code if not provided
        if (empty($validated['code'])) {
            $validated['code'] = strtoupper(str_replace(' ', '_', $validated['name']));
        }
        
        // Get next sequence order if not provided
        if (empty($validated['sequence_order'])) {
            $maxOrder = TaxConfiguration::where('country_code', $company->country_code)
                ->max('sequence_order') ?? 0;
            $validated['sequence_order'] = $maxOrder + 1;
        }
        
        $tax = TaxConfiguration::create([
            'country_code' => $company->country_code,
            'name' => $validated['name'],
            'code' => $validated['code'],
            'tax_type' => $validated['tax_type'],
            'percentage_rate' => $validated['percentage_rate'] ?? null,
            'fixed_amount' => $validated['fixed_amount'] ?? null,
            'applies_to' => $validated['applies_to'],
            'sequence_order' => $validated['sequence_order'],
            'stacks_on' => $validated['stacks_on'] ?? 'BASE_AMOUNT',
            'applicable_document_types' => $validated['applicable_document_types'] ?? [],
            'is_default' => false,
            'is_active' => $validated['is_active'] ?? true,
            'is_stamp_duty' => false,
        ]);
        
        return response()->json(['data' => $tax], 201);
    }
    
    /**
     * Get a single tax configuration
     */
    public function show(string $id): JsonResponse
    {
        $tax = TaxConfiguration::findOrFail($id);
        
        return response()->json(['data' => $tax]);
    }
    
    /**
     * Update a tax configuration
     */
    public function update(Request $request, string $id): JsonResponse
    {
        $tax = TaxConfiguration::findOrFail($id);
        
        $validated = $request->validate([
            'name' => 'sometimes|string|max:100',
            'code' => 'sometimes|string|max:50',
            'tax_type' => 'sometimes|in:PERCENTAGE,FIXED_AMOUNT',
            'percentage_rate' => 'nullable|numeric|min:0|max:100',
            'fixed_amount' => 'nullable|numeric|min:0',
            'applies_to' => 'sometimes|in:LINE_ITEMS,DOCUMENT_TOTAL',
            'sequence_order' => 'sometimes|integer|min:1',
            'stacks_on' => 'sometimes|in:BASE_AMOUNT,SUBTOTAL_PLUS_PREVIOUS_TAXES',
            'applicable_document_types' => 'sometimes|array',
            'applicable_document_types.*' => 'string',
            'is_active' => 'sometimes|boolean',
        ]);
        
        $tax->update($validated);
        
        return response()->json(['data' => $tax->fresh()]);
    }
    
    /**
     * Deactivate a tax configuration (soft delete)
     */
    public function destroy(string $id): JsonResponse
    {
        $tax = TaxConfiguration::findOrFail($id);
        
        $tax->update(['is_active' => false]);
        
        return response()->json(null, 204);
    }
    
    /**
     * Reorder tax configurations
     */
    public function reorder(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'order' => 'required|array',
            'order.*.id' => 'required|uuid|exists:tax_configurations,id',
            'order.*.sequence_order' => 'required|integer|min:1',
        ]);
        
        foreach ($validated['order'] as $item) {
            TaxConfiguration::where('id', $item['id'])
                ->update(['sequence_order' => $item['sequence_order']]);
        }
        
        return response()->json(['message' => 'Order updated']);
    }
    
    /**
     * Get available document types for the dropdown
     */
    public function documentTypes(): JsonResponse
    {
        // Get from DocumentType enum
        $types = [];
        
        // This should pull from your actual DocumentType enum
        // Example:
        foreach (\App\Modules\Document\Domain\Enums\DocumentType::cases() as $case) {
            $types[] = [
                'value' => $case->value,
                'label' => $case->label(),
            ];
        }
        
        return response()->json(['data' => $types]);
    }
}
```

#### 2.5.2 Routes

**File:** `app/Modules/Taxation/Presentation/routes.php` (or appropriate routes file)

```php
<?php

use App\Modules\Taxation\Presentation\Controllers\TaxConfigurationController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'company'])->prefix('api')->group(function () {
    // Tax Configurations
    Route::get('/tax-configurations', [TaxConfigurationController::class, 'index']);
    Route::post('/tax-configurations', [TaxConfigurationController::class, 'store']);
    Route::get('/tax-configurations/document-types', [TaxConfigurationController::class, 'documentTypes']);
    Route::get('/tax-configurations/{id}', [TaxConfigurationController::class, 'show']);
    Route::put('/tax-configurations/{id}', [TaxConfigurationController::class, 'update']);
    Route::delete('/tax-configurations/{id}', [TaxConfigurationController::class, 'destroy']);
    Route::post('/tax-configurations/reorder', [TaxConfigurationController::class, 'reorder']);
});
```

---

### 2.6 Tunisia Seeder

**File:** `database/seeders/TunisiaTaxConfigurationSeeder.php`

```php
<?php

namespace Database\Seeders;

use App\Modules\Taxation\Domain\Entities\TaxConfiguration;
use Illuminate\Database\Seeder;

class TunisiaTaxConfigurationSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedVATRates();
        $this->seedStampDuties();
    }
    
    private function seedVATRates(): void
    {
        $vatRates = [
            [
                'name' => 'TVA 19%',
                'code' => 'TVA_19',
                'percentage_rate' => 19.00,
                'is_default' => true,
            ],
            [
                'name' => 'TVA 13%',
                'code' => 'TVA_13', 
                'percentage_rate' => 13.00,
                'is_default' => false,
            ],
            [
                'name' => 'TVA 7%',
                'code' => 'TVA_7',
                'percentage_rate' => 7.00,
                'is_default' => false,
            ],
            [
                'name' => 'Exonéré TVA',
                'code' => 'TVA_0',
                'percentage_rate' => 0.00,
                'is_default' => false,
            ],
        ];
        
        foreach ($vatRates as $index => $rate) {
            TaxConfiguration::updateOrCreate(
                [
                    'country_code' => 'TN',
                    'code' => $rate['code'],
                ],
                [
                    'name' => $rate['name'],
                    'tax_type' => 'PERCENTAGE',
                    'percentage_rate' => $rate['percentage_rate'],
                    'fixed_amount' => null,
                    'applies_to' => 'LINE_ITEMS',
                    'is_default' => $rate['is_default'],
                    'is_active' => true,
                    'sequence_order' => $index + 1,
                    'stacks_on' => 'BASE_AMOUNT',
                    'applicable_document_types' => [
                        'TAX_INVOICE',
                        'FISCAL_RECEIPT', 
                        'CREDIT_NOTE',
                        'PURCHASE_INVOICE',
                    ],
                    'is_stamp_duty' => false,
                ]
            );
        }
    }
    
    private function seedStampDuties(): void
    {
        $stampDuties = [
            [
                'name' => 'Timbre Fiscal - Facture',
                'code' => 'STAMP_INVOICE',
                'amount' => 1.000,
                'document_types' => ['TAX_INVOICE'],
            ],
            [
                'name' => 'Timbre Fiscal - Ticket',
                'code' => 'STAMP_RECEIPT',
                'amount' => 0.100,
                'document_types' => ['FISCAL_RECEIPT'],
            ],
        ];
        
        foreach ($stampDuties as $stamp) {
            TaxConfiguration::updateOrCreate(
                [
                    'country_code' => 'TN',
                    'code' => $stamp['code'],
                ],
                [
                    'name' => $stamp['name'],
                    'tax_type' => 'FIXED_AMOUNT',
                    'percentage_rate' => null,
                    'fixed_amount' => $stamp['amount'],
                    'applies_to' => 'DOCUMENT_TOTAL',
                    'is_default' => false,
                    'is_active' => true,
                    'sequence_order' => 99, // Stamps apply last
                    'stacks_on' => 'BASE_AMOUNT',
                    'applicable_document_types' => $stamp['document_types'],
                    'is_stamp_duty' => true,
                ]
            );
        }
    }
}
```

---

## Part 3: Frontend Implementation

### 3.1 Tax Settings Page

**File:** `apps/web/src/features/settings/TaxSettingsPage.tsx`

Create a tabbed interface with:

#### Tab 1: Company Tax Profile
```tsx
// Fields:
// - Tax Status: Select (REGISTERED, NON_REGISTERED)
// - VAT Registration Number: Input
// - Default Tax Rate: Select (from tax configurations)

// Use existing company settings update API
```

#### Tab 2: Tax Types Management
```tsx
// Data table with columns:
// - Drag handle (for reordering)
// - Name
// - Code
// - Type (Percentage/Fixed)
// - Rate/Amount
// - Applies To
// - Document Types (badges)
// - Active (toggle)
// - Actions (Edit, Delete)

// Add Tax button opens modal
// Reorder via drag-and-drop or arrow buttons
```

#### Tab 3: Stamp Duties (filtered view of Tab 2)
```tsx
// Same as Tab 2 but filtered to is_stamp_duty = true
// Or just show within Tab 2 with visual distinction
```

### 3.2 Add/Edit Tax Modal

```tsx
interface TaxFormData {
  name: string;
  code: string;
  tax_type: 'PERCENTAGE' | 'FIXED_AMOUNT';
  percentage_rate?: number;
  fixed_amount?: number;
  applies_to: 'LINE_ITEMS' | 'DOCUMENT_TOTAL';
  sequence_order?: number;
  stacks_on: 'BASE_AMOUNT' | 'SUBTOTAL_PLUS_PREVIOUS_TAXES';
  applicable_document_types: string[];
  is_active: boolean;
}

// Form fields:
// - Name (required)
// - Code (optional, auto-generated)
// - Type: Radio (Percentage / Fixed Amount)
// - If Percentage: Rate input with % suffix
// - If Fixed: Amount input with currency
// - Applies to: Radio (Line Items / Document Total)
// - Stacking: Select (only if not first tax)
// - Document Types: Multi-select checkboxes
// - Active: Toggle
```

### 3.3 Partner Form Enhancement

**File:** Update existing partner form component

Add "Tax Information" section:
```tsx
// Fields:
// - Tax Status: Select (REGISTERED, NON_REGISTERED, EXEMPT)
// - VAT Registration Number: Input (shown if not EXEMPT)
// 
// If status = EXEMPT, show additional fields:
// - Exemption Reason: Textarea
// - Exemption Certificate: File upload
// - Certificate Valid Until: Date picker
// 
// Show warning badges if:
// - EXEMPT but no certificate
// - EXEMPT but certificate expired
// - EXEMPT and certificate expiring within 30 days
```

### 3.4 Sales Document Exemption Notice

When creating/editing a sales document, after partner selection:

```tsx
// If partner.tax_status === 'EXEMPT':
// Show alert banner:
// "⚠️ This customer is marked as tax-exempt"
// 
// Show any warnings from partner.getTaxExemptionWarnings()
// 
// Show info text:
// "Select the appropriate tax rate (0% for exempt) and add a tax mention if required."
//
// tax_mention field: Textarea
// Placeholder: "e.g., Exonéré TVA - Certificat #123"
```

### 3.5 API Client

**File:** `apps/web/src/features/settings/api/taxConfigurationApi.ts`

```typescript
import { apiClient } from '@/lib/api';

export interface TaxConfiguration {
  id: string;
  country_code: string;
  name: string;
  code: string;
  tax_type: 'PERCENTAGE' | 'FIXED_AMOUNT';
  percentage_rate: string | null;
  fixed_amount: string | null;
  applies_to: 'LINE_ITEMS' | 'DOCUMENT_TOTAL';
  sequence_order: number;
  stacks_on: 'BASE_AMOUNT' | 'SUBTOTAL_PLUS_PREVIOUS_TAXES';
  applicable_document_types: string[];
  is_default: boolean;
  is_active: boolean;
  is_stamp_duty: boolean;
}

export interface DocumentType {
  value: string;
  label: string;
}

export const taxConfigurationApi = {
  list: () => 
    apiClient.get<{ data: TaxConfiguration[] }>('/tax-configurations'),
  
  create: (data: Partial<TaxConfiguration>) =>
    apiClient.post<{ data: TaxConfiguration }>('/tax-configurations', data),
  
  update: (id: string, data: Partial<TaxConfiguration>) =>
    apiClient.put<{ data: TaxConfiguration }>(`/tax-configurations/${id}`, data),
  
  delete: (id: string) =>
    apiClient.delete(`/tax-configurations/${id}`),
  
  reorder: (order: { id: string; sequence_order: number }[]) =>
    apiClient.post('/tax-configurations/reorder', { order }),
  
  getDocumentTypes: () =>
    apiClient.get<{ data: DocumentType[] }>('/tax-configurations/document-types'),
};
```

### 3.6 Translations

Add to translation files:

```json
// en/settings.json
{
  "tax": {
    "title": "Tax Settings",
    "tabs": {
      "profile": "Company Tax Profile",
      "taxes": "Tax Types",
      "stamps": "Stamp Duties"
    },
    "profile": {
      "status": "Tax Registration Status",
      "status_registered": "VAT Registered (Assujetti)",
      "status_non_registered": "Not VAT Registered (Non-Assujetti)",
      "vat_number": "VAT Registration Number",
      "default_rate": "Default Tax Rate"
    },
    "form": {
      "name": "Tax Name",
      "code": "Tax Code",
      "type": "Calculation Type",
      "type_percentage": "Percentage",
      "type_fixed": "Fixed Amount",
      "rate": "Rate (%)",
      "amount": "Amount",
      "applies_to": "Applies To",
      "applies_line_items": "Line Items",
      "applies_document": "Document Total",
      "stacking": "Stacking Behavior",
      "stacking_base": "Calculate on base amount only",
      "stacking_compound": "Calculate on subtotal + previous taxes",
      "document_types": "Applicable Document Types",
      "active": "Active",
      "add": "Add Tax",
      "edit": "Edit Tax"
    },
    "table": {
      "name": "Name",
      "code": "Code",
      "type": "Type",
      "rate": "Rate/Amount",
      "applies_to": "Applies To",
      "documents": "Documents",
      "order": "Order",
      "active": "Active",
      "actions": "Actions"
    }
  }
}
```

---

## Part 4: Testing Scenarios

### 4.1 Unit Tests

**File:** `tests/Unit/Taxation/TaxCalculationServiceTest.php`

```php
<?php

namespace Tests\Unit\Taxation;

use Tests\TestCase;
use App\Modules\Taxation\Domain\Services\TaxCalculationService;
use App\Modules\Taxation\Domain\Entities\TaxConfiguration;
use App\Modules\Document\Domain\Document;
// ... other imports

class TaxCalculationServiceTest extends TestCase
{
    private TaxCalculationService $service;
    
    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(TaxCalculationService::class);
    }
    
    /** @test */
    public function it_calculates_single_percentage_tax(): void
    {
        // Setup: Document with 100 TND subtotal, 19% VAT
        // Expected: tax = 19, total = 119
    }
    
    /** @test */
    public function it_calculates_fixed_amount_tax(): void
    {
        // Setup: Document with 100 TND subtotal, 1 TND stamp
        // Expected: tax = 1, total = 101
    }
    
    /** @test */
    public function it_calculates_multiple_taxes_in_sequence(): void
    {
        // Setup: Document with 100 TND, 19% VAT (order 1), 1 TND stamp (order 99)
        // Expected: VAT = 19, stamp = 1, total = 120
    }
    
    /** @test */
    public function it_calculates_compound_taxes(): void
    {
        // Setup: 100 TND base, 10% DC (order 1), 19% VAT on base+DC (order 2)
        // Expected: DC = 10, VAT = 20.9, total = 130.9
    }
    
    /** @test */
    public function it_filters_taxes_by_document_type(): void
    {
        // Setup: Tax only applies to TAX_INVOICE, document is FISCAL_RECEIPT
        // Expected: Tax not applied
    }
    
    /** @test */
    public function it_returns_exemption_info_for_exempt_partner(): void
    {
        // Setup: Partner with tax_status = EXEMPT
        // Expected: exemptionInfo populated in result
    }
    
    /** @test */
    public function it_returns_warnings_for_expired_certificate(): void
    {
        // Setup: Partner EXEMPT with expired certificate
        // Expected: warnings array contains 'expired_certificate'
    }
}
```

### 4.2 Feature Tests

**File:** `tests/Feature/Taxation/TaxConfigurationCrudTest.php`

```php
<?php

namespace Tests\Feature\Taxation;

use Tests\TestCase;
use App\Modules\Taxation\Domain\Entities\TaxConfiguration;
// ... imports

class TaxConfigurationCrudTest extends TestCase
{
    /** @test */
    public function user_can_list_tax_configurations(): void
    {
        // GET /api/tax-configurations
        // Assert: returns taxes for user's company country
    }
    
    /** @test */
    public function user_can_create_percentage_tax(): void
    {
        // POST /api/tax-configurations with percentage data
        // Assert: created with correct values
    }
    
    /** @test */
    public function user_can_create_fixed_amount_tax(): void
    {
        // POST /api/tax-configurations with fixed amount data
        // Assert: created with correct values
    }
    
    /** @test */
    public function user_can_update_tax_configuration(): void
    {
        // PUT /api/tax-configurations/{id}
        // Assert: updated correctly
    }
    
    /** @test */
    public function user_can_reorder_taxes(): void
    {
        // POST /api/tax-configurations/reorder
        // Assert: sequence_order updated for all
    }
    
    /** @test */
    public function deleting_tax_sets_inactive(): void
    {
        // DELETE /api/tax-configurations/{id}
        // Assert: is_active = false (soft delete)
    }
}
```

### 4.3 Immutability Tests

**File:** `tests/Feature/Taxation/TaxImmutabilityTest.php`

```php
<?php

namespace Tests\Feature\Taxation;

use Tests\TestCase;
// ... imports

class TaxImmutabilityTest extends TestCase
{
    /** @test */
    public function finalized_document_stores_tax_snapshot(): void
    {
        // Create and finalize document
        // Assert: document_tax_details records created
    }
    
    /** @test */
    public function changing_tax_config_does_not_affect_finalized_documents(): void
    {
        // Create and finalize document with 19% VAT
        // Change tax config to 20%
        // Assert: document still shows 19% from snapshot
    }
    
    /** @test */
    public function cannot_modify_tax_details_of_finalized_document(): void
    {
        // Finalize document
        // Try to update document_tax_details
        // Assert: DomainException thrown
    }
    
    /** @test */
    public function cannot_delete_tax_details_of_finalized_document(): void
    {
        // Finalize document
        // Try to delete document_tax_details
        // Assert: DomainException thrown
    }
}
```

---

## Part 5: Implementation Order

Execute in this sequence:

### Phase 1: Database & Backend Foundation
1. Run verification commands from Part 1
2. Create all migrations (2.1.1 - 2.1.6)
3. Run migrations
4. Create enums (2.2.1 - 2.2.3)
5. Update models (2.3.1 - 2.3.4)
6. Update TaxCalculationService (2.4.1)
7. Create DTOs (2.4.2)

### Phase 2: API & Seeding
8. Create/update controller (2.5.1)
9. Add routes (2.5.2)
10. Run Tunisia seeder (2.6)
11. Test API endpoints manually

### Phase 3: Frontend
12. Create API client (3.5)
13. Add translations (3.6)
14. Build Tax Settings page (3.1, 3.2)
15. Update Partner form (3.3)
16. Add exemption notice to sales documents (3.4)

### Phase 4: Testing & Integration
17. Write and run unit tests (4.1)
18. Write and run feature tests (4.2, 4.3)
19. Integration testing with full flow
20. Fix any issues found

### Phase 5: Cleanup
21. Deprecate stamp_duty_rules table (add comment, don't delete yet)
22. Update any remaining StampDutyService references
23. Documentation update

---

## Part 6: Verification Checklist

After implementation, verify:

- [ ] Tax configurations CRUD works
- [ ] Tunisia presets are seeded correctly
- [ ] Multiple taxes calculate in correct order
- [ ] Compound/stacking taxes calculate correctly
- [ ] Document type filtering works
- [ ] Stamp duties appear only on correct document types
- [ ] Partner tax status saves correctly
- [ ] Partner exemption warnings appear
- [ ] Document tax_mention field saves
- [ ] Finalized documents store tax snapshots
- [ ] Tax config changes don't affect old documents
- [ ] Cannot modify tax details of finalized documents
- [ ] Company tax status affects VAT recoverability logic
- [ ] All tests pass
- [ ] No TypeScript errors in frontend
- [ ] PHPStan passes

---

**End of Specification**
