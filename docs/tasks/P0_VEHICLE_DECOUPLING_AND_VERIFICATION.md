# P0: Vehicle Decoupling + Feature Verification

## Objective

This task has TWO parts:
1. **Verify** that existing features work as documented
2. **Decouple** Vehicle from Document module to make it universal

## Part A: Verify Existing Features

Before making changes, verify that the business logic the user described is actually implemented correctly. Document your findings for each.

### A1: Line-Level Delivery Notes

**Expected behavior:** Delivery notes track which specific invoice lines have been delivered, allowing:
- Multiple delivery notes per invoice
- Partial deliveries (some lines delivered, others pending)
- Line-by-line delivery tracking

**Verification steps:**

```bash
# Find delivery note line structure
grep -rn "delivery.*line" database/migrations --include="*.php"
grep -rn "document_line_id\|invoice_line_id" database/migrations --include="*.php"

# Find the delivery note model and relationships
find app -name "*DeliveryNote*" -o -name "*Delivery*" | grep -v test
cat $(find app/Modules -name "DeliveryNote.php" | head -1)

# Check for line-level tracking
grep -rn "lines\|items" app/Modules/Document/Domain/DeliveryNote*.php 2>/dev/null
grep -rn "delivered_quantity\|quantity_delivered" app/Modules --include="*.php"
```

**Document:**
- [ ] Does `delivery_note_lines` or similar table exist?
- [ ] Does it reference specific invoice/document lines?
- [ ] Can multiple delivery notes reference the same invoice?
- [ ] Is `delivered_quantity` tracked per line?

### A2: Service-Only Invoices (No Delivery Notes)

**Expected behavior:** When an invoice contains only services (no products), no delivery note should be created or required.

**Verification steps:**

```bash
# Find product type distinction
grep -rn "is_service\|product_type\|type.*service" app/Modules/Product --include="*.php"
grep -rn "is_service\|product_type" app/Modules/Document --include="*.php"

# Find delivery note creation logic
grep -rn "createDeliveryNote\|create.*delivery" app/Modules/Document --include="*.php"

# Check for service filtering
grep -rn "service\|physical\|tangible" app/Modules/Document/Domain/Services --include="*.php"
```

**Document:**
- [ ] How are services distinguished from products? (flag, type enum, separate table?)
- [ ] Is there logic that skips delivery note creation for services?
- [ ] What happens with mixed invoices (products + services)?

### A3: Mixed Invoices (Products + Services)

**Expected behavior:** For invoices with both products and services:
- Delivery notes created ONLY for product lines
- Service lines are NOT included in delivery notes

**Verification steps:**

```bash
# Check line-level filtering in delivery note creation
grep -rn "product\|service" app/Modules/Document/Domain/Services/DeliveryNote*.php 2>/dev/null
grep -rn "is_physical\|needs_delivery\|deliverable" app/Modules --include="*.php"
```

**Document:**
- [ ] Is there a `is_deliverable` or similar flag on line items?
- [ ] Does delivery note creation filter out service lines?

### A4: Invoice Before/After Delivery

**Expected behavior:** Both flows should work:
- **Flow 1:** Create invoice → Post invoice → Create delivery note → Confirm delivery
- **Flow 2:** Create delivery note → Confirm delivery → Create invoice later (batch invoicing)

**Verification steps:**

```bash
# Check if delivery notes can exist without invoice
grep -rn "invoice_id.*nullable\|document_id.*nullable" database/migrations | grep -i delivery

# Check for batch invoicing
grep -rn "batch.*invoice\|invoice.*delivery\|uninvoiced" app/Modules --include="*.php"

# Find delivery note to invoice linking
grep -rn "invoice_id\|document_id" app/Modules/Document/Domain/DeliveryNote*.php 2>/dev/null
```

**Document:**
- [ ] Can delivery notes exist without an invoice (invoice_id nullable)?
- [ ] Is there a way to find unvoiced deliveries?
- [ ] How does batch invoicing work?

### A5: Fiscal Hash Chain on Documents

**Expected behavior:** Posted documents have:
- SHA-256 hash of document content
- Link to previous document's hash (chain)
- Sequential chain number
- Pessimistic locking to prevent race conditions

**Verification steps:**

```bash
# Check hash fields in documents
grep -rn "fiscal_hash\|previous_hash\|chain_sequence" database/migrations | grep -i document

# Check FiscalHashService
find app -name "*FiscalHash*" -o -name "*HashService*"
cat $(find app -name "FiscalHashService.php" | head -1)

# Check for pessimistic locking
grep -rn "lockForUpdate\|FOR UPDATE" app/Modules/Document --include="*.php"
```

