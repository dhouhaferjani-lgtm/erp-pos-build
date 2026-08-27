<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Domain\Exceptions;

use App\Modules\Treasury\Domain\Enums\CashTenderInvariantRefusalCode;
use DomainException;

/**
 * Thrown when a payment-method write would leave `is_cash_tender` incoherent
 * with the method's `code` (Session D final review, finding I-1).
 *
 * Shape copied from `Company\Domain\Exceptions\FiscalPeriodCloseRefusedException`:
 * a typed exception carrying a backed {@see CashTenderInvariantRefusalCode}
 * whose value is the stable wire code, rendered by the controller into the house
 * `{error: {code, message, ...}}` envelope with HTTP 422.
 *
 * It extends `\DomainException`, so the generic `bootstrap/app.php` handler
 * would render it as a 422 `BUSINESS_ERROR` if it ever escaped — but it does
 * not: `PaymentMethodController::store()`/`update()` are the only raisers and
 * both catch it. No render callback is registered, deliberately.
 */
final class CashTenderInvariantViolationException extends DomainException
{
    private function __construct(
        string $message,
        public readonly CashTenderInvariantRefusalCode $refusalCode,
        public readonly string $paymentMethodCode,
    ) {
        parent::__construct($message);
    }

    public static function flagOnNonCanonicalCode(string $code): self
    {
        return new self(
            sprintf(
                'Only the payment method with code CASH may be flagged as a cash tender; this one is "%s". '
                .'Clear is_cash_tender, or rename the method to CASH (one method per company may hold that code).',
                $code,
            ),
            CashTenderInvariantRefusalCode::FlagOnNonCanonicalCode,
            $code,
        );
    }

    public static function canonicalCodeNotFlagged(string $code): self
    {
        return new self(
            sprintf(
                'A payment method whose code is cash ("%s") cannot be left with is_cash_tender = false: '
                .'is_cash_tender is the single cash-ness predicate, and an unflagged cash-looking code is read as '
                .'cash by some consumers and as non-cash by others. Set is_cash_tender (available when the code is '
                .'exactly CASH), or rename the method to a code that is not cash.',
                $code,
            ),
            CashTenderInvariantRefusalCode::CanonicalCodeNotFlagged,
            $code,
        );
    }
}
