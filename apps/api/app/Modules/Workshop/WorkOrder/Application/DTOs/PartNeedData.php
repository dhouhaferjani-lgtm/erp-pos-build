<?php

declare(strict_types=1);

namespace App\Modules\Workshop\WorkOrder\Application\DTOs;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Wire DTO for a structured part need carried by WorkOrderPartsNeeded /
 * WorkOrderWaitingParts events. Consumed by the future procurement agent.
 */
#[TypeScript]
final class PartNeedData extends Data
{
    public function __construct(
        public string $work_order_id,
        public string $work_order_line_id,
        public ?string $product_id,
        public string $display_name,
        public string $quantity,
        public string $unit,
        public string $vehicle_id,
        public string $vehicle_display_name,
        public ?string $preferred_brand,
        public ?string $urgency,
        public ?string $notes,
    ) {}
}
