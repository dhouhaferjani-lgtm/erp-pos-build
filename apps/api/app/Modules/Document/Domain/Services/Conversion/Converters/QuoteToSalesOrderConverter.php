<?php

declare(strict_types=1);

namespace App\Modules\Document\Domain\Services\Conversion\Converters;

use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Document\Domain\Services\Conversion\Concerns\CopiesDocumentData;
use App\Modules\Document\Domain\Services\Conversion\DocumentConverterInterface;
use App\Modules\Document\Domain\Services\Conversion\StripSubToleranceDiscountsService;
use App\Modules\Document\Domain\Services\DocumentNumberingService;
use App\Modules\Taxation\Domain\Services\TaxCalculationService;
use App\Shared\Contracts\CurrencyScaleResolverInterface;
use Illuminate\Support\Facades\DB;

/**
 * Converter for Quote to Sales Order conversion.
 *
 * Validates:
 * - Source must be Quote type
 * - Not cancelled
 * - Not in draft status (must be confirmed)
 * - Not already converted
 * - Not expired
 * - Has line items
 *
 * On conversion:
 * - Creates a new Sales Order with all data from the quote
 * - Copies all lines to the new order
 * - Copies vehicle context if present
 * - Marks the quote as converted with timestamp and order ID
 * - Dispatches DocumentConverted event
 */
final class QuoteToSalesOrderConverter implements DocumentConverterInterface
{
    use CopiesDocumentData;

    public function __construct(
        protected readonly DocumentNumberingService $numberingService,
        protected readonly CurrencyScaleResolverInterface $scaleResolver,
        protected readonly TaxCalculationService $taxCalculationService,
        private readonly StripSubToleranceDiscountsService $discountStripper,
    ) {}

    public function sourceType(): DocumentType
    {
        return DocumentType::Quote;
    }

    public function targetType(): DocumentType
    {
        return DocumentType::SalesOrder;
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

        if ($source->type !== DocumentType::Quote) {
            $errors[] = 'Source document must be a quote';
        }

        if ($source->status === DocumentStatus::Cancelled) {
            $errors[] = 'Cannot convert cancelled quote';
        }

        if ($source->status === DocumentStatus::Draft) {
            $errors[] = 'Quote must be confirmed before conversion';
        }

        // Check if quote has been converted already
        $payload = $source->payload ?? [];
        if (! empty($payload['converted_to_order_id'])) {
            $errors[] = 'Quote has already been converted to a sales order';
        }

        // Check if quote is expired
        if ($source->valid_until !== null && $source->valid_until->isPast()) {
            $errors[] = 'Cannot convert expired quote';
        }

        // Check if quote has line items
        if ($source->lines->isEmpty()) {
            $errors[] = 'Quote must have at least one line item';
        }

        return $errors;
    }

    /**
     * @param  array<string, mixed>  $options  No options supported for this converter
     */
    public function convert(Document $source, array $options = []): Document
    {
        // Validate source type
        if ($source->type !== DocumentType::Quote) {
            throw new \InvalidArgumentException('Source document must be a quote');
        }

        if ($source->status === DocumentStatus::Cancelled) {
            throw new \RuntimeException('Cannot convert cancelled quote');
        }

        if ($source->status === DocumentStatus::Draft) {
            throw new \DomainException('Quote must be confirmed before conversion', 422);
        }

        // Check if quote has been converted already
        $payload = $source->payload ?? [];
        if (! empty($payload['converted_to_order_id'])) {
            throw new \RuntimeException('Quote has already been converted to a sales order');
        }

        // Check if quote is expired
        if ($source->valid_until !== null && $source->valid_until->isPast()) {
            throw new \RuntimeException('Cannot convert expired quote');
        }

        return DB::transaction(function () use ($source): Document {
            // Create sales order
            $order = $this->createTargetDocument($source, DocumentType::SalesOrder);

            // Copy lines
            $this->copyLines($source, $order);

            // Strip sub-tolerance discounts BEFORE downstream consumers see
            // the order — catches the Quote loophole described in spec §7.
            // The strip mutates per-line line_total (residual flows through
            // as an amount due), so document-level subtotal/tax/total MUST
            // be recomputed afterwards or the sales order would carry stale
            // source-quote totals that no longer match the sum of its lines.
            $this->discountStripper->stripFromConvertedDocument($source, $order);
            $this->recalculateTotals($order);

            // Copy vehicle context if present
            $this->copyVehicleContext($source, $order);

            // Mark quote as converted
            $this->linkDocuments($source, $order, 'converted_to_order_id');

            // Dispatch conversion event for audit trail
            $this->dispatchConversionEvent($source, $order);

            return $order;
        });
    }
}
