<?php

declare(strict_types=1);

namespace App\Modules\Workshop\WorkOrder\Application\Commands;

/**
 * Modify fields on an existing line. Only the fields typically edited after
 * initial capture are exposed; structural fields (line_type, polymorphic refs)
 * are immutable — delete and re-add instead.
 */
final readonly class UpdateLineCommand
{
    public function __construct(
        public string $work_order_id,
        public string $line_id,
        public ?string $display_name,
        public ?string $description,
        public ?string $quantity,
        public ?string $unit_price,
        public ?string $tax_rate,
        public ?string $discount_percent,
        public ?string $labor_hours_actual,
        public ?string $assigned_technician_profile_id,
        public ?bool $is_completed,
    ) {}
}
