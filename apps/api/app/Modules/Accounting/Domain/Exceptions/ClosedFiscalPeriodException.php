<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Domain\Exceptions;

use App\Modules\Company\Domain\FiscalPeriod;

/**
 * Exception thrown when attempting to post a journal entry into a fiscal
 * period that exists and is closed for the entry's date.
 *
 * Guard semantics (Treasury spine BLOCKER-2): thrown ONLY when a
 * FiscalPeriod row exists for the entry date AND that period is closed
 * (see {@see FiscalPeriod::isClosed()}, which treats both Closed and Locked
 * statuses as closed). The ABSENCE of any fiscal period covering the date is
 * explicitly ALLOWED — posting must never be blocked for a company that has
 * not configured fiscal periods yet, or this would brick every unconfigured
 * tenant.
 *
 * Extends \DomainException (not \RuntimeException) so the generic
 * DomainException render handler (bootstrap/app.php) maps it to an HTTP 422
 * BUSINESS_ERROR response instead of an unhandled 500 — a closed period is a
 * business-rule rejection, not a server fault.
 */
class ClosedFiscalPeriodException extends \DomainException
{
    public function __construct(
        public readonly string $companyId,
        public readonly string $entryDate,
    ) {
        parent::__construct(
            "Cannot post journal entry dated {$entryDate} for company {$companyId}: ".
            'the fiscal period covering this date is closed. Reopen the period or choose a date in an open period.'
        );
    }
}
