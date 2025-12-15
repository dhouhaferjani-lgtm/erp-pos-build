# Tunisia-Compliant Document Conversion - Revised Implementation Plan

> **Status:** DRAFT - Awaiting user approval before implementation
> **Created:** 2025-12-12
> **Updated:** 2025-12-12
> **Related Docs:** `docs/tasks/delivery_notes_invoicing_tunisia.md`

---

## Executive Summary

This plan addresses critical bugs discovered during testing and introduces Tunisia fiscal compliance for the document conversion workflow. The key change is that **invoices are based on delivered quantities, not ordered quantities**.

### Core Principle

> Once a delivery note exists for a sales order, the SO can no longer be directly converted to an invoice. The invoice must be created from the delivery notes.

### Three Invoicing Scenarios

| Scenario | SO Contains | Can Convert SO→Invoice? | Behavior |
|----------|-------------|-------------------------|----------|
| **A. Products Only** | Physical items | NO (if DN exists) | Must invoice via DN |
| **B. Services Only** | Services only | YES (always) | Direct SO→Invoice |
| **C. Mixed** | Products + Services | YES (auto-creates DN) | SO→Invoice creates DN for physical items |

---

## Bug Fixes Included

### Bug 1: Duplicate SO→Invoice Conversion
- **Current:** `convertOrderToInvoice()` allows multiple calls
- **Fix:** Check `fully_invoiced` flag before conversion

### Bug 2: DNs from SO Show in Consolidation After SO Invoiced
- **Current:** DNs not marked when parent SO is invoiced
- **Fix:** Mark DNs as fulfilled when SO is invoiced

### Bug 3: No Navigation Link to Consolidation Page
- **Current:** Missing button in DocumentListPage.tsx
- **Fix:** Add "Consolidate" button for delivery notes

---

## Phase 1: Product Classification

### 1.1 Add `is_physical` Flag to Products

**File:** `app/Modules/Product/Domain/Product.php`

Add property:
```php
/**
 * @property bool $is_physical Whether this is a physical product requiring inventory tracking
 */
protected $fillable = [
    // ... existing fields
    'is_physical',
];

protected $attributes = [
    'is_active' => true,
    'is_physical' => true, // Default to physical
];

protected function casts(): array
{
    return [
        // ... existing casts
        'is_physical' => 'boolean',
    ];
}

/**
 * Check if this product requires inventory tracking (physical goods).
 */
public function isPhysical(): bool
{
    return $this->is_physical;
}

/**
 * Check if this product is a service (no inventory).
 */
public function isService(): bool
{
    return $this->type === ProductType::Service || !$this->is_physical;
}

/**
 * Check if this product requires delivery note tracking.
 */
public function requiresDeliveryNote(): bool
{
    return $this->is_physical && $this->type !== ProductType::Service;
}
```

### 1.2 Migration

**File:** `database/migrations/xxxx_add_is_physical_to_products.php`

```php
Schema::table('products', function (Blueprint $table) {
    $table->boolean('is_physical')->default(true)->after('type');
});

// Set existing services to is_physical = false
DB::table('products')
    ->where('type', 'service')
    ->update(['is_physical' => false]);
```

### 1.3 Update ProductType Enum

**File:** `app/Modules/Product/Domain/Enums/ProductType.php`

```php
enum ProductType: string
{
    case Part = 'part';           // Physical, tracked in inventory
    case Service = 'service';     // Non-physical, no inventory
    case Consumable = 'consumable'; // Physical, not individually tracked

    /**
     * Whether this type typically requires inventory tracking.
     */
    public function isTypicallyPhysical(): bool
    {
        return match ($this) {
            self::Part, self::Consumable => true,
            self::Service => false,
        };
    }
}
```

---

## Phase 2: Backend - Three-Scenario Invoicing Logic

### 2.1 Add Line Analysis Helper

**File:** `app/Modules/Document/Domain/Services/DocumentConversionService.php`

