# Claude Code Implementation Prompt: Inventory Counting Module

## Project Context

You are implementing the Inventory Counting module for AutoERP, an ERP system for automotive service businesses. This module enables physical inventory counts with blind counting methodology, multi-counter validation, and fraud detection.

**Monorepo Structure:**
```
autoerp/
├── apps/
│   ├── api/                    # Laravel 12 backend
│   ├── web/                    # React frontend (Vite)
│   └── mobile/                 # Expo React Native app
├── packages/
│   └── shared/                 # Shared TypeScript types
└── docs/
```

**Reference Documents:**
- Feature Spec: `/docs/features/inventory-counting-feature.md`
- Web Frontend Spec: `/docs/features/inventory-counting-web-frontend.md`
- Mobile App Spec: `/docs/features/inventory-counting-mobile.md`

---

## PHASE 1: Database Migrations

### Task 1.1: Create Migration Files

Create migrations in `apps/api/database/migrations/`:

```bash
cd apps/api
php artisan make:migration create_inventory_countings_table
php artisan make:migration create_inventory_counting_assignments_table
php artisan make:migration create_inventory_counting_items_table
php artisan make:migration create_inventory_counting_events_table
php artisan make:migration create_inventory_counter_metrics_table
```

### Task 1.2: Implement inventory_countings Table

```php
// YYYY_MM_DD_XXXXXX_create_inventory_countings_table.php

Schema::create('inventory_countings', function (Blueprint $table) {
    $table->id();
    $table->uuid('uuid')->unique();
    $table->foreignId('company_id')->constrained()->onDelete('cascade');
    $table->foreignId('created_by_user_id')->constrained('users');
    
    // Scope
    $table->enum('scope_type', [
        'product_location',
        'product',
        'location', 
        'category',
        'warehouse',
        'full_inventory'
    ]);
    $table->jsonb('scope_filters')->default('{}');
    
    // Configuration
    $table->enum('execution_mode', ['parallel', 'sequential'])->default('parallel');
    $table->enum('status', [
        'draft',
        'scheduled',
        'count_1_in_progress',
        'count_1_completed',
        'count_2_in_progress',
        'count_2_completed',
        'count_3_in_progress',
        'count_3_completed',
        'pending_review',
        'finalized',
        'cancelled'
    ])->default('draft');
    
    // Schedule
    $table->timestampTz('scheduled_start')->nullable();
    $table->timestampTz('scheduled_end')->nullable();
    
    // Counter assignments
    $table->foreignId('count_1_user_id')->nullable()->constrained('users');
    $table->foreignId('count_2_user_id')->nullable()->constrained('users');
    $table->foreignId('count_3_user_id')->nullable()->constrained('users');
    $table->boolean('requires_count_2')->default(true);
    $table->boolean('requires_count_3')->default(false);
    
    // Options
    $table->boolean('allow_unexpected_items')->default(false);
    $table->text('instructions')->nullable();
    
    // Timestamps
    $table->timestampsTz();
    $table->timestampTz('activated_at')->nullable();
    $table->timestampTz('finalized_at')->nullable();
    $table->timestampTz('cancelled_at')->nullable();
    $table->text('cancellation_reason')->nullable();
    
    // Indexes
    $table->index(['company_id', 'status']);
    $table->index(['company_id', 'scheduled_start']);
    $table->index('count_1_user_id');
    $table->index('count_2_user_id');
    $table->index('count_3_user_id');
});

// Add CHECK constraints
DB::statement("
    ALTER TABLE inventory_countings 
    ADD CONSTRAINT valid_schedule 
    CHECK (scheduled_end IS NULL OR scheduled_start IS NULL OR scheduled_end > scheduled_start)
");

DB::statement("
    ALTER TABLE inventory_countings 
    ADD CONSTRAINT valid_count_2_assignment 
    CHECK (NOT requires_count_2 OR count_2_user_id IS NOT NULL OR status = 'draft')
");
```

### Task 1.3: Implement inventory_counting_assignments Table

```php
Schema::create('inventory_counting_assignments', function (Blueprint $table) {
    $table->id();
    $table->foreignId('counting_id')->constrained('inventory_countings')->onDelete('cascade');
    $table->foreignId('user_id')->constrained();
    $table->unsignedTinyInteger('count_number'); // 1, 2, or 3
    
    $table->enum('status', [
        'pending',
        'in_progress',
        'completed',
        'overdue'
    ])->default('pending');
    
    $table->timestampTz('assigned_at');
    $table->timestampTz('started_at')->nullable();
    $table->timestampTz('completed_at')->nullable();
    $table->timestampTz('deadline')->nullable();
    
    // Progress tracking
    $table->unsignedInteger('total_items')->default(0);
    $table->unsignedInteger('counted_items')->default(0);
    
    $table->timestampsTz();
    
    // Unique constraint
    $table->unique(['counting_id', 'count_number']);
    $table->index(['user_id', 'status']);
});
```

### Task 1.4: Implement inventory_counting_items Table

```php
Schema::create('inventory_counting_items', function (Blueprint $table) {
    $table->id();
    $table->foreignId('counting_id')->constrained('inventory_countings')->onDelete('cascade');
    $table->foreignId('product_id')->constrained();
    $table->foreignId('variant_id')->nullable()->constrained('product_variants');
    $table->foreignId('location_id')->constrained('warehouse_locations');
    $table->foreignId('warehouse_id')->constrained();
    
    // Theoretical quantity (frozen at counting creation)
    $table->decimal('theoretical_qty', 15, 4);
    
    // Count results
    $table->decimal('count_1_qty', 15, 4)->nullable();
    $table->timestampTz('count_1_at')->nullable();
    $table->text('count_1_notes')->nullable();
    
    $table->decimal('count_2_qty', 15, 4)->nullable();
    $table->timestampTz('count_2_at')->nullable();
    $table->text('count_2_notes')->nullable();
    
    $table->decimal('count_3_qty', 15, 4)->nullable();
    $table->timestampTz('count_3_at')->nullable();
    $table->text('count_3_notes')->nullable();
    
    // Resolution
    $table->decimal('final_qty', 15, 4)->nullable();
    $table->enum('resolution_method', [
        'pending',
        'auto_all_match',
        'auto_counters_agree',
        'third_count_decisive',
        'manual_override'
    ])->default('pending');
    $table->text('resolution_notes')->nullable();
    $table->foreignId('resolved_by_user_id')->nullable()->constrained('users');
    $table->timestampTz('resolved_at')->nullable();
    
    // Flags
    $table->boolean('is_flagged')->default(false);
    $table->string('flag_reason')->nullable();
    $table->boolean('is_unexpected_item')->default(false);
    
    $table->timestampsTz();
    
    // Indexes
    $table->index(['counting_id', 'is_flagged']);
    $table->index(['counting_id', 'resolution_method']);
    $table->unique(['counting_id', 'product_id', 'variant_id', 'location_id'], 'counting_item_unique');
});
```

### Task 1.5: Implement inventory_counting_events Table

```php
Schema::create('inventory_counting_events', function (Blueprint $table) {
    $table->id();
    $table->foreignId('counting_id')->constrained('inventory_countings')->onDelete('cascade');
    $table->foreignId('item_id')->nullable()->constrained('inventory_counting_items')->onDelete('cascade');
    
    $table->string('event_type');
    $table->jsonb('event_data')->default('{}');
    $table->foreignId('user_id')->nullable()->constrained();
    
    // Hash chain for tamper-proofing
    $table->string('previous_hash', 64);
    $table->string('event_hash', 64);
    
    $table->timestampTz('created_at');
    
    // Indexes
    $table->index(['counting_id', 'created_at']);
    $table->index('event_type');
});
```

### Task 1.6: Implement inventory_counter_metrics Table

```php
Schema::create('inventory_counter_metrics', function (Blueprint $table) {
    $table->id();
    $table->foreignId('company_id')->constrained()->onDelete('cascade');
    $table->foreignId('user_id')->constrained()->onDelete('cascade');
    
    // Counts
    $table->unsignedInteger('total_counts')->default(0);
    $table->unsignedInteger('total_items_counted')->default(0);
    
    // Accuracy metrics
    $table->unsignedInteger('matches_with_theoretical')->default(0);
    $table->unsignedInteger('matches_with_other_counter')->default(0);
    $table->unsignedInteger('disagreements_proven_wrong')->default(0);
    $table->unsignedInteger('disagreements_proven_right')->default(0);
    
    // Speed
    $table->decimal('avg_seconds_per_item', 10, 2)->nullable();
    
    // Period
    $table->date('period_start');
    $table->date('period_end');
    
    $table->timestampsTz();
    
    // Unique per user per period
    $table->unique(['user_id', 'period_start', 'period_end']);
    $table->index(['company_id', 'period_start']);
});
```

### Verification Command:
```bash
cd apps/api
php artisan migrate
php artisan migrate:status | grep inventory
```

---

## PHASE 2: Enums and Value Objects

### Task 2.1: Create Enums

Create in `apps/api/app/Enums/Inventory/`:

```php
// CountingScopeType.php
namespace App\Enums\Inventory;

enum CountingScopeType: string
{
    case ProductLocation = 'product_location';
    case Product = 'product';
    case Location = 'location';
    case Category = 'category';
    case Warehouse = 'warehouse';
    case FullInventory = 'full_inventory';
    
    public function allowsUnexpectedItems(): bool
    {
        return $this === self::FullInventory;
    }
    
    public function label(): string
    {
        return match($this) {
            self::ProductLocation => 'Specific Product at Location',
            self::Product => 'Product (All Locations)',
            self::Location => 'Location',
            self::Category => 'Category',
            self::Warehouse => 'Warehouse',
            self::FullInventory => 'Full Inventory',
        };
    }
}
```

