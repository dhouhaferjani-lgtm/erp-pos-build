<?php

declare(strict_types=1);

namespace App\Shared\Contracts\Compliance\DTOs;

/**
 * Snapshot of a receipt VAT breakdown row for NF525 export.
 *
 * Immutable view of POS\Domain\ReceiptVatDetail.
 */
final readonly class Nf525ReceiptVatDetailData
{
    public function __construct(
        public string $taxRate,
        public string $netAmount,
        public string $vatAmount,
        public string $grossAmount,
    ) {}
}
