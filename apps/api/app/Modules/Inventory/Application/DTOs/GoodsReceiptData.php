<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application\DTOs;

use App\Modules\Inventory\Domain\GoodsReceipt;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class GoodsReceiptData extends Data
{
    /**
     * @param  list<GoodsReceiptLineData>  $lines
     * @param  array<string, mixed>|null  $payload
     */
    public function __construct(
        public string $id,
        public string $tenant_id,
        public string $company_id,
        public string $purchase_order_id,
        public string $receipt_number,
        public string $status,
        public string $received_at,
        public ?string $received_by,
        public ?string $notes,
        public ?array $payload,
        public array $lines,
        public string $created_at,
        public string $updated_at,
    ) {}

    public static function fromModel(GoodsReceipt $receipt, bool $withLines = true): self
    {
        if ($withLines && ! $receipt->relationLoaded('lines')) {
            $receipt->load('lines');
        }

        $lines = [];
        if ($withLines) {
            foreach ($receipt->lines as $line) {
                $lines[] = GoodsReceiptLineData::fromModel($line);
            }
        }

        return new self(
            id: $receipt->id,
            tenant_id: $receipt->tenant_id,
            company_id: $receipt->company_id,
            purchase_order_id: $receipt->purchase_order_id,
            receipt_number: $receipt->receipt_number,
            status: $receipt->status->value,
            received_at: $receipt->received_at->toIso8601String(),
            received_by: $receipt->received_by,
            notes: $receipt->notes,
            payload: $receipt->payload,
            lines: $lines,
            created_at: $receipt->created_at?->toIso8601String() ?? '',
            updated_at: $receipt->updated_at?->toIso8601String() ?? '',
        );
    }
}
