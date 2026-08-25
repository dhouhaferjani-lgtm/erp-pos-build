<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain\Exceptions;

use DomainException;

/**
 * Thrown at finalize when the counting still has lines whose resolution method
 * is `pending` (LEDGER C-14(iii)).
 *
 * This refusal used to be a bare `\InvalidArgumentException`. That type has no
 * render handler in `bootstrap/app.php`, so the reviewer got a 500 with no
 * guidance for the single most ordinary pre-finalize mistake — while both
 * sibling pre-finalize refusals in the same method
 * (`OverlappingCountingException`, `OpeningCostRequiredException`) already
 * extend `DomainException` and surface as a typed 422 `BUSINESS_ERROR`. Same
 * shape now, so the FE renders one refusal family instead of branching on a
 * server error.
 *
 * The pending line COUNT is carried as a field as well as being in the message:
 * the reviewer's next action ("go resolve N lines") depends on it.
 */
final class CountingUnresolvedItemsException extends DomainException
{
    public function __construct(public readonly int $unresolvedCount)
    {
        $noun = $unresolvedCount === 1 ? 'item' : 'items';

        parent::__construct(
            "Cannot finalize: {$unresolvedCount} {$noun} still pending resolution."
        );
    }
}
