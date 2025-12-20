# Delivery Note Compliance Design Document

> **Status**: Draft - Implementation Phase 1 Complete
> **Last Updated**: December 2025
> **Author**: Auto-generated from implementation

---

## Executive Summary

This document details the delivery note implementation in AutoERP and outlines country-specific compliance requirements. Delivery notes (also called dispatch notes, packing slips, or DDT in Italy) are critical logistics documents that track the physical movement of goods from seller to buyer.

---

## Current Implementation (Phase 1)

### Overview

The current implementation provides basic delivery note functionality with duplicate prevention:

```
┌─────────────┐      ┌─────────────┐      ┌─────────────┐
│   Quote     │─────>│ Sales Order │─────>│  Invoice    │
└─────────────┘      └──────┬──────┘      └─────────────┘
                           │
                           │ (parallel path)
                           v
                    ┌─────────────┐
                    │Delivery Note│
                    └─────────────┘
```

### Key Features Implemented

1. **One-to-One Conversion (Full Delivery)**
   - Sales Order can generate exactly ONE delivery note
   - `fully_delivered` flag prevents duplicate generation
   - Backend validation in `DocumentConversionService::convertOrderToDelivery()`

2. **Document Relationship Tracking**
   - `delivery_note_ids[]` array in Sales Order payload
   - `invoice_ids[]` array in Sales Order payload
   - `source_document_id` links delivery note back to order

3. **Navigation and UX**
   - "Fully Delivered" badge on completed orders
   - "Convert to Delivery Note" button hidden once delivered
   - Back-navigation link from delivery note to source order

### Data Model

**Sales Order Payload (after delivery):**
```json
{
  "delivery_note_ids": ["uuid-delivery-note-1"],
  "fully_delivered": true,
  "fully_delivered_at": "2025-12-11T10:30:00Z"
}
```

**Delivery Note Fields:**
```php
Document {
    type: DocumentType::DeliveryNote,
    source_document_id: "uuid-sales-order",
    // All line items copied from source order
}
```

### Backend Service: DocumentConversionService

```php
public function convertOrderToDelivery(Document $order): Document
{
    // Validations
    if ($order->type !== DocumentType::SalesOrder) {
        throw new InvalidArgumentException('Source must be sales order');
    }

    if ($order->status === DocumentStatus::Draft) {
        throw new DomainException('Order must be confirmed first', 422);
    }

    if ($this->isOrderFullyDelivered($order)) {
        throw new RuntimeException('Order already fully delivered');
    }

    // Create delivery note with all lines
    // Mark order as fully_delivered
}
```

---

## Country-Specific Requirements

### Italy (DDT - Documento di Trasporto)

**Regulatory Framework:**
- D.P.R. 472/96 - Transport document regulations
- DDT is a fiscal document for tax purposes
- Must accompany goods during transport

**Critical Requirements:**

| Requirement | Description | Implementation Status |
|-------------|-------------|----------------------|
| Sequential Numbering | DDT must have unique sequential number per year | ✅ Implemented |
| Mandatory Fields | Sender, recipient, transport details, goods description | ⚠️ Partial |
| Issue Before Transport | DDT must be issued BEFORE goods leave premises | ⚠️ Workflow needed |
| Invoice Correlation | Invoice must reference DDT number(s) | ⚠️ Not implemented |
| Transport Mode | Vehicle, carrier, delivery address required | ❌ Not implemented |
| Signature | Driver/recipient signature for proof of delivery | ❌ Not implemented |

**Italian DDT-Invoice Relationship:**
```
One or more DDTs → One Invoice (consolidation allowed)
One DDT → One Invoice (1:1 also valid)
```

**Required DDT Fields (DPR 472/96):**
```
- Numero DDT (sequential per year)
- Data emissione
- Dati cedente (seller)
- Dati cessionario (buyer)
- Luogo di destinazione (delivery address)
- Causale del trasporto (reason: sale, loan, repair return, etc.)
- Aspetto dei beni (appearance of goods)
- Numero colli (number of packages)
- Peso (weight - net/gross)
- Porto (carriage: franco/assegnato)
- Vettore (carrier details if third-party)
- Annotazioni (notes)
```

### Tunisia

**Regulatory Framework:**
- Code de commerce - Commercial code
- Delivery notes are common but less strictly regulated than Italy

**Key Characteristics:**

| Aspect | Tunisia Approach |
|--------|-----------------|
| Consolidation | Multiple delivery notes → Single invoice |
| Numbering | Internal numbering acceptable |
| Transport | Driver details optional |
| Invoice Timing | Invoice can be issued later (end of month billing) |

