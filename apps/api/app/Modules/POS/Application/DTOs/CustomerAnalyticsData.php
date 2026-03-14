<?php

declare(strict_types=1);

namespace App\Modules\POS\Application\DTOs;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class CustomerAnalyticsData extends Data
{
    /**
     * @param  array<int, array{partner_id: string, customer_name: string, total_spent: string, receipt_count: int}>  $top_customers
     */
    public function __construct(
        public int $unique_customers,
        public int $returning_count,
        public string $returning_rate,
        public array $top_customers,
    ) {}
}
