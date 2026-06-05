<?php

declare(strict_types=1);

namespace App\Modules\BatchExpiry\Domain\DTOs;

/**
 * Result of an atomic FEFO consumption pass.
 *
 * `$shortfall` is a decimal string (4dp). It is always '0.0000' when strict
 * fulfillment succeeds (the strict path throws instead of returning a shortfall).
 * A non-zero shortfall is only possible from a non-strict (advisory) consume.
 *
 * Lives in the Domain tier so the Domain FEFO service may return it without
 * crossing the Domain → Application deptrac boundary.
 */
final readonly class BatchConsumptionResultDTO
{
    /**
     * @param  array<int, ConsumedBatchDTO>  $consumed
     * @param  numeric-string  $shortfall  Unfulfilled quantity (decimal string, 4dp)
     */
    public function __construct(
        public array $consumed,
        public string $shortfall,
    ) {}

    public function hasShortfall(): bool
    {
        return bccomp($this->shortfall, '0', 4) > 0;
    }

    /**
     * Total quantity consumed across all batches (decimal string, 4dp).
     *
     * @return numeric-string
     */
    public function totalConsumed(): string
    {
        $total = '0';
        foreach ($this->consumed as $batch) {
            $total = bcadd($total, $batch->quantityConsumed, 4);
        }

        /** @var numeric-string $total */
        return $total;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'consumed' => array_map(
                static fn (ConsumedBatchDTO $c): array => $c->toArray(),
                $this->consumed,
            ),
            'shortfall' => $this->shortfall,
            'total_consumed' => $this->totalConsumed(),
            'has_shortfall' => $this->hasShortfall(),
        ];
    }
}