Add new method:
```php
/**
 * Analyze order lines to determine invoicing scenario.
 *
 * @return array{
 *   has_physical_lines: bool,
 *   has_service_lines: bool,
 *   physical_line_ids: array<string>,
 *   service_line_ids: array<string>,
 *   scenario: 'products_only'|'services_only'|'mixed'
 * }
 */
private function analyzeOrderLines(Document $order): array
{
    $physicalLineIds = [];
    $serviceLineIds = [];

    foreach ($order->lines as $line) {
        $product = $line->product;

        // If no product linked, check if description suggests service
        if ($product === null) {
            // Lines without product: treat as service (common for labor/hourly work)
            $serviceLineIds[] = $line->id;
            continue;
        }

        if ($product->requiresDeliveryNote()) {
            $physicalLineIds[] = $line->id;
        } else {
            $serviceLineIds[] = $line->id;
        }
    }

    $hasPhysical = !empty($physicalLineIds);
    $hasService = !empty($serviceLineIds);

    $scenario = match (true) {
        $hasPhysical && $hasService => 'mixed',
        $hasPhysical => 'products_only',
        default => 'services_only',
    };

    return [
        'has_physical_lines' => $hasPhysical,
        'has_service_lines' => $hasService,
        'physical_line_ids' => $physicalLineIds,
        'service_line_ids' => $serviceLineIds,
        'scenario' => $scenario,
    ];
}
```

### 2.2 Check for Existing Delivery Notes

**File:** `app/Modules/Document/Domain/Services/DocumentConversionService.php`

Add method:
```php
/**
 * Check if order has any delivery notes created.
 */
public function orderHasDeliveryNotes(Document $order): bool
{
    $payload = $order->payload ?? [];
    $dnIds = $payload['delivery_note_ids'] ?? [];
    return !empty($dnIds);
}

/**
 * Get all delivery notes for an order.
 *
 * @return \Illuminate\Database\Eloquent\Collection<int, Document>
 */
public function getOrderDeliveryNotes(Document $order): \Illuminate\Database\Eloquent\Collection
{
    $payload = $order->payload ?? [];
    $dnIds = $payload['delivery_note_ids'] ?? [];

    if (empty($dnIds)) {
        return collect();
    }

    return Document::whereIn('id', $dnIds)
        ->where('type', DocumentType::DeliveryNote)
        ->get();
}
```

### 2.3 Revised `convertOrderToInvoice()` Method

**File:** `app/Modules/Document/Domain/Services/DocumentConversionService.php`

Replace the existing method (lines 94-160):

