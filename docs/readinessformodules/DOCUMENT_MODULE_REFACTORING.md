# Document Module Refactoring

**Context:** The Document module has three oversized files that need to be split for maintainability before we add more document types via Otospex/IziPOS modules.

**Risk Level:** Medium-High — These files are central to the application. Proceed carefully with tests.

---

## Files to Refactor

| File | Current Lines | Problem | Target |
|------|---------------|---------|--------|
| DocumentController.php | 1,131 | God controller, 9 dependencies | 5-6 focused controllers |
| DocumentConversionService.php | 1,032 | All conversions in one file | Strategy pattern with converter classes |
| DocumentDetailPage.tsx | 1,368 | 15+ useState, all types in one | Type-specific components |

---

## Guiding Principles

1. **No behavior changes** — This is pure refactoring. All existing functionality must work identically.
2. **Tests must pass** — Run tests after each major step. Fix any failures before proceeding.
3. **Extract, don't rewrite** — Move code to new files, don't rewrite logic.
4. **Shared code → Traits/Base classes** — Common behavior stays DRY.
5. **One file at a time** — Complete each file's refactor before starting the next.

---

## Part 1: DocumentController Refactoring

### 1.1 Current State Analysis

First, analyze what the controller currently does:

```bash
# Find all public methods
grep -n "public function" app/Modules/Document/Presentation/Controllers/DocumentController.php

# Find all injected dependencies
grep -n "private.*Service\|private.*Repository" app/Modules/Document/Presentation/Controllers/DocumentController.php

# Find route registrations
grep -rn "DocumentController" app/Modules/Document/Presentation/routes.php
```

Document the findings before proceeding.

### 1.2 Target Architecture

```
app/Modules/Document/Presentation/Controllers/
├── DocumentController.php          # REMOVE after refactor (or keep as facade)
├── Concerns/
│   └── HandlesDocuments.php        # Shared trait for common methods
├── QuoteController.php             # ~150-200 lines
├── SalesOrderController.php        # ~200-250 lines  
├── InvoiceController.php           # ~200-250 lines
├── DeliveryNoteController.php      # ~150-200 lines
├── CreditNoteController.php        # ~150-200 lines
├── PurchaseOrderController.php     # ~150-200 lines
└── ReturnNoteController.php        # ~100-150 lines (if exists)
```

### 1.3 Step 1: Create Shared Trait

Extract common functionality that ALL document controllers need:

**File:** `app/Modules/Document/Presentation/Controllers/Concerns/HandlesDocuments.php`

```php
<?php

namespace App\Modules\Document\Presentation\Controllers\Concerns;

use App\Modules\Document\Application\DTOs\DocumentData;
use App\Modules\Document\Domain\Document;
use Illuminate\Http\Request;

trait HandlesDocuments
{
    /**
     * Get base query scoped to current company
     */
    protected function baseQuery(Request $request)
    {
        return Document::query()
            ->where('company_id', $request->user()->current_company_id);
    }

    /**
     * Format single document response
     */
    protected function documentResponse(Document $document, int $status = 200)
    {
        return response()->json([
            'data' => DocumentData::fromModel($document->fresh()->load($this->defaultRelations())),
        ], $status);
    }

    /**
     * Default relations to load
     */
    protected function defaultRelations(): array
    {
        return ['lines', 'customer', 'vehicleContext'];
    }

    /**
     * Apply common filters to query
     */
    protected function applyFilters($query, Request $request)
    {
        return $query
            ->when($request->filled('customer_id'), fn($q) => 
                $q->where('customer_id', $request->input('customer_id'))
            )
            ->when($request->filled('status'), fn($q) => 
                $q->where('status', $request->input('status'))
            )
            ->when($request->filled('date_from'), fn($q) => 
                $q->whereDate('document_date', '>=', $request->input('date_from'))
            )
            ->when($request->filled('date_to'), fn($q) => 
                $q->whereDate('document_date', '<=', $request->input('date_to'))
            )
            ->when($request->filled('search'), fn($q) => 
                $q->where(function($q) use ($request) {
                    $q->where('document_number', 'ilike', '%' . $request->input('search') . '%')
                      ->orWhere('reference', 'ilike', '%' . $request->input('search') . '%');
                })
            );
    }

    /**
     * Validate and prepare line items from request
     */
    protected function prepareLineItems(array $lines): array
    {
        // Common line item preparation logic
        // Extract from current DocumentController
        return $lines;
    }

    /**
     * Handle vehicle context attachment
     */
    protected function attachVehicleContext(Document $document, ?array $vehicleContext): void
    {
        if (!$vehicleContext) {
            $document->vehicleContext()?->delete();
            return;
        }

        // Use the new context pattern from vehicle decoupling
        \App\Modules\Document\Domain\DocumentVehicleContext::updateOrCreate(
            ['document_id' => $document->id],
            [
                'vehicle_id' => $vehicleContext['vehicle_id'] ?? null,
                'vehicle_snapshot' => $vehicleContext['snapshot'] ?? null,
                'mileage_at_service' => $vehicleContext['mileage'] ?? null,
                'context_data' => $vehicleContext['additional_data'] ?? null,
            ]
        );
    }
}
```

