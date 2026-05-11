<?php

declare(strict_types=1);

namespace App\Modules\Progression\Application\Services;

use App\Modules\Progression\Application\Contracts\GrowthAdvisorClientInterface;
use App\Modules\Progression\Application\DTOs\CompanyProfileData;
use App\Modules\Progression\Application\DTOs\MilestoneData;
use App\Modules\Progression\Application\DTOs\ModuleReadinessData;
use App\Modules\Progression\Application\DTOs\RecommendationData;
use RuntimeException;

/**
 * api.module-gating cluster — Growth Advisor response-id contract.
 *
 * When the upstream Growth Advisor response shape echoes the company id
 * (`id` field) and/or tenant id (`tenant_id` field), this service
 * asserts those values match the request's expected company/tenant.
 * A mismatch is the canonical "cross-tenant data leak" shape — fail
 * loud (RuntimeException) so the controller's error handler returns
 * a real 500 rather than silently returning the wrong tenant's data.
 *
 * Two response shapes carry the echo:
 *   - getCompanyProfile($companyId) → CompanyProfileData (id, tenant_id)
 *   - registerCompany($data)        → CompanyProfileData (id, tenant_id)
 *
 * Six don't (URL-bound only — mis-routing detectable only Growth-
 * Advisor-side):
 *   - getMilestones, getModules, activateModule, getRecommendations,
 *     acceptRecommendation, dismissRecommendation
 *
 * The guard distinguishes legitimate "no echo" responses from
 * mismatching echoes — only mismatches throw.
 */
final class ProgressionService
{
    public function __construct(
        private readonly GrowthAdvisorClientInterface $client,
    ) {}

    public function getProfile(string $companyId): ?CompanyProfileData
    {
        $response = $this->client->getCompanyProfile($companyId);

        if ($response === null) {
            return null;
        }

        $this->assertResponseCompanyMatches($response, $companyId, 'getCompanyProfile');

        return CompanyProfileData::fromApiResponse($response);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function registerCompany(array $data): ?CompanyProfileData
    {
        $response = $this->client->registerCompany($data);

        if ($response === null) {
            return null;
        }

        $expectedCompanyId = isset($data['company_id']) ? (string) $data['company_id'] : null;
        $expectedTenantId = isset($data['tenant_id']) ? (string) $data['tenant_id'] : null;

        if ($expectedCompanyId !== null) {
            $this->assertResponseCompanyMatches($response, $expectedCompanyId, 'registerCompany');
        }

        if ($expectedTenantId !== null) {
            $this->assertResponseTenantMatches($response, $expectedTenantId, 'registerCompany');
        }

        return CompanyProfileData::fromApiResponse($response);
    }

    /**
     * @return list<MilestoneData>
     */
    public function getMilestones(string $companyId): array
    {
        $response = $this->client->getMilestones($companyId);

        return array_map(
            static fn (array $item): MilestoneData => MilestoneData::fromApiResponse($item),
            $response,
        );
    }

    /**
     * @return list<ModuleReadinessData>
     */
    public function getModules(string $companyId): array
    {
        $response = $this->client->getModules($companyId);

        return array_map(
            static fn (array $item): ModuleReadinessData => ModuleReadinessData::fromApiResponse($item),
            $response,
        );
    }

    public function activateModule(string $companyId, string $moduleId): ?ModuleReadinessData
    {
        $response = $this->client->activateModule($companyId, $moduleId);

        if ($response === null) {
            return null;
        }

        return ModuleReadinessData::fromApiResponse($response);
    }

    /**
     * @return list<RecommendationData>
     */
    public function getRecommendations(string $companyId): array
    {
        $response = $this->client->getRecommendations($companyId);

        $dtos = array_map(
            static fn (array $item): RecommendationData => RecommendationData::fromApiResponse($item),
            $response,
        );

        usort($dtos, static fn (RecommendationData $a, RecommendationData $b): int => $a->priority->sortOrder() <=> $b->priority->sortOrder());

        return $dtos;
    }

    public function acceptRecommendation(string $companyId, string $recommendationId): ?RecommendationData
    {
        $response = $this->client->acceptRecommendation($companyId, $recommendationId);

        if ($response === null) {
            return null;
        }

        return RecommendationData::fromApiResponse($response);
    }

    public function dismissRecommendation(string $companyId, string $recommendationId): ?RecommendationData
    {
        $response = $this->client->dismissRecommendation($companyId, $recommendationId);

        if ($response === null) {
            return null;
        }

        return RecommendationData::fromApiResponse($response);
    }

    public function isAvailable(): bool
    {
        return ! $this->client->isCircuitOpen();
    }

    /**
     * Assert that the upstream response's company `id` echo matches the
     * request's expected company id. Distinguishes "no echo" (legitimate
     * for response shapes that don't carry one) from "echo mismatch"
     * (real cross-tenant data leak shape — throws fail-loud).
     *
     * @param  array<string, mixed>  $response
     */
    private function assertResponseCompanyMatches(array $response, string $expectedCompanyId, string $context): void
    {
        $responseCompanyId = $response['id'] ?? null;

        if ($responseCompanyId === null) {
            return; // legitimate no-echo case — URL-bound only
        }

        if ((string) $responseCompanyId !== $expectedCompanyId) {
            throw new RuntimeException(sprintf(
                'Growth Advisor response company id mismatch in %s: expected %s, got %s. Possible cross-tenant data leak.',
                $context,
                $expectedCompanyId,
                (string) $responseCompanyId,
            ));
        }
    }

    /**
     * Assert that the upstream response's `tenant_id` echo matches the
     * request's expected tenant id. Same shape as
     * assertResponseCompanyMatches; tenant binding is the second
     * defense-in-depth check on CompanyProfileData responses.
     *
     * @param  array<string, mixed>  $response
     */
    private function assertResponseTenantMatches(array $response, string $expectedTenantId, string $context): void
    {
        $responseTenantId = $response['tenant_id'] ?? null;

        if ($responseTenantId === null) {
            return; // legitimate no-echo case
        }

        if ((string) $responseTenantId !== $expectedTenantId) {
            throw new RuntimeException(sprintf(
                'Growth Advisor response tenant id mismatch in %s: expected %s, got %s. Possible cross-tenant data leak.',
                $context,
                $expectedTenantId,
                (string) $responseTenantId,
            ));
        }
    }
}
