# P0-B Vehicle Decoupling Remediation

**Context:** Initial vehicle decoupling was implemented but Codex verification found several gaps. The Document module still has coupling to Vehicle module. This remediation completes the decoupling properly.

---

## Problem Summary

The linking table `document_vehicle_contexts` was created, but:

1. ❌ Foreign key to `vehicles` table still exists (should be soft reference)
2. ❌ Document module still imports `App\Modules\Vehicle`
3. ❌ Missing columns: `vehicle_snapshot`, `mileage_at_service`
4. ❌ CreditNoteService still tries to persist `vehicle_id` column
5. ❌ Tests mass-assign `vehicle_id` on Document model
6. ❌ API still returns flat `vehicle_id` instead of `vehicle_context` object
7. ❌ Frontend not updated for new structure
8. ❌ No `DocumentVehicleContextFactory`
9. ❌ No display helper for vehicle info

---

## Success Criteria

After remediation:

```bash
# This command must return ZERO results
rg -n "use App\\\\Modules\\\\Vehicle" app/Modules/Document
```

- [ ] No foreign key constraint from `document_vehicle_contexts` to `vehicles`
- [ ] No `use App\Modules\Vehicle` imports anywhere in Document module
- [ ] `vehicle_snapshot` JSONB column stores vehicle data at time of document creation
- [ ] `mileage_at_service` integer column for odometer reading
- [ ] Display helper method returns formatted vehicle string
- [ ] API returns `vehicle_context` object, not flat `vehicle_id`
- [ ] Frontend uses new `vehicle_context` structure
- [ ] All tests pass without mass-assigning `vehicle_id`
- [ ] CreditNoteService uses new context pattern
- [ ] Factory exists for DocumentVehicleContext

---

## Task 1: Fix Migration

**File:** `database/migrations/2025_12_26_172358_create_document_vehicle_contexts_table.php`

### Current (Wrong):
```php
$table->foreignId('vehicle_id')->constrained('vehicles')->cascadeOnDelete();
```

### Target:
```php
// Soft reference - NO foreign key constraint
// This allows Document module to work without Vehicle module installed
$table->unsignedBigInteger('vehicle_id')->index();

// Snapshot of vehicle data at document creation time
// Stored so we don't need to query Vehicle module
$table->jsonb('vehicle_snapshot')->nullable();

// Odometer reading at time of service/sale
$table->unsignedInteger('mileage_at_service')->nullable();

// Existing context_data for other metadata
$table->jsonb('context_data')->nullable();
```

