<?php

declare(strict_types=1);

namespace App\Modules\Expense\Application\DTOs;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class ExpenseAnalyticsData extends Data
{
    /**
     * @param  list<ExpenseAnalyticsCategoryData>  $by_category
     * @param  list<ExpenseAnalyticsMatrixData>  $matrix
     * @param  list<ExpenseAnalyticsVendorData>  $top_vendors
     */
    public function __construct(
        public ExpenseAnalyticsTilesData $tiles,
        public array $by_category,
        public array $matrix,
        public array $top_vendors,
    ) {}
}
