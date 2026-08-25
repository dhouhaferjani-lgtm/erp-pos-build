<?php

declare(strict_types=1);

namespace App\Modules\Company\Domain\Exceptions;

use App\Modules\Company\Domain\Enums\FiscalPeriodReopenRefusalCode;
use DomainException;

/**
 * Thrown when `FiscalPeriodReopenService::reopen()` (Company/Application/Services)
 * refuses to reopen a fiscal period. Named, not imported: a Domain class must not
 * depend on the Application layer, and deptrac counts a `use` in either position.
 *
 * Session B lane Q-10 fix round, gate r1 minor M-1: the four refusals used to be
 * bare `\DomainException`s with prose messages, mapped to a `{"message": ...}` 422,
 * so a front end could not tell "locked" from "successor already closed" from
 * "fiscal year closed" without string-matching. Shape copied from Taxation's
 * `DocumentPeriodLockedException`: a typed exception carrying a backed enum whose
 * value is the stable wire code.
 *
 * It still extends `\DomainException`, so the generic `bootstrap/app.php` handler
 * would render it as a 422 `BUSINESS_ERROR` if it ever escaped — but it does not:
 * `FiscalPeriodController::reopen()` catches it and emits the house
 * `{error: {code, message, ...}}` envelope with the typed code. No render callback
 * is registered, deliberately: the exception is raised on exactly one route.
 */
final class FiscalPeriodReopenRefusedException extends DomainException
{
    private function __construct(
        string $message,
        public readonly FiscalPeriodReopenRefusalCode $refusalCode,
        public readonly string $fiscalPeriodId,
    ) {
        parent::__construct($message);
    }

    public static function periodLocked(string $fiscalPeriodId): self
    {
        return new self(
            'Locked periods cannot be reopened: the fiscal year they belong to is closed.',
            FiscalPeriodReopenRefusalCode::PeriodLocked,
            $fiscalPeriodId,
        );
    }

    public static function periodNotClosed(string $fiscalPeriodId): self
    {
        return new self(
            'Only closed periods can be reopened',
            FiscalPeriodReopenRefusalCode::PeriodNotClosed,
            $fiscalPeriodId,
        );
    }

    public static function fiscalYearClosed(string $fiscalPeriodId): self
    {
        return new self(
            'Cannot reopen: the fiscal year this period belongs to is closed.',
            FiscalPeriodReopenRefusalCode::FiscalYearClosed,
            $fiscalPeriodId,
        );
    }

    public static function successorSettled(string $fiscalPeriodId): self
    {
        return new self(
            'Cannot reopen: a successor period is already closed or locked',
            FiscalPeriodReopenRefusalCode::SuccessorSettled,
            $fiscalPeriodId,
        );
    }
}
