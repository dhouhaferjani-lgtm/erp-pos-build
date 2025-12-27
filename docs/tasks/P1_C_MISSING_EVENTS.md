# P1-C: Add Missing Events to Accounting and Inventory Modules

## Objective

Add proper domain events to the Accounting and Inventory modules to enable event-driven architecture. This allows:
- Vertical modules to react to universal module changes
- Loose coupling between modules
- Audit trail of all significant actions
- Future integration with external systems

## Current State

Based on the audit:
- Document module has events (InvoicePosted, etc.) ✅
- Accounting module has **no events** in Domain/Events folder ❌
- Inventory module has **no events** in Domain/Events folder ❌

## Target State

```
app/Modules/Accounting/Domain/Events/
├── JournalEntryPosted.php
├── JournalEntryReversed.php
├── FiscalPeriodOpened.php
├── FiscalPeriodClosed.php
├── AccountCreated.php
└── AccountDeactivated.php

app/Modules/Inventory/Domain/Events/
├── StockReceived.php
├── StockConsumed.php
├── StockAdjusted.php
├── StockTransferInitiated.php
├── StockTransferCompleted.php
├── StockCountStarted.php
├── StockCountCompleted.php
└── LowStockAlertTriggered.php
```

## Tasks

### Step 1: Audit Existing Events Structure

```bash
# Find all existing events
find app/Modules -path "*/Domain/Events/*" -name "*.php"

# Check Document module events as reference
ls -la app/Modules/Document/Domain/Events/
cat $(find app/Modules/Document/Domain/Events -name "*.php" | head -1)

# Check if Event directories exist
ls -la app/Modules/Accounting/Domain/Events/ 2>/dev/null || echo "No Events folder"
ls -la app/Modules/Inventory/Domain/Events/ 2>/dev/null || echo "No Events folder"

# Find where events should be dispatched
grep -rn "event(" app/Modules/Accounting/Domain/Services --include="*.php"
grep -rn "event(" app/Modules/Inventory/Domain/Services --include="*.php"
```

### Step 2: Create Accounting Events

#### JournalEntryPosted

```php
<?php
// app/Modules/Accounting/Domain/Events/JournalEntryPosted.php

namespace App\Modules\Accounting\Domain\Events;

use App\Modules\Accounting\Domain\JournalEntry;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class JournalEntryPosted
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly JournalEntry $journalEntry,
        public readonly string $sourceType,     // 'invoice', 'payment', 'adjustment', 'manual'
        public readonly ?string $sourceId = null,
    ) {}
    
    /**
     * Get summary for logging/audit
     */
    public function getSummary(): array
    {
        return [
            'journal_entry_id' => $this->journalEntry->id,
            'company_id' => $this->journalEntry->company_id,
            'reference' => $this->journalEntry->reference,
            'entry_date' => $this->journalEntry->entry_date->toDateString(),
            'total_amount' => $this->journalEntry->lines->sum('debit'),
            'source_type' => $this->sourceType,
            'source_id' => $this->sourceId,
            'chain_sequence' => $this->journalEntry->chain_sequence,
        ];
    }
}
```

#### JournalEntryReversed

```php
<?php
// app/Modules/Accounting/Domain/Events/JournalEntryReversed.php

namespace App\Modules\Accounting\Domain\Events;

use App\Modules\Accounting\Domain\JournalEntry;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class JournalEntryReversed
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly JournalEntry $originalEntry,
        public readonly JournalEntry $reversalEntry,
        public readonly string $reason,
        public readonly ?string $reversedBy = null, // User ID
    ) {}
}
```

#### FiscalPeriodOpened

```php
<?php
// app/Modules/Accounting/Domain/Events/FiscalPeriodOpened.php

namespace App\Modules\Accounting\Domain\Events;

use App\Modules\Accounting\Domain\FiscalPeriod;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class FiscalPeriodOpened
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly FiscalPeriod $period,
        public readonly ?string $openedBy = null,
    ) {}
}
```

#### FiscalPeriodClosed