**Document:**
- [ ] Are hash fields present on documents table?
- [ ] Does FiscalHashService exist and work correctly?
- [ ] Is pessimistic locking used during posting?

### A6: COGS GL Entry on Delivery Note

**Expected behavior:** When a delivery note is confirmed:
- Stock is decremented
- COGS GL entry is created (Debit COGS, Credit Inventory)

**Verification steps:**

```bash
# Find PostCOGSOnInvoice listener (mentioned in audit)
cat app/Modules/Inventory/Listeners/PostCOGSOnInvoice.php 2>/dev/null

# Check what event it listens to
grep -rn "InvoicePosted\|DeliveryNote" app/Modules/Inventory/Listeners --include="*.php"

# Check if stock is actually decremented
grep -rn "decrement\|subtract\|deduct\|reduce.*stock\|stock.*reduce" app/Modules/Inventory --include="*.php"
```

**Document:**
- [ ] Does COGS entry get created? On what trigger?
- [ ] Is stock actually decremented? On what trigger?
- [ ] Is this in a transaction?

### A7: Verification Summary

Create a summary table:

| Feature | Expected | Actual Status | Files/Evidence | Notes |
|---------|----------|---------------|----------------|-------|
| Line-level delivery notes | ✅ | ✅/⚠️/❌ | path/to/file | |
| Service-only invoices skip delivery | ✅ | ✅/⚠️/❌ | | |
| Mixed invoices filter by type | ✅ | ✅/⚠️/❌ | | |
| Delivery before invoice | ✅ | ✅/⚠️/❌ | | |
| Invoice before delivery | ✅ | ✅/⚠️/❌ | | |
| Fiscal hash chain | ✅ | ✅/⚠️/❌ | | |
| COGS on delivery confirm | ✅ | ✅/⚠️/❌ | | |
| Stock decrement on delivery | ✅ | ✅/⚠️/❌ | | |

---

## Part B: Vehicle Decoupling

### B1: Audit Current Vehicle Usage

Find all places where Vehicle is referenced in the Document module:

```bash
# Find imports
grep -rn "use App\\\\Modules\\\\Vehicle" app/Modules/Document

# Find vehicle_id in migrations
grep -rn "vehicle_id" database/migrations | grep -i document

# Find vehicle relationship usage in models
grep -rn "->vehicle" app/Modules/Document
grep -rn "vehicle_id" app/Modules/Document

# Find in controllers/services
grep -rn "vehicle" app/Modules/Document/Presentation --include="*.php"
grep -rn "vehicle" app/Modules/Document/Application --include="*.php"
grep -rn "vehicle" app/Modules/Document/Domain/Services --include="*.php"

# Find in tests
grep -rn "vehicle" tests --include="*Document*"

# Find in frontend (if accessible)
grep -rn "vehicle_id\|vehicle" apps/web/src --include="*.tsx" --include="*.ts" | grep -i document
```

**Document your findings:**

| Location | File | Line | Usage | Migration Plan |
|----------|------|------|-------|----------------|
| Import | Document.php | 18 | `use Vehicle` | Remove |
| Column | documents migration | ? | `vehicle_id` FK | Move to context table |
| Relationship | Document.php | ? | `vehicle()` | Remove, add `vehicleContext()` |
| Controller | ? | ? | ? | Update to use context |

### B2: Check Existing Data

```bash
# Count documents with vehicle_id
php artisan tinker --execute="
    echo 'Documents with vehicle_id: ' . \App\Modules\Document\Domain\Document::whereNotNull('vehicle_id')->count();
    echo PHP_EOL;
    echo 'Total documents: ' . \App\Modules\Document\Domain\Document::count();
"
```

### B3: Create Migration

Based on Strategy B (linking table), create the migration:

