<?php

declare(strict_types=1);

namespace App\Modules\BatchExpiry\Application\DTOs;

readonly class BatchSuggestionResultDTO
{
    /**
     * @param  array<BatchSuggestionDTO>  $suggestions
     */
    public function __construct(
        public array $suggestions,
        public bool $fullyFulfilled,
        public float $shortfall,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'suggestions' => array_map(
                fn (BatchSuggestionDTO $suggestion) => $suggestion->toArray(),
                $this->suggestions
            ),
            'fully_fulfilled' => $this->fullyFulfilled,
            'shortfall' => $this->shortfall,
            'total_quantity_suggested' => array_sum(
                array_map(fn (BatchSuggestionDTO $s) => $s->quantity, $this->suggestions)
            ),
        ];
    }

    public function hasShortfall(): bool
    {
        return $this->shortfall > 0;
    }

    public function getSuggestedQuantity(): float
    {
        return array_sum(
            array_map(fn (BatchSuggestionDTO $s) => $s->quantity, $this->suggestions)
        );
    }
}