```php
/**
 * Convert a sales order to an invoice.
 *
 * Tunisia Compliance Rules:
 * - If order contains physical products AND delivery notes exist, BLOCK conversion
 * - If order contains only services, allow direct conversion
 * - If order contains mixed (physical + services), auto-create DN for physical items
 *
 * @param Document $order The sales order to convert
 * @param bool $partial Whether this is a partial invoice (not full order)
 * @param array<int, string>|null $lineIds Specific line IDs to include (for partial)
 * @return Document The created invoice
 *
 * @throws \InvalidArgumentException If source is not a sales order
 * @throws \RuntimeException If order is cancelled or already fully invoiced
 * @throws \DomainException If conversion is blocked due to existing delivery notes
 */
public function convertOrderToInvoice(Document $order, bool $partial = false, ?array $lineIds = null): Document
{
    // Basic validations
    if ($order->type !== DocumentType::SalesOrder) {
        throw new \InvalidArgumentException('Source document must be a sales order');
    }

    if ($order->status === DocumentStatus::Cancelled) {
        throw new \RuntimeException('Cannot convert cancelled sales order');
    }

    if ($order->status === DocumentStatus::Draft) {
        throw new \DomainException('Sales order must be confirmed before conversion', 422);
    }

    // CRITICAL: Check if already fully invoiced (prevents duplicate invoices)
    if ($this->isOrderFullyInvoiced($order)) {
        throw new \RuntimeException('Sales order has already been fully invoiced');
    }

    // Analyze order lines to determine scenario
    $analysis = $this->analyzeOrderLines($order);
    $hasDeliveryNotes = $this->orderHasDeliveryNotes($order);

    // Tunisia compliance: Block SO→Invoice if physical items AND DNs exist
    if ($analysis['scenario'] === 'products_only' && $hasDeliveryNotes) {
        throw new \DomainException(
            'This order has delivery notes. Please create the invoice from the delivery notes instead.',
            422
        );
    }

    return DB::transaction(function () use ($order, $partial, $lineIds, $analysis, $hasDeliveryNotes): Document {
        $autoCreatedDeliveryNote = null;

        // Mixed scenario: Auto-create DN for physical items if none exists
        if ($analysis['scenario'] === 'mixed' && !$hasDeliveryNotes && !empty($analysis['physical_line_ids'])) {
            $autoCreatedDeliveryNote = $this->autoCreateDeliveryNoteForPhysicalItems(
                $order,
                $analysis['physical_line_ids']
            );
        }

        // Create the invoice
        $invoice = Document::create([
            'tenant_id' => $order->tenant_id,
            'company_id' => $order->company_id,
            'location_id' => $order->location_id,
            'partner_id' => $order->partner_id,
            'vehicle_id' => $order->vehicle_id,
            'type' => DocumentType::Invoice,
            'status' => DocumentStatus::Draft,
            'document_number' => $this->numberingService->generateNumber(
                $order->tenant_id,
                $order->company_id,
                DocumentType::Invoice
            ),
            'document_date' => now(),
            'due_date' => now()->addDays(30),
            'currency' => $order->currency,
            'notes' => $order->notes,
            'internal_notes' => $order->internal_notes,
            'reference' => $order->document_number,
            'source_document_id' => $order->id,
        ]);

        // Copy lines (all or partial)
        if ($partial && $lineIds !== null) {
            $this->copyPartialLines($order, $invoice, $lineIds);
        } else {
            $this->copyLines($order, $invoice);
        }

        // Recalculate totals
        $this->recalculateTotals($invoice);

        // Transfer any prepayments from the order to the invoice
        $this->transferPrepayments($order, $invoice);

        // Update order payload
        $orderPayload = $order->payload ?? [];
        $orderPayload['invoice_ids'] = array_merge(
            $orderPayload['invoice_ids'] ?? [],
            [$invoice->id]
        );

        if (!$partial) {
            $orderPayload['fully_invoiced'] = true;
            $orderPayload['fully_invoiced_at'] = now()->toDateTimeString();
        }

        $order->update(['payload' => $orderPayload]);

        // Mark any existing delivery notes as fulfilled
        $this->markDeliveryNotesAsFulfilledByInvoice($order, $invoice);

        // Store auto-created DN reference in invoice payload
        $invoicePayload = $invoice->payload ?? [];
        if ($autoCreatedDeliveryNote !== null) {
            $invoicePayload['auto_created_delivery_note'] = [
                'id' => $autoCreatedDeliveryNote->id,
                'document_number' => $autoCreatedDeliveryNote->document_number,
                'status' => $autoCreatedDeliveryNote->status->value,
            ];
        }

        // Store delivery note references for UI display
        $allDnIds = $orderPayload['delivery_note_ids'] ?? [];
        if (!empty($allDnIds)) {
            $invoicePayload['source_delivery_note_ids'] = $allDnIds;
        }

        if (!empty($invoicePayload)) {
            $invoice->update(['payload' => $invoicePayload]);
        }

        return $invoice;
    });
}
```

### 2.4 Auto-Create Delivery Note for Physical Items

**File:** `app/Modules/Document/Domain/Services/DocumentConversionService.php`

