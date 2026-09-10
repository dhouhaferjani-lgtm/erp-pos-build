<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application\DTOs;

use App\Modules\Inventory\Domain\Enums\TransferCloseDisposition;
use App\Modules\Inventory\Domain\Enums\TransferReceiptKind;
use App\Modules\Inventory\Domain\StockTransferReceipt;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\LiteralTypeScriptType;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** One posted document in the transfer's receipt history. Carries no quantity. */
#[TypeScript]
final class TransferReconciliationReceiptData extends Data
{
    /**
     * @param  array{id: string, name: string}  $received_by
     */
    public function __construct(
        public string $receipt_number,
        public TransferReceiptKind $kind,
        public ?TransferCloseDisposition $disposition,
        public int $sequence,
        #[LiteralTypeScriptType('{ id: string; name: string }')]
        public array $received_by,
        public string $received_at,
        public bool $is_blind,
        public bool $has_discrepancy,
    ) {}

    public static function fromModel(StockTransferReceipt $receipt): self
    {
        return new self(
            receipt_number: (string) $receipt->receipt_number,
            kind: $receipt->kind,
            disposition: $receipt->disposition,
            sequence: (int) $receipt->sequence,
            received_by: ['id' => (string) $receipt->received_by_user_id, 'name' => (string) $receipt->receivedBy?->name],
            received_at: $receipt->received_at->toIso8601String(),
            is_blind: (bool) $receipt->is_blind,
            has_discrepancy: (bool) $receipt->has_discrepancy,
        );
    }
}
