<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Application\DTOs\Reports;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class CashReconciliationData extends Data
{
    public function __construct(
        public readonly string $date,
        public readonly string $location_id,
        public readonly string $location_name,
        public readonly string $terminal_id,
        public readonly string $terminal_name,
        public readonly string $shift_id,
        public readonly string $expected_cash,
        public readonly string $counted_cash,
        public readonly string $variance,
        public readonly string $variance_severity,
    ) {}
}
