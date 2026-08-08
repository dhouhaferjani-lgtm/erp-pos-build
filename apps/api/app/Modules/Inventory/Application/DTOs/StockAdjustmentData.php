<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application\DTOs;

use App\Modules\Inventory\Domain\StockAdjustment;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * A stock-correction document as the frontend consumes it (DPA V7 / T7).
 *
 * `StockAdjustmentStatus` needs NO `#[TypeScript]` attribute of its own —
 * config/typescript-transformer.php registers EnumCollector, which is why
 * unattributed enums like CountingStatus already appear in generated.d.ts.
 *
 * `correction_id` is the INVERSE of `corrects_adjustment_id`, which §3 stores
 * only one side of. F4's `canCorrect` and ADJUSTMENT_ALREADY_CORRECTED's
 * `details.correction_id` both consume it, and it is safely at-most-one because
 * of the `stock_adjustments_corrects_unique` partial index.
 */
#[TypeScript]
final class StockAdjustmentData extends Data
{
    /**
     * @param  list<StockAdjustmentLineData>  $lines
     */
    public function __construct(
        public string $id,
        public string $tenant_id,
        public string $company_id,
        public ?string $adjustment_number,
        public string $status,
        public ?string $note,
        public string $location_id,
        public ?string $location_name,
        public string $occurred_at,
        public ?string $idempotency_key,
        public string $created_by_user_id,
        public ?string $created_by_name,
        public ?string $posted_by_user_id,
        public ?string $posted_by_name,
        public ?string $cancelled_by_user_id,
        public ?string $cancelled_by_name,
        public ?string $posted_at,
        public ?string $cancelled_at,
        public ?string $cancellation_reason,
        public ?string $stale_acknowledged_at,
        public ?string $stale_acknowledged_by_user_id,
        public ?string $reservations_ignored_at,
        public ?string $reservations_ignored_by_user_id,
        public ?string $corrects_adjustment_id,
        public ?string $correction_id,
        public array $lines,
        public int $lines_count,
        public string $created_at,
        public string $updated_at,
    ) {}

    public static function fromModel(StockAdjustment $adjustment, bool $withLines = true): self
    {
        if ($withLines && ! $adjustment->relationLoaded('lines')) {
            $adjustment->load('lines.product.unitOfMeasure', 'lines.batch');
        }

        $lines = [];
        if ($withLines) {
            foreach ($adjustment->lines as $line) {
                $lines[] = StockAdjustmentLineData::fromModel($line);
            }
        }

        return new self(
            id: $adjustment->id,
            tenant_id: $adjustment->tenant_id,
            company_id: $adjustment->company_id,
            adjustment_number: $adjustment->adjustment_number,
            status: $adjustment->status->value,
            note: $adjustment->note,
            location_id: $adjustment->location_id,
            location_name: $adjustment->relationLoaded('location') ? ($adjustment->location->name ?? null) : null,
            occurred_at: $adjustment->occurred_at->toIso8601String(),
            idempotency_key: $adjustment->idempotency_key,
            created_by_user_id: $adjustment->created_by_user_id,
            created_by_name: $adjustment->relationLoaded('createdBy') ? ($adjustment->createdBy->name ?? null) : null,
            posted_by_user_id: $adjustment->posted_by_user_id,
            posted_by_name: $adjustment->relationLoaded('postedBy') ? ($adjustment->postedBy->name ?? null) : null,
            cancelled_by_user_id: $adjustment->cancelled_by_user_id,
            cancelled_by_name: $adjustment->relationLoaded('cancelledBy') ? ($adjustment->cancelledBy->name ?? null) : null,
            posted_at: $adjustment->posted_at?->toIso8601String(),
            cancelled_at: $adjustment->cancelled_at?->toIso8601String(),
            cancellation_reason: $adjustment->cancellation_reason,
            stale_acknowledged_at: $adjustment->stale_acknowledged_at?->toIso8601String(),
            stale_acknowledged_by_user_id: $adjustment->stale_acknowledged_by_user_id,
            reservations_ignored_at: $adjustment->reservations_ignored_at?->toIso8601String(),
            reservations_ignored_by_user_id: $adjustment->reservations_ignored_by_user_id,
            corrects_adjustment_id: $adjustment->corrects_adjustment_id,
            correction_id: $adjustment->correction()->value('id'),
            lines: $lines,
            lines_count: count($lines),
            created_at: $adjustment->created_at?->toIso8601String() ?? '',
            updated_at: $adjustment->updated_at?->toIso8601String() ?? '',
        );
    }
}