Add method:
```php
/**
 * Auto-create a delivery note for physical items in a mixed order.
 *
 * This is called during SO→Invoice conversion when the order contains
 * both physical products and services. The DN is created in DRAFT status.
 *
 * @param Document $order The sales order
 * @param array<string> $physicalLineIds IDs of lines with physical products
 * @return Document The created delivery note
 */
private function autoCreateDeliveryNoteForPhysicalItems(Document $order, array $physicalLineIds): Document
{
    // Build delivery quantities map (full quantities for each physical line)
    $deliveryQuantities = [];
    foreach ($order->lines as $line) {
        if (in_array($line->id, $physicalLineIds, true)) {
            $deliveryQuantities[$line->id] = (string) $line->quantity;
        }
    }

    // Create the delivery note in DRAFT status
    $delivery = Document::create([
        'tenant_id' => $order->tenant_id,
        'company_id' => $order->company_id,
        'location_id' => $order->location_id,
        'partner_id' => $order->partner_id,
        'vehicle_id' => $order->vehicle_id,
        'type' => DocumentType::DeliveryNote,
        'status' => DocumentStatus::Draft, // Draft - user must confirm when posting invoice
        'document_number' => $this->numberingService->generateNumber(
            $order->tenant_id,
            $order->company_id,
            DocumentType::DeliveryNote
        ),
        'document_date' => now(),
        'currency' => $order->currency,
        'notes' => $order->notes,
        'internal_notes' => 'Auto-created during invoice generation',
        'reference' => $order->document_number,
        'source_document_id' => $order->id,
    ]);

    // Copy only physical lines
    $this->copyLinesForPartialDelivery($order, $delivery, $deliveryQuantities);

    // Recalculate totals
    $this->recalculateTotals($delivery);

    // Update order payload to track this DN
    $orderPayload = $order->payload ?? [];
    $orderPayload['delivery_note_ids'] = array_merge(
        $orderPayload['delivery_note_ids'] ?? [],
        [$delivery->id]
    );
    $order->update(['payload' => $orderPayload]);

    // Mark DN payload as auto-created
    $dnPayload = [
        'auto_created' => true,
        'auto_created_from_invoice' => true,
        'auto_created_at' => now()->toDateTimeString(),
    ];
    $delivery->update(['payload' => $dnPayload]);

    return $delivery;
}
```

### 2.5 Mark Delivery Notes as Fulfilled

**File:** `app/Modules/Document/Domain/Services/DocumentConversionService.php`

Add method:
```php
/**
 * Mark all delivery notes from a sales order as fulfilled when that SO is invoiced.
 *
 * These DNs are "covered" by the SO→Invoice conversion and should not appear
 * in the consolidation view.
 */
private function markDeliveryNotesAsFulfilledByInvoice(Document $order, Document $invoice): void
{
    $deliveryNoteIds = $order->payload['delivery_note_ids'] ?? [];

    if (empty($deliveryNoteIds)) {
        return;
    }

    $deliveryNotes = Document::whereIn('id', $deliveryNoteIds)
        ->where('type', DocumentType::DeliveryNote)
        ->get();

    foreach ($deliveryNotes as $dn) {
        $dnPayload = $dn->payload ?? [];
        $dnPayload['fulfilled_by_so_invoice'] = true;
        $dnPayload['fulfilled_by_so_invoice_id'] = $invoice->id;
        $dnPayload['fulfilled_by_so_invoice_at'] = now()->toDateTimeString();
        $dnPayload['source_order_invoiced'] = true;  // Backward-compatible flag
        $dn->update(['payload' => $dnPayload]);
    }
}
```

---

## Phase 3: Frontend - Fix Convert Button Visibility

### 3.1 Update canConvert Logic

**File:** `apps/web/src/features/documents/DocumentDetailPage.tsx`

Replace lines 335-343:
```typescript
// Determine if conversion is allowed based on document type and status
const canConvert = useMemo(() => {
  // Must be confirmed to convert
  if (document.status !== 'confirmed' || conversionTarget == null) {
    return false
  }

  // For quotes: check if already converted to order
  if (document.type === 'quote') {
    return document.converted_to_order_id == null
  }

  // For sales orders: check if already fully invoiced
  if (document.type === 'sales_order') {
    return !document.fully_invoiced
  }

  return true
}, [document.status, document.type, document.fully_invoiced, document.converted_to_order_id, conversionTarget])
```

