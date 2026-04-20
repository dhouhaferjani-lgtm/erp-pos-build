<?php

declare(strict_types=1);

namespace App\Modules\Workshop\WorkOrder\Application\Commands;

use App\Modules\Workshop\WorkOrder\Domain\Enums\WorkOrderLineType;

/**
 * Add a single line to a WorkOrder. Use AddBundleCommand for bundle expansion.
 *
 * Polymorphic refs (product_id, service_id) are validated against line_type by
 * the line service; DB CHECK constraints enforce the rule at the storage layer.
 */
final readonly class AddLineCommand
{
    public function __construct(
        public string $work_order_id,
        public WorkOrderLineType $line_type,
        public ?string $product_id,
        public ?string $service_id,
        public string $display_name,
        public ?string $sku_or_code,
        public ?string $description,
        public string $quantity,
        public string $unit,
        public string $unit_price,
        public string $tax_rate,
        public string $discount_percent,
        public ?string $labor_hours_estimated,
        public ?string $assigned_technician_profile_id,
        public bool $is_customer_supplied,
    ) {}
}