```php
// CountingStatus.php
namespace App\Enums\Inventory;

enum CountingStatus: string
{
    case Draft = 'draft';
    case Scheduled = 'scheduled';
    case Count1InProgress = 'count_1_in_progress';
    case Count1Completed = 'count_1_completed';
    case Count2InProgress = 'count_2_in_progress';
    case Count2Completed = 'count_2_completed';
    case Count3InProgress = 'count_3_in_progress';
    case Count3Completed = 'count_3_completed';
    case PendingReview = 'pending_review';
    case Finalized = 'finalized';
    case Cancelled = 'cancelled';
    
    public function isActive(): bool
    {
        return in_array($this, [
            self::Count1InProgress,
            self::Count2InProgress,
            self::Count3InProgress,
        ]);
    }
    
    public function isCompleted(): bool
    {
        return $this === self::Finalized;
    }
    
    public function canTransitionTo(self $target): bool
    {
        $allowed = match($this) {
            self::Draft => [self::Scheduled, self::Count1InProgress, self::Cancelled],
            self::Scheduled => [self::Count1InProgress, self::Cancelled],
            self::Count1InProgress => [self::Count1Completed, self::Cancelled],
            self::Count1Completed => [self::Count2InProgress, self::PendingReview],
            self::Count2InProgress => [self::Count2Completed, self::Cancelled],
            self::Count2Completed => [self::Count3InProgress, self::PendingReview],
            self::Count3InProgress => [self::Count3Completed, self::Cancelled],
            self::Count3Completed => [self::PendingReview],
            self::PendingReview => [self::Finalized, self::Count3InProgress],
            self::Finalized => [],
            self::Cancelled => [],
        };
        
        return in_array($target, $allowed);
    }
}
```

```php
// CountingExecutionMode.php
namespace App\Enums\Inventory;

enum CountingExecutionMode: string
{
    case Parallel = 'parallel';
    case Sequential = 'sequential';
}
```

```php
// ItemResolutionMethod.php
namespace App\Enums\Inventory;

enum ItemResolutionMethod: string
{
    case Pending = 'pending';
    case AutoAllMatch = 'auto_all_match';
    case AutoCountersAgree = 'auto_counters_agree';
    case ThirdCountDecisive = 'third_count_decisive';
    case ManualOverride = 'manual_override';
    
    public function isAutomatic(): bool
    {
        return in_array($this, [
            self::AutoAllMatch,
            self::AutoCountersAgree,
        ]);
    }
    
    public function label(): string
    {
        return match($this) {
            self::Pending => 'Pending',
            self::AutoAllMatch => 'All Counts Match',
            self::AutoCountersAgree => 'Counters Agree',
            self::ThirdCountDecisive => 'Third Count Decisive',
            self::ManualOverride => 'Manual Override',
        };
    }
}
```

```php
// AssignmentStatus.php
namespace App\Enums\Inventory;

enum AssignmentStatus: string
{
    case Pending = 'pending';
    case InProgress = 'in_progress';
    case Completed = 'completed';
    case Overdue = 'overdue';
}
```

---

## PHASE 3: Eloquent Models

### Task 3.1: Create InventoryCounting Model

```php
// app/Models/Inventory/InventoryCounting.php

namespace App\Models\Inventory;

use App\Enums\Inventory\{CountingScopeType, CountingStatus, CountingExecutionMode};
use App\Models\{Company, User};
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\{BelongsTo, HasMany};

class InventoryCounting extends Model
{
    protected $fillable = [
        'company_id',
        'created_by_user_id',
        'scope_type',
        'scope_filters',
        'execution_mode',
        'status',
        'scheduled_start',
        'scheduled_end',
        'count_1_user_id',
        'count_2_user_id',
        'count_3_user_id',
        'requires_count_2',
        'requires_count_3',
        'allow_unexpected_items',
        'instructions',
    ];
    
    protected $casts = [
        'scope_type' => CountingScopeType::class,
        'status' => CountingStatus::class,
        'execution_mode' => CountingExecutionMode::class,
        'scope_filters' => 'array',
        'scheduled_start' => 'datetime',
        'scheduled_end' => 'datetime',
        'activated_at' => 'datetime',
        'finalized_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'requires_count_2' => 'boolean',
        'requires_count_3' => 'boolean',
        'allow_unexpected_items' => 'boolean',
    ];
    
    protected static function booted(): void
    {
        static::creating(function (self $counting) {
            $counting->uuid = (string) \Illuminate\Support\Str::uuid();
        });
    }
    
    // Relationships
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
    
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
    
    public function count1User(): BelongsTo
    {
        return $this->belongsTo(User::class, 'count_1_user_id');
    }
    
    public function count2User(): BelongsTo
    {
        return $this->belongsTo(User::class, 'count_2_user_id');
    }
    
    public function count3User(): BelongsTo
    {
        return $this->belongsTo(User::class, 'count_3_user_id');
    }
    
    public function items(): HasMany
    {
        return $this->hasMany(InventoryCountingItem::class, 'counting_id');
    }
    
    public function assignments(): HasMany
    {
        return $this->hasMany(InventoryCountingAssignment::class, 'counting_id');
    }
    
    public function events(): HasMany
    {
        return $this->hasMany(InventoryCountingEvent::class, 'counting_id');
    }
    
    // Scopes
    public function scopeActive($query)
    {
        return $query->whereIn('status', [
            CountingStatus::Count1InProgress,
            CountingStatus::Count2InProgress,
            CountingStatus::Count3InProgress,
        ]);
    }
    
    public function scopePendingReview($query)
    {
        return $query->where('status', CountingStatus::PendingReview);
    }
    
    public function scopeForCompany($query, int $companyId)
    {
        return $query->where('company_id', $companyId);
    }
    
    // Accessors
    public function getProgressAttribute(): array
    {
        $totalItems = $this->items()->count();
        
        return [
            'count_1' => $this->calculateCountProgress(1, $totalItems),
            'count_2' => $this->requires_count_2 ? $this->calculateCountProgress(2, $totalItems) : null,
            'count_3' => $this->requires_count_3 ? $this->calculateCountProgress(3, $totalItems) : null,
            'overall' => $this->calculateOverallProgress($totalItems),
        ];
    }
    
    private function calculateCountProgress(int $countNumber, int $total): array
    {
        if ($total === 0) {
            return ['counted' => 0, 'total' => 0, 'percentage' => 0];
        }
        
        $column = "count_{$countNumber}_qty";
        $counted = $this->items()->whereNotNull($column)->count();
        
        return [
            'counted' => $counted,
            'total' => $total,
            'percentage' => round(($counted / $total) * 100, 1),
        ];
    }
    
    private function calculateOverallProgress(int $total): float
    {
        if ($total === 0) return 0;
        
        $phases = $this->requires_count_2 ? ($this->requires_count_3 ? 3 : 2) : 1;
        $totalSteps = $total * $phases;
        
        $completed = $this->items()->whereNotNull('count_1_qty')->count();
        if ($this->requires_count_2) {
            $completed += $this->items()->whereNotNull('count_2_qty')->count();
        }
        if ($this->requires_count_3) {
            $completed += $this->items()->whereNotNull('count_3_qty')->count();
        }
        
        return round(($completed / $totalSteps) * 100, 1);
    }
    
    // Status transitions
    public function canTransitionTo(CountingStatus $status): bool
    {
        return $this->status->canTransitionTo($status);
    }
    
    public function transitionTo(CountingStatus $status): void
    {
        if (!$this->canTransitionTo($status)) {
            throw new \InvalidArgumentException(
                "Cannot transition from {$this->status->value} to {$status->value}"
            );
        }
        
        $this->status = $status;
        
        match($status) {
            CountingStatus::Count1InProgress => $this->activated_at = now(),
            CountingStatus::Finalized => $this->finalized_at = now(),
            CountingStatus::Cancelled => $this->cancelled_at = now(),
            default => null,
        };
        
        $this->save();
    }
    
    // Helper methods
    public function getCurrentCountNumber(): ?int
    {
        return match($this->status) {
            CountingStatus::Count1InProgress, CountingStatus::Count1Completed => 1,
            CountingStatus::Count2InProgress, CountingStatus::Count2Completed => 2,
            CountingStatus::Count3InProgress, CountingStatus::Count3Completed => 3,
            default => null,
        };
    }
    
    public function isUserAssigned(int $userId): bool
    {
        return in_array($userId, array_filter([
            $this->count_1_user_id,
            $this->count_2_user_id,
            $this->count_3_user_id,
        ]));
    }
    
    public function getUserCountNumber(int $userId): ?int
    {
        if ($this->count_1_user_id === $userId) return 1;
        if ($this->count_2_user_id === $userId) return 2;
        if ($this->count_3_user_id === $userId) return 3;
        return null;
    }
}
```

### Task 3.2: Create InventoryCountingItem Model

