<?php

declare(strict_types=1);

namespace App\Modules\POS\Domain\ValueObjects;

/**
 * The resolved result of all discount sources after stacking rules.
 */
final readonly class DiscountBreakdown
{
    /**
     * @param  array<int, DiscountLine>  $lines  All resolved discount lines
     * @param  numeric-string  $totalTransactionDiscount
     * @param  array<string, numeric-string>  $lineDiscounts  Keyed by product_id
     */
    public function __construct(
        public array $lines,
        public string $totalTransactionDiscount,
        public array $lineDiscounts = [],
    ) {}

    /**
     * @return numeric-string
     */
    public function totalDiscount(int $scale = 3): string
    {
        /** @var numeric-string $total */
        $total = $this->totalTransactionDiscount;
        foreach ($this->lineDiscounts as $amount) {
            $total = bcadd($total, $amount, $scale);
        }

        return $total;
    }

    /**
     * Convert to array for JSONB storage.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'lines' => array_map(fn (DiscountLine $l) => [
                'source' => $l->source,
                'stacking_group' => $l->stackingGroup,
                'is_exclusive' => $l->isExclusive,
                'priority' => $l->priority,
                'discount_amount' => $l->discountAmount,
                'label' => $l->label,
                'reference_id' => $l->referenceId,
                'applies_to' => $l->appliesTo,
            ], $this->lines),
            'total_transaction_discount' => $this->totalTransactionDiscount,
            'line_discounts' => $this->lineDiscounts,
        ];
    }
}
