<?php

declare(strict_types=1);

namespace App\Modules\POS\Application\DTOs;

/**
 * Immutable result value object returned by ExchangeService::processExchange().
 *
 * $netAmount is signed:
 *  - Positive  → customer paid the difference (sale > return)
 *  - Negative  → customer received a refund / voucher (return > sale)
 *  - Zero      → even exchange (no payment or refund)
 */
final readonly class ExchangeResult
{
    /**
     * @param  string  $returnReceiptId  UUID of the sealed return half
     * @param  string  $saleReceiptId  UUID of the sealed sale half
     * @param  string|null  $voucherId  UUID of the issued surplus voucher (when applicable)
     * @param  numeric-string  $netAmount  Signed net amount (positive = customer paid)
     */
    public function __construct(
        public readonly string $returnReceiptId,
        public readonly string $saleReceiptId,
        public readonly ?string $voucherId,
        public readonly string $netAmount,
    ) {}
}