```php
// app/Models/Inventory/InventoryCountingItem.php

namespace App\Models\Inventory;

use App\Enums\Inventory\ItemResolutionMethod;
use App\Models\{Product, ProductVariant, User, Warehouse, WarehouseLocation};
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryCountingItem extends Model
{
    protected $fillable = [
        'counting_id',
        'product_id',
        'variant_id',
        'location_id',
        'warehouse_id',
        'theoretical_qty',
        'count_1_qty',
        'count_1_at',
        'count_1_notes',
        'count_2_qty',
        'count_2_at',
        'count_2_notes',
        'count_3_qty',
        'count_3_at',
        'count_3_notes',
        'final_qty',
        'resolution_method',
        'resolution_notes',
        'resolved_by_user_id',
        'resolved_at',
        'is_flagged',
        'flag_reason',
        'is_unexpected_item',
    ];
    
    protected $casts = [
        'resolution_method' => ItemResolutionMethod::class,
        'theoretical_qty' => 'decimal:4',
        'count_1_qty' => 'decimal:4',
        'count_2_qty' => 'decimal:4',
        'count_3_qty' => 'decimal:4',
        'final_qty' => 'decimal:4',
        'count_1_at' => 'datetime',
        'count_2_at' => 'datetime',
        'count_3_at' => 'datetime',
        'resolved_at' => 'datetime',
        'is_flagged' => 'boolean',
        'is_unexpected_item' => 'boolean',
    ];
    
    // Relationships
    public function counting(): BelongsTo
    {
        return $this->belongsTo(InventoryCounting::class, 'counting_id');
    }
    
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
    
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class);
    }
    
    public function location(): BelongsTo
    {
        return $this->belongsTo(WarehouseLocation::class);
    }
    
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }
    
    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by_user_id');
    }
    
    // Scopes
    public function scopeFlagged($query)
    {
        return $query->where('is_flagged', true);
    }
    
    public function scopeNeedsResolution($query)
    {
        return $query->where('resolution_method', ItemResolutionMethod::Pending);
    }
    
    public function scopeNeedsThirdCount($query)
    {
        return $query->whereNotNull('count_1_qty')
                     ->whereNotNull('count_2_qty')
                     ->whereNull('count_3_qty')
                     ->whereRaw('count_1_qty != count_2_qty');
    }
    
    // Accessors
    public function getVarianceAttribute(): ?float
    {
        if ($this->final_qty === null) {
            return null;
        }
        
        return (float) $this->final_qty - (float) $this->theoretical_qty;
    }
    
    public function getVariancePercentageAttribute(): ?float
    {
        if ($this->final_qty === null || (float) $this->theoretical_qty === 0.0) {
            return null;
        }
        
        return round((($this->variance / (float) $this->theoretical_qty) * 100), 2);
    }
    
    // Helper methods
    public function hasCountForPhase(int $phase): bool
    {
        $column = "count_{$phase}_qty";
        return $this->$column !== null;
    }
    
    public function submitCount(int $phase, float $quantity, ?string $notes = null): void
    {
        $qtyColumn = "count_{$phase}_qty";
        $atColumn = "count_{$phase}_at";
        $notesColumn = "count_{$phase}_notes";
        
        $this->$qtyColumn = $quantity;
        $this->$atColumn = now();
        $this->$notesColumn = $notes;
        $this->save();
    }
    
    public function countersAgree(): bool
    {
        if ($this->count_1_qty === null || $this->count_2_qty === null) {
            return false;
        }
        
        return $this->floatsEqual((float) $this->count_1_qty, (float) $this->count_2_qty);
    }
    
    public function allCountsMatchTheoretical(): bool
    {
        if ($this->count_1_qty === null) {
            return false;
        }
        
        $theoretical = (float) $this->theoretical_qty;
        
        if (!$this->floatsEqual((float) $this->count_1_qty, $theoretical)) {
            return false;
        }
        
        if ($this->count_2_qty !== null && !$this->floatsEqual((float) $this->count_2_qty, $theoretical)) {
            return false;
        }
        
        if ($this->count_3_qty !== null && !$this->floatsEqual((float) $this->count_3_qty, $theoretical)) {
            return false;
        }
        
        return true;
    }
    
    private function floatsEqual(float $a, float $b, float $epsilon = 0.0001): bool
    {
        return abs($a - $b) < $epsilon;
    }
}
```

### Task 3.3: Create InventoryCountingEvent Model

```php
// app/Models/Inventory/InventoryCountingEvent.php

namespace App\Models\Inventory;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryCountingEvent extends Model
{
    public $timestamps = false;
    
    protected $fillable = [
        'counting_id',
        'item_id',
        'event_type',
        'event_data',
        'user_id',
        'previous_hash',
        'event_hash',
        'created_at',
    ];
    
    protected $casts = [
        'event_data' => 'array',
        'created_at' => 'datetime',
    ];
    
    protected static function booted(): void
    {
        static::creating(function (self $event) {
            $event->created_at = now();
            
            // Get previous hash
            $lastEvent = static::where('counting_id', $event->counting_id)
                ->orderByDesc('id')
                ->first();
            
            $event->previous_hash = $lastEvent?->event_hash ?? 'GENESIS';
            
            // Calculate event hash
            $event->event_hash = hash('sha256', json_encode([
                'previous_hash' => $event->previous_hash,
                'counting_id' => $event->counting_id,
                'item_id' => $event->item_id,
                'event_type' => $event->event_type,
                'event_data' => $event->event_data,
                'user_id' => $event->user_id,
                'created_at' => $event->created_at->toIso8601String(),
            ]));
        });
    }
    
    // Relationships
    public function counting(): BelongsTo
    {
        return $this->belongsTo(InventoryCounting::class, 'counting_id');
    }
    
    public function item(): BelongsTo
    {
        return $this->belongsTo(InventoryCountingItem::class, 'item_id');
    }
    
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
    
    // Event type constants
    public const COUNTING_CREATED = 'counting.created';
    public const COUNTING_ACTIVATED = 'counting.activated';
    public const COUNTING_CANCELLED = 'counting.cancelled';
    public const COUNT_SUBMITTED = 'count.submitted';
    public const ITEM_AUTO_RESOLVED = 'item.auto_resolved';
    public const ITEM_MANUALLY_OVERRIDDEN = 'item.manually_overridden';
    public const THIRD_COUNT_TRIGGERED = 'third_count.triggered';
    public const COUNTING_FINALIZED = 'counting.finalized';
    
    // Factory methods
    public static function recordCountSubmitted(
        InventoryCountingItem $item,
        int $countNumber,
        float $quantity,
        ?string $notes,
        int $userId
    ): self {
        return self::create([
            'counting_id' => $item->counting_id,
            'item_id' => $item->id,
            'event_type' => self::COUNT_SUBMITTED,
            'event_data' => [
                'count_number' => $countNumber,
                'quantity' => $quantity,
                'notes' => $notes,
            ],
            'user_id' => $userId,
        ]);
    }
    
    public static function recordAutoResolution(
        InventoryCountingItem $item,
        string $method,
        float $finalQty
    ): self {
        return self::create([
            'counting_id' => $item->counting_id,
            'item_id' => $item->id,
            'event_type' => self::ITEM_AUTO_RESOLVED,
            'event_data' => [
                'method' => $method,
                'final_qty' => $finalQty,
                'theoretical_qty' => (float) $item->theoretical_qty,
                'variance' => $finalQty - (float) $item->theoretical_qty,
            ],
            'user_id' => null,
        ]);
    }
    
    public static function recordManualOverride(
        InventoryCountingItem $item,
        float $finalQty,
        string $notes,
        int $userId
    ): self {
        return self::create([
            'counting_id' => $item->counting_id,
            'item_id' => $item->id,
            'event_type' => self::ITEM_MANUALLY_OVERRIDDEN,
            'event_data' => [
                'final_qty' => $finalQty,
                'notes' => $notes,
                'count_1_qty' => (float) $item->count_1_qty,
                'count_2_qty' => $item->count_2_qty ? (float) $item->count_2_qty : null,
                'count_3_qty' => $item->count_3_qty ? (float) $item->count_3_qty : null,
                'theoretical_qty' => (float) $item->theoretical_qty,
            ],
            'user_id' => $userId,
        ]);
    }
}
```

### Task 3.4: Create InventoryCountingAssignment Model

```php
// app/Models/Inventory/InventoryCountingAssignment.php

namespace App\Models\Inventory;

use App\Enums\Inventory\AssignmentStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryCountingAssignment extends Model
{
    protected $fillable = [
        'counting_id',
        'user_id',
        'count_number',
        'status',
        'assigned_at',
        'started_at',
        'completed_at',
        'deadline',
        'total_items',
        'counted_items',
    ];
    
    protected $casts = [
        'status' => AssignmentStatus::class,
        'count_number' => 'integer',
        'assigned_at' => 'datetime',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'deadline' => 'datetime',
        'total_items' => 'integer',
        'counted_items' => 'integer',
    ];
    
    // Relationships
    public function counting(): BelongsTo
    {
        return $this->belongsTo(InventoryCounting::class, 'counting_id');
    }
    
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
    
    // Scopes
    public function scopeForUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }
    
    public function scopePending($query)
    {
        return $query->where('status', AssignmentStatus::Pending);
    }
    
    public function scopeActive($query)
    {
        return $query->where('status', AssignmentStatus::InProgress);
    }
    
    // Accessors
    public function getProgressPercentageAttribute(): float
    {
        if ($this->total_items === 0) {
            return 0;
        }
        
        return round(($this->counted_items / $this->total_items) * 100, 1);
    }
    
    public function getIsOverdueAttribute(): bool
    {
        return $this->deadline !== null 
            && $this->deadline->isPast() 
            && $this->status !== AssignmentStatus::Completed;
    }
    
    // State transitions
    public function start(): void
    {
        $this->status = AssignmentStatus::InProgress;
        $this->started_at = now();
        $this->save();
    }
    
    public function complete(): void
    {
        $this->status = AssignmentStatus::Completed;
        $this->completed_at = now();
        $this->save();
    }
    
    public function incrementProgress(): void
    {
        $this->increment('counted_items');
    }
}
```

### Verification Command:
```bash
cd apps/api
./vendor/bin/phpstan analyse app/Models/Inventory app/Enums/Inventory --level=8
```

---

## PHASE 4: Service Layer

### Task 4.1: Create InventoryCountingService

