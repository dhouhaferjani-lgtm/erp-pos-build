<?php

declare(strict_types=1);

namespace App\Modules\Document\Application\DTOs;

use App\Modules\Document\Domain\DocumentLine;
use Spatie\LaravelData\Data;

final class DocumentLineData extends Data
{
    public function __construct(
        public string $id,
        public string $document_id,
        public ?string $product_id,
        public int $line_number,
        public string $description,
        public string $quantity,
        public string $unit_price,
        public ?string $discount_percent,
        public ?string $discount_amount,
        public ?string $tax_rate,
        public string $line_total,
        public ?string $notes,
    ) {}

    public static function fromModel(DocumentLine $line, int $scale = 3): self
    {
        return new self(
            id: $line->id,
            document_id: $line->document_id,
            product_id: $line->product_id,
            line_number: $line->line_number,
            description: $line->description,
            quantity: number_format((float) $line->quantity, $scale, '.', ''),
            unit_price: number_format((float) $line->unit_price, $scale, '.', ''),
            discount_percent: $line->discount_percent !== null ? number_format((float) $line->discount_percent, 2, '.', '') : null,
            discount_amount: $line->discount_amount !== null ? number_format((float) $line->discount_amount, $scale, '.', '') : null,
            tax_rate: $line->tax_rate !== null ? number_format((float) $line->tax_rate, 2, '.', '') : null,
            line_total: number_format((float) $line->line_total, $scale, '.', ''),
            notes: $line->notes,
        );
    }
}
