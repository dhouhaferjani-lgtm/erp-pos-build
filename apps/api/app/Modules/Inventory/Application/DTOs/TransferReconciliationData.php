<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application\DTOs;

use App\Modules\Inventory\Domain\Enums\TransferCloseDisposition;
use App\Modules\Inventory\Domain\Enums\TransferDiscrepancyReason;
use App\Modules\Inventory\Domain\Enums\TransferStatus;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * The `GET /stock-transfers/{transfer}/reconciliation` body (§5.4).
 *
 * Built ONLY by TransferReconciliationService. The route is gated on
 * `inventory.transfers.reconcile`, so its reader is by definition a
 * `canSeeExpected() = true` actor and this DTO carries expected quantities
 * on purpose; it is NEVER returned from any other endpoint.
 */
#[TypeScript]
final class TransferReconciliationData extends Data
{
    /**
     * @param  list<TransferReconciliationLineData>  $lines
     * @param  list<TransferReconciliationReceiptData>  $receipts
     */
    public function __construct(
        public string $transfer_id,
        public string $transfer_number,
        public TransferStatus $status,
        public string $source_location_id,
        public string $source_location_name,
        public string $destination_location_id,
        public string $destination_location_name,
        public string $initiated_at,
        public ?string $closed_at,
        public ?TransferCloseDisposition $close_disposition,
        public ?TransferDiscrepancyReason $close_reason,
        public ?string $close_note,
        public array $lines,
        public array $receipts,
        public TransferReconciliationSummaryData $summary,
    ) {}
}