### 3.2 Update Document Interface

**File:** `apps/web/src/features/documents/DocumentDetailPage.tsx`

Ensure the Document interface includes (around line 38-72):
```typescript
interface Document {
  // ... existing fields
  fully_invoiced: boolean
  fully_delivered: boolean
  delivery_note_ids: string[]
  invoice_ids: string[]
  // New: For showing auto-created DN in invoice view
  payload?: {
    auto_created_delivery_note?: {
      id: string
      document_number: string
      status: string
    }
    source_delivery_note_ids?: string[]
  }
}
```

---

## Phase 4: Frontend - DN Consolidation Filtering

### 4.1 Update Filtering Logic

**File:** `apps/web/src/features/documents/api/deliveryNotes.ts`

Replace `getInvoiceableDeliveryNotes` function (lines 94-102):
```typescript
/**
 * Get confirmed delivery notes that are eligible for consolidation.
 *
 * Excludes:
 * 1. Already invoiced via DN consolidation (has invoiced_at)
 * 2. Parent SO was invoiced (fulfilled_by_so_invoice)
 * 3. Draft status delivery notes
 */
export async function getInvoiceableDeliveryNotes(partnerId?: string): Promise<DeliveryNote[]> {
  const deliveryNotes = await apiGet<DeliveryNote[]>('/delivery-notes', {
    status: 'confirmed',
    partner_id: partnerId,
  })

  // Filter out:
  // 1. Already invoiced delivery notes (via consolidation)
  // 2. DNs whose parent sales order has been invoiced
  return deliveryNotes.filter(dn => {
    // Already invoiced via DN consolidation
    if (dn.payload?.invoiced_at) {
      return false
    }
    // Parent SO was invoiced - DN is fulfilled
    if (dn.payload?.fulfilled_by_so_invoice || dn.payload?.source_order_invoiced) {
      return false
    }
    return true
  })
}
```

### 4.2 Update DeliveryNote Interface

**File:** `apps/web/src/features/documents/api/deliveryNotes.ts`

Update interface (around line 11-35):
```typescript
export interface DeliveryNote {
  id: string
  document_number: string
  type: 'delivery_note'
  status: 'draft' | 'confirmed' | 'cancelled'
  partner_id: string
  partner_name: string | null
  partner?: {
    id: string
    name: string
    type: string
  }
  document_date: string
  subtotal: string | null
  tax_amount: string | null
  total: string | null
  currency: string
  lines: DeliveryNoteLine[]
  payload?: {
    invoiced_at?: string
    invoice_id?: string
    // New fields for SO fulfillment tracking
    fulfilled_by_so_invoice?: boolean
    fulfilled_by_so_invoice_id?: string
    fulfilled_by_so_invoice_at?: string
    source_order_invoiced?: boolean
    // Auto-creation tracking
    auto_created?: boolean
    auto_created_from_invoice?: boolean
    auto_created_at?: string
  }
  created_at: string
  updated_at: string
}
```

---

## Phase 5: Frontend - Add Consolidation Navigation

### 5.1 Add Consolidation Button

**File:** `apps/web/src/features/documents/DocumentListPage.tsx`

Add import at top:
```typescript
import { Plus, FileText, Calendar, Layers } from 'lucide-react'
```

In the header actions area (find where the "Add" button is), add the consolidation button for delivery notes:
```typescript
<div className="flex items-center gap-2">
  {/* Consolidation button - only for delivery notes */}
  {effectiveType === 'delivery_note' && (
    <Link
      to={`${basePath}/consolidate`}
      className="inline-flex items-center gap-2 rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 transition-colors"
    >
      <Layers className="h-4 w-4" />
      {t('sales:deliveryNotes.consolidation.title')}
    </Link>
  )}
  <Link
    to={`${basePath}/new`}
    className="inline-flex items-center gap-2 rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700 transition-colors"
  >
    <Plus className="h-4 w-4" />
    {t('common:actions.add')} {entityName}
  </Link>
</div>
```

