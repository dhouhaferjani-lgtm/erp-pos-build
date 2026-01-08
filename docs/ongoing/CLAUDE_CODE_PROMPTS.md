# Claude Code Implementation Prompts - Tax Management Module

**Reference Document:** `TAX_MANAGEMENT_IMPLEMENTATION_SPEC.md` under /docs/ongoing/

Use these prompts sequentially with Claude Code. Each prompt builds on the previous phase.

---

## PROMPT 1: Initial Verification & Gap Analysis

```
I need you to verify the current state of our tax management system and compare it against our target implementation. 

**IMPORTANT**: Before making any changes, perform a thorough verification and create a gap analysis report.

### Step 1: Verify Database Schema

Run these checks and document findings:

1. Check tax_configurations table:
```bash
php artisan tinker --execute="print_r(Schema::getColumnListing('tax_configurations'))"
```

2. Check stamp_duty_rules table:
```bash
php artisan tinker --execute="print_r(Schema::getColumnListing('stamp_duty_rules'))"
```

3. Check partners table:
```bash
php artisan tinker --execute="print_r(Schema::getColumnListing('partners'))"
```

4. Check companies table:
```bash
php artisan tinker --execute="print_r(Schema::getColumnListing('companies'))"
```

5. Check documents table:
```bash
php artisan tinker --execute="print_r(Schema::getColumnListing('documents'))"
```

6. Check document_lines table:
```bash
php artisan tinker --execute="print_r(Schema::getColumnListing('document_lines'))"
```

7. Check if document_tax_details exists:
```bash
php artisan tinker --execute="echo Schema::hasTable('document_tax_details') ? 'EXISTS' : 'MISSING'"
```

If document_tax_details exists, get its columns:
```bash
php artisan tinker --execute="print_r(Schema::getColumnListing('document_tax_details'))"
```

### Step 2: Verify Existing Code

1. Find TaxConfiguration model and show its location and content:
```bash
find app/ -name "*TaxConfiguration*" -type f
```

2. Find TaxCalculationService:
```bash
find app/ -name "*TaxCalculation*" -type f
```

3. Find StampDutyService:
```bash
find app/ -name "*StampDuty*" -type f
```

4. Find existing tax-related enums:
```bash
grep -r "enum.*Tax" app/ --include="*.php" -l
```

5. Find DocumentType enum:
```bash
find app/ -name "DocumentType.php" -type f
```

6. Check existing routes for tax endpoints:
```bash
grep -r "tax" routes/ --include="*.php"
grep -r "tax-config" app/ --include="*.php" -l
```

### Step 3: Create Gap Analysis Report

After gathering all information, create a file at `storage/app/tax_implementation_gap_analysis.md` with this structure:

```markdown
# Tax Management - Gap Analysis Report
Generated: [timestamp]

## Current State

### Database Tables

#### tax_configurations
- Columns found: [list]
- Missing columns needed: [list]

#### stamp_duty_rules  
- Columns found: [list]
- Status: [will be migrated to tax_configurations]

#### partners
- Columns found: [list]
- Missing columns needed: [list]

#### companies
- Columns found: [list]
- Missing columns needed: [list]

#### documents
- Columns found: [list]
- Missing columns needed: [list]

#### document_tax_details
- Exists: [yes/no]
- Columns found: [list]
- Missing/incorrect columns: [list]

### Existing Code

#### Models
- TaxConfiguration: [path] - needs updates: [yes/no]
- Partner: [path] - needs updates: [yes/no]
- Company: [path] - needs updates: [yes/no]
- DocumentTaxDetail: [exists/missing]

#### Services
- TaxCalculationService: [path] - needs updates: [yes/no]
- StampDutyService: [path] - will be deprecated

#### Enums
- TaxType: [path or missing]
- DocumentType: [path]
- CompanyTaxStatus: [missing - to create]
- PartnerTaxStatus: [missing - to create]
- StackingBehavior: [missing - to create]

#### API Endpoints
- Existing tax endpoints: [list]
- Missing endpoints: [list]

## Required Changes Summary

### Migrations Needed
1. [list each migration]

### Models to Create/Update
1. [list each model]

### Services to Create/Update
1. [list each service]

### Enums to Create
1. [list each enum]

### Controllers to Create/Update
1. [list each controller]

## Recommended Implementation Order
1. [ordered list]
```

**Do not make any changes yet. Just gather information and create the gap analysis report.**

When done, show me the complete gap analysis report.
```

---

## PROMPT 2: Create Migrations

```
Based on the gap analysis, now create the database migrations. Reference the TAX_MANAGEMENT_IMPLEMENTATION_SPEC.md for exact schema requirements.

### Create migrations in this order:

1. **Enhance tax_configurations table** (if columns are missing)

Create migration to add these columns if they don't exist:
- sequence_order (integer, default 1)
- stacks_on (varchar 50, default 'BASE_AMOUNT')
- applicable_document_types (jsonb, default '[]')
- is_stamp_duty (boolean, default false)

2. **Add tax fields to partners table** (if columns are missing)

Create migration to add:
- tax_status (varchar 50, default 'REGISTERED')
- tax_exemption_reason (text, nullable)
- tax_exemption_certificate_media_id (uuid, nullable, FK to media)
- tax_exemption_valid_until (date, nullable)

3. **Add tax_status to companies table** (if missing)

Create migration to add:
- tax_status (varchar 50, default 'REGISTERED')

4. **Add tax_mention to documents table** (if missing)

Create migration to add:
- tax_mention (text, nullable)

5. **Ensure document_tax_details has correct structure**

If table doesn't exist, create it. If it exists but is missing columns, add them.

Required columns:
- id (uuid, PK)
- document_id (uuid, FK)
- sequence_order (integer)
- tax_code (varchar 50)
- tax_name (varchar 100)
- tax_type (varchar 20)
- tax_rate (decimal 5,2, default 0)
- tax_fixed_amount (decimal 10,3, default 0)
- tax_base (decimal 15,3)
- tax_amount (decimal 15,3)
- created_at (timestamp)
- NO updated_at (immutable records)

6. **Migrate stamp duties to tax configurations**

Create a migration that:
- Reads existing stamp_duty_rules
- Creates corresponding tax_configurations with is_stamp_duty=true
- Sets sequence_order=99 for stamps (apply last)

### Important Notes:

- Use safe migrations that check if columns exist before adding
- All new columns with NOT NULL must have defaults
- Don't drop any existing columns or tables
- Follow the existing migration naming convention in the project

After creating migrations, run:
```bash
php artisan migrate --pretend
```

Show me the output to verify before actually running them.
```

