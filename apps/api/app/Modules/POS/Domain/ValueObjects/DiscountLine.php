<?php

declare(strict_types=1);

namespace App\Modules\POS\Domain\ValueObjects;

/**
 * Represents a single discount candidate from any source.
 */
final readonly class DiscountLine
{
    /**
     * @param  'manual'|'promotion'|'coupon'|'loyalty'  $source
     * @param  numeric-string  $discountAmount
     * @param  'transaction'|string  $appliesTo  'transaction' or 'line:{product_id}'
     */
    public function __construct(
        public string $source,
        public string $stackingGroup,
        public bool $isExclusive,
        public int $priority,
        public string $discountAmount,
        public string $label,
        public ?string $referenceId,
        public string $appliesTo = 'transaction',
    ) {}
}
