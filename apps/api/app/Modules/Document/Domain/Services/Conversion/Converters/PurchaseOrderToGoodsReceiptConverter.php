<?php

declare(strict_types=1);

namespace App\Modules\Document\Domain\Services\Conversion\Converters;

use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Document\Domain\Events\DocumentConverted;
use App\Modules\Document\Domain\Services\Conversion\DocumentConverterInterface;
use App\Modules\Inventory\Application\Services\GoodsReceiptService;
use Illuminate\Support\Facades\Event;

/**
 * Converter for Purchase Order to Goods Receipt.
 *
 * NOTE: This converter is different from others in that it does NOT create a new document.
 * Instead, it updates the source PurchaseOrder with received quantities and changes its status.
 * This converter exists to provide a consistent API through the converter registry.
 *
 * Options:
 * - 'received_quantities' (array<string, string>): Map of line IDs to quantities to receive.
 *   If provided, enables partial receipt. If omitted, full receipt is performed.
 *
 * Validates:
 * - Source must be PurchaseOrder type
 * - Must be in Confirmed status (not draft or received)
 * - For partial: quantities don't exceed remaining quantities
 * - Has physical products (not services-only)
 *
 * On conversion:
 * - Updates quantity_received on PurchaseOrder lines
 * - Increases stock levels for received products
 * - Updates Weighted Average Cost (WAC) for products
 * - Updates PurchaseOrder status to Received if fully received
 * - Dispatches DocumentConverted event
 * - Returns the updated PurchaseOrder document
 */
final class PurchaseOrderToGoodsReceiptConverter implements DocumentConverterInterface
{
    public function __construct(
        protected readonly GoodsReceiptService $goodsReceiptService
    ) {}

    public function sourceType(): DocumentType
    {
        return DocumentType::PurchaseOrder;
    }

    /**
     * Returns PurchaseOrder as the target type since goods receipt updates
     * the PurchaseOrder rather than creating a new document.
     */
    public function targetType(): DocumentType
    {
        return DocumentType::PurchaseOrder;
    }

    public function canConvert(Document $source): bool
    {
        return empty($this->getConversionErrors($source));
    }

    /**
     * @return array<int, string>
     */
    public function getConversionErrors(Document $source): array
    {
        $errors = [];

        if ($source->type !== DocumentType::PurchaseOrder) {
            $errors[] = 'Source document must be a purchase order';
        }

        if ($source->status === DocumentStatus::Draft) {
            $errors[] = 'Purchase order must be confirmed before receiving goods';
        }

        if ($source->status === DocumentStatus::Cancelled) {
            $errors[] = 'Cannot receive goods for cancelled purchase order';
        }

        if ($source->status === DocumentStatus::Received) {
            $errors[] = 'Purchase order has already been fully received';
        }

        // Check if order has line items
        if ($source->lines->isEmpty()) {
            $errors[] = 'Purchase order must have at least one line item';
        }

        // Check if already fully received using service
        if ($source->type === DocumentType::PurchaseOrder && $source->status === DocumentStatus::Confirmed) {
            try {
                if ($this->goodsReceiptService->isFullyReceived($source)) {
                    $errors[] = 'Purchase order has already been fully received';
                }
            } catch (\DomainException) {
                // Not a valid PurchaseOrder - already caught above
            }
        }

        return $errors;
    }

    /**
     * Receive goods for a purchase order.
     *
     * @param  array<string, mixed>  $options  Options: 'received_quantities' (array<string, string>)
     * @return Document The updated PurchaseOrder document
     */
    public function convert(Document $source, array $options = []): Document
    {
        /** @var array<string, string>|null $receivedQuantities */
        $receivedQuantities = $options['received_quantities'] ?? null;

        if ($source->type !== DocumentType::PurchaseOrder) {
            throw new \InvalidArgumentException('Source document must be a purchase order');
        }

        if ($source->status === DocumentStatus::Draft) {
            throw new \DomainException('Purchase order must be confirmed before receiving goods', 422);
        }

        if ($source->status === DocumentStatus::Cancelled) {
            throw new \RuntimeException('Cannot receive goods for cancelled purchase order');
        }

        if ($source->status === DocumentStatus::Received) {
            throw new \RuntimeException('Purchase order has already been fully received');
        }

        // Use GoodsReceiptService for the actual business logic
        if ($receivedQuantities !== null && count($receivedQuantities) > 0) {
            $updatedDocument = $this->goodsReceiptService->receiveGoods($source, $receivedQuantities);
        } else {
            // Receive all remaining quantities
            $updatedDocument = $this->goodsReceiptService->receiveAll($source);
        }

        // Dispatch conversion event for audit trail
        Event::dispatch(new DocumentConverted(
            sourceDocument: $source,
            targetDocument: $updatedDocument,
            converterClass: self::class,
            isPartialConversion: $receivedQuantities !== null,
        ));

        return $updatedDocument;
    }
}
