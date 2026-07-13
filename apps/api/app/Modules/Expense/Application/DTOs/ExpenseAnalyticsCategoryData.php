<?php

declare(strict_types=1);

namespace App\Modules\Expense\Application\DTOs;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class ExpenseAnalyticsCategoryData extends Data
{
    public function __construct(
        public ?string $category_id,
        public string $name,
        public string $total,
        public string $share_percent,
    ) {}
}