```php
<?php
// database/migrations/YYYY_MM_DD_HHMMSS_decouple_vehicle_from_documents.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Create the linking table
        Schema::create('document_vehicle_contexts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('document_id')->unique();
            $table->uuid('vehicle_id');
            $table->integer('mileage_at_service')->nullable();
            $table->json('vehicle_snapshot')->nullable();
            $table->timestamps();
            
            $table->foreign('document_id')
                ->references('id')
                ->on('documents')
                ->onDelete('cascade');
                
            // Note: We don't add FK to vehicles table to keep Document module independent
            // The vehicle_id is validated at application level in the Vehicle module
            $table->index('vehicle_id', 'dvc_vehicle_id_index');
        });
        
        // 2. Migrate existing data
        DB::statement("
            INSERT INTO document_vehicle_contexts (id, document_id, vehicle_id, created_at, updated_at)
            SELECT 
                gen_random_uuid(), 
                d.id, 
                d.vehicle_id, 
                COALESCE(d.created_at, NOW()), 
                COALESCE(d.updated_at, NOW())
            FROM documents d
            WHERE d.vehicle_id IS NOT NULL
        ");
        
        // 3. Optionally populate vehicle_snapshot for historical data
        // This query joins with vehicles table - run only if vehicles table exists
        // DB::statement("
        //     UPDATE document_vehicle_contexts dvc
        //     SET vehicle_snapshot = jsonb_build_object(
        //         'registration', v.registration,
        //         'vin', v.vin,
        //         'make', v.make,
        //         'model', v.model,
        //         'year', v.year
        //     )
        //     FROM vehicles v
        //     WHERE dvc.vehicle_id = v.id
        // ");
        
        // 4. Drop the foreign key constraint first
        Schema::table('documents', function (Blueprint $table) {
            // Check if FK exists before dropping
            $table->dropForeign(['vehicle_id']);
        });
        
        // 5. Drop the column
        Schema::table('documents', function (Blueprint $table) {
            $table->dropColumn('vehicle_id');
        });
    }

    public function down(): void
    {
        // 1. Add column back
        Schema::table('documents', function (Blueprint $table) {
            $table->uuid('vehicle_id')->nullable();
        });
        
        // 2. Restore data
        DB::statement("
            UPDATE documents d
            SET vehicle_id = dvc.vehicle_id
            FROM document_vehicle_contexts dvc
            WHERE d.id = dvc.document_id
        ");
        
        // 3. Add FK back (only if vehicles table exists)
        Schema::table('documents', function (Blueprint $table) {
            $table->foreign('vehicle_id')
                ->references('id')
                ->on('vehicles')
                ->onDelete('set null');
        });
        
        // 4. Drop the context table
        Schema::dropIfExists('document_vehicle_contexts');
    }
};
```

### B4: Create DocumentVehicleContext Model

```php
<?php
// app/Modules/Document/Domain/DocumentVehicleContext.php

namespace App\Modules\Document\Domain;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentVehicleContext extends Model
{
    use HasUuids;
    
    protected $fillable = [
        'document_id',
        'vehicle_id',
        'mileage_at_service',
        'vehicle_snapshot',
    ];
    
    protected $casts = [
        'vehicle_snapshot' => 'array',
    ];
    
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }
    
    /**
     * Get a snapshot value with fallback
     */
    public function getSnapshotValue(string $key, mixed $default = null): mixed
    {
        return $this->vehicle_snapshot[$key] ?? $default;
    }
    
    /**
     * Get display name from snapshot
     */
    public function getDisplayName(): string
    {
        $snapshot = $this->vehicle_snapshot ?? [];
        
        $parts = array_filter([
            $snapshot['make'] ?? null,
            $snapshot['model'] ?? null,
            $snapshot['year'] ?? null,
        ]);
        
        if (empty($parts)) {
            return $snapshot['registration'] ?? 'Unknown Vehicle';
        }
        
        return implode(' ', $parts);
    }
}
```

### B5: Update Document Model

```php
<?php
// app/Modules/Document/Domain/Document.php

// REMOVE this import:
// use App\Modules\Vehicle\Domain\Vehicle;

// REMOVE from $fillable:
// 'vehicle_id',

// REMOVE this method:
// public function vehicle(): BelongsTo
// {
//     return $this->belongsTo(Vehicle::class);
// }

// ADD this method:
public function vehicleContext(): HasOne
{
    return $this->hasOne(DocumentVehicleContext::class);
}

// ADD helper method for backward compatibility during transition
public function hasVehicle(): bool
{
    return $this->vehicleContext !== null;
}
```

### B6: Update DocumentResource (API Response)

```php
<?php
// Find and update the Document API resource

// BEFORE:
'vehicle_id' => $this->vehicle_id,
'vehicle' => $this->vehicle ? new VehicleResource($this->vehicle) : null,

// AFTER:
'vehicle_context' => $this->vehicleContext ? [
    'vehicle_id' => $this->vehicleContext->vehicle_id,
    'mileage_at_service' => $this->vehicleContext->mileage_at_service,
    'vehicle_snapshot' => $this->vehicleContext->vehicle_snapshot,
    'display_name' => $this->vehicleContext->getDisplayName(),
] : null,
```

### B7: Update Services That Use Vehicle

Find and update any service that accesses `$document->vehicle`:

```bash
# Find usages
grep -rn "\->vehicle[^_]" app/Modules/Document --include="*.php"
```

For each usage, update:

```php
// BEFORE
$vehicleName = $document->vehicle->display_name;
$vehicleId = $document->vehicle_id;

// AFTER
$vehicleName = $document->vehicleContext?->getDisplayName();
$vehicleId = $document->vehicleContext?->vehicle_id;
```

