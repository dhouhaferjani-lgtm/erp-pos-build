<?php

declare(strict_types=1);

namespace App\Modules\Document\Domain\Exceptions;

use RuntimeException;

/**
 * A broken billed-once invariant — an INTEGRITY ALARM, never a customer-data refusal.
 *
 * The count guard that raises this is the wave's only detector for the defect
 * DeliveryNoteBillingClaimService's own docblock names: "a committed runtime-lane marker
 * with a null invoice id is a defect". While this extended DomainException it fell through
 * the generic `catch (\DomainException)` handlers in DocumentConversionController and
 * InvoiceController and shipped as HTTP 422 under a validation error code —
 * indistinguishable from "you cannot invoice this delivery note", so the defect produced no
 * 500, no alert, and an operator-facing message reading "Delivery-note payload finalisation
 * affected 0 rows; expected 1."
 *
 * Extending RuntimeException keeps it out of every DomainException handler so it surfaces as
 * a 500-class alert and is reported. (M5-terminal treasury F-7.)
 */
final class DeliveryNoteClaimNotFinalisedException extends RuntimeException
{
    public static function forMarkerCount(int $expected, int $affected): self
    {
        return new self("Delivery-note marker finalisation affected {$affected} rows; expected {$expected}.");
    }

    public static function forPayloadCount(int $expected, int $affected): self
    {
        return new self("Delivery-note payload finalisation affected {$affected} rows; expected {$expected}.");
    }
}
