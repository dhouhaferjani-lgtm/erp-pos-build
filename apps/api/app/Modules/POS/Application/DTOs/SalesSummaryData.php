<?php

declare(strict_types=1);

namespace App\Modules\POS\Application\DTOs;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class SalesSummaryData extends Data
{
    /**
     * @param  array<int, array{payment_type: string, total: string, count: int}>  $payment_breakdown
     */
    public function __construct(
        public int $receipt_count,
        public string $gross_sales,
        public string $net_sales,
        public string $tax_total,
        public string $average_ticket,
        public int $refund_count,
        public string $refund_total,
        public int $voided_count,
        public array $payment_breakdown,
    ) {}
}