### B8: Update Document Creation/Update Logic

If there's a service that creates documents with vehicle:

```php
// BEFORE
$document = Document::create([
    // ...
    'vehicle_id' => $dto->vehicleId,
]);

// AFTER
$document = Document::create([
    // ... (no vehicle_id)
]);

if ($dto->vehicleId) {
    // Fetch vehicle snapshot (this CAN import Vehicle since it's in a service that knows about vehicles)
    $vehicle = Vehicle::find($dto->vehicleId);
    
    DocumentVehicleContext::create([
        'document_id' => $document->id,
        'vehicle_id' => $dto->vehicleId,
        'mileage_at_service' => $dto->mileage ?? null,
        'vehicle_snapshot' => $vehicle ? [
            'registration' => $vehicle->registration,
            'vin' => $vehicle->vin,
            'make' => $vehicle->make,
            'model' => $vehicle->model,
            'year' => $vehicle->year,
        ] : null,
    ]);
}
```

**IMPORTANT:** The service that creates the vehicle context CAN import Vehicle - it's just the Document MODEL that must not import Vehicle. This keeps the module boundary clean while allowing practical usage.

### B9: Update Tests

```bash
# Find tests that use vehicle with documents
grep -rn "vehicle" tests --include="*Document*"
```

Update test factories and assertions:

```php
// BEFORE
$document = Document::factory()->create([
    'vehicle_id' => $vehicle->id,
]);
$this->assertEquals($vehicle->id, $document->vehicle->id);

// AFTER
$document = Document::factory()->create();
DocumentVehicleContext::factory()->create([
    'document_id' => $document->id,
    'vehicle_id' => $vehicle->id,
    'vehicle_snapshot' => [
        'registration' => $vehicle->registration,
        'make' => $vehicle->make,
        'model' => $vehicle->model,
    ],
]);
$this->assertEquals($vehicle->id, $document->vehicleContext->vehicle_id);
```

### B10: Create Factory for DocumentVehicleContext

```php
<?php
// database/factories/DocumentVehicleContextFactory.php

namespace Database\Factories;

use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\DocumentVehicleContext;
use Illuminate\Database\Eloquent\Factories\Factory;

class DocumentVehicleContextFactory extends Factory
{
    protected $model = DocumentVehicleContext::class;

    public function definition(): array
    {
        return [
            'document_id' => Document::factory(),
            'vehicle_id' => $this->faker->uuid(),
            'mileage_at_service' => $this->faker->optional()->numberBetween(0, 300000),
            'vehicle_snapshot' => [
                'registration' => strtoupper($this->faker->bothify('??-###-??')),
                'vin' => strtoupper($this->faker->bothify('?????????????????')),
                'make' => $this->faker->randomElement(['Toyota', 'Honda', 'Ford', 'BMW', 'Mercedes']),
                'model' => $this->faker->word(),
                'year' => $this->faker->numberBetween(2000, 2024),
            ],
        ];
    }
}
```

### B11: Final Verification

```bash
# 1. No Vehicle imports in Document module
grep -r "use App\\\\Modules\\\\Vehicle" app/Modules/Document
# Expected: 0 results

# 2. No vehicle_id in Document model fillable
grep -n "vehicle_id" app/Modules/Document/Domain/Document.php
# Expected: 0 results (except maybe in comments)

# 3. Check the new relationship exists
grep -n "vehicleContext" app/Modules/Document/Domain/Document.php
# Expected: HasOne relationship method

# 4. Run all Document tests
php artisan test --filter=Document

# 5. Run migration test
php artisan migrate:fresh --seed
php artisan migrate:rollback --step=1
php artisan migrate
```

---

## Deliverables

### From Part A (Verification):
1. **Feature verification report** documenting which features work as expected
2. **Gap list** if any features are missing or incomplete

### From Part B (Decoupling):
1. **Migration file**: Moves vehicle_id to context table
2. **New model**: `DocumentVehicleContext`
3. **Updated Document model**: No vehicle imports, new `vehicleContext()` relationship
4. **Updated factory**: For testing
5. **Updated services**: Any that accessed `$document->vehicle`
6. **Updated tests**: Use new relationship
7. **Verification output**: Proof of clean separation

---

## Success Criteria

### Part A:
- [ ] All 8 features verified with evidence
- [ ] Any gaps documented with recommended fixes

### Part B:
- [ ] `grep -r "use App\\Modules\\Vehicle" app/Modules/Document` returns 0 results
- [ ] Document model has no `vehicle_id` in `$fillable`
- [ ] Existing vehicle relationships preserved in new structure
- [ ] All Document-related tests pass
- [ ] Migration is reversible