```php
<?php
// app/Modules/Accounting/Domain/Events/FiscalPeriodClosed.php

namespace App\Modules\Accounting\Domain\Events;

use App\Modules\Accounting\Domain\FiscalPeriod;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class FiscalPeriodClosed
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly FiscalPeriod $period,
        public readonly array $closingBalances,    // Account balances at close
        public readonly ?string $closedBy = null,  // User ID
    ) {}
}
```

#### AccountCreated

```php
<?php
// app/Modules/Accounting/Domain/Events/AccountCreated.php

namespace App\Modules\Accounting\Domain\Events;

use App\Modules\Accounting\Domain\Account;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class AccountCreated
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly Account $account,
        public readonly ?string $createdBy = null,
    ) {}
}
```

#### AccountDeactivated

```php
<?php
// app/Modules/Accounting/Domain/Events/AccountDeactivated.php

namespace App\Modules\Accounting\Domain\Events;

use App\Modules\Accounting\Domain\Account;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class AccountDeactivated
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly Account $account,
        public readonly string $reason,
        public readonly ?string $deactivatedBy = null,
    ) {}
}
```

### Step 3: Create Inventory Events

#### StockReceived

```php
<?php
// app/Modules/Inventory/Domain/Events/StockReceived.php

namespace App\Modules\Inventory\Domain\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class StockReceived
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly string $productId,
        public readonly string $locationId,
        public readonly float $quantity,
        public readonly string $sourceType,      // 'goods_receipt', 'return', 'adjustment', 'transfer'
        public readonly string $sourceId,
        public readonly ?float $unitCost = null,
        public readonly ?string $batchNumber = null,
        public readonly ?string $serialNumber = null,
    ) {}
    
    public function getSummary(): array
    {
        return [
            'product_id' => $this->productId,
            'location_id' => $this->locationId,
            'quantity' => $this->quantity,
            'source_type' => $this->sourceType,
            'source_id' => $this->sourceId,
            'unit_cost' => $this->unitCost,
        ];
    }
}
```

#### StockConsumed

```php
<?php
// app/Modules/Inventory/Domain/Events/StockConsumed.php

namespace App\Modules\Inventory\Domain\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class StockConsumed
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly string $productId,
        public readonly string $locationId,
        public readonly float $quantity,
        public readonly string $sourceType,      // 'delivery_note', 'adjustment', 'transfer', 'production'
        public readonly string $sourceId,
        public readonly ?float $unitCost = null, // COGS value
        public readonly ?string $customerId = null,
    ) {}
    
    public function getSummary(): array
    {
        return [
            'product_id' => $this->productId,
            'location_id' => $this->locationId,
            'quantity' => $this->quantity,
            'source_type' => $this->sourceType,
            'source_id' => $this->sourceId,
            'unit_cost' => $this->unitCost,
        ];
    }
}
```

#### StockAdjusted

```php
<?php
// app/Modules/Inventory/Domain/Events/StockAdjusted.php

namespace App\Modules\Inventory\Domain\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class StockAdjusted
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly string $productId,
        public readonly string $locationId,
        public readonly float $previousQuantity,
        public readonly float $newQuantity,
        public readonly string $reason,          // 'count', 'damage', 'expiry', 'correction', 'other'
        public readonly string $adjustmentId,
        public readonly ?string $notes = null,
        public readonly ?string $adjustedBy = null,
    ) {}
    
    public function getDifference(): float
    {
        return $this->newQuantity - $this->previousQuantity;
    }
}
```

#### StockTransferInitiated

```php
<?php
// app/Modules/Inventory/Domain/Events/StockTransferInitiated.php

namespace App\Modules\Inventory\Domain\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class StockTransferInitiated
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly string $transferId,
        public readonly string $productId,
        public readonly string $fromLocationId,
        public readonly string $toLocationId,
        public readonly float $quantity,
        public readonly ?string $initiatedBy = null,
    ) {}
}
```

#### StockTransferCompleted

```php
<?php
// app/Modules/Inventory/Domain/Events/StockTransferCompleted.php

namespace App\Modules\Inventory\Domain\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class StockTransferCompleted
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly string $transferId,
        public readonly float $quantityReceived,     // May differ from sent (damage, loss)
        public readonly ?float $quantityDiscrepancy = null,
        public readonly ?string $discrepancyReason = null,
        public readonly ?string $completedBy = null,
    ) {}
}
```