---

## PROMPT 3: Create Enums

```
Create the required enums for the tax management system. Place them in the appropriate module directory following the project's conventions.

### 1. CompanyTaxStatus Enum

Location: `app/Modules/Taxation/Domain/Enums/CompanyTaxStatus.php`

```php
<?php

declare(strict_types=1);

namespace App\Modules\Taxation\Domain\Enums;

enum CompanyTaxStatus: string
{
    case REGISTERED = 'REGISTERED';
    case NON_REGISTERED = 'NON_REGISTERED';
    
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

### 2. PartnerTaxStatus Enum

Location: `app/Modules/Taxation/Domain/Enums/PartnerTaxStatus.php`

```php
<?php

declare(strict_types=1);

namespace App\Modules\Taxation\Domain\Enums;

enum PartnerTaxStatus: string
{
    case REGISTERED = 'REGISTERED';
    case NON_REGISTERED = 'NON_REGISTERED';
    case EXEMPT = 'EXEMPT';
    
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

### 3. StackingBehavior Enum

Location: `app/Modules/Taxation/Domain/Enums/StackingBehavior.php`

```php
<?php

declare(strict_types=1);

namespace App\Modules\Taxation\Domain\Enums;

enum StackingBehavior: string
{
    case BASE_AMOUNT = 'BASE_AMOUNT';
    case SUBTOTAL_PLUS_PREVIOUS_TAXES = 'SUBTOTAL_PLUS_PREVIOUS_TAXES';
    
