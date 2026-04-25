<?php

declare(strict_types=1);

namespace App\Modules\POS\Domain\DTOs;

use App\Shared\Domain\Enums\VarianceDirection;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class CashCountBreakdownDTO extends Data
{
    public function __construct(
        public readonly string $paymentMethodId,
        public readonly string $currencyCode,
        public readonly string $expectedAmount,
        public readonly string $actualAmount,
        public readonly string $varianceAmount,
        public readonly VarianceDirection $varianceDirection,
        public readonly int $transactionCount,
    ) {}
}
