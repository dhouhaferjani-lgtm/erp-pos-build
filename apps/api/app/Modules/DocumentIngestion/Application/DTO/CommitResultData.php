<?php

declare(strict_types=1);

namespace App\Modules\DocumentIngestion\Application\DTO;

use Spatie\LaravelData\Data;

final class CommitResultData extends Data
{
    public function __construct(
        public string $committedType,
        public string $committedId,
        public ?string $goodsReceiptNumber = null,
    ) {}

    /**
     * @return array{committed_type: string, committed_id: string, goods_receipt_number: string|null}
     */
    public function toResponseArray(): array
    {
        return [
            'committed_type' => $this->committedType,
            'committed_id' => $this->committedId,
            'goods_receipt_number' => $this->goodsReceiptNumber,
        ];
    }
}