    public function label(): string
    {
        return match($this) {
            self::BASE_AMOUNT => 'Calculate on base amount only',
            self::SUBTOTAL_PLUS_PREVIOUS_TAXES => 'Compound (calculate on subtotal + previous taxes)',
        };
    }
}
```

### 4. Check/Update TaxType Enum

Find the existing TaxType enum and ensure it has:
- PERCENTAGE
- FIXED_AMOUNT

If it doesn't exist, create it at `app/Modules/Taxation/Domain/Enums/TaxType.php`

### 5. Check/Update TaxApplicationLevel Enum

Ensure there's an enum for applies_to with:
- LINE_ITEMS
- DOCUMENT_TOTAL

If missing, create at `app/Modules/Taxation/Domain/Enums/TaxApplicationLevel.php`

After creating all enums, run PHPStan to verify:
```bash
./vendor/bin/phpstan analyse app/Modules/Taxation/Domain/Enums/ --level=max
```

Show me any errors that need fixing.
```

---

## PROMPT 4: Update Models

```
Update the domain models to support the new tax management features. Reference TAX_MANAGEMENT_IMPLEMENTATION_SPEC.md for the full implementation details.

### 1. Update TaxConfiguration Model

Find the existing TaxConfiguration model and add:

**Imports:**
```php
use App\Modules\Taxation\Domain\Enums\TaxType;
use App\Modules\Taxation\Domain\Enums\TaxApplicationLevel;
use App\Modules\Taxation\Domain\Enums\StackingBehavior;
```

**Add to $casts array:**
```php
'tax_type' => TaxType::class,
'applies_to' => TaxApplicationLevel::class,
'stacks_on' => StackingBehavior::class,
'applicable_document_types' => 'array',
'is_stamp_duty' => 'boolean',
'sequence_order' => 'integer',
```

**Add scopes:**
```php
public function scopeForDocumentType($query, string $documentType)
{
    return $query->where(function ($q) use ($documentType) {
        $q->whereJsonContains('applicable_document_types', $documentType)
          ->orWhereJsonLength('applicable_document_types', 0);
    });
}

public function scopeOrdered($query)
{
    return $query->orderBy('sequence_order', 'asc');
}

public function scopeActive($query)
{
    return $query->where('is_active', true);
}
```

**Add methods:**
```php
public function appliesToDocumentType(string $documentType): bool
{
    $types = $this->applicable_document_types ?? [];
    return empty($types) || in_array($documentType, $types);
}

public function calculateAmount(string $base, ?string $previousTaxesTotal = null): string
{
    if ($this->tax_type === TaxType::FIXED_AMOUNT) {
        return $this->fixed_amount ?? '0';
    }
    
    $calculationBase = $base;
    
    if ($this->stacks_on === StackingBehavior::SUBTOTAL_PLUS_PREVIOUS_TAXES && $previousTaxesTotal) {
        $calculationBase = bcadd($base, $previousTaxesTotal, 3);
    }
    
    $rate = bcdiv($this->percentage_rate ?? '0', '100', 6);
    return bcmul($calculationBase, $rate, 3);
}
```

### 2. Update Partner Model

Find the Partner model (likely at App\Modules\Partner\Domain\Partner.php or similar) and add:

**Imports:**
```php
use App\Modules\Taxation\Domain\Enums\PartnerTaxStatus;
```

**Add to $casts:**
```php
'tax_status' => PartnerTaxStatus::class,
'tax_exemption_valid_until' => 'date',
```

**Add relationship:**
```php
public function taxExemptionCertificate()
{
    return $this->belongsTo(\App\Modules\Media\Domain\Media::class, 'tax_exemption_certificate_media_id');
}
```

**Add methods:**
```php
public function hasValidTaxExemption(): bool
{
    if ($this->tax_status !== PartnerTaxStatus::EXEMPT) {
        return false;
    }
    
    if (!$this->tax_exemption_certificate_media_id) {
        return false;
    }
    
    if ($this->tax_exemption_valid_until && $this->tax_exemption_valid_until->isPast()) {
        return false;
    }
    
    return true;
}

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

### 3. Update Company Model

Find the Company model (at App\Modules\Company\Domain\Company.php) and add:

**Import:**
```php
use App\Modules\Taxation\Domain\Enums\CompanyTaxStatus;
```

**Add to $casts:**
```php
'tax_status' => CompanyTaxStatus::class,
```

**Add method:**
```php
public function canRecoverVAT(): bool
{
    return $this->tax_status === CompanyTaxStatus::REGISTERED;
}
```

### 4. Create DocumentTaxDetail Model

Create new model at `app/Modules/Taxation/Domain/Entities/DocumentTaxDetail.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Taxation\Domain\Entities;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentTaxDetail extends Model
{
    use HasUuids;
    
    protected $table = 'document_tax_details';
    
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
        // Adjust namespace based on actual Document model location
        return $this->belongsTo(\App\Modules\Document\Domain\Document::class);
    }
    
    protected static function booted(): void
    {
        static::updating(function (self $detail) {
            if ($detail->document && method_exists($detail->document, 'isFinalized') && $detail->document->isFinalized()) {
                throw new \DomainException('Cannot modify tax details of a finalized document');
            }
        });
        
        static::deleting(function (self $detail) {
            if ($detail->document && method_exists($detail->document, 'isFinalized') && $detail->document->isFinalized()) {
                throw new \DomainException('Cannot delete tax details of a finalized document');
            }
        });
    }
}
```

After updating all models, run PHPStan:
```bash
./vendor/bin/phpstan analyse app/Modules/Taxation/ app/Modules/Partner/ app/Modules/Company/ --level=5
```

Fix any errors before proceeding.
```

---

## PROMPT 5: Create DTOs and Update TaxCalculationService

```
Create the Data Transfer Objects and update the TaxCalculationService for the new tax calculation logic with stacking support.

### 1. Create CalculatedTax DTO

Location: `app/Modules/Taxation/Domain/DTOs/CalculatedTax.php`

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

### 2. Create TaxCalculationResult DTO

Location: `app/Modules/Taxation/Domain/DTOs/TaxCalculationResult.php`

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

### 3. Update TaxCalculationService

Find the existing TaxCalculationService and either update it or replace the calculation method. The service needs to:

1. Get applicable taxes for the document type, ordered by sequence
2. Calculate each tax respecting stacking behavior
3. Handle both LINE_ITEMS and DOCUMENT_TOTAL application levels
4. Return exemption info if partner is exempt
5. Provide a method to snapshot tax details when document is finalized

Key method signature:
```php
public function calculateDocumentTaxes(Document $document): TaxCalculationResult
```

The full implementation is in TAX_MANAGEMENT_IMPLEMENTATION_SPEC.md section 2.4.1.

**Important considerations:**

- Use bcmath functions (bcadd, bcsub, bcmul, bcdiv) for all calculations
- Scale of 3 decimal places for amounts
- Scale of 6 for intermediate rate calculations
- Handle compound taxes: when stacks_on = SUBTOTAL_PLUS_PREVIOUS_TAXES, the base includes previous taxes
- Fixed amount taxes ignore the base and just add their amount
- Document-level taxes (stamps) apply once to the whole document
- Line-item taxes calculate per line then sum

Also add the snapshot method:
```php
public function snapshotTaxDetails(Document $document, TaxCalculationResult $result): void
```

This creates immutable DocumentTaxDetail records when a document is finalized.

After implementation, create a simple test:
```bash
php artisan tinker
```

Then test the calculation manually with a mock scenario to verify the math is correct.
```

---

## PROMPT 6: Create API Controller and Routes

```
Create the API controller for tax configuration CRUD operations and add the routes.

### 1. Create TaxConfigurationController

Location: `app/Modules/Taxation/Presentation/Controllers/TaxConfigurationController.php`

The controller should have these methods:
- index() - List tax configurations for current company's country
- store() - Create new tax configuration
- show($id) - Get single tax configuration
- update($id) - Update tax configuration
- destroy($id) - Soft delete (set is_active = false)
- reorder() - Bulk update sequence_order
- documentTypes() - Get available document types for dropdown

Full implementation is in TAX_MANAGEMENT_IMPLEMENTATION_SPEC.md section 2.5.1.

**Validation rules for store/update:**
- name: required, string, max 100
- code: nullable, string, max 50 (auto-generate if empty)
- tax_type: required, in:PERCENTAGE,FIXED_AMOUNT
- percentage_rate: required_if tax_type=PERCENTAGE, numeric, 0-100
- fixed_amount: required_if tax_type=FIXED_AMOUNT, numeric, min 0
- applies_to: required, in:LINE_ITEMS,DOCUMENT_TOTAL
- sequence_order: nullable, integer, min 1
- stacks_on: nullable, in:BASE_AMOUNT,SUBTOTAL_PLUS_PREVIOUS_TAXES
- applicable_document_types: nullable, array of strings
- is_active: nullable, boolean

### 2. Add Routes

Find the appropriate routes file for the Taxation module (likely `app/Modules/Taxation/Presentation/routes.php` or similar) and add:

```php
Route::middleware(['auth:sanctum'])->prefix('api')->group(function () {
    Route::get('/tax-configurations', [TaxConfigurationController::class, 'index']);
    Route::post('/tax-configurations', [TaxConfigurationController::class, 'store']);
    Route::get('/tax-configurations/document-types', [TaxConfigurationController::class, 'documentTypes']);
    Route::get('/tax-configurations/{id}', [TaxConfigurationController::class, 'show']);
    Route::put('/tax-configurations/{id}', [TaxConfigurationController::class, 'update']);
    Route::delete('/tax-configurations/{id}', [TaxConfigurationController::class, 'destroy']);
    Route::post('/tax-configurations/reorder', [TaxConfigurationController::class, 'reorder']);
});
```

If the module uses a different routing pattern, adapt accordingly.

### 3. Add Partner Tax Status Endpoint

Add to the Partner module's controller (or create a new endpoint):

```php
// GET /api/partners/{id}/tax-status
public function taxStatus(string $id): JsonResponse
{
    $partner = Partner::findOrFail($id);
    
    return response()->json([
        'data' => [
            'tax_status' => $partner->tax_status->value,
            'tax_status_label' => $partner->tax_status->label(),
            'has_valid_exemption' => $partner->hasValidTaxExemption(),
            'warnings' => $partner->getTaxExemptionWarnings(),
            'exemption_reason' => $partner->tax_exemption_reason,
            'exemption_valid_until' => $partner->tax_exemption_valid_until?->format('Y-m-d'),
        ],
    ]);
}
```

### 4. Test the Endpoints

After creating the controller and routes:

```bash
# Clear route cache
php artisan route:clear

# List routes to verify
php artisan route:list --path=tax

# Test with curl or Postman
```

Run PHPStan on the new controller:
```bash
./vendor/bin/phpstan analyse app/Modules/Taxation/Presentation/Controllers/ --level=5
```
```

---

## PROMPT 7: Create Tunisia Seeder

```
Create the seeder for Tunisia's default tax configurations.

### Create TunisiaTaxConfigurationSeeder

Location: `database/seeders/TunisiaTaxConfigurationSeeder.php`

```php
<?php

namespace Database\Seeders;

use App\Modules\Taxation\Domain\Entities\TaxConfiguration;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

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
                'percentage_rate' => '19.00',
                'is_default' => true,
                'sequence_order' => 1,
            ],
            [
                'name' => 'TVA 13%',
                'code' => 'TVA_13', 
                'percentage_rate' => '13.00',
                'is_default' => false,
                'sequence_order' => 2,
            ],
            [
                'name' => 'TVA 7%',
                'code' => 'TVA_7',
                'percentage_rate' => '7.00',
                'is_default' => false,
                'sequence_order' => 3,
            ],
            [
                'name' => 'Exonéré TVA',
                'code' => 'TVA_EXEMPT',
                'percentage_rate' => '0.00',
                'is_default' => false,
                'sequence_order' => 4,
            ],
        ];
        
        foreach ($vatRates as $rate) {
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
                    'sequence_order' => $rate['sequence_order'],
                    'stacks_on' => 'BASE_AMOUNT',
                    'applicable_document_types' => json_encode([
                        'TAX_INVOICE',
                        'FISCAL_RECEIPT', 
                        'CREDIT_NOTE',
                        'PURCHASE_INVOICE',
                        'DELIVERY_NOTE',
                        'QUOTATION',
                    ]),
                    'is_stamp_duty' => false,
                ]
            );
        }
        
        $this->command->info('Tunisia VAT rates seeded.');
    }
    
    private function seedStampDuties(): void
    {
        $stampDuties = [
            [
                'name' => 'Timbre Fiscal - Facture',
                'code' => 'STAMP_TAX_INVOICE',
                'amount' => '1.000',
                'document_types' => ['TAX_INVOICE'],
            ],
            [
                'name' => 'Timbre Fiscal - Ticket',
                'code' => 'STAMP_FISCAL_RECEIPT',
                'amount' => '0.100',
                'document_types' => ['FISCAL_RECEIPT'],
            ],
            [
                'name' => 'Timbre Fiscal - Avoir',
                'code' => 'STAMP_CREDIT_NOTE',
                'amount' => '0.600',
                'document_types' => ['CREDIT_NOTE'],
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
                    'sequence_order' => 99,
                    'stacks_on' => 'BASE_AMOUNT',
                    'applicable_document_types' => json_encode($stamp['document_types']),
                    'is_stamp_duty' => true,
                ]
            );
        }
        
        $this->command->info('Tunisia stamp duties seeded.');
    }
}
```

### Run the Seeder

```bash
php artisan db:seed --class=TunisiaTaxConfigurationSeeder
```

### Verify Seeded Data

```bash
php artisan tinker --execute="App\Modules\Taxation\Domain\Entities\TaxConfiguration::where('country_code', 'TN')->get(['code', 'name', 'tax_type', 'percentage_rate', 'fixed_amount', 'sequence_order'])->toArray()"
```

Expected output should show:
- 4 VAT rates (19%, 13%, 7%, 0%)
- 3 stamp duties (1.000, 0.100, 0.600)
- All with correct sequence orders
```

---

## PROMPT 8: Write Backend Tests

```
Create comprehensive tests for the tax management functionality.

### 1. Unit Tests for TaxCalculationService

Location: `tests/Unit/Taxation/TaxCalculationServiceTest.php`

Test cases to implement:

```php
/** @test */
public function it_calculates_single_percentage_tax()
{
    // Setup: 100.000 subtotal, 19% VAT
    // Expected: tax = 19.000, total = 119.000
}

/** @test */
public function it_calculates_fixed_amount_tax()
{
    // Setup: 100.000 subtotal, 1.000 stamp duty
    // Expected: tax = 1.000, total = 101.000
}

/** @test */
public function it_calculates_multiple_taxes_in_sequence()
{
    // Setup: 100.000 subtotal, 19% VAT (seq 1), 1.000 stamp (seq 99)
    // Expected: VAT = 19.000, stamp = 1.000, total = 120.000
}

/** @test */
public function it_calculates_compound_taxes()
{
    // Setup: 100.000 subtotal
    // Tax 1: 10% (seq 1, stacks_on = BASE_AMOUNT) = 10.000
    // Tax 2: 5% (seq 2, stacks_on = SUBTOTAL_PLUS_PREVIOUS_TAXES) = 5.500 (on 110)
    // Expected: total tax = 15.500, total = 115.500
}

/** @test */
public function it_filters_taxes_by_document_type()
{
    // Setup: Tax configured for TAX_INVOICE only
    // Document type: FISCAL_RECEIPT
    // Expected: Tax not applied
}

/** @test */
public function it_applies_tax_when_document_type_matches()
{
    // Setup: Tax configured for TAX_INVOICE
    // Document type: TAX_INVOICE  
    // Expected: Tax applied
}

/** @test */
public function it_applies_tax_when_no_document_types_specified()
{
    // Setup: Tax with empty applicable_document_types (applies to all)
    // Expected: Tax applied
}

/** @test */
public function it_returns_exemption_info_for_exempt_partner()
{
    // Setup: Partner with tax_status = EXEMPT
    // Expected: exemptionInfo populated with warnings
}

/** @test */
public function it_warns_about_missing_certificate()
{
    // Setup: Partner EXEMPT, no certificate
    // Expected: warning type = 'missing_certificate'
}

/** @test */
public function it_warns_about_expired_certificate()
{
    // Setup: Partner EXEMPT, certificate expired
    // Expected: warning type = 'expired_certificate'
}

/** @test */
public function it_warns_about_expiring_certificate()
{
    // Setup: Partner EXEMPT, certificate expires in 15 days
    // Expected: warning type = 'expiring_soon'
}
```

### 2. Feature Tests for API

Location: `tests/Feature/Taxation/TaxConfigurationApiTest.php`

```php
/** @test */
public function user_can_list_tax_configurations()
{
    // GET /api/tax-configurations
    // Assert: returns taxes for user's company country only
}

/** @test */
public function user_can_create_percentage_tax()
{
    // POST with percentage data
    // Assert: 201, correct values stored
}

/** @test */
public function user_can_create_fixed_amount_tax()
{
    // POST with fixed amount data
    // Assert: 201, correct values stored
}

/** @test */
public function validation_requires_percentage_rate_for_percentage_type()
{
    // POST with tax_type=PERCENTAGE but no percentage_rate
    // Assert: 422 validation error
}

/** @test */
public function validation_requires_fixed_amount_for_fixed_type()
{
    // POST with tax_type=FIXED_AMOUNT but no fixed_amount
    // Assert: 422 validation error
}

/** @test */
public function user_can_update_tax_configuration()
{
    // PUT with updated data
    // Assert: 200, values updated
}

/** @test */
public function user_can_reorder_taxes()
{
    // POST /api/tax-configurations/reorder
    // Assert: sequence_order updated correctly
}

/** @test */
public function delete_sets_inactive_not_hard_delete()
{
    // DELETE /api/tax-configurations/{id}
    // Assert: is_active = false, record still exists
}
```

### 3. Immutability Tests

Location: `tests/Feature/Taxation/TaxImmutabilityTest.php`

```php
/** @test */
public function finalized_document_stores_tax_snapshot()
{
    // Create and finalize document
    // Assert: document_tax_details records created with correct values
}

/** @test */
public function tax_config_change_does_not_affect_finalized_documents()
{
    // Create document with 19% VAT, finalize
    // Change tax config to 20%
    // Assert: document tax details still show 19%
}

/** @test */
public function cannot_update_tax_details_of_finalized_document()
{
    // Finalize document
    // Try to update DocumentTaxDetail
    // Assert: DomainException thrown
}

/** @test */
public function cannot_delete_tax_details_of_finalized_document()
{
    // Finalize document
    // Try to delete DocumentTaxDetail
    // Assert: DomainException thrown
}

/** @test */
public function can_modify_tax_details_of_draft_document()
{
    // Create draft document
    // Update/delete tax details
    // Assert: no exception, changes applied
}
```

### Run Tests

```bash
# Run all tax-related tests
php artisan test --filter=Tax

# Run with coverage
php artisan test --filter=Tax --coverage
```

Fix any failing tests before proceeding to frontend.
```

---

## PROMPT 9: Frontend - API Client and Types

```
Now let's implement the frontend. Start with the TypeScript types and API client.

### 1. Create Types

Location: `apps/web/src/features/settings/types/tax.ts`

```typescript
export type TaxType = 'PERCENTAGE' | 'FIXED_AMOUNT';
export type TaxApplicationLevel = 'LINE_ITEMS' | 'DOCUMENT_TOTAL';
export type StackingBehavior = 'BASE_AMOUNT' | 'SUBTOTAL_PLUS_PREVIOUS_TAXES';
export type CompanyTaxStatus = 'REGISTERED' | 'NON_REGISTERED';
export type PartnerTaxStatus = 'REGISTERED' | 'NON_REGISTERED' | 'EXEMPT';

export interface TaxConfiguration {
  id: string;
  country_code: string;
  name: string;
  code: string;
  tax_type: TaxType;
  percentage_rate: string | null;
  fixed_amount: string | null;
  applies_to: TaxApplicationLevel;
  sequence_order: number;
  stacks_on: StackingBehavior;
  applicable_document_types: string[];
  is_default: boolean;
  is_active: boolean;
  is_stamp_duty: boolean;
  created_at: string;
  updated_at: string;
}

export interface TaxConfigurationFormData {
  name: string;
  code?: string;
  tax_type: TaxType;
  percentage_rate?: string;
  fixed_amount?: string;
  applies_to: TaxApplicationLevel;
  sequence_order?: number;
  stacks_on: StackingBehavior;
  applicable_document_types: string[];
  is_active: boolean;
}

export interface DocumentType {
  value: string;
  label: string;
}

export interface TaxExemptionWarning {
  type: 'missing_certificate' | 'expired_certificate' | 'expiring_soon';
  message: string;
  severity: 'error' | 'warning';
}

export interface PartnerTaxInfo {
  tax_status: PartnerTaxStatus;
  tax_status_label: string;
  has_valid_exemption: boolean;
  warnings: TaxExemptionWarning[];
  exemption_reason: string | null;
  exemption_valid_until: string | null;
}

export interface CalculatedTax {
  configuration_id: string;
  code: string;
  name: string;
  type: TaxType;
  rate: string | null;
  fixed_amount: string | null;
  base: string;
  amount: string;
  sequence_order: number;
  is_stamp_duty: boolean;
}

export interface TaxCalculationResult {
  taxes: CalculatedTax[];
  subtotal: string;
  line_items_tax_total: string;
  document_tax_total: string;
  total_tax: string;
  total: string;
  exemption_info: {
    status: string;
    reason: string | null;
    hasValidCertificate: boolean;
    warnings: TaxExemptionWarning[];
  } | null;
}
```

### 2. Create API Client

Location: `apps/web/src/features/settings/api/taxConfigurationApi.ts`

```typescript
import { apiClient } from '@/lib/api';
import type { 
  TaxConfiguration, 
  TaxConfigurationFormData,
  DocumentType,
} from '../types/tax';

interface ListResponse<T> {
  data: T[];
  meta?: Record<string, unknown>;
}

interface SingleResponse<T> {
  data: T;
}

export const taxConfigurationApi = {
  list: () => 
    apiClient.get<ListResponse<TaxConfiguration>>('/tax-configurations'),
  
  get: (id: string) =>
    apiClient.get<SingleResponse<TaxConfiguration>>(`/tax-configurations/${id}`),
  
  create: (data: TaxConfigurationFormData) =>
    apiClient.post<SingleResponse<TaxConfiguration>>('/tax-configurations', data),
  
  update: (id: string, data: Partial<TaxConfigurationFormData>) =>
    apiClient.put<SingleResponse<TaxConfiguration>>(`/tax-configurations/${id}`, data),
  
  delete: (id: string) =>
    apiClient.delete(`/tax-configurations/${id}`),
  
  reorder: (order: { id: string; sequence_order: number }[]) =>
    apiClient.post('/tax-configurations/reorder', { order }),
  
  getDocumentTypes: () =>
    apiClient.get<ListResponse<DocumentType>>('/tax-configurations/document-types'),
};
```

### 3. Create React Query Hooks

Location: `apps/web/src/features/settings/hooks/useTaxConfigurations.ts`

```typescript
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { taxConfigurationApi } from '../api/taxConfigurationApi';
import type { TaxConfigurationFormData } from '../types/tax';

export const taxConfigurationKeys = {
  all: ['tax-configurations'] as const,
  list: () => [...taxConfigurationKeys.all, 'list'] as const,
  detail: (id: string) => [...taxConfigurationKeys.all, 'detail', id] as const,
  documentTypes: () => [...taxConfigurationKeys.all, 'document-types'] as const,
};

export function useTaxConfigurations() {
  return useQuery({
    queryKey: taxConfigurationKeys.list(),
    queryFn: () => taxConfigurationApi.list(),
    select: (response) => response.data,
  });
}

export function useTaxConfiguration(id: string) {
  return useQuery({
    queryKey: taxConfigurationKeys.detail(id),
    queryFn: () => taxConfigurationApi.get(id),
    select: (response) => response.data,
    enabled: !!id,
  });
}

export function useDocumentTypes() {
  return useQuery({
    queryKey: taxConfigurationKeys.documentTypes(),
    queryFn: () => taxConfigurationApi.getDocumentTypes(),
    select: (response) => response.data,
    staleTime: Infinity, // Document types don't change often
  });
}

export function useCreateTaxConfiguration() {
  const queryClient = useQueryClient();
  
  return useMutation({
    mutationFn: (data: TaxConfigurationFormData) => 
      taxConfigurationApi.create(data),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: taxConfigurationKeys.list() });
    },
  });
}

export function useUpdateTaxConfiguration() {
  const queryClient = useQueryClient();
  
  return useMutation({
    mutationFn: ({ id, data }: { id: string; data: Partial<TaxConfigurationFormData> }) =>
      taxConfigurationApi.update(id, data),
    onSuccess: (_, variables) => {
      queryClient.invalidateQueries({ queryKey: taxConfigurationKeys.list() });
      queryClient.invalidateQueries({ queryKey: taxConfigurationKeys.detail(variables.id) });
    },
  });
}

export function useDeleteTaxConfiguration() {
  const queryClient = useQueryClient();
  
  return useMutation({
    mutationFn: (id: string) => taxConfigurationApi.delete(id),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: taxConfigurationKeys.list() });
    },
  });
}

export function useReorderTaxConfigurations() {
  const queryClient = useQueryClient();
  
  return useMutation({
    mutationFn: (order: { id: string; sequence_order: number }[]) =>
      taxConfigurationApi.reorder(order),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: taxConfigurationKeys.list() });
    },
  });
}
```

### 4. Verify TypeScript Compilation

```bash
cd apps/web
npm run type-check
# or
npx tsc --noEmit
```

Fix any TypeScript errors before proceeding to UI components.
```

---

## PROMPT 10: Frontend - Tax Settings Page UI

```
Create the Tax Settings page with tabs for Company Profile and Tax Types management.

### 1. Create TaxSettingsPage

Location: `apps/web/src/features/settings/pages/TaxSettingsPage.tsx`

This page should have:
- Two tabs: "Company Tax Profile" and "Tax Types"
- Company Tax Profile tab: 
  - Radio buttons for tax status (REGISTERED / NON_REGISTERED)
  - VAT registration number input
- Tax Types tab:
  - Data table with all tax configurations
  - Sortable by sequence_order (drag-drop or arrows)
  - Add Tax button
  - Edit/Delete actions per row
  - Active toggle per row

### 2. Create TaxConfigurationTable Component

Location: `apps/web/src/features/settings/components/TaxConfigurationTable.tsx`

Table columns:
- Drag handle or order arrows
- Name
- Code
- Type (badge: Percentage / Fixed)
- Rate or Amount
- Applies To
- Document Types (truncated badges)
- Active (toggle)
- Actions (Edit, Delete buttons)

### 3. Create TaxConfigurationModal Component

Location: `apps/web/src/features/settings/components/TaxConfigurationModal.tsx`

Form fields:
- Name (required)
- Code (optional)
- Type: Radio group (Percentage / Fixed Amount)
- If Percentage: Rate input (0-100) with % suffix
- If Fixed: Amount input with currency
- Applies To: Radio group (Line Items / Document Total)
- Stacking: Select (Base Amount / Compound) - only show if not first tax
- Document Types: Multi-select checkboxes
- Active: Toggle

### 4. Add Translations

Find the translation file for settings and add:

```json
{
  "tax": {
    "pageTitle": "Tax Settings",
    "tabs": {
      "profile": "Company Tax Profile",
      "taxes": "Tax Types"
    },
    "profile": {
      "title": "VAT Registration Status",
      "description": "This affects how VAT is handled on purchases",
      "registered": "VAT Registered (Assujetti)",
      "registeredDescription": "You can recover VAT on purchases",
      "nonRegistered": "Not VAT Registered",
      "nonRegisteredDescription": "VAT on purchases is added to inventory cost",
      "vatNumber": "VAT Registration Number"
    },
    "table": {
      "name": "Name",
      "code": "Code", 
      "type": "Type",
      "rate": "Rate/Amount",
      "appliesTo": "Applies To",
      "documentTypes": "Document Types",
      "active": "Active",
      "actions": "Actions"
    },
    "form": {
      "addTitle": "Add Tax",
      "editTitle": "Edit Tax",
      "name": "Tax Name",
      "namePlaceholder": "e.g., TVA 19%",
      "code": "Tax Code",
      "codePlaceholder": "Auto-generated if empty",
      "typeLabel": "Calculation Type",
      "typePercentage": "Percentage",
      "typeFixed": "Fixed Amount",
      "rate": "Rate (%)",
      "amount": "Amount",
      "appliesTo": "Applies To",
      "appliesToLineItems": "Line Items",
      "appliesToDocument": "Document Total",
      "stacking": "Stacking Behavior",
      "stackingBase": "Calculate on base amount only",
      "stackingCompound": "Compound (on subtotal + previous taxes)",
      "documentTypes": "Applicable Document Types",
      "documentTypesHint": "Leave empty to apply to all document types",
      "active": "Active"
    },
    "messages": {
      "created": "Tax configuration created",
      "updated": "Tax configuration updated",
      "deleted": "Tax configuration deactivated",
      "reordered": "Tax order updated"
    },
    "confirmDelete": {
      "title": "Deactivate Tax?",
      "message": "This tax will be deactivated but not deleted. Existing documents will not be affected."
    }
  }
}
```

### 5. Add Route

Add the route to your router configuration:

```typescript
{
  path: '/settings/tax',
  element: <TaxSettingsPage />,
}
```

### 6. Add Navigation Link

Add a link to Tax Settings in the settings navigation menu.

After implementing, test:
1. Page loads without errors
2. Company profile tab shows current status
3. Tax types tab shows list of taxes
4. Can create new percentage tax
5. Can create new fixed amount tax
6. Can edit existing tax
7. Can toggle active status
8. Can reorder taxes
```

---

## PROMPT 11: Frontend - Partner Tax Fields

```
Add tax-related fields to the Partner form.

### 1. Update Partner Form

Find the partner creation/edit form and add a "Tax Information" section with:

**Tax Status Field:**
- Label: "Tax Status"
- Type: Select/Dropdown
- Options:
  - REGISTERED: "VAT Registered"
  - NON_REGISTERED: "Not VAT Registered"  
  - EXEMPT: "Tax Exempt"
- Default: REGISTERED

**Conditional Fields (when status = EXEMPT):**
- Exemption Reason: Textarea
- Exemption Certificate: File upload (use existing Media/file upload component)
- Valid Until: Date picker

**Warning Badges:**
Show warning alert if partner is EXEMPT and:
- No certificate uploaded (severity: error)
- Certificate expired (severity: error)
- Certificate expiring within 30 days (severity: warning)

### 2. Update Partner Types

Add to partner type definition:

```typescript
interface Partner {
  // ... existing fields
  tax_status: 'REGISTERED' | 'NON_REGISTERED' | 'EXEMPT';
  tax_exemption_reason: string | null;
  tax_exemption_certificate_media_id: string | null;
  tax_exemption_valid_until: string | null;
}
```

### 3. Create TaxExemptionAlert Component

Location: `apps/web/src/features/partners/components/TaxExemptionAlert.tsx`

Shows warnings based on partner tax status:

```tsx
interface Props {
  partner: Partner;
  warnings: TaxExemptionWarning[];
}

function TaxExemptionAlert({ partner, warnings }: Props) {
  if (partner.tax_status !== 'EXEMPT') return null;
  if (warnings.length === 0) return null;
  
  return (
    <Alert variant={warnings.some(w => w.severity === 'error') ? 'destructive' : 'warning'}>
      <AlertTriangle className="h-4 w-4" />
      <AlertTitle>Tax Exemption Warnings</AlertTitle>
      <AlertDescription>
        <ul>
          {warnings.map((warning, i) => (
            <li key={i}>{warning.message}</li>
          ))}
        </ul>
      </AlertDescription>
    </Alert>
  );
}
```

### 4. Add Translations

```json
{
  "partner": {
    "taxInfo": {
      "title": "Tax Information",
      "status": "Tax Status",
      "statusRegistered": "VAT Registered",
      "statusNonRegistered": "Not VAT Registered",
      "statusExempt": "Tax Exempt",
      "exemptionReason": "Exemption Reason",
      "exemptionReasonPlaceholder": "Describe why this partner is tax exempt",
      "certificate": "Exemption Certificate",
      "certificateHint": "Upload the tax exemption certificate",
      "validUntil": "Certificate Valid Until",
      "warnings": {
        "missingCertificate": "No exemption certificate on file",
        "expiredCertificate": "Exemption certificate has expired",
        "expiringSoon": "Exemption certificate is expiring soon"
      }
    }
  }
}
```

### 5. Test the Partner Form

1. Create new partner, verify tax status defaults to REGISTERED
2. Change status to EXEMPT, verify additional fields appear
3. Save partner with EXEMPT status but no certificate, verify warning shows
4. Upload certificate, set expiry date, save
5. Edit existing partner, verify all fields load correctly
```

---

## PROMPT 12: Frontend - Document Tax Exemption Notice

```
Add tax exemption notice to sales document forms (Invoice, Receipt, etc.)

### 1. Create PartnerTaxExemptionNotice Component

Location: `apps/web/src/features/documents/components/PartnerTaxExemptionNotice.tsx`

```tsx
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { AlertTriangle, Info } from 'lucide-react';
import type { PartnerTaxInfo } from '@/features/settings/types/tax';

interface Props {
  partnerTaxInfo: PartnerTaxInfo | null;
}

export function PartnerTaxExemptionNotice({ partnerTaxInfo }: Props) {
  if (!partnerTaxInfo || partnerTaxInfo.tax_status !== 'EXEMPT') {
    return null;
  }

  const hasErrors = partnerTaxInfo.warnings.some(w => w.severity === 'error');

  return (
    <Alert variant={hasErrors ? 'destructive' : 'default'}>
      <AlertTriangle className="h-4 w-4" />
      <AlertTitle>Tax Exempt Customer</AlertTitle>
      <AlertDescription className="space-y-2">
        <p>
          This customer is marked as tax-exempt.
          {partnerTaxInfo.exemption_reason && (
            <span className="block text-sm text-muted-foreground">
              Reason: {partnerTaxInfo.exemption_reason}
            </span>
          )}
        </p>
        
        {partnerTaxInfo.warnings.length > 0 && (
          <ul className="list-disc list-inside text-sm">
            {partnerTaxInfo.warnings.map((warning, i) => (
              <li key={i} className={warning.severity === 'error' ? 'text-destructive' : 'text-warning'}>
                {warning.message}
              </li>
            ))}
          </ul>
        )}
        
        <p className="text-sm text-muted-foreground">
          <Info className="inline h-3 w-3 mr-1" />
          Verify the exemption certificate before applying 0% tax rate. 
          Add a tax mention to the document if required.
        </p>
      </AlertDescription>
    </Alert>
  );
}
```

### 2. Add Partner Tax Status Hook

Location: `apps/web/src/features/partners/hooks/usePartnerTaxStatus.ts`

```typescript
import { useQuery } from '@tanstack/react-query';
import { apiClient } from '@/lib/api';
import type { PartnerTaxInfo } from '@/features/settings/types/tax';

export function usePartnerTaxStatus(partnerId: string | null) {
  return useQuery({
    queryKey: ['partners', partnerId, 'tax-status'],
    queryFn: async () => {
      const response = await apiClient.get<{ data: PartnerTaxInfo }>(
        `/partners/${partnerId}/tax-status`
      );
      return response.data;
    },
    enabled: !!partnerId,
    staleTime: 1000 * 60 * 5, // 5 minutes
  });
}
```

### 3. Update Document Form

In the sales document form (Invoice, Receipt, Quotation, etc.):

1. When a partner is selected, fetch their tax status
2. If partner is EXEMPT, show the PartnerTaxExemptionNotice component
3. Add a `tax_mention` textarea field below the notice

```tsx
// In document form component:

const { data: partnerTaxInfo } = usePartnerTaxStatus(selectedPartnerId);

// In the JSX:
{partnerTaxInfo?.tax_status === 'EXEMPT' && (
  <>
    <PartnerTaxExemptionNotice partnerTaxInfo={partnerTaxInfo} />
    
    <FormField
      control={form.control}
      name="tax_mention"
      render={({ field }) => (
        <FormItem>
          <FormLabel>Tax Mention</FormLabel>
          <FormControl>
            <Textarea
              placeholder="e.g., Exonéré TVA - Certificat #123 du 01/01/2025"
              {...field}
            />
          </FormControl>
          <FormDescription>
            Legal text to appear on the document explaining the tax exemption
          </FormDescription>
        </FormItem>
      )}
    />
  </>
)}
```

### 4. Add to Document Display/Print

When displaying or printing a finalized document that has a `tax_mention`:

```tsx
{document.tax_mention && (
  <div className="text-sm italic border-t pt-2 mt-4">
    {document.tax_mention}
  </div>
)}
```

### 5. Add Translations

```json
{
  "document": {
    "taxMention": {
      "label": "Tax Mention",
      "placeholder": "e.g., Exonéré TVA - Certificat #123",
      "description": "Legal text explaining tax exemption (will appear on document)"
    },
    "exemptionNotice": {
      "title": "Tax Exempt Customer",
      "verifyWarning": "Verify the exemption certificate before applying 0% tax rate."
    }
  }
}
```

### 6. Test the Flow

1. Create a partner with EXEMPT status
2. Create a new invoice, select the exempt partner
3. Verify exemption notice appears
4. Add text to tax_mention field
5. If certificate missing/expired, verify warnings show
6. Finalize document
7. View/print document, verify tax_mention appears
```

---

## PROMPT 13: Final Integration Testing

```
Perform comprehensive integration testing of the entire tax management system.

### 1. Backend Integration Tests

Run all tax-related tests:
```bash
php artisan test --filter=Tax
```

### 2. Full Flow Test Script

Create a test script at `tests/Scripts/tax_full_flow_test.php` or run in tinker:

```php
// 1. Verify Tunisia taxes are seeded
$taxes = TaxConfiguration::where('country_code', 'TN')
    ->where('is_active', true)
    ->ordered()
    ->get();
    
assert($taxes->count() >= 4, 'Should have at least 4 Tunisia taxes');

// 2. Test single tax calculation
// Create a test document with 100 TND subtotal
// Apply 19% VAT
// Expected: tax = 19, total = 119

// 3. Test multiple taxes
// Apply 19% VAT + 1 TND stamp
// Expected: VAT = 19, stamp = 1, total = 120

// 4. Test compound tax (if configured)
// Tax 1: 10% on base
// Tax 2: 5% on base + previous taxes
// Expected: correct compound calculation

// 5. Test document type filtering
// Create stamp that only applies to TAX_INVOICE
// Create FISCAL_RECEIPT document
// Expected: stamp not applied

// 6. Test partner exemption flow
// Create exempt partner
// Expected: warnings returned in calculation result

// 7. Test immutability
// Finalize document
// Change tax rate in configuration
// Expected: document still has old rate in snapshot
```

### 3. Frontend Integration Tests

Manual testing checklist:

**Tax Settings Page:**
- [ ] Page loads without errors
- [ ] Company tax status can be changed
- [ ] Tax list displays all taxes
- [ ] Can create new percentage tax
- [ ] Can create new fixed amount tax
- [ ] Can edit existing tax
- [ ] Can reorder taxes (sequence updates)
- [ ] Can deactivate tax
- [ ] Document types dropdown populates
- [ ] Stacking option works correctly

**Partner Form:**
- [ ] Tax status field appears
- [ ] Changing to EXEMPT shows additional fields
- [ ] Certificate upload works
- [ ] Date picker works
- [ ] Warnings display correctly
- [ ] Saves correctly

**Document Form:**
- [ ] Selecting exempt partner shows notice
- [ ] Warnings from partner display
- [ ] Tax mention field appears
- [ ] Tax calculation reflects correct rates
- [ ] Stamp duty applies to correct document types
- [ ] Document finalizes with tax snapshot

**Document Display:**
- [ ] Tax breakdown shows all taxes
- [ ] Tax mention displays if present
- [ ] Print view includes tax mention

### 4. Edge Cases to Test

- [ ] Tax with 0% rate (exempt tax option)
- [ ] Multiple stamps (different amounts per document type)
- [ ] Partner with expired certificate
- [ ] Partner with certificate expiring in 15 days
- [ ] Changing company tax status
- [ ] Creating tax with empty document types (applies to all)
- [ ] Reordering to make stamp first (should still calculate correctly)

### 5. PHPStan and TypeScript Verification

```bash
# Backend
./vendor/bin/phpstan analyse app/Modules/Taxation/ --level=5

# Frontend
cd apps/web && npm run type-check
```

### 6. Generate Final Report

Create a file documenting:
- What was implemented
- What works
- Any known issues or limitations
- Recommended follow-up tasks
```

---

## Summary: Prompt Execution Order

1. **PROMPT 1**: Verification & Gap Analysis (do NOT implement yet)
2. **PROMPT 2**: Create Migrations
3. **PROMPT 3**: Create Enums
4. **PROMPT 4**: Update Models
5. **PROMPT 5**: Create DTOs & Update Service
6. **PROMPT 6**: Create API Controller & Routes
7. **PROMPT 7**: Create Tunisia Seeder
8. **PROMPT 8**: Write Backend Tests
9. **PROMPT 9**: Frontend Types & API Client
10. **PROMPT 10**: Tax Settings Page UI
11. **PROMPT 11**: Partner Tax Fields
12. **PROMPT 12**: Document Exemption Notice
13. **PROMPT 13**: Final Integration Testing

**Important**: Wait for each prompt to complete successfully before moving to the next. If issues arise, fix them before proceeding.
