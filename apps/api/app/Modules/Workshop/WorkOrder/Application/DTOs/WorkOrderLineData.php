<?php

declare(strict_types=1);

namespace App\Modules\Workshop\WorkOrder\Application\DTOs;

use App\Modules\Workshop\WorkOrder\Domain\Enums\CoreDepositStatus;
use App\Modules\Workshop\WorkOrder\Domain\Enums\WorkOrderLineType;
use App\Modules\Workshop\WorkOrder\Domain\WorkOrderLine;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Wire DTO for a WorkOrderLine row. Snake_case per Spec §7.3.
 */
#[TypeScript]
final class WorkOrderLineData extends Data
{
    public function __construct(
        public string $id,
        public string $work_order_id,
        public WorkOrderLineType $line_type,
        public int $display_order,
        public ?string $product_id,
        public ?string $service_id,
        public ?string $service_bundle_id,
        public string $display_name,
        public ?string $sku_or_code,
        public ?string $description,
        public string $quantity,
        public string $unit,
        public string $unit_price,
        public string $tax_rate,
        public string $discount_percent,
        public string $line_total_excl_tax,
        public string $line_total_tax,
        public string $line_total_incl_tax,
        public ?string $labor_hours_estimated,
        public ?string $labor_hours_actual,
        public ?string $assigned_technician_profile_id,
        public ?string $stock_reservation_id,
        public bool $is_customer_supplied,
        public ?string $core_deposit_partner_id,
        public ?CoreDepositStatus $core_deposit_status,
        public ?string $core_return_of_line_id,
        public ?string $from_bundle_id,
        public bool $is_bundle_informational,
        public bool $is_completed,
        public ?string $completed_at,
    ) {}

    public static function fromModel(WorkOrderLine $line): self
    {
        return new self(
            id: $line->id,
            work_order_id: $line->work_order_id,
            line_type: $line->line_type,
            display_order: $line->display_order,
            product_id: $line->product_id,
            service_id: $line->service_id,
            service_bundle_id: $line->service_bundle_id,
            display_name: $line->display_name,
            sku_or_code: $line->sku_or_code,
            description: $line->description,
            quantity: $line->quantity,
            unit: $line->unit,
            unit_price: $line->unit_price,
            tax_rate: $line->tax_rate,
            discount_percent: $line->discount_percent,
            line_total_excl_tax: $line->line_total_excl_tax,
            line_total_tax: $line->line_total_tax,
            line_total_incl_tax: $line->line_total_incl_tax,
            labor_hours_estimated: $line->labor_hours_estimated,
            labor_hours_actual: $line->labor_hours_actual,
            assigned_technician_profile_id: $line->assigned_technician_profile_id,
            stock_reservation_id: $line->stock_reservation_id,
            is_customer_supplied: $line->is_customer_supplied,
            core_deposit_partner_id: $line->core_deposit_partner_id,
            core_deposit_status: $line->core_deposit_status,
            core_return_of_line_id: $line->core_return_of_line_id,
            from_bundle_id: $line->from_bundle_id,
            is_bundle_informational: $line->is_bundle_informational,
            is_completed: $line->is_completed,
            completed_at: $line->completed_at?->toIso8601String(),
        );
    }
}