**Tunisian Flow Example:**
```
Sales Order SO-2025-001
    ├── Delivery Note BL-2025-001 (partial: items A, B)
    ├── Delivery Note BL-2025-002 (partial: item C)
    └── Delivery Note BL-2025-003 (partial: item D)
         │
         └──────────> Invoice FAC-2025-001 (consolidates all 3)
```

### France

**Regulatory Framework:**
- Bon de livraison (delivery note) - Not strictly regulated for B2B
- Required for e-commerce returns proof

**Key Points:**
- Not a fiscal document (unlike invoice)
- Recommended but not mandatory for B2B
- Required for consumer returns (e-commerce)
- Can consolidate multiple deliveries into one invoice

### Gulf Countries (UAE, KSA)

**Regulatory Framework:**
- VAT regulations require delivery note for goods movement
- ZATCA e-invoicing compliance (Saudi Arabia)

**Key Points:**
- Delivery note number must appear on e-invoice XML
- Goods movement tracking for customs/VAT purposes

---

## Proposed Enhancements

### Phase 2: Partial Delivery Support

**Use Case:** Customer orders 100 items, warehouse has 60 in stock. Ship 60 now, 40 later.

**Data Model Changes:**

```php
// DocumentLine additions
$table->decimal('quantity_ordered', 10, 2);
$table->decimal('quantity_delivered', 10, 2)->default(0);
$table->decimal('quantity_remaining', 10, 2)->virtualAs(
    'quantity_ordered - quantity_delivered'
);
```

**Sales Order Payload:**
```json
{
  "delivery_note_ids": ["uuid-dn-1", "uuid-dn-2"],
  "fully_delivered": false,
  "deliveries": [
    {
      "delivery_note_id": "uuid-dn-1",
      "delivered_at": "2025-12-11T10:00:00Z",
      "lines": [
        { "product_id": "X", "quantity_delivered": 60 }
      ]
    },
    {
      "delivery_note_id": "uuid-dn-2",
      "delivered_at": "2025-12-15T10:00:00Z",
      "lines": [
        { "product_id": "X", "quantity_delivered": 40 }
      ]
    }
  ],
  "fully_delivered": true,
  "fully_delivered_at": "2025-12-15T10:00:00Z"
}
```

**Service Changes:**
```php
public function convertOrderToDelivery(
    Document $order,
    array $lineQuantities = null  // null = full delivery
): Document {
    if ($lineQuantities === null) {
        // Current behavior: full delivery
        return $this->createFullDelivery($order);
    }

    // Partial delivery logic
    return $this->createPartialDelivery($order, $lineQuantities);
}
```

### Phase 3: DDT Metadata (Italy Compliance)

**New Table: `delivery_metadata`**
```php
Schema::create('delivery_metadata', function (Blueprint $table) {
    $table->uuid('id')->primary();
    $table->uuid('document_id')->unique();
    $table->string('transport_reason'); // causale: vendita, conto visione, reso
    $table->string('goods_appearance')->nullable(); // aspetto: scatole, pallet
    $table->integer('package_count')->nullable();
    $table->decimal('gross_weight', 10, 3)->nullable();
    $table->decimal('net_weight', 10, 3)->nullable();
    $table->string('carriage_type'); // porto: franco, assegnato
    $table->string('carrier_name')->nullable();
    $table->string('carrier_vat')->nullable();
    $table->string('vehicle_plate')->nullable();
    $table->string('driver_name')->nullable();
    $table->text('delivery_address');
    $table->timestamp('departure_datetime')->nullable();
    $table->timestamps();

    $table->foreign('document_id')->references('id')->on('documents');
});
```

**Transport Reason Enum (Italian DDT):**
```php
enum TransportReason: string
{
    case Sale = 'vendita';                    // Regular sale
    case Consignment = 'conto_visione';       // On approval/trial
    case Loan = 'comodato';                   // Free loan
    case Processing = 'conto_lavorazione';    // Processing/repair
    case Return = 'reso';                     // Return
    case Transfer = 'trasferimento';          // Inter-warehouse
    case Gift = 'omaggio';                    // Free sample
}
```

### Phase 4: Invoice-Delivery Correlation

**Multi-Delivery Invoice (Tunisia/France model):**
```php
// Invoice payload
{
  "source_delivery_notes": [
    { "id": "uuid-dn-1", "number": "BL-2025-001" },
    { "id": "uuid-dn-2", "number": "BL-2025-002" }
  ],
  "consolidation_period": {
    "from": "2025-12-01",
    "to": "2025-12-31"
  }
}
```

**Invoice Generation Options:**
```php
// Option A: Invoice per delivery note (1:1)
$invoice = $conversion->convertDeliveryToInvoice($deliveryNote);

// Option B: Invoice consolidating multiple deliveries (N:1)
$invoice = $conversion->createConsolidatedInvoice(
    partner: $customer,
    deliveryNotes: [$dn1, $dn2, $dn3],
    invoiceDate: now()
);
```

