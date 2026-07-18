<?php

declare(strict_types=1);

namespace App\Modules\Expense\Application\DTOs;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class ExpenseAnalyticsTilesData extends Data
{
    public function __construct(
        public string $total,
        public int $count,
        public string $unpaid_total,
        public ?string $mom_delta_percent,
    ) {}
}