---

## Phase 6: Invoice UI - Show Associated DN

### 6.1 Add DN Section to Invoice Detail

**File:** `apps/web/src/features/documents/DocumentDetailPage.tsx`

Add after the line items table (around line 827), before payment history:
```typescript
{/* Associated Delivery Notes Section - for invoices */}
{document.type === 'invoice' && document.payload?.source_delivery_note_ids && document.payload.source_delivery_note_ids.length > 0 && (
  <div className="rounded-lg border border-gray-200 bg-white p-6">
    <h2 className="mb-4 text-lg font-semibold text-gray-900">
      <Truck className="me-2 inline h-5 w-5" />
      {t('sales:deliveryNotes.title')}
    </h2>
    <div className="space-y-2">
      {document.payload.source_delivery_note_ids.map((dnId) => (
        <Link
          key={dnId}
          to={`/inventory/delivery-notes/${dnId}`}
          className="flex items-center justify-between rounded-lg border border-gray-200 p-3 hover:bg-gray-50 transition-colors"
        >
          <span className="text-sm font-medium text-gray-900">
            {/* DN number would ideally come from a lookup, for now show link */}
            {t('sales:deliveryNotes.singular')}
          </span>
          <ArrowRight className="h-4 w-4 text-gray-400" />
        </Link>
      ))}
    </div>

    {/* Show auto-created DN notice */}
    {document.payload?.auto_created_delivery_note && (
      <div className="mt-4 rounded-lg bg-yellow-50 p-3 border border-yellow-200">
        <div className="flex items-center gap-2">
          <AlertTriangle className="h-4 w-4 text-yellow-600" />
          <span className="text-sm text-yellow-800">
            {t('sales:deliveryNotes.autoCreatedNotice', 'A delivery note was automatically created for physical items.')}
          </span>
        </div>
        <Link
          to={`/inventory/delivery-notes/${document.payload.auto_created_delivery_note.id}`}
          className="mt-2 inline-flex items-center gap-1 text-sm font-medium text-yellow-700 hover:text-yellow-900"
        >
          {document.payload.auto_created_delivery_note.document_number}
          <span className={`inline-flex rounded-full px-2 py-0.5 text-xs ${
            document.payload.auto_created_delivery_note.status === 'confirmed'
              ? 'bg-blue-100 text-blue-800'
              : 'bg-gray-100 text-gray-800'
          }`}>
            {document.payload.auto_created_delivery_note.status}
          </span>
          <ArrowRight className="h-3 w-3" />
        </Link>
      </div>
    )}
  </div>
)}
```

---

## Phase 7: Invoice Posting - DN Status Check

### 7.1 Backend: Check DN Status Before Posting

**File:** `app/Modules/Document/Domain/Services/DocumentPostingService.php`

Add validation in the post method:
```php
/**
 * Check if associated delivery notes need attention before posting invoice.
 *
 * @return array{
 *   can_post: bool,
 *   warning: string|null,
 *   draft_delivery_notes: array<array{id: string, document_number: string}>
 * }
 */
public function checkDeliveryNoteStatus(Document $invoice): array
{
    $payload = $invoice->payload ?? [];
    $dnIds = $payload['source_delivery_note_ids'] ?? [];

    if (empty($dnIds)) {
        return [
            'can_post' => true,
            'warning' => null,
            'draft_delivery_notes' => [],
        ];
    }

    $draftDns = Document::whereIn('id', $dnIds)
        ->where('type', DocumentType::DeliveryNote)
        ->where('status', DocumentStatus::Draft)
        ->get(['id', 'document_number']);

    if ($draftDns->isEmpty()) {
        return [
            'can_post' => true,
            'warning' => null,
            'draft_delivery_notes' => [],
        ];
    }

    return [
        'can_post' => true, // Can still post, but warn user
        'warning' => 'Some associated delivery notes are still in draft status',
        'draft_delivery_notes' => $draftDns->map(fn($dn) => [
            'id' => $dn->id,
            'document_number' => $dn->document_number,
        ])->toArray(),
    ];
}
```