### 1.4 Step 2: Create Type-Specific Controllers

For each document type, create a focused controller. Example for Quotes:

**File:** `app/Modules/Document/Presentation/Controllers/QuoteController.php`

```php
<?php

namespace App\Modules\Document\Presentation\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Document\Application\DTOs\DocumentData;
use App\Modules\Document\Application\Services\QuoteService;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Document\Presentation\Controllers\Concerns\HandlesDocuments;
use App\Modules\Document\Presentation\Requests\CreateQuoteRequest;
use App\Modules\Document\Presentation\Requests\UpdateQuoteRequest;
use App\Support\Traits\PaginatesResults;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class QuoteController extends Controller
{
    use HandlesDocuments, PaginatesResults;

    public function __construct(
        private readonly QuoteService $quoteService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $params = $this->getPaginationParams($request);

        $query = $this->baseQuery($request)
            ->where('document_type', DocumentType::QUOTE)
            ->with($this->defaultRelations());

        $query = $this->applyFilters($query, $request);

        $paginator = $query
            ->orderByDesc('document_date')
            ->orderByDesc('id')
            ->cursorPaginate($params['per_page']);

        return response()->json(
            $this->formatPaginatedResponse($paginator, DocumentData::class)
        );
    }

    public function store(CreateQuoteRequest $request): JsonResponse
    {
        $quote = $this->quoteService->create(
            companyId: $request->user()->current_company_id,
            customerId: $request->validated('customer_id'),
            lines: $request->validated('lines'),
            documentDate: $request->validated('document_date'),
            reference: $request->validated('reference'),
            notes: $request->validated('notes'),
            validUntil: $request->validated('valid_until'),
        );

        if ($request->has('vehicle_context')) {
            $this->attachVehicleContext($quote, $request->validated('vehicle_context'));
        }

        return $this->documentResponse($quote, 201);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $quote = $this->baseQuery($request)
            ->where('document_type', DocumentType::QUOTE)
            ->with($this->defaultRelations())
            ->findOrFail($id);

        return $this->documentResponse($quote);
    }

    public function update(UpdateQuoteRequest $request, int $id): JsonResponse
    {
        $quote = $this->baseQuery($request)
            ->where('document_type', DocumentType::QUOTE)
            ->findOrFail($id);

        // Only allow updates if quote is still draft
        if ($quote->status !== 'draft') {
            return response()->json([
                'error' => 'Cannot update a quote that is no longer in draft status',
            ], 422);
        }

        $quote = $this->quoteService->update(
            quote: $quote,
            data: $request->validated(),
        );

        if ($request->has('vehicle_context')) {
            $this->attachVehicleContext($quote, $request->validated('vehicle_context'));
        }

        return $this->documentResponse($quote);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $quote = $this->baseQuery($request)
            ->where('document_type', DocumentType::QUOTE)
            ->findOrFail($id);

        if ($quote->status !== 'draft') {
            return response()->json([
                'error' => 'Cannot delete a quote that is no longer in draft status',
            ], 422);
        }

        $quote->delete();

        return response()->json(null, 204);
    }

    public function convertToOrder(Request $request, int $id): JsonResponse
    {
        $quote = $this->baseQuery($request)
            ->where('document_type', DocumentType::QUOTE)
            ->findOrFail($id);

        $salesOrder = $this->quoteService->convertToSalesOrder($quote);

        return $this->documentResponse($salesOrder, 201);
    }

    public function duplicate(Request $request, int $id): JsonResponse
    {
        $quote = $this->baseQuery($request)
            ->where('document_type', DocumentType::QUOTE)
            ->findOrFail($id);

        $newQuote = $this->quoteService->duplicate($quote);

        return $this->documentResponse($newQuote, 201);
    }
}
```