#### StockCountStarted

```php
<?php
// app/Modules/Inventory/Domain/Events/StockCountStarted.php

namespace App\Modules\Inventory\Domain\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class StockCountStarted
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly string $countId,
        public readonly string $locationId,
        public readonly string $countType,       // 'full', 'partial', 'cycle'
        public readonly array $productIds,       // Products to count (empty = all)
        public readonly ?string $startedBy = null,
    ) {}
}
```

#### StockCountCompleted

```php
<?php
// app/Modules/Inventory/Domain/Events/StockCountCompleted.php

namespace App\Modules\Inventory\Domain\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class StockCountCompleted
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly string $countId,
        public readonly int $productsChecked,
        public readonly int $discrepanciesFound,
        public readonly float $totalVarianceValue,
        public readonly bool $adjustmentsApplied,
        public readonly ?string $completedBy = null,
    ) {}
}
```

#### LowStockAlertTriggered

```php
<?php
// app/Modules/Inventory/Domain/Events/LowStockAlertTriggered.php

namespace App\Modules\Inventory\Domain\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class LowStockAlertTriggered
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly string $productId,
        public readonly string $locationId,
        public readonly float $currentQuantity,
        public readonly float $minimumQuantity,
        public readonly float $reorderQuantity,
    ) {}
    
    public function getShortfall(): float
    {
        return $this->minimumQuantity - $this->currentQuantity;
    }
}
```

### Step 4: Dispatch Events from Services

#### Update GeneralLedgerService

```bash
# Find the service
cat app/Modules/Accounting/Domain/Services/GeneralLedgerService.php
```

Add event dispatching:

```php
<?php
// In GeneralLedgerService

use App\Modules\Accounting\Domain\Events\JournalEntryPosted;
use App\Modules\Accounting\Domain\Events\JournalEntryReversed;

public function postEntry(JournalEntry $entry, string $sourceType = 'manual', ?string $sourceId = null): JournalEntry
{
    // ... existing posting logic inside transaction ...
    
    // After transaction commits, dispatch event
    event(new JournalEntryPosted($entry, $sourceType, $sourceId));
    
    return $entry;
}

public function reverseEntry(JournalEntry $original, string $reason): JournalEntry
{
    // ... create reversal entry inside transaction ...
    
    // After transaction commits
    event(new JournalEntryReversed($original, $reversalEntry, $reason, auth()->id()));
    
    return $reversalEntry;
}
```

#### Update FiscalPeriodService (if exists)

```php
<?php
// In FiscalPeriodService

use App\Modules\Accounting\Domain\Events\FiscalPeriodClosed;
use App\Modules\Accounting\Domain\Events\FiscalPeriodOpened;

public function closePeriod(FiscalPeriod $period): void
{
    // ... closing logic ...
    
    $closingBalances = $this->calculateClosingBalances($period);
    
    event(new FiscalPeriodClosed($period, $closingBalances, auth()->id()));
}

public function openPeriod(FiscalPeriod $period): void
{
    // ... opening logic ...
    
    event(new FiscalPeriodOpened($period, auth()->id()));
}
```

#### Update InventoryService / StockService

```bash
# Find inventory services
find app/Modules/Inventory -name "*Service*.php"
cat $(find app/Modules/Inventory -name "*Stock*Service*.php" | head -1)
```

Add event dispatching:

