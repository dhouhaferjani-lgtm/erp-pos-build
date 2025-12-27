<?php

declare(strict_types=1);

namespace App\Modules\Document\Domain\Services;

use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Document\Domain\Services\Conversion\DocumentConverterRegistry;

/**
 * @deprecated This service is being phased out in favor of DocumentConverterRegistry with specific converter classes.
 * Use DocumentConverterRegistry with QuoteToSalesOrderConverter, SalesOrderToInvoiceConverter, etc.
 *
 * This class now delegates to the registry for backwards compatibility.
 * Will be removed in v3.0
 *
 * @see \App\Modules\Document\Domain\Services\Conversion\DocumentConverterRegistry
 * @see \App\Modules\Document\Domain\Services\Conversion\Converters\QuoteToSalesOrderConverter
 * @see \App\Modules\Document\Domain\Services\Conversion\Converters\SalesOrderToInvoiceConverter
 * @see \App\Modules\Document\Domain\Services\Conversion\Converters\SalesOrderToDeliveryNoteConverter
 * @see \App\Modules\Document\Domain\Services\Conversion\Converters\DeliveryNoteToInvoiceConverter
 */
class DocumentConversionService
{
    public function __construct(
        private readonly DocumentConverterRegistry $registry
    ) {}

    /**
     * Convert a quote to a sales order.
     *
     * @deprecated Use DocumentConverterRegistry::convert() with DocumentType::SalesOrder instead
     */
    public function convertQuoteToOrder(Document $quote): Document
    {
        return $this->registry->convert($quote, DocumentType::SalesOrder);
    }

    /**
     * Convert a sales order to an invoice.
     *
     * @param  array<int, string>|null  $lineIds
     *
     * @deprecated Use DocumentConverterRegistry::convert() with DocumentType::Invoice instead
     */
    public function convertOrderToInvoice(Document $order, bool $partial = false, ?array $lineIds = null): Document
    {
        return $this->registry->convert($order, DocumentType::Invoice, [
            'partial' => $partial,
            'line_ids' => $lineIds,
        ]);
    }

    /**
     * Convert a sales order to a delivery note (full delivery).
     *
     * @deprecated Use DocumentConverterRegistry::convert() with DocumentType::DeliveryNote instead
     */
    public function convertOrderToDelivery(Document $order): Document
    {
        return $this->registry->convert($order, DocumentType::DeliveryNote);
    }

    /**
     * Convert a sales order to a partial delivery note.
     *
     * @param  array<string, string>  $deliveryQuantities  Map of order line IDs to quantities to deliver
     *
     * @deprecated Use DocumentConverterRegistry::convert() with DocumentType::DeliveryNote and 'delivery_quantities' option
     */
    public function convertOrderToPartialDelivery(Document $order, array $deliveryQuantities): Document
    {
        return $this->registry->convert($order, DocumentType::DeliveryNote, [
            'delivery_quantities' => $deliveryQuantities,
        ]);
    }

    /**
     * Check if order has been fully delivered.
     *
     * @deprecated Access $order->payload['fully_delivered'] directly or use Document::getDeliveryStatus()
     */
    public function isOrderFullyDelivered(Document $order): bool
    {
        if ($order->type !== DocumentType::SalesOrder) {
            throw new \InvalidArgumentException('Document must be a sales order');
        }

        $payload = $order->payload ?? [];

        return $payload['fully_delivered'] ?? false;
    }

    /**
     * Check if quote has expired.
     *
     * @deprecated Access $quote->valid_until directly and compare with now()
     */
    public function isQuoteExpired(Document $quote): bool
    {
        if ($quote->type !== DocumentType::Quote) {
            throw new \InvalidArgumentException('Document must be a quote');
        }

        return $quote->valid_until !== null && $quote->valid_until->isPast();
    }

    /**
     * Check if order has been fully invoiced.
     *
     * @deprecated Access $order->payload['fully_invoiced'] directly
     */
    public function isOrderFullyInvoiced(Document $order): bool
    {
        if ($order->type !== DocumentType::SalesOrder) {
            throw new \InvalidArgumentException('Document must be a sales order');
        }

        $payload = $order->payload ?? [];

        return $payload['fully_invoiced'] ?? false;
    }

    /**
     * Create an invoice from one or more delivery notes (Tunisia consolidation model).
     *
     * @param  array<int, Document>  $deliveryNotes
     *
     * @deprecated Use DocumentConverterRegistry::convert() with DocumentType::Invoice and 'delivery_note_ids' option
     */
    public function createInvoiceFromDeliveryNotes(array $deliveryNotes): Document
    {
        if (empty($deliveryNotes)) {
            throw new \InvalidArgumentException('At least one delivery note is required');
        }

        $firstDn = $deliveryNotes[0];
        $deliveryNoteIds = array_map(fn (Document $dn) => $dn->id, $deliveryNotes);

        return $this->registry->convert($firstDn, DocumentType::Invoice, [
            'delivery_note_ids' => $deliveryNoteIds,
        ]);
    }
}