### 1.5 Step 3: Create Remaining Controllers

Follow the same pattern for:

| Controller | Document Type | Special Methods |
|------------|---------------|-----------------|
| `SalesOrderController` | SALES_ORDER | `convertToInvoice()`, `convertToDeliveryNote()`, `cancel()` |
| `InvoiceController` | INVOICE | `post()`, `createCreditNote()`, `sendToCustomer()` |
| `DeliveryNoteController` | DELIVERY_NOTE | `confirm()`, `createFromOrder()` |
| `CreditNoteController` | CREDIT_NOTE | `post()` |
| `PurchaseOrderController` | PURCHASE_ORDER | `receive()`, `convertToGoodsReceipt()` |

### 1.6 Step 4: Update Routes

**File:** `app/Modules/Document/Presentation/routes.php`

```php
<?php

use App\Modules\Document\Presentation\Controllers\QuoteController;
use App\Modules\Document\Presentation\Controllers\SalesOrderController;
use App\Modules\Document\Presentation\Controllers\InvoiceController;
use App\Modules\Document\Presentation\Controllers\DeliveryNoteController;
use App\Modules\Document\Presentation\Controllers\CreditNoteController;
use App\Modules\Document\Presentation\Controllers\PurchaseOrderController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum'])->group(function () {
    
    // Quotes
    Route::prefix('quotes')->group(function () {
        Route::get('/', [QuoteController::class, 'index']);
        Route::post('/', [QuoteController::class, 'store']);
        Route::get('/{id}', [QuoteController::class, 'show']);
        Route::put('/{id}', [QuoteController::class, 'update']);
        Route::delete('/{id}', [QuoteController::class, 'destroy']);
        Route::post('/{id}/convert-to-order', [QuoteController::class, 'convertToOrder']);
        Route::post('/{id}/duplicate', [QuoteController::class, 'duplicate']);
    });

    // Sales Orders
    Route::prefix('sales-orders')->group(function () {
        Route::get('/', [SalesOrderController::class, 'index']);
        Route::post('/', [SalesOrderController::class, 'store']);
        Route::get('/{id}', [SalesOrderController::class, 'show']);
        Route::put('/{id}', [SalesOrderController::class, 'update']);
        Route::delete('/{id}', [SalesOrderController::class, 'destroy']);
        Route::post('/{id}/convert-to-invoice', [SalesOrderController::class, 'convertToInvoice']);
        Route::post('/{id}/convert-to-delivery-note', [SalesOrderController::class, 'convertToDeliveryNote']);
        Route::post('/{id}/cancel', [SalesOrderController::class, 'cancel']);
    });

    // Invoices
    Route::prefix('invoices')->group(function () {
        Route::get('/', [InvoiceController::class, 'index']);
        Route::post('/', [InvoiceController::class, 'store']);
        Route::get('/{id}', [InvoiceController::class, 'show']);
        Route::put('/{id}', [InvoiceController::class, 'update']);
        Route::post('/{id}/post', [InvoiceController::class, 'post']);
        Route::post('/{id}/credit-note', [InvoiceController::class, 'createCreditNote']);
        Route::post('/{id}/send', [InvoiceController::class, 'sendToCustomer']);
    });

    // Delivery Notes
    Route::prefix('delivery-notes')->group(function () {
        Route::get('/', [DeliveryNoteController::class, 'index']);
        Route::post('/', [DeliveryNoteController::class, 'store']);
        Route::get('/{id}', [DeliveryNoteController::class, 'show']);
        Route::post('/{id}/confirm', [DeliveryNoteController::class, 'confirm']);
        Route::post('/consolidate-to-invoice', [DeliveryNoteController::class, 'consolidateToInvoice']);
    });

    // Credit Notes
    Route::prefix('credit-notes')->group(function () {
        Route::get('/', [CreditNoteController::class, 'index']);
        Route::post('/', [CreditNoteController::class, 'store']);
        Route::get('/{id}', [CreditNoteController::class, 'show']);
        Route::post('/{id}/post', [CreditNoteController::class, 'post']);
    });

    // Purchase Orders
    Route::prefix('purchase-orders')->group(function () {
        Route::get('/', [PurchaseOrderController::class, 'index']);
        Route::post('/', [PurchaseOrderController::class, 'store']);
        Route::get('/{id}', [PurchaseOrderController::class, 'show']);
        Route::put('/{id}', [PurchaseOrderController::class, 'update']);
        Route::delete('/{id}', [PurchaseOrderController::class, 'destroy']);
        Route::post('/{id}/receive', [PurchaseOrderController::class, 'receive']);
    });

    // Keep generic /documents endpoint for backward compatibility or cross-type queries
    Route::get('/documents', [DocumentController::class, 'index']);
    Route::get('/documents/{id}', [DocumentController::class, 'show']);
});
```

