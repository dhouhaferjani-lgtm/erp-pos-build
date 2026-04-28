<?php

declare(strict_types=1);

namespace App\Modules\Progression\Application\Contracts;

interface GrowthAdvisorClientInterface
{
    /**
     * @return array<string, mixed>|null
     */
    public function getCompanyProfile(string $companyId): ?array;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>|null
     */
    public function registerCompany(array $data): ?array;

    /**
     * @return list<array<string, mixed>>
     */
    public function getMilestones(string $companyId): array;

    /**
     * @return list<array<string, mixed>>
     */
    public function getModules(string $companyId): array;

    /**
     * @return array<string, mixed>|null
     */
    public function activateModule(string $companyId, string $moduleId): ?array;

    /**
     * @return list<array<string, mixed>>
     */
    public function getRecommendations(string $companyId): array;

    /**
     * @return array<string, mixed>|null
     */
    public function acceptRecommendation(string $companyId, string $recommendationId): ?array;

    /**
     * @return array<string, mixed>|null
     */
    public function dismissRecommendation(string $companyId, string $recommendationId): ?array;

    public function isCircuitOpen(): bool;
}
