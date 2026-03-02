<?php

declare(strict_types=1);

namespace App\Modules\Promotion\Domain\ValueObjects;

use App\Modules\Promotion\Domain\Enums\DiscountAppliesTo;

/**
 * Represents a single discount line produced by a promotion.
 */
final readonly class PromotionDiscount
{
    public function __construct(
        public string $promotionId,
        public string $promotionName,
        public string $discountAmount,
        public DiscountAppliesTo $appliesTo,
        public ?string $targetProductId,
        public bool $isExclusive,
        public string $stackingGroup,
        public int $priority,
    ) {}
}
