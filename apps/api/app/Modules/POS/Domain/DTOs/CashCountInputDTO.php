<?php

declare(strict_types=1);

namespace App\Modules\POS\Domain\DTOs;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class CashCountInputDTO extends Data
{
    public function __construct(
        public readonly string $paymentMethodId,
        public readonly string $currencyCode,
        public readonly string $actualAmount,
    ) {}
}