### Migration Must:
1. Remove the `->constrained('vehicles')` foreign key
2. Add `vehicle_snapshot` JSONB column
3. Add `mileage_at_service` integer column
4. If this is a new migration (table doesn't exist yet), just create it correctly
5. If table already exists, create an ALTER migration to add missing columns and drop FK

---

## Task 2: Remove Vehicle Imports from Document Module

**Files to check and fix:**

```bash
rg -n "use App\\Modules\\Vehicle" app/Modules/Document
```

### DocumentVehicleContext.php

**Current (Wrong):**
```php
use App\Modules\Vehicle\Domain\Vehicle;

public function vehicle(): BelongsTo
{
    return $this->belongsTo(Vehicle::class);
}
```

**Target:**
```php
// NO Vehicle import!

// Instead of a relationship, provide the snapshot data
public function getVehicleSnapshot(): ?array
{
    return $this->vehicle_snapshot;
}

// Display helper - uses snapshot, not live relation
public function getVehicleDisplayString(): string
{
    $snapshot = $this->vehicle_snapshot;
    if (!$snapshot) {
        return '';
    }
    
    $parts = array_filter([
        $snapshot['registration_number'] ?? null,
        $snapshot['make'] ?? null,
        $snapshot['model'] ?? null,
        $snapshot['year'] ?? null,
    ]);
    
    return implode(' - ', $parts);
}

// For cases where live vehicle data is needed (rare)
// Returns just the ID - caller must resolve if needed
public function getVehicleId(): ?int
{
    return $this->vehicle_id;
}

public function getMileageAtService(): ?int
{
    return $this->mileage_at_service;
}
```

### Document.php

**Current (Wrong):**
```php
use App\Modules\Vehicle\Domain\Vehicle;
```

**Target:**
Remove this import entirely. The Document model should only know about DocumentVehicleContext, not Vehicle.

```php
// Accessor for backward compatibility
public function getVehicleIdAttribute(): ?int
{
    return $this->vehicleContext?->vehicle_id;
}

// Display helper delegates to context
public function getVehicleDisplayAttribute(): string
{
    return $this->vehicleContext?->getVehicleDisplayString() ?? '';
}
```

---

## Task 3: Fix DocumentController

**File:** `app/Modules/Document/Presentation/Controllers/DocumentController.php`

### Current (Wrong):
```php
// Validates vehicle_id against Vehicle module
// Stores empty context_data
```

### Target:

The controller should:
1. Accept `vehicle_context` object from request (not flat `vehicle_id`)
2. NOT validate against Vehicle module (that's the caller's responsibility)
3. Store the snapshot data provided by the caller

```php
// In store/update methods:

if ($request->has('vehicle_context')) {
    $vehicleContext = $request->input('vehicle_context');
    
    DocumentVehicleContext::updateOrCreate(
        ['document_id' => $document->id],
        [
            'vehicle_id' => $vehicleContext['vehicle_id'] ?? null,
            'vehicle_snapshot' => $vehicleContext['snapshot'] ?? null,
            'mileage_at_service' => $vehicleContext['mileage'] ?? null,
            'context_data' => $vehicleContext['additional_data'] ?? null,
        ]
    );
}
```

### Request Validation:

```php
// In CreateDocumentRequest / UpdateDocumentRequest

'vehicle_context' => 'nullable|array',
'vehicle_context.vehicle_id' => 'nullable|integer',
'vehicle_context.snapshot' => 'nullable|array',
'vehicle_context.mileage' => 'nullable|integer|min:0',
'vehicle_context.additional_data' => 'nullable|array',
```

**Important:** Do NOT validate that `vehicle_id` exists in vehicles table. The Document module doesn't know about vehicles. The frontend/caller is responsible for providing valid data.

---

## Task 4: Fix CreditNoteService

**File:** `app/Modules/Document/Application/Services/CreditNoteService.php`

### Current (Wrong - Line 189):
```php
// Tries to persist vehicle_id column that doesn't exist
$creditNote->vehicle_id = $originalInvoice->vehicle_id;
```

### Target:
```php
// Copy vehicle context from original invoice
if ($originalInvoice->vehicleContext) {
    DocumentVehicleContext::create([
        'document_id' => $creditNote->id,
        'vehicle_id' => $originalInvoice->vehicleContext->vehicle_id,
        'vehicle_snapshot' => $originalInvoice->vehicleContext->vehicle_snapshot,
        'mileage_at_service' => $originalInvoice->vehicleContext->mileage_at_service,
        'context_data' => $originalInvoice->vehicleContext->context_data,
    ]);
}
```

---

## Task 5: Fix API Response (DocumentData DTO)

**File:** `app/Modules/Document/Application/DTOs/DocumentData.php`

### Current (Wrong):
```php
public readonly ?int $vehicle_id,
```

### Target:
```php
public readonly ?array $vehicle_context,

// In fromModel():
vehicle_context: $document->vehicleContext ? [
    'vehicle_id' => $document->vehicleContext->vehicle_id,
    'snapshot' => $document->vehicleContext->vehicle_snapshot,
    'mileage' => $document->vehicleContext->mileage_at_service,
    'display' => $document->vehicleContext->getVehicleDisplayString(),
    'additional_data' => $document->vehicleContext->context_data,
] : null,
```

---

## Task 6: Create Factory

**File:** `database/factories/DocumentVehicleContextFactory.php`

```php
<?php

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
            'vehicle_id' => $this->faker->randomNumber(5),
            'vehicle_snapshot' => [
                'registration_number' => strtoupper($this->faker->bothify('???-###')),
                'make' => $this->faker->randomElement(['Toyota', 'Honda', 'Ford', 'BMW']),
                'model' => $this->faker->word(),
                'year' => $this->faker->year(),
                'vin' => strtoupper($this->faker->bothify('?????????????????')),
            ],
            'mileage_at_service' => $this->faker->numberBetween(10000, 200000),
            'context_data' => null,
        ];
    }

    public function withoutVehicle(): static
    {
        return $this->state([
            'vehicle_id' => null,
            'vehicle_snapshot' => null,
            'mileage_at_service' => null,
        ]);
    }
}
```

---

## Task 7: Fix Tests

**File:** `tests/Unit/Document/DocumentVehicleRelationshipTest.php`

### Current (Wrong):
```php
$document = Document::factory()->create(['vehicle_id' => 123]);
```

### Target:
```php
$document = Document::factory()->create();
DocumentVehicleContext::factory()->create([
    'document_id' => $document->id,
    'vehicle_id' => 123,
    'vehicle_snapshot' => [
        'registration_number' => 'ABC-123',
        'make' => 'Toyota',
        'model' => 'Camry',
        'year' => 2022,
    ],
]);

// Then test:
$this->assertEquals(123, $document->vehicle_id); // Uses accessor
$this->assertEquals('ABC-123 - Toyota - Camry - 2022', $document->vehicle_display);
```

---

## Task 8: Update Frontend

**Location:** `apps/web/src/` (wherever documents are handled)

### Changes needed:

1. Update TypeScript types (regenerate from API)
2. Change document forms to send `vehicle_context` object instead of `vehicle_id`
3. Update document display to use `vehicle_context.display`

### Example form change:

```typescript
// Before
const payload = {
    ...formData,
    vehicle_id: selectedVehicle?.id,
};

// After
const payload = {
    ...formData,
    vehicle_context: selectedVehicle ? {
        vehicle_id: selectedVehicle.id,
        snapshot: {
            registration_number: selectedVehicle.registration_number,
            make: selectedVehicle.make,
            model: selectedVehicle.model,
            year: selectedVehicle.year,
            vin: selectedVehicle.vin,
        },
        mileage: mileageInput,
    } : null,
};
```

---

## Task 9: Verification

After all changes:

```bash
# 1. Must return ZERO results
rg -n "use App\\\\Modules\\\\Vehicle" app/Modules/Document

# 2. Must pass
php artisan test --filter=Document

# 3. Check migration works
php artisan migrate:fresh --seed

# 4. Regenerate types
php artisan typescript:generate

# 5. PHPStan clean
./vendor/bin/phpstan analyse --level=8
```

---

## Summary

The goal is **complete independence** of the Document module from the Vehicle module:

| Aspect | Before | After |
|--------|--------|-------|
| DB | FK constraint to vehicles | Soft reference (just an integer) |
| PHP | `use App\Modules\Vehicle` | No Vehicle imports |
| Data | Live relation lookup | Snapshot stored at creation |
| API | Flat `vehicle_id` | Rich `vehicle_context` object |
| Display | Needs Vehicle model | Uses stored snapshot |

This allows:
- Document module to work without Vehicle module installed
- Historical accuracy (vehicle data as it was when document created)
- Different applications (IziPOS) to ignore vehicles entirely
- Automotive applications (Otospex) to add vehicle context when needed
