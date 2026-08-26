<?php

declare(strict_types=1);

namespace App\Modules\POS\Domain\Exceptions;

use RuntimeException;

/**
 * A shift carries a cash movement the server cannot sign, so its expected cash
 * cannot be derived.
 *
 * Raised rather than swallowed on purpose. Treating an unrecognised movement as
 * zero would return a figure that looks entirely reasonable, is short by
 * whatever the movement was worth, and is written to `pos_shifts.expected_cash`
 * — which `Nf525DataProvider::mapShift()` exports to the NF525 JET as
 * `EspecesAttendues` with `Ecart = 0`. Silently-short money in a certified
 * export is the exact failure this whole derivation was rewritten to end
 * (O-30 gate r1), so the derivation fails closed and asks for a human.
 *
 * `CASH_CORRECTION` is the live case: it is the only movement whose direction
 * lives in the amount's SIGN rather than in its type, and it has no device
 * authoring path today — so there is no observed convention to encode, and
 * guessing one would be the same class of error.
 */
final class UnsignableCashMovementException extends RuntimeException
{
    public static function forShift(string $shiftId, string $movementType, string $knownTypes): self
    {
        return new self(sprintf(
            'Shift %s carries a cash movement of type "%s", which has no agreed direction on the server '
            .'(known: %s). Refusing to derive expected cash rather than silently dropping it — resolve the '
            .'movement with an accountant before closing this shift.',
            $shiftId,
            $movementType,
            $knownTypes,
        ));
    }

    public static function forIncompletePayload(string $shiftId): self
    {
        return new self(sprintf(
            'Shift %s has a cash movement with no usable movement_type/amount in its payload; refusing to '
            .'derive expected cash from an incomplete movement set.',
            $shiftId,
        ));
    }
}