```php
// app/Services/Inventory/InventoryCountingService.php

namespace App\Services\Inventory;

use App\Enums\Inventory\{CountingStatus, CountingScopeType, ItemResolutionMethod};
use App\Models\Inventory\{InventoryCounting, InventoryCountingItem, InventoryCountingAssignment, InventoryCountingEvent};
use App\Models\{User, StockLevel};
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Collection;

class InventoryCountingService
{
    public function __construct(
        private CountingReconciliationService $reconciliationService,
    ) {}
    
    /**
     * Create a new counting operation
     */
    public function create(array $data, User $createdBy): InventoryCounting
    {
        return DB::transaction(function () use ($data, $createdBy) {
            // Create the counting record
            $counting = InventoryCounting::create([
                'company_id' => $createdBy->current_company_id,
                'created_by_user_id' => $createdBy->id,
                'scope_type' => $data['scope_type'],
                'scope_filters' => $data['scope_filters'] ?? [],
                'execution_mode' => $data['execution_mode'] ?? 'parallel',
                'requires_count_2' => $data['requires_count_2'] ?? true,
                'requires_count_3' => $data['requires_count_3'] ?? false,
                'allow_unexpected_items' => $data['allow_unexpected_items'] ?? false,
                'count_1_user_id' => $data['count_1_user_id'],
                'count_2_user_id' => $data['count_2_user_id'] ?? null,
                'count_3_user_id' => $data['count_3_user_id'] ?? null,
                'scheduled_start' => $data['scheduled_start'] ?? null,
                'scheduled_end' => $data['scheduled_end'] ?? null,
                'instructions' => $data['instructions'] ?? null,
            ]);
            
            // Generate items based on scope
            $this->generateCountingItems($counting);
            
            // Create assignments
            $this->createAssignments($counting);
            
            // Record event
            InventoryCountingEvent::create([
                'counting_id' => $counting->id,
                'event_type' => InventoryCountingEvent::COUNTING_CREATED,
                'event_data' => [
                    'scope_type' => $counting->scope_type->value,
                    'scope_filters' => $counting->scope_filters,
                    'items_count' => $counting->items()->count(),
                ],
                'user_id' => $createdBy->id,
            ]);
            
            return $counting->fresh(['items', 'assignments']);
        });
    }
    
    /**
     * Generate counting items based on scope
     */
    private function generateCountingItems(InventoryCounting $counting): void
    {
        $stockLevels = $this->getStockLevelsForScope(
            $counting->company_id,
            $counting->scope_type,
            $counting->scope_filters
        );
        
        foreach ($stockLevels as $stock) {
            InventoryCountingItem::create([
                'counting_id' => $counting->id,
                'product_id' => $stock->product_id,
                'variant_id' => $stock->variant_id,
                'location_id' => $stock->location_id,
                'warehouse_id' => $stock->warehouse_id,
                'theoretical_qty' => $stock->quantity, // Frozen at this moment
            ]);
        }
    }
    
    /**
     * Get stock levels based on counting scope
     */
    private function getStockLevelsForScope(
        int $companyId,
        CountingScopeType $scopeType,
        array $filters
    ): Collection {
        $query = StockLevel::query()
            ->where('company_id', $companyId)
            ->with(['product', 'variant', 'location', 'warehouse']);
        
        switch ($scopeType) {
            case CountingScopeType::ProductLocation:
                $query->whereIn('product_id', $filters['product_ids'] ?? [])
                      ->where('location_id', $filters['location_id']);
                break;
                
            case CountingScopeType::Product:
                $query->whereIn('product_id', $filters['product_ids'] ?? []);
                break;
                
            case CountingScopeType::Location:
                $query->whereIn('location_id', $filters['location_ids'] ?? []);
                break;
                
            case CountingScopeType::Category:
                $query->whereHas('product', function ($q) use ($filters) {
                    $q->whereIn('category_id', $filters['category_ids'] ?? []);
                });
                break;
                
            case CountingScopeType::Warehouse:
                $query->whereIn('warehouse_id', $filters['warehouse_ids'] ?? []);
                break;
                
            case CountingScopeType::FullInventory:
                // No additional filters
                break;
        }
        
        return $query->where('quantity', '>', 0)->get();
    }
    
    /**
     * Create counter assignments
     */
    private function createAssignments(InventoryCounting $counting): void
    {
        $totalItems = $counting->items()->count();
        
        // Assignment for Count 1
        InventoryCountingAssignment::create([
            'counting_id' => $counting->id,
            'user_id' => $counting->count_1_user_id,
            'count_number' => 1,
            'assigned_at' => now(),
            'deadline' => $counting->scheduled_end,
            'total_items' => $totalItems,
        ]);
        
        // Assignment for Count 2 (if required)
        if ($counting->requires_count_2 && $counting->count_2_user_id) {
            InventoryCountingAssignment::create([
                'counting_id' => $counting->id,
                'user_id' => $counting->count_2_user_id,
                'count_number' => 2,
                'assigned_at' => now(),
                'deadline' => $counting->scheduled_end,
                'total_items' => $totalItems,
            ]);
        }
        
        // Assignment for Count 3 (if required)
        if ($counting->requires_count_3 && $counting->count_3_user_id) {
            InventoryCountingAssignment::create([
                'counting_id' => $counting->id,
                'user_id' => $counting->count_3_user_id,
                'count_number' => 3,
                'assigned_at' => now(),
                'deadline' => $counting->scheduled_end,
                'total_items' => 0, // Will be updated when 3rd count triggered
            ]);
        }
    }
    
    /**
     * Activate a counting operation
     */
    public function activate(InventoryCounting $counting, User $user): void
    {
        if ($counting->status !== CountingStatus::Draft && 
            $counting->status !== CountingStatus::Scheduled) {
            throw new \InvalidArgumentException('Counting is not in draft or scheduled status');
        }
        
        DB::transaction(function () use ($counting, $user) {
            $counting->transitionTo(CountingStatus::Count1InProgress);
            
            // Start assignment for count 1
            $assignment = $counting->assignments()
                ->where('count_number', 1)
                ->first();
            $assignment?->start();
            
            InventoryCountingEvent::create([
                'counting_id' => $counting->id,
                'event_type' => InventoryCountingEvent::COUNTING_ACTIVATED,
                'event_data' => [],
                'user_id' => $user->id,
            ]);
        });
    }
    
    /**
     * Submit a count for an item
     * 
     * CRITICAL: This is the only method that should modify count values
     */
    public function submitCount(
        InventoryCountingItem $item,
        int $countNumber,
        float $quantity,
        ?string $notes,
        User $user
    ): void {
        $counting = $item->counting;
        
        // Validate user is assigned to this count number
        $expectedUserId = match($countNumber) {
            1 => $counting->count_1_user_id,
            2 => $counting->count_2_user_id,
            3 => $counting->count_3_user_id,
            default => throw new \InvalidArgumentException('Invalid count number'),
        };
        
        if ($expectedUserId !== $user->id) {
            throw new \InvalidArgumentException('User is not assigned to this count phase');
        }
        
        // Validate counting is in correct status
        $expectedStatus = match($countNumber) {
            1 => CountingStatus::Count1InProgress,
            2 => CountingStatus::Count2InProgress,
            3 => CountingStatus::Count3InProgress,
        };
        
        if ($counting->status !== $expectedStatus) {
            throw new \InvalidArgumentException('Counting is not in correct phase');
        }
        
        DB::transaction(function () use ($item, $countNumber, $quantity, $notes, $user, $counting) {
            // Submit the count
            $item->submitCount($countNumber, $quantity, $notes);
            
            // Record event
            InventoryCountingEvent::recordCountSubmitted(
                $item,
                $countNumber,
                $quantity,
                $notes,
                $user->id
            );
            
            // Update assignment progress
            $assignment = $counting->assignments()
                ->where('count_number', $countNumber)
                ->first();
            $assignment?->incrementProgress();
            
            // Check if this phase is complete
            $this->checkPhaseCompletion($counting, $countNumber);
        });
    }
    
    /**
     * Check if a counting phase is complete and transition status
     */
    private function checkPhaseCompletion(InventoryCounting $counting, int $countNumber): void
    {
        $column = "count_{$countNumber}_qty";
        $totalItems = $counting->items()->count();
        $countedItems = $counting->items()->whereNotNull($column)->count();
        
        if ($countedItems < $totalItems) {
            return; // Phase not complete
        }
        
        // Phase is complete
        $assignment = $counting->assignments()
            ->where('count_number', $countNumber)
            ->first();
        $assignment?->complete();
        
        // Determine next status
        $nextStatus = match($countNumber) {
            1 => $counting->requires_count_2 
                ? CountingStatus::Count2InProgress 
                : CountingStatus::PendingReview,
            2 => CountingStatus::PendingReview,
            3 => CountingStatus::PendingReview,
        };
        
        $counting->transitionTo(match($countNumber) {
            1 => CountingStatus::Count1Completed,
            2 => CountingStatus::Count2Completed,
            3 => CountingStatus::Count3Completed,
        });
        
        // If moving to next count phase
        if ($nextStatus === CountingStatus::Count2InProgress) {
            $counting->transitionTo($nextStatus);
            $counting->assignments()
                ->where('count_number', 2)
                ->first()
                ?->start();
        }
        
        // If moving to pending review, run reconciliation
        if ($nextStatus === CountingStatus::PendingReview) {
            $counting->transitionTo($nextStatus);
            $this->reconciliationService->runReconciliation($counting);
        }
    }
    
    /**
     * Get items for a counter (BLIND - no theoretical qty!)
     * 
     * CRITICAL: This method must NEVER return theoretical_qty or other counters' results
     */
    public function getItemsForCounter(
        InventoryCounting $counting,
        User $user,
        bool $uncountedOnly = false
    ): Collection {
        $countNumber = $counting->getUserCountNumber($user->id);
        
        if ($countNumber === null) {
            throw new \InvalidArgumentException('User is not assigned to this counting');
        }
        
        $column = "count_{$countNumber}_qty";
        
        $query = $counting->items()
            ->with(['product', 'variant', 'location', 'warehouse'])
            ->select([
                'id',
                'counting_id',
                'product_id',
                'variant_id',
                'location_id',
                'warehouse_id',
                // Include ONLY this counter's data
                $column . ' as my_count_qty',
                "count_{$countNumber}_at as my_count_at",
                "count_{$countNumber}_notes as my_count_notes",
                // NEVER include: theoretical_qty, count_X_qty (other counters)
            ]);
        
        if ($uncountedOnly) {
            $query->whereNull($column);
        }
        
        return $query->get();
    }
    
    /**
     * Get full item details (admin view - includes all data)
     */
    public function getItemsForAdmin(InventoryCounting $counting): Collection
    {
        return $counting->items()
            ->with(['product', 'variant', 'location', 'warehouse', 'resolvedBy'])
            ->get();
    }
    
    /**
     * Trigger third count for specific items
     */
    public function triggerThirdCount(
        InventoryCounting $counting,
        array $itemIds,
        User $triggeredBy
    ): void {
        if (!$counting->count_3_user_id) {
            throw new \InvalidArgumentException('No user assigned for third count');
        }
        
        DB::transaction(function () use ($counting, $itemIds, $triggeredBy) {
            // Update items to require third count
            InventoryCountingItem::whereIn('id', $itemIds)
                ->where('counting_id', $counting->id)
                ->update([
                    'resolution_method' => ItemResolutionMethod::Pending,
                    'is_flagged' => true,
                    'flag_reason' => 'third_count_requested',
                ]);
            
            // Update count 3 assignment
            $assignment = $counting->assignments()
                ->where('count_number', 3)
                ->first();
            
            if ($assignment) {
                $assignment->total_items = count($itemIds);
                $assignment->save();
            }
            
            // Transition to count 3 in progress
            if ($counting->status === CountingStatus::PendingReview) {
                $counting->transitionTo(CountingStatus::Count3InProgress);
                $assignment?->start();
            }
            
            // Record event
            InventoryCountingEvent::create([
                'counting_id' => $counting->id,
                'event_type' => InventoryCountingEvent::THIRD_COUNT_TRIGGERED,
                'event_data' => [
                    'item_ids' => $itemIds,
                    'count' => count($itemIds),
                ],
                'user_id' => $triggeredBy->id,
            ]);
        });
    }
    
    /**
     * Manual override for an item
     */
    public function manualOverride(
        InventoryCountingItem $item,
        float $quantity,
        string $notes,
        User $user
    ): void {
        DB::transaction(function () use ($item, $quantity, $notes, $user) {
            $item->final_qty = $quantity;
            $item->resolution_method = ItemResolutionMethod::ManualOverride;
            $item->resolution_notes = $notes;
            $item->resolved_by_user_id = $user->id;
            $item->resolved_at = now();
            $item->is_flagged = true;
            $item->flag_reason = 'manual_override';
            $item->save();
            
            InventoryCountingEvent::recordManualOverride(
                $item,
                $quantity,
                $notes,
                $user->id
            );
        });
    }
    
    /**
     * Finalize counting and create stock adjustments
     */
    public function finalize(InventoryCounting $counting, User $user): void
    {
        // Ensure all items are resolved
        $unresolvedCount = $counting->items()
            ->where('resolution_method', ItemResolutionMethod::Pending)
            ->count();
        
        if ($unresolvedCount > 0) {
            throw new \InvalidArgumentException(
                "Cannot finalize: {$unresolvedCount} items still pending resolution"
            );
        }
        
        DB::transaction(function () use ($counting, $user) {
            // Create stock adjustments for items with variance
            $adjustmentIds = [];
            
            foreach ($counting->items as $item) {
                if ($item->variance !== 0 && $item->variance !== null) {
                    // TODO: Create stock adjustment
                    // This integrates with your existing stock management
                }
            }
            
            // Finalize the counting
            $counting->transitionTo(CountingStatus::Finalized);
            
            // Record event
            InventoryCountingEvent::create([
                'counting_id' => $counting->id,
                'event_type' => InventoryCountingEvent::COUNTING_FINALIZED,
                'event_data' => [
                    'total_items' => $counting->items()->count(),
                    'items_with_variance' => $counting->items()->whereRaw('final_qty != theoretical_qty')->count(),
                    'adjustment_ids' => $adjustmentIds,
                ],
                'user_id' => $user->id,
            ]);
        });
    }
    
    /**
     * Cancel a counting operation
     */
    public function cancel(InventoryCounting $counting, string $reason, User $user): void
    {
        if ($counting->status === CountingStatus::Finalized || 
            $counting->status === CountingStatus::Cancelled) {
            throw new \InvalidArgumentException('Cannot cancel finalized or already cancelled counting');
        }
        
        DB::transaction(function () use ($counting, $reason, $user) {
            $counting->cancellation_reason = $reason;
            $counting->save();
            $counting->transitionTo(CountingStatus::Cancelled);
            
            InventoryCountingEvent::create([
                'counting_id' => $counting->id,
                'event_type' => InventoryCountingEvent::COUNTING_CANCELLED,
                'event_data' => [
                    'reason' => $reason,
                    'previous_status' => $counting->status->value,
                ],
                'user_id' => $user->id,
            ]);
        });
    }
}
```

