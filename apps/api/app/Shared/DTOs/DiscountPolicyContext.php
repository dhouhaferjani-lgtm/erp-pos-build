<?php

declare(strict_types=1);

namespace App\Shared\DTOs;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class DiscountPolicyContext extends Data
{
    public function __construct(
        public DiscountPolicySubject $subject,
        public string $effectiveUnitPrice,
        public ?string $taxRate = null,
        public string $priceBasis = 'Ht',
    ) {}
}