```php
<?php
// In StockService or InventoryService

use App\Modules\Inventory\Domain\Events\StockReceived;
use App\Modules\Inventory\Domain\Events\StockConsumed;
use App\Modules\Inventory\Domain\Events\StockAdjusted;
use App\Modules\Inventory\Domain\Events\LowStockAlertTriggered;

public function receiveStock(
    string $productId,
    string $locationId,
    float $quantity,
    string $sourceType,
    string $sourceId,
    ?float $unitCost = null
): void {
    DB::transaction(function () use ($productId, $locationId, $quantity, $unitCost) {
        // ... update stock level ...
    });
    
    event(new StockReceived(
        productId: $productId,
        locationId: $locationId,
        quantity: $quantity,
        sourceType: $sourceType,
        sourceId: $sourceId,
        unitCost: $unitCost,
    ));
}

public function consumeStock(
    string $productId,
    string $locationId,
    float $quantity,
    string $sourceType,
    string $sourceId,
    ?float $unitCost = null
): void {
    DB::transaction(function () use ($productId, $locationId, $quantity) {
        // ... update stock level ...
        
        // Check for low stock
        $stockLevel = $this->getStockLevel($productId, $locationId);
        if ($stockLevel->quantity <= $stockLevel->minimum_quantity) {
            // Queue this for after commit
            $this->pendingLowStockAlerts[] = [
                'product_id' => $productId,
                'location_id' => $locationId,
                'current' => $stockLevel->quantity,
                'minimum' => $stockLevel->minimum_quantity,
                'reorder' => $stockLevel->reorder_quantity,
            ];
        }
    });
    
    event(new StockConsumed(
        productId: $productId,
        locationId: $locationId,
        quantity: $quantity,
        sourceType: $sourceType,
        sourceId: $sourceId,
        unitCost: $unitCost,
    ));
    
    // Dispatch low stock alerts
    foreach ($this->pendingLowStockAlerts as $alert) {
        event(new LowStockAlertTriggered(
            productId: $alert['product_id'],
            locationId: $alert['location_id'],
            currentQuantity: $alert['current'],
            minimumQuantity: $alert['minimum'],
            reorderQuantity: $alert['reorder'],
        ));
    }
    $this->pendingLowStockAlerts = [];
}

public function adjustStock(
    string $productId,
    string $locationId,
    float $newQuantity,
    string $reason,
    string $adjustmentId,
    ?string $notes = null
): void {
    $previousQuantity = $this->getStockLevel($productId, $locationId)->quantity;
    
    DB::transaction(function () use ($productId, $locationId, $newQuantity) {
        // ... update stock level ...
    });
    
    event(new StockAdjusted(
        productId: $productId,
        locationId: $locationId,
        previousQuantity: $previousQuantity,
        newQuantity: $newQuantity,
        reason: $reason,
        adjustmentId: $adjustmentId,
        notes: $notes,
        adjustedBy: auth()->id(),
    ));
}
```

### Step 5: Create Example Listeners

Create a few example listeners to show how verticals can react:

```php
<?php
// app/Modules/Inventory/Listeners/SendLowStockNotification.php

namespace App\Modules\Inventory\Listeners;

use App\Modules\Inventory\Domain\Events\LowStockAlertTriggered;
use Illuminate\Contracts\Queue\ShouldQueue;

class SendLowStockNotification implements ShouldQueue
{
    public function handle(LowStockAlertTriggered $event): void
    {
        // This would send notification to relevant users
        // Implementation depends on your notification system
        
        \Log::info('Low stock alert', [
            'product_id' => $event->productId,
            'location_id' => $event->locationId,
            'current' => $event->currentQuantity,
            'minimum' => $event->minimumQuantity,
            'shortfall' => $event->getShortfall(),
        ]);
    }
}
```

```php
<?php
// app/Modules/Inventory/Listeners/LogStockMovement.php

namespace App\Modules\Inventory\Listeners;

use App\Modules\Inventory\Domain\Events\StockReceived;
use App\Modules\Inventory\Domain\Events\StockConsumed;
use Illuminate\Events\Dispatcher;

class LogStockMovement
{
    public function handleReceived(StockReceived $event): void
    {
        \Log::channel('inventory')->info('Stock received', $event->getSummary());
    }
    
    public function handleConsumed(StockConsumed $event): void
    {
        \Log::channel('inventory')->info('Stock consumed', $event->getSummary());
    }
    
    public function subscribe(Dispatcher $events): array
    {
        return [
            StockReceived::class => 'handleReceived',
            StockConsumed::class => 'handleConsumed',
        ];
    }
}
```

### Step 6: Register Event-Listener Mappings

