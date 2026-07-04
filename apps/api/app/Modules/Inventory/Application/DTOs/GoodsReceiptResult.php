<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application\DTOs;

use App\Modules\Document\Domain\Document;
use App\Modules\Inventory\Domain\GoodsReceipt;

final readonly class GoodsReceiptResult
{
    public function __construct(
        public Document $purchaseOrder,
        public GoodsReceipt $receipt,
    ) {}

    public function __get(string $name): mixed
    {
        return $this->purchaseOrder->{$name};
    }

    /**
     * @param  array<int, mixed>  $arguments
     */
    public function __call(string $name, array $arguments): mixed
    {
        return $this->purchaseOrder->{$name}(...$arguments);
    }
}
