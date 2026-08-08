<?php

declare(strict_types=1);

namespace App\Modules\BatchExpiry\Application\DTOs;

use App\Shared\Domain\QuantityScale;

readonly class BatchSuggestionResultDTO
{
    /**
     * @param  array<BatchSuggestionDTO>  $suggestions
     * @param  numeric-string  $shortfall  Unfulfilled remainder at the canonical
     *                                     quantity scale (4dp), "0.0000" when the
     *                                     request was fully covered.
     */
    public function __construct(
        public array $suggestions,
        public bool $fullyFulfilled,
        public string $shortfall,
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
            'total_quantity_suggested' => $this->getSuggestedQuantity(),
        ];
    }

    public function hasShortfall(): bool
    {
        return bccomp($this->shortfall, '0', QuantityScale::SCALE) > 0;
    }

    /**
     * Total suggested across all lots.
     *
     * Summed with bcadd rather than array_sum: native float addition of scale-4
     * quantities drifts (0.7 + 0.4 !== 1.1 in IEEE 754) and this value is
     * serialized directly onto the wire.
     *
     * @return numeric-string
     */
    public function getSuggestedQuantity(): string
    {
        /** @var numeric-string $total */
        $total = bcadd('0', '0', QuantityScale::SCALE);

        foreach ($this->suggestions as $suggestion) {
            $total = bcadd($total, $suggestion->quantity, QuantityScale::SCALE);
        }

        return $total;
    }
}
