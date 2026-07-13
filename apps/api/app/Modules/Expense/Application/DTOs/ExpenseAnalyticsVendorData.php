<?php

declare(strict_types=1);

namespace App\Modules\Expense\Application\DTOs;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class ExpenseAnalyticsVendorData extends Data
{
    public function __construct(
        public ?string $partner_id,
        public string $vendor_name,
        public string $total,
    ) {}
}