### 1.7 Step 5: Update Tests

For each test file that references `DocumentController`:

1. Find which document type the test is actually testing
2. Update to use the appropriate controller/route
3. Run the test to verify it still passes

```bash
# Find all tests using DocumentController
grep -rn "DocumentController\|/api/documents" tests/

# Run document tests after each controller is extracted
php artisan test --filter=Quote
php artisan test --filter=SalesOrder
php artisan test --filter=Invoice
# etc.
```

### 1.8 Step 6: Remove Old Controller

Once all tests pass with the new controllers:

1. Verify no code references `DocumentController` for type-specific operations
2. Keep `DocumentController` only if needed for cross-type queries
3. Or remove entirely and update any remaining references

---

## Part 2: DocumentConversionService Refactoring

### 2.1 Target Architecture: Strategy Pattern

```
app/Modules/Document/Domain/Services/
├── DocumentConversionService.php    # REMOVE after refactor
├── Conversion/
│   ├── DocumentConverterInterface.php
│   ├── DocumentConverterRegistry.php
│   ├── Converters/
│   │   ├── QuoteToSalesOrderConverter.php
│   │   ├── SalesOrderToInvoiceConverter.php
│   │   ├── SalesOrderToDeliveryNoteConverter.php
│   │   ├── DeliveryNoteToInvoiceConverter.php
│   │   ├── InvoiceToCreditNoteConverter.php
│   │   └── PurchaseOrderToGoodsReceiptConverter.php
│   └── Concerns/
│       └── CopiesDocumentData.php   # Shared conversion logic
```

### 2.2 Step 1: Create Interface

**File:** `app/Modules/Document/Domain/Services/Conversion/DocumentConverterInterface.php`

```php
<?php

namespace App\Modules\Document\Domain\Services\Conversion;

use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\Enums\DocumentType;

interface DocumentConverterInterface
{
    /**
     * Source document type this converter handles
     */
    public function sourceType(): DocumentType;

    /**
     * Target document type this converter produces
     */
    public function targetType(): DocumentType;

    /**
     * Convert source document to target type
     */
    public function convert(Document $source, array $options = []): Document;

    /**
     * Check if conversion is allowed
     */
    public function canConvert(Document $source): bool;

    /**
     * Get validation errors if conversion not allowed
     */
    public function getConversionErrors(Document $source): array;
}
```

### 2.3 Step 2: Create Shared Trait

**File:** `app/Modules/Document/Domain/Services/Conversion/Concerns/CopiesDocumentData.php`

```php
<?php

namespace App\Modules\Document\Domain\Services\Conversion\Concerns;

use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\DocumentLine;
use App\Modules\Document\Domain\DocumentVehicleContext;
use App\Modules\Document\Domain\Enums\DocumentType;

trait CopiesDocumentData
{
    protected function createTargetDocument(
        Document $source,
        DocumentType $targetType,
        array $overrides = []
    ): Document {
        $data = [
            'company_id' => $source->company_id,
            'customer_id' => $source->customer_id,
            'document_type' => $targetType,
            'document_date' => now(),
            'currency_code' => $source->currency_code,
            'exchange_rate' => $source->exchange_rate,
            'notes' => $source->notes,
            'reference' => $source->reference,
            'source_document_id' => $source->id,
            'source_document_type' => $source->document_type,
            'status' => 'draft',
            ...$overrides,
        ];

        return Document::create($data);
    }

    protected function copyLines(Document $source, Document $target, array $lineFilter = null): void
    {
        $lines = $source->lines;

        if ($lineFilter !== null) {
            $lines = $lines->whereIn('id', $lineFilter);
        }

        foreach ($lines as $line) {
            $this->copyLine($line, $target);
        }

        $target->recalculateTotals();
    }

    protected function copyLine(DocumentLine $line, Document $target): DocumentLine
    {
        return DocumentLine::create([
            'document_id' => $target->id,
            'product_id' => $line->product_id,
            'description' => $line->description,
            'quantity' => $line->quantity,
            'unit_price' => $line->unit_price,
            'discount_percent' => $line->discount_percent,
            'discount_amount' => $line->discount_amount,
            'tax_rate' => $line->tax_rate,
            'tax_amount' => $line->tax_amount,
            'line_total' => $line->line_total,
            'sort_order' => $line->sort_order,
            'source_line_id' => $line->id,
        ]);
    }

    protected function copyVehicleContext(Document $source, Document $target): void
    {
        if (!$source->vehicleContext) {
            return;
        }

        DocumentVehicleContext::create([
            'document_id' => $target->id,
            'vehicle_id' => $source->vehicleContext->vehicle_id,
            'vehicle_snapshot' => $source->vehicleContext->vehicle_snapshot,
            'mileage_at_service' => $source->vehicleContext->mileage_at_service,
            'context_data' => $source->vehicleContext->context_data,
        ]);
    }

    protected function linkDocuments(Document $source, Document $target): void
    {
        // Create bidirectional link if needed
        $source->update(['converted_to_id' => $target->id]);
    }
}
```

