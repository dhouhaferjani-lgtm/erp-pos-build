<?php

declare(strict_types=1);

namespace App\Modules\SmartPrompts\Application\DTOs;

use App\Modules\SmartPrompts\Domain\Enums\RecommendationContext;
use App\Shared\Domain\Enums\SkinType;

final readonly class RecommendationRequestData
{
    /**
     * @param  list<string>  $productIds
     */
    public function __construct(
        public array $productIds,
        public RecommendationContext $context,
        public int $limit = 5,
        public ?SkinType $skinType = null,
        public ?string $customerId = null,
        public string $vertical = 'parapharmacy',
        public string $country = 'FR',
    ) {}
}
