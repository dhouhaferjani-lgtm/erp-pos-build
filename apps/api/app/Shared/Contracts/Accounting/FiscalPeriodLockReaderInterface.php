<?php

declare(strict_types=1);

namespace App\Shared\Contracts\Accounting;

use App\Modules\Accounting\Application\Services\FiscalPeriodResolverService;
use Carbon\CarbonInterface;

/**
 * Ask Accounting whether a date falls inside a fiscal period whose books are shut.
 *
 * Plan CF CF-D3 / fiscal gate I-1. The return-note backdating guard has to key on
 * BOTH period tables, and `fiscal_periods` is Accounting's. Taxation cannot reach
 * `FiscalPeriodResolverService` directly — cross-module calls go through
 * `Shared/Contracts` (CLAUDE.md rule 6), and Taxation/Application →
 * Accounting/Application is a deptrac layer violation besides. This is that seam.
 *
 * ABSENT PERMITS, and the method name says so: it asks whether a period EXISTS and
 * is shut, never whether one is open. Those are different questions and the
 * difference is the whole point — `isDateInOpenPeriod()` returns false both for a
 * closed period and for no period at all, and gating on it would refuse every
 * return note on every tenant that has not configured fiscal periods.
 *
 * @see FiscalPeriodResolverService::isDateInClosedPeriod()
 */
interface FiscalPeriodLockReaderInterface
{
    /**
     * TRUE only when a `fiscal_periods` row covers the date AND its status is
     * Closed or Locked. Absence of any covering period returns FALSE.
     */
    public function isDateInClosedFiscalPeriod(string $companyId, CarbonInterface $date): bool;
}
