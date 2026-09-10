<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application\DTOs;

use App\Modules\Inventory\Domain\Enums\TransferCloseDisposition;
use App\Modules\Inventory\Domain\Enums\TransferCostDistribution;
use App\Modules\Inventory\Domain\Enums\TransferDiscrepancyReason;
use App\Modules\Inventory\Domain\Enums\TransferStatus;
use App\Modules\Inventory\Domain\Enums\TransferType;
use App\Modules\Inventory\Domain\StockTransfer;
use App\Modules\Inventory\Domain\StockTransferLine;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * The FULL transfer projection (spec §5.3). Built ONLY by TransferPayloadBuilder.
 *
 * Members 1-24 are the exact shape StockTransferController::formatTransfer()
 * emits today (apps/api/app/Modules/Inventory/Presentation/Controllers/StockTransferController.php:282-340),
 * key for key and type for type, so S1 changes no existing wire member. Members
 * 25-33 are the T-2 additions of §5.3: the close columns, the freight residual,
 * the receipt history and the lines.
 *
 * `lines` is NULL on the list route and a list on the show route: today's
 * formatTransfer OMITS the key entirely when $includeLines is false, and a Spatie
 * Data property always serialises, so the list body gains one member with the
 * value null. That is a deliberate, stated addition (a null means "not loaded",
 * never "no lines"); no existing member changes and nothing is removed.
 */
#[TypeScript]
final class StockTransferData extends Data
{
    /**
     * @param  numeric-string  $transfer_cost
     * @param  numeric-string  $freight_uncapitalized
     * @param  list<TransferReconciliationReceiptData>  $receipts
     * @param  list<StockTransferLineData>|null  $lines
     */
    public function __construct(
        public readonly string $id,
        public readonly string $transfer_number,
        public readonly TransferType $transfer_type,
        public readonly TransferStatus $status,
        public readonly string $source_location_id,
        public readonly ?string $source_location_name,
        public readonly string $destination_location_id,
        public readonly ?string $destination_location_name,
        public readonly ?string $notes,
        public readonly string $transfer_cost,
        public readonly ?string $transfer_cost_label,
        public readonly TransferCostDistribution $transfer_cost_distribution,
        public readonly string $initiated_by_user_id,
        public readonly ?string $initiated_by_name,
        public readonly ?string $completed_by_user_id,
        public readonly ?string $completed_by_name,
        public readonly ?string $cancelled_by_user_id,
        public readonly ?string $cancelled_by_name,
        public readonly ?string $initiated_at,
        public readonly ?string $completed_at,
        public readonly ?string $cancelled_at,
        public readonly ?string $cancellation_reason,
        public readonly ?string $created_at,
        public readonly ?string $updated_at,
        public readonly ?string $closed_by_user_id,
        public readonly ?string $closed_by_name,
        public readonly ?string $closed_at,
        public readonly ?TransferCloseDisposition $close_disposition,
        public readonly ?TransferDiscrepancyReason $close_reason,
        public readonly ?string $close_note,
        public readonly string $freight_uncapitalized,
        public readonly array $receipts,
        public readonly ?array $lines,
    ) {}

    public static function fromModel(StockTransfer $transfer, bool $includeLines = false): self
    {
        return new self(
            id: (string) $transfer->id,
            transfer_number: (string) $transfer->transfer_number,
            transfer_type: $transfer->transfer_type,
            status: $transfer->status,
            source_location_id: (string) $transfer->source_location_id,
            source_location_name: $transfer->sourceLocation->name ?? null,
            destination_location_id: (string) $transfer->destination_location_id,
            destination_location_name: $transfer->destinationLocation->name ?? null,
            notes: $transfer->notes,
            transfer_cost: (string) $transfer->transfer_cost,
            transfer_cost_label: $transfer->transfer_cost_label,
            transfer_cost_distribution: $transfer->transfer_cost_distribution,
            initiated_by_user_id: (string) $transfer->initiated_by_user_id,
            initiated_by_name: $transfer->initiatedBy->name ?? null,
            completed_by_user_id: $transfer->completed_by_user_id,
            completed_by_name: $transfer->completedBy?->name,
            cancelled_by_user_id: $transfer->cancelled_by_user_id,
            cancelled_by_name: $transfer->cancelledBy?->name,
            initiated_at: $transfer->initiated_at?->toIso8601String(),
            completed_at: $transfer->completed_at?->toIso8601String(),
            cancelled_at: $transfer->cancelled_at?->toIso8601String(),
            cancellation_reason: $transfer->cancellation_reason,
            created_at: $transfer->created_at?->toIso8601String(),
            updated_at: $transfer->updated_at?->toIso8601String(),
            closed_by_user_id: $transfer->closed_by_user_id,
            closed_by_name: $transfer->closedBy?->name,
            closed_at: $transfer->closed_at?->toIso8601String(),
            close_disposition: $transfer->close_disposition,
            close_reason: $transfer->close_reason,
            close_note: $transfer->close_note,
            freight_uncapitalized: (string) $transfer->freight_uncapitalized,
            receipts: array_values($transfer->receipts
                ->map(static fn ($receipt): TransferReconciliationReceiptData => TransferReconciliationReceiptData::fromModel($receipt))
                ->values()->all()),
            lines: $includeLines
                ? array_values($transfer->lines
                    ->map(static fn (StockTransferLine $line): StockTransferLineData => StockTransferLineData::fromModel($line))
                    ->values()->all())
                : null,
        );
    }
}
