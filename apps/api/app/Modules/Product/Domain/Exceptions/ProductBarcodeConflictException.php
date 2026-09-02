<?php

declare(strict_types=1);

namespace App\Modules\Product\Domain\Exceptions;

use RuntimeException;

final class ProductBarcodeConflictException extends RuntimeException
{
    public function __construct(
        public readonly string $barcode,
        public readonly string $existingProductId,
        public readonly string $existingProductSku,
        public readonly string $existingProductName,
    ) {
        parent::__construct('barcode_identity_conflict: barcode is already assigned to another product in this company.');
    }
}