### Task 4.2: Create CountingReconciliationService

```php
// app/Services/Inventory/CountingReconciliationService.php

namespace App\Services\Inventory;

use App\Enums\Inventory\ItemResolutionMethod;
use App\Models\Inventory\{InventoryCounting, InventoryCountingItem, InventoryCountingEvent};
use Illuminate\Support\Facades\DB;

class CountingReconciliationService
{
    private const EPSILON = 0.0001;
    private const VARIANCE_THRESHOLD_MINOR = 0.02; // 2%
    private const VARIANCE_THRESHOLD_SIGNIFICANT = 0.05; // 5%
    private const VARIANCE_THRESHOLD_CRITICAL = 0.10; // 10%
    
    /**
     * Run reconciliation for all items in a counting
     */
    public function runReconciliation(InventoryCounting $counting): void
    {
        DB::transaction(function () use ($counting) {
            foreach ($counting->items as $item) {
                $this->reconcileItem($item, $counting->requires_count_3);
            }
        });
    }
    
    /**
     * Reconcile a single item
     */
    public function reconcileItem(InventoryCountingItem $item, bool $hasThirdCount = false): void
    {
        // Skip if already resolved
        if ($item->resolution_method !== ItemResolutionMethod::Pending) {
            return;
        }
        
        $count1 = $item->count_1_qty !== null ? (float) $item->count_1_qty : null;
        $count2 = $item->count_2_qty !== null ? (float) $item->count_2_qty : null;
        $count3 = $item->count_3_qty !== null ? (float) $item->count_3_qty : null;
        $theoretical = (float) $item->theoretical_qty;
        
        // Single count mode
        if ($count2 === null) {
            $this->resolveSingleCount($item, $count1, $theoretical);
            return;
        }
        
        // Double count mode
        if ($count3 === null) {
            $this->resolveDoubleCount($item, $count1, $count2, $theoretical, $hasThirdCount);
            return;
        }
        
        // Triple count mode
        $this->resolveTripleCount($item, $count1, $count2, $count3, $theoretical);
    }
    
    /**
     * Resolve single count
     */
    private function resolveSingleCount(
        InventoryCountingItem $item,
        float $count1,
        float $theoretical
    ): void {
        $item->final_qty = $count1;
        $item->resolved_at = now();
        
        if ($this->floatsEqual($count1, $theoretical)) {
            $item->resolution_method = ItemResolutionMethod::AutoAllMatch;
            $item->is_flagged = false;
        } else {
            $item->resolution_method = ItemResolutionMethod::AutoCountersAgree;
            $item->is_flagged = true;
            $item->flag_reason = $this->getVarianceFlagReason($count1, $theoretical);
        }
        
        $item->save();
        
        InventoryCountingEvent::recordAutoResolution(
            $item,
            $item->resolution_method->value,
            $count1
        );
    }
    
    /**
     * Resolve double count
     */
    private function resolveDoubleCount(
        InventoryCountingItem $item,
        float $count1,
        float $count2,
        float $theoretical,
        bool $hasThirdCount
    ): void {
        // Case 1: Both counts match theoretical
        if ($this->floatsEqual($count1, $theoretical) && $this->floatsEqual($count2, $theoretical)) {
            $item->final_qty = $theoretical;
            $item->resolution_method = ItemResolutionMethod::AutoAllMatch;
            $item->is_flagged = false;
            $item->resolved_at = now();
            $item->save();
            
            InventoryCountingEvent::recordAutoResolution($item, 'auto_all_match', $theoretical);
            return;
        }
        
        // Case 2: Counters agree but differ from theoretical
        if ($this->floatsEqual($count1, $count2)) {
            $item->final_qty = $count1;
            $item->resolution_method = ItemResolutionMethod::AutoCountersAgree;
            $item->is_flagged = true;
            $item->flag_reason = 'variance_from_theoretical';
            $item->resolved_at = now();
            $item->save();
            
            InventoryCountingEvent::recordAutoResolution($item, 'auto_counters_agree', $count1);
            return;
        }
        
        // Case 3: Counters disagree
        // Flag for third count or manual override
        $item->is_flagged = true;
        $item->flag_reason = 'counter_disagreement';
        
        // If one matches theoretical, suggest 3rd count
        if ($this->floatsEqual($count1, $theoretical) || $this->floatsEqual($count2, $theoretical)) {
            $item->flag_reason = 'counter_disagreement_one_matches_theoretical';
        }
        
        $item->save();
    }
    
    /**
     * Resolve triple count
     */
    private function resolveTripleCount(
        InventoryCountingItem $item,
        float $count1,
        float $count2,
        float $count3,
        float $theoretical
    ): void {
        // Find majority (2 of 3 agree)
        $counts = [$count1, $count2, $count3];
        $majority = $this->findMajority($counts);
        
        if ($majority !== null) {
            $item->final_qty = $majority;
            $item->resolution_method = ItemResolutionMethod::ThirdCountDecisive;
            $item->resolved_at = now();
            
            // Determine which counter was wrong
            $wrongCounter = $this->identifyWrongCounter($count1, $count2, $count3, $majority);
            
            $item->is_flagged = true;
            $item->flag_reason = $this->floatsEqual($majority, $theoretical)
                ? "counter_{$wrongCounter}_proven_wrong"
                : 'variance_confirmed_by_third_count';
            
            $item->save();
            
            InventoryCountingEvent::recordAutoResolution($item, 'third_count_decisive', $majority);
            return;
        }
        
        // All three counts differ - requires manual override
        $item->is_flagged = true;
        $item->flag_reason = 'no_consensus';
        $item->save();
    }
    
    /**
     * Find majority value (2 of 3 must match)
     */
    private function findMajority(array $counts): ?float
    {
        if ($this->floatsEqual($counts[0], $counts[1])) {
            return $counts[0];
        }
        if ($this->floatsEqual($counts[0], $counts[2])) {
            return $counts[0];
        }
        if ($this->floatsEqual($counts[1], $counts[2])) {
            return $counts[1];
        }
        
        return null; // No majority
    }
    
    /**
     * Identify which counter was wrong
     */
    private function identifyWrongCounter(
        float $count1,
        float $count2,
        float $count3,
        float $majority
    ): int {
        if (!$this->floatsEqual($count1, $majority)) return 1;
        if (!$this->floatsEqual($count2, $majority)) return 2;
        return 3;
    }
    
    /**
     * Get flag reason based on variance
     */
    private function getVarianceFlagReason(float $counted, float $theoretical): string
    {
        if ($theoretical === 0.0) {
            return 'variance_from_zero_theoretical';
        }
        
        $variancePercent = abs(($counted - $theoretical) / $theoretical);
        
        if ($variancePercent >= self::VARIANCE_THRESHOLD_CRITICAL) {
            return 'critical_variance';
        }
        if ($variancePercent >= self::VARIANCE_THRESHOLD_SIGNIFICANT) {
            return 'significant_variance';
        }
        if ($variancePercent >= self::VARIANCE_THRESHOLD_MINOR) {
            return 'minor_variance';
        }
        
        return 'variance_from_theoretical';
    }
    
    /**
     * Compare floats with epsilon tolerance
     */
    private function floatsEqual(float $a, float $b): bool
    {
        return abs($a - $b) < self::EPSILON;
    }
}
```

