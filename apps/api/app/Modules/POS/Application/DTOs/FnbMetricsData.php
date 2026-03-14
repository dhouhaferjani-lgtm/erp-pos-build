<?php

declare(strict_types=1);

namespace App\Modules\POS\Application\DTOs;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class FnbMetricsData extends Data
{
    /**
     * @param  array<int, array{hour: int, order_count: int}>  $peak_hours
     * @param  array<int, array{mode: string, count: int}>  $orders_by_mode
     */
    public function __construct(
        public string $avg_table_time_minutes,
        public string $avg_items_per_order,
        public array $peak_hours,
        public array $orders_by_mode,
    ) {}
}
