<?php

declare(strict_types=1);

namespace App\Modules\Document\Domain\Services\Conversion;

use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\Enums\DocumentType;

/**
 * Interface for document type converters.
 *
 * Each converter handles a specific source-to-target document type conversion.
 * Converters implement the Strategy pattern to allow modular, testable conversion logic.
 *
 * @example
 * ```php
 * class QuoteToOrderConverter implements DocumentConverterInterface
 * {
 *     public function sourceType(): DocumentType
 *     {
 *         return DocumentType::Quote;
 *     }
 *
 *     public function targetType(): DocumentType
 *     {
 *         return DocumentType::SalesOrder;
 *     }
 *
 *     public function convert(Document $source, array $options = []): Document
 *     {
 *         // Conversion logic here
 *     }
 * }
 * ```
 */
interface DocumentConverterInterface
{
    /**
     * Get the document type this converter accepts as source.
     */
    public function sourceType(): DocumentType;

    /**
     * Get the document type this converter produces as target.
     */
    public function targetType(): DocumentType;

    /**
     * Perform the conversion from source document to target document type.
     *
     * This method creates a new document of the target type based on the source document.
     * The conversion is performed within a database transaction.
     *
     * @param  Document  $source  The source document to convert
     * @param  array<string, mixed>  $options  Conversion options (e.g., partial line IDs, quantities)
     * @return Document The newly created document of the target type
     *
     * @throws \InvalidArgumentException If source document type does not match sourceType()
     * @throws \RuntimeException If conversion cannot be performed (e.g., invalid state)
     * @throws \DomainException If business rules prevent conversion (e.g., draft status)
     */
    public function convert(Document $source, array $options = []): Document;

    /**
     * Check if the given source document can be converted.
     *
     * This method performs validation checks without actually performing the conversion.
     * Use this to display appropriate UI states or pre-validate before attempting conversion.
     *
     * @param  Document  $source  The document to check
     * @return bool True if conversion is allowed, false otherwise
     */
    public function canConvert(Document $source): bool;

    /**
     * Get detailed validation errors explaining why conversion is not allowed.
     *
     * If canConvert() returns false, this method provides specific error messages
     * that can be displayed to the user or logged.
     *
     * @param  Document  $source  The document to validate
     * @return array<int, string> Array of error messages, empty if conversion is allowed
     */
    public function getConversionErrors(Document $source): array;
}