### Verification Command:
```bash
cd apps/api
./vendor/bin/phpstan analyse app/Services/Inventory --level=8
```

---

## PHASE 5: API Controllers

### Task 5.1: Create InventoryCountingController

```php
// app/Http/Controllers/Api/V1/Inventory/InventoryCountingController.php

namespace App\Http\Controllers\Api\V1\Inventory;

use App\Http\Controllers\Controller;
use App\Http\Requests\Inventory\{
    CreateCountingRequest,
    UpdateCountingRequest
};
use App\Http\Resources\Inventory\{
    InventoryCountingResource,
    InventoryCountingCollection,
    CountingDashboardResource
};
use App\Models\Inventory\InventoryCounting;
use App\Services\Inventory\InventoryCountingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InventoryCountingController extends Controller
{
    public function __construct(
        private InventoryCountingService $countingService
    ) {}
    
    /**
     * Dashboard summary
     */
    public function dashboard(Request $request): JsonResponse
    {
        $companyId = $request->user()->current_company_id;
        
        $active = InventoryCounting::forCompany($companyId)->active()->count();
        $pendingReview = InventoryCounting::forCompany($companyId)->pendingReview()->count();
        $completedThisMonth = InventoryCounting::forCompany($companyId)
            ->where('status', 'finalized')
            ->whereMonth('finalized_at', now()->month)
            ->count();
        $overdue = InventoryCounting::forCompany($companyId)
            ->active()
            ->where('scheduled_end', '<', now())
            ->count();
        
        $activeCounts = InventoryCounting::forCompany($companyId)
            ->active()
            ->with(['count1User', 'count2User', 'count3User', 'assignments'])
            ->orderBy('scheduled_end')
            ->take(5)
            ->get();
        
        $pendingReviewCounts = InventoryCounting::forCompany($companyId)
            ->pendingReview()
            ->with(['items'])
            ->orderBy('updated_at', 'desc')
            ->take(5)
            ->get();
        
        return response()->json([
            'data' => [
                'summary' => [
                    'active' => $active,
                    'pending_review' => $pendingReview,
                    'completed_this_month' => $completedThisMonth,
                    'overdue' => $overdue,
                ],
                'active_counts' => InventoryCountingResource::collection($activeCounts),
                'pending_review' => InventoryCountingResource::collection($pendingReviewCounts),
            ],
        ]);
    }
    
    /**
     * List counting operations
     */
    public function index(Request $request): InventoryCountingCollection
    {
        $query = InventoryCounting::forCompany($request->user()->current_company_id)
            ->with(['count1User', 'count2User', 'count3User', 'createdBy']);
        
        // Apply filters
        if ($request->has('status')) {
            $query->where('status', $request->input('status'));
        }
        
        if ($request->has('warehouse_id')) {
            $query->whereJsonContains('scope_filters->warehouse_ids', (int) $request->input('warehouse_id'));
        }
        
        if ($request->has('search')) {
            $search = $request->input('search');
            $query->where('uuid', 'ilike', "%{$search}%");
        }
        
        // Apply sorting
        $sortBy = $request->input('sort_by', 'created_at');
        $sortDir = $request->input('sort_dir', 'desc');
        $query->orderBy($sortBy, $sortDir);
        
        return new InventoryCountingCollection(
            $query->paginate($request->input('per_page', 15))
        );
    }
    
    /**
     * Show counting details (admin view - includes all data)
     */
    public function show(InventoryCounting $counting): InventoryCountingResource
    {
        $this->authorize('view', $counting);
        
        $counting->load([
            'count1User',
            'count2User', 
            'count3User',
            'createdBy',
            'assignments',
            'items.product',
            'items.location',
        ]);
        
        return new InventoryCountingResource($counting);
    }
    
    /**
     * Counter view (BLIND - no theoretical quantities!)
     * 
     * CRITICAL: This endpoint must NEVER return theoretical_qty or other counters' results
     */
    public function counterView(Request $request, InventoryCounting $counting): JsonResponse
    {
        $user = $request->user();
        
        // Verify user is assigned
        if (!$counting->isUserAssigned($user->id)) {
            abort(403, 'You are not assigned to this counting');
        }
        
        $items = $this->countingService->getItemsForCounter($counting, $user);
        
        // Transform items - NEVER include theoretical_qty
        $transformedItems = $items->map(fn($item) => [
            'id' => $item->id,
            'product' => [
                'id' => $item->product->id,
                'name' => $item->product->name,
                'sku' => $item->product->sku,
                'barcode' => $item->product->barcode,
                'image_url' => $item->product->image_url,
            ],
            'variant' => $item->variant ? [
                'id' => $item->variant->id,
                'name' => $item->variant->name,
            ] : null,
            'location' => [
                'id' => $item->location->id,
                'code' => $item->location->code,
                'name' => $item->location->name,
            ],
            'warehouse' => [
                'id' => $item->warehouse->id,
                'name' => $item->warehouse->name,
            ],
            'unit_of_measure' => $item->product->unit_of_measure,
            'is_counted' => $item->my_count_qty !== null,
            'my_count' => $item->my_count_qty,
            'my_count_at' => $item->my_count_at,
            // NEVER INCLUDE: theoretical_qty, count_1_qty, count_2_qty, count_3_qty
        ]);
        
        return response()->json([
            'data' => [
                'counting' => [
                    'id' => $counting->id,
                    'uuid' => $counting->uuid,
                    'status' => $counting->status,
                    'instructions' => $counting->instructions,
                    'deadline' => $counting->scheduled_end,
                ],
                'my_count_number' => $counting->getUserCountNumber($user->id),
                'items' => $transformedItems,
                'progress' => [
                    'counted' => $items->filter(fn($i) => $i->my_count_qty !== null)->count(),
                    'total' => $items->count(),
                ],
            ],
        ]);
    }
    
    /**
     * Create counting operation
     */
    public function store(CreateCountingRequest $request): InventoryCountingResource
    {
        $counting = $this->countingService->create(
            $request->validated(),
            $request->user()
        );
        
        return new InventoryCountingResource($counting);
    }
    
    /**
     * Activate counting
     */
    public function activate(InventoryCounting $counting, Request $request): JsonResponse
    {
        $this->authorize('activate', $counting);
        
        $this->countingService->activate($counting, $request->user());
        
        return response()->json([
            'message' => 'Counting activated successfully',
            'data' => new InventoryCountingResource($counting->fresh()),
        ]);
    }
    
    /**
     * Cancel counting
     */
    public function cancel(InventoryCounting $counting, Request $request): JsonResponse
    {
        $this->authorize('cancel', $counting);
        
        $request->validate([
            'reason' => 'required|string|min:10',
        ]);
        
        $this->countingService->cancel(
            $counting,
            $request->input('reason'),
            $request->user()
        );
        
        return response()->json([
            'message' => 'Counting cancelled',
        ]);
    }
    
    /**
     * Finalize counting
     */
    public function finalize(InventoryCounting $counting, Request $request): JsonResponse
    {
        $this->authorize('finalize', $counting);
        
        $this->countingService->finalize($counting, $request->user());
        
        return response()->json([
            'message' => 'Counting finalized successfully',
            'data' => new InventoryCountingResource($counting->fresh()),
        ]);
    }
}
```

### Task 5.2: Create CountingItemController

