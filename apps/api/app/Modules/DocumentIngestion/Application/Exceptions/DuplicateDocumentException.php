<?php

declare(strict_types=1);

namespace App\Modules\DocumentIngestion\Application\Exceptions;

/**
 * A non-rejected ingestion already exists for the same file bytes
 * (company-scoped checksum). Rendered by the controller as a 422 with the
 * typed code DUPLICATE_DOCUMENT so clients don't have to infer duplicates
 * from validation-field presence.
 */
final class DuplicateDocumentException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('A non-rejected document ingestion already exists for this file.');
    }
}
