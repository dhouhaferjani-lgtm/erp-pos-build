<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application\DTOs;

use App\Modules\Inventory\Domain\Enums\MovementGlKind;
use App\Modules\Inventory\Domain\Enums\MovementReason;

/**
 * Immutable inventory-to-GL hand-off. Quantities and cost are copied from the
 * persisted movement row; writers must not re-derive either value.
 */
final readonly class MovementGlContext
{
    /**
     * @param  numeric-string  $quantityBefore
     * @param  numeric-string  $quantityAfter
     * @param  numeric-string  $unitCost
     */
    public function __construct(
        public MovementGlKind $kind,
        public string $movementId,
        public string $companyId,
        public string $currencyCode,
        public MovementReason $reason,
        public string $quantityBefore,
        public string $quantityAfter,
        public string $unitCost,
        public ?string $sourceType,
        public ?string $sourceId,
        public \DateTimeImmutable $occurredAt,
        public \DateTimeImmutable $entryDate,
        public ?string $postedByUserId,
        public bool $isHistorical,
        public ?string $batchNumber = null,
        public ?string $productId = null,
    ) {}
}