```php
// app/Http/Controllers/Api/V1/Inventory/CountingItemController.php

namespace App\Http\Controllers\Api\V1\Inventory;

use App\Http\Controllers\Controller;
use App\Http\Requests\Inventory\{SubmitCountRequest, ManualOverrideRequest};
use App\Models\Inventory\{InventoryCounting, InventoryCountingItem};
use App\Services\Inventory\InventoryCountingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CountingItemController extends Controller
{
    public function __construct(
        private InventoryCountingService $countingService
    ) {}
    
    /**
     * Get items for counter (BLIND view)
     */
    public function toCount(Request $request, InventoryCounting $counting): JsonResponse
    {
        $user = $request->user();
        
        if (!$counting->isUserAssigned($user->id)) {
            abort(403, 'You are not assigned to this counting');
        }
        
        $uncountedOnly = $request->boolean('uncounted_only', true);
        $items = $this->countingService->getItemsForCounter($counting, $user, $uncountedOnly);
        
        return response()->json([
            'data' => $items,
        ]);
    }
    
    /**
     * Submit count for an item
     */
    public function submitCount(
        SubmitCountRequest $request,
        InventoryCounting $counting,
        InventoryCountingItem $item
    ): JsonResponse {
        $user = $request->user();
        $countNumber = $counting->getUserCountNumber($user->id);
        
        if ($countNumber === null) {
            abort(403, 'You are not assigned to this counting');
        }
        
        $this->countingService->submitCount(
            $item,
            $countNumber,
            $request->input('quantity'),
            $request->input('notes'),
            $user
        );
        
        return response()->json([
            'message' => 'Count submitted successfully',
            'data' => [
                'item_id' => $item->id,
                'quantity' => $request->input('quantity'),
                'count_number' => $countNumber,
            ],
        ]);
    }
    
    /**
     * Lookup item by barcode (for mobile scanner)
     */
    public function lookupByBarcode(Request $request, InventoryCounting $counting): JsonResponse
    {
        $user = $request->user();
        
        if (!$counting->isUserAssigned($user->id)) {
            abort(403, 'You are not assigned to this counting');
        }
        
        $barcode = $request->input('barcode');
        
        $item = $counting->items()
            ->whereHas('product', fn($q) => $q->where('barcode', $barcode))
            ->with(['product', 'variant', 'location', 'warehouse'])
            ->first();
        
        if (!$item) {
            return response()->json([
                'found' => false,
                'message' => 'Product not found in this counting',
            ], 404);
        }
        
        $countNumber = $counting->getUserCountNumber($user->id);
        $myCountColumn = "count_{$countNumber}_qty";
        
        return response()->json([
            'found' => true,
            'data' => [
                'id' => $item->id,
                'product' => [
                    'id' => $item->product->id,
                    'name' => $item->product->name,
                    'sku' => $item->product->sku,
                    'barcode' => $item->product->barcode,
                    'image_url' => $item->product->image_url,
                ],
                'location' => [
                    'id' => $item->location->id,
                    'code' => $item->location->code,
                ],
                'is_counted' => $item->$myCountColumn !== null,
                'my_count' => $item->$myCountColumn,
                // NEVER INCLUDE: theoretical_qty
            ],
        ]);
    }
    
    /**
     * Get reconciliation data (admin only)
     */
    public function reconciliation(InventoryCounting $counting): JsonResponse
    {
        $this->authorize('viewReconciliation', $counting);
        
        $items = $this->countingService->getItemsForAdmin($counting);
        
        $summary = [
            'total' => $items->count(),
            'auto_resolved' => $items->filter(fn($i) => $i->resolution_method?->isAutomatic())->count(),
            'needs_attention' => $items->filter(fn($i) => $i->is_flagged && $i->final_qty === null)->count(),
            'manually_overridden' => $items->filter(fn($i) => $i->resolution_method === \App\Enums\Inventory\ItemResolutionMethod::ManualOverride)->count(),
        ];
        
        return response()->json([
            'data' => [
                'summary' => $summary,
                'items' => $items->map(fn($item) => [
                    'id' => $item->id,
                    'product' => [
                        'id' => $item->product->id,
                        'name' => $item->product->name,
                        'sku' => $item->product->sku,
                    ],
                    'location' => [
                        'code' => $item->location->code,
                        'name' => $item->location->name,
                    ],
                    'theoretical_qty' => $item->theoretical_qty,
                    'count_1' => [
                        'qty' => $item->count_1_qty,
                        'at' => $item->count_1_at,
                        'notes' => $item->count_1_notes,
                    ],
                    'count_2' => $item->count_2_qty !== null ? [
                        'qty' => $item->count_2_qty,
                        'at' => $item->count_2_at,
                        'notes' => $item->count_2_notes,
                    ] : null,
                    'count_3' => $item->count_3_qty !== null ? [
                        'qty' => $item->count_3_qty,
                        'at' => $item->count_3_at,
                        'notes' => $item->count_3_notes,
                    ] : null,
                    'final_qty' => $item->final_qty,
                    'variance' => $item->variance,
                    'resolution_method' => $item->resolution_method?->value,
                    'resolution_notes' => $item->resolution_notes,
                    'is_flagged' => $item->is_flagged,
                    'flag_reason' => $item->flag_reason,
                ]),
            ],
        ]);
    }
    
    /**
     * Trigger third count for items
     */
    public function triggerThirdCount(Request $request, InventoryCounting $counting): JsonResponse
    {
        $this->authorize('triggerThirdCount', $counting);
        
        $request->validate([
            'item_ids' => 'required|array|min:1',
            'item_ids.*' => 'integer|exists:inventory_counting_items,id',
        ]);
        
        $this->countingService->triggerThirdCount(
            $counting,
            $request->input('item_ids'),
            $request->user()
        );
        
        return response()->json([
            'message' => 'Third count triggered',
        ]);
    }
    
    /**
     * Manual override
     */
    public function override(ManualOverrideRequest $request, InventoryCountingItem $item): JsonResponse
    {
        $this->authorize('override', $item->counting);
        
        $this->countingService->manualOverride(
            $item,
            $request->input('quantity'),
            $request->input('notes'),
            $request->user()
        );
        
        return response()->json([
            'message' => 'Item overridden successfully',
        ]);
    }
}
```

---

## PHASE 6: Form Requests

### Task 6.1: Create Form Requests

```php
// app/Http/Requests/Inventory/CreateCountingRequest.php

namespace App\Http\Requests\Inventory;

use App\Enums\Inventory\{CountingScopeType, CountingExecutionMode};
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateCountingRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'scope_type' => ['required', Rule::enum(CountingScopeType::class)],
            
            'scope_filters' => ['required', 'array'],
            'scope_filters.product_ids' => ['array'],
            'scope_filters.product_ids.*' => ['integer', 'exists:products,id'],
            'scope_filters.category_ids' => ['array'],
            'scope_filters.category_ids.*' => ['integer', 'exists:categories,id'],
            'scope_filters.warehouse_ids' => ['array'],
            'scope_filters.warehouse_ids.*' => ['integer', 'exists:warehouses,id'],
            'scope_filters.location_ids' => ['array'],
            'scope_filters.location_ids.*' => ['integer', 'exists:warehouse_locations,id'],
            'scope_filters.location_id' => ['integer', 'exists:warehouse_locations,id'],
            
            'execution_mode' => ['sometimes', Rule::enum(CountingExecutionMode::class)],
            
            'requires_count_2' => ['sometimes', 'boolean'],
            'requires_count_3' => ['sometimes', 'boolean'],
            'allow_unexpected_items' => ['sometimes', 'boolean'],
            
            'count_1_user_id' => ['required', 'integer', 'exists:users,id'],
            'count_2_user_id' => ['required_if:requires_count_2,true', 'nullable', 'integer', 'exists:users,id'],
            'count_3_user_id' => ['required_if:requires_count_3,true', 'nullable', 'integer', 'exists:users,id'],
            
            'scheduled_start' => ['nullable', 'date', 'after_or_equal:now'],
            'scheduled_end' => ['nullable', 'date', 'after:scheduled_start'],
            
            'instructions' => ['nullable', 'string', 'max:2000'],
        ];
    }
    
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            // Validate scope filters based on scope type
            $this->validateScopeFilters($validator);
            
            // Validate execution mode when same user assigned to multiple counts
            $this->validateExecutionMode($validator);
        });
    }
    
    private function validateScopeFilters($validator): void
    {
        $scopeType = $this->input('scope_type');
        $filters = $this->input('scope_filters', []);
        
        switch ($scopeType) {
            case 'product_location':
                if (empty($filters['product_ids'])) {
                    $validator->errors()->add('scope_filters.product_ids', 'Products are required for this scope');
                }
                if (empty($filters['location_id'])) {
                    $validator->errors()->add('scope_filters.location_id', 'Location is required for this scope');
                }
                break;
                
            case 'product':
                if (empty($filters['product_ids'])) {
                    $validator->errors()->add('scope_filters.product_ids', 'Products are required for this scope');
                }
                break;
                
            case 'location':
                if (empty($filters['location_ids'])) {
                    $validator->errors()->add('scope_filters.location_ids', 'Locations are required for this scope');
                }
                break;
                
            case 'category':
                if (empty($filters['category_ids'])) {
                    $validator->errors()->add('scope_filters.category_ids', 'Categories are required for this scope');
                }
                break;
                
            case 'warehouse':
                if (empty($filters['warehouse_ids'])) {
                    $validator->errors()->add('scope_filters.warehouse_ids', 'Warehouses are required for this scope');
                }
                break;
        }
    }
    
    private function validateExecutionMode($validator): void
    {
        $userIds = array_filter([
            $this->input('count_1_user_id'),
            $this->input('count_2_user_id'),
            $this->input('count_3_user_id'),
        ]);
        
        $uniqueUsers = array_unique($userIds);
        
        // If same user assigned to multiple counts, must use sequential mode
        if (count($userIds) !== count($uniqueUsers) && $this->input('execution_mode') === 'parallel') {
            $validator->errors()->add(
                'execution_mode',
                'Sequential mode is required when the same user is assigned to multiple counts'
            );
        }
    }
}
```

```php
// app/Http/Requests/Inventory/SubmitCountRequest.php

namespace App\Http\Requests\Inventory;

use Illuminate\Foundation\Http\FormRequest;

class SubmitCountRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'quantity' => ['required', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }
}
```

```php
// app/Http/Requests/Inventory/ManualOverrideRequest.php

namespace App\Http\Requests\Inventory;

use Illuminate\Foundation\Http\FormRequest;

class ManualOverrideRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'quantity' => ['required', 'numeric', 'min:0'],
            'notes' => ['required', 'string', 'min:10', 'max:1000'],
        ];
    }
    
    public function messages(): array
    {
        return [
            'notes.required' => 'Detailed notes are required when manually overriding a count',
            'notes.min' => 'Please provide more detailed notes (at least 10 characters)',
        ];
    }
}
```

---

## PHASE 7: API Routes

### Task 7.1: Define Routes

