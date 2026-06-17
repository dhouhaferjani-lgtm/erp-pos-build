<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Application\DTOs;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class VariantAttributeValueData extends Data
{
    public function __construct(
        public string $attribute_id,
        public string $attribute_value_id,
    ) {}
}
