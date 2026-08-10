<?php

declare(strict_types=1);

namespace App\Modules\Document\Domain\Exceptions;

/**
 * A delivery note cannot be built for this invoice — the partner or the location
 * the generator needs does not resolve.
 *
 * ── WHY IT IS RAISED FROM INSIDE THE LOCKED TRANSACTION (fix round 2, inv N-3) ──
 * These two lookups used to run BEFORE the row lock and their results were then
 * carried into the transaction. That is the same stale-read shape P1-2 closed for
 * the invoice itself, one level down: between the read and the lock, another
 * request can deactivate the location, move it to another company, or delete the
 * partner — and the composite would then issue stock at a location it had already
 * stopped being allowed to use, inside a transaction that believed it had checked.
 *
 * Resolving them AFTER the lock costs two queries and removes the window
 * entirely. The exception carries the same machine reason the pre-lock checks
 * used to return, so the HTTP contract is unchanged: one 422,
 * `DELIVERY_CANNOT_BE_GENERATED`, with `NO_PARTNER` or `NO_RESOLVABLE_LOCATION`
 * as the reason.
 */
final class GuidedDeliveryCannotBeGeneratedException extends \DomainException
{
    private function __construct(public readonly string $reason)
    {
        parent::__construct($reason);
    }

    public static function noPartner(): self
    {
        return new self('NO_PARTNER');
    }

    public static function noResolvableLocation(): self
    {
        return new self('NO_RESOLVABLE_LOCATION');
    }
}