```php
<?php
// app/Providers/EventServiceProvider.php

use App\Modules\Accounting\Domain\Events\JournalEntryPosted;
use App\Modules\Inventory\Domain\Events\LowStockAlertTriggered;
use App\Modules\Inventory\Domain\Events\StockConsumed;
use App\Modules\Inventory\Domain\Events\StockReceived;
use App\Modules\Inventory\Listeners\LogStockMovement;
use App\Modules\Inventory\Listeners\SendLowStockNotification;

protected $listen = [
    // Accounting events
    JournalEntryPosted::class => [
        // Add listeners here as needed
    ],
    
    // Inventory events
    LowStockAlertTriggered::class => [
        SendLowStockNotification::class,
    ],
];

protected $subscribe = [
    LogStockMovement::class,
];
```

### Step 7: Add Tests

```php
<?php
// tests/Feature/Accounting/AccountingEventsTest.php

namespace Tests\Feature\Accounting;

use App\Modules\Accounting\Domain\Events\JournalEntryPosted;
use App\Modules\Accounting\Domain\JournalEntry;
use App\Modules\Accounting\Domain\Services\GeneralLedgerService;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class AccountingEventsTest extends TestCase
{
    public function test_journal_entry_posted_event_is_dispatched(): void
    {
        Event::fake([JournalEntryPosted::class]);
        
        $company = $this->createCompanyWithChartOfAccounts();
        $entry = JournalEntry::factory()->for($company)->withBalancedLines()->create();
        
        $service = app(GeneralLedgerService::class);
        $service->postEntry($entry, 'test', 'test-123');
        
        Event::assertDispatched(JournalEntryPosted::class, function ($event) use ($entry) {
            return $event->journalEntry->id === $entry->id
                && $event->sourceType === 'test'
                && $event->sourceId === 'test-123';
        });
    }
    
    public function test_journal_entry_posted_event_contains_correct_data(): void
    {
        $company = $this->createCompanyWithChartOfAccounts();
        $entry = JournalEntry::factory()->for($company)->withBalancedLines()->create();
        
        $capturedEvent = null;
        Event::listen(JournalEntryPosted::class, function ($event) use (&$capturedEvent) {
            $capturedEvent = $event;
        });
        
        $service = app(GeneralLedgerService::class);
        $service->postEntry($entry, 'invoice', 'inv-456');
        
        $this->assertNotNull($capturedEvent);
        $summary = $capturedEvent->getSummary();
        
        $this->assertEquals($entry->id, $summary['journal_entry_id']);
        $this->assertEquals($company->id, $summary['company_id']);
        $this->assertEquals('invoice', $summary['source_type']);
        $this->assertEquals('inv-456', $summary['source_id']);
    }
}
```

```php
<?php
// tests/Feature/Inventory/InventoryEventsTest.php

namespace Tests\Feature\Inventory;

use App\Modules\Inventory\Domain\Events\StockConsumed;
use App\Modules\Inventory\Domain\Events\StockReceived;
use App\Modules\Inventory\Domain\Events\LowStockAlertTriggered;
use App\Modules\Inventory\Domain\Services\StockService;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class InventoryEventsTest extends TestCase
{
    public function test_stock_received_event_is_dispatched(): void
    {
        Event::fake([StockReceived::class]);
        
        $product = $this->createProduct();
        $location = $this->createLocation();
        
        $service = app(StockService::class);
        $service->receiveStock(
            productId: $product->id,
            locationId: $location->id,
            quantity: 100,
            sourceType: 'goods_receipt',
            sourceId: 'gr-123',
            unitCost: 10.50,
        );
        
        Event::assertDispatched(StockReceived::class, function ($event) use ($product) {
            return $event->productId === $product->id
                && $event->quantity === 100.0
                && $event->sourceType === 'goods_receipt';
        });
    }
    
    public function test_stock_consumed_event_is_dispatched(): void
    {
        Event::fake([StockConsumed::class, LowStockAlertTriggered::class]);
        
        $product = $this->createProduct();
        $location = $this->createLocation();
        
        // First add stock
        $this->addStock($product, $location, 100);
        
        $service = app(StockService::class);
        $service->consumeStock(
            productId: $product->id,
            locationId: $location->id,
            quantity: 25,
            sourceType: 'delivery_note',
            sourceId: 'dn-456',
        );
        
        Event::assertDispatched(StockConsumed::class, function ($event) {
            return $event->quantity === 25.0
                && $event->sourceType === 'delivery_note';
        });
    }
    
    public function test_low_stock_alert_triggered_when_below_minimum(): void
    {
        Event::fake([StockConsumed::class, LowStockAlertTriggered::class]);
        
        $product = $this->createProduct();
        $location = $this->createLocation();
        
        // Set up stock with minimum threshold
        $this->addStockWithThreshold($product, $location, 
            quantity: 50, 
            minimum: 20, 
            reorder: 100
        );
        
        // Consume to below minimum
        $service = app(StockService::class);
        $service->consumeStock(
            productId: $product->id,
            locationId: $location->id,
            quantity: 35, // Leaves 15, below minimum of 20
            sourceType: 'delivery_note',
            sourceId: 'dn-789',
        );
        
        Event::assertDispatched(LowStockAlertTriggered::class, function ($event) {
            return $event->currentQuantity === 15.0
                && $event->minimumQuantity === 20.0
                && $event->getShortfall() === 5.0;
        });
    }
    
    public function test_no_low_stock_alert_when_above_minimum(): void
    {
        Event::fake([StockConsumed::class, LowStockAlertTriggered::class]);
        
        $product = $this->createProduct();
        $location = $this->createLocation();
        
        $this->addStockWithThreshold($product, $location, 
            quantity: 100, 
            minimum: 20, 
            reorder: 50
        );
        
        // Consume but stay above minimum
        $service = app(StockService::class);
        $service->consumeStock(
            productId: $product->id,
            locationId: $location->id,
            quantity: 30, // Leaves 70, still above minimum
            sourceType: 'delivery_note',
            sourceId: 'dn-101',
        );
        
        Event::assertNotDispatched(LowStockAlertTriggered::class);
    }
}
```

