<?php

declare(strict_types=1);

namespace App\Modules\Company\Domain\Exceptions;

use DomainException;

/**
 * Exception thrown when attempting to change company tax status
 * when posted fiscal documents exist.
 *
 * Tax status changes are permanently locked after the first invoice
 * or credit note is posted to ensure fiscal compliance.
 */
class CannotChangeTaxStatusException extends DomainException
{
    /**
     * Create exception for companies with posted fiscal documents.
     */
    public static function hasPostedFiscalDocuments(string $companyName): self
    {
        return new self(
            "Cannot change tax status for company '{$companyName}' because posted fiscal documents exist. ".
            'Tax status is permanently locked after the first invoice or credit note is posted for fiscal compliance. '.
            'Contact support if you need to change this setting.'
        );
    }
}