### 7.2 Frontend: Show DN Status Warning

When user clicks "Post Invoice", if there are draft DNs, show a confirmation dialog:

```typescript
// In DocumentDetailPage.tsx, modify the post confirmation handler
const handlePostInvoice = async () => {
  // First check for draft delivery notes
  if (document.type === 'invoice' && document.payload?.source_delivery_note_ids?.length) {
    // API call to check DN status
    const dnStatus = await api.get(`/invoices/${document.id}/delivery-note-status`)

    if (dnStatus.data.draft_delivery_notes?.length > 0) {
      // Show warning dialog
      setDraftDnWarning(dnStatus.data.draft_delivery_notes)
      return
    }
  }

  // Proceed with post
  postMutation.mutate()
}
```

---

## Phase 8: Backend Tests

### 8.1 Test: Cannot Convert SO→Invoice When DN Exists (Products Only)

```php
public function test_cannot_convert_so_to_invoice_when_delivery_note_exists_for_physical_products(): void
{
    // Create SO with physical product line
    $order = $this->createConfirmedSalesOrderWithPhysicalProduct();

    // Create delivery note from SO
    $this->conversionService->convertOrderToDelivery($order);

    // Attempt to convert SO to invoice should fail
    $this->expectException(DomainException::class);
    $this->expectExceptionMessage('This order has delivery notes');

    $this->conversionService->convertOrderToInvoice($order->fresh());
}
```

### 8.2 Test: Can Convert Services-Only SO

```php
public function test_can_convert_services_only_so_to_invoice(): void
{
    // Create SO with only service lines
    $order = $this->createConfirmedSalesOrderWithServicesOnly();

    // Should succeed without creating DN
    $invoice = $this->conversionService->convertOrderToInvoice($order);

    $this->assertNotNull($invoice);
    $this->assertEquals(DocumentType::Invoice, $invoice->type);
}
```

### 8.3 Test: Mixed Order Auto-Creates DN

```php
public function test_mixed_order_auto_creates_delivery_note(): void
{
    // Create SO with both physical and service lines
    $order = $this->createConfirmedMixedSalesOrder();

    // Convert to invoice
    $invoice = $this->conversionService->convertOrderToInvoice($order);

    // Verify DN was auto-created
    $order->refresh();
    $this->assertNotEmpty($order->payload['delivery_note_ids']);

    // Verify DN contains only physical items
    $dnId = $order->payload['delivery_note_ids'][0];
    $dn = Document::find($dnId);

    foreach ($dn->lines as $line) {
        $product = $line->product;
        $this->assertTrue($product->isPhysical());
    }
}
```

### 8.4 Test: Cannot Convert Already Invoiced SO

```php
public function test_cannot_convert_already_invoiced_so(): void
{
    $order = $this->createConfirmedSalesOrderWithServicesOnly();

    // First conversion succeeds
    $this->conversionService->convertOrderToInvoice($order);

    // Second conversion should fail
    $this->expectException(RuntimeException::class);
    $this->expectExceptionMessage('already been fully invoiced');

    $this->conversionService->convertOrderToInvoice($order->fresh());
}
```

---

## Phase 9: Translations

### 9.1 English Translations

**File:** `apps/web/src/locales/en/sales.json`

Add:
```json
{
  "deliveryNotes": {
    "autoCreatedNotice": "A delivery note was automatically created for physical items in this order.",
    "draftWarning": {
      "title": "Draft Delivery Notes",
      "message": "The following delivery notes are still in draft status. Do you want to confirm them before posting the invoice?",
      "confirmAll": "Confirm All & Post",
      "postAnyway": "Post Invoice Only",
      "cancel": "Cancel"
    }
  },
  "orders": {
    "cannotInvoiceWithDn": "This order has delivery notes. Please create the invoice from the delivery notes instead."
  }
}
```