### 2.4 Step 3: Create Individual Converters

**File:** `app/Modules/Document/Domain/Services/Conversion/Converters/QuoteToSalesOrderConverter.php`

```php
<?php

namespace App\Modules\Document\Domain\Services\Conversion\Converters;

use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Document\Domain\Events\QuoteConvertedToOrder;
use App\Modules\Document\Domain\Services\Conversion\Concerns\CopiesDocumentData;
use App\Modules\Document\Domain\Services\Conversion\DocumentConverterInterface;
use Illuminate\Support\Facades\DB;

class QuoteToSalesOrderConverter implements DocumentConverterInterface
{
    use CopiesDocumentData;

    public function sourceType(): DocumentType
    {
        return DocumentType::QUOTE;
    }

    public function targetType(): DocumentType
    {
        return DocumentType::SALES_ORDER;
    }

    public function canConvert(Document $source): bool
    {
        return empty($this->getConversionErrors($source));
    }

    public function getConversionErrors(Document $source): array
    {
        $errors = [];

        if ($source->document_type !== DocumentType::QUOTE) {
            $errors[] = 'Source document must be a quote';
        }

        if ($source->status === DocumentStatus::CANCELLED) {
            $errors[] = 'Cannot convert a cancelled quote';
        }

        if ($source->status === DocumentStatus::CONVERTED) {
            $errors[] = 'Quote has already been converted';
        }

        if ($source->lines->isEmpty()) {
            $errors[] = 'Quote has no line items';
        }

        return $errors;
    }

    public function convert(Document $source, array $options = []): Document
    {
        $errors = $this->getConversionErrors($source);
        if (!empty($errors)) {
            throw new \DomainException(implode(', ', $errors));
        }

        return DB::transaction(function () use ($source, $options) {
            // Create sales order
            $salesOrder = $this->createTargetDocument($source, DocumentType::SALES_ORDER, [
                'valid_until' => null, // Sales orders don't expire like quotes
            ]);

            // Copy lines
            $this->copyLines($source, $salesOrder);

            // Copy vehicle context
            $this->copyVehicleContext($source, $salesOrder);

            // Link documents
            $this->linkDocuments($source, $salesOrder);

            // Update source status
            $source->update(['status' => DocumentStatus::CONVERTED]);

            // Dispatch event
            event(new QuoteConvertedToOrder($source, $salesOrder));

            return $salesOrder;
        });
    }
}
```

**Create similar converters for:**
- `SalesOrderToInvoiceConverter`
- `SalesOrderToDeliveryNoteConverter`
- `DeliveryNoteToInvoiceConverter`
- `InvoiceToCreditNoteConverter`
- `PurchaseOrderToGoodsReceiptConverter`

### 2.5 Step 4: Create Registry

**File:** `app/Modules/Document/Domain/Services/Conversion/DocumentConverterRegistry.php`

