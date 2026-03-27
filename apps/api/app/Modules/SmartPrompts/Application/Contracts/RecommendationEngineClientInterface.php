<?php

declare(strict_types=1);

namespace App\Modules\SmartPrompts\Application\Contracts;

use App\Modules\SmartPrompts\Application\DTOs\RecommendationRequestData;

interface RecommendationEngineClientInterface
{
    /**
     * @return array<string, mixed>|null
     */
    public function getRecommendations(RecommendationRequestData $request, string $tenantId, string $companyId): ?array;

    public function isCircuitOpen(): bool;
}
