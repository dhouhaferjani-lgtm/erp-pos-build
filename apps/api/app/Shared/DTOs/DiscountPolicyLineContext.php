<?php

declare(strict_types=1);

namespace App\Shared\DTOs;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class DiscountPolicyLineContext extends Data
{
    public function __construct(
        public string $productId,
        public ?string $variantId = null,
    ) {}
}