```php
// routes/api/v1/inventory.php

use App\Http\Controllers\Api\V1\Inventory\{
    InventoryCountingController,
    CountingItemController,
    CountingReportController
};
use Illuminate\Support\Facades\Route;

Route::prefix('inventory/countings')->middleware(['auth:sanctum'])->group(function () {
    // Dashboard
    Route::get('dashboard', [InventoryCountingController::class, 'dashboard']);
    
    // List & CRUD
    Route::get('/', [InventoryCountingController::class, 'index']);
    Route::post('/', [InventoryCountingController::class, 'store']);
    Route::get('{counting}', [InventoryCountingController::class, 'show']);
    
    // Counter-specific endpoints (BLIND view)
    Route::get('my-tasks', [InventoryCountingController::class, 'myTasks']);
    Route::get('{counting}/counter-view', [InventoryCountingController::class, 'counterView']);
    Route::get('{counting}/items/to-count', [CountingItemController::class, 'toCount']);
    Route::get('{counting}/lookup', [CountingItemController::class, 'lookupByBarcode']);
    
    // Submit count
    Route::post('{counting}/items/{item}/count', [CountingItemController::class, 'submitCount']);
    
    // Admin actions
    Route::post('{counting}/activate', [InventoryCountingController::class, 'activate']);
    Route::post('{counting}/cancel', [InventoryCountingController::class, 'cancel']);
    Route::post('{counting}/finalize', [InventoryCountingController::class, 'finalize']);
    
    // Reconciliation
    Route::get('{counting}/reconciliation', [CountingItemController::class, 'reconciliation']);
    Route::post('{counting}/trigger-third-count', [CountingItemController::class, 'triggerThirdCount']);
    Route::post('items/{item}/override', [CountingItemController::class, 'override']);
    
    // Reports
    Route::get('{counting}/report', [CountingReportController::class, 'show']);
    Route::get('{counting}/report/export', [CountingReportController::class, 'export']);
});
```

---

## PHASE 8: Tests

### Task 8.1: Create Feature Tests

```php
// tests/Feature/Inventory/BlindCountingTest.php

namespace Tests\Feature\Inventory;

use App\Models\Inventory\{InventoryCounting, InventoryCountingItem};
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BlindCountingTest extends TestCase
{
    use RefreshDatabase;
    
    /**
     * CRITICAL TEST: Counter must NEVER see theoretical quantities
     */
    public function test_counter_cannot_see_theoretical_quantity(): void
    {
        $counting = InventoryCounting::factory()
            ->has(InventoryCountingItem::factory()->count(5))
            ->create(['status' => 'count_1_in_progress']);
        
        $counter = User::find($counting->count_1_user_id);
        
        $response = $this->actingAs($counter)
            ->getJson("/api/v1/inventory/countings/{$counting->id}/counter-view");
        
        $response->assertOk();
        
        // Assert theoretical_qty is NOT in response
        $response->assertJsonMissingPath('data.items.0.theoretical_qty');
        
        // Also check raw JSON
        $json = $response->json();
        $this->assertArrayNotHasKey('theoretical_qty', $json['data']['items'][0]);
    }
    
    /**
     * CRITICAL TEST: Counter must NEVER see other counters' results
     */
    public function test_counter_cannot_see_other_counters_results(): void
    {
        $counting = InventoryCounting::factory()
            ->has(InventoryCountingItem::factory()->state([
                'count_1_qty' => 10,
            ])->count(5))
            ->create(['status' => 'count_2_in_progress']);
        
        $counter2 = User::find($counting->count_2_user_id);
        
        $response = $this->actingAs($counter2)
            ->getJson("/api/v1/inventory/countings/{$counting->id}/counter-view");
        
        $response->assertOk();
        
        // Assert count_1_qty is NOT in response
        $response->assertJsonMissingPath('data.items.0.count_1_qty');
        $response->assertJsonMissingPath('data.items.0.count_2_qty');
        $response->assertJsonMissingPath('data.items.0.count_3_qty');
    }
    
    public function test_admin_can_see_all_counts(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        
        $counting = InventoryCounting::factory()
            ->has(InventoryCountingItem::factory()->state([
                'theoretical_qty' => 100,
                'count_1_qty' => 98,
                'count_2_qty' => 99,
            ])->count(5))
            ->create(['status' => 'pending_review']);
        
        $response = $this->actingAs($admin)
            ->getJson("/api/v1/inventory/countings/{$counting->id}/reconciliation");
        
        $response->assertOk();
        
        // Admin CAN see all data
        $response->assertJsonPath('data.items.0.theoretical_qty', 100);
        $response->assertJsonPath('data.items.0.count_1.qty', 98);
        $response->assertJsonPath('data.items.0.count_2.qty', 99);
    }
}
```

```php
// tests/Feature/Inventory/ReconciliationTest.php

namespace Tests\Feature\Inventory;

use App\Enums\Inventory\ItemResolutionMethod;
use App\Models\Inventory\{InventoryCounting, InventoryCountingItem};
use App\Services\Inventory\CountingReconciliationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReconciliationTest extends TestCase
{
    use RefreshDatabase;
    
    private CountingReconciliationService $service;
    
    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(CountingReconciliationService::class);
    }
    
    public function test_auto_resolves_when_all_counts_match_theoretical(): void
    {
        $item = InventoryCountingItem::factory()->create([
            'theoretical_qty' => 50,
            'count_1_qty' => 50,
            'count_2_qty' => 50,
        ]);
        
        $this->service->reconcileItem($item);
        
        $item->refresh();
        
        $this->assertEquals(50, $item->final_qty);
        $this->assertEquals(ItemResolutionMethod::AutoAllMatch, $item->resolution_method);
        $this->assertFalse($item->is_flagged);
    }
    
    public function test_auto_resolves_when_counters_agree_but_differ_from_theoretical(): void
    {
        $item = InventoryCountingItem::factory()->create([
            'theoretical_qty' => 50,
            'count_1_qty' => 48,
            'count_2_qty' => 48,
        ]);
        
        $this->service->reconcileItem($item);
        
        $item->refresh();
        
        $this->assertEquals(48, $item->final_qty);
        $this->assertEquals(ItemResolutionMethod::AutoCountersAgree, $item->resolution_method);
        $this->assertTrue($item->is_flagged);
        $this->assertEquals('variance_from_theoretical', $item->flag_reason);
    }
    
    public function test_flags_for_third_count_when_counters_disagree(): void
    {
        $item = InventoryCountingItem::factory()->create([
            'theoretical_qty' => 50,
            'count_1_qty' => 48,
            'count_2_qty' => 52,
        ]);
        
        $this->service->reconcileItem($item, hasThirdCount: true);
        
        $item->refresh();
        
        $this->assertNull($item->final_qty);
        $this->assertEquals(ItemResolutionMethod::Pending, $item->resolution_method);
        $this->assertTrue($item->is_flagged);
        $this->assertEquals('counter_disagreement', $item->flag_reason);
    }
    
    public function test_third_count_resolves_by_majority(): void
    {
        $item = InventoryCountingItem::factory()->create([
            'theoretical_qty' => 50,
            'count_1_qty' => 50,
            'count_2_qty' => 48,
            'count_3_qty' => 50,
        ]);
        
        $this->service->reconcileItem($item);
        
        $item->refresh();
        
        $this->assertEquals(50, $item->final_qty);
        $this->assertEquals(ItemResolutionMethod::ThirdCountDecisive, $item->resolution_method);
        $this->assertStringContainsString('counter_2', $item->flag_reason);
    }
    
    public function test_flags_no_consensus_when_all_three_differ(): void
    {
        $item = InventoryCountingItem::factory()->create([
            'theoretical_qty' => 50,
            'count_1_qty' => 48,
            'count_2_qty' => 52,
            'count_3_qty' => 55,
        ]);
        
        $this->service->reconcileItem($item);
        
        $item->refresh();
        
        $this->assertNull($item->final_qty);
        $this->assertTrue($item->is_flagged);
        $this->assertEquals('no_consensus', $item->flag_reason);
    }
}
```

### Verification Commands:

```bash
cd apps/api

# Run all inventory tests
php artisan test --filter=Inventory

# Run specific test
php artisan test --filter=BlindCountingTest

# Static analysis
./vendor/bin/phpstan analyse app/Models/Inventory app/Services/Inventory app/Http/Controllers/Api/V1/Inventory --level=8

# Check for blind counting violations
grep -r "theoretical_qty" app/Http/Controllers/Api/V1/Inventory/ | grep -v "reconciliation\|admin\|report"
# ^ This should return empty (theoretical_qty should only appear in admin endpoints)
```

---

## FINAL CHECKLIST

Before considering this phase complete, verify:

- [ ] All migrations run without errors
- [ ] All models pass PHPStan level 8
- [ ] All services pass PHPStan level 8
- [ ] All controllers pass PHPStan level 8
- [ ] BlindCountingTest passes (CRITICAL)
- [ ] ReconciliationTest passes
- [ ] No `theoretical_qty` leaks in counter endpoints
- [ ] API routes registered correctly
- [ ] Form validation works correctly
- [ ] Event hash chain integrity verified

```bash
# Final verification script
cd apps/api

echo "=== Running Migrations ==="
php artisan migrate:fresh

echo "=== Running PHPStan ==="
./vendor/bin/phpstan analyse app/Models/Inventory app/Services/Inventory app/Http/Controllers/Api/V1/Inventory app/Enums/Inventory --level=8

echo "=== Running Tests ==="
php artisan test --filter=Inventory

echo "=== Checking for Blind Counting Violations ==="
VIOLATIONS=$(grep -r "theoretical_qty" app/Http/Controllers/Api/V1/Inventory/ | grep -v "reconciliation\|override\|report\|dashboard" | wc -l)
if [ "$VIOLATIONS" -gt 0 ]; then
    echo "WARNING: Potential blind counting violations found!"
    grep -r "theoretical_qty" app/Http/Controllers/Api/V1/Inventory/ | grep -v "reconciliation\|override\|report\|dashboard"
else
    echo "OK: No blind counting violations"
fi

echo "=== Complete ==="
```