```php
<?php

namespace App\Modules\Document\Domain\Services\Conversion;

use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\Enums\DocumentType;

class DocumentConverterRegistry
{
    /** @var DocumentConverterInterface[] */
    private array $converters = [];

    public function register(DocumentConverterInterface $converter): void
    {
        $key = $this->makeKey($converter->sourceType(), $converter->targetType());
        $this->converters[$key] = $converter;
    }

    public function getConverter(DocumentType $from, DocumentType $to): ?DocumentConverterInterface
    {
        $key = $this->makeKey($from, $to);
        return $this->converters[$key] ?? null;
    }

    public function convert(Document $source, DocumentType $targetType, array $options = []): Document
    {
        $converter = $this->getConverter($source->document_type, $targetType);

        if (!$converter) {
            throw new \InvalidArgumentException(
                "No converter registered for {$source->document_type->value} to {$targetType->value}"
            );
        }

        return $converter->convert($source, $options);
    }

    public function canConvert(Document $source, DocumentType $targetType): bool
    {
        $converter = $this->getConverter($source->document_type, $targetType);
        return $converter?->canConvert($source) ?? false;
    }

    public function getAvailableConversions(DocumentType $from): array
    {
        $available = [];
        foreach ($this->converters as $converter) {
            if ($converter->sourceType() === $from) {
                $available[] = $converter->targetType();
            }
        }
        return $available;
    }

    private function makeKey(DocumentType $from, DocumentType $to): string
    {
        return "{$from->value}:{$to->value}";
    }
}
```

### 2.6 Step 5: Register in Service Provider

**File:** `app/Modules/Document/DocumentServiceProvider.php` (add to existing)

```php
use App\Modules\Document\Domain\Services\Conversion\DocumentConverterRegistry;
use App\Modules\Document\Domain\Services\Conversion\Converters\QuoteToSalesOrderConverter;
use App\Modules\Document\Domain\Services\Conversion\Converters\SalesOrderToInvoiceConverter;
// ... other converters

public function register(): void
{
    $this->app->singleton(DocumentConverterRegistry::class, function ($app) {
        $registry = new DocumentConverterRegistry();
        
        // Register all converters
        $registry->register($app->make(QuoteToSalesOrderConverter::class));
        $registry->register($app->make(SalesOrderToInvoiceConverter::class));
        $registry->register($app->make(SalesOrderToDeliveryNoteConverter::class));
        $registry->register($app->make(DeliveryNoteToInvoiceConverter::class));
        $registry->register($app->make(InvoiceToCreditNoteConverter::class));
        $registry->register($app->make(PurchaseOrderToGoodsReceiptConverter::class));
        
        return $registry;
    });
}
```

### 2.7 Step 6: Update Controllers to Use Registry

```php
// In QuoteController
public function __construct(
    private readonly DocumentConverterRegistry $converterRegistry,
) {}

public function convertToOrder(Request $request, int $id): JsonResponse
{
    $quote = $this->baseQuery($request)
        ->where('document_type', DocumentType::QUOTE)
        ->findOrFail($id);

    $salesOrder = $this->converterRegistry->convert($quote, DocumentType::SALES_ORDER);

    return $this->documentResponse($salesOrder, 201);
}
```

---

## Part 3: DocumentDetailPage.tsx Refactoring

### 3.1 Target Architecture

```
apps/web/src/features/documents/
├── DocumentDetailPage.tsx          # REMOVE after refactor (or keep as router)
├── components/
│   ├── DocumentHeader.tsx          # Shared header component
│   ├── DocumentLines.tsx           # Shared lines table
│   ├── DocumentTotals.tsx          # Shared totals display
│   ├── DocumentActions.tsx         # Base actions component
│   └── DocumentTimeline.tsx        # Shared history/audit component
├── quotes/
│   ├── QuoteDetailPage.tsx
│   ├── QuoteActions.tsx
│   └── QuoteForm.tsx
├── sales-orders/
│   ├── SalesOrderDetailPage.tsx
│   ├── SalesOrderActions.tsx
│   └── SalesOrderForm.tsx
├── invoices/
│   ├── InvoiceDetailPage.tsx
│   ├── InvoiceActions.tsx
│   └── InvoiceForm.tsx
├── delivery-notes/
│   ├── DeliveryNoteDetailPage.tsx
│   ├── DeliveryNoteActions.tsx
│   └── DeliveryNoteForm.tsx
└── credit-notes/
    ├── CreditNoteDetailPage.tsx
    ├── CreditNoteActions.tsx
    └── CreditNoteForm.tsx
```

### 3.2 Step 1: Extract Shared Components

**File:** `apps/web/src/features/documents/components/DocumentHeader.tsx`

