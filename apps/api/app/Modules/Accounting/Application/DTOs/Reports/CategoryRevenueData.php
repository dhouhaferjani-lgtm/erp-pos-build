<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Application\DTOs\Reports;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class CategoryRevenueData extends Data
{
    public function __construct(
        public readonly ?int $category_id,
        public readonly string $category_name,
        public readonly string $revenue,
        public readonly string $percentage,
        public readonly string $quantity,
    ) {}
}
