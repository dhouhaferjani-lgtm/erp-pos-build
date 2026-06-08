<?php

declare(strict_types=1);

namespace App\Modules\BatchExpiry\Domain\Events;

use App\Modules\BatchExpiry\Domain\DTOs\ConsumedBatchDTO;
use App\Shared\Domain\Events\DomainEvent;

/**
 * Event raised after an atomic FEFO consume has committed.
 *
 * V1. Dispatched via DB::afterCommit so subscribers only ever see fully
 * committed, durable consumption. Immutable per Agent Rule 8 — create a V2
 * rather than reshaping this once it is in use.
 */
final class BatchStockConsumed extends DomainEvent
{
    /**
     * @param  array<int, ConsumedBatchDTO>  $consumed
     */
    public function __construct(
        public readonly string $productId,
        public readonly ?string $variantId,
        public readonly string $locationId,
        public readonly array $consumed,
    ) {
        parent::__construct($productId);
    }

    public function getEventName(): string
    {
        return 'batch_expiry.stock.consumed';
    }
}
