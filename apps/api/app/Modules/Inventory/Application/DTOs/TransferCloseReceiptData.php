<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application\DTOs;

use App\Modules\Inventory\Domain\Enums\TransferCloseDisposition;
use App\Modules\Inventory\Domain\Enums\TransferDiscrepancyReason;
use App\Modules\Inventory\Domain\Enums\TransferReceiptKind;
use App\Modules\Inventory\Domain\Enums\TransferReceiptStatus;
use App\Modules\Inventory\Domain\StockTransferReceipt;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** Header of a kind=close receipt. `disposition` and `close_reason` exist only here. */
#[TypeScript]
final class TransferCloseReceiptData extends Data
{
    /**
     * @param  array{id: string, name: string}  $received_by
     * @param  list<TransferCloseLineData>  $lines
     */
    public function __construct(
        public string $id,
        public string $receipt_number,
        public TransferReceiptKind $kind,
        public TransferCloseDisposition $disposition,
        public TransferDiscrepancyReason $close_reason,
        public ?string $close_note,
        public int $sequence,
        public TransferReceiptStatus $status,
        public bool $is_blind,
        public bool $has_discrepancy,
        #[LiteralTypeScriptType('{ id: string; name: string }')]
        public array $received_by,
        public string $received_at,
        public ?string $notes,
        public string $freight_uncapitalized,
        public array $lines,
    ) {}

    public static function fromModel(StockTransferReceipt $receipt): self
    {
        return new self(
            id: (string) $receipt->id,
            receipt_number: (string) $receipt->receipt_number,
            kind: $receipt->kind,
            disposition: $receipt->disposition ?? throw new \LogicException('A close receipt always carries a disposition.'),
            close_reason: $receipt->transfer->close_reason ?? throw new \LogicException('A close receipt always carries a reason.'),
            close_note: $receipt->transfer->close_note,
            sequence: (int) $receipt->sequence,
            status: $receipt->status,
            is_blind: (bool) $receipt->is_blind,
            has_discrepancy: (bool) $receipt->has_discrepancy,
            received_by: ['id' => (string) $receipt->received_by_user_id, 'name' => (string) $receipt->receivedBy?->name],
            received_at: $receipt->received_at->toIso8601String(),
            notes: $receipt->notes,
            freight_uncapitalized: (string) $receipt->transfer->freight_uncapitalized,
            lines: array_values($receipt->lines->map(TransferCloseLineData::fromModel(...))->values()->all()),
        );
    }
}
