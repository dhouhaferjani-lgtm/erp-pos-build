<?php

declare(strict_types=1);

namespace App\Modules\Document\Domain\Services\Conversion;

use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\Enums\DocumentType;

/**
 * Registry for document converters.
 *
 * This class manages the collection of document converters and provides
 * methods to look up and execute conversions between document types.
 *
 * Converters are indexed by "{sourceType}:{targetType}" for O(1) lookup.
 *
 * @example
 * ```php
 * $registry = new DocumentConverterRegistry();
 * $registry->register(new QuoteToOrderConverter());
 * $registry->register(new OrderToInvoiceConverter());
 *
 * // Convert a quote to an order
 * $order = $registry->convert($quote, DocumentType::SalesOrder);
 *
 * // Check what conversions are available from a quote
 * $targets = $registry->getAvailableConversions(DocumentType::Quote);
 * // Returns [DocumentType::SalesOrder]
 * ```
 */
final class DocumentConverterRegistry
{
    /**
     * Registered converters indexed by "{sourceType}:{targetType}".
     *
     * @var array<string, DocumentConverterInterface>
     */
    private array $converters = [];

    /**
     * Register a document converter.
     *
     * @param  DocumentConverterInterface  $converter  The converter to register
     *
     * @throws \InvalidArgumentException If a converter for the same source-target pair is already registered
     */
    public function register(DocumentConverterInterface $converter): void
    {
        $key = $this->buildKey($converter->sourceType(), $converter->targetType());

        if (isset($this->converters[$key])) {
            throw new \InvalidArgumentException(
                sprintf(
                    'A converter for %s to %s is already registered',
                    $converter->sourceType()->value,
                    $converter->targetType()->value
                )
            );
        }

        $this->converters[$key] = $converter;
    }

    /**
     * Get the converter for a specific source-target pair.
     *
     * @param  DocumentType  $from  The source document type
     * @param  DocumentType  $to  The target document type
     * @return DocumentConverterInterface|null The converter, or null if not registered
     */
    public function getConverter(DocumentType $from, DocumentType $to): ?DocumentConverterInterface
    {
        $key = $this->buildKey($from, $to);

        return $this->converters[$key] ?? null;
    }

    /**
     * Convert a document to the specified target type.
     *
     * @param  Document  $source  The source document to convert
     * @param  DocumentType  $targetType  The target document type
     * @param  array<string, mixed>  $options  Conversion options
     * @return Document The newly created document
     *
     * @throws \InvalidArgumentException If no converter is registered for this conversion path
     * @throws \RuntimeException If conversion cannot be performed
     * @throws \DomainException If business rules prevent conversion
     */
    public function convert(Document $source, DocumentType $targetType, array $options = []): Document
    {
        $converter = $this->getConverter($source->type, $targetType);

        if ($converter === null) {
            throw new \InvalidArgumentException(
                sprintf(
                    'No converter registered for %s to %s conversion',
                    $source->type->value,
                    $targetType->value
                )
            );
        }

        return $converter->convert($source, $options);
    }

    /**
     * Check if conversion from source document to target type is possible.
     *
     * This checks both that a converter exists and that the source document
     * passes the converter's validation.
     *
     * @param  Document  $source  The source document
     * @param  DocumentType  $targetType  The target document type
     * @return bool True if conversion is possible, false otherwise
     */
    public function canConvert(Document $source, DocumentType $targetType): bool
    {
        $converter = $this->getConverter($source->type, $targetType);

        if ($converter === null) {
            return false;
        }

        return $converter->canConvert($source);
    }

    /**
     * Get all available conversion targets from a given source type.
     *
     * @param  DocumentType  $from  The source document type
     * @return array<int, DocumentType> Array of target document types that have registered converters
     */
    public function getAvailableConversions(DocumentType $from): array
    {
        $targets = [];
        $prefix = $from->value.':';

        foreach ($this->converters as $key => $converter) {
            if (str_starts_with($key, $prefix)) {
                $targets[] = $converter->targetType();
            }
        }

        return $targets;
    }

    /**
     * Get conversion errors for a specific source-target pair.
     *
     * @param  Document  $source  The source document
     * @param  DocumentType  $targetType  The target document type
     * @return array<int, string> Array of error messages, empty if conversion is allowed
     */
    public function getConversionErrors(Document $source, DocumentType $targetType): array
    {
        $converter = $this->getConverter($source->type, $targetType);

        if ($converter === null) {
            return [
                sprintf(
                    'No converter registered for %s to %s conversion',
                    $source->type->value,
                    $targetType->value
                ),
            ];
        }

        return $converter->getConversionErrors($source);
    }

    /**
     * Check if a converter is registered for a specific source-target pair.
     *
     * @param  DocumentType  $from  The source document type
     * @param  DocumentType  $to  The target document type
     * @return bool True if a converter is registered
     */
    public function hasConverter(DocumentType $from, DocumentType $to): bool
    {
        return $this->getConverter($from, $to) !== null;
    }

    /**
     * Get all registered converters.
     *
     * @return array<string, DocumentConverterInterface> All registered converters
     */
    public function getConverters(): array
    {
        return $this->converters;
    }

    /**
     * Build the lookup key for a source-target pair.
     *
     * @param  DocumentType  $from  The source document type
     * @param  DocumentType  $to  The target document type
     * @return string The lookup key
     */
    private function buildKey(DocumentType $from, DocumentType $to): string
    {
        return $from->value.':'.$to->value;
    }
}
