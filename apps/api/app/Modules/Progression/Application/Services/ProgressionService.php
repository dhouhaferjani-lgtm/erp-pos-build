<?php

declare(strict_types=1);

namespace App\Modules\Progression\Application\Services;

use App\Modules\Progression\Application\Contracts\GrowthAdvisorClientInterface;
use App\Modules\Progression\Application\DTOs\CompanyProfileData;
use App\Modules\Progression\Application\DTOs\MilestoneData;
use App\Modules\Progression\Application\DTOs\ModuleReadinessData;
use App\Modules\Progression\Application\DTOs\RecommendationData;

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
}
