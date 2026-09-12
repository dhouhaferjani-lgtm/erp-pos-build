<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application\DTOs;

use App\Modules\Inventory\Domain\Enums\TransferReceiptKind;
use App\Modules\Inventory\Domain\Enums\TransferReceiptStatus;
use App\Modules\Inventory\Domain\StockTransferReceipt;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Header of a kind=receipt (or kind=legacy_completion) receipt.
 *
 * `disposition` is deliberately ABSENT: it is close-only and lives on
 * TransferCloseReceiptData. `kind` is still emitted, so a client can discriminate.
 */
#[TypeScript]
final class StockTransferReceiptData extends Data
{
    /**
     * @param  array{id: string, name: string}  $received_by
     * @param  list<StockTransferReceiptLineData>  $lines
     */
    public function __construct(
        public string $id,
        public string $receipt_number,
        public TransferReceiptKind $kind,
        public int $sequence,
        public TransferReceiptStatus $status,
        public bool $is_blind,
        public bool $has_discrepancy,
        #[LiteralTypeScriptType('{ id: string; name: string }')]
        public array $received_by,
        public string $received_at,
        public ?string $notes,
        public array $lines,
    ) {}

    public static function fromModel(StockTransferReceipt $receipt): self
    {
        return new self(
            id: (string) $receipt->id,
            receipt_number: (string) $receipt->receipt_number,
            kind: $receipt->kind,
            sequence: (int) $receipt->sequence,
            status: $receipt->status,
            is_blind: (bool) $receipt->is_blind,
            has_discrepancy: (bool) $receipt->has_discrepancy,
            received_by: ['id' => (string) $receipt->received_by_user_id, 'name' => (string) $receipt->receivedBy?->name],
            received_at: $receipt->received_at->toIso8601String(),
            notes: $receipt->notes,
            lines: array_values($receipt->lines->map(StockTransferReceiptLineData::fromModel(...))->values()->all()),
        );
    }
}
