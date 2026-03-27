<?php

declare(strict_types=1);

namespace App\Modules\SmartPrompts\Application\Services;

use App\Modules\SmartPrompts\Application\Contracts\RecommendationEngineClientInterface;
use App\Modules\SmartPrompts\Application\DTOs\RecommendationRequestData;
use App\Modules\SmartPrompts\Application\DTOs\RecommendationResponseData;

final class SmartPromptsService
{
    public function __construct(
        private readonly RecommendationEngineClientInterface $client,
    ) {}

    public function getRecommendations(
        RecommendationRequestData $request,
        string $tenantId,
        string $companyId,
    ): RecommendationResponseData {
        if ($this->client->isCircuitOpen()) {
            return new RecommendationResponseData(
                recommendations: [],
                context: $request->context->value,
                generatedAt: now()->toIso8601String(),
            );
        }

        $response = $this->client->getRecommendations($request, $tenantId, $companyId);

        if ($response === null) {
            return new RecommendationResponseData(
                recommendations: [],
                context: $request->context->value,
                generatedAt: now()->toIso8601String(),
            );
        }

        return RecommendationResponseData::fromApiResponse($response);
    }
}