```typescript
import React from 'react';
import { Badge } from '@/components/ui/Badge';
import { formatDate } from '@/lib/utils';

interface DocumentHeaderProps {
  documentNumber: string;
  documentType: string;
  status: string;
  date: string;
  customerName?: string;
  reference?: string;
}

export function DocumentHeader({
  documentNumber,
  documentType,
  status,
  date,
  customerName,
  reference,
}: DocumentHeaderProps) {
  return (
    <div className="border-b pb-4">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-bold">
            {documentType} {documentNumber}
          </h1>
          {reference && (
            <p className="text-sm text-gray-500">Ref: {reference}</p>
          )}
        </div>
        <Badge variant={getStatusVariant(status)}>{status}</Badge>
      </div>
      <div className="mt-2 flex gap-4 text-sm text-gray-600">
        <span>Date: {formatDate(date)}</span>
        {customerName && <span>Customer: {customerName}</span>}
      </div>
    </div>
  );
}

function getStatusVariant(status: string) {
  const variants: Record<string, 'default' | 'success' | 'warning' | 'destructive'> = {
    draft: 'default',
    confirmed: 'success',
    posted: 'success',
    cancelled: 'destructive',
    partial: 'warning',
  };
  return variants[status] || 'default';
}
```

**File:** `apps/web/src/features/documents/components/DocumentLines.tsx`

```typescript
import React from 'react';
import { Table, TableHead, TableBody, TableRow, TableCell } from '@/components/ui/Table';
import { formatCurrency } from '@/lib/utils';

interface DocumentLine {
  id: number;
  description: string;
  quantity: number;
  unit_price: number;
  discount_percent?: number;
  tax_rate: number;
  line_total: number;
}

interface DocumentLinesProps {
  lines: DocumentLine[];
  currency: string;
  showTax?: boolean;
}

export function DocumentLines({ lines, currency, showTax = true }: DocumentLinesProps) {
  return (
    <Table>
      <TableHead>
        <TableRow>
          <TableCell>Description</TableCell>
          <TableCell className="text-right">Qty</TableCell>
          <TableCell className="text-right">Unit Price</TableCell>
          {showTax && <TableCell className="text-right">Tax %</TableCell>}
          <TableCell className="text-right">Total</TableCell>
        </TableRow>
      </TableHead>
      <TableBody>
        {lines.map((line) => (
          <TableRow key={line.id}>
            <TableCell>{line.description}</TableCell>
            <TableCell className="text-right">{line.quantity}</TableCell>
            <TableCell className="text-right">
              {formatCurrency(line.unit_price, currency)}
            </TableCell>
            {showTax && <TableCell className="text-right">{line.tax_rate}%</TableCell>}
            <TableCell className="text-right">
              {formatCurrency(line.line_total, currency)}
            </TableCell>
          </TableRow>
        ))}
      </TableBody>
    </Table>
  );
}
```

### 3.3 Step 2: Create Type-Specific Detail Pages

**File:** `apps/web/src/features/documents/quotes/QuoteDetailPage.tsx`

```typescript
import React from 'react';
import { useParams, useNavigate } from 'react-router-dom';
import { useQuote, useConvertQuoteToOrder } from '@/api/quotes';
import { DocumentHeader } from '../components/DocumentHeader';
import { DocumentLines } from '../components/DocumentLines';
import { DocumentTotals } from '../components/DocumentTotals';
import { QuoteActions } from './QuoteActions';
import { Skeleton } from '@/components/ui/Skeleton';
import { Alert } from '@/components/ui/Alert';

export function QuoteDetailPage() {
  const { id } = useParams<{ id: string }>();
  const navigate = useNavigate();
  const { data: quote, isLoading, error } = useQuote(Number(id));
  const convertToOrder = useConvertQuoteToOrder();

  if (isLoading) {
    return <QuoteDetailSkeleton />;
  }

  if (error || !quote) {
    return <Alert variant="destructive">Failed to load quote</Alert>;
  }

  const handleConvertToOrder = async () => {
    const result = await convertToOrder.mutateAsync(quote.id);
    navigate(`/sales-orders/${result.data.id}`);
  };

  const handleDuplicate = () => {
    // Handle duplicate logic
  };

  const handleEdit = () => {
    navigate(`/quotes/${quote.id}/edit`);
  };

  return (
    <div className="space-y-6">
      <DocumentHeader
        documentNumber={quote.document_number}
        documentType="Quote"
        status={quote.status}
        date={quote.document_date}
        customerName={quote.customer?.name}
        reference={quote.reference}
      />

      {quote.valid_until && (
        <div className="text-sm">
          Valid until: {formatDate(quote.valid_until)}
        </div>
      )}

      <DocumentLines
        lines={quote.lines}
        currency={quote.currency_code}
      />

      <DocumentTotals
        subtotal={quote.subtotal}
        taxTotal={quote.tax_total}
        total={quote.total}
        currency={quote.currency_code}
      />

      <QuoteActions
        quote={quote}
        onConvertToOrder={handleConvertToOrder}
        onDuplicate={handleDuplicate}
        onEdit={handleEdit}
        isConverting={convertToOrder.isLoading}
      />
    </div>
  );
}

function QuoteDetailSkeleton() {
  return (
    <div className="space-y-6">
      <Skeleton className="h-20 w-full" />
      <Skeleton className="h-64 w-full" />
      <Skeleton className="h-32 w-full" />
    </div>
  );
}
```