### Phase 5: Proof of Delivery (PoD)

**Digital Signature Capture:**
```php
Schema::table('delivery_metadata', function (Blueprint $table) {
    $table->text('recipient_signature')->nullable(); // Base64 image
    $table->string('recipient_name')->nullable();
    $table->timestamp('signed_at')->nullable();
    $table->string('signed_location')->nullable(); // GPS coords
});
```

**Mobile App Integration:**
- Driver app captures signature
- Photo of delivered goods (optional)
- GPS timestamp of delivery
- Syncs back to ERP

---

## Configuration by Country

### Country Profile Settings

```php
// Company model or config
'delivery_note_settings' => [
    'IT' => [
        'ddt_required' => true,
        'sequential_numbering' => true,
        'transport_details_required' => true,
        'consolidation_allowed' => true,
        'max_days_to_invoice' => 30,
        'proof_of_delivery' => 'recommended',
    ],
    'TN' => [
        'ddt_required' => false,
        'sequential_numbering' => false, // Internal numbering OK
        'transport_details_required' => false,
        'consolidation_allowed' => true,
        'max_days_to_invoice' => null, // End of month billing
        'proof_of_delivery' => 'optional',
    ],
    'FR' => [
        'ddt_required' => false,
        'sequential_numbering' => false,
        'transport_details_required' => false,
        'consolidation_allowed' => true,
        'max_days_to_invoice' => null,
        'proof_of_delivery' => 'recommended_ecommerce',
    ],
]
```

---

## Workflow Diagrams

### Current Flow (Phase 1 - Full Delivery Only)

```
┌─────────────────────────────────────────────────────────────┐
│                     SALES ORDER                             │
│  Status: Confirmed                                          │
│  fully_delivered: false                                     │
├─────────────────────────────────────────────────────────────┤
│  [Convert to Delivery Note]  [Convert to Invoice]           │
└──────────────┬──────────────────────────────────────────────┘
               │
               │ User clicks "Convert to Delivery Note"
               v
┌─────────────────────────────────────────────────────────────┐
│                    DELIVERY NOTE                            │
│  Status: Draft                                              │
│  source_document_id: {order_id}                             │
│  All lines copied from order                                │
├─────────────────────────────────────────────────────────────┤
│  [Back: SO-2025-001]  [Confirm]                             │
└──────────────┬──────────────────────────────────────────────┘
               │
               │ Confirm delivery note
               v
┌─────────────────────────────────────────────────────────────┐
│                     SALES ORDER                             │
│  Status: Confirmed                                          │
│  fully_delivered: true ✅                                   │
│  [Fully Delivered] badge displayed                          │
├─────────────────────────────────────────────────────────────┤
│  [Convert to Invoice]  (no delivery button)                 │
└─────────────────────────────────────────────────────────────┘
```

### Future Flow (Phase 2 - Partial Delivery)

```
┌─────────────────────────────────────────────────────────────┐
│                     SALES ORDER                             │
│  Lines:                                                     │
│  - Product A: 100 units (0 delivered)                       │
│  - Product B: 50 units (0 delivered)                        │
├─────────────────────────────────────────────────────────────┤
│  [Create Partial Delivery]  [Create Full Delivery]          │
└──────────────┬──────────────────────────────────────────────┘
               │
               │ Select: Product A = 60, Product B = 50
               v
┌─────────────────────────────────────────────────────────────┐
│                  DELIVERY NOTE #1                           │
│  Lines:                                                     │
│  - Product A: 60 units                                      │
│  - Product B: 50 units                                      │
└─────────────────────────────────────────────────────────────┘
               │
               │ Order now shows remaining
               v
┌─────────────────────────────────────────────────────────────┐
│                     SALES ORDER                             │
│  Lines:                                                     │
│  - Product A: 100 units (60 delivered, 40 remaining)        │
│  - Product B: 50 units (50 delivered, 0 remaining) ✅       │
│  fully_delivered: false                                     │
├─────────────────────────────────────────────────────────────┤
│  [Create Partial Delivery]  [Convert to Invoice]            │
└─────────────────────────────────────────────────────────────┘
```

---

## API Endpoints

### Current

```
POST /api/v1/documents/{orderId}/convert-to-delivery
  Response: 201 Created with delivery note data
  Error 422: "Sales order must be confirmed first"
  Error 409: "Sales order has already been fully delivered"
```

### Proposed (Phase 2+)

