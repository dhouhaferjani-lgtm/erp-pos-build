<?php

declare(strict_types=1);

namespace App\Modules\SmartPrompts\Application\DTOs;

final readonly class RecommendationResponseData
{
    /**
     * @param list<RecommendationData> $recommendations
     */
    public function __construct(
        public array $recommendations,
        public string $context,
        public string $generatedAt,
    ) {}

    /**
     * @param array<string, mixed> $response
     */
    public static function fromApiResponse(array $response): self
    {
        $recommendations = array_map(
            static fn (array $item) => RecommendationData::fromApiResponse($item),
            $response['recommendations'] ?? [],
        );

        return new self(
            recommendations: $recommendations,
            context: (string) ($response['context'] ?? 'cart'),
            generatedAt: (string) ($response['generated_at'] ?? ''),
        );
    }
}