**File:** `apps/web/src/features/documents/quotes/QuoteActions.tsx`

```typescript
import React from 'react';
import { Button } from '@/components/ui/Button';
import { ArrowRight, Copy, Edit, Trash } from 'lucide-react';

interface QuoteActionsProps {
  quote: Quote;
  onConvertToOrder: () => void;
  onDuplicate: () => void;
  onEdit: () => void;
  isConverting?: boolean;
}

export function QuoteActions({
  quote,
  onConvertToOrder,
  onDuplicate,
  onEdit,
  isConverting = false,
}: QuoteActionsProps) {
  const isDraft = quote.status === 'draft';
  const canConvert = quote.status !== 'converted' && quote.status !== 'cancelled';

  return (
    <div className="flex gap-2 border-t pt-4">
      {isDraft && (
        <Button variant="outline" onClick={onEdit}>
          <Edit className="h-4 w-4 mr-2" />
          Edit
        </Button>
      )}

      {canConvert && (
        <Button onClick={onConvertToOrder} disabled={isConverting}>
          <ArrowRight className="h-4 w-4 mr-2" />
          {isConverting ? 'Converting...' : 'Convert to Order'}
        </Button>
      )}

      <Button variant="outline" onClick={onDuplicate}>
        <Copy className="h-4 w-4 mr-2" />
        Duplicate
      </Button>
    </div>
  );
}
```

### 3.4 Step 3: Update Router

**File:** `apps/web/src/routes/documents.tsx`

```typescript
import { QuoteDetailPage } from '@/features/documents/quotes/QuoteDetailPage';
import { SalesOrderDetailPage } from '@/features/documents/sales-orders/SalesOrderDetailPage';
import { InvoiceDetailPage } from '@/features/documents/invoices/InvoiceDetailPage';
import { DeliveryNoteDetailPage } from '@/features/documents/delivery-notes/DeliveryNoteDetailPage';
import { CreditNoteDetailPage } from '@/features/documents/credit-notes/CreditNoteDetailPage';

export const documentRoutes = [
  { path: '/quotes/:id', element: <QuoteDetailPage /> },
  { path: '/sales-orders/:id', element: <SalesOrderDetailPage /> },
  { path: '/invoices/:id', element: <InvoiceDetailPage /> },
  { path: '/delivery-notes/:id', element: <DeliveryNoteDetailPage /> },
  { path: '/credit-notes/:id', element: <CreditNoteDetailPage /> },
];
```

---

## Verification Checklist

After completing all refactoring:

### Backend
```bash
# All document tests pass
php artisan test --filter=Document

# All conversion tests pass
php artisan test --filter=Conversion

# PHPStan clean
./vendor/bin/phpstan analyse --level=8

# No references to old DocumentController for type-specific operations
grep -rn "DocumentController" app/ --include="*.php" | grep -v "// Deprecated"
```

### Frontend
```bash
# TypeScript compiles
npm run build

# E2E tests pass
npm run test:e2e
```

### Manual Testing
- [ ] Can create quote and view detail
- [ ] Can convert quote to sales order
- [ ] Can create sales order and convert to invoice
- [ ] Can convert sales order to delivery note
- [ ] Can confirm delivery note
- [ ] Can post invoice
- [ ] Can create credit note from invoice
- [ ] All document lists show with pagination

---

## Summary

| Before | After | Benefit |
|--------|-------|---------|
| 1 controller (1,131 lines) | 6 controllers (~200 lines each) | Single responsibility, easier testing |
| 1 conversion service (1,032 lines) | 6 converter classes + registry | Strategy pattern, extensible for new types |
| 1 React component (1,368 lines) | 5 detail pages + shared components | Maintainable, focused state management |

**Total estimated time:** 12-16 hours

**Risk mitigation:**
- Run tests after each file extraction
- Use Codex to verify after completion
- Keep git commits granular for easy rollback