```
# Full delivery (current behavior)
POST /api/v1/documents/{orderId}/convert-to-delivery

# Partial delivery
POST /api/v1/documents/{orderId}/convert-to-delivery
Body: {
  "lines": [
    { "line_id": "uuid", "quantity": 60 },
    { "line_id": "uuid", "quantity": 50 }
  ]
}

# Consolidated invoice from multiple deliveries
POST /api/v1/invoices/from-deliveries
Body: {
  "delivery_note_ids": ["uuid-1", "uuid-2", "uuid-3"],
  "invoice_date": "2025-12-31"
}
```

---

## Migration Path

### Phase 1 (Current) → Phase 2

No breaking changes required. Existing `fully_delivered` boolean remains valid.
Add optional `lineQuantities` parameter to conversion endpoint.

### Adding DDT Metadata

1. Create `delivery_metadata` table (new, no migration of existing data)
2. Make metadata optional initially
3. Add country-based validation rules
4. Italy profile requires transport reason field

---

## Testing Scenarios

### Current Implementation Tests

```php
/** @test */
public function it_prevents_duplicate_delivery_notes()
{
    $order = Document::factory()->salesOrder()->confirmed()->create();

    // First delivery succeeds
    $delivery1 = $this->conversionService->convertOrderToDelivery($order);
    $this->assertNotNull($delivery1);

    // Refresh order from database
    $order->refresh();

    // Second delivery fails
    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('already been fully delivered');
    $this->conversionService->convertOrderToDelivery($order);
}

/** @test */
public function delivery_note_links_back_to_source_order()
{
    $order = Document::factory()->salesOrder()->confirmed()->create();
    $delivery = $this->conversionService->convertOrderToDelivery($order);

    $this->assertEquals($order->id, $delivery->source_document_id);

    $dto = DocumentData::fromModel($delivery);
    $this->assertEquals($order->document_number, $dto->source_document_number);
    $this->assertEquals('sales_order', $dto->source_document_type);
}
```

### Future Tests (Phase 2)

```php
/** @test */
public function partial_delivery_tracks_remaining_quantities()
{
    $order = Document::factory()
        ->salesOrder()
        ->confirmed()
        ->withLines([
            ['product_id' => $productA, 'quantity' => 100],
            ['product_id' => $productB, 'quantity' => 50],
        ])
        ->create();

    $delivery = $this->conversionService->convertOrderToDelivery($order, [
        $lineA->id => 60,
        $lineB->id => 50,
    ]);

    $order->refresh();
    $this->assertFalse($order->payload['fully_delivered']);
    $this->assertEquals(40, $order->lines[0]->quantity_remaining);
    $this->assertEquals(0, $order->lines[1]->quantity_remaining);
}
```

---

## Open Questions

1. **Partial Delivery UI Complexity**
   - Should we show a modal with quantity pickers?
   - Or a table with editable quantities?
   - Mobile-friendly design considerations?

2. **Backorder Management**
   - When partial delivery is made, should we auto-create backorder?
   - Or keep original order open until fully delivered?

3. **Italy DDT Number Reset**
   - Reset annually (DDT-2025-0001)?
   - Or continuous (DDT-0001, DDT-0002...)?
   - Per company or per warehouse?

4. **Invoice-Delivery Timing**
   - Can invoice be created before delivery is confirmed?
   - Italy may require delivery-first for certain goods

5. **Returns/Refusals**
   - What happens if customer refuses part of delivery?
   - Create return delivery note?
   - Update original delivery note?

---

## References

- [Italian DDT Regulations - DPR 472/96](https://www.normattiva.it/uri-res/N2Ls?urn:nir:stato:decreto.del.presidente.della.repubblica:1996-10-14;472)
- [French Bon de Livraison Guide](https://www.service-public.fr/professionnels-entreprises)
- [ZATCA E-Invoicing Guidelines (Saudi Arabia)](https://zatca.gov.sa/en/E-Invoicing/Pages/default.aspx)
- [Tunisian Commercial Code](http://www.legislation.tn/)

---

## Appendix: Document Type Matrix

| Country | Delivery Note Name | Fiscal? | Numbering | Consolidation | PoD Required |
|---------|-------------------|---------|-----------|---------------|--------------|
| Italy | DDT | Yes | Sequential/Year | Yes | Recommended |
| France | Bon de livraison | No | Internal | Yes | E-commerce only |
| Tunisia | Bon de livraison | No | Internal | Yes | No |
| UAE | Delivery Note | VAT-related | Sequential | Yes | Business |
| Saudi Arabia | Delivery Note | ZATCA | Sequential | Yes | Business |
| Morocco | Bon de livraison | No | Internal | Yes | No |

---

*Document Version: 1.0*
*Implementation Phase: 1 (Full Delivery Only)*
