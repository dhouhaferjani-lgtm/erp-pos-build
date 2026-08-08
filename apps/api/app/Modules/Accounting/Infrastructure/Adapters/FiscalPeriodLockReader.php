<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Infrastructure\Adapters;

use App\Modules\Accounting\Application\Services\FiscalPeriodResolverService;
use App\Shared\Contracts\Accounting\FiscalPeriodLockReaderInterface;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Accounting's side of the {@see FiscalPeriodLockReaderInterface} seam.
 *
 * Plan CF CF-D3. A pure delegation to the existing, already-tested
 * `FiscalPeriodResolverService::isDateInClosedPeriod()` — this class adds no
 * policy of its own on purpose. Duplicating the query here is how the two
 * verdicts would drift; the resolver's docblock carries the absent-permits
 * reasoning and the order-independence fix (a date covered by BOTH an Open and a
 * Closed period must resolve to closed), and both must keep applying.
 *
 * Sits in Infrastructure because it is an adapter over an Application service:
 * ModuleInfrastructure may depend on ModuleApplication, whereas Taxation's
 * Application tier may not.
 */
final class FiscalPeriodLockReader implements FiscalPeriodLockReaderInterface
{
    public function __construct(
        private readonly FiscalPeriodResolverService $resolver,
    ) {}

    public function isDateInClosedFiscalPeriod(string $companyId, CarbonInterface $date): bool
    {
        return $this->resolver->isDateInClosedPeriod($companyId, Carbon::instance($date->toDateTimeImmutable()));
    }
}
