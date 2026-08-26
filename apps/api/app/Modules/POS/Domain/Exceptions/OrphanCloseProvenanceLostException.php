<?php

declare(strict_types=1);

namespace App\Modules\POS\Domain\Exceptions;

use RuntimeException;

/**
 * The `shift.orphan_closed` audit row could not be confirmed, so the close it
 * would have justified must not stand.
 *
 * An administrative shift close has exactly two durable records of WHO closed
 * it and WHY — the audit row and `pos_shifts.notes` — and the closed row itself
 * is exported to the NF525 JET as an ordinary `FERMETURE_CAISSE` with no field
 * that could say otherwise. `DomainEventSubscriber::persistEvent()` catches
 * every `Throwable` and only logs, so the dispatch cannot report its own
 * failure; the command therefore READS the row back inside its transaction and
 * raises this when it is absent. Thrown inside `DB::transaction`, it rolls the
 * close back: an orphan that is still OPEN is recoverable, an unexplained
 * closed fiscal shift is not.
 */
final class OrphanCloseProvenanceLostException extends RuntimeException
{
    public static function forShift(string $shiftId): self
    {
        return new self(sprintf(
            'The shift.orphan_closed audit row for shift %s could not be confirmed after dispatch; '
            .'rolling the close back rather than leaving a closed fiscal shift with no record of who '
            .'closed it or why.',
            $shiftId,
        ));
    }
}
