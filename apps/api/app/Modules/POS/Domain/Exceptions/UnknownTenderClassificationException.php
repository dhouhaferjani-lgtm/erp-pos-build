<?php

declare(strict_types=1);

namespace App\Modules\POS\Domain\Exceptions;

use RuntimeException;

/**
 * A receipt payment inside the shift's window names a `payment_method_code`
 * that no `payment_methods` row carries, so its CASH-NESS cannot be decided and
 * the shift's expected cash cannot be derived.
 *
 * # Why this is a refusal and not a default (Session D final review, I-1)
 *
 * `ShiftExpectedCashService` used to answer the cash question itself, with
 * `UPPER(payment_method_code) = 'CASH'`, while every other consumer of the same
 * tender — the shared repository rule
 * (`Treasury\Application\Services\TenderRepositoryResolver`),
 * the fiscal bridge and the device checkout resolver — read
 * `payment_methods.is_cash_tender`. On a brownfield row the two predicates
 * disagreed in OPPOSITE directions, which is how a repository movement and a
 * certified expected-cash figure ended up on different tender classifications.
 * The service now reads the same flag as everyone else, which makes the missing
 * row a real possibility: a method DELETED after its receipts were written has
 * no flag left to read.
 *
 * Guessing either way writes a wrong number into `pos_shifts.expected_cash`,
 * which `Nf525DataProvider::mapShift()` exports to the NF525 JET as
 * `EspecesAttendues` with `Ecart = 0` against the original cashier — treating
 * the leg as cash overstates the drawer, treating it as non-cash understates it,
 * and both are certified as balanced. So this fails closed, the same standard
 * {@see UnattributableAccountCollectionException} and
 * {@see UnsignableCashMovementException} already set for this derivation.
 *
 * The remedy is a data one: restore (or re-create with the same code) the
 * payment method the receipts reference, then re-run the close.
 */
final class UnknownTenderClassificationException extends RuntimeException
{
    public static function forShift(string $shiftId, string $paymentMethodCode): self
    {
        return new self(sprintf(
            'Shift %s has receipt payments tendered as "%s", but no payment method with that code exists for the '
            .'company, so the leg cannot be classified as cash or non-cash. Refusing to derive expected cash rather '
            .'than guess — either guess is exported to the NF525 JET as a balanced count. Restore the payment '
            .'method (same code) and re-run.',
            $shiftId,
            $paymentMethodCode,
        ));
    }

    /**
     * The shift's terminal row cannot be loaded, so there is no (tenant,
     * company) scope in which to resolve its tender codes.
     *
     * Structurally close to impossible — `pos_shifts.terminal_id` is a foreign
     * key and `CloseOrphanedShiftCommand` resolves the terminal before it gets
     * here — but the alternative to naming it is a `null` dereference deep
     * inside a certified derivation.
     */
    public static function forOrphanedTerminal(string $shiftId): self
    {
        return new self(sprintf(
            'Shift %s has no resolvable terminal, so its payment methods cannot be scoped to a company and its '
            .'tenders cannot be classified as cash or non-cash. Refusing to derive expected cash.',
            $shiftId,
        ));
    }
}
