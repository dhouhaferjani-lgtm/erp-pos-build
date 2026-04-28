<?php

declare(strict_types=1);

namespace App\Modules\Workshop\WorkOrder\Application\DTOs;

use App\Modules\Workshop\WorkOrder\Domain\Enums\WorkOrderStatus;
use App\Modules\Workshop\WorkOrder\Domain\Enums\WorkOrderType;
use App\Modules\Workshop\WorkOrder\Domain\WorkOrder;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Slim projection used by the WorkOrder list + daily-board endpoints.
 * Excludes line items + audit rows for page-load efficiency.
 *
 * Financial fields respect the `work-orders.view_financials` permission
 * — callers without the permission should receive these as `null` via
 * the controller's redaction logic (see Task 17 FinancialRedactionTest).
 */
#[TypeScript]
final class WorkOrderListItemData extends Data
{
    public function __construct(
        public string $id,
        public string $work_order_number,
        public WorkOrderStatus $status,
        public WorkOrderType $type,
        public string $customer_display_name,
        public string $vehicle_display_name,
        public ?string $primary_technician_display_name,
        public ?string $scheduled_start_at,
        public ?string $promised_at,
        public string $currency,
        public ?string $estimated_grand_total,
        public ?string $actual_grand_total,
        public string $created_at,
    ) {}

    public static function fromModel(WorkOrder $wo): self
    {
        return new self(
            id: $wo->id,
            work_order_number: $wo->work_order_number,
            status: $wo->status,
            type: $wo->type,
            customer_display_name: (string) ($wo->customer->name ?? ''),
            vehicle_display_name: self::vehicleDisplay($wo),
            primary_technician_display_name: $wo->primaryTechnician?->user->name,
            scheduled_start_at: $wo->scheduled_start_at?->toIso8601String(),
            promised_at: $wo->promised_at?->toIso8601String(),
            currency: $wo->currency,
            estimated_grand_total: $wo->estimated_grand_total,
            actual_grand_total: $wo->actual_grand_total,
            created_at: ($wo->created_at ?? now())->toIso8601String(),
        );
    }

    private static function vehicleDisplay(WorkOrder $wo): string
    {
        $v = $wo->vehicle;
        $parts = array_filter([
            $v->getAttribute('brand'),
            $v->getAttribute('model'),
            $v->getAttribute('license_plate'),
        ], static fn ($x): bool => is_string($x) && $x !== '');

        return implode(' ', $parts);
    }
}
