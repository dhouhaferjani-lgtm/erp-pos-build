<?php

declare(strict_types=1);

namespace App\Modules\Document\Domain\Exceptions;

use DomainException;

/**
 * A delivery the `(product, location)` tuple shape cannot express.
 *
 * Plan CF CF-D11 / fiscal gate N2-C1. Kept deliberately NARROW. The tuple split
 * exists so a product delivered from two locations restocks to both — so this is
 * **never** the refusal for "several locations, pick one". It is reserved for a
 * delivery line whose location cannot be resolved at all while its siblings can, i.e.
 * a shape where part of the quantity would silently restock somewhere it never left.
 *
 * If you find yourself reaching for this to break a tie between two locations, the
 * tuple emission is wrong — go fix that instead.
 */
final class ReturnLocationAmbiguousException extends DomainException
{
    public const CODE = 'RETURN_LOCATION_AMBIGUOUS';

    /**
     * @param  list<string>  $productIds  Products whose delivery could not be expressed as tuples.
     */
    public function __construct(
        public readonly string $invoiceId,
        public readonly string $invoiceNumber,
        public readonly array $productIds,
    ) {
        parent::__construct(
            "Cannot record a goods return for invoice {$invoiceNumber}: part of the delivered quantity cannot be "
            .'attributed to the location it left from. Create the return note manually against the delivery note.'
        );
    }
}
