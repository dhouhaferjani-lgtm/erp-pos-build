<?php

declare(strict_types=1);

namespace App\Modules\Document\Domain\Exceptions;

use DomainException;

/**
 * Raised when a lifecycle transition attempts to replace a document's
 * established fiscal identity.
 */
final class DocumentRenumberingException extends DomainException
{
    public function __construct(
        public readonly string $documentId,
        public readonly string $existingNumber,
        public readonly string|int|float|bool|null $attemptedNumber,
    ) {
        parent::__construct(sprintf(
            'Document %s cannot be renumbered from `%s` to `%s`.',
            $documentId,
            $existingNumber,
            (string) $attemptedNumber,
        ));
    }
}
