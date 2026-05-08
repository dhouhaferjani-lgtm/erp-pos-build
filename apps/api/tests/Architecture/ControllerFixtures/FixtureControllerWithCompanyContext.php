<?php

declare(strict_types=1);

namespace Tests\Architecture\ControllerFixtures;

use App\Modules\Company\Services\CompanyContext;

/**
 * Positive-control fixture for ControllerTenantContextTest, branch (b).
 *
 * The handle() method body contains the substring `CompanyContext` (via
 * type-hint and call) so the heuristic accepts it.
 */
final class FixtureControllerWithCompanyContext
{
    public function __construct(
        private readonly CompanyContext $companyContext,
    ) {}

    public function handle(): array
    {
        $companyId = $this->companyContext->requireCompanyId();

        return ['company_id' => $companyId];
    }
}