### Step 8: Verification

```bash
# 1. Check event files were created
find app/Modules/Accounting/Domain/Events -name "*.php" | wc -l
# Expected: 5-6 files

find app/Modules/Inventory/Domain/Events -name "*.php" | wc -l
# Expected: 7-8 files

# 2. Check events are dispatched in services
grep -rn "event(new" app/Modules/Accounting/Domain/Services --include="*.php"
grep -rn "event(new" app/Modules/Inventory/Domain/Services --include="*.php"

# 3. Run event tests
php artisan test --filter=AccountingEventsTest
php artisan test --filter=InventoryEventsTest

# 4. Verify events in action (manual test)
php artisan tinker --execute="
    Event::listen(\App\Modules\Accounting\Domain\Events\JournalEntryPosted::class, function(\$e) {
        dump('Event fired!', \$e->getSummary());
    });
    
    // Post an entry and see if event fires
    \$company = \App\Modules\Company\Domain\Company::first();
    \$entry = \App\Modules\Accounting\Domain\JournalEntry::factory()->for(\$company)->create();
    app(\App\Modules\Accounting\Domain\Services\GeneralLedgerService::class)->postEntry(\$entry, 'test', 'test-id');
"
```

---

## Deliverables

### Accounting Module Events:
1. `JournalEntryPosted.php`
2. `JournalEntryReversed.php`
3. `FiscalPeriodOpened.php`
4. `FiscalPeriodClosed.php`
5. `AccountCreated.php`
6. `AccountDeactivated.php`

### Inventory Module Events:
1. `StockReceived.php`
2. `StockConsumed.php`
3. `StockAdjusted.php`
4. `StockTransferInitiated.php`
5. `StockTransferCompleted.php`
6. `StockCountStarted.php`
7. `StockCountCompleted.php`
8. `LowStockAlertTriggered.php`

### Updated Services:
- GeneralLedgerService with event dispatching
- StockService/InventoryService with event dispatching
- FiscalPeriodService with event dispatching (if exists)

### Tests:
- AccountingEventsTest.php
- InventoryEventsTest.php

### Documentation:
- List of events with their purposes
- Example listener implementations

---

## Success Criteria

- [ ] All event classes created in Domain/Events folders
- [ ] Events dispatched from appropriate service methods
- [ ] Events dispatched AFTER transaction commits (not inside transaction)
- [ ] Events contain all necessary data for listeners
- [ ] Example listeners demonstrate usage pattern
- [ ] All tests pass
- [ ] Events follow consistent naming convention
