<?php

declare(strict_types=1);

namespace App\Modules\Company\Domain\Exceptions;

use App\Modules\Company\Domain\Enums\FiscalPeriodCloseRefusalCode;
use DomainException;

/**
 * Thrown when `FiscalPeriodCloseService::close()` (Company/Application/Services) refuses
 * to close a fiscal period. The service is NAMED, not imported, for the same reason as
 * {@see FiscalPeriodReopenRefusedException}: a Domain class must not depend on the
 * Application layer, and deptrac counts a `use` in either position.
 *
 * Shape copied one-for-one from the reopen sibling: a typed exception carrying a backed
 * {@see FiscalPeriodCloseRefusalCode} whose value is the stable wire code.
 *
 * It extends `\DomainException`, so the generic `bootstrap/app.php` handler would render
 * it as a 422 `BUSINESS_ERROR` if it ever escaped — but it does not:
 * `FiscalPeriodController::close()` catches it and emits the house
 * `{error: {code, message, ...}}` envelope with the typed code. No render callback is
 * registered, deliberately: the exception is raised on exactly one route.
 */
final class FiscalPeriodCloseRefusedException extends DomainException
{
    private function __construct(
        string $message,
        public readonly FiscalPeriodCloseRefusalCode $refusalCode,
        public readonly string $fiscalPeriodId,
    ) {
        parent::__construct($message);
    }

    public static function periodLocked(string $fiscalPeriodId): self
    {
        return new self(
            'Locked periods cannot be closed: `locked` is terminal and the fiscal year they belong to is closed.',
            FiscalPeriodCloseRefusalCode::PeriodLocked,
            $fiscalPeriodId,
        );
    }

    public static function periodNotOpen(string $fiscalPeriodId): self
    {
        return new self(
            'Only open periods can be closed',
            FiscalPeriodCloseRefusalCode::PeriodNotOpen,
            $fiscalPeriodId,
        );
    }

    public static function fiscalYearClosed(string $fiscalPeriodId): self
    {
        return new self(
            'Cannot close: the fiscal year this period belongs to is already closed.',
            FiscalPeriodCloseRefusalCode::FiscalYearClosed,
            $fiscalPeriodId,
        );
    }

    public static function predecessorOpen(string $fiscalPeriodId): self
    {
        return new self(
            'Cannot close: an earlier period of this company is still open. Periods are closed oldest first.',
            FiscalPeriodCloseRefusalCode::PredecessorOpen,
            $fiscalPeriodId,
        );
    }

    public static function periodNotEnded(string $fiscalPeriodId): self
    {
        return new self(
            'Cannot close: the period has not ended yet.',
            FiscalPeriodCloseRefusalCode::PeriodNotEnded,
            $fiscalPeriodId,
        );
    }
}
