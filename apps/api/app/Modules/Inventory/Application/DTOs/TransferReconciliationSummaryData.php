<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application\DTOs;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** Transfer-level counts and freight at transfer_cost's stored scale; quantities stay per line. */
#[TypeScript]
final class TransferReconciliationSummaryData extends Data
{
    public function __construct(
        public int $lines,
        public int $lines_with_discrepancy,
        public string $freight_uncapitalized,
    ) {}
}
