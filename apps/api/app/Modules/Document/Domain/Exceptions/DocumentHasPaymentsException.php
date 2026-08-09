<?php

declare(strict_types=1);

namespace App\Modules\Document\Domain\Exceptions;

use DomainException;

/**
 * A cancellation refused because money has already been allocated to the document.
 *
 * Plan CF T6 / frontend gate I-1. The refusal is NOT new — `RefundService` and
 * `DocumentPostingService` have thrown `\DomainException('DOCUMENT_HAS_PAYMENTS')`
 * all along. What was broken is how it reached the client:
 * `RefundController::cancelInvoice()`'s generic catch flattens every failure into
 * `{error: <message>, code: <message>}`, i.e. a STRING in `error`. Against that body
 * the web app's `getErrorMessage` (`lib/api.ts`) falls through to axios' bare
 * "Request failed with status code 422" and `extractErrorCode`
 * (`utils/errorHandling.ts`) — which reads `error.code` — yields `undefined`. So the
 * modal could not distinguish this blocking, unfixable-by-retry condition from any
 * other 422.
 *
 * This type lets the dedicated `bootstrap/app.php` renderer emit the standard
 * `{error: {code, message}}` envelope. The generic catch itself is deliberately left
 * untouched (other consumers assert on it).
 *
 * BACKWARD-COMPATIBILITY NOTE — read before "cleaning up" the renderer. Its entry for
 * this exception ALSO emits a top-level `code`, duplicating `error.code`. That is not
 * an oversight: `DocumentCancelConsolidationTest` asserts
 * `assertJsonPath('code', 'DOCUMENT_HAS_PAYMENTS')` in three tests against the old
 * flat envelope, and plan CF names that class as part of this lane's regression
 * contract ("stays green unmodified") while ALSO requiring the typed envelope. Both
 * requirements are satisfiable only by emitting both keys. See the CF report's
 * contradiction note.
 */
final class DocumentHasPaymentsException extends DomainException
{
    public const CODE = 'DOCUMENT_HAS_PAYMENTS';

    public function __construct(
        public readonly string $documentId,
        public readonly string $documentNumber,
    ) {
        parent::__construct(
            "Document {$documentNumber} cannot be cancelled because payments or credit notes have already been "
            .'allocated to it. Unallocate them first, or issue a credit note instead.'
        );
    }
}
