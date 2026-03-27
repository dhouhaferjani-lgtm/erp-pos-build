<?php

declare(strict_types=1);

namespace App\Modules\SmartPrompts\Application\DTOs;

final readonly class RecommendationData
{
    public function __construct(
        public string $productId,
        public string $productName,
        public string $score,
        public string $reason,
        public string $strategy,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromApiResponse(array $data): self
    {
        return new self(
            productId: (string) $data['product_id'],
            productName: (string) $data['product_name'],
            score: (string) $data['score'],
            reason: (string) $data['reason'],
            strategy: (string) $data['strategy'],
        );
    }
}
