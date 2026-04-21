<?php

declare(strict_types=1);

namespace App\Modules\Workshop\WorkOrder\Domain\ValueObjects;

/**
 * Structured description of a part that needs to be procured for a WorkOrder.
 *
 * Carried by `WorkOrderWaitingParts` and `WorkOrderPartsNeeded` events for
 * consumption by the future procurement agent. All fields are immutable.
 *
 * `quantity` is a scale-preserving numeric-string (never float). `urgency`
 * is a free-form code for now (`low | medium | high | critical`); a dedicated
 * enum can be added later if procurement workflows require it.
 */
final readonly class PartNeed
{
    public function __construct(
        public string $product_id,
        public string $display_name,
        public string $quantity,
        public string $unit,
        public string $vehicle_id,
        public string $vehicle_display,
        public string $urgency,
        public ?string $notes,
    ) {}
}
