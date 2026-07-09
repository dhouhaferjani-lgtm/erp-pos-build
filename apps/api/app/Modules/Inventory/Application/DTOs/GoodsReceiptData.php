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
        public ?string $purchase_order_number,
        public ?string $supplier_id,
        public ?string $supplier_name,
        public ?string $receipt_number,
        public string $status,
        public string $received_at,
        public ?string $received_by,
        public ?string $external_reference,
        public ?string $external_date,
        public ?string $notes,
        public ?array $payload,
        public array $lines,
        public int $lines_count,
        public string $created_at,
        public string $updated_at,
    ) {}

    public static function fromModel(GoodsReceipt $receipt, bool $withLines = true): self
    {
        if ($withLines && ! $receipt->relationLoaded('lines')) {
            $receipt->load('lines');
        }

        if (! $receipt->relationLoaded('purchaseOrder')) {
            $receipt->load('purchaseOrder.partner');
        }

        if ($receipt->getAttribute('lines_count') === null) {
            $receipt->loadCount('lines');
        }

        $lines = [];
        if ($withLines) {
            foreach ($receipt->lines as $line) {
                $lines[] = GoodsReceiptLineData::fromModel($line);
            }
        }

        $purchaseOrder = $receipt->purchaseOrder;
        $linesCount = $receipt->getAttribute('lines_count');

        return new self(
            id: $receipt->id,
            tenant_id: $receipt->tenant_id,
            company_id: $receipt->company_id,
            purchase_order_id: $receipt->purchase_order_id,
            purchase_order_number: $purchaseOrder->document_number,
            supplier_id: $purchaseOrder->partner_id,
            supplier_name: $purchaseOrder->partner->name,
            receipt_number: $receipt->receipt_number,
            status: $receipt->status->value,
            received_at: $receipt->received_at->toIso8601String(),
            received_by: $receipt->received_by,
            external_reference: $receipt->external_reference,
            external_date: $receipt->external_date?->toDateString(),
            notes: $receipt->notes,
            payload: $receipt->payload,
            lines: $lines,
            lines_count: is_numeric($linesCount) ? (int) $linesCount : count($lines),
            created_at: $receipt->created_at?->toIso8601String() ?? '',
            updated_at: $receipt->updated_at?->toIso8601String() ?? '',
        );
    }
}
