<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application\DTOs;

use App\Modules\Inventory\Domain\Enums\TransferCostDistribution;
use App\Modules\Inventory\Domain\Enums\TransferType;

/**
 * Input DTO for creating + (optionally) immediately initiating a stock transfer.
 *
 * The service splits this into two steps internally — create the draft, then
 * move stock — so the caller can pass `autoInitiate=false` to leave the
 * transfer in `draft` for later send.
 */
final class InitiateTransferData
{
    /**
     * @param  list<InitiateTransferLineData>  $lines
     * @param  numeric-string  $transferCost
     * @param  bool  $autoAllocateBatchesFefo  When true, any batch-tracked line
     *                                         that carries no explicit batch allocations is auto-allocated from the
     *                                         source location's sellable batches earliest-expiry-first (FEFO) before
     *                                         the transfer is issued. Defaults false so existing callers — which must
     *                                         supply their own allocations — are unaffected. Callers that cannot
     *                                         compute batch splits themselves (e.g. replenishment fulfilment, which
     *                                         must not reach into batch tables) opt in.
     */
    public function __construct(
        public readonly string $tenantId,
        public readonly string $companyId,
        public readonly string $sourceLocationId,
        public readonly string $destinationLocationId,
        public readonly string $initiatedByUserId,
        public readonly array $lines,
        public readonly ?string $transferNumber = null,
        public readonly TransferType $transferType = TransferType::Intracompany,
        public readonly ?string $notes = null,
        public readonly string $transferCost = '0',
        public readonly ?string $transferCostLabel = null,
        public readonly TransferCostDistribution $transferCostDistribution = TransferCostDistribution::ProRataValue,
        public readonly ?string $idempotencyKey = null,
        public readonly bool $autoAllocateBatchesFefo = false,
    ) {}
}
