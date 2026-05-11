<?php

declare(strict_types=1);

namespace Tests\Architecture\ControllerFixtures;

use App\Modules\Company\Services\CompanyContext;

/**
 * Positive-control fixture for ControllerTenantContextTest, branch (b2).
 *
 * The show() method body itself does NOT contain any tenant-scoping
 * heuristic substring — it delegates to a private helper. The class
 * body, however, has CompanyContext as a constructor dependency, so the
 * class-level fallback heuristic admits it.
 *
 * This pins the b2 branch — if a future change weakens or removes the
 * class-level fallback, this fixture's test fails BEFORE the production
 * tenant-scoped controllers (BatchController, WorkOrderTransitionController,
 * etc.) flood the failure list.
 */
final class FixtureControllerWithClassLevelCompanyContext
{
    public function __construct(
        private readonly CompanyContext $companyContext,
    ) {}

    public function show(string $uuid): array
    {
        $resource = $this->resolveResource($uuid);

        return ['ok' => true, 'resource' => $resource];
    }

    private function resolveResource(string $uuid): array
    {
        $companyId = $this->companyContext->requireCompanyId();

        return ['uuid' => $uuid, 'company_id' => $companyId];
    }
}
