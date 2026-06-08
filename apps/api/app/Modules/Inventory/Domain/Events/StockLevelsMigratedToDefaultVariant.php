<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain\Events;

use App\Shared\Domain\Events\DomainEvent;

/**
 * Event raised after all pre-existing product-level stock state (variant_id NULL)
 * has been atomically migrated to the newly-created default variant.
 *
 * Tables migrated: stock_levels, stock_reservations (open), product_batches (active),
 * recipe_lines (component_type = 'product').
 * Table NOT touched: stock_movements (append-only audit log).
 */
final class StockLevelsMigratedToDefaultVariant extends DomainEvent
{
    public function __construct(
        public readonly string $productId,
        public readonly string $defaultVariantId,
    ) {
        parent::__construct($productId);
    }

    public function getEventName(): string
    {
        return 'inventory.stock_levels.migrated_to_default_variant';
    }
}