### 9.2 French Translations

**File:** `apps/web/src/locales/fr/sales.json`

Add:
```json
{
  "deliveryNotes": {
    "autoCreatedNotice": "Un bon de livraison a ete cree automatiquement pour les articles physiques de cette commande.",
    "draftWarning": {
      "title": "Bons de livraison en brouillon",
      "message": "Les bons de livraison suivants sont encore en brouillon. Voulez-vous les confirmer avant de comptabiliser la facture ?",
      "confirmAll": "Confirmer tout et comptabiliser",
      "postAnyway": "Comptabiliser la facture uniquement",
      "cancel": "Annuler"
    }
  },
  "orders": {
    "cannotInvoiceWithDn": "Cette commande a des bons de livraison. Veuillez creer la facture a partir des bons de livraison."
  }
}
```

---

## Data Migration

### Backfill Existing DNs from Invoiced SOs

Run once after deployment:
```php
// database/migrations/xxxx_backfill_fulfilled_delivery_notes.php
public function up(): void
{
    // Find all SOs that are fully invoiced
    Document::where('type', DocumentType::SalesOrder)
        ->where('payload->fully_invoiced', true)
        ->chunkById(100, function ($orders) {
            foreach ($orders as $order) {
                $dnIds = $order->payload['delivery_note_ids'] ?? [];

                if (empty($dnIds)) {
                    continue;
                }

                // Mark DNs as fulfilled
                Document::whereIn('id', $dnIds)
                    ->where('type', DocumentType::DeliveryNote)
                    ->update([
                        'payload->source_order_invoiced' => true,
                        'payload->fulfilled_by_so_invoice' => true,
                    ]);
            }
        });
}
```

---

## Implementation Order

1. **Phase 1:** Product classification (`is_physical` flag) + migration
2. **Phase 2:** Backend conversion logic with 3-scenario support
3. **Phase 3:** Frontend convert button fix
4. **Phase 4:** DN consolidation filtering fix
5. **Phase 5:** Consolidation navigation button
6. **Phase 6:** Invoice UI showing associated DNs
7. **Phase 7:** Invoice posting DN status check
8. **Phase 8:** Backend tests
9. **Phase 9:** Translations
10. **Data Migration:** Backfill existing data

---

## Testing Checklist

After implementation:

- [ ] Create SO with physical products only, create DN, attempt SO→Invoice = ERROR
- [ ] Create SO with services only, convert to invoice = SUCCESS, no DN created
- [ ] Create SO with mixed items, convert to invoice = SUCCESS, DN auto-created for physical items
- [ ] Convert SO to invoice twice = ERROR on second attempt
- [ ] DNs from invoiced SO do not appear in consolidation view
- [ ] Consolidation button visible on DN list page
- [ ] Invoice shows associated delivery notes section
- [ ] Auto-created DN shows warning badge on invoice
- [ ] Posting invoice with draft DNs shows confirmation dialog

---

## Country Adaptation (Future)

For Phase 2, add country-specific rules configuration:

```php
// config/document_rules.php
return [
    'TN' => [  // Tunisia
        'dn_required_before_invoice' => true,
        'dn_consolidation_allowed' => true,
        'direct_so_invoice_services_only' => true,
    ],
    'IT' => [  // Italy (DDT)
        'dn_required_before_invoice' => true,
        'dn_consolidation_allowed' => true,
        'direct_so_invoice_services_only' => true,
    ],
    'FR' => [  // France
        'dn_required_before_invoice' => false,  // Not required for B2B
        'dn_consolidation_allowed' => true,
        'direct_so_invoice_services_only' => true,
    ],
];
```

---

## Questions Resolved

1. **Partial SO→Invoice?** - Not yet supported; full conversion only
2. **Backfill existing data?** - Yes, via migration
3. **Display fulfilled status?** - Yes, DNs from invoiced SOs shown differently

---

*End of Revised Plan*
